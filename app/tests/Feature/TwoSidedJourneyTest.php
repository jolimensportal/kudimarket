<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Models\User;
use App\Services\OfferDesk;
use App\Services\TradeDesk;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use App\Services\VerificationDesk;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * Two traders, two sides, one trade between them, every chain verifying at the end.
 *
 * This is the file that answers "both sides, actually?" Everything before it built one side and proved it
 * worked. A buyer could buy from KudiMarket. A seller could list, and a seller could be taken up, but
 * nothing had yet put a buyer and a seller in the same trade and checked the result.
 */
class TwoSidedJourneyTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        // Decimal STRINGS. A float here publishes 0.0067567568, and the whole design turns on an offer
        // being measured against the same number the card holds.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['NGN', 'GHS', '0.006756756756756756', 'rates-desk@kudimarket.com']
        );
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', '148.2', 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_seller_lists_and_a_buyer_takes_it_and_the_trade_settles(): void
    {
        $desk = app(OfferDesk::class);

        // Publish signed in as the seller, then switch to the buyer. Signing both in first made the buyer
        // the lister, which the ledger then refused to let accept its own offer.
        $seller = $this->trader('seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');
        $this->assertCount(1, $desk->mine($seller->email));

        $buyer = $this->trader('buyer');
        $tradeId = $desk->accept($offerId);

        // Both sides funded. A vendor-to-vendor claim takes a deposit from each party, and a deposit is a
        // ledger movement, so a trader with no balance cannot claim -- which is the product working, not
        // a test obstacle. Minted on the admin connection because that is the chain watcher's job and no
        // application role may do it. See the note on test_a_vendor_trade_cannot_be_claimed_without_balances.
        $this->fund($seller->email, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);

        // actingAs, not just setUser: the claim below is an HTTP request and needs the client signed in,
        // not merely the guard holding a user. setUser satisfies a service call and nothing else.
        $this->actingAs($buyer, 'trader');

        $trade = DB::connection('pgsql_trader')->selectOne('select * from trade where id = ?', [$tradeId]);

        $this->assertSame('vendor_vendor', $trade->trade_type);
        $this->assertSame($seller->email, $trade->seller, 'the lister is the seller');
        $this->assertSame($buyer->email, $trade->buyer, 'the taker is the buyer');
        $this->assertSame(
            '0.006756756756756756',
            (string) $trade->rate,
            'the trade is priced by our rate card, not by the walk-away the seller typed'
        );

        // It settles the whole way: the buyer claims, the SELLER -- the recipient of the buyer's payment --
        // confirms it arrived, the window elapses, and it releases.
        //
        // NOT operations. On a "Checked" vendor trade the buyer is relying on our verification of the
        // seller, not on a guarantee from us: "Two labels let us sell the strong promise on the trades we
        // control and be factual on the rest." Operations confirming here would make KudiMarket the
        // guarantor of every peer-to-peer trade -- the tier we have not priced, and the one that would put
        // the Bank of Ghana first rather than the SEC.
        $this->post("/trade/{$tradeId}/claim");
        $this->assertSame('paid_claimed', $this->stateOf($tradeId));

        app(TradeDesk::class)->attestPaid($tradeId, $seller->email);
        $this->assertSame('paid_attested', $this->stateOf($tradeId));

        $this->withClaimWindow('0 seconds', function () use ($tradeId): void {
            app(TradeLedger::class)->release($tradeId, 'ops@kudimarket.com');
        });

        $this->assertSame('released', $this->stateOf($tradeId), 'a vendor-to-vendor trade completes');
        $this->assertEveryChainVerifies();
    }

    public function test_a_vendor_to_vendor_trade_takes_deposits_from_both_sides(): void
    {
        // The one thing that makes cheating more expensive than honesty on a trade where neither side is
        // us. Trading against KudiMarket takes no deposit because we are the risk; trading against another
        // trader does.
        $desk = app(OfferDesk::class);

        // Publish as the seller, then switch. Signing both in first made the BUYER list the offer and then
        // accept it, which the ledger refused -- correctly, and with a message that reads like a product
        // bug rather than a test that lost track of who was signed in.
        $seller = $this->trader('dep-seller');
        $sellerEmail = $seller->email;
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('dep-buyer');
        $tradeId = $desk->accept($offerId);

        $this->fund($sellerEmail, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        // As operations: kudi_trader has no grant on `account`, which is the whole point of it not having
        // one. A trader screen cannot read deposits either -- see OfferDesk::position().
        //
        // Scoped to THIS trade. Counted globally it also picks up every deposit any other test in this
        // shared, persistent database has left behind, and the assertion then describes the suite rather
        // than the trade.
        $deposits = DB::connection('pgsql_ops')->select(
            'select owner, asset, balance from account
              where owner like ? and balance <> 0
              order by owner',
            ["trade:{$tradeId}%\_deposit"]
        );

        $this->assertCount(2, $deposits, 'both sides of a vendor trade post a deposit');
        $this->assertEveryChainVerifies();
    }

    public function test_the_seller_can_withdraw_before_anybody_takes_it(): void
    {
        $desk = app(OfferDesk::class);

        $this->trader('withdraw-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');
        $desk->withdraw($offerId);

        $this->trader('withdraw-buyer');

        $refused = '';
        try {
            $desk->accept($offerId);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        // The wording a trader reads, not the wording the database uses. The service maps 'offer N is no
        // longer open' onto something that says what to do next.
        $this->assertStringContainsString('Somebody already took this offer, or you withdrew it', $refused);
        $this->assertEveryChainVerifies();
    }

    public function test_an_offer_below_the_board_never_trades_and_costs_nobody_anything(): void
    {
        // The seller's protection, end to end through the screens.
        $desk = app(OfferDesk::class);

        $this->trader('greedy-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0090');

        $this->trader('hopeful-buyer');

        $tradesBefore = count($this->allTradeIds());

        $this->get(route('trade.offer.board'))->assertOk()->assertSee('will not clear');

        try {
            $desk->accept($offerId);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount($tradesBefore, $this->allTradeIds(), 'nothing was traded');
        $this->assertEveryChainVerifies();
    }

    public function test_a_seller_sees_their_own_offer_and_cannot_take_it(): void
    {
        $seller = $this->trader('self-seller');
        $desk = app(OfferDesk::class);
        $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        // Scoped to what this seller's own row must contain. assertDontSee('Take this') over the whole
        // page would be asserting about every OTHER trader's offer too, which the board shows on purpose.
        $this->get(route('trade.offer.board'))
            ->assertOk()
            ->assertSee('You are selling')
            ->assertSee('Withdraw this');

        $this->expectException(\RuntimeException::class);
        app(OfferDesk::class)->accept((int) $desk->mine($seller->email)[0]->id);
    }

    public function test_a_trader_cannot_withdraw_an_offer_that_is_not_theirs(): void
    {
        $sellerEmail = $this->trader('keep-mine')->email;
        $offerId = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->trader('nosy-neighbour');

        $this->post(route('trade.offer.withdraw', $offerId))->assertForbidden();

        $this->assertCount(1, app(OfferDesk::class)->mine($sellerEmail), 'the offer is untouched');
    }

    public function test_the_offer_form_rejects_nonsense_rates(): void
    {
        $this->trader('form-tester');

        $this->post(route('trade.offer.store'), [
            'base_asset' => 'NGN',
            'quote_asset' => 'GHS',
            'amount_minor' => 50000000,
            'min_rate' => 'about six thousandths',
        ])->assertSessionHasErrors('min_rate');

        $this->post(route('trade.offer.store'), [
            'base_asset' => 'NGN',
            'quote_asset' => 'NGN',
            'amount_minor' => 50000000,
            'min_rate' => '0.0060',
        ])->assertSessionHasErrors('quote_asset');
    }

    public function test_the_offer_form_says_it_is_not_setting_a_price(): void
    {
        // The sentence is the design decision, made visible where it is acted on.
        $this->trader('wordy-check');

        $this->get(route('trade.offer.create'))
            ->assertOk()
            ->assertSee('You are not setting a price')
            ->assertSee('lowest rate you will accept');
    }

    public function test_the_position_screen_never_shows_a_balance(): void
    {
        $this->trader('position-check');

        $this->get(route('trade.offer.position'))
            ->assertOk()
            ->assertSee('Where you stand')
            ->assertDontSee('You hold');
    }

    public function test_a_full_two_sided_day_leaves_every_chain_verifying(): void
    {
        // Mixed traffic through both paths at once. A chain that only verifies when every trade took the
        // same route is not verified.
        for ($round = 0; $round < 3; $round++) {
            $desk = app(OfferDesk::class);

            $seller = $this->trader("day-seller-{$round}");
            $offerId = $desk->publish('NGN', 'GHS', 10000000 + $round, '0.0060');

            $buyer = $this->trader("day-buyer-{$round}");
            $tradeId = $desk->accept($offerId);
            $this->fund($seller->email, 'GHS', 100000);
            $this->fund($buyer->email, 'GHS', 100000);
            $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");
        }

        // And one against our own book, so both trade types are in the same verification pass.
        $buyer = $this->trader('day-platform-buyer');
        $direct = app(TradeDesk::class)->openAgainstUs($buyer->email, 200000, 'NGN');
        $this->actingAs($buyer, 'trader')->post("/trade/{$direct}/claim");

        $this->assertEveryChainVerifies();
    }

    public function test_a_vendor_trade_cannot_be_claimed_without_balances(): void
    {
        // Found while wiring the happy path, and worth pinning. A vendor-to-vendor claim moves a deposit
        // from each side, so a trader whose ledger balance is zero cannot claim, and the refusal names the
        // balance rather than the state. Trading against KudiMarket claims with no deposit, because we
        // are the risk -- so the same button behaves differently on the two kinds of trade, which is
        // exactly the kind of difference a trader meets for the first time at 2am.
        $desk = app(OfferDesk::class);

        $this->trader('broke-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('broke-buyer');
        $tradeId = $desk->accept($offerId);

        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $this->assertSame(
            'open',
            $this->stateOf($tradeId),
            'an unfunded party cannot claim, and the deposit is what stops them'
        );
    }

    public function test_the_trade_screen_shows_a_vendor_buyer_what_the_trade_is_worth(): void
    {
        /**
         * The screen a buyer reads before paying. It used to say "You send 74,000,000.00 GHS" on a trade
         * worth C3,378.38 and then hand over a Mobile Money number to send it to.
         *
         * The arithmetic is covered in TradeFiguresTest. What is covered HERE is that the number reaching
         * the screen is right for a trade built the way the product builds one -- an offer accepted off the
         * rate card -- rather than for a hand-built object. The two shapes are identical rows to the
         * database and used to differ only here, which is why a unit test alone would not have caught it.
         */
        $desk = app(OfferDesk::class);

        $seller = $this->trader('screen-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('screen-buyer');
        $tradeId = $desk->accept($offerId);

        $this->fund($seller->email, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $screen = $this->actingAs($buyer, 'trader')->get("/trade/{$tradeId}")
            ->assertOk()
            ->getContent();

        $figures = app(TradeFigures::class)->forTrade(
            DB::connection('pgsql_ops')->selectOne('select * from trade_view where id = ?', [$tradeId])
        );

        // The cedi figure on the page is the one the ledger says the trade is worth.
        $this->assertStringContainsString(
            str_replace(',', '', $figures['send']),
            str_replace(',', '', $screen),
            'the cedi figure on the screen is the derived one'
        );

        // The two legs agree with each other at the agreed rate. The old comparison divided one currency's
        // minor units by another's, which is meaningless now that the buyer sends naira and receives cedi --
        // 50,000,000 minor naira is naturally a bigger NUMBER than 337,838 minor cedi. So this compares
        // majors against the rate instead, which is the actual invariant.
        $sendMajor = $figures['send_minor'] / 100;
        $receiveMajor = $figures['receive_minor'] / 100;
        $rate = (float) app(TradeLedger::class)->trade($tradeId)->rate;

        $this->assertEqualsWithDelta(
            $sendMajor * $rate,
            $receiveMajor,
            1.0,
            'the cedi leg is the naira leg at the agreed rate, not some other order of magnitude'
        );

        // The two figures are read the RIGHT WAY ROUND for each party, which is the half that stayed wrong
        // after the arithmetic was fixed. The buyer sends the naira; the seller owes the cedi. Asserted
        // against the flattened text, because in the markup a tag sits between "You send" and the figure.
        $sellerScreen = $this->flat($this->actingAs($seller, 'trader')->get("/trade/{$tradeId}")->getContent());

        $this->assertStringContainsString('You send 500,000.00 NGN', $this->flat($screen), 'the buyer sends naira');
        $this->assertStringContainsString('You receive 3,378.38 GHS', $this->flat($screen), 'and receives cedi');

        $this->assertStringContainsString('You send 3,378.38 GHS', $sellerScreen, 'the seller owes cedi');
        $this->assertStringContainsString('You receive 500,000.00 NGN', $sellerScreen, 'and receives the naira');

        // And the instruction under the figures matches, which is what a person acts on.
        $this->assertStringContainsString('Send the naira to', $this->flat($screen), 'the buyer sends naira');
        $this->assertStringContainsString('Send the cedi to', $sellerScreen, 'the seller sends cedi');
    }

    public function test_a_vendor_buyer_is_given_a_reference_to_quote(): void
    {
        /**
         * The payment card used to say "Use the reference we gave you" -- but nothing in the product ever
         * gave one. That sentence is a phone call to operations waiting to happen: the buyer has no way to
         * learn the reference except asking. The reference already exists on both sides of this screen: the
         * trade number. Pinned so it cannot drift back to a promise with nothing behind it.
         */
        $seller = $this->trader('ref-seller');
        $offerId = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('ref-buyer');
        $tradeId = app(OfferDesk::class)->accept($offerId);

        $screen = $this->flat(
            $this->actingAs($buyer, 'trader')->get("/trade/{$tradeId}")->assertOk()->getContent()
        );

        $this->assertStringContainsString(
            "Use Trade #{$tradeId} as the reference",
            $screen,
            'the reference the buyer must quote is the trade number, which the seller sees too'
        );
        $this->assertStringNotContainsString(
            'the reference we gave you',
            $screen,
            'a reference that was never given must not be promised'
        );
    }

    public function test_the_seller_can_open_the_trade_they_are_part_of(): void
    {
        /**
         * The seller was locked out of their own trade.
         *
         * TradeController::ownedTrade checked `$row->buyer === ...` and nothing else, so a seller got
         * "That trade belongs to somebody else" on a trade they are named on. On a vendor trade the
         * seller's entire job is to confirm the naira arrived -- they are the RECIPIENT, and the reason the
         * Checked tier works is that nobody else can say "it reached me". The one screen telling them the
         * buyer has claimed was the one screen they could not open.
         *
         * And the reverse still holds: a party may look, but only the buyer may say they have paid.
         */
        $desk = app(OfferDesk::class);

        $seller = $this->trader('access-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('access-buyer');
        $tradeId = $desk->accept($offerId);

        $this->fund($seller->email, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $this->actingAs($seller, 'trader')->get("/trade/{$tradeId}")
            ->assertOk();

        // A third party is still refused, and the refusal still names a reason.
        $stranger = $this->trader('access-stranger');
        $this->actingAs($stranger, 'trader')->get("/trade/{$tradeId}")
            ->assertForbidden();

        // Being a party is not permission to do everything.
        $this->actingAs($seller, 'trader')->post("/trade/{$tradeId}/claim")->assertForbidden();
    }

    public function test_the_vendor_deposit_is_a_share_of_the_value_of_the_trade(): void
    {
        /**
         * The defect this file used to pin, now a regression test for the fix.
         *
         * kudi-core/004 computed the deposit as a share of the BASE leg:
         *
         *     d := div(t.fiat_amount * pol.deposit_bps, 10000)
         *
         * and then took it in the DEPOSIT asset. A vendor trade has its two legs in two currencies -- the
         * buyer sends naira, the seller owes cedi, and the deposit is taken in cedi -- so 10% of N500,000
         * was charged as C50,000 to move a trade worth C3,378.38. That is fourteen times the money involved,
         * in the very currency the seller is selling naira to raise, which is why the sellers in this file
         * had to be funded C60,000 to move N500,000 before the buyer could claim at all.
         *
         * It survived 286 passing SQL tests because every deposit test used rate = 1 with deposit_asset =
         * fiat_asset, where the conversion is a no-op and a share of the wrong number looks correct. The
         * fix converts the value into the deposit asset first: base -> quote multiplies, because rate is
         * quote per base.
         *
         * The exact figure below is the point. A deposit that drifts back to a share of the naira number
         * fails here, and so does one that is merely too large -- the trade has to be movable on a
         * proportionate sum, which is what makes it usable by anyone who is not a bank.
         */
        $desk = app(OfferDesk::class);

        $seller = $this->trader('deposit-value-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('deposit-value-buyer');
        $tradeId = $desk->accept($offerId);

        // Enough to post the deposit and nothing more: C338 is 10% of the C3,378.38 the trade is worth. The
        // old code needed C60,000 here, so funding only what is owed is itself part of the assertion.
        $this->fund($seller->email, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);

        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");
        $this->assertSame('paid_claimed', $this->stateOf($tradeId), 'the buyer could claim without C60,000');

        // Scoped to this trade, for the same reason: a global sum picks up every other test's deposits.
        $held = (int) DB::connection('pgsql_ops')->selectOne(
            "select coalesce(sum(balance), 0) as b from account
              where owner like ? and asset = 'GHS'",
            ["trade:{$tradeId}%\\_deposit"]
        )->b;

        // C337.83 from EACH side, C675.66 held in total, against a trade worth C3,378.38. It was C50,000.00
        // from each side -- C100,000.00 held, 14.8x the trade.
        $this->assertSame(67566, $held, 'the deposit is 10% of the value, taken in the currency of the leg');

        // And both sides get it back on success, which is the other half of a deposit rather than a toll.
        // There is no HTTP route for either of these steps -- they are services, and the two URLs I first
        // guessed at (/confirm-received, /release) do not exist and 404 silently, leaving the trade stuck.
        app(TradeDesk::class)->attestPaid($tradeId, $seller->email);
        app(TradeLedger::class)->release($tradeId, 'ops@kudimarket.com');

        $this->assertSame('released', $this->stateOf($tradeId));
        $this->assertSame(0, (int) DB::connection('pgsql_ops')->selectOne(
            'select coalesce(sum(balance), 0) as b from account where owner like ?',
            ["trade:{$tradeId}%\\_deposit"]
        )->b, 'the deposits came back');
    }

    public function test_only_the_recipient_confirms_a_vendor_payment(): void
    {
        /**
         * The two assurance tiers, enforced.
         *
         * brand/product.html: "Two levels: checked, and KudiMarket handled. One badge that reads 'safe to
         * send money to' is a guarantee we have not priced. Two labels let us sell the strong promise on the
         * trades we control and be factual on the rest."
         *
         * So on a vendor trade the buyer is relying on our verification of the seller, NOT on a guarantee
         * from us. Three refusals and one permission:
         *
         *   - operations may NOT confirm it, because we are not the counterparty. An exception allowing
         *     exactly this was written into kudi-core/004 and then removed: it made KudiMarket the guarantor
         *     of every peer-to-peer trade, which is the tier we have not priced and the reason the Bank of
         *     Ghana rather than the SEC is the first regulator.
         *   - the PAYER may not confirm it, because whoever is owed the money is never the person who says
         *     they sent it. That is the whole reason the step exists.
         *   - the RECIPIENT -- the seller, who is being paid the naira -- may. That is the tier working, not
         *     a hole in it.
         */
        $desk = app(OfferDesk::class);

        $seller = $this->trader('tier-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('tier-buyer');
        $tradeId = $desk->accept($offerId);

        $this->fund($seller->email, 'GHS', 33784);
        $this->fund($buyer->email, 'GHS', 33784);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $this->assertSame('paid_claimed', $this->stateOf($tradeId), 'claimed');

        $opsRefused = false;
        try {
            app(TradeLedger::class)->attest($tradeId, 'ops@kudimarket.com');
        } catch (\RuntimeException) {
            $opsRefused = true;
        }
        $this->assertTrue($opsRefused, 'operations may not confirm a trade we are not the counterparty for');

        $payerRefused = false;
        try {
            app(TradeDesk::class)->attestPaid($tradeId, $buyer->email);
        } catch (\RuntimeException) {
            $payerRefused = true;
        }
        $this->assertTrue($payerRefused, 'the payer may not confirm the payment it owes');

        app(TradeDesk::class)->attestPaid($tradeId, $seller->email);
        $this->assertSame('paid_attested', $this->stateOf($tradeId), 'the recipient confirms');

        $this->assertEveryChainVerifies();
    }

    /**
     * Put a trader through the real check, because the gate reads the verification CHAIN and no longer
     * trusts trader.verified_at. Applying and deciding through the service is the point: if only the test
     * harness can make someone a counterparty, then nothing in the product can, which is the bug 010
     * existed to fix.
     */
    protected function check(Trader $trader): void
    {
        // Sign the trader in FIRST: apply_for_verification reads the trader guard, and calling it before
        // one exists fails with "You need to be signed in" -- which is the service refusing to apply on
        // anybody's behalf, working correctly.
        Auth::guard('trader')->setUser($trader);

        $desk = VerificationDesk::class;
        app($desk)->apply('Checked in tests. Ghana Card GHA-000000000-9-9, 1 Test Road Accra, own-name MTN 0240000009, funds from trading.');

        $staff = User::firstOrNew(['email' => 'ops-fixtures@kudimarket.com']);
        $staff->name = 'Fixtures';
        $staff->password = bcrypt('kudi-dev-only');
        $staff->email_verified_at = now();
        $staff->save();

        $this->actingAs($staff, 'web');
        app($desk)->decide($trader->email, true, null);

        // Back to the trader: deciding signed the staff member in, and the caller is about to act as the
        // trader. Two guards, two identities, and the order matters.
        Auth::guard('trader')->setUser($trader);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Put a balance in a trader's account, as the chain watcher would after real money arrived.
     *
     * On the admin connection, because that is the only role allowed to mint: kudi_chain does it in
     * production and kudi_pricing and kudi_trader must not. It stands in for a bank or mobile-money
     * confirmation, which the trader app has no way to produce on its own -- a gap worth naming rather
     * than papering over, since a trader cannot currently fund themselves from anywhere in this product.
     */
    /**
     * Markup flattened to one line of text. Assertions about what a person READS belong against the words
     * on the page, not the tags between them, and a view that wraps a figure in a <span> should not break a
     * test about the sentence around it.
     */
    private function flat(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function fund(string $email, string $asset, int $minorUnits): void
    {
        DB::connection('pgsql_admin')->selectOne(
            'select ledger_deposit(?, ?, ?, ?) as id',
            [$email, $asset, $minorUnits, 'fund-'.bin2hex(random_bytes(6))]
        );
    }

    /**
     * A VERIFIED trader -- which is what publishing an offer requires.
     *
     * brand/product.html's two tiers are enforced in the database: only a verified trader may be a
     * counterparty to another trader, so an unverified one can only trade against KudiMarket's own book,
     * where we are the counterparty and do guarantee. Verification is set here explicitly because
     * email_verified_at is not fillable, and mass assignment drops it without a word.
     */
    private function trader(string $label, bool $verified = true): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        if ($verified) {
            $this->check($trader);
        }

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    private function stateOf(int $tradeId): string
    {
        return DB::connection('pgsql_trader')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }

    private function allTradeIds(): array
    {
        return DB::connection('pgsql_trader')->select('select id from trade');
    }

    private function assertEveryChainVerifies(): void
    {
        $db = DB::connection('pgsql_ops');

        foreach (['verify_chain', 'verify_trade_chain', 'verify_offer_chain', 'verify_rate_chain'] as $chain) {
            $this->assertTrue(
                $this->postgresTrue($db->selectOne("select {$chain}() as ok")->ok),
                "{$chain}() did not verify"
            );
        }

        $report = $db->selectOne('select * from integrity_report');

        $this->assertSame(0, (int) $report->unanchored_trades, 'a trade exists with no chained event');
        $this->assertSame(0, (int) $report->balance_mismatches, 'cached balances disagree with the postings');
    }

    /** Restored afterwards: trade_policy is one shared row per trade type in a database that persists. */
    private function withClaimWindow(string $window, callable $work): void
    {
        $original = DB::connection('pgsql_admin')->selectOne(
            "select claim_window from trade_policy where trade_type = 'vendor_vendor'"
        )->claim_window;

        DB::connection('pgsql_admin')->update(
            "update trade_policy set claim_window = ? where trade_type = 'vendor_vendor'",
            [$window]
        );

        try {
            $work();
        } finally {
            DB::connection('pgsql_admin')->update(
                "update trade_policy set claim_window = ? where trade_type = 'vendor_vendor'",
                [$original]
            );
        }
    }
}

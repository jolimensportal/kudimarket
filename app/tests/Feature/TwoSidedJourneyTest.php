<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\OfferDesk;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
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
        $this->fund($seller->email, 'GHS', 6000000);
        $this->fund($buyer->email, 'GHS', 6000000);

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

        $this->fund($sellerEmail, 'GHS', 6000000);
        $this->fund($buyer->email, 'GHS', 6000000);
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
        $direct = app(TradeDesk::class)->openAgainstUs($buyer->email, 200000, 148.2);
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

    public function test_the_vendor_deposit_is_about_fifteen_times_the_value_of_the_trade(): void
    {
        /**
         * A KNOWN DEFECT, pinned so it cannot be forgotten. Not an expectation of correct behaviour.
         *
         * In kudi-core/004, the deposit is computed as a share of the BASE leg:
         *
         *     d := div(t.fiat_amount * pol.deposit_bps, 10000)
         *
         * and then taken in the DEPOSIT asset. When those are the same currency -- which was true of every
         * crypto-leg trade the core was built for -- that is a plain 10%. On a cedi-to-naira trade they
         * are different currencies, so a 10% share of ₦500,000 is taken as ₵50,000, which for a trade
         * worth ₵3,378 is roughly fifteen times the money involved. Both parties must be able to post it
         * or the trade cannot be claimed at all, which is what stopped the happy path until the sellers
         * in this file were funded ₵60,000 to move ₦500,000.
         *
         * It also lands in the currency the seller may not hold: the seller is selling naira and is asked
         * for cedi.
         *
         * The right fix is economic, not clerical: a deposit should be a share of the trade's VALUE in
         * the asset it is posted in. That changes what traders are asked for, so it is a decision rather
         * than a bugfix, and it is not made here. Until it is, no vendor-to-vendor trade is realistically
         * usable.
         */
        $desk = app(OfferDesk::class);

        $seller = $this->trader('deposit-defect-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->trader('deposit-defect-buyer');
        $tradeId = $desk->accept($offerId);

        $this->fund($seller->email, 'GHS', 6000000);
        $this->fund($buyer->email, 'GHS', 6000000);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $this->assertSame('paid_claimed', $this->stateOf($tradeId));

        // Scoped to this trade, for the same reason: a global sum picks up every other test's deposits.
        $held = (int) DB::connection('pgsql_ops')->selectOne(
            "select coalesce(sum(balance), 0) as b from account
              where owner like ? and asset = 'GHS'",
            ["trade:{$tradeId}%\_deposit"]
        )->b;

        // ₵50,000.00 held, against a trade worth ₵3,378.38. Pinned exactly, so that fixing the defect
        // makes this test fail and forces whoever fixes it to say so here.
        $this->assertSame(10000000, $held, 'each side is holding a deposit of GHS 50,000.00');
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

        $this->fund($seller->email, 'GHS', 6000000);
        $this->fund($buyer->email, 'GHS', 6000000);
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

    // ---------------------------------------------------------------- helpers

    /**
     * Put a balance in a trader's account, as the chain watcher would after real money arrived.
     *
     * On the admin connection, because that is the only role allowed to mint: kudi_chain does it in
     * production and kudi_pricing and kudi_trader must not. It stands in for a bank or mobile-money
     * confirmation, which the trader app has no way to produce on its own -- a gap worth naming rather
     * than papering over, since a trader cannot currently fund themselves from anywhere in this product.
     */
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
            $trader->verified_at = now();
            $trader->save();
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

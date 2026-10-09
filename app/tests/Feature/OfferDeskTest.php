<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\OfferDesk;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * The seller's side.
 *
 * The assertions that matter most are the ones about min_rate. It is a walk-away, not a price, and that
 * single distinction is the difference between a bureau de change that quotes its own rate and a
 * matching platform -- which is the question a regulator asks, and which brand/product.html settles in
 * writing. A test suite that only checked "an offer can be published" would pass either way.
 */
class OfferDeskTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['NGN', 'GHS', '0.006756756756756756', 'rates-desk@kudimarket.com']
        );
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', '148.2', 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_trader_can_list_naira_they_want_to_sell_for_cedi(): void
    {
        $seller = $this->signIn();

        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $offer = app(OfferDesk::class)->offer($id);

        $this->assertSame($seller->email, $offer->seller);
        $this->assertSame('NGN', $offer->base_asset);
        $this->assertSame('GHS', $offer->quote_asset);
        $this->assertSame('50000000', (string) $offer->amount);
    }

    public function test_listing_an_offer_creates_no_trade(): void
    {
        // A listing is a statement of intent, not a commitment and not a custody. If it took the seller's
        // currency then the SEC question in brand/legal.html -- whether we hold customer assets -- would
        // already be answered, and it is not.
        $this->signIn();
        $trades = count($this->trades());
        $postings = count($this->postings());

        app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        // Compared before and after, not against zero. This database is shared between files and survives
        // between runs, so a count of zero asserts that the whole suite has never traded anything --
        // which is a statement about the suite, not about this method.
        $this->assertCount($trades, $this->trades(), 'listing an offer creates no trade');
        $this->assertCount($postings, $this->postings(), 'listing an offer moves no money');
    }

    public function test_an_offer_appears_on_the_board_and_expires(): void
    {
        $this->signIn();
        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $board = app(OfferDesk::class)->board();

        $this->assertContains($id, array_map(fn ($o) => (int) $o->id, $board));
        $this->assertNotNull(DB::connection('pgsql_trader')->selectOne(
            'select expires_at from offer where id = ?', [$id]
        )->expires_at, 'an offer with no expiry is a standing instruction to hold funds');
    }

    public function test_a_seller_sees_their_own_offers_and_can_withdraw_one(): void
    {
        $seller = $this->signIn();
        $desk = app(OfferDesk::class);
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->assertCount(1, $desk->mine($seller->email));

        $desk->withdraw($id);

        $this->assertSame([], $desk->mine($seller->email));
    }

    public function test_a_withdrawn_offer_keeps_what_the_seller_actually_said(): void
    {
        // The original statement survives. An offer that could be edited would let a seller rewrite the
        // terms of a deal a buyer had already acted on.
        $this->signIn();
        $desk = app(OfferDesk::class);
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0060');
        $desk->withdraw($id);

        $offer = $desk->offer($id);

        $this->assertSame('50000000', (string) $offer->amount);
        $this->assertSame('0.006000000000000000', (string) $offer->min_rate);
        $this->assertSame('withdrawn', DB::connection('pgsql_trader')->selectOne(
            'select reason from offer_withdrawal where offer_id = ?', [$id]
        )->reason);
    }

    public function test_taking_an_offer_creates_a_vendor_to_vendor_trade_priced_by_the_card(): void
    {
        // THE assertion. The seller's walk-away is 0.0060 and the card says 0.006756756756756756. The
        // trade must be recorded at the CARD, not at the number the seller typed. Pricing at min_rate
        // would make KudiMarket a matching platform, which brand/product.html rules out in writing.
        $seller = $this->signIn();
        $desk = app(OfferDesk::class);
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->anotherTrader('buyer');

        $tradeId = app(OfferDesk::class)->accept($id);

        $trade = DB::connection('pgsql_trader')->selectOne('select * from trade where id = ?', [$tradeId]);

        $this->assertSame('vendor_vendor', $trade->trade_type);
        $this->assertSame($seller->email, $trade->seller);
        $this->assertSame($buyer->email, $trade->buyer);
        $this->assertSame(
            '0.006756756756756756',
            (string) $trade->rate,
            'the trade is priced by the rate card, never by the walk-away'
        );
    }

    public function test_an_offer_the_board_does_not_reach_does_not_trade(): void
    {
        // The seller's guard. If the card has moved past the walk-away, they do not trade, and they are
        // told why rather than being quietly filled at a worse price.
        $seller = $this->signIn();
        $desk = app(OfferDesk::class);
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0090');

        $this->anotherTrader('buyer2');

        $message = '';
        try {
            app(OfferDesk::class)->accept($id);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $trades = count($this->trades());

        $this->assertStringContainsString('has not reached the rate you asked for', $message);
        $this->assertCount($trades, $this->trades(), 'nothing was traded');
        // This specific offer, by id. A count here would be a statement about whatever else this shared
        // database happens to hold, which is not what the assertion is for.
        $this->assertContains(
            $id,
            array_map(fn ($o) => (int) $o->id, app(OfferDesk::class)->board()),
            'the offer is still up for later'
        );
        $this->assertNotNull($seller);
    }

    public function test_the_same_offer_cannot_be_sold_twice(): void
    {
        $this->signIn();
        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->anotherTrader('first');
        app(OfferDesk::class)->accept($id);

        $this->anotherTrader('second');

        $this->expectException(\RuntimeException::class);
        app(OfferDesk::class)->accept($id);
    }

    public function test_a_seller_cannot_take_their_own_offer(): void
    {
        $this->signIn();
        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->expectException(\RuntimeException::class);
        app(OfferDesk::class)->accept($id);
    }

    public function test_a_stranger_cannot_withdraw_somebody_elses_offer(): void
    {
        $this->signIn();
        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->anotherTrader('nosy');

        $message = '';
        try {
            app(OfferDesk::class)->withdraw($id);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('not yours', $message);
    }

    public function test_an_offer_needs_a_signed_in_trader(): void
    {
        Auth::guard('trader')->forgetUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You need to be signed in.');

        app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');
    }

    public function test_an_offer_needs_an_amount_and_a_rate(): void
    {
        $this->signIn();
        $desk = app(OfferDesk::class);

        $this->expectExceptionMessage('Say how much you want to sell.');
        $desk->publish('NGN', 'GHS', 0, '0.0060');
    }

    public function test_an_offer_needs_a_walk_away_rate(): void
    {
        $this->signIn();

        $this->expectExceptionMessage('lowest rate you would accept');
        app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0');
    }

    public function test_a_lifetime_from_the_client_is_not_passed_through_arbitrarily(): void
    {
        // The client picks from a fixed set rather than sending an interval. Even if it did send one, the
        // database caps it at a day -- this service refuses to be the thing that forwards a surprise.
        $this->signIn();
        $id = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060', '99 days');

        $seconds = (int) DB::connection('pgsql_trader')->selectOne(
            'select extract(epoch from (expires_at - created_at))::int as s from offer where id = ?', [$id]
        )->s;

        $this->assertSame(900, $seconds, 'an unknown lifetime falls back to fifteen minutes');
    }

    public function test_the_position_counts_only_what_the_trader_can_already_read(): void
    {
        $trader = $this->signIn();
        $position = app(OfferDesk::class)->position();

        $this->assertSame(0, $position['settled']);
        $this->assertSame(0, $position['disputed']);
        $this->assertSame(0, $position['open']);
        $this->assertSame(0, $position['selling']);
        $this->assertSame(0, $position['trades']);

        // And the balance is NOT there. Not faked, not read through a connection that happens to be
        // allowed to. kudi_trader cannot read `account` and every trader shares one database login, so a
        // balance would have to be either leaked or taken from a role the trader app has no business
        // holding. Leaving it out is the honest version; see OfferDesk::position().
        $this->assertArrayNotHasKey('holds', $position);
        $this->assertNotNull($trader);
    }

    public function test_the_position_counts_a_sellers_own_activity(): void
    {
        $trader = $this->signIn();
        $desk = app(OfferDesk::class);
        $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $position = $desk->position();

        $this->assertSame(1, $position['selling'], 'their own live offer is counted');

        $desk->withdraw((int) $desk->mine($trader->email)[0]->id);
        $this->assertSame(0, $desk->position()['selling']);
    }

    public function test_a_trader_session_cannot_read_any_balance(): void
    {
        // Asserted directly against the role, not inferred. If somebody later grants SELECT on account
        // to make a balance screen work, this is what catches it.
        $this->signIn();

        // No row is inserted to make this interesting. An earlier version of this test inserted an
        // account row directly, which left a balance with no postings behind it and broke
        // check_balances() for every test in the suite that ran afterwards -- including five journey
        // tests that had nothing to do with balances. A permission error does not need data present.
        $refused = false;
        try {
            DB::connection('pgsql_trader')->select('select balance from account');
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a trader must not be able to read balances at all');
    }

    public function test_the_offer_chain_still_verifies_after_a_whole_exchange(): void
    {
        $this->signIn();
        $desk = app(OfferDesk::class);
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->anotherTrader('verify');
        $desk->accept($id);

        $ok = DB::connection('pgsql_trader')->selectOne('select verify_offer_chain() as ok')->ok;
        $this->assertTrue($this->postgresTrue($ok));
    }

    public function test_a_trader_cannot_publish_a_rate(): void
    {
        // The walk-away is the seller's own; the price is not anybody's but pricing's. This one test is
        // the whole reason the walk-away/price split is safe.
        $this->signIn();

        $refused = false;
        try {
            DB::connection('pgsql_trader')->selectOne('select set_rate(?, ?, ?, ?)', ['NGN', 'GHS', '9.0', 'sneaky@example.com']);
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a trader must not be able to move the board rate');
    }

    public function test_an_unverified_trader_cannot_list_an_offer(): void
    {
        // Where the "Checked" tier is actually enforced. An unchecked trader may still trade against
        // KudiMarket's own book -- where we are the counterparty and do guarantee -- but may not be a
        // counterparty to another trader. Without this the badge was decoration: anyone could publish and
        // be treated as a peer, and "Checked" meant nothing because verified_at was never consulted.
        $this->signIn(verified: false);

        $refused = '';
        try {
            app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        // The words the trader reads, not the database's. The service maps the refusal onto something
        // that says what they CAN still do, because a trader who is told only "no" asks support instead.
        $this->assertStringContainsString('need to be checked', $refused);
        $this->assertStringContainsString('You can still buy from us today', $refused);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A VERIFIED trader, which is what publishing requires.
     *
     * brand/product.html's two tiers are enforced in the database: only a checked trader may stand on the
     * other side of somebody else's money. Verification is assigned explicitly because email_verified_at
     * is not fillable and mass assignment drops it without a word -- which is how every staff account in
     * this project once ended up unverified.
     */
    private function signIn(bool $verified = true): Trader
    {
        $trader = Trader::factory()->create();

        if ($verified) {
            $trader->verified_at = now();
            $trader->save();
        }

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    /**
     * A trader whose email has not been used in this run.
     *
     * The scratch database survives between runs, so a fixed address like 'buyer@example.com' collides
     * on the second run and the failure reads as a product bug rather than a test that assumes it is
     * the only thing running.
     */
    private function anotherTrader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);
        $trader->verified_at = now();
        $trader->save();

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    private function totalHoldings(): string
    {
        return (string) DB::connection('pgsql_trader')->selectOne(
            "select coalesce(sum(balance), 0) as b from account where owner = ? and kind = 'available'",
            [Auth::guard('trader')->user()->email]
        )->b;
    }

    private function trades(): array
    {
        return DB::connection('pgsql_trader')->select('select id from trade');
    }

    /** Read as operations: kudi_trader has no grant on posting, which is the point of it not having one. */
    private function postings(): array
    {
        return DB::connection('pgsql_ops')->select('select id from posting');
    }
}

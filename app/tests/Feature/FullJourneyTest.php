<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\RateLimiter;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * One trader, one trade, every step, and every chain still verifying at the end.
 *
 * The last assertions are the point. A journey test that only checks the screens passed can still leave
 * a ledger that does not verify, and that is the failure that matters.
 *
 * The plan for this task stopped at a refund, which is the path nobody is waiting for. The happy path is
 * the one the product exists for: a trader pays, somebody confirms the money arrived, the wait elapses,
 * and the naira is released. That path is where the real gap was.
 */
class FullJourneyTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        \DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', 148.2, 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_trade_goes_open_claimed_attested_and_released(): void
    {
        $trader = $this->paidTrade();

        // Operations confirms the money arrived. Without this the trade sits at paid_claimed for ever:
        // the Release button is locked until paid_attested, and nothing could reach that state.
        app(TradeLedger::class)->attest($trader->trade_id, 'ops@kudimarket.com');

        $this->assertSame('paid_attested', $this->stateOf($trader->trade_id));

        // The window is stated, not inherited. These two release tests originally relied on the harness
        // leaving claim_window at '0 seconds', which is shared state in a database that survives between
        // runs: they passed alone and failed in the full suite, and after one run the window stayed at
        // 15 minutes for every test that followed. A test that depends on what another test left behind is
        // a test that reports somebody else's bug.
        $this->withClaimWindow('0 seconds', function () use ($trader): void {
            app(TradeLedger::class)->release($trader->trade_id, 'ops@kudimarket.com');

            $this->assertSame('released', $this->stateOf($trader->trade_id));
        });

        $this->assertLedgerIntact();
    }

    public function test_releasing_a_fiat_only_trade_posts_nothing_and_that_is_correct(): void
    {
        // The first version of this test asserted that release posts something, and failed -- correctly.
        // Every posting branch in _apply_transition is gated on crypto_amount being set, and a GHS<->NGN
        // trade has no crypto leg, so there is no escrowed asset to move. The cedi and the naira are real
        // money in a real bank; the ledger records the agreement and its state, not the fiat.
        //
        // Asserted anyway, in both directions, because it is a property somebody could change by accident:
        // a release that started posting fiat would imply the platform held a ledger liability it never
        // took, and a release that posted for a crypto trade would be the real bug this hides behind.
        $trader = $this->paidTrade();
        $ledger = app(TradeLedger::class);

        $before = $this->platformBalance();
        $ledger->attest($trader->trade_id, 'ops@kudimarket.com');

        $this->withClaimWindow('0 seconds', function () use ($ledger, $trader, $before): void {
            $ledger->release($trader->trade_id, 'ops@kudimarket.com');

            $this->assertSame([], $ledger->postings($trader->trade_id), 'a fiat-only trade escrows nothing, so release posts nothing');
            $this->assertSame($before, $this->platformBalance(), 'no fiat liability was taken or discharged');
            $this->assertSame('released', $this->stateOf($trader->trade_id));
        });

        $this->assertLedgerIntact();
    }

    public function test_release_is_refused_before_the_claim_window_has_elapsed(): void
    {
        // The window is the only thing stopping the platform taking a buyer's money out of escrow the
        // moment it is confirmed. The PHP harness sets claim_window to '0 seconds' so ordinary tests are
        // not held up, which means no PHP test had ever exercised this guard at all. It is set for real
        // here, and put back afterwards -- trade_policy is shared state in a database that survives
        // between runs, and a window left at 15 minutes would quietly break every later release test.
        $trader = $this->paidTrade();
        $ledger = app(TradeLedger::class);
        $ledger->attest($trader->trade_id, 'ops@kudimarket.com');

        $this->withClaimWindow('15 minutes', function () use ($ledger, $trader): void {
            $refusal = '';

            try {
                $ledger->release($trader->trade_id, 'ops@kudimarket.com');
            } catch (\RuntimeException $e) {
                $refusal = $e->getMessage();
            }

            $this->assertStringContainsStringIgnoringCase('claim window', $refusal, "ledger said: {$refusal}");
            $this->assertSame('paid_attested', $this->stateOf($trader->trade_id), 'nothing moved');
        });
    }

    public function test_release_succeeds_once_the_window_has_elapsed(): void
    {
        $trader = $this->paidTrade();
        $ledger = app(TradeLedger::class);
        $ledger->attest($trader->trade_id, 'ops@kudimarket.com');

        // Zero seconds: the window has elapsed the instant it is confirmed. The release guard is the same
        // guard in both tests; only the clock differs, which is the point.
        $this->withClaimWindow('0 seconds', function () use ($ledger, $trader): void {
            $ledger->release($trader->trade_id, 'ops@kudimarket.com');

            $this->assertSame('released', $this->stateOf($trader->trade_id));
        });

        $this->assertLedgerIntact();
    }

    public function test_a_trader_still_cannot_attest_or_release_their_own_payment(): void
    {
        // The whole reason attestation exists. Both refusals are checked through the trader connection, not
        // through the absence of a route, because a route added later would otherwise reach them.
        $trader = $this->paidTrade();

        foreach ([
            'attest' => ['paid_claimed', 'paid_attested'],
            'release' => ['paid_attested', 'released'],
        ] as $step => [$from, $to]) {
            $refused = false;

            try {
                \DB::connection('pgsql_trader')->selectOne(
                    'select transition_trade(?, ?, ?, ?, ?) as ok',
                    [$trader->trade_id, $from, $to, "trader:{$trader->trade_id}:{$step}", $trader->email]
                );
            } catch (\Throwable) {
                $refused = true;
            }

            $this->assertTrue($refused, "a trader must not be able to {$step} their own trade");
        }

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id));
    }

    public function test_a_disputed_trade_can_still_be_refunded_and_stays_verifiable(): void
    {
        $trader = $this->paidTrade();

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'The naira never arrived at all.']);
        $this->assertSame('disputed', $this->stateOf($trader->trade_id));

        app(TradeLedger::class)->resolve($trader->trade_id, 'disputed', 'resolved_refund', 'ops@kudimarket.com');

        $this->assertSame('resolved_refund', $this->stateOf($trader->trade_id));
        $this->assertLedgerIntact();
    }

    public function test_the_narrative_a_trader_wrote_survives_the_whole_journey(): void
    {
        // The reason is the reason anyone reads the record for, so it has to still be there, word for
        // word, after the trade has been adjudicated.
        $trader = $this->paidTrade();
        $reason = 'I sent the cedi at 09:14 and it has not arrived four hours later.';

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => $reason]);
        app(TradeLedger::class)->resolve($trader->trade_id, 'disputed', 'resolved_refund', 'ops@kudimarket.com');

        $stored = \DB::connection('pgsql_trader')->selectOne(
            'select narrative from trade_event where trade_id = ? and to_state = ?',
            [$trader->trade_id, 'disputed']
        )->narrative;

        $this->assertSame($reason, $stored);
        $this->assertLedgerIntact();
    }

    public function test_every_chain_verifies_after_a_whole_day_of_trading(): void
    {
        // The plan's stated purpose for this task: prove the design lock and the ledger still hold at the
        // end. Mixed trades rather than one, because a chain that only verifies when every trade took the
        // same path is not verified.
        foreach ([200000, 150000, 75000, 400000] as $amount) {
            $this->paidTrade($amount);
        }

        $this->assertLedgerIntact();
    }

    // ---------------------------------------------------------------- helpers

    /** A signed-in trader with a trade that has been opened and claimed. */
    private function paidTrade(int $amount = 200000): object
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $trader->trade_id = app(TradeDesk::class)->openAgainstUs($trader->email, $amount, 'NGN');
        $this->post("/trade/{$trader->trade_id}/claim");

        return $trader;
    }

    /**
     * Every assertion that matters, in one place, so no journey test can quietly skip it.
     *
     * The ledger has to balance, all three chains have to verify, and no trade may be left unanchored.
     * An unanchored trade is one that exists with no chained event -- the exact hole 005 was written to
     * close, and worth re-asserting on every journey rather than once.
     */
    private function assertLedgerIntact(): void
    {
        // As operations, not as the trader. check_balances is granted to kudi_app and kudi_ops but not to
        // kudi_trader, which is right: a trader has no business checking the books. The first version read
        // this through the trader connection and failed with "permission denied for function
        // check_balances".
        $ops = \DB::connection('pgsql_ops');

        $this->assertSame(
            0,
            (int) $ops->selectOne('select count(*) as n from check_balances()')->n,
            'cached balances disagree with the postings'
        );

        foreach (['verify_chain', 'verify_trade_chain', 'verify_rate_chain'] as $chain) {
            $this->assertTrue(
                $this->postgresTrue($ops->selectOne("select {$chain}() as ok")->ok),
                "{$chain}() did not verify"
            );
        }

        $report = $ops->selectOne('select * from integrity_report');

        $this->assertTrue($this->postgresTrue($report->ledger_chain_ok), 'integrity report says the ledger chain is broken');
        $this->assertTrue($this->postgresTrue($report->trade_chain_ok), 'integrity report says a trade chain is broken');
        $this->assertTrue($this->postgresTrue($report->rate_chain_ok), 'integrity report says the rate chain is broken');
        $this->assertSame(0, (int) $report->unanchored_trades, 'a trade exists with no chained event');
    }

    /**
     * Run a block with a real claim window, then put the shared policy back.
     *
     * trade_policy is one row per trade type, and this database is shared and survives between runs, so
     * leaving it at 15 minutes would break every later test that releases. Restored in a finally, and the
     * restore is verified rather than assumed.
     */
    private function withClaimWindow(string $window, callable $work): void
    {
        $original = \DB::connection('pgsql_admin')->selectOne(
            "select claim_window from trade_policy where trade_type = 'platform_inventory'"
        )->claim_window;

        \DB::connection('pgsql_admin')->update(
            "update trade_policy set claim_window = ? where trade_type = 'platform_inventory'",
            [$window]
        );

        try {
            $work();
        } finally {
            \DB::connection('pgsql_admin')->update(
                "update trade_policy set claim_window = ? where trade_type = 'platform_inventory'",
                [$original]
            );

            $this->assertSame(
                $original,
                \DB::connection('pgsql_admin')->selectOne(
                    "select claim_window from trade_policy where trade_type = 'platform_inventory'"
                )->claim_window,
                'the claim window was left changed for every later test'
            );
        }
    }

    private function stateOf(int $tradeId): string
    {
        return \DB::connection('pgsql_trader')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }

    /**
     * The platform's naira balance, read as operations.
     *
     * Not read through the trader connection: kudi_trader has no grant on the account table, which is the
     * point -- a trader must not be able to read anybody's balance. The first version of this helper used
     * the trader connection and died with "permission denied for table account".
     */
    private function platformBalance(): string
    {
        return \DB::connection('pgsql_ops')->selectOne(
            "select coalesce(sum(balance), 0) as b from account where owner = 'platform' and asset = 'NGN'"
        )->b;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sweep that settles trades nobody has to chase.
 *
 * paid_attested -> released has actor 'system' in the ledger. Before this, nothing performed it: every
 * attested trade sat at paid_attested for ever with money in escrow. The trade screen's copy had been
 * softened to stop promising automatic settlement, which was honest and also hid a missing mechanism.
 */
final class ReleaseDueTradesTest extends TestCase
{
    public function test_it_releases_nothing_before_the_claim_window_closes(): void
    {
        [$tradeId] = $this->attestedTrade('window-open');
        $this->admin()->statement(
            "update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory'"
        );

        $this->artisan('trades:release-due')->assertSuccessful();

        $this->assertSame('paid_attested', $this->state($tradeId), 'the window is the guarantee, not a delay');
    }

    public function test_it_releases_an_attested_trade_once_the_window_has_closed(): void
    {
        [$tradeId] = $this->attestedTrade('window-closed');
        $this->closeWindow();

        $this->artisan('trades:release-due')->assertSuccessful();

        $this->assertSame('released', $this->state($tradeId));
    }

    public function test_running_it_twice_settles_nothing_the_second_time(): void
    {
        // Idempotent by construction: the sweep only looks at trades still in paid_attested. Run it as
        // often as you like, which is what lets the scheduler be every minute without thinking about it.
        $this->attestedTrade('twice-a');
        $this->attestedTrade('twice-b');
        $this->closeWindow();

        $this->artisan('trades:release-due')->assertSuccessful();

        // Scoped to this block's own trades. The seeded fixtures are released by earlier tests in the
        // suite, so a global count would be reporting on them rather than on the sweep.
        $this->assertSame(2, (int) DB::connection('pgsql_ops')->selectOne(
            "select count(*) from trade_event where to_state = 'released'
               and trade_id in (select id from trade where buyer like 'sweep-twice-%')"
        )->count);

        $events = (int) DB::connection('pgsql_ops')->selectOne('select count(*) from trade_event')->count;

        $this->artisan('trades:release-due')->assertSuccessful();
        $this->artisan('trades:release-due')->assertSuccessful();

        $this->assertSame(
            $events,
            (int) DB::connection('pgsql_ops')->selectOne('select count(*) from trade_event')->count,
            'no further events were appended by the empty runs'
        );
    }

    public function test_the_ledger_still_balances_after_the_sweep_moves_money(): void
    {
        $this->attestedTrade('balanced');
        $this->closeWindow();

        $this->artisan('trades:release-due')->assertSuccessful();

        $this->assertSame(
            0,
            (int) DB::connection('pgsql_ops')->selectOne('select count(*) from check_balances()')->count,
            'a sweeper that moved money without balancing would be a second ledger'
        );
    }

    public function test_a_disputed_trade_is_left_to_the_dispute_and_never_swept(): void
    {
        // The sweeper only reads trades in paid_attested. A dispute is adjudicated by operations, and a
        // trade that is sitting in disputed has money held deliberately -- settling it here would take the
        // other side of a live case.
        // Raised by the BUYER, which is the point: paid_attested -> disputed has actor 'either', meaning
        // an actual party to the trade. 'platform' cannot raise it on its own trade, and a dispute only a
        // counterparty can open is not a dispute.
        [$tradeId, $buyer] = $this->attestedTrade('disputed');
        $this->admin()->statement(
            "select transition_trade({$tradeId}, 'paid_attested', 'disputed', 'swt-d', ?)",
            [$buyer]
        );
        $this->closeWindow();

        $this->artisan('trades:release-due')->assertSuccessful();

        $this->assertSame('disputed', $this->state($tradeId));
    }

    public function test_it_is_scheduled_every_minute(): void
    {
        // Without this the command is dead code that works when someone remembers to type it, which is the
        // situation this whole commit exists to end.
        $schedule = app(Schedule::class);
        $events = collect($schedule->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'trades:release-due'));

        $this->assertCount(1, $events, 'the sweep is registered');
        $this->assertSame('* * * * *', $events->first()->expression, 'once a minute');
    }

    private function closeWindow(): void
    {
        $this->admin()->statement("update trade_policy set claim_window = '0 seconds'");
    }

    /**
     * Fixture statements run as the operator.
     *
     * kudi_ops cannot mint deposits and cannot edit trade_policy, and both of those are controls the suite
     * asserts on elsewhere ("ops role cannot mint deposits"). So the fixtures do not go through the
     * operations connection: they would either fail or quietly widen a boundary that exists on purpose.
     * The COMMAND still runs as kudi_ops, which is the privilege that actually matters here.
     */
    private function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }

    /**
     * A trade on our own book, taken all the way to paid_attested: escrowed, claimed, and attested.
     *
     * @return array{int, string} the trade id, and the buyer's email
     */
    private function attestedTrade(string $label): array
    {
        // No users row: kudi_ops has no grant on that table, which is correct -- the operations role has no
        // business writing the people table, and a test that needs one to exist is a test reaching for
        // privilege it does not have.
        $buyer = sprintf('sweep-%s-%s@example.com', $label, bin2hex(random_bytes(4)));

        // The float belongs to PLATFORM: on our own book we are the seller, so it is our balance that is
        // reserved on escrow. Funding the buyer instead fails with "insufficient funds", which is a
        // confusing way to learn whose money moves first.
        // Minted on the admin connection, because ledger_deposit is granted to kudi_chain alone and the
        // suite asserts that operations CANNOT mint deposits -- "ops role cannot mint deposits". A fixture
        // that needed ops to mint money would either fail or quietly widen a control that exists on
        // purpose, so it goes through the operator identity the way every other test here does.
        $this->admin()->selectOne(
            'select ledger_deposit(?, ?, ?, ?) as id',
            ['platform', 'USDT', 20000, 'sweep-plat-'.bin2hex(random_bytes(6))]
        );

        $id = (int) $this->admin()->selectOne(
            "select create_fiat_trade('platform', ?, null, null, 'NGN', 200000, 148.2, 'platform_inventory', 'GHS', 'NGN', 'GHS') as id",
            [$buyer]
        )->id;

        $this->admin()->statement("update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory'");
        $this->admin()->statement("select transition_trade({$id}, 'open', 'paid_claimed', 'sweep-c-{$label}', ?)", [$buyer]);
        $this->admin()->statement("select ops_transition({$id}, 'paid_claimed', 'paid_attested', 'sweep-a-{$label}', 'ops@kudimarket.com')");

        return [$id, $buyer];
    }

    private function state(int $tradeId): string
    {
        return (string) DB::connection('pgsql_ops')
            ->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }
}

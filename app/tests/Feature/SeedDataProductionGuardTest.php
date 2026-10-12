<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reference-data migration must never carry demo content into production.
 *
 * The seed migration inserts named demo traders, funded balances, a fabricated rate and four trades
 * (one disputed, one settled) "so the console has something real to show". In development that is
 * exactly what it is for. In production those rows would be indistinguishable from real customers
 * and real money: a ledger that records a disputed trade that never happened cannot be held up in
 * front of a regulator as the truth.
 *
 * The guard is one line -- the migration returns before the demo content when running in production.
 * This test exists so that line cannot be deleted or reworded into oblivion by someone tidying up,
 * because nothing in the normal test run sees it (tests run in the 'testing' environment, where the
 * demo content IS inserted). It re-runs the migration under a production environment and asserts the
 * ledger, the trades and the rate card are exactly as they were.
 */
final class SeedDataProductionGuardTest extends TestCase
{
    private object $migration;

    public function test_the_seed_migration_does_not_write_demo_content_in_production(): void
    {
        $migration = require database_path('migrations/2026_01_01_000300_seed_reference_data.php');
        $this->migration = $migration;

        $tradesBefore = DB::table('trade')->count();
        $ledgerBefore = DB::table('ledger_tx')->count();
        $ratesBefore = DB::table('rate_card')->count();

        $originalEnv = app()->environment();
        $originalDefault = DB::getDefaultConnection();
        app()->instance('env', 'production');

        try {
            // The migrator runs a migration inside usingConnection(), which temporarily swaps the
            // default connection to the one migrate was pointed at -- normally pgsql_admin, the
            // operator role. Without that, the migration's DB:: calls run as kudi_app_login and are
            // refused before the guard is even reached. Reproduce that swap so the guard itself is
            // what is under test.
            DB::setDefaultConnection('pgsql_admin');
            $this->migration->up();
        } finally {
            DB::setDefaultConnection($originalDefault);
            app()->instance('env', $originalEnv);
        }

        $this->assertSame($tradesBefore, DB::table('trade')->count());
        $this->assertSame($ledgerBefore, DB::table('ledger_tx')->count());
        $this->assertSame($ratesBefore, DB::table('rate_card')->count());

        // Reference data is not demo content: the currencies themselves must exist in production.
        $this->assertDatabaseHas('asset', ['code' => 'GHS']);
        $this->assertDatabaseHas('asset', ['code' => 'NGN']);
        $this->assertDatabaseHas('asset', ['code' => 'USDT']);
    }
}

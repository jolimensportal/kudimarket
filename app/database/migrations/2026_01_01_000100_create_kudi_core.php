<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads the ledger and trade state machine as one unit.
 *
 * The SQL files are the source of truth and are tested directly by kudi-core/run_tests.sh.
 * They are not translated into Eloquent: the invariants live below the application, and the
 * whole point of the exercise is that no application bug can reach a balance.
 *
 * Runs on the admin connection (cluster superuser) because it creates roles and extensions.
 */
return new class extends Migration
{
    private function sql(string $file): string
    {
        $path = base_path('../kudi-core/'.$file);

        if (! is_readable($path)) {
            throw new RuntimeException("missing SQL file: {$path}");
        }

        return file_get_contents($path);
    }

    public function up(): void
    {
        foreach (['001_core.sql', '002_trade_types.sql', '003_fiat_leg.sql', '004_state_chain.sql', '005_creation_anchor.sql', '006_rate_card.sql', '007_trader_role.sql'] as $file) {
            DB::unprepared($this->sql($file));
        }
    }

    public function down(): void
    {
        DB::unprepared('drop schema public cascade; create schema public;');
    }
};

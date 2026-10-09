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
        // 008 and 009 are in this list now. They were not, and neither omission was cosmetic: 008 creates
        // the trader table, so a database built by `php artisan migrate` had no traders and no way to
        // sign in as one; 009 creates the offer book, so the seller could not list anything. Both existed
        // on this machine only because the files had been applied by hand earlier, which is exactly the
        // kind of gap a fresh checkout turns into a silent failure -- and the first sign of 009's absence
        // was a trader told "We could not publish that offer" with no way to find out why.
        foreach (['001_core.sql', '002_trade_types.sql', '003_fiat_leg.sql', '004_state_chain.sql', '005_creation_anchor.sql', '006_rate_card.sql', '007_trader_role.sql', '008_traders.sql', '009_offer_book.sql', '010_verification.sql', '011_rate_direction.sql'] as $file) {
            DB::unprepared($this->sql($file));
        }
    }

    public function down(): void
    {
        DB::unprepared('drop schema public cascade; create schema public;');
    }
};

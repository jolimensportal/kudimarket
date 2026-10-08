<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app role gets Laravel's own tables and nothing else.
 *
 * The point of the role split is that this connection cannot touch money. It can read trades and
 * call the ledger functions, but it has no privilege on account, posting, ledger_tx or trade_event,
 * so no bug in the application can write a balance. This migration makes that boundary explicit
 * rather than accidental: application tables are granted here, ledger tables never are.
 */
return new class extends Migration
{
    private array $appTables = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
    ];

    public function up(): void
    {
        $existing = DB::selectOne(
            "select string_agg(tablename, ',') as names from pg_tables where schemaname = 'public'"
        )->names ?? '';

        $tables = array_values(array_filter($this->appTables, fn ($t) => str_contains((string) $existing, $t)));

        foreach ($tables as $table) {
            DB::unprepared(sprintf(
                'grant select, insert, update, delete on table %s to kudi_app', $table
            ));
            // Sequences too: an identity column needs its sequence granted separately, and forgetting
            // it produces "permission denied for sequence" rather than anything about money.
            DB::unprepared(sprintf(
                "grant usage, select on sequence %s_id_seq to kudi_app", $table
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->appTables as $table) {
            DB::unprepared(sprintf('revoke all on table %s from kudi_app', $table));
            DB::unprepared(sprintf('revoke all on sequence %s_id_seq from kudi_app', $table));
        }
    }
};

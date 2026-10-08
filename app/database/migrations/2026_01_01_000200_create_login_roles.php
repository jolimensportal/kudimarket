<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Login roles that inherit the NOLOGIN roles created by the SQL core.
 *
 * The core defines kudi_app, kudi_ops and kudi_chain as NOLOGIN so that nothing can log in as
 * them. These wrappers are the only credentials in the system: the web app gets the app login,
 * Filament's console gets the ops login, and the chain watcher gets the chain login.
 */
return new class extends Migration
{
    public function up(): void
    {
        // kudi_pricing joined with the rate card in 006. It publishes our own price and nothing
        // else: it cannot settle a trade, resolve a dispute, mint a deposit, or read a balance, so
        // whoever can reprice the book is never whoever judges it.
        foreach (['kudi_app', 'kudi_ops', 'kudi_chain', 'kudi_pricing'] as $role) {
            DB::unprepared(sprintf(
                "do \$\$ begin if not exists (select from pg_roles where rolname='%s_login') then create role %s_login login; end if; end \$\$;",
                $role,
                $role
            ));
            DB::unprepared(sprintf('grant %s to %s_login;', $role, $role));
            DB::unprepared(sprintf('grant connect on database %s to %s_login;', DB::getDatabaseName(), $role));
        }
    }

    public function down(): void
    {
        foreach (['kudi_app_login', 'kudi_ops_login', 'kudi_chain_login', 'kudi_pricing_login'] as $role) {
            DB::unprepared(sprintf('drop role if exists %s;', $role));
        }
    }
};

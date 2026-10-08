<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Builds kudi_test from the SQL in kudi-core, exactly as the dev database was built.
 *
 * The suite creates a real database with the real schema rather than mocking the ledger. Mocking a
 * double-entry ledger is how you end up with tests that pass while the ledger is wrong.
 *
 * Roles are cluster-wide, so they are created once and left alone; only the database is rebuilt. That
 * means every test file sees the same roles the product uses, including their limits.
 */
trait BuildsLedgerDatabase
{
    private static bool $built = false;

    /** The SQL files, in the order they must be applied. 006 must follow 001-005. */
    private const CORE_FILES = [
        '001_core.sql',
        '002_trade_types.sql',
        '003_fiat_leg.sql',
        '004_state_chain.sql',
        '005_creation_anchor.sql',
        '006_rate_card.sql',
        '007_trader_role.sql',
    ];

    protected function buildLedgerDatabase(): void
    {
        if (self::$built) {
            return;
        }

        $core = base_path('../kudi-core');

        if (! is_dir($core)) {
            throw new RuntimeException("kudi-core not found at {$core}");
        }

        $database = 'kudi_test';
        $admin = config('database.connections.pgsql_admin');

        // Roles live at cluster level, so they survive a database rebuild. Created once, if missing.
        $this->psql($admin, 'postgres', <<<'SQL'
            do $$ begin
              if not exists (select from pg_roles where rolname='kudi_app')      then create role kudi_app nologin;      end if;
              if not exists (select from pg_roles where rolname='kudi_ops')      then create role kudi_ops nologin;      end if;
              if not exists (select from pg_roles where rolname='kudi_chain')    then create role kudi_chain nologin;    end if;
              if not exists (select from pg_roles where rolname='kudi_pricing')  then create role kudi_pricing nologin;  end if;
              if not exists (select from pg_roles where rolname='kudi_trader')   then create role kudi_trader nologin;   end if;
              if not exists (select from pg_roles where rolname='kudi_app_login')     then create role kudi_app_login login;     end if;
              if not exists (select from pg_roles where rolname='kudi_ops_login')     then create role kudi_ops_login login;     end if;
              if not exists (select from pg_roles where rolname='kudi_pricing_login') then create role kudi_pricing_login login; end if;
              if not exists (select from pg_roles where rolname='kudi_trader_login')  then create role kudi_trader_login login;  end if;
            end $$;
            grant kudi_app to kudi_app_login;
            grant kudi_ops to kudi_ops_login;
            grant kudi_pricing to kudi_pricing_login;
            grant kudi_trader to kudi_trader_login;
        SQL);

        // The login roles need to reach the database before it exists only in the sense that CONNECT
        // is granted per database, so it is granted after the create.
        $this->psql($admin, 'postgres', "drop database if exists {$database}");
        $this->psql($admin, 'postgres', "create database {$database}");
        $this->psql($admin, 'postgres', "grant connect on database {$database} to kudi_app_login, kudi_ops_login, kudi_pricing_login, kudi_trader_login");

        foreach (self::CORE_FILES as $file) {
            $sql = @file_get_contents("{$core}/{$file}");

            // 007 does not exist yet while Task 1 runs. Skipped rather than failed, because the task
            // that creates it is Task 2 and this one is only about the database.
            if ($sql === false) {
                continue;
            }

            $this->psql($admin, $database, $sql);
        }

        $this->psql($admin, $database, "insert into asset values ('GHS',2),('NGN',2),('USDT',6)");
        $this->psql($admin, $database, "update trade_policy set claim_window = '0 seconds'");

        self::$built = true;
    }

    private function psql(array $connection, string $database, string $sql): void
    {
        $command = sprintf(
            'psql -X -q -v ON_ERROR_STOP=1 -h %s -p %s -U %s -d %s -c %s 2>&1',
            escapeshellarg($connection['host'] ?: '/tmp'),
            escapeshellarg((string) ($connection['port'] ?: 5433)),
            escapeshellarg($connection['username']),
            escapeshellarg($database),
            escapeshellarg($sql)
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        if ($status !== 0) {
            throw new RuntimeException("psql failed against {$database}: ".implode("\n", $output));
        }
    }
}

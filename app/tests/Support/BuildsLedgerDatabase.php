<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
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
        '008_traders.sql',
        '009_offer_book.sql',
        '010_verification.sql',
        '011_rate_direction.sql',
        '012_trade_pair.sql',
        '013_release_sweeper.sql',
        '014_segregation.sql',
        '015_trader_identity.sql',
        '016_cdd.sql',
    ];

    /**
     * The file list is written out in THREE places -- here, kudi-core/run_tests.sh, and the
     * 2026_01_01_000100_create_kudi_core migration -- and they must agree with what is on disk.
     *
     * 016_cdd.sql shipped into all three and the PHP suite still failed with "relation cdd_status does
     * not exist", because this list was missed while fixing the other two. A missing entry fails
     * confusingly and late: the SQL suite passes, and the application fails on a table that plainly
     * exists in the repository. coreSqlFilesAreListedEverywhereTheyAreLoaded() is the cheap guard.
     *
     * @return list<string>
     */
    public static function coreFiles(): array
    {
        return (new \ReflectionClass(self::class))->getConstant('CORE_FILES');
    }

    protected function buildLedgerDatabase(): void
    {
        if (self::$built) {
            return;
        }

        // One database for the whole run, not one per test file. set_rate() refuses to backdate and the
        // unique index refuses two rates at the same moment, so a second publish of the same pair in
        // the same transaction is an error rather than a no-op. Rebuilding per file would make the
        // second file's setup fail for a reason that has nothing to do with what it is testing.
        //
        // The cost is that test files share one database and must not assume they are alone. They do
        // not: each creates its own traders and trades and asserts on those ids.
        if (self::databaseExists()) {
            self::$built = true;

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
            $path = "{$core}/{$file}";

            // Skipped rather than failed, so a task can run against the schema as it exists today
            // rather than the schema the whole plan intends. The alternative is every task failing
            // because a later file has not been written yet.
            if (! is_readable($path)) {
                continue;
            }

            $this->psql($admin, $database, (string) file_get_contents($path));
        }

        $this->psql($admin, $database, "insert into asset values ('GHS',2),('NGN',2),('USDT',6)");
        $this->psql($admin, $database, "update trade_policy set claim_window = '0 seconds'");

        // Trades are written by kudi_trader and read by kudi_app; the console reads and adjudicates as
        // kudi_ops. Every connection the application has needs to be able to reach the rows it is
        // responsible for, or a test passes against a role the product never uses.
        $this->psql($admin, $database, 'grant select, insert, update on trader to kudi_app, kudi_ops');

        // And the sequence behind its identity column. A table grant without its sequence grants
        // produces "permission denied for sequence", which is a confusing way to learn nothing.
        $this->psql($admin, $database, 'grant usage, select on sequence trader_id_seq to kudi_app, kudi_ops');

        // pricing_staff's sequence is granted by the migration that creates the table, not here: this runs
        // before that migration, and a grant for a relation that does not exist yet is an error rather than
        // a no-op.

        $this->buildApplicationTables($admin, $database);

        self::$built = true;
    }

    /**
     * Give the scratch database the APPLICATION tables too, not just the financial core.
     *
     * Until this existed, kudi_test held the ledger and nothing else: no users, no sessions, no Filament
     * tables. Every trader test was unaffected because none of them needed them -- and the operations
     * console therefore could not be tested at all, which is how two fatal errors shipped in the trades
     * page while 76 tests passed. The console is half the product; the harness has to be able to hold it.
     *
     * Runs artisan migrate, with the core-loading migration recorded as already applied. That migration
     * loads the same kudi-core SQL this trait just loaded by hand, and running it again fails on objects
     * that already exist -- so it is marked rather than executed, and everything after it runs normally.
     */
    private function buildApplicationTables(array $admin, string $database): void
    {
        $this->psql($admin, $database, 'create table if not exists migrations (id serial primary key, migration varchar(255) not null, batch integer not null)');
        $this->psql($admin, $database, 'create unique index if not exists migrations_migration_unique on migrations(migration)');

        // ONLY the core loader is marked as applied. Marking the users, cache and jobs migrations too --
        // which is what this did first -- means those tables are never created and every console test
        // dies with "relation users does not exist". They are ordinary migrations and must run.
        foreach ([
            '2026_01_01_000100_create_kudi_core',
        ] as $applied) {
            $this->psql(
                $admin,
                $database,
                sprintf(
                    "insert into migrations (migration, batch) values ('%s', 0) on conflict (migration) do nothing",
                    $applied
                )
            );
        }

        // --database=pgsql_admin, on purpose. The default connection is kudi_app_login, which correctly
        // has no privilege on the migrations table -- the application must not be able to alter its own
        // schema. Creating tables is an operator action, so the migrator runs as the operator. Without
        // this it dies with "permission denied for table migrations", which reads like a permissions
        // problem rather than the harness choosing the wrong identity.
        $exit = Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_admin']);

        if ($exit !== 0) {
            throw new RuntimeException(
                'migrate failed against the scratch database: '.Artisan::output()
            );
        }

        // The application tables are created by the OPERATOR, because the application must not be able to
        // alter its own schema -- but that leaves them owned by a role the application does not use, and
        // an owner is not an implicit grant. So kudi_app is granted the DML it actually performs.
        //
        // This only bites on a genuinely fresh build. A scratch database left over from an earlier run
        // already had these grants, which is why dropping kudi_test and rebuilding it was enough to turn
        // 47 tests into "permission denied for table users" -- an error that reads like an authentication
        // bug and is nothing of the kind. The migrations table is deliberately NOT granted: that is the
        // permission the operator identity exists to withhold.
        $this->psql($admin, $database, sprintf(
            'grant select, insert, update, delete on %s to kudi_app',
            implode(', ', ['users', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
                'failed_jobs', 'password_reset_tokens'])
        ));

        // Belt and braces: a console test that cannot find users would fail for a reason that reads like
        // an authentication bug, which is a miserable thing to debug at 2am.
        foreach (['users', 'sessions', 'cache'] as $table) {
            if (trim($this->scalar($admin, $database, sprintf(
                "select count(*) from information_schema.tables where table_schema='public' and table_name='%s'",
                $table
            ))) !== '1') {
                throw new RuntimeException("expected table {$table} in the scratch database and it is not there");
            }
        }
    }

    /**
     * Whether the scratch database is already there.
     *
     * Checked before rebuilding because a fresh database means empty rate_card, and the rate-card
     * tests legitimately need to publish a rate before others can read it. This is a deliberate
     * trade: shared state, in exchange for a suite that can assert on a rate existing.
     */
    private static function databaseExists(): bool
    {
        $command = sprintf(
            'psql -X -q -t -A -h /tmp -p 5433 -U %s -d postgres -c %s 2>&1',
            escapeshellarg((string) config('database.connections.pgsql_admin.username')),
            escapeshellarg('select 1 from pg_database where datname = \'kudi_test\'')
        );

        $output = [];
        exec($command, $output, $status);

        return $status === 0 && trim(implode('', $output)) === '1';
    }

    /**
     * psql() for a query whose answer matters -- psql() itself discards stdout, which is right for DDL.
     *
     * Named scalar() rather than query() because TestCase already declares a public query(), and a trait
     * cannot narrow an inherited method's visibility: naming it query() is a fatal at class load.
     */
    private function scalar(array $connection, string $database, string $sql): string
    {
        $command = sprintf(
            'psql -X -q -t -A -h %s -p %s -U %s -d %s -c %s 2>&1',
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
            throw new RuntimeException("psql query failed against {$database}: ".implode("\n", $output));
        }

        return implode('', $output);
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

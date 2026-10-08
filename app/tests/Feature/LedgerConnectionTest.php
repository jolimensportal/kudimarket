<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * The suite must run against the same Postgres the product runs against.
 *
 * Under the default sqlite configuration none of the stored procedures, roles or triggers exist, so a
 * test can pass while proving nothing about money. These two assertions are the canary for that whole
 * class of lie: if they ever fail, the suite has been pointed somewhere harmless.
 */
class LedgerConnectionTest extends TestCase
{
    use InterpretsPostgresBooleans;

    public function test_the_ledger_functions_exist_in_the_test_database(): void
    {
        // The pdo_pgsql driver returns Postgres booleans as '1' or '', not as 't'. Normalise here
        // rather than in every assertion, and say so, because a test that expects 't' from this driver
        // fails for a reason that has nothing to do with the ledger.
        $this->assertTrue($this->postgresTrue($this->scalar('select verify_chain()')));
    }

    public function test_the_app_role_cannot_write_a_balance_directly(): void
    {
        $out = $this->raw('update account set balance = 999999 where id = (select min(id) from account)');

        $this->assertStringContainsString('permission denied', $out);
    }

    public function test_the_app_role_cannot_write_a_posting_directly(): void
    {
        $out = $this->raw("insert into posting(tx_id, account_id, asset, amount) values (1, (select min(id) from account), 'GHS', 1)");

        $this->assertStringContainsString('permission denied', $out);
    }

    public function test_the_database_is_the_postgres_one_and_not_sqlite(): void
    {
        // Belt and braces. If someone reverts the connection to sqlite, this fails by name rather
        // than by producing a confusing error three tests later.
        $this->assertSame('pgsql', config('database.default'));
        $this->assertStringNotContainsString('sqlite', (string) config('database.connections.pgsql.driver'));
    }

    /** Reads the single value of a single-column query, whatever the driver named the column. */
    private function scalar(string $sql): string
    {
        $row = \DB::connection('pgsql')->selectOne($sql);
        $values = array_values((array) $row);

        return (string) ($values[0] ?? '');
    }

    /** Runs SQL as the app role and returns the error text, or '' if it unexpectedly succeeded. */
    private function raw(string $sql): string
    {
        try {
            \DB::connection('pgsql')->unprepared($sql);
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Tests;

/**
 * Reading a Postgres boolean from PHP.
 *
 * The same query returns a PHP bool on one connection and the string 't' or '1' on another, depending on
 * how that connection's PDO options are set. Both are correct; neither is portable, and a test that
 * hard-codes one of them starts failing when somebody adds a connection rather than when the code is
 * broken.
 *
 * That is not hypothetical. LedgerConnectionTest discovered it first and wrote its own helper; the journey
 * test then asserted `=== 't'` against integrity_report, which is a real boolean column, and passed only
 * because it happened to read it through the trader connection. Two copies of the same workaround, and
 * the second one was quietly testing the driver instead of the ledger.
 */
trait InterpretsPostgresBooleans
{
    /**
     * Whether a value read out of Postgres means true.
     *
     * @param  mixed  $value  whatever the driver handed back
     */
    protected function postgresTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array($value, ['t', 'true', '1'], true);
    }
}

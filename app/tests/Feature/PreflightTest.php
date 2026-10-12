<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CommissionDesk;
use Tests\TestCase;

/**
 * The readiness gate, as executable checks.
 *
 * kudi:preflight is the staged runbook (docs/production-runbook.md) turned into one command, so an
 * operator's answer to "is this instance ready to open?" is a command's exit code rather than a
 * checklist remembered from prose. It reads real functions and real rows: the hash chain, the
 * balances, the published rate and the set commission -- all of which exist on the shared test
 * database because they are exactly what the product shows a trader.
 *
 * The production gate is part of the check that must never be relaxed (an instance running with
 * APP_ENV=local and debugging on is not ready by definition), and the rest of the checks are real
 * against kudi_test: a healthy chain, agreeing balances, both rates and a commission are present.
 */
final class PreflightTest extends TestCase
{
    public function test_it_never_passes_outside_production(): void
    {
        $this->artisan('kudi:preflight')
            ->assertFailed()
            ->expectsOutputToContain('running in production');
    }

    public function test_a_healthy_production_view_passes_all_checks(): void
    {
        $originalEnv = app()->environment();
        $originalDebug = config('app.debug');

        // A healthy production instance has a commission charged on its book. The shared test
        // database does not (each file that needs one sets its own), so set it the way a real
        // deployment would -- through the pricing desk -- and reset it afterwards.
        $commission = app(CommissionDesk::class);
        $commission->set('platform_inventory', 100, 'buyer', 'preflight@kudimarket.com', 'preflight test');

        try {
            app()->instance('env', 'production');
            config(['app.debug' => false]);

            $this->artisan('kudi:preflight')
                ->assertSuccessful()
                ->expectsOutputToContain('8 checks');
        } finally {
            app()->instance('env', $originalEnv);
            config(['app.debug' => $originalDebug]);
            $commission->set('platform_inventory', 0, 'buyer', 'preflight@kudimarket.com', 'reset for other tests');
        }
    }
}

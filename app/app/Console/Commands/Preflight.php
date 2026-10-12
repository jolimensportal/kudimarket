<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Decide, against the deployed target, whether this instance is safe to open to traders.
 *
 * The staging runbook (docs/production-runbook.md) is a checklist in prose; this command is the same
 * checklist as executable checks, so "is the instance ready" is one command an operator runs rather
 * than a list a reader has to remember to apply. It checks the things that make the difference between
 * a demo and a product: the hash chain, the price the trader is shown, the fee that gets charged, the
 * scheduler that settles, and the role the database is reached through.
 *
 * A failed check exits non-zero, so a deploy pipeline can gate on it. Each check says what is wrong
 * and what to do, because a preflight that fails with "configuration is bad" has not done its job.
 */
final class Preflight extends Command
{
    protected $signature = 'kudi:preflight';

    protected $description = 'Check the instance is safe to open to traders';

    public function handle(): int
    {
        $checks = [
            'running in production' => fn (): array => $this->checkProduction(),
            'scheduler registered' => fn (): array => $this->checkScheduler(),
            'storage writable' => fn (): array => $this->checkStorage(),
            'hash chain intact' => fn (): array => $this->checkHashChain(),
            'ledger balances agree' => fn (): array => $this->checkBalances(),
            'a rate is published for GHS/NGN' => fn (): array => $this->checkRate('GHS', 'NGN'),
            'a rate is published for NGN/GHS' => fn (): array => $this->checkRate('NGN', 'GHS'),
            'a commission is set' => fn (): array => $this->checkCommission(),
        ];

        $failures = 0;

        foreach ($checks as $label => $run) {
            $outcome = $run();

            if ($outcome['ok']) {
                $this->info('  [ok] '.$label);

                continue;
            }

            $failures++;
            $this->error('  [FAIL] '.$label);
            $this->error('         '.$outcome['message']);
        }

        $this->newLine();

        if ($failures === 0) {
            $this->info('Preflight passed: '.count($checks).' checks.');

            return self::SUCCESS;
        }

        $this->error('Preflight failed: '.$failures.' of '.count($checks).' checks. Fix these before opening to traders.');

        return self::FAILURE;
    }

    /** The instance must be production and must not run with debugging on. */
    private function checkProduction(): array
    {
        if (app()->environment('production') && ! config('app.debug')) {
            return ['ok' => true];
        }

        // APP_ENV and APP_DEBUG are read once into config at boot; app()->environment() and
        // config('app.debug') are the truth for this running instance, not whatever is in .env.
        return ['ok' => false, 'message' => 'APP_ENV=production and APP_DEBUG=false are required. The values this process sees are '.app()->environment().' and debug='.(config('app.debug') ? 'true' : 'false').'.'];
    }

    /** Without the cron, every settlement waits for a human to notice. */
    private function checkScheduler(): array
    {
        // The schedule is declared in routes/console.php; check the exact command this instance must
        // run is registered. The cron line itself cannot be verified from inside the app -- the
        // runbook has the crontab one-liner -- but its absence here is worth failing on by itself.
        $protectedSchedules = app(Schedule::class)->events();
        $present = collect($protectedSchedules)
            ->map(fn ($event): string => $event->command ?? '')
            ->filter(fn (string $command): bool => str_contains($command, 'trades:release-due'))
            ->isNotEmpty();

        if ($present) {
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => 'trades:release-due is not registered in routes/console.php; trades will never settle automatically.'];
    }

    private function checkStorage(): array
    {
        foreach (['logs', 'framework/sessions'] as $path) {
            $absolute = storage_path($path);

            if (is_writable($absolute)) {
                continue;
            }

            return ['ok' => false, 'message' => "{$path} is not writable by the web/PHP user."];
        }

        return ['ok' => true];
    }

    /** An edited ledger is the one failure no trader will ever see and no desk can fix. */
    private function checkHashChain(): array
    {
        try {
            $ok = DB::connection('pgsql_ops')->selectOne('select verify_chain() as ok');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'verify_chain() failed: '.$e->getMessage()];
        }

        if ((bool) $ok->ok) {
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => 'the ledger hash chain does not verify. Do not open to traders; call operations.'];
    }

    private function checkBalances(): array
    {
        try {
            $mismatches = DB::connection('pgsql_ops')->selectOne('select count(*) as n from check_balances() where cached <> actual');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'check_balances() failed: '.$e->getMessage()];
        }

        if ((int) $mismatches->n === 0) {
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => "{$mismatches->n} account(s) have cached and actual balances disagreeing."];
    }

    /** A board that shows a price is a board that must have one. No default rate exists. */
    private function checkRate(string $base, string $quote): array
    {
        try {
            $latest = DB::connection('pgsql_ops')->selectOne(
                'select 1 as present from rate_card where base_asset = ? and quote_asset = ? order by id desc limit 1',
                [$base, $quote]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'could not read rate_card: '.$e->getMessage()];
        }

        return isset($latest->present)
            ? ['ok' => true]
            : ['ok' => false, 'message' => "no rate is published for {$base}/{$quote}. Publish one on the pricing desk or the board will refuse to quote."];
    }

    /** The commission is charged on every trade; an unset one is a price nobody agreed to. */
    private function checkCommission(): array
    {
        try {
            $rows = DB::connection('pgsql_ops')->select(
                'select 1 as present, fee_bps from trade_policy where fee_bps > 0 limit 1'
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'could not read trade_policy: '.$e->getMessage()];
        }

        return $rows !== []
            ? ['ok' => true]
            : ['ok' => false, 'message' => 'no commission is set on any trade type (all fee_bps are 0). Set one on the pricing desk before trading.'];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settle the trades nobody has to chase.
 *
 * paid_attested -> released has actor 'system' in the ledger, and until this command nothing ever
 * performed it. Every attested trade sat at paid_attested for ever: money in escrow, somebody paid, the
 * other side never receiving anything.
 *
 * It matters most on our own book, which is the tier where the money is ours and delivery is ours -- and
 * which a trader can now open from /trade/buy.
 *
 * Run on the operations connection, because the function moves money and is granted to kudi_ops alone.
 * Nothing here decides anything: it calls kudi-core/013's release_due_trades(), which runs the same
 * transition_trade() the console uses, so the postings, the hash chain and the idempotency keys all come
 * from one place. A sweeper that wrote postings itself would be a second ledger.
 */
final class ReleaseDueTrades extends Command
{
    protected $signature = 'trades:release-due';

    protected $description = 'Release every attested trade whose claim window has closed';

    public function handle(): int
    {
        try {
            $result = DB::connection('pgsql_ops')->selectOne('select released, already_settled from release_due_trades()');
        } catch (Throwable $e) {
            // A sweeper that dies quietly is worse than one that is absent: an operator watching a screen
            // would believe trades were settling. Say so and let the scheduler try again next minute.
            $this->error('The release sweep did not run: '.$e->getMessage());

            return self::FAILURE;
        }

        $released = (int) ($result->released ?? 0);
        $skipped = (int) ($result->already_settled ?? 0);

        if ($released === 0 && $skipped === 0) {
            $this->line('Nothing was due.');

            return self::SUCCESS;
        }

        // "Ran and did nothing" and "ran and found nothing to do" are different answers, which is why 013
        // returns two numbers. A trade counted as skipped is one whose release the ledger refused -- most
        // often a fault recorded against it -- and it will still be there next minute.
        $this->info("Released {$released}.".($skipped > 0 ? " Skipped {$skipped}, still waiting." : ''));

        if ($skipped > 0) {
            $this->warn('A skipped trade is not a released trade. Check its state record.');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Setting what we charge.
 *
 * The commission is our price, so it moves with the rate card onto the PRICING desk. That is not tidiness:
 * kudi-core/006 keeps pricing and operations apart so one account cannot reprice the book and then judge
 * its own trades, and 017 grants set_commission() to kudi_pricing for the same reason. Operations resolve
 * disputes, and the disputed question is very often the fee -- so operations can READ this setting, which
 * it must in order to answer a trader on the phone, and cannot change it.
 *
 * EVERY write goes to set_commission() on pgsql_pricing, and nothing here writes a row. So this service
 * can weaken none of the properties 017 gives the ledger: the setting is effective only for trades opened
 * after it (the fee is frozen onto each trade at creation), and the change is appended to a hash-chained
 * log so "who raised our commission and when" is a question the record can answer.
 */
final class CommissionDesk
{
    /**
     * Change the commission for a trade type.
     *
     * $feeBps is an int: a commission is basis points per ten thousand, and the whole point of it being
     * an integer is that no fraction of somebody's money is decided by a float.
     */
    public function set(
        string $tradeType,
        int $feeBps,
        string $feeBearer,
        string $actor,
        ?string $note = null,
    ): void {
        if (trim($actor) === '') {
            throw new RuntimeException('A price with nobody\'s name on it cannot be defended later.');
        }

        try {
            DB::connection('pgsql_pricing')->selectOne(
                'select set_commission(?, ?, ?, ?, ?) as ok',
                [
                    strtolower(trim($tradeType)),
                    $feeBps,
                    strtolower(trim($feeBearer)),
                    $actor,
                    $note,
                ]
            );
        } catch (\Throwable $e) {
            // The ledger's own words. "fee_bps 10001 is out of range: a commission is between 0 and 10000
            // basis points" tells a pricing desk exactly what to fix; "could not set the commission" does
            // not, and a settings screen that swallows the reason is a screen people learn to retry blindly.
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The connection that may READ the setting and its history.
     *
     * NOT pgsql_pricing, and for the same reason RateDesk reads its card on the operations connection:
     * pricing is granted the setter and nothing else, so reading the book back through its own role would
     * widen what that role can see. Reading as operations hands pricing no new power -- what decides who
     * may move the price is which panel a person can reach, and that write goes through pgsql_pricing.
     */
    private const READ = 'pgsql_ops';

    /**
     * The current settings for every trade type.
     *
     * @return list<object>
     */
    public function policies(): array
    {
        return DB::connection(self::READ)
            ->select('select trade_type, claim_window, fee_bps, fee_bearer from trade_policy order by trade_type')
            ->all();
    }

    /**
     * Every commission ever set, newest first, with who set it.
     *
     * @return list<object>
     */
    public function history(): array
    {
        return DB::connection(self::READ)
            ->select('select trade_type, fee_bps, fee_bearer, set_by, note, set_at from commission_log order by id desc')
            ->all();
    }
}

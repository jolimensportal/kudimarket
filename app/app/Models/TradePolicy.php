<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Our per-tier pricing settings: the claim window, the deposit, and what we charge.
 *
 * READ ONLY, for the same reason RateCard is: trade_policy is SELECT-only to every application role, and
 * the commission on it is written by kudi-core/017's set_commission() through the PRICING connection, on
 * the pricing desk. An ordinary editable Eloquent model here would be an invitation to update our price
 * from a console, which is the one property 006 exists to keep.
 */
class TradePolicy extends Model
{
    protected $table = 'trade_policy';

    /** The policy is read on the operations connection, because 017 grants SELECT on it to kudi_app and kudi_ops. */
    protected $connection = 'pgsql_ops';

    protected $primaryKey = 'trade_type';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Eloquent casts integers to strings on read by default when they come off a numeric column, which
     * would render "250" as "250" but "0" correctly -- the risk is a basis-points figure reaching a form
     * as a string and a form submitting it back as one. int here, so the pricing screen binds an int.
     */
    protected $casts = [
        'fee_bps' => 'int',
        'deposit_bps' => 'int',
    ];

    /** Nothing writes through this model. */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \RuntimeException('trade_policy is not edited. Use set_commission() on the pricing desk.');
        });

        static::deleting(static function (): never {
            throw new \RuntimeException('trade_policy is not deleted.');
        });
    }
}

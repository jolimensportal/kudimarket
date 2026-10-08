<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A read-only view of a trade, over the operations connection.
 *
 * Not an Eloquent model in spirit: it maps a database view, has no writes, and the role behind
 * this connection has no INSERT or UPDATE privilege on the underlying tables anyway. Every change
 * to a trade goes through TradeLedger and a stored procedure.
 *
 * @property int $id
 * @property string $trade_type
 * @property string $seller
 * @property string $buyer
 * @property string|null $crypto_asset
 * @property int|null $crypto_amount
 * @property string|null $deposit_asset
 * @property string $fiat_asset
 * @property int $fiat_amount
 * @property string $state
 * @property string $colour
 */
class Trade extends Model
{
    protected $connection = 'pgsql_ops';

    protected $table = 'trade_view';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function getFiatDisplayAttribute(): string
    {
        return $this->fiat_amount === null
            ? '—'
            : number_format((float) $this->fiat_amount / 100, 2).' '.$this->fiat_asset;
    }

    public function getCryptoDisplayAttribute(): string
    {
        return $this->crypto_amount === null
            ? 'none'
            : number_format((float) $this->crypto_amount / 1_000_000, 2).' '.$this->crypto_asset;
    }
}

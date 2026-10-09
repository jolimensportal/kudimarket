<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RateBoard;
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

    /**
     * Both legs render through RateBoard::formatMoney, which reads the currency's own decimals from the
     * asset table.
     *
     * These two used to divide by 100 and by 1,000,000 respectively. That is right for naira, cedi and
     * USDT and wrong by a factor of a hundred for any currency that is not two decimals, which is the
     * failure RateBoard::formatMoney's own comment warns about by name: "hardcoding 100 gets that wrong
     * silently". On the operations console -- the screen a regulator or a dispute reads -- a wrong figure
     * is not a cosmetic problem.
     */
    public function getFiatDisplayAttribute(): string
    {
        return $this->fiat_amount === null
            ? '—'
            : RateBoard::formatMoney($this->fiat_amount, $this->fiat_asset);
    }

    public function getCryptoDisplayAttribute(): string
    {
        return $this->crypto_amount === null
            ? 'none'
            : RateBoard::formatMoney($this->crypto_amount, $this->crypto_asset);
    }
}

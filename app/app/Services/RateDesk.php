<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishing KudiMarket's own price.
 *
 * "KudiMarket quotes its own rates" is the central claim in brand/product.html, and until this existed
 * nothing in the product could act on it: the price of every trade was 148.2 hardcoded in a reset script.
 *
 * EVERY write goes to set_rate() on the pgsql_pricing connection, and nothing here writes a row. That is
 * not a style preference. kudi-core/006 gives the rate card four properties and this service can weaken
 * none of them, because it holds no SQL of its own:
 *
 *   1. Append-only. There is no update path, so "what was the rate at 14:03" survives.
 *   2. Hash-chained per pair. A price quietly rewritten afterwards is the cleanest scam in an FX book.
 *   3. kudi_pricing, not kudi_ops. pgsql_ops cannot call set_rate at all, which is the control that keeps
 *      a compromised desk from repricing the book and then judging its own trades.
 *   4. Backdating refused.
 *
 * Returning an Eloquent model is a lie told to satisfy Filament's shape -- there is no such row and no
 * table. It is a zero-column stand-in and is documented as such at every use.
 */
final class RateDesk
{
    /**
     * Publish a rate. $rate is a DECIMAL STRING, never a float.
     *
     * 0.0067567 has no exact binary representation, so round-tripping a price through a float changes
     * what we publish by a few units in the last place -- on every single trade priced from it, and on
     * the chain that commits to it.
     */
    public function publish(
        string $base,
        string $quote,
        string $rate,
        string $actor,
        ?string $effectiveFrom = null,
        ?string $note = null,
    ): Model {
        if (trim($actor) === '') {
            throw new RuntimeException('A price with nobody\'s name on it cannot be defended later.');
        }

        try {
            $id = DB::connection('pgsql_pricing')->selectOne(
                'select set_rate(?, ?, ?, ?, ?, ?) as id',
                [
                    strtoupper(trim($base)),
                    strtoupper(trim($quote)),
                    // numeric, cast from the string, never from a PHP float.
                    $rate,
                    $actor,
                    $effectiveFrom === null ? null : date('Y-m-d H:i:s', strtotime($effectiveFrom)),
                    $note,
                ]
            )->id;
        } catch (\Throwable $e) {
            // The ledger's own words, not a paraphrase. "backdating refused: X is more than 5 seconds in
            // the past" tells a pricing desk exactly what to fix; "could not publish rate" does not.
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $this->standIn((int) $id);
    }

    /**
     * The connection that may READ the card.
     *
     * NOT pgsql_pricing. kudi-core/006 revokes everything and then "grant select on rate_card,
     * current_rate, rate_board to kudi_app, kudi_ops" -- pricing is granted set_rate() and nothing else.
     * So the pricing desk can write the price and cannot read the book back on its own role, which is
     * deliberate and is not something to widen for the convenience of a screen.
     *
     * Reading through the operations role does not hand pricing any power: the connection is an
     * application detail, not a person's authority. What decides who may move the price is which panel a
     * person can reach, and that write goes through pgsql_pricing, which only this panel uses.
     */
    private const READ = 'pgsql_ops';

    /**
     * Every price ever published, newest first.
     *
     * @return list<object>
     */
    public function all(): array
    {
        return DB::connection(self::READ)->select(
            'select base_asset, quote_asset, rate, effective_from, set_by, note
               from rate_card order by effective_from desc, id desc'
        );
    }

    /**
     * What each pair is at right now.
     *
     * @return list<object>
     */
    public function current(): array
    {
        return DB::connection(self::READ)->select(
            'select base_asset, quote_asset, rate, effective_from, set_by from current_rate order by base_asset, quote_asset'
        );
    }

    /**
     * Not a row. Filament's CreateAction wants something back, and there is no table to give it a row
     * from -- a published rate is an event, not an object.
     *
     * @return Model<never, never>
     */
    private function standIn(int $id): Model
    {
        $model = new class extends Model
        {
            protected $table = 'rate_card';

            public $exists = false;
        };

        $model->id = $id;

        return $model;
    }
}

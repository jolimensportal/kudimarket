<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A price we published.
 *
 * READ ONLY, and the class is the reason that is enforceable in one place: nothing in the application
 * calls save() or delete() on it, and RateDesk holds no SQL of its own -- every write goes through
 * set_rate() on the pricing connection.
 *
 * That is kudi-core/006's first property: a rate is never updated, because inserting a new one that
 * supersedes the old by effective_from is what makes "the rate was 148.20 at 14:03" survive forever. An
 * ordinary Eloquent model invites exactly the update this table refuses.
 *
 * @property int $id
 * @property string $base_asset
 * @property string $quote_asset
 * @property string $rate
 * @property \DateTimeInterface $effective_from
 * @property string $set_by
 * @property string|null $note
 */
class RateCard extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Named, not derived. Eloquent pluralises RateCard to `rate_cards`, and the table is `rate_card` --
     * the singular is the name kudi-core/006 gave it. Without this the screen asks for a relation that
     * does not exist.
     */
    protected $table = 'rate_card';

    /**
     * Read on the operations connection, because 006 grants SELECT on the card to kudi_app and kudi_ops
     * and to kudi_pricing nothing but set_rate(). Declared here so the resource cannot forget it.
     */
    protected $connection = 'pgsql_ops';

    /** No Eloquent ever. Writes go through set_rate(); this class exists so a table can be listed. */
    public function save(array $options = []): bool
    {
        throw new \LogicException(
            'A published rate is appended by set_rate(), never saved. Insert it through RateDesk so it '
            .'commits to the chain.'
        );
    }

    public function delete(): ?bool
    {
        throw new \LogicException(
            'A published rate is never deleted. What was the rate when a trade was filled has to stay '
            .'provable, and that is the first property of the rate card.'
        );
    }
}

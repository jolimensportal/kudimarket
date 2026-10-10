<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person who may set KudiMarket's own price.
 *
 * Separate from User on purpose: `users` is the operations console and this is the pricing desk, and the
 * database already refuses set_rate to the operations role. This table is the application half of the same
 * separation -- it decides who may open the /pricing panel at all.
 *
 * @property int $id
 * @property string $email
 * @property string $name
 */
class PricingStaff extends Model
{
    protected $fillable = ['email', 'name'];

    /** Whether this signed-in address holds a pricing desk. */
    public static function holds(string $email): bool
    {
        return $email !== null && static::query()->where('email', $email)->exists();
    }
}

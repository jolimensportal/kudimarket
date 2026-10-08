<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A trader on the board.
 *
 * Deliberately not the Filament User: `users` belongs to the operations console, and a trader in
 * Accra and an operator at a desk are different people who should not share a table, a session or a
 * credential. This model is read-mostly too -- a trader's balance and history come from the ledger,
 * never from a column here.
 */
class Trader extends Authenticatable
{
    /** @use HasFactory<TraderFactory> */
    use HasFactory;

    /**
     * Reads as the trader role, not the app role.
     *
     * Same table, different privileges: 008 gives kudi_trader SELECT and nothing more, and
     * kudi_trader is also what holds the execute grants on the trade constructors. Reading as kudi_app
     * would work for this table but would mean the trader app's reads were not being checked against
     * the role that actually runs in production.
     */
    protected $connection = 'pgsql_trader';

    protected $table = 'trader';

    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'created_at' => 'datetime',
        ];
    }
}

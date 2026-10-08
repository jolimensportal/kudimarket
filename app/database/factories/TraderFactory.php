<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Trader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trader>
 */
class TraderFactory extends Factory
{
    protected $model = Trader::class;

    /**
     * Writes through the admin connection, not the model's own.
     *
     * A trader cannot insert their own row: 008 gives kudi_trader SELECT on this table and nothing
     * more, on purpose, because registration is an application task rather than a trader one. Without
     * this the factory would fail against the very role limits the SQL suite asserts, which is a
     * confusing way to discover that the limits work.
     */
    public function newModel(array $attributes = [])
    {
        $model = parent::newModel($attributes);
        $model->setConnection('pgsql_admin');

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // unique() rather than a fixed address: the ledger records this address as the trade
            // counterparty, and two tests sharing one would share one balance and pass for the wrong
            // reason. This is the same mistake as using asops to test a trader's limits.
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('#########'),
            'password' => bcrypt('password'),
            'kind' => 'person',
            'verified_at' => null,
        ];
    }

    /** A trader operations have looked at the paperwork for. */
    public function verified(): static
    {
        return $this->state(fn (): array => ['verified_at' => now()]);
    }

    /** A registered business rather than a person. */
    public function business(): static
    {
        return $this->state(fn (): array => ['kind' => 'business']);
    }
}

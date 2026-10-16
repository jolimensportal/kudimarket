<?php

declare(strict_types=1);

namespace App\Support;

final class RateFormatter
{
    /**
     * Render a rate at a fixed, stored precision. Never print a raw float.
     *
     * A direct rate (>= 1) is a price per unit and reads at two decimals. An inverse rate (< 1) is
     * a small number where the leading digits are the significant ones, so it needs six: four was
     * tried and rejected because it renders 1/148 as "0.0068", which destroys the value. A corridor
     * may override the default without changing any caller.
     */
    public static function format(float|int|string $rate, ?int $decimals = null): string
    {
        $value = (float) $rate;

        $decimals ??= $value >= 1 ? 2 : 6;

        return number_format($value, $decimals, '.', '');
    }
}

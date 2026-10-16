<?php

declare(strict_types=1);

namespace App\Support;

final class MoneyFormatter
{
    /**
     * Format an amount of money with thousands separators and a fixed number of decimals.
     *
     * Separate from RateFormatter on purpose. A rate is a price and reads bare -- 148.20, never
     * 148.2 and never grouped. An amount is counted by eye, and 14,820.00 is checked faster than
     * 14820.00. One formatter for both would leave whichever it was wrong for, wrong everywhere.
     */
    public static function format(float|int|string $amount, int $decimals = 2): string
    {
        return number_format((float) $amount, $decimals, '.', ',');
    }
}

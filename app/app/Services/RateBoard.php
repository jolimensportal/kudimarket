<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the rate card. Nothing here writes, and nothing here decides a rate.
 *
 * Formatting happens in SQL because the same number appears on the board, on a trade and on the trade
 * record, and three places formatting a rate differently is how a trader ends up not believing any of
 * them. The whole service runs on the trader role, so it physically cannot write a rate.
 */
final class RateBoard
{
    /**
     * Both directions of a pair, ours only. Empty when we have published nothing.
     *
     * Matched on both legs explicitly rather than with `base_asset = ? or quote_asset = ?`. That
     * shorter form looks right and is wrong: asked for GHS/NGN it matches GHS/NGN and also, say,
     * GHS/USD — a different pair that happens to share a leg. It missed NGN/GHS entirely, which is
     * the row the trader most wants to see on a board they are comparing both ways.
     */
    public function forPair(string $base, string $quote): array
    {
        return DB::connection('pgsql_trader')->select(
            'select base_asset, quote_asset, rate, effective_from, set_by, inverse_rate
               from rate_board
              where (base_asset = ? and quote_asset = ?)
                 or (base_asset = ? and quote_asset = ?)
              order by base_asset',
            [$base, $quote, $quote, $base]
        );
    }

    /**
     * Every rate currently on the card, keyed "BASE/QUOTE".
     *
     * The offer board weighs each open offer against our rate for its own pair, and asking per offer is a
     * query per row on a screen built for a bad connection.
     *
     * @return array<string, object>
     */
    public function currentRates(): array
    {
        $rates = [];

        foreach (DB::connection('pgsql_trader')->select(
            'select base_asset, quote_asset, rate, effective_from from current_rate order by base_asset'
        ) as $row) {
            $rates[$row->base_asset.'/'.$row->quote_asset] = $row;
        }

        return $rates;
    }

    /** Our own rate for one direction, or null when we have not published one. Null is a real state. */
    public function ourRate(string $base, string $quote): ?object
    {
        return DB::connection('pgsql_trader')->selectOne(
            'select rate, effective_from, set_by from current_rate where base_asset = ? and quote_asset = ?',
            [$base, $quote]
        );
    }

    /**
     * The same rate, with the timestamp as something the view can format.
     *
     * Done in SQL rather than by casting in the view because these come back as stdClass from a raw
     * query: there is no cast on them, and calling diffForHumans() on a string is a 500 that only
     * appears once a rate exists. The first version of this screen did exactly that.
     */
    public function ourRateForDisplay(string $base, string $quote): ?object
    {
        $rate = $this->ourRate($base, $quote);

        if ($rate === null) {
            return null;
        }

        $rate->effective_from = Carbon::parse((string) $rate->effective_from);

        return $rate;
    }

    /**
     * Format a decimal rate for reading, from a string or a number.
     *
     * Ten places, then trailing zeros trimmed but never below the floor. Ten is not arbitrary: the GHS/NGN
     * leg is 148.20 and wants two, while the NGN/GHS leg is 0.00675676 and needs eight before it says
     * anything. Rounding both to two turned the second into 0.01, which is not a rate, it is a rounding.
     *
     * Display only. The precision that matters is the number STORED, and that is handled where the
     * number is stored: the rate card holds 18 places and trade.rate was widened to match it.
     *
     * An earlier version rounded a decimal string by hand at the decimal point, and rendered 148.2 as
     * 148.00 and 0.00676 as 0.00, because it lost the whole number's own decimals. A version that
     * ignored the small-value case rendered the naira leg as 0.01. Bounded formatting is the actual
     * requirement and neither of those met it.
     */
    private static function formatDecimalString(int|float|string $rate, int $minPlaces): string
    {
        $formatted = number_format((float) $rate, 10, '.', '');

        if (! str_contains($formatted, '.')) {
            return number_format((float) $rate, $minPlaces, '.', '');
        }

        [$whole, $decimals] = explode('.', $formatted, 2);

        // Trim trailing zeros, then put back however many the floor demands.
        $decimals = substr(str_pad(rtrim($decimals, '0'), $minPlaces, '0'), 0, max($minPlaces, strlen(rtrim($decimals, '0'))));

        return $whole.'.'.$decimals;
    }

    /** @var array<string, int> */
    private static array $decimals = [];

    public static function decimalsFor(string $asset): int
    {
        // The asset table is reference data: a currency's decimals do not change while the process runs.
        // Cached because formatMoney calls this, and a list screen formats one amount per row -- without
        // this, every table on the operations console would issue one query per row to learn what it
        // already knew. Keyed by code, so it cannot serve one currency's decimals for another's.
        if (isset(self::$decimals[$asset])) {
            return self::$decimals[$asset];
        }

        $row = DB::connection('pgsql_trader')->selectOne('select decimals from asset where code = ?', [$asset]);

        if ($row === null) {
            // An unknown currency is a programming error, not a display problem. Guessing 2 here would
            // quietly render every amount of an unlisted currency wrong by a factor of a hundred.
            throw new \RuntimeException("unknown asset {$asset}: not in the asset table");
        }

        return self::$decimals[$asset] = (int) $row->decimals;
    }

    /**
     * An amount in minor units, rendered with its currency's own decimals.
     *
     * From the asset table rather than a divisor, because 200000 minor units is 2,000.00 in naira and
     * 200,000.00 in a currency with no decimals, and hardcoding 100 gets that wrong silently.
     */
    /**
     * A decimal string in major units, as minor units. The inverse of formatMoney.
     *
     * NO FLOATS, deliberately. 0.1 has no exact binary representation, so round-tripping a money amount
     * through one loses pesewas: 1000.10 comes back as 1000.09, which is the same class of bug as dividing
     * minor units by a major-per-major rate. Both were real bugs in this codebase.
     *
     * The string is parsed, not cast. Anything that is not a plain decimal number is refused rather than
     * quietly becoming zero, because a buy screen that turns a typo into a trade for nothing is worse than
     * one that says so.
     *
     * @throws \RuntimeException when the string is not a plain positive decimal
     */
    public static function toMinor(string $majorAmount, string $asset): int
    {
        $decimals = self::decimalsFor($asset);
        $majorAmount = trim($majorAmount);

        if (preg_match('/^\d{1,12}(\.\d*)?$/', $majorAmount) !== 1) {
            throw new \RuntimeException(
                "'{$majorAmount}' is not an amount of {$asset}: digits, and at most a decimal point"
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $majorAmount, 2), 2, '');
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);

        return ((int) $whole) * (10 ** $decimals) + (int) $fraction;
    }

    public static function formatMoney(int|float|string|null $minorUnits, string $asset): string
    {
        if ($minorUnits === null) {
            return '—';
        }

        $decimals = self::decimalsFor($asset);

        // Cast to float before dividing. These columns arrive from a raw query as numeric STRINGS --
        // '200000' -- and PHP's arithmetic on a numeric string, or a float cast that skips the
        // parentheses, quietly produces 2000 with no decimal places.
        //
        // The thousands separator is the other half. number_format defaults to ',' for the separator,
        // so 2000.00 came out as "2000.00" here and "2,000.00" only after the separator was set
        // explicitly. Money on a board is read in columns of digits; an unseparated figure is harder
        // to scan and reads as a different number at a glance.
        $major = ((float) $minorUnits) / (10 ** $decimals);

        return number_format($major, $decimals, '.', ',').' '.$asset;
    }

    /**
     * A rate as a person should read it.
     *
     * $minPlaces is a floor, not a fixed width. 148.2 and 148.20 are the same price and a board that
     * shows one of them inconsistently reads as a rounding bug, so at least two places always show.
     * But a floor that also capped the width turned the reverse leg of this pair into "0.00": a
     * cedi-to-naira rate is 148.20, and one naira buys 0.0067 cedi, which needs six places to mean
     * anything. Anything the rate genuinely needs beyond the floor is kept.
     *
     * Static because the views call it without a service instance, and a static formatter needs no
     * database, so there is nothing to inject.
     */
    /**
     * @param  int|float|string  $rate  a decimal STRING whenever it came from the database. PHP floats are
     *                                  doubles: 0.006756756756756756 round-trips through one as
     *                                  0.0067567567567568, and that figure is displayed beside the card rate
     *                                  it is meant to equal, so the loss is the reader's problem too.
     */
    public static function formatRate(int|float|string $rate, int $minPlaces = 2): string
    {
        if (is_string($rate) && is_numeric($rate)) {
            return self::formatDecimalString($rate, $minPlaces);
        }

        // Ten places is enough for any pair we will list, and trailing zeros come off afterwards so
        // 0.50 reads as 0.5 rather than 0.5000000000.
        $formatted = rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.');

        if (! str_contains($formatted, '.')) {
            return str_contains($formatted, '.') ? $formatted : number_format($rate, $minPlaces, '.', '');
        }

        [$whole, $decimals] = explode('.', $formatted, 2);

        if (strlen($decimals) >= $minPlaces) {
            return $formatted;
        }

        return $whole.'.'.str_pad($decimals, $minPlaces, '0');
    }
}

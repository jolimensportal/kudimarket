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
     * Vendor offers: trades opened against nobody, waiting for a counterparty.
     *
     * A trade in state 'open' is an offer, which is why no offers table is needed. When somebody
     * accepts one it stops being open and becomes a trade with two parties, so the same row does both
     * jobs without a second concept to keep in sync.
     */
    public function offers(string $quote, string $base, int $limit = 20): array
    {
        return DB::connection('pgsql_trader')->select(
            "select id, seller, rate, fiat_amount, fiat_asset
               from trade_view
              where state = 'open'
                and trade_type = 'vendor_vendor'
                and fiat_asset = ?
                and deposit_asset = ?
              order by rate desc
              limit {$limit}",
            [$quote, $base]
        );
    }

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
    public static function formatRate(float $rate, int $minPlaces = 2): string
    {
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

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The two figures a trader reads off a trade, and nothing else.
 *
 * WHY THIS IS A SEPARATE CLASS
 * The first version of the trade screen printed the SAME number for what you send and what you
 * receive. A trade stores one amount -- the naira leg, in minor units -- so the cedi side has to be
 * derived from the agreed rate. Getting that derivation right means dividing in major units, because
 * the rate is major-per-major: divide minor units by the rate and you are out by a factor of a
 * hundred, giving 1,350 cedi instead of 13.50. That is the kind of bug that belongs in a class with
 * tests, not inside a Blade template where only a rendered-page assertion can find it.
 *
 * It is static and returns strings because it needs no database and no session: the caller already
 * has the trade row.
 */
final class TradeFigures
{
    /**
     * @param  object  $trade  a trade_view row: needs fiat_amount, fiat_asset and rate
     * @return array{send: string, receive: string, send_minor: int, receive_minor: int}
     */
    public static function forTrade(object $trade): array
    {
        $receiveMinor = (int) $trade->fiat_amount;
        $receiveDecimals = RateBoard::decimalsFor($trade->fiat_asset);
        $rate = (float) $trade->rate;

        if ($rate <= 0) {
            // A trade with no rate is a data problem, not something to paper over with a zero. Guessing
            // here would render a real amount to send as 0.00.
            throw new \RuntimeException("trade {$trade->id} has no usable rate");
        }

        // Major units on both sides, because the rate compares majors to majors.
        $receiveMajor = $receiveMinor / (10 ** $receiveDecimals);
        $sendMajor = $receiveMajor / $rate;

        // What the trader actually types into their phone: the cedi figure, to the cedi's decimals.
        $sendDecimals = RateBoard::decimalsFor('GHS');
        $sendMinor = (int) round($sendMajor * (10 ** $sendDecimals));

        return [
            'send' => RateBoard::formatMoney($sendMinor, 'GHS'),
            'receive' => RateBoard::formatMoney($receiveMinor, $trade->fiat_asset),
            'send_minor' => $sendMinor,
            'receive_minor' => $receiveMinor,
        ];
    }
}

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
     * @param  object  $trade  a trade_view row: needs fiat_amount, fiat_asset, rate and rate_quote_asset
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

        // WHICH WAY THE RATE POINTS, and this is the whole point of the rewrite.
        //
        // rate is "how many rate_quote_asset per one unit of the other asset in the pair". The core has
        // always stored two rates in this one column, quoted from opposite ends, and this class had to
        // assume which one it was looking at -- so it assumed the platform way and divided, always.
        //
        //   platform_inventory   148.2 NGN per GHS, fiat_asset NGN -> naira / 148.2  = cedi.  Divide.
        //   vendor_vendor        0.0067567 GHS per NGN, fiat_asset NGN -> naira * 0.0067 = cedi. Multiply.
        //
        // Both rows read "fiat_amount 500000, fiat_asset NGN", so the wrong guess was invisible in the data
        // and 21904x wrong on the screen: a buyer was told to send C74,000,000 on a C3,378.38 trade, and
        // given a Mobile Money number to send it to. kudi-core/011 records the direction on the row, and
        // every fixture before this used a single-cedi rate, where both conventions agree.
        // Major units on both sides, because the rate compares majors to majors.
        $receiveMajor = $receiveMinor / (10 ** $receiveDecimals);

        $quoteAsset = $trade->rate_quote_asset ?? $trade->fiat_asset;

        if ($quoteAsset === 'GHS') {
            $sendMajor = $receiveMajor * $rate;
        } elseif ($quoteAsset === $trade->fiat_asset) {
            $sendMajor = $receiveMajor / $rate;
        } else {
            // The rate counts in neither leg of this trade, so no arithmetic here can be right. Say so
            // instead of returning a number that looks authoritative.
            throw new \RuntimeException(
                "trade {$trade->id} has a rate quoted in {$quoteAsset}, which is neither its fiat asset "
                ."({$trade->fiat_asset}) nor cedi, so the cedi side cannot be derived"
            );
        }

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

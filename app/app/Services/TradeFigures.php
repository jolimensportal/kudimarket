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
     * @param  string  $viewer  'buyer' or 'seller' -- which side of the trade is looking at this screen
     * @return array{send: string, receive: string, send_minor: int, receive_minor: int}
     */
    /**
     * What the trade is worth in cedi, in minor units, with no opinion about who is looking.
     *
     * One place knows which way the rate points, because two places is how the same currency bug gets
     * written twice. forTrade uses this for its arithmetic, and the operations console uses it to show an
     * agreed value -- there is no buyer or seller to ask on that screen, so it is deliberately the neutral
     * question rather than one of the two views.
     *
     * @throws \RuntimeException when the rate counts in neither cedi nor the fiat leg
     */
    public static function cediMinor(object $trade): int
    {
        $rate = (float) $trade->rate;

        if ($rate <= 0) {
            throw new \RuntimeException("trade {$trade->id} has no usable rate");
        }

        $quoteAsset = $trade->rate_quote_asset ?? $trade->fiat_asset;
        $fiatMajor = ((int) $trade->fiat_amount) / (10 ** RateBoard::decimalsFor($trade->fiat_asset));

        $cediMajor = match ($quoteAsset) {
            'GHS' => $fiatMajor * $rate,
            $trade->fiat_asset => $fiatMajor / $rate,
            default => throw new \RuntimeException(
                "trade {$trade->id} has a rate quoted in {$quoteAsset}, which is neither its fiat asset "
                ."({$trade->fiat_asset}) nor cedi, so the cedi side cannot be derived"
            ),
        };

        return (int) round($cediMajor * (10 ** RateBoard::decimalsFor('GHS')));
    }

    public static function forTrade(object $trade, string $viewer = 'seller'): array
    {
        $receiveMinor = (int) $trade->fiat_amount;
        // A trade with no usable rate is a data problem, not something to paper over with a zero, and
        // cediMinor refuses it rather than returning a rate of zero.
        //
        // WHICH WAY THE RATE POINTS is decided once, in cediMinor(), and asked for here rather than
        // reimplemented. Two implementations of the same currency rule is how one of them ends up stale:
        // this file and the operations console both need it, and the bug that started all of it was
        // exactly one rule living in one place and the rest of the app guessing.
        //
        // rate is "how many rate_quote_asset per one unit of the other asset in the pair". The core has
        // always stored two rates in this one column, quoted from opposite ends, and this class had to
        // assume which one it was looking at -- so it assumed the platform way and divided, always.
        //
        //   platform_inventory   148.2 NGN per GHS, fiat_asset NGN -> naira / 148.2  = cedi.  Divide.
        //   vendor_vendor        0.0067567 GHS per NGN, fiat_asset NGN -> naira * 0.0067 = cedi. Multiply.
        //
        // Both rows read "fiat_amount 500000, fiat_asset NGN", so the wrong guess was invisible in the data
        // and 21904x wrong on the screen. kudi-core/011 records the direction on the row.
        $sendMinor = self::cediMinor($trade);

        // WHICH WAY ROUND, which depends on whose screen this is.
        //
        // kudi-core/003 states the vendor flow in one sentence: "A buyer in Accra pays 300,000 naira into
        // a seller's Nigerian bank account; the seller owes cedis into the buyer's mobile money." So on a
        // vendor trade the BUYER sends fiat_asset and receives the cedi, and the seller is the exact mirror.
        //
        // This class used to hand both of them the seller's view -- "You send GHS" -- because it was never
        // told who was looking. The numbers were right and the labels were backwards, so the buyer was
        // told to send cedi to a Nigerian bank account while holding the naira, and the instruction printed
        // under the figures said "Send the cedi to MTN Mobile Money", which is the seller's instruction.
        //
        // Only vendor_vendor is role-aware, because it is the only shape whose direction is written down
        // anywhere. platform_inventory keeps today's wording: what its fiat leg means is not documented,
        // and inventing a rule for it here would be a guess with a real number attached. Flagged rather
        // than fixed.
        $isVendor = ($trade->trade_type ?? null) === 'vendor_vendor';

        if ($isVendor && $viewer === 'seller') {
            return [
                'send' => RateBoard::formatMoney($sendMinor, 'GHS'),
                'receive' => RateBoard::formatMoney($receiveMinor, $trade->fiat_asset),
                'send_minor' => $sendMinor,
                'receive_minor' => $receiveMinor,
            ];
        }

        if ($isVendor) {
            // The buyer, on a vendor trade: sends the naira, receives the cedi.
            return [
                'send' => RateBoard::formatMoney($receiveMinor, $trade->fiat_asset),
                'receive' => RateBoard::formatMoney($sendMinor, 'GHS'),
                'send_minor' => $receiveMinor,
                'receive_minor' => $sendMinor,
            ];
        }

        return [
            'send' => RateBoard::formatMoney($sendMinor, 'GHS'),
            'receive' => RateBoard::formatMoney($receiveMinor, $trade->fiat_asset),
            'send_minor' => $sendMinor,
            'receive_minor' => $receiveMinor,
        ];
    }
}

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
     * Convert an amount between the two ends of a trade's rate pair.
     *
     * rate is quote per base, so converting INTO the quote multiplies and into the base divides. Both ends
     * come from the row -- rate_base_asset and rate_quote_asset, recorded by kudi-core/012 -- so nothing
     * here is hardcoded to cedi. That hardcoding was the bug: a trader buying cedi with naira receives a
     * currency the old code had no stored fact about, so it assumed.
     *
     * @return array{0: int, 1: string} the amount in minor units, and the asset it is now in
     */
    public static function convert(object $trade, int $minorUnits, string $targetAsset): array
    {
        $base = $trade->rate_base_asset ?? null;
        $quote = $trade->rate_quote_asset ?? null;
        $from = $trade->fiat_asset;

        if ($base === null || $quote === null || $base === $quote) {
            // No pair, or a pair whose ends are the same -- a rate of 1 is the placeholder for "no
            // conversion here". Either way there is nothing to convert with, and inventing a conversion
            // is how this file shipped four wrong figures.
            throw new \RuntimeException(
                "trade {$trade->id} has no rate pair to convert between, so {$targetAsset} cannot be derived"
            );
        }

        if ($from !== $base && $from !== $quote) {
            throw new \RuntimeException(
                "trade {$trade->id} holds {$from}, which is not one end of its {$base}/{$quote} pair"
            );
        }

        if ($targetAsset !== $base && $targetAsset !== $quote) {
            throw new \RuntimeException(
                "trade {$trade->id} cannot be converted to {$targetAsset}: its pair is {$base}/{$quote}"
            );
        }

        // Converting a leg to itself is the identity, NOT the rate. Without this the leg that happens to be
        // stored got multiplied by the rate on its way to being reported -- so N200,000 came back as
        // N296,400 at a rate of 148.2, which is a number nobody on earth asked for.
        if ($from === $targetAsset) {
            return [$minorUnits, $from];
        }

        $rate = (float) $trade->rate;
        if ($rate <= 0) {
            // A trade with no rate is a data problem, not something to paper over with a zero. Guessing
            // here would render a real amount to send as 0.00.
            throw new \RuntimeException("trade {$trade->id} has no usable rate");
        }

        // Major units in the middle, because the rate compares majors to majors. Dividing minor units by a
        // major-per-major rate is out by a factor of a hundred, which is the first version of this file.
        $major = $minorUnits / (10 ** RateBoard::decimalsFor($from));
        $converted = $targetAsset === $quote ? $major * $rate : $major / $rate;

        return [(int) round($converted * (10 ** RateBoard::decimalsFor($targetAsset))), $targetAsset];
    }

    /**
     * What the trade is worth in cedi, in minor units, with no opinion about who is looking.
     *
     * The operations console asks the same question this does, which is the reason it is a method rather
     * than a line copied across: two implementations of one currency rule is how one of them goes stale.
     *
     * @throws \RuntimeException when the trade has no pair, or its pair does not include cedi
     */
    public static function cediMinor(object $trade): int
    {
        [$minor] = self::convert($trade, (int) $trade->fiat_amount, 'GHS');

        return $minor;
    }

    /**
     * What each side of a trade sends and receives.
     *
     * ONE RULE, and it is the reason this file needs no special cases:
     *
     *   THE BUYER PAYS THE BASE LEG AND RECEIVES THE QUOTE LEG.
     *
     * Checked against all three shapes rather than assumed, because the whole file rests on it:
     *
     *   vendor, offer NGN/GHS     buyer pays NGN, receives GHS   -> base NGN,  quote GHS
     *   platform, buying naira    buyer pays GHS, receives NGN   -> base GHS,  quote NGN
     *   platform, buying cedi     buyer pays NGN, receives GHS   -> base NGN,  quote GHS
     *
     * The vendor flow stores the naira leg and the platform flow stores the cedi leg, and both fall out of
     * the same sentence. Only the stored leg differs, and that is just which of the two numbers was kept.
     *
     * $viewer is 'buyer' or 'seller'. On a platform trade the trader is whichever side they are -- our book
     * makes them the buyer -- and on a vendor trade they are one or the other, which is what the two
     * directions of the same screen are.
     *
     * @param  object  $trade  a trade_view row: needs fiat_amount, fiat_asset, rate, rate_quote_asset
     *                         and rate_base_asset
     * @return array{send: string, receive: string, send_minor: int, receive_minor: int, send_asset: string, receive_asset: string, fee: string|null, fee_name: string|null, fee_bearer: string, send_total: string|null, send_total_minor: int|null}
     */
    public static function forTrade(object $trade, string $viewer = 'buyer'): array
    {
        $base = $trade->rate_base_asset ?? null;
        $quote = $trade->rate_quote_asset ?? null;

        if ($base === null || $quote === null || $base === $quote) {
            throw new \RuntimeException("trade {$trade->id} has no rate pair, so the two sides cannot be named");
        }

        $sendAsset = $viewer === 'seller' ? $quote : $base;
        $receiveAsset = $viewer === 'seller' ? $base : $quote;
        $stored = (int) $trade->fiat_amount;

        [$sendMinor, $sendAsset] = self::convert($trade, $stored, $sendAsset);
        [$receiveMinor, $receiveAsset] = self::convert($trade, $stored, $receiveAsset);

        // The commission is READ, never recomputed. The ledger froze fee_minor_units at creation and the
        // trader was shown it; a figure derived here could disagree with the amount actually charged, and
        // a screen that quotes one number and charges another is the exact failure 011 records.
        $feeMinor = isset($trade->fee_minor_units) ? (int) $trade->fee_minor_units : 0;
        $feeAsset = $trade->fee_asset ?? null;
        $feeName = self::name($feeAsset);
        $bearer = (string) ($trade->fee_bearer ?? 'buyer');

        // THE TOTAL, in whole minor units, and only when the commission is on the buyer and lands in the
        // currency they are already paying in.
        //
        // "Send 13.50 plus the fee" is how a mobile-money payment gets underpaid: the trader does the sum
        // on a phone, rounds it, and the payment arrives a few pesewas short, which cannot settle and
        // cannot be quietly corrected. The instruction has to carry the one number to type, so it is
        // added here in MINOR UNITS and formatted once -- never by adding two formatted strings, which is
        // arithmetic on a display format.
        $sendTotalMinor = null;
        $sendTotal = null;
        if ($feeMinor > 0 && $bearer === 'buyer' && $feeAsset === $sendAsset) {
            $sendTotalMinor = $sendMinor + $feeMinor;
            $sendTotal = RateBoard::formatMoney($sendTotalMinor, $sendAsset);
        }

        return [
            'send' => RateBoard::formatMoney($sendMinor, $sendAsset),
            'receive' => RateBoard::formatMoney($receiveMinor, $receiveAsset),
            'send_minor' => $sendMinor,
            'receive_minor' => $receiveMinor,
            'send_asset' => $sendAsset,
            'receive_asset' => $receiveAsset,
            'fee' => $feeMinor > 0 ? RateBoard::formatMoney($feeMinor, (string) $feeAsset) : null,
            'fee_name' => $feeName,
            'fee_bearer' => $bearer,
            'send_total' => $sendTotal,
            'send_total_minor' => $sendTotalMinor,
        ];
    }

    /**
     * The plain name for a currency, or the code when it is one we have no word for.
     *
     * The trader reads "cedi", not "GHS". Same map the payment-instruction card uses, in one place, so
     * the fee cannot be named differently from the legs it sits between.
     */
    private static function name(?string $asset): ?string
    {
        if ($asset === null) {
            return null;
        }

        return ['GHS' => 'cedi', 'NGN' => 'naira'][$asset] ?? $asset;
    }
}

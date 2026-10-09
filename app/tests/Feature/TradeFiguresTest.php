<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trade;
use App\Services\RateBoard;
use App\Services\TradeFigures;
use Tests\TestCase;

/**
 * The two figures on a trade screen, tested without a browser.
 *
 * These live in their own class because the arithmetic is the kind that is quietly out by a factor of
 * a hundred when minor units meet a major-per-major rate. The first version of this screen showed the
 * same number for both sides, and the only thing that caught it was a test asserting on rendered HTML.
 */
class TradeFiguresTest extends TestCase
{
    public function test_the_cedi_side_is_derived_from_the_agreed_rate(): void
    {
        // ₦2,000.00 at 148.20 naira per cedi is ₵13.50.
        $figures = $this->figures(200000, 'NGN', 148.2);

        $this->assertSame('2,000.00 NGN', $figures['receive']);
        $this->assertSame('13.50 GHS', $figures['send']);
    }

    public function test_dividing_minor_units_by_the_rate_is_the_bug_this_class_exists_to_prevent(): void
    {
        // Spelled out rather than left implicit. 200000 / 148.2 is 1349.5 minor units, which read as
        // 1,349.50 cedi -- a hundred times what the trader owes. Same inputs, same rate, factor of 100.
        $figures = $this->figures(200000, 'NGN', 148.2);

        $wrongWay = 200000 / 148.2;

        $this->assertGreaterThan(1300, $wrongWay, 'the naive division really is ~1,350');
        $this->assertSame(1350, $figures['send_minor'], 'the correct figure is 13.50 cedi = 1350 minor units');
    }

    public function test_the_two_sides_really_are_different_numbers(): void
    {
        $figures = $this->figures(200000, 'NGN', 148.2);

        $this->assertNotSame($figures['send'], $figures['receive']);
    }

    public function test_amounts_are_rendered_with_thousands_separators(): void
    {
        // Money is read in columns. An unseparated 2000.00 is harder to scan and reads as a different
        // number at a glance than 2,000.00 does.
        $this->assertStringContainsString(',', $this->figures(200000, 'NGN', 148.2)['receive']);
    }

    public function test_a_zero_rate_is_refused_rather_than_rendering_zero(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->figures(200000, 'NGN', 0.0);
    }

    public function test_a_rate_with_many_places_still_renders_a_usable_figure(): void
    {
        // The reverse leg: one naira buys 0.0067567568 cedi, so a small trade in naira is a cedi
        // amount that must not round away to nothing.
        // 1,000,000 minor units is ₦10,000.00 -- six figures, not four. Reading it as 1,000.00 would
        // be my own arithmetic slip in the test, which is the same class of error as the one the class
        // exists to prevent.
        $figures = $this->figures(1000000, 'NGN', 0.006756756756756756);

        $this->assertSame('10,000.00 NGN', $figures['receive']);
        $this->assertGreaterThan(0, $figures['send_minor'], 'the cedi side must not vanish');
    }

    public function test_a_vendor_rate_is_read_in_the_direction_it_was_quoted(): void
    {
        /**
         * The bug that mattered, pinned on both sides of the same pair.
         *
         * The core stores two rates in one column. openAgainstUs reads the card for (GHS,NGN) and gets
         * 148.2 NGN per cedi; accept_offer reads it for (NGN,GHS) and gets 0.0067567 cedi per naira. Both
         * rows say fiat_asset = NGN and a naira amount, so the data cannot tell them apart and this class
         * had to guess. It guessed divide.
         *
         * N500,000 is then either C13.50 (wrong, divide) or C3,378.38 (right, multiply). The screen said
         * C74,000,000, which is neither, because the real fixture quotes the rate from the other end.
         */
        $vendor = TradeFigures::forTrade((object) [
            'id' => 6,
            'fiat_amount' => '50000000',
            'fiat_asset' => 'NGN',
            'rate' => '0.006756756756756756',
            'rate_quote_asset' => 'GHS',
        ]);

        $this->assertSame('3,378.38 GHS', $vendor['send'], 'a vendor rate counts cedi per naira, so multiply');
        $this->assertSame('500,000.00 NGN', $vendor['receive']);

        $platform = TradeFigures::forTrade((object) [
            'id' => 7,
            'fiat_amount' => '50000000',
            'fiat_asset' => 'NGN',
            'rate' => '148.2',
            'rate_quote_asset' => 'NGN',
        ]);

        $this->assertSame('3,373.82 GHS', $platform['send'], 'a platform rate counts naira per cedi, so divide');

        // The check that makes the pair meaningful: the same money, quoted from either end, is the same
        // money. 500,000 naira is C3,378.38 by one route and C3,373.82 by the other -- the small gap is
        // 148.2 against 1/0.0067567, and neither is a factor of twenty thousand.
        $this->assertEqualsWithDelta(337838, $vendor['send_minor'], 600, 'both directions agree on one value');
    }

    public function test_a_vendor_buyer_is_told_they_send_naira_and_receive_cedi(): void
    {
        /**
         * The two figures above were right and the LABELS were backwards.
         *
         * kudi-core/003, in one sentence: "A buyer in Accra pays 300,000 naira into a seller's Nigerian
         * bank account; the seller owes cedis into the buyer's mobile money." So the buyer sends the fiat
         * leg and receives the cedi; the seller is the mirror.
         *
         * forTrade was never told who was looking, so it handed both of them the seller's view. The buyer
         * was told to send cedi while holding naira.
         */
        $trade = (object) [
            'id' => 6,
            'trade_type' => 'vendor_vendor',
            'fiat_amount' => '50000000',
            'fiat_asset' => 'NGN',
            'rate' => '0.006756756756756756',
            'rate_quote_asset' => 'GHS',
        ];

        $buyer = TradeFigures::forTrade($trade, 'buyer');
        $this->assertSame('500,000.00 NGN', $buyer['send'], 'the buyer sends naira');
        $this->assertSame('3,378.38 GHS', $buyer['receive'], 'and receives cedi into their own account');

        $seller = TradeFigures::forTrade($trade, 'seller');
        $this->assertSame('3,378.38 GHS', $seller['send'], 'the seller owes cedi');
        $this->assertSame('500,000.00 NGN', $seller['receive'], 'and receives the naira');

        // The same money, both ways round. A buyer and a seller who disagree about the amount is a
        // dispute waiting to happen, so this is asserted rather than left to the eye.
        $this->assertSame($buyer['send_minor'], $seller['receive_minor']);
        $this->assertSame($buyer['receive_minor'], $seller['send_minor']);
    }

    public function test_a_platform_trade_keeps_its_existing_wording(): void
    {
        // platform_inventory is deliberately NOT role-aware. What its fiat leg means is not written down
        // anywhere in the core, so inventing a direction here would be a guess with a real number on it.
        // Pinned so the day someone does decide, this test says they changed it on purpose.
        $trade = (object) [
            'id' => 9,
            'trade_type' => 'platform_inventory',
            'fiat_amount' => '200000',
            'fiat_asset' => 'NGN',
            'rate' => '148.2',
            'rate_quote_asset' => 'NGN',
        ];

        $this->assertSame('13.50 GHS', TradeFigures::forTrade($trade, 'buyer')['send']);
        $this->assertSame('13.50 GHS', TradeFigures::forTrade($trade, 'seller')['send']);
    }

    public function test_cedi_value_is_derived_the_same_way_for_everyone_who_asks(): void
    {
        /**
         * One rule, asked for rather than reimplemented.
         *
         * The operations console needed the agreed value too, and the obvious way to get it was to copy
         * the multiply-or-divide decision out of forTrade. That is precisely how one of two copies goes
         * stale: the bug this whole file exists to prevent was a rule living in one place while the rest of
         * the app guessed. So the neutral question is its own method and forTrade uses it.
         */
        $vendor = (object) [
            'id' => 6, 'trade_type' => 'vendor_vendor', 'fiat_amount' => '50000000', 'fiat_asset' => 'NGN',
            'rate' => '0.006756756756756756', 'rate_quote_asset' => 'GHS',
        ];
        $platform = (object) [
            'id' => 7, 'trade_type' => 'platform_inventory', 'fiat_amount' => '50000000', 'fiat_asset' => 'NGN',
            'rate' => '148.2', 'rate_quote_asset' => 'NGN',
        ];

        $this->assertSame(337838, TradeFigures::cediMinor($vendor));
        $this->assertSame(337382, TradeFigures::cediMinor($platform));

        // Whichever way the question is asked, the answer is the same one.
        $this->assertSame(TradeFigures::cediMinor($vendor), TradeFigures::forTrade($vendor, 'seller')['send_minor']);
        $this->assertSame(TradeFigures::cediMinor($vendor), TradeFigures::forTrade($vendor, 'buyer')['receive_minor']);
    }

    public function test_a_rate_quoted_in_neither_leg_is_refused_rather_than_guessed(): void
    {
        // Same reasoning as a zero rate: an authoritative-looking wrong number on a money screen is worse
        // than a refusal that says what is wrong with the data.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neither its fiat asset');

        TradeFigures::forTrade((object) [
            'id' => 8,
            'fiat_amount' => '500000',
            'fiat_asset' => 'NGN',
            'rate' => '148.2',
            'rate_quote_asset' => 'EUR',
        ]);
    }

    /** @return array{send: string, receive: string, send_minor: int, receive_minor: int} */
    public function test_an_amount_is_rendered_with_its_own_currency_decimals(): void
    {
        /**
         * Trade::fiat_display divided by 100 and crypto_display by 1,000,000, both hardcoded.
         *
         * That is right for naira, cedi and USDT, and wrong by a factor of a hundred for anything that is
         * not two decimals. RateBoard::formatMoney exists to render an amount in its own currency's
         * decimals, and its comment names this exact trap: "hardcoding 100 gets that wrong silently". The
         * model did the hardcoded thing on the operations console, which is the screen a dispute is read
         * off, so this is not cosmetic.
         */
        // The same stored 200000 is 2,000.00 naira and 200,000.00 of a currency with no decimals.
        $this->assertSame('2,000.00 NGN', RateBoard::formatMoney(200000, 'NGN'));

        $trade = new Trade;
        $trade->fiat_amount = 200000;
        $trade->fiat_asset = 'NGN';
        $this->assertSame('2,000.00 NGN', $trade->fiat_display);

        // USDT has six decimals, so 200000 minor units is 0.200000 USDT -- and it is shown in full. The
        // old hardcoded divide-by-a-million also got that magnitude right and then rounded it to "0.20",
        // throwing away four of the six places the currency actually has.
        $trade->crypto_amount = 200000;
        $trade->crypto_asset = 'USDT';
        $this->assertSame('0.200000 USDT', $trade->crypto_display);

        // A null leg stays a dash rather than becoming a zero somebody might read as money.
        $empty = new Trade;
        $empty->fiat_amount = null;
        $this->assertSame('—', $empty->fiat_display);
    }

    private function figures(int $amount, string $asset, float $rate): array
    {
        return TradeFigures::forTrade((object) [
            'id' => 1,
            'fiat_amount' => (string) $amount,
            'fiat_asset' => $asset,
            'rate' => (string) $rate,
        ]);
    }
}

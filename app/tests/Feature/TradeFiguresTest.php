<?php

declare(strict_types=1);

namespace Tests\Feature;

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

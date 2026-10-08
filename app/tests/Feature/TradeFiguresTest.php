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

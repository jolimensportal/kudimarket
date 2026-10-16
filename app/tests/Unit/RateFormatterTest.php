<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RateFormatter;
use PHPUnit\Framework\TestCase;

/**
 * A rate is the one number on this product a reader is asked to trust, so it is rendered at a stored
 * precision and never as a raw float.
 *
 * The defect this pins: the live landing page printed 0.006756756756756756, and rendered 148.20 in
 * one place and 148.2 in another on the same page. Both came from the same cause, which was a
 * hand-rolled rtrim(rtrim($rate, '0'), '.') in the view doing the formatting nobody asked it to.
 */
class RateFormatterTest extends TestCase
{
    public function test_a_direct_rate_renders_with_two_decimals(): void
    {
        $this->assertSame('148.20', RateFormatter::format(148.2));
        $this->assertSame('150.50', RateFormatter::format(150.5));
        $this->assertSame('1.00', RateFormatter::format(1));
    }

    public function test_an_inverse_rate_renders_with_six_decimals(): void
    {
        // The value that shipped on the live page as 0.006756756756756756.
        $this->assertSame('0.006757', RateFormatter::format(1 / 148.0));
    }

    public function test_the_same_rate_never_appears_two_ways(): void
    {
        // 148.2 and 148.20 both appeared on the live page. One stored precision ends that.
        $this->assertSame(RateFormatter::format(148.2), RateFormatter::format(148.20));
    }

    public function test_no_rate_ever_prints_more_than_six_decimals(): void
    {
        $decimals = strlen(substr(strrchr(RateFormatter::format(1 / 3), '.'), 1));

        $this->assertLessThanOrEqual(6, $decimals);
    }

    public function test_a_corridor_may_override_the_default_precision(): void
    {
        $this->assertSame('0.0068', RateFormatter::format(1 / 148.0, 4));
    }

    public function test_it_accepts_the_decimal_string_the_database_returns(): void
    {
        // publishedRates() casts to string, so the formatter is handed a string in production.
        $this->assertSame('148.20', RateFormatter::format('148.20'));
        $this->assertSame('0.006757', RateFormatter::format('0.006756756756756756'));
    }
}

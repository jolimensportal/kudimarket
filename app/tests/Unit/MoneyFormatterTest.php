<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MoneyFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Amounts, not rates.
 *
 * A rate is a price and reads bare: 148.20. An amount of money is read by people counting digits,
 * and 14,820.00 is checked at a glance where 14820.00 is not. RateFormatter deliberately does not
 * group, so the two cannot be the same class.
 */
class MoneyFormatterTest extends TestCase
{
    public function test_it_groups_thousands(): void
    {
        $this->assertSame('14,820.00', MoneyFormatter::format(14820));
        $this->assertSame('100.00', MoneyFormatter::format(100));
        $this->assertSame('1,048,576.00', MoneyFormatter::format(1048576));
    }

    public function test_it_defaults_to_two_decimals(): void
    {
        $this->assertSame('1,500.00', MoneyFormatter::format('1500'));
        $this->assertSame('0.05', MoneyFormatter::format(0.05));
    }

    public function test_it_never_returns_a_raw_float(): void
    {
        // 0.1 + 0.2 is the canonical reason a money product cannot format the sum in place.
        $this->assertSame('0.30', MoneyFormatter::format(0.1 + 0.2));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every CSS custom property the trader layout USES must be DEFINED in that same file.
 *
 * The design linter checks that declared colours match the brand palette. It cannot check the other
 * direction: it does not read var(--name) references, so a stylesheet can name a token that does not
 * exist and lint clean. It happened here -- a notice background citing --plum-100 and a text colour
 * citing --plum-700, neither of which was ever declared. The rule rendered with no background and
 * inherited text, and nothing failed.
 *
 * This closes that direction: a token that is read but not defined is an error, not a fallback.
 */
class DesignTokensTest extends TestCase
{
    public function test_the_trader_layout_declares_every_token_it_uses(): void
    {
        $css = file_get_contents(resource_path('views/layouts/trader.blade.php'));

        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $used);
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $css, $declared);

        $missing = array_values(array_unique(array_diff($used[1], $declared[1])));

        $this->assertSame(
            [],
            $missing,
            'these tokens are used in the trader layout but never declared, so they resolve to nothing: '
                .implode(', ', $missing)
        );
    }

    public function test_the_laid_out_colours_are_the_locked_brand_palette(): void
    {
        // The locked palette, spelled out so a well-meaning change to the CSS has to argue with it.
        $css = file_get_contents(resource_path('views/layouts/trader.blade.php'));

        foreach (['#331931', '#5E2D5A', '#FCF6EE', '#185B37', '#933331'] as $locked) {
            $this->assertStringContainsStringIgnoringCase($locked, $css);
        }
    }
}

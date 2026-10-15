<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every CSS custom property a layout USES must be DEFINED somewhere it can see.
 *
 * The design linter checks that declared colours match the brand palette. It cannot check the other
 * direction: it does not read var(--name) references, so a stylesheet can name a token that does not
 * exist and lint clean. It happened here -- a notice background citing --plum-100 and a text colour
 * citing --plum-700, neither of which was ever declared. The rule rendered with no background and
 * inherited text, and nothing failed.
 *
 * This closes that direction: a token that is read but not defined is an error, not a fallback.
 *
 * The tokens moved out of layouts/trader.blade.php into partials/tokens.blade.php when the public
 * landing page needed the same palette and a second copy would have drifted. Both files are read here,
 * because both ship the same rules -- which is exactly the pair that must be checked together.
 */
class DesignTokensTest extends TestCase
{
    public function test_the_trader_layout_declares_every_token_it_uses(): void
    {
        $css = $this->tokens().' '.$this->layout('trader');

        $missing = array_values(array_unique(array_diff(
            $this->usedIn($css),
            $this->declaredIn($css)
        )));

        $this->assertSame(
            [],
            $missing,
            'these tokens are used in the trader layout but never declared, so they resolve to nothing: '
                .implode(', ', $missing)
        );
    }

    public function test_the_marketing_layout_declares_every_token_it_uses(): void
    {
        // Same rule for the public page. It was added after the original test existed and shipped with
        // a missing token of its own, which is the argument for making the check a loop rather than a
        // single hand-picked file.
        $css = $this->tokens().' '.$this->layout('marketing');

        $missing = array_values(array_unique(array_diff(
            $this->usedIn($css),
            $this->declaredIn($css)
        )));

        $this->assertSame(
            [],
            $missing,
            'these tokens are used in the marketing layout but never declared, so they resolve to nothing: '
                .implode(', ', $missing)
        );
    }

    public function test_the_laid_out_colours_are_the_locked_brand_palette(): void
    {
        // The locked palette, spelled out so a well-meaning change to the CSS has to argue with it.
        $css = $this->tokens();

        foreach (['#331931', '#5E2D5A', '#FCF6EE', '#185B37', '#933331'] as $locked) {
            $this->assertStringContainsStringIgnoringCase($locked, $css);
        }
    }

    public function test_a_token_is_defined_in_one_place(): void
    {
        // The whole reason the tokens were extracted. Two copies of a palette is one copy waiting to
        // disagree with the other, and the disagreement is invisible until a page renders wrong.
        $tokens = $this->tokens();
        $layouts = $this->layout('trader').' '.$this->layout('marketing');

        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $layouts, $inLayouts);

        $this->assertSame(
            [],
            array_values(array_unique($inLayouts[1])),
            'tokens are declared in a layout; they belong in partials/tokens.blade.php'
        );

        $this->assertNotSame('', trim($tokens), 'the tokens partial must not be empty');
    }

    public function test_the_three_token_layers_are_declared_in_the_stylesheet(): void
    {
        $css = $this->stylesheet('resources/css/tokens.css');

        // Layer 1: primitives. The only values a designer edits.
        foreach (['--plum-900', '--plum-600', '--bone', '--paper'] as $token) {
            $this->assertStringContainsString($token.':', $css, "primitive {$token} is missing");
        }

        // Layer 2: semantic. Meaning, not colour.
        foreach (['--ink', '--surface', '--line', '--action-primary'] as $token) {
            $this->assertStringContainsString($token.':', $css, "semantic token {$token} is missing");
        }

        // Layer 3: component.
        foreach (['--card-bg', '--control-height', '--tap-target'] as $token) {
            $this->assertStringContainsString($token.':', $css, "component token {$token} is missing");
        }

        // Every semantic token must reference a primitive, never a raw hex. This is the property that
        // makes a designer swap propagate instead of half-applying: if --ink were a literal, changing
        // --plum-900 would leave the body text behind.
        $this->assertStringNotContainsString(
            '--ink: #',
            $css,
            '--ink must be derived from a primitive, not written as a raw hex'
        );
    }

    public function test_layout_css_declares_the_breakpoints_and_the_container(): void
    {
        $css = $this->stylesheet('resources/css/layout.css');

        foreach ([640, 768, 1024, 1280, 1536] as $breakpoint) {
            $this->assertStringContainsString(
                "min-width: {$breakpoint}px",
                $css,
                "breakpoint {$breakpoint} is missing"
            );
        }

        $this->assertStringContainsString('var(--container-max)', $css);
        $this->assertStringContainsString('var(--container-pad)', $css);
    }

    public function test_layout_css_defines_the_three_grid_primitives(): void
    {
        $css = $this->stylesheet('resources/css/layout.css');

        $this->assertStringContainsString('.stack', $css);
        $this->assertStringContainsString('.grid-auto', $css);
        $this->assertStringContainsString('.split', $css);

        // auto-fit, not a fixed column count: a fixed count is what produced the phone page on a
        // desktop, because it could not adapt to a wider canvas.
        $this->assertStringContainsString('auto-fit', $css);
    }

    public function test_base_css_preserves_the_deliberate_constraints(): void
    {
        $css = $this->stylesheet('resources/css/base.css');

        // Tabular lining figures. A rate board with proportional digits looks like a typo.
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);

        // Safe-area insets. A notched phone cuts into the top of the header and the bottom of the
        // page; env() is 0 on a device with no inset, so this costs nothing on a desktop.
        $this->assertStringContainsString('env(safe-area-inset-', $css);

        // 16px base size so iOS does not zoom the viewport on focus.
        $this->assertStringContainsString('font-size: 16px', $css);
    }

    private function tokens(): string
    {
        return (string) file_get_contents(resource_path('views/partials/tokens.blade.php'));
    }

    private function stylesheet(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function layout(string $name): string
    {
        return (string) file_get_contents(resource_path("views/layouts/{$name}.blade.php"));
    }

    /** @return list<string> */
    private function usedIn(string $css): array
    {
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $used);

        return $used[1];
    }

    /** @return list<string> */
    private function declaredIn(string $css): array
    {
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $css, $declared);

        return $declared[1];
    }
}

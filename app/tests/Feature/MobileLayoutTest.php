<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Tests\TestCase;

/**
 * The trader app is used on budget Android handsets, and the screens were drawn at 305px.
 *
 * These tests cannot run a browser, so they assert the things that are checkable in the served HTML --
 * the CSS contract the layout promises -- and leave the visual confirmation to a human at a real
 * viewport. That split is deliberate: asserting on rendered pixel geometry from PHPUnit means
 * asserting on a headless browser's opinion of it, which is a test that passes when nothing is wrong.
 *
 * What actually happened when this was written, measured in Chrome DevTools at 390x844:
 *
 *   - nothing overflowed horizontally on any screen. The layout is flex-based and wraps. An earlier
 *     claim in the repo that the app was "unusable on a phone" was overstated and is not what this
 *     file supports.
 *   - three things were under the 44px tap floor: the sign-out button at 36px, and the standalone
 *     back-links at 17-19px. Those are the cases asserted below.
 *
 * The subtle one is the link selector. `p > a[href]:only-child` LOOKS like "a link standing alone in
 * its paragraph" and is the opposite: :only-child counts element children and ignores text nodes, so
 * it matches a paragraph of running prose that happens to contain exactly one link. Applying min-height
 * to that inflated the line spacing of every prose paragraph on the site. The rule is therefore a class,
 * .standalone-link, asserted by the author on purpose.
 */
class MobileLayoutTest extends TestCase
{
    public function test_the_layout_carries_a_viewport_meta_tag(): void
    {
        // Without this the phone renders a 980px desktop page and scales it down to nothing readable.
        $html = $this->layoutHtml();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('width=device-width', $html);
    }

    public function test_the_base_font_is_16px_so_ios_does_not_zoom_on_focus(): void
    {
        $this->assertMatchesRegularExpression(
            '/body\s*\{[^}]*font-size:\s*16px/',
            $this->layoutCss(),
            'below 16px iOS zooms the viewport when a field is focused, and the trader cannot read the page'
        );
    }

    public function test_controls_meet_the_44px_tap_floor(): void
    {
        $html = $this->layoutCss();

        // The form controls were already at 44px (with the reason written next to them). This asserts
        // the floor still exists so a later edit cannot quietly shrink it.
        $this->assertMatchesRegularExpression(
            '/select,\s*button,\s*input\[type=text\][^{]*\{[^}]*min-height:\s*44px/',
            $html,
            'form controls must keep a 44px minimum height'
        );
    }

    public function test_the_sign_out_button_is_not_left_under_the_tap_floor(): void
    {
        // It was 36px, inline in the header. Sign-out is the control a trader uses most in a hurry,
        // often one-handed, often in a queue.
        $html = $this->layoutCss();

        $this->assertStringNotContainsString('min-height:36px', $html, 'the sign-out button regressed to 36px');
    }

    public function test_standalone_links_get_a_tap_target_and_prose_links_do_not(): void
    {
        $css = $this->layoutCss();

        $this->assertStringContainsString('.standalone-link', $css);

        // The trap this guards: a blanket a[href] rule, or the :only-child selector that behaves the
        // same way for paragraphs of running prose. Either one gives every in-prose link a 44px line
        // box and the copy on the page falls apart.
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|})\s*a\[href\]\s*\{[^}]*min-height/m',
            $css,
            'a blanket a[href] min-height inflates the line spacing of prose paragraphs'
        );
        $this->assertStringNotContainsString(
            ':only-child',
            $css,
            ':only-child ignores text nodes, so it matches links inside running prose'
        );
    }

    public function test_the_safe_area_insets_are_present_for_notched_phones(): void
    {
        // A notched phone cuts into the top of the header and the bottom of the last control. env()
        // is 0 on a device with no inset, so this costs nothing anywhere else.
        $html = $this->layoutCss();

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, 'env(safe-area-inset'),
            'a notched phone puts the wordmark under the notch and the last button under the home indicator'
        );
    }

    public function test_the_layout_declares_a_narrow_breakpoint(): void
    {
        // Measured rather than assumed: at 390px the stats dl holds two columns fine, so the
        // breakpoint is 360px, not 400px. Stacking at 390 would make every screen longer for nothing.
        $html = $this->layoutCss();

        $this->assertStringContainsString('@media (max-width: 360px)', $html);
        $this->assertStringNotContainsString('@media (max-width: 400px)', $html);
    }

    public function test_a_traders_balance_list_is_not_forced_into_two_columns(): void
    {
        // The dl grid defaults to two columns, which put "held for a trade in progress" beside
        // "GHS you can use" on a phone and wrapped both labels mid-phrase.
        //
        // The trader is given a balance first, because the balances block sits inside an @forelse:
        // with an empty account the screen renders the "Nothing in your account yet" branch and there
        // is no dl to find. Asserting on the empty case would have passed against a view that never
        // renders the list at all.
        $trader = Trader::factory()->create();
        \DB::connection('pgsql_admin')->selectOne(
            'select ledger_deposit(?, ?, ?, ?)',
            [$trader->email, 'GHS', 50000, 'mobile-layout-test']
        );

        $this->actingAs($trader, 'trader');

        $html = $this->get('/trade/sell')->assertOk()->getContent();

        $this->assertStringContainsString('GHS you can use', $html, 'the balance list did not render');
        $this->assertStringContainsString('dl class="stack"', $html);
    }

    private function layoutHtml(): string
    {
        return (string) file_get_contents(
            resource_path('views/layouts/trader.blade.php')
        );
    }

    /**
     * The layout's CSS with its comments stripped.
     *
     * The first version of these tests asserted against the whole file and failed on their own
     * documentation: the explanation of why :only-child is wrong contains the literal text
     * ":only-child", so the test that forbids it was forbidding its own comment. A rule test that
     * cannot tell a selector from a sentence about a selector is not testing the stylesheet.
     */
    private function layoutCss(): string
    {
        $html = $this->layoutHtml();

        // The <style> block, then its CSS comments. Blade comments ({{-- --}}) are HTML and live
        // outside it; the CSS comments are /* ... */ inside.
        preg_match('/<style>(.*?)<\/style>/s', $html, $style);

        return (string) preg_replace('!/\*.*?\*/!s', '', $style[1] ?? '');
    }
}

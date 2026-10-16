<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The reported defect, pinned.
 *
 * The trader layout capped its content at 720px and defined no desktop breakpoint, so a desktop
 * browser received a phone page with empty margins either side. These tests fail if the cap is
 * reintroduced, or if either layout goes back to carrying its own inline CSS and drifting from the
 * shared stylesheets.
 */
class ResponsiveLayoutTest extends TestCase
{
    public function test_the_trader_layout_no_longer_caps_its_content_at_720px(): void
    {
        $this->assertStringNotContainsString(
            'max-width: 720px',
            $this->layout('trader'),
            'the 720px mobile cap is back; a desktop will get a phone page again'
        );
    }

    public function test_the_trader_layout_no_longer_carries_an_inline_style_block(): void
    {
        // Strip Blade comments first. The comment above the @vite directive explains *why* the styles
        // moved out, and it necessarily contains the words "<style> block". Asserting on the raw
        // source would fail on our own explanation of the change.
        $markup = $this->withoutBladeComments($this->layout('trader'));

        // Match the <style> tag specifically, not any style= attribute: the sign-out button carries a
        // legitimate inline style and must be allowed to keep it.
        $this->assertDoesNotMatchRegularExpression(
            '/<style[\s>]/i',
            $markup,
            'the trader layout is carrying its own <style> block again; the shared stylesheets must be the only source'
        );
    }

    public function test_the_trader_layout_uses_the_reading_column_not_the_container(): void
    {
        // Deliberate asymmetry. The marketing page is a document that wants a content measure, so it
        // uses .container. The trader app is a working surface whose header is full-bleed plum and
        // whose body is one readable column, so it uses .read-col, which releases at lg. Putting a
        // container on the app would cap the full-bleed header, which is the bug the old 720px cap
        // was a symptom of rather than a cause.
        $this->assertStringContainsString('read-col', $this->layout('trader'));
        $this->assertStringNotContainsString('class="container', $this->layout('trader'));
    }

    public function test_the_marketing_layout_uses_the_shared_container(): void
    {
        $this->assertStringContainsString('class="container', $this->layout('marketing'));
    }

    public function test_the_marketing_layout_no_longer_carries_an_inline_style_block(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<style[\s>]/i',
            $this->withoutBladeComments($this->layout('marketing')),
            'the marketing layout is carrying its own <style> block again'
        );
    }

    public function test_the_printed_rate_is_no_longer_hand_stripped(): void
    {
        // The defect: rtrim(rtrim($rate, '0'), '.') rendered 148.20 as 148.2 and printed the inverse
        // rate at full float length. Formatting now comes from RateFormatter, in one place.
        $view = (string) file_get_contents(resource_path('views/public/landing.blade.php'));

        $this->assertStringNotContainsString('rtrim(rtrim($rates', $view);
        $this->assertStringContainsString('RateFormatter::format', $view);
    }

    public function test_the_landing_page_declares_no_removed_section_or_image(): void
    {
        $view = (string) file_get_contents(resource_path('views/public/landing.blade.php'));

        // The exchange screenshots. They proved a product this page no longer describes.
        foreach (['board.png', 'buy.png', 'history.png'] as $image) {
            $this->assertStringNotContainsString($image, $view, "{$image} is still referenced");
        }

        // The dead anchors the doors used to point at.
        foreach (['#send"', '#earn"', '#trust"'] as $anchor) {
            $this->assertStringNotContainsString("href=\"{$anchor}", $view);
        }
    }

    public function test_the_doors_point_at_anchors_that_exist(): void
    {
        $view = (string) file_get_contents(resource_path('views/public/landing.blade.php'));

        preg_match_all('/href="#([a-z]+)"/', $view, $hrefs);

        foreach (array_unique($hrefs[1]) as $anchor) {
            $this->assertStringContainsString(
                "id=\"{$anchor}\"",
                $view,
                "the page links to #{$anchor} but nothing carries that id"
            );
        }
    }

    private function layout(string $name): string
    {
        $path = resource_path("views/layouts/{$name}.blade.php");
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Blade comments are prose, not markup. A comment is allowed to name a tag it is describing
     * without that tag existing in the output, so tests that assert on structure must read the
     * markup the browser would actually receive.
     */
    private function withoutBladeComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }
}

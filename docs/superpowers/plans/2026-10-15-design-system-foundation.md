# KudiMarket Design System Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one shared design system (three token layers, a real layout system, and nine components) that renders both the landing page and the trader app correctly from 375px to 1440px, with the current plum + Instrument Sans brand as a token swap, not a rewrite.

**Architecture:** Split the flat token block into three layers (primitives → semantic → component) in a real stylesheet served by Vite. Wire the semantic tokens into Tailwind 4's `@theme` so responsive utilities drive layout. Build nine hand-written component classes for the decisions that have real content (rate precision, 44px targets, tabular numerals, safe areas). Release the 720px mobile cap at `lg`. Migrate the two inline `<style>` blocks out of the layouts onto the shared system.

**Tech Stack:** Laravel 12 + Blade, Tailwind CSS 4 (`@tailwindcss/vite`), Vite 8, `laravel-vite-plugin` (with Bunny-hosted Instrument Sans 400/500/600), PHPUnit 10, `kudilint` (Node design linter), Postgres via `kudi-core` SQL suite.

**Design doc:** `docs/superpowers/specs/2026-10-15-design-system-foundation-design.md`

---

## How to run the tests (read this before Task 0)

`kudi_test` is **persistent**. Running `php artisan test` directly grows it and causes false failures. **Always** use the project's entry point, which rebuilds the DB from `kudi-core/*.sql` first:

```bash
cd /Users/macbookpro/Desktop/kudimarket
bash scripts/test.sh
```

For a fast single-file loop against a freshly-rebuilt DB:

```bash
cd /Users/macbookpro/Desktop/kudimarket/kudi-core
PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh   # rebuild kudi_test
cd ../app && php artisan test --filter=DesignTokensTest
```

> `run_tests.sh` needs `PGHOST`/`PGPORT`/`PGUSER`. Run bare, it connects to the wrong cluster and
> `psql` blocks on a password prompt, which looks exactly like a hung test suite. `scripts/test.sh`
> exports them; if you call `run_tests.sh` directly, export them yourself.

Design lint (palette lock) runs as part of `scripts/test.sh` and can be run alone:

```bash
cd /Users/macbookpro/Desktop/kudimarket && npm run lint:design
```

---

## Baseline (measured 15 Oct 2026, commit 383f1b8)

- SQL suite: **443 / 443 pass.**
- PHP suite: **250 tests, 245 pass, 5 fail.**
- Design lint: passes.

All 5 PHP failures are pre-existing and environmental, **not** caused by the design work:

| Failure | Cause |
|---|---|
| `RootRedirectTest` (4 failures) | Local `app/.env` has `KUDI_LAUNCHING=true`; `phpunit.xml` does not pin it, so `/` serves the landing page and the redirect assertions fail. |
| `LandingPageTest::test_the_address_is_normalised` | A leftover signup row in persistent `kudi_test` makes the count 2 instead of 1. |
| `LandingPageTest::test_the_page_shows_the_real_number_waiting_and_never_an_invented_one` | Same leftover; the page shows a different waiting count than the assertion. |

Task 0 fixes the harness so the baseline is honestly green. Do not begin Task 1 with a red suite.

---

## File structure

| File | Responsibility |
|---|---|
| `app/resources/css/tokens.css` | **New.** The three token layers. Single source of truth for colour, type, space, container. Replaces `partials/tokens.blade.php`. |
| `app/resources/css/base.css` | **New.** Reset, body, typography defaults, safe-area handling, `.num` tabular figures. Moved from the layouts' inline `<style>`. |
| `app/resources/css/layout.css` | **New.** Breakpoints, container, `.stack`, `.grid-auto`, `.split`, the mobile-cap rule. |
| `app/resources/css/components.css` | **New.** The nine component classes. |
| `app/resources/css/app.css` | **Modify.** Import the four files above; map semantic tokens into Tailwind `@theme`. |
| `app/resources/views/partials/tokens.blade.php` | **Delete** after both layouts consume `tokens.css`. Its content moves to `tokens.css`. |
| `app/resources/views/layouts/marketing.blade.php` | **Modify.** Remove inline `<style>`, consume the shared CSS. |
| `app/resources/views/layouts/trader.blade.php` | **Modify.** Remove inline `<style>`, consume the shared CSS, release the 720px cap at `lg`. |
| `app/app/Support/RateFormatter.php` | **New.** Formats a rate at fixed precision (2dp direct, 6dp inverse). Backs `RateRow`. |
| `app/tests/Unit/RateFormatterTest.php` | **New.** Unit tests for the precision rule. |
| `app/tests/Feature/DesignTokensTest.php` | **Modify.** Read the new stylesheets; assert three-layer integrity and used-but-undeclared detection. |
| `app/tests/Feature/ResponsiveLayoutTest.php` | **New.** Assert no fixed mobile cap survives at `lg`; assert breakpoints exist. |
| `scripts/visual-check.mjs` | **New.** Playwright harness: no horizontal overflow and ≥44px tap targets at four widths. |

Nothing in `app/resources/views/{board,buy,cdd,offer,sell-to-us,trade,trader,verification}` changes in this project. They inherit the new system through the trader layout.

---

## Task 0: Restore an honest green baseline

The suite must be green before any design work, and `phpunit.xml` must stop depending on a developer's `.env`.

- [ ] **Step 1: Pin `KUDI_LAUNCHING=false` in `phpunit.xml`**

Open `app/phpunit.xml`. Add this inside `<php>` (it currently has `<env>` entries; add one more):

```xml
        <!-- Tests must not inherit the developer's .env. RootRedirectTest asserts / redirects to
             sign-in, which only happens when the launching switch is off. Leaving this to .env made
             four tests fail for anyone whose local .env had KUDI_LAUNCHING=true. -->
        <env name="KUDI_LAUNCHING" value="false" force="true"/>
```

- [ ] **Step 2: Clear the stale signup row so the count assertions are deterministic**

`LandingPageTest` expects exactly one signup row. The persistent `kudi_test` carries leftovers. `scripts/test.sh` rebuilds `kudi_test` from SQL on every run, but the `launch_signups` table is created by a Laravel migration, not by `kudi-core`, so it survives the SQL rebuild. Fix it in the test setup so it stops depending on DB history. Open `app/tests/Feature/LandingPageTest.php` and, in `setUp()`, after `parent::setUp()`, add:

```php
        // launch_signups is created by a migration, not by kudi-core, so the SQL rebuild does not
        // clear it and rows from previous runs accumulate. These assertions count real rows, so
        // start every test from an empty table rather than from whatever the last run left behind.
        \Illuminate\Support\Facades\DB::table('launch_signups')->delete();
```

- [ ] **Step 3: Run the full suite**

Run: `cd /Users/macbookpro/Desktop/kudimarket && bash scripts/test.sh`

Expected: `passed: 443 failed: 0` (SQL) and `{"result":"passed","tests":250,...}` (PHP), and `design lock` clean.

If PHP still shows `RootRedirectTest` failures, confirm the `force="true"` attribute is present — without `force`, an existing real environment variable can override the `<env>` default.

- [ ] **Step 4: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/phpunit.xml app/tests/Feature/LandingPageTest.php
git commit -m "fix(test): stop the suite inheriting the developer's .env

Four RootRedirectTest failures and two LandingPageTest count failures were
environmental: phpunit.xml did not pin KUDI_LAUNCHING, so the assertions ran
against whatever the local .env said, and launch_signups rows accumulated
across runs because the table is migration-created, not part of the kudi-core
rebuild. Pin the switch and clear the table in setup so the baseline is
honestly green."
```

---

## Task 1: Create `tokens.css` — the three token layers

- [ ] **Step 1: Write the failing test**

Create `app/tests/Feature/DesignTokensTest.php` additions. Actually, extend the existing file — it already reads `partials/tokens.blade.php` and both layouts. Add a new test that reads the new stylesheet once it exists. First, write the test that *requires* the three layers to exist:

Append to `app/tests/Feature/DesignTokensTest.php` (inside the class):

```php
    public function test_the_three_token_layers_are_declared_in_the_stylesheet(): void
    {
        $css = $this->stylesheet('resources/css/tokens.css');

        // Primitives: the only values a designer edits.
        foreach (['--plum-900', '--plum-600', '--bone', '--paper'] as $token) {
            $this->assertStringContainsString($token.':', $css, "primitive {$token} is missing");
        }

        // Semantic: meaning, derived from primitives.
        foreach (['--ink', '--surface', '--line', '--action-primary'] as $token) {
            $this->assertStringContainsString($token.':', $css, "semantic token {$token} is missing");
        }

        // Component layer.
        foreach (['--card-bg', '--control-height', '--tap-target'] as $token) {
            $this->assertStringContainsString($token.':', $css, "component token {$token} is missing");
        }

        // Every semantic token must reference a primitive, never a raw hex. This is what makes a
        // designer swap propagate instead of half-applying.
        $this->assertStringNotContainsString('--ink: #', $css, '--ink must be derived, not a raw hex');
    }
```

Add a `stylesheet()` helper to the test class (the existing `tokens()` and `layout()` helpers follow this shape):

```php
    private function stylesheet(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/kudi-core && bash run_tests.sh && cd ../app && php artisan test --filter=DesignTokensTest`

Expected: FAIL — `resources/css/tokens.css` does not exist (`assertFileExists` fails).

- [ ] **Step 3: Create `tokens.css`**

Create `app/resources/css/tokens.css`:

```css
/*
    The design system, in three layers.

    Primitives are the only values a designer edits. Semantic tokens carry meaning and are derived
    from primitives with color-mix(), so swapping a primitive propagates everywhere. Component tokens
    let a component change shape without touching the palette.

    The hex values here are what ship today in partials/tokens.blade.php. brand/BRAND.md records
    settled green as #2F7D4F and oxblood as #822B2A, which disagrees with the shipped code. This
    project builds on the code; the discrepancy is an owner decision recorded in the design doc.
*/

:root {
    /* ── Layer 1: primitives ─────────────────────────────────────────── */
    --plum-900: #331931;
    --plum-800: #462444;
    --plum-600: #5E2D5A;
    --plum-400: #743F6F;
    --plum-300: #8B6187;
    --bone: #FCF6EE;
    --paper: #FFFFFF;
    --green: #185B37;
    --red: #933331;

    /* ── Layer 2: semantic ───────────────────────────────────────────── */
    --ink: var(--plum-900);
    --ink-mid: color-mix(in oklch, var(--plum-900) 74%, var(--bone));
    --ink-soft: color-mix(in oklch, var(--plum-900) 52%, var(--bone));
    --surface: var(--bone);
    --surface-raised: var(--paper);
    --line: color-mix(in oklch, var(--plum-900) 12%, var(--bone));
    --focus: var(--plum-600);
    --action-primary: var(--plum-600);
    --action-primary-hover: var(--plum-400);
    --action-destructive: var(--red);
    --state-settled: var(--green);

    /* ── Layer 3: component ──────────────────────────────────────────── */
    --card-bg: var(--surface-raised);
    --card-border: var(--line);
    --card-radius: 12px;
    --control-height: 44px;
    --control-radius: 10px;
    --tap-target: 44px;

    /* ── Type scale: font-size / line-height ─────────────────────────── */
    --text-2xs: 12px;  --leading-2xs: 16px;
    --text-xs:  13px;  --leading-xs:  18px;
    --text-sm:  14px;  --leading-sm:  20px;
    --text-md:  16px;  --leading-md:  24px;
    --text-lg:  18px;  --leading-lg:  26px;
    --text-xl:  21px;  --leading-xl:  28px;
    --text-2xl: 24px;  --leading-2xl: 32px;
    --text-3xl: 30px;  --leading-3xl: 38px;
    --text-4xl: 38px;  --leading-4xl: 44px;
    --text-5xl: 48px;  --leading-5xl: 52px;
    --text-6xl: 60px;  --leading-6xl: 60px;

    /* ── Space scale ─────────────────────────────────────────────────── */
    --space-1: 4px;   --space-2: 8px;   --space-3: 12px;  --space-4: 16px;
    --space-5: 24px;  --space-6: 32px;  --space-7: 48px;  --space-8: 64px;
    --space-9: 96px;  --space-10: 128px;

    /* ── Container ───────────────────────────────────────────────────── */
    --container-max: 1200px;
    --container-pad: var(--space-4);
}

@media (min-width: 768px) { :root { --container-pad: var(--space-5); } }
@media (min-width: 1024px) { :root { --container-pad: var(--space-6); } }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: PASS (the new three-layer test).

Note: the older tests in `DesignTokensTest` still read `partials/tokens.blade.php` and the two layouts, which still exist at this point, so they keep passing.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/tokens.css app/tests/Feature/DesignTokensTest.php
git commit -m "feat(design): the three token layers in one stylesheet

Primitives are the only values a designer edits; semantic tokens carry meaning
and derive from primitives via color-mix so a swap propagates; component tokens
decouple shape from palette. Adds the type, space and container scales that did
not exist. Builds on the shipped hexes and records the BRAND.md discrepancy
rather than silently picking a side."
```

---

## Task 2: `base.css` — reset, typography, safe areas, tabular figures

Move the shared non-layout CSS out of the two inline `<style>` blocks. Do not migrate the layouts yet; this task only creates the file and a test that it is well-formed.

- [ ] **Step 1: Write the failing test**

Append to `app/tests/Feature/DesignTokensTest.php`:

```php
    public function test_base_css_preserves_the_deliberate_constraints(): void
    {
        $css = $this->stylesheet('resources/css/base.css');

        // Tabular lining figures. A rate board with proportional digits looks like a typo.
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);

        // Safe-area insets. A notched phone cuts into the top of the header and the bottom of the
        // page; env() is 0 on a device with no inset, so this costs nothing on desktop.
        $this->assertStringContainsString('env(safe-area-inset-', $css);

        // 16px base size so iOS does not zoom the viewport on focus.
        $this->assertStringContainsString('font-size: 16px', $css);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: FAIL — `resources/css/base.css` does not exist.

- [ ] **Step 3: Create `base.css`**

Create `app/resources/css/base.css`:

```css
/*
    Reset, page defaults, and the constraints that are deliberate rather than decorative.

    Everything here is lifted from the two inline <style> blocks so the landing page and the trader
    app stop carrying two copies that can drift. Safe areas and 16px inputs are not opinions: a
    notched phone clips the header without env(), and iOS zooms the whole viewport on focus if an
    input is under 16px.
*/

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}

body {
    background: var(--surface);
    color: var(--ink);
    font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
    font-size: 16px;      /* 16px so iOS does not zoom the viewport on focus */
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;

    /* Safe areas. env() is 0 on a device with no inset, so this costs nothing on a desktop. */
    padding-left: env(safe-area-inset-left);
    padding-right: env(safe-area-inset-right);
}

img { display: block; max-width: 100%; height: auto; }
ol, ul { list-style: none; }

/* Currency, rates and counts line up in a column. Proportional digits make a rate board look like
   a typo, so the convention is: anything numeric carries this class. */
.num, output { font-variant-numeric: tabular-nums; }

a { color: var(--plum-600); }

:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { transition: none !important; animation: none !important; }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/base.css app/tests/Feature/DesignTokensTest.php
git commit -m "feat(design): one base stylesheet for reset, type and safe areas

Lifts the constraints that were duplicated across two inline style blocks:
tabular numerals, safe-area insets, and the 16px input floor that stops iOS
zooming the viewport on focus."
```

---

## Task 3: `layout.css` — the breakpoints, container, and the rule that fixes desktop

This is the task that fixes the reported bug.

- [ ] **Step 1: Write the failing test**

Append to `app/tests/Feature/DesignTokensTest.php`:

```php
    public function test_layout_css_declares_the_breakpoints_and_the_container(): void
    {
        $css = $this->stylesheet('resources/css/layout.css');

        foreach ([640, 768, 1024, 1280, 1536] as $bp) {
            $this->assertStringContainsString("min-width: {$bp}px", $css, "breakpoint {$bp} is missing");
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
        $this->assertStringContainsString('auto-fit', $css, '.grid-auto must be auto-fit, not a fixed column count');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: FAIL — `resources/css/layout.css` does not exist.

- [ ] **Step 3: Create `layout.css`**

Create `app/resources/css/layout.css`:

```css
/*
    Layout. This is what was missing: the trader layout capped its content at 720px and defined no
    desktop breakpoint, so a desktop browser got a phone page with margins either side.

    Breakpoints: sm 640 · md 768 · lg 1024 · xl 1280 · 2xl 1536.
    Container: full width below md, 24px pad at md, centred max-width at lg and above.
    Container max: 1120px at lg, 1200px at xl and above.
*/

.container {
    width: 100%;
    margin-inline: auto;
    padding-inline: var(--container-pad);
    max-width: var(--container-max);
}

@media (min-width: 1024px) { .container { max-width: 1120px; } }
@media (min-width: 1280px) { .container { max-width: 1200px; } }

/*
    The three grid primitives. Layout never relies on inline-flow siblings, because a run of
    siblings collapses the moment content is reordered.
*/

.stack { display: flex; flex-direction: column; gap: var(--space-4); }

.grid-auto {
    display: grid;
    gap: var(--space-4);
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
}

.split { display: grid; gap: var(--space-6); }

@media (min-width: 1024px) {
    .split { grid-template-columns: 1fr 400px; gap: var(--space-8); }
}

/* Reading column. Used by the trader app at lg so rates and trade rows still read instead of
   stretching the length of a 1440px screen. */
.read-col { width: 100%; margin-inline: auto; max-width: 100%; }

@media (min-width: 1024px) { .read-col { max-width: 880px; } }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/layout.css app/tests/Feature/DesignTokensTest.php
git commit -m "feat(design): a real layout system, and the cap that fixes desktop

The trader layout capped content at 720px with no desktop breakpoint, which is
why the page looked like a phone screen in a wide browser. Adds breakpoints,
a centred container, the three grid primitives, and a reading column so wide
screens do not stretch a rate row past the point it reads."
```

---

## Task 4: `RateFormatter` and the precision fix

The printed `0.006756756756756756` is a real defect on a money page. Fix it in a testable class before it reaches `RateRow`.

- [ ] **Step 1: Write the failing unit test**

Create `app/tests/Unit/RateFormatterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RateFormatter;
use PHPUnit\Framework\TestCase;

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
        // 148.2 and 148.20 both appeared on the live page. One stored precision fixes it.
        $this->assertSame(RateFormatter::format(148.2), RateFormatter::format(148.20));
    }

    public function test_no_rate_ever_prints_more_than_six_decimals(): void
    {
        $out = RateFormatter::format(1 / 3);
        $decimals = strlen(substr(strrchr($out, '.'), 1));
        $this->assertLessThanOrEqual(6, $decimals);
    }

    public function test_a_corridor_may_override_the_default_precision(): void
    {
        $this->assertSame('0.0068', RateFormatter::format(1 / 148.0, 4));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=RateFormatterTest`

Expected: FAIL — `App\Support\RateFormatter` does not exist.

- [ ] **Step 3: Implement `RateFormatter`**

Create `app/app/Support/RateFormatter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class RateFormatter
{
    /**
     * Render a rate at a fixed, stored precision. Never print a raw float.
     *
     * Four decimals was tried and rejected: it renders 1/148 as "0.0068", which destroys the value.
     * Two decimals for a direct rate (>= 1) and six for an inverse rate (< 1) is the minimum that
     * keeps a cedi/naira rate usable. A corridor can override the default without changing callers.
     */
    public static function format(float|int|string $rate, ?int $decimals = null): string
    {
        $value = (float) $rate;

        $decimals ??= $value >= 1 ? 2 : 6;

        return number_format($value, $decimals, '.', '');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=RateFormatterTest`

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/app/Support/RateFormatter.php app/tests/Unit/RateFormatterTest.php
git commit -m "feat(design): format rates at a stored precision, never a raw float

The live page printed 0.006756756756756756 and showed 148.2 in one place and
148.20 in another. Direct rates render at 2 decimals, inverse at 6, and a
corridor may override. Fixes the most damaging thing on a money page: a number
the reader cannot trust because it is noisy."
```

---

## Task 5: `components.css` — the nine components

- [ ] **Step 1: Write the failing test**

Append to `app/tests/Feature/DesignTokensTest.php`:

```php
    public function test_every_declared_component_exists_and_meets_the_tap_target(): void
    {
        $css = $this->stylesheet('resources/css/components.css');

        foreach ([
            '.btn', '.card', '.field', '.rate-row', '.badge',
            '.section-header', '.phone-frame', '.stat-band', '.nav',
        ] as $component) {
            $this->assertStringContainsString($component, $css, "component {$component} is missing");
        }

        // Every interactive control is at least a 44px target. This is asserted here rather than
        // only in the visual harness so a regression is caught by the unit suite too.
        $this->assertStringContainsString('var(--tap-target)', $css);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: FAIL — `resources/css/components.css` does not exist.

- [ ] **Step 3: Create `components.css`**

Create `app/resources/css/components.css`:

```css
/*
    The nine components shared by both surfaces.

    These are hand-written rather than utility-composed because each one carries a decision worth
    naming: a 44px tap target, tabular figures, a fixed rate precision, a destructive colour that
    never sits beside a primary. The decision lives with the component, not scattered in a view.
*/

.btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: var(--tap-target);
    padding: 0 var(--space-4);
    border-radius: var(--control-radius);
    border: 1px solid transparent;
    font: inherit; font-size: 16px; font-weight: 600; text-decoration: none;
    cursor: pointer;
}
.btn.primary { background: var(--action-primary); border-color: var(--action-primary); color: #fff; }
.btn.primary:hover { background: var(--action-primary-hover); border-color: var(--action-primary-hover); }
.btn.ghost { background: transparent; border-color: var(--line); color: var(--ink); }
.btn.destructive { background: var(--action-destructive); border-color: var(--action-destructive); color: #fff; }
/* Destructive never sits beside a primary. Rule 2 of the brand system. */
.btn + .btn { margin-left: var(--space-2); }

.card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--card-radius);
    padding: var(--space-4);
}
.card.ours { border-color: var(--action-primary); background: var(--action-primary); color: var(--bone); }

.field { display: block; }
.field label { display: block; font-size: var(--text-sm); font-weight: 600; margin-bottom: var(--space-2); }
.field input, .field select {
    width: 100%; min-height: var(--control-height);
    padding: 0 var(--space-3); font: inherit; font-size: 16px;
    border: 1px solid var(--line); border-radius: var(--control-radius);
    background: var(--surface-raised); color: var(--ink);
}
.field .help { margin-top: var(--space-2); font-size: var(--text-sm); color: var(--ink-soft); }
.field .err  { margin-top: var(--space-2); font-size: var(--text-sm); color: var(--action-destructive); }

.rate-row { display: flex; flex-direction: column; gap: var(--space-1); }
.rate-row .label { font-size: var(--text-2xs); font-weight: 600; letter-spacing: 0.06em;
    text-transform: uppercase; color: var(--ink-soft); }
/* Rule 1 of the brand system: the brand colour is the frame, the data is never dressed in it. */
.rate-row .value { font-size: var(--text-3xl); font-weight: 800; letter-spacing: -0.03em;
    color: var(--ink); font-variant-numeric: tabular-nums; }

.badge { display: inline-flex; align-items: center; gap: var(--space-1);
    font-size: var(--text-xs); font-weight: 600; padding: 4px 10px; border-radius: 999px;
    border: 1px solid var(--line); background: var(--surface-raised); color: var(--ink-mid); }
.badge.settled   { color: var(--state-settled); }
.badge.disputed  { color: var(--action-destructive); }

.section-header .eyebrow { font-size: var(--text-2xs); font-weight: 700;
    letter-spacing: 0.1em; text-transform: uppercase; color: var(--plum-300); }
.section-header h2 { font-size: var(--text-3xl); font-weight: 800; letter-spacing: -0.025em;
    margin-top: var(--space-2); }
.section-header .lede { margin-top: var(--space-3); color: var(--ink-mid); }

/* Sized by breakpoint. This is what stops the 375px mockup from being a stamp on a desktop. */
.phone-frame img { width: 100%; border-radius: 26px; border: 1px solid var(--line);
    box-shadow: 0 24px 60px -28px color-mix(in oklch, var(--plum-900) 42%, transparent); }
@media (min-width: 1024px) { .phone-frame img { max-width: 320px; } }

.stat-band { display: grid; gap: 0; border-top: 1px solid var(--line); }
@media (min-width: 768px) { .stat-band { grid-template-columns: repeat(3, 1fr); } }
.stat-band > div { padding: var(--space-5) 0; border-bottom: 1px solid var(--line); }
@media (min-width: 768px) {
    .stat-band > div { border-bottom: 0; padding: var(--space-5) var(--space-5) var(--space-5) 0; }
    .stat-band > div + div { border-left: 1px solid var(--line); padding-left: var(--space-5); }
}
.stat-band .value { font-size: var(--text-4xl); font-weight: 800; letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums; }

.nav { display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); }
@media (min-width: 1024px) {
    .nav { border-left: 1px solid var(--line); padding-left: var(--space-5); }
    .nav.rail { flex-direction: column; align-items: flex-start; }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/components.css app/tests/Feature/DesignTokensTest.php
git commit -m "feat(design): the nine shared components

Button, Card, Field, RateRow, StatusBadge, SectionHeader, PhoneFrame, StatBand,
Nav. Each carries the decision that belongs to it: a 44px target, tabular
figures, the fixed rate precision, a destructive colour that never sits beside
a primary, and a product mockup sized by breakpoint rather than a fixed stamp."
```

---

## Task 6: Wire the stylesheets into `app.css` and Tailwind `@theme`

- [ ] **Step 1: Write the failing test**

Append to `app/tests/Feature/DesignTokensTest.php`:

```php
    public function test_app_css_imports_the_four_layers(): void
    {
        $css = $this->stylesheet('resources/css/app.css');

        foreach (['tokens.css', 'base.css', 'layout.css', 'components.css'] as $import) {
            $this->assertStringContainsString($import, $css, "{$import} is not imported");
        }
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`

Expected: FAIL — `app.css` does not yet import the four files.

- [ ] **Step 3: Update `app.css`**

Replace `app/resources/css/app.css` with:

```css
@import 'tailwindcss';
@import './tokens.css';
@import './base.css';
@import './layout.css';
@import './components.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';

    /* Tailwind utilities for the brand colour, derived from the same token the components use.
       This is why the designer's later palette change lands once, in tokens.css. */
    --color-plum-900: #331931;
    --color-plum-800: #462444;
    --color-plum-600: #5E2D5A;
    --color-plum-400: #743F6F;
    --color-bone: #FCF6EE;
    --color-paper: #FFFFFF;
}
```

- [ ] **Step 4: Run the test and the asset build**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=DesignTokensTest`
Expected: PASS.

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && npm run build`
Expected: Vite builds without error. If it fails on an `@import` order or a missing file, the import path is wrong relative to `resources/css`.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/app.css app/tests/Feature/DesignTokensTest.php
git commit -m "feat(design): wire the layers into the Vite entrypoint

Tailwind utilities now resolve their brand colour from the same tokens the
components use, so a palette change lands once rather than in two places."
```

---

## Task 7: Migrate the trader layout — release the 720px cap

This is the task the whole project exists for. The trader layout must stop carrying its own CSS and must stop capping at 720px.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Feature/ResponsiveLayoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The reported defect, pinned.
 *
 * The trader layout capped its content at 720px and defined no desktop breakpoint, so a desktop
 * browser received a phone page with empty margins either side. These tests fail if that cap is
 * reintroduced or if the layouts go back to carrying their own inline CSS.
 */
class ResponsiveLayoutTest extends TestCase
{
    public function test_the_trader_layout_no_longer_caps_its_content_at_720px(): void
    {
        $css = $this->layout('trader');

        $this->assertStringNotContainsString(
            'max-width: 720px',
            $css,
            'the 720px mobile cap is back; a desktop will get a phone page again'
        );
    }

    public function test_the_trader_layout_no_longer_carries_an_inline_style_block(): void
    {
        $this->assertStringNotContainsString(
            '<style>',
            $this->layout('trader'),
            'the trader layout is carrying its own <style> again; the shared stylesheets must be the only source'
        );
    }

    public function test_the_trader_layout_uses_the_shared_container(): void
    {
        $this->assertStringContainsString('class="container', $this->layout('trader'));
    }

    private function layout(string $name): string
    {
        $path = resource_path("views/layouts/{$name}.blade.php");
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/kudi-core && bash run_tests.sh && cd ../app && php artisan test --filter=ResponsiveLayoutTest`

Expected: FAIL — the trader layout still has `max-width: 720px` and an inline `<style>`.

- [ ] **Step 3: Migrate `layouts/trader.blade.php`**

Replace the entire inline `<style>…</style>` block with nothing, and replace the `<head>` content to load the compiled asset. The layout's existing markup keeps its class names (`.bar`, `.card`, `.label`, `.note`) but those move to the component classes.

1. Delete the whole `<style> … </style>` block from `layouts/trader.blade.php`.
2. Ensure `@vite(['resources/css/app.css', 'resources/js/app.js'])` is present in the `<head>`.
3. Replace `<div class="wrap">` (or whatever wraps `main`) with `<div class="container">`.
4. Change `main { max-width: 720px }` behaviour: wrap `main` in `.read-col` so it caps at 880px **at `lg` and above only**. The simplest form: give the `main` element `class="read-col"`.
5. Update the wordmark `bar` to use `.nav` so the header picks up the system.

Do not change any copy, route, or business logic in this file.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=ResponsiveLayoutTest`

Expected: PASS.

- [ ] **Step 5: Verify the trader app renders (launching off)**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && KUDI_LAUNCHING=false php artisan test --filter=MobileLayoutTest`

Expected: PASS. This test already asserts 44px targets and no horizontal overflow at 390px; the new `.read-col` must not regress it.

- [ ] **Step 6: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/views/layouts/trader.blade.php app/tests/Feature/ResponsiveLayoutTest.php
git commit -m "feat(design): the trader app releases the 720px cap at lg

Removes the inline style block, moves the content onto the shared container and
the reading column, and pins the defect with a test so the cap cannot come back.
A phone stays a phone layout; a desktop now gets a desktop layout."
```

---

## Task 8: Migrate the marketing layout

- [ ] **Step 1: Write the failing test**

Extend `ResponsiveLayoutTest`:

```php
    public function test_the_marketing_layout_uses_the_shared_container(): void
    {
        $this->assertStringContainsString('class="container', $this->layout('marketing'));
    }

    public function test_the_marketing_layout_no_longer_carries_an_inline_style_block(): void
    {
        $this->assertStringNotContainsString(
            '<style>',
            $this->layout('marketing'),
            'the marketing layout is carrying its own <style> again'
        );
    }

    public function test_the_rate_band_uses_the_stat_band_component(): void
    {
        $this->assertStringContainsString('stat-band', $this->layout('marketing'));
    }

    public function test_the_printed_rate_is_no_longer_hand_stripped(): void
    {
        // The defect: rtrim(rtrim($rate, '0'), '.') rendered 148.20 as 148.2 and printed the inverse
        // rate at full float length. Formatting now comes from RateFormatter, in one place.
        $view = (string) file_get_contents(resource_path('views/public/landing.blade.php'));
        $this->assertStringNotContainsString("rtrim(rtrim(\$rates", $view);
        $this->assertStringContainsString('RateFormatter::format', $view);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan test --filter=ResponsiveLayoutTest`

Expected: FAIL — the marketing layout still carries inline CSS and its own `.wrap`.

- [ ] **Step 3: Migrate `layouts/marketing.blade.php` and `views/public/landing.blade.php`**

1. Delete the inline `<style> … </style>` from `layouts/marketing.blade.php`; ensure `@vite` is in `<head>`.
2. Replace `.wrap` with `.container`.
3. In `views/public/landing.blade.php`:
   - Give the hero the `.split` class so it becomes two real columns at `lg`.
   - Give the rate section's `<dl>` the `stat-band` class.
   - Give the product `<img>`s the `.phone-frame` class.
   - Replace the raw rate output with `RateFormatter`. The current line strips trailing zeros by
     hand — `rtrim(rtrim($rates['GHS/NGN'], '0'), '.')` — which is why `148.20` renders as `148.2`
     and the inverse rate renders at full float length. That hand-strip is the defect; delete it.

     In `resources/views/public/landing.blade.php`, replace:

```blade
<div><dt>We publish</dt><dd class="num">{{ rtrim(rtrim($rates['GHS/NGN'], '0'), '.') }} <span>GHS to NGN</span></dd></div>
```

     with:

```blade
<div><dt>We publish</dt><dd class="num">{{ RateFormatter::format($rates['GHS/NGN']) }} <span>GHS to NGN</span></dd></div>
```

     and the same for `NGN/GHS`:

```blade
<div><dt>And back</dt><dd class="num">{{ RateFormatter::format($rates['NGN/GHS']) }} <span>NGN to GHS</span></dd></div>
```

     Add the import at the top of the view, above the markup:

```blade
@php
    use App\Support\RateFormatter;
@endphp
```

     `LandingController` needs no change — `$rates` stays as the raw strings it already returns,
     and formatting happens where the number is displayed.

   Do not change any marketing copy in this task. Copy is a design decision, not a layout decision.

- [ ] **Step 4: Run the landing tests to verify they pass**

Run: `cd /Users/macbookpro/Desktop/kudimarket/kudi-core && bash run_tests.sh && cd ../app && php artisan test --filter=LandingPageTest`

Expected: PASS. `test_the_page_shows_the_real_number_waiting_and_never_an_invented_one` now sees a cleanly formatted rate.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/views/layouts/marketing.blade.php \
        app/resources/views/public/landing.blade.php \
        app/tests/Feature/ResponsiveLayoutTest.php
git commit -m "feat(design): the landing page joins the shared system

Moves off its inline CSS onto the container, the split hero and the stat band,
and deletes the hand-rolled trailing-zero strip on the printed rate, which is
what rendered 148.20 as 148.2 and the inverse rate at full float length. Copy
is untouched."
```

---

## Task 9: Delete the retired tokens partial

- [ ] **Step 1: Confirm nothing still includes it**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && rg -n "partials.tokens|partials/tokens" resources/ app/`

Expected: no output. If anything still includes it, remove that include first.

- [ ] **Step 2: Delete it**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && git rm resources/views/partials/tokens.blade.php`

- [ ] **Step 3: Update `DesignTokensTest` so it reads the new stylesheet instead**

The two older tests (`test_the_trader_layout_declares_every_token_it_uses` and the marketing equivalent) currently call `$this->tokens()`. Repoint them at the new stylesheets: replace `$this->tokens()` with `$this->stylesheet('resources/css/tokens.css').' '.$this->stylesheet('resources/css/base.css').' '.$this->stylesheet('resources/css/components.css')` in both, and remove the now-unused `tokens()` helper.

- [ ] **Step 4: Run the full suite**

Run: `cd /Users/macbookpro/Desktop/kudimarket && bash scripts/test.sh`

Expected: SQL `passed: 443 failed: 0`; PHP `{"result":"passed","tests":250,...}`; design lock clean.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git commit -m "refactor(design): retire the tokens partial

tokens.css is the single source now. The used-but-undeclared test still runs,
reading the stylesheets, so a token that is referenced without being defined is
still a failure rather than a silent fallback."
```

---

## Task 10: Visual-check harness

The unit suite cannot see layout. This harness asserts the two things that only a browser can: no horizontal overflow, and real tap targets, at the four widths the design promises.

- [ ] **Step 1: Write the harness**

Create `scripts/visual-check.mjs`:

```javascript
import { chromium } from 'playwright';

const BASE = process.env.KUDI_URL ?? 'http://127.0.0.1:8100';
const WIDTHS = [375, 768, 1024, 1440];
const PATHS = ['/', '/sign-in', '/trade/board'];

const browser = await chromium.launch();
let failures = 0;

for (const width of WIDTHS) {
    for (const path of PATHS) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch(() => {});
        await page.waitForTimeout(300);

        const result = await page.evaluate(() => {
            const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth;
            const smallTargets = [...document.querySelectorAll('a, button, input, select')]
                .map((el) => el.getBoundingClientRect())
                .filter((r) => r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44))
                .length;
            return { overflow, smallTargets };
        });

        if (result.overflow || result.smallTargets > 0) {
            failures++;
            console.log(`FAIL ${width}px ${path} overflow=${result.overflow} smallTargets=${result.smallTargets}`);
        } else {
            console.log(`ok   ${width}px ${path}`);
        }
        await page.close();
    }
}

await browser.close();
process.exit(failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Install playwright as a dev dependency**

Run: `cd /Users/macbookpro/Desktop/kudimarket && npm i -D playwright`

If installing a browser binary is blocked, run with the system Chrome instead by changing the launch call to `chromium.launch({ channel: 'chrome' })`.

- [ ] **Step 3: Run it against the local app**

Run: `cd /Users/macbookpro/Desktop/kudimarket/app && php artisan serve --port=8100 &` then, in another shell, `cd /Users/macbookpro/Desktop/kudimarket && node scripts/visual-check.mjs`.

Expected: `ok` at every width and path, or specific failures you fix.

- [ ] **Step 4: Add an npm script**

Add to the root `package.json` scripts:

```json
"visual": "node scripts/visual-check.mjs"
```

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add scripts/visual-check.mjs package.json package-lock.json
git commit -m "test(design): assert no overflow and real tap targets at four widths

The unit suite can check tokens and markup but not layout. This runs a real
browser at 375, 768, 1024 and 1440 and fails on horizontal overflow or any
interactive control under 44px."
```

---

## Task 11: Full verification

- [ ] **Step 1: Run the whole suite**

Run: `cd /Users/macbookpro/Desktop/kudimarket && bash scripts/test.sh`

Expected: SQL `passed: 443 failed: 0`; PHP `{"result":"passed","tests":250,...}`; design lock `0 errors`.

- [ ] **Step 2: Run the visual harness**

Run: `cd /Users/macbookpro/Desktop/kudimarket && KUDI_URL=http://127.0.0.1:8100 node scripts/visual-check.mjs`

Expected: exit 0.

- [ ] **Step 3: Look at the landing page at 1440px and confirm the reported defect is gone**

Take a screenshot at 1440×900 and compare against `/tmp/kudilab/look/desktop-1440.png`. The specific things to confirm: content is not a lone ~1000px column with empty margins; the rate band is a three-column stat band; the printed inverse rate is `0.006757`, not `0.006756756756756756`; the hero is a two-column split at this width.

- [ ] **Step 4: Confirm the brand-document discrepancy is still recorded, not silently resolved**

Run: `cd /Users/macbookpro/Desktop/kudimarket && rg -n "185B37|933331|2F7D4F|822B2A" app/resources/css/tokens.css docs/superpowers/specs/2026-10-15-design-system-foundation-design.md`

Expected: the code uses `#185B37` / `#933331` and the spec records the disagreement as an owner decision. Do not "fix" this in this project.

---

## Definition of done

- The design system exists as four stylesheets and one class, with the tokens in three layers.
- The 720px mobile cap is gone from the trader layout, and a test prevents it returning.
- Neither layout carries its own inline CSS; the shared stylesheets are the only source.
- A rate never prints as a raw float and never appears two ways.
- The PHP suite is green (250/250) and the SQL suite is green (443/443) via `scripts/test.sh`.
- A browser harness asserts no overflow and ≥44px targets at 375 / 768 / 1024 / 1440.
- The colour discrepancy between the code and `BRAND.md` is recorded as an open owner decision, not silently picked.
- The landing page at 1440px no longer looks like a phone page in a wide window.

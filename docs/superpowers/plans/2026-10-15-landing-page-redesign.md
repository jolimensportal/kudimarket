# KudiMarket Landing Page Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the landing page's exchange screenshots and escrow copy with a remittance page whose hero proof is the live rate card, and whose layout uses the full 1440px width.

**Architecture:** A second small formatter, `MoneyFormatter`, formats amounts with thousands separators — a different job from `RateFormatter`, which formats rates without them. `LandingController` computes a worked example from the live rate rather than hard-coding it. The view is rewritten onto the design-system components; `marketing.css` is rewritten to match. Three existing test assertions change because the copy changes, and the banned-word test is extended.

**Tech Stack:** Laravel 12 + Blade, Tailwind 4 via Vite, PHPUnit, the seven design-system stylesheets built in project 1.

**Design doc:** `docs/superpowers/specs/2026-10-15-landing-page-redesign.md`

---

## Test commands

`kudi_test` is persistent. Always use the project entry point:

```bash
cd /Users/macbookpro/Desktop/kudimarket && bash scripts/test.sh
```

For a single file:

```bash
cd /Users/macbookpro/Desktop/kudimarket/kudi-core
PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh   # rebuild kudi_test
cd ../app && php artisan test --filter=LandingPageTest
```

**Nothing is deployed.** Do not run `scripts/deploy.sh` and do not touch the server.

---

## Task 1: `MoneyFormatter`

`RateFormatter` formats a rate at a fixed precision with no thousands separator, which is right for a price and wrong for an amount. `14,820.00` is not `14820.00`.

- [ ] **Step 1: Write the failing test**

```bash
cd /Users/macbookpro/Desktop/kudimarket/app
php artisan make:test --phpunit --unit MoneyFormatterTest --no-interaction
```

Then write `tests/Unit/MoneyFormatterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MoneyFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Amounts, not rates. A rate is a price and reads bare (148.20); an amount of money is read by
 * people counting digits, and 14820.00 is harder to check at a glance than 14,820.00.
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
        // 0.1 + 0.2 is the canonical reason a money product cannot format in place.
        $this->assertSame('0.30', MoneyFormatter::format(0.1 + 0.2));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=MoneyFormatterTest`

Expected: FAIL — `App\Support\MoneyFormatter` does not exist.

- [ ] **Step 3: Implement it**

```bash
php artisan make:class Support/MoneyFormatter --no-interaction
```

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class MoneyFormatter
{
    /**
     * Format an amount of money with thousands separators and a fixed number of decimals.
     *
     * Separate from RateFormatter on purpose. A rate is a price and reads bare -- 148.20, never
     * 148.2 and never grouped. An amount is counted by eye, and 14,820.00 is checked faster than
     * 14820.00. Using one formatter for both would make the rate unreadable or the amount illegible,
     * depending on which way it went.
     */
    public static function format(float|int|string $amount, int $decimals = 2): string
    {
        return number_format((float) $amount, $decimals, '.', ',');
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=MoneyFormatterTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Pint and commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket/app && vendor/bin/pint --dirty --format agent
cd .. && git add app/app/Support/MoneyFormatter.php app/tests/Unit/MoneyFormatterTest.php
git commit -m "feat(landing): format amounts with thousands separators

RateFormatter is right for a rate and wrong for an amount: 14820.00 is harder to
check at a glance than 14,820.00, and grouping a rate would be worse. One
formatter for both would make whichever it was wrong for, so there are two with
one job each."
```

---

## Task 2: The worked example, computed from the live rate

The cost band shows "You send 100.00 GHS · They receive 14,820.00 NGN". Those figures must be derived from the published rate. A hard-coded 14,820 would be the exact failure this page exists to avoid — a number on the page that is not the real number.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LandingPageTest.php`:

```php
    public function test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded(): void
    {
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '150.5', 'rates@kudimarket.com']
        );

        // 100 GHS at 150.5 is 15,050 NGN. If the page ever shows a figure that was typed into the
        // template rather than multiplied out of the rate card, this fails.
        $this->get('/about')
            ->assertOk()
            ->assertSee('15,050.00');

        // And the rate moves, so the example moves with it.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '200', 'rates@kudimarket.com']
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee('20,000.00')
            ->assertDontSee('15,050.00');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /Users/macbookpro/Desktop/kudimarket/kudi-core && PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh && cd ../app && php artisan test --filter=test_the_worked_example_is_computed_from_the_live_rate`

Expected: FAIL — the string `15,050.00` is not on the page.

- [ ] **Step 3: Compute the example in the controller**

Open `app/app/Http/Controllers/LandingController.php`. Add to the imports:

```php
use App\Support\RateFormatter;
```

Add `'example' => $this->workedExample($this->publishedRates()),` to the `view()` array, alongside `'rates'` and `'feeBps'`.

Add this method:

```php
    /**
     * A worked example at the live rate, or null when no GHS/NGN rate is published.
     *
     * Computed here rather than typed into the template. A sender wants to see their own transaction
     * rather than three abstract rates, and the moment anyone hard-codes 14,820 the page carries a
     * number that is not the real number -- which is the single thing this page is built to avoid.
     */
    private function workedExample(array $rates): ?array
    {
        $rate = $rates['GHS/NGN'] ?? null;

        if ($rate === null) {
            return null;
        }

        $send = 100.0;

        return [
            'send' => MoneyFormatter::format($send),
            'receive' => MoneyFormatter::format($send * (float) $rate),
            'rate' => RateFormatter::format($rate),
        ];
    }
```

Add `use App\Support\MoneyFormatter;` to the imports too.

- [ ] **Step 4: Run it to verify it passes**

Expected: FAIL still — the view does not render `$example` yet. That is correct; the controller half is done. Note it and continue to Task 3, which renders it.

- [ ] **Step 5: Do not commit yet**

Task 3 renders it. Commit both together so the branch is never red.

---

## Task 3: Rewrite the view

- [ ] **Step 1: Update the three copy assertions first**

In `tests/Feature/LandingPageTest.php`, replace the promises test body:

```php
    public function test_it_makes_three_promises_and_every_one_is_mechanically_true(): void
    {
        $response = $this->get('/about')->assertOk();

        $response->assertSee('Cash in hand, the same day.');
        $response->assertSee('Nobody touches your money until you say so.');
        $response->assertSee('The rate you agreed is the rate you get.');

        // The banned-words test. brand/BRAND.md is explicit that adjectives doing work a fact could
        // do are the failure mode, and this page is the first thing a regulator, a bank or a
        // journalist will read.
        foreach (['seamless', 'revolutionary', 'cutting-edge', 'world-class', 'bank-grade', 'best-in-class'] as $word) {
            $response->assertDontSee($word, false);
        }

        // Escrow, offers and trading are cut from this page, because the business is a remittance
        // company and brand/BRAND.md section 0 retires escrow explicitly. Leaving one of these words
        // on the page would put the exchange back into a remittance page one noun at a time.
        foreach (['escrow', 'trade', 'offer'] as $word) {
            $response->assertDontSee($word, false);
        }
    }
```

In `test_the_page_shows_a_live_rate`, change `assertSee('150.5')` to `assertSee('150.50')` — two decimals, because `RateFormatter` fixes the precision and that is the whole point of it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=LandingPageTest`

Expected: FAIL on the promises and the worked example. The view still says "Escrow you can see."

- [ ] **Step 3: Rewrite `resources/views/public/landing.blade.php`**

Replace the whole `@section('content')` body with the five sections below. Keep the `session('subscribed')` branch and the `@include('public.partials.confirmation')` exactly as they are.

**Section 1 — hero.** Two columns at `lg`. Left: status, locked tagline, lead, doors, form. Right: the rate card.

```blade
        <section class="hero">
            <div class="hero-copy">
                <p class="status"><span class="dot"></span>Not open yet</p>

                <h1>Your market.<br>Your rate.</h1>

                <p class="lead">
                    You send your side, we send ours. Nothing waits on a stranger to turn up,
                    because we are the other side.
                </p>

                {{-- The doors point at real anchors. The previous set pointed at #send, #earn and
                     #trust, none of which existed, so all three were dead links. --}}
                <nav class="doors" aria-label="What brings you here">
                    <a href="#cost"><span class="n">01</span> I am sending money</a>
                    <a href="#cost"><span class="n">02</span> I want to know what it costs first</a>
                    <a href="#promise"><span class="n">03</span> I do not trust apps that took my money</a>
                </nav>

                <form method="post" action="{{ route('landing.subscribe') }}" class="signup" novalidate>
                    @csrf
                    @if ($referrer)
                        <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
                    @endif
                    <label for="email">Tell us when we open</label>
                    <div class="row">
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               placeholder="you@example.com" autocomplete="email" required
                               aria-describedby="email-help email-error">
                        <button type="submit">Notify me</button>
                    </div>
                    @error('email')
                        <p class="err" id="email-error" role="alert">{{ $message }}</p>
                    @enderror
                    <p class="help" id="email-help">
                        One email, when we open. No sequence.
                        @if ($waiting > 0)
                            <strong>{{ $waiting }} {{ Str::plural('person', $waiting) }} {{ Str::plural('is', $waiting) }} already on the list.</strong>
                        @endif
                    </p>
                    <p class="alt">
                        No email?
                        <a href="{{ $waLink ?? '#' }}" rel="noopener">WhatsApp us</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ $tgLink ?? '#' }}" rel="noopener">Telegram</a>
                    </p>
                </form>
            </div>

            {{-- The proof, stated rather than photographed. The phone screenshots this replaces said
                 "Sell to us", "Buy from us" and "Your trades", which is a P2P exchange photographed
                 inside a remittance page. The rate card is the claim the business actually makes, it
                 is live, and it cannot contradict the copy beside it. --}}
            @if (count($rates) > 0)
                <aside class="rate-card" aria-label="Today's published rate">
                    <div class="rate-card__head">
                        <span class="rate-card__title">Today's rate</span>
                        <span class="live"><span class="dot"></span>Live from the ledger</span>
                    </div>
                    <div class="rate-card__body">
                        <p class="rate-card__pair">GHS &rarr; NGN</p>
                        <p class="rate-card__value num">{{ \App\Support\RateFormatter::format($rates['GHS/NGN'] ?? 0) }}</p>
                        <p class="rate-card__eq">1 cedi is {{ \App\Support\RateFormatter::format($rates['GHS/NGN'] ?? 0) }} naira</p>

                        <div class="rate-card__split">
                            <div>
                                <span class="k">And back</span>
                                <span class="n num">{{ \App\Support\RateFormatter::format($rates['NGN/GHS'] ?? 0) }}</span>
                            </div>
                            <div>
                                <span class="k">Our fee</span>
                                <span class="n num">{{ $feeBps !== null ? rtrim(rtrim(sprintf('%.2f', $feeBps / 100), '0'), '.') : '—' }}%</span>
                            </div>
                        </div>
                    </div>
                    <p class="rate-card__foot">
                        Every rate we have ever shown stays on file. Nothing here is edited in place.
                    </p>
                </aside>
            @endif
        </section>
```

**Section 2 — the worked example.**

```blade
        @if ($example !== null)
            <section class="cost" id="cost">
                <h2>What it costs, before you ask</h2>
                <dl>
                    <div><dt>You send</dt><dd class="num">{{ $example['send'] }} <span>GHS</span></dd></div>
                    <div><dt>They receive</dt><dd class="num">{{ $example['receive'] }} <span>NGN</span></dd></div>
                    <div>
                        <dt>Our fee</dt>
                        <dd class="num">{{ $feeBps !== null ? rtrim(rtrim(sprintf('%.2f', $feeBps / 100), '0'), '.') : '—' }}% <span>{{ $feeBps }} bps, charged on the send</span></dd>
                    </div>
                </dl>
                <p class="foot">
                    Worked example at today's rate. Yours is priced at the rate you are shown,
                    not a rate that moves while you look.
                </p>
            </section>
        @endif
```

**Section 3 — the three promises, remittance version.**

```blade
        <section class="promise" id="promise">
            <h2>What we promise, and how you could check it</h2>
            <ol>
                <li>
                    <span class="t">Cash in hand, the same day.</span>
                    <span class="d">The recipient is holding it, not watching a progress bar. You confirm when they have it.</span>
                </li>
                <li>
                    <span class="t">Nobody touches your money until you say so.</span>
                    <span class="d">Operations cannot confirm a delivery on your behalf, and neither can we.</span>
                </li>
                <li>
                    <span class="t">The rate you agreed is the rate you get.</span>
                    <span class="d">We quote our rate and you see it before you commit. The transfer is priced at that number.</span>
                </li>
            </ol>
        </section>
```

**Section 4 — address promise beside the second ask.**

```blade
        <section class="foot2">
            <div class="plain">
                <h2>What we do with your address</h2>
                <ul>
                    <li>Store it, and write once when we open.</li>
                    <li>Nothing else. No partners, no selling it, no sequence.</li>
                    <li>Reply with <strong>delete</strong> and it is gone.</li>
                </ul>
                <p class="foot">
                    We are completing licensing before we open. Nothing here is an offer to trade.
                </p>
            </div>

            <div class="closer">
                <h2>When it opens, you will hear it from us first.</h2>
                <form method="post" action="{{ route('landing.subscribe') }}" novalidate>
                    @csrf
                    <div class="row">
                        <input type="email" name="email" placeholder="you@example.com" autocomplete="email"
                               aria-label="Email address" required>
                        <button type="submit">Notify me</button>
                    </div>
                </form>
            </div>
        </section>
```

Also update the meta description, which currently advertises escrow:

```blade
@section('description', 'Send cedi, receive naira, at a rate you see before you commit. KudiMarket is not open yet — join the list and we will write once.')
```

**Delete entirely:** the `.proof` section and all three `<img>` tags. `board.png`, `buy.png` and `history.png` stay in the repo and stop being referenced here.

- [ ] **Step 4: Run the landing tests**

Run: `php artisan test --filter=LandingPageTest`

Expected: PASS, including the worked-example test from Task 2.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket/app && vendor/bin/pint --dirty --format agent
cd .. && git add app/app/Http/Controllers/LandingController.php \
        app/resources/views/public/landing.blade.php \
        app/tests/Feature/LandingPageTest.php
git commit -m "feat(landing): a remittance page, with the rate as the proof

The page advertised a P2P exchange. Its screenshots said Sell to us, Buy from
us and Your trades, and its promise block was escrow, which BRAND.md section 0
retires because the business moves money rather than holding it. The doors
pointed at #send, #earn and #trust, none of which existed.

The hero proof is now the live rate card. The cost band is a worked example at
100 GHS computed from the published rate, so the figures move when the rate
moves instead of being typed into a template. Same-day replaces in-minutes,
because minutes cannot be guaranteed yet.

The banned-word test now also forbids escrow, trade and offer, so the exchange
cannot creep back one noun at a time."
```

---

## Task 4: Rewrite `marketing.css` for the new sections

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ResponsiveLayoutTest.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it passes**

Run: `php artisan test --filter=ResponsiveLayoutTest`

Expected: PASS — Task 3 already removed the images and fixed the anchors. This test exists to stop them coming back.

- [ ] **Step 3: Rewrite the landing rules in `resources/css/marketing.css`**

Replace `.hero`, `.numbers`, `.terms`, `.proof`, `.plain` and `.closer` with rules for `.hero`, `.rate-card`, `.cost`, `.promise`, `.plain`, `.foot2` and `.closer`. Keep `.wrap`-era resets out of it; `base.css` owns those. Keep `.mark`, `.top`, `.status`, `.lead`, `.doors`, `.row`, `button`, `input`, `.help`, `.err`, `.alt`, `footer` — they are unchanged by this task.

Key rules the new sections need, using the tokens that already exist:

```css
/* Hero. The split is what uses the width; below lg it is one column and the
   rate card follows the form. */
.hero { display: grid; gap: 40px; align-items: center; padding: 56px 0 48px; }
@media (min-width: 1024px) { .hero { grid-template-columns: 1fr 400px; gap: 64px; } }

.rate-card { background: var(--surface-raised); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden; }
.rate-card__head { display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--line); }
.rate-card__title { font-size: var(--text-md); font-weight: 700; }
.live { display: inline-flex; align-items: center; gap: 7px; font-size: var(--text-xs);
    font-weight: 700; color: var(--action-destructive);
    background: color-mix(in oklch, var(--action-destructive) 8%, var(--surface-raised));
    border: 1px solid color-mix(in oklch, var(--action-destructive) 28%, var(--surface-raised));
    padding: 4px 10px; border-radius: 999px; }
.live .dot { width: 6px; height: 6px; }
.rate-card__body { padding: 20px 18px; }
.rate-card__pair { font-size: var(--text-xs); font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; color: var(--ink-soft); }
.rate-card__value { font-size: 52px; font-weight: 800; letter-spacing: -.035em;
    line-height: 1; margin-top: 6px; font-variant-numeric: tabular-nums; }
.rate-card__eq { font-size: var(--text-sm); color: var(--ink-soft); margin-top: 6px; }
.rate-card__split { display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
.rate-card__split .k { display: block; font-size: var(--text-2xs); font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase; color: var(--ink-soft); }
.rate-card__split .n { display: block; font-size: var(--text-xl); font-weight: 800;
    letter-spacing: -.02em; margin-top: 3px; font-variant-numeric: tabular-nums; }
.rate-card__foot { padding: 12px 18px; border-top: 1px solid var(--line);
    font-size: var(--text-sm); color: var(--ink-soft); }

.cost dl { display: grid; gap: 0; margin-top: 22px; border-top: 1px solid var(--line); }
@media (min-width: 768px) { .cost dl { grid-template-columns: repeat(3, 1fr); } }
.cost dl > div { padding: 22px 0; border-bottom: 1px solid var(--line); }
@media (min-width: 768px) {
    .cost dl > div { border-bottom: 0; padding: 22px 24px 22px 0; }
    .cost dl > div + div { border-left: 1px solid var(--line); padding-left: 24px; }
}
.cost dt { font-size: var(--text-2xs); font-weight: 700; letter-spacing: .07em;
    text-transform: uppercase; color: var(--ink-soft); }
.cost dd { font-size: var(--text-4xl); font-weight: 800; letter-spacing: -.03em;
    margin-top: 5px; font-variant-numeric: tabular-nums; }
.cost dd span { display: block; font-size: var(--text-sm); font-weight: 500;
    color: var(--ink-soft); margin-top: 2px; }

/* Three promises across three columns. Two columns left the third orphaned,
   which is what the earlier 1fr 1fr grid did at 1440px. */
.promise ol { list-style: none; margin-top: 20px; border-top: 1px solid var(--line); }
@media (min-width: 1024px) { .promise ol { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0 40px; } }
.promise li { padding: 22px 0; border-bottom: 1px solid var(--line); }
.promise .t { display: block; font-size: var(--text-lg); font-weight: 700; letter-spacing: -.015em; }
.promise .d { display: block; color: var(--ink-mid); font-size: var(--text-md); margin-top: 6px; }

.foot2 { display: grid; gap: 40px; padding: 60px 0 0; align-items: start; }
@media (min-width: 1024px) { .foot2 { grid-template-columns: 1fr 400px; gap: 64px; } }
```

Use `var(--text-*)` and `var(--space-*)` from `tokens.css` rather than the literal pixel values, so the type scale actually drives the page.

- [ ] **Step 4: Build and check**

Run: `cd app && npm run build && npm run lint:design`

Expected: build succeeds, design lint reports 0 errors.

- [ ] **Step 5: Run the whole suite**

Run: `cd /Users/macbookpro/Desktop/kudimarket && bash scripts/test.sh`

Expected: SQL `passed: 443 failed: 0`; PHP all green.

- [ ] **Step 6: Commit**

```bash
cd /Users/macbookpro/Desktop/kudimarket
git add app/resources/css/marketing.css app/tests/Feature/ResponsiveLayoutTest.php
git commit -m "feat(landing): style the new sections, using the type scale

The hero splits at lg, the rate card carries the proof, the cost band is three
columns, and the promises are three columns rather than two -- a two-column grid
left the third promise orphaned with a gap beside it at 1440px.

Values come from the tokens rather than the literal pixels, so the type scale
that project 1 built is what actually drives the page."
```

---

## Task 5: Verify in a browser, then stop

- [ ] **Step 1: Build and serve locally**

```bash
cd /Users/macbookpro/Desktop/kudimarket/app && npm run build
KUDI_LAUNCHING=true nohup php artisan serve --port=8123 >/tmp/kudi-landing.log 2>&1 & disown
sleep 8 && curl -s -o /dev/null -w "status=%{http_code}\n" http://127.0.0.1:8123/
```

If port 8123 answers with a page that still shows "Sell to us", something else already owns it — check `lsof -ti:8123` before trusting any screenshot.

- [ ] **Step 2: Run the visual harness**

```bash
cd /Users/macbookpro/Desktop/kudimarket && KUDI_URL=http://127.0.0.1:8123 node scripts/visual-check.mjs
```

Expected: `ok` at 375, 768, 1024 and 1440.

- [ ] **Step 3: Screenshot 1440px and compare against the approved mockup**

Take a full-page screenshot at 1440px. Check, against `/tmp/kudilab/optD.png`:

- the rate card sits right of the hero copy at 1440 and below it at 375
- `15,050.00` / `14,820.00` appears in the cost band, never a raw float
- no screenshot of the app anywhere on the page
- no occurrence of escrow, trade or offer
- the three promises sit in three columns with no orphan

- [ ] **Step 4: Stop the server**

```bash
pkill -f "artisan serve --port=8123"
```

- [ ] **Step 5: Do not deploy**

Report what changed and stop. Deploying is a separate, explicit instruction.

---

## Definition of done

- The page describes a remittance business and contains no escrow, offer or trade language.
- The hero proof is the live rate card; no product screenshots remain on the page.
- The doors link to anchors that exist.
- The worked example is computed from `rate_card` and moves when the rate moves.
- "Same day", never "in minutes".
- No horizontal overflow and every standalone control ≥44px at 375 / 768 / 1024 / 1440.
- Full suite green: SQL 443/443, PHP all passing, design lint 0 errors.
- Nothing deployed.
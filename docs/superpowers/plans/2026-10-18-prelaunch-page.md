# Prelaunch Page — Phone As Hero Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the public landing page as the approved 1b composition — a playable send device in the hero, ending in the waitlist — with every figure computed from the live rate.

**Architecture:** `LandingController` keeps reading the live `rate_card` and grows one method, `sendDemo()`, that returns the three chip amounts with their formatted figures plus the raw rate. A new Blade partial renders the five-screen device; the marketing stylesheet styles it; one small ES module drives screen changes and chip recalculation. The existing waitlist form, referral capture, signup count and contact links are untouched.

**Tech Stack:** Laravel 12 / PHP 8.3, Blade, PostgreSQL (`rate_card`, `trade_policy`), Vite, plain ES modules, PHPUnit feature tests.

**Design reference:** `brand/prelaunch/1b.html` is the approved composition; `docs/superpowers/specs/2026-10-18-prelaunch-page-design.md` is the spec. Read the candidate before Task 2 — it holds the exact copy and spacing.

---

## File Structure

| file | responsibility |
|---|---|
| `app/app/Http/Controllers/LandingController.php` (modify) | adds `sendDemo()`; passes `demo` to the view; drops the now-unused `workedExample()` |
| `app/resources/views/public/partials/send-demo.blade.php` (create) | the five-screen device, server-rendered with live figures |
| `app/resources/views/public/landing.blade.php` (modify) | 1b hero, waitlist band, promise rows, footer |
| `app/resources/css/marketing.css` (modify) | `.device` styles and the new hero/rows |
| `app/resources/js/landing-demo.js` (create) | screen state machine, chip recalculation, waitlist focus |
| `app/resources/js/app.js` (modify) | dynamically imports the demo module only when the device is present |
| `app/tests/Feature/LandingPageTest.php` (modify) | live-rate assertions, updated `h1`, new device assertions |
| `app/tests/Feature/ResponsiveLayoutTest.php` (modify) | asserts the partial carries no inline CSS and the styles live in the stylesheet |

---

### Task 1: The device's figures come from the live rate

**Files:**
- Modify: `app/app/Http/Controllers/LandingController.php:36-53` (view data) and `:142-167` (replace `workedExample()`)
- Test: `app/tests/Feature/LandingPageTest.php`

- [ ] **Step 1: Write the failing test**

Add to `LandingPageTest`, directly after `test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded`:

```php
    public function test_the_send_demo_states_figures_taken_from_the_live_rate(): void
    {
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '150.5', 'rates@kudimarket.com']
        );

        // 500 GHS at 150.5 is 75,250 NGN. Typed into the template it would survive a rate move, which
        // is the one failure this page is built to make impossible.
        $this->get('/about')
            ->assertOk()
            ->assertSee('75,250.00');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '200', 'rates@kudimarket.com']
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee('100,000.00')
            ->assertDontSee('75,250.00');
    }
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd app && php artisan test --filter=test_the_send_demo_states_figures_taken_from_the_live_rate --compact
```

Expected: FAIL — `75,250.00` is not on the page, because nothing renders the device yet.

- [ ] **Step 3: Add `sendDemo()` to the controller**

In `LandingController`, replace the `'example' => $this->workedExample($rates),` line in the view data with:

```php
            'demo' => $this->sendDemo($rates),
```

Replace the whole `workedExample()` method (and its docblock) with:

```php
    /**
     * The three amounts the demo offers, each with its figures at the live rate.
     *
     * Computed here rather than typed into the template, for the reason every figure on this page is
     * computed here: a demo that shows a stale number teaches a visitor to distrust the rate.
     *
     * @param  array<string, string>  $rates
     * @return array{raw: float, amounts: array<int, array{value: float, send: string, receive: string}>}|null
     */
    private function sendDemo(array $rates): ?array
    {
        $rate = $rates['GHS/NGN'] ?? null;

        if ($rate === null) {
            return null;
        }

        $amounts = [];

        foreach ([500.0, 1000.0, 3000.0] as $amount) {
            $amounts[] = [
                'value' => $amount,
                'send' => MoneyFormatter::format($amount),
                'receive' => MoneyFormatter::format($amount * (float) $rate),
            ];
        }

        return ['raw' => (float) $rate, 'amounts' => $amounts];
    }
```

- [ ] **Step 4: Render one figure so the test can pass**

In `app/resources/views/public/landing.blade.php`, inside the existing `.rate-card` `<aside>`, add one line under the `.rate-card__eq` paragraph:

```blade
                        @if ($demo)
                            <p class="rate-card__eq">Demo: you send {{ \App\Support\MoneyFormatter::format($demo['amounts'][0]['send']) }}, she receives {{ \App\Support\MoneyFormatter::format($demo['amounts'][0]['receive']) }}</p>
                        @endif
```

- [ ] **Step 5: Run the test and the neighbours**

```bash
cd app && php artisan test --filter="LandingPageTest" --compact
```

Expected: the new test PASSES. `test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded` also still passes — the ₵100 example is still rendered by the rate card for now, and Task 3 removes both together.

- [ ] **Step 6: Commit**

```bash
git add app/app/Http/Controllers/LandingController.php app/resources/views/public/landing.blade.php app/tests/Feature/LandingPageTest.php
git commit -m "feat(landing): compute the demo figures from the live rate"
```

---

### Task 2: The device, server-rendered

**Files:**
- Create: `app/resources/views/public/partials/send-demo.blade.php`
- Modify: `app/resources/views/public/landing.blade.php` (replace the `@if ($demo)` paragraph from Task 1 with the include)
- Test: `app/tests/Feature/LandingPageTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `LandingPageTest`:

```php
    public function test_the_device_shows_the_send_confirmed_in_words_and_never_on_the_button(): void
    {
        $response = $this->get('/about')->assertOk();

        $response->assertSee('You send');
        $response->assertSee('Amara receives');
        $response->assertSee('Amara Okafor');
        $response->assertSee('Confirm send');
        $response->assertSee('Demo — no real money moves.');

        // The confirm button is a plum surface, and a plum surface never holds a number.
        $this->assertStringNotContainsString(
            '>Confirm send ₵',
            (string) file_get_contents(resource_path('views/public/partials/send-demo.blade.php'))
        );
    }

    public function test_the_device_states_a_configured_fee_and_says_nothing_when_there_is_none(): void
    {
        DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 100 where trade_type = 'platform_inventory'"
        );

        $this->get('/about')->assertOk()->assertSee('Our fee 1%');

        DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 0 where trade_type = 'platform_inventory'"
        );

        $this->get('/about')->assertOk()->assertDontSee('Our fee');
    }
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd app && php artisan test --filter="test_the_device" --compact
```

Expected: FAIL — the partial does not exist.

- [ ] **Step 3: Create the partial**

Create `app/resources/views/public/partials/send-demo.blade.php`:

```blade
{{-- The playable send. The strongest objection on a money product is "show me it works", and a rate
     card answers it with a number rather than an interface. Every figure here is computed in
     LandingController from the live rate; none is typed in. The screens are labelled a demo because
     they are one -- nothing here moves money. --}}
@if ($demo)
    <div class="device" data-send-demo data-rate="{{ $demo['raw'] }}">
        <div class="device__frame">
            <div class="device__screen">

                <p class="device__status">9:41</p>

                <ol class="device__steps">
                    <li class="on"></li><li></li><li></li><li></li><li></li>
                </ol>

                <section class="device__panel" data-screen="amount" aria-label="Amount">
                    <p class="device__label">You send</p>
                    <p class="device__figure" data-bind="send">₵{{ $demo['amounts'][0]['send'] }}</p>
                    <div class="device__chips" role="group" aria-label="Choose an amount">
                        @foreach ($demo['amounts'] as $index => $amount)
                            <button type="button"
                                    class="device__chip{{ $index === 0 ? ' is-on' : '' }}"
                                    data-amount="{{ $amount['value'] }}"
                                    aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">₵{{ $amount['send'] }}</button>
                        @endforeach
                    </div>
                    <p class="device__note">1 cedi = {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }} naira today</p>
                    <div class="device__actions">
                        <button type="button" data-go="rate">Continue</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="rate" aria-label="Rate" hidden>
                    <p class="device__label">Today's rate</p>
                    <p class="device__figure">{{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }}</p>
                    <p class="device__note">1 cedi = {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }} naira</p>
                    <div class="device__card">
                        <span class="device__tag">Rate locked</span>
                        <p class="device__line">Amara receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b></p>
                        @if ($feePercent !== null)
                            <p class="device__line">Our fee {{ $feePercent }}%</p>
                        @endif
                    </div>
                    <div class="device__actions">
                        <button type="button" data-go="receiver">Continue</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="receiver" aria-label="Receiver" hidden>
                    <p class="device__label">Send to</p>
                    <div class="device__person">
                        <span class="device__avatar" aria-hidden="true">AO</span>
                        <span class="device__who">
                            <b>Amara Okafor</b>
                            <span>+234 803 555 0142 · Nigeria</span>
                        </span>
                    </div>
                    <p class="device__note">Naira lands with her. Not a promise — a transfer.</p>
                    <div class="device__actions">
                        <button type="button" data-go="confirm">Continue</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="confirm" aria-label="Confirm" hidden>
                    <p class="device__label">Confirm</p>
                    <div class="device__words">
                        <p>You're sending <b data-bind="send">₵{{ $demo['amounts'][0]['send'] }}</b> to <b>Amara Okafor</b>.</p>
                        <p>She receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b>.</p>
                    </div>
                    <p class="device__note">Nothing moves until she confirms the naira landed.</p>
                    <div class="device__actions">
                        <button type="button" data-go="sent">Confirm send</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="sent" aria-label="Sent" hidden>
                    <div class="device__done">
                        <p class="device__done-title">Sent to Amara Okafor</p>
                        <p class="device__done-line">She receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b></p>
                        <p class="device__done-note">Rate locked at {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }}. She confirms, then it releases.</p>
                    </div>
                    <div class="device__actions">
                        <button type="button" data-waitlist>Join the waitlist</button>
                        <button type="button" class="device__ghost" data-restart>Run it again</button>
                    </div>
                </section>

            </div>
        </div>
        <p class="device__disclaimer">Demo — no real money moves. The rate is today's.</p>
    </div>
@endif
```

- [ ] **Step 4: Include it, replacing the Task 1 paragraph**

In `landing.blade.php`, delete the paragraph added in Task 1 and add the include in its place:

```blade
                        @include('public.partials.send-demo')
```

- [ ] **Step 5: Run the device tests**

```bash
cd app && php artisan test --filter="test_the_device" --compact
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/resources/views/public/partials/send-demo.blade.php app/resources/views/public/landing.blade.php app/tests/Feature/LandingPageTest.php
git commit -m "feat(landing): the playable send device, server-rendered"
```

---

### Task 3: The 1b hero

**Files:**
- Modify: `app/resources/views/public/landing.blade.php` (whole `@section('content')`)
- Modify: `app/app/Http/Controllers/LandingController.php` (drop the now-unused `workedExample()` if the view no longer renders `$example`)
- Test: `app/tests/Feature/LandingPageTest.php`

- [ ] **Step 1: Update the failing test**

In `LandingPageTest`, replace the `h1` assertions in the first test (`Your market.` / `Your rate.`) with:

```php
            ->assertSee('Send money home')
            ->assertSee('in a minute.');
```

Also update `test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded`, whose figures move with the device's default amount:

```php
        // 500 GHS at 150.5 is 75,250 NGN. If the page ever shows a figure that was typed into the
        // template rather than multiplied out of the rate card, this fails.
        $this->get('/about')
            ->assertOk()
            ->assertSee('75,250.00');
        ...
            ->assertSee('100,000.00')
            ->assertDontSee('75,250.00');
```

And extend `ResponsiveLayoutTest::test_the_printed_rate_is_no_longer_hand_stripped`, which today reads
only `landing.blade.php`. The rate now prints inside the partial, so the test has to read both:

```php
        $view = (string) file_get_contents(resource_path('views/public/landing.blade.php'))
            . (string) file_get_contents(resource_path('views/public/partials/send-demo.blade.php'));
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd app && php artisan test --filter="LandingPageTest" --compact
```

Expected: FAIL — the `h1` is still "Your market. Your rate.".

- [ ] **Step 3: Rebuild the content section**

Replace everything between `@section('content')` and `@endsection` with:

```blade
@section('content')
    @if (session('subscribed'))
        @include('public.partials.confirmation')
    @else
        <section class="hero hero--centred">
            <p class="status">Not open yet · Kumasi &amp; Lagos</p>

            <h1>Send money home<br>in a minute.</h1>

            <p class="lead">Pick an amount. Watch the rate. Confirm in words. Nothing moves until the naira lands.</p>

            @include('public.partials.send-demo')
        </section>

        <section class="join" id="waitlist">
            <h2>Be first when we open.</h2>
            <p>One email, when we open. Nothing else.</p>

            <form method="post" action="{{ route('landing.subscribe') }}" class="signup" novalidate>
                @csrf
                @if ($referrer)
                    <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
                @endif
                <label for="email">Email address</label>
                <div class="row">
                    <input type="email" id="email" name="email" value="{{ old('email') }}"
                           autocomplete="email" required aria-describedby="email-help email-error">
                    <button type="submit">Join the waitlist</button>
                </div>
                @error('email')
                    <p class="err" id="email-error" role="alert">{{ $message }}</p>
                @enderror
                <p class="help" id="email-help">
                    No spam, no resold lists.
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
        </section>

        <dl class="promises">
            <div class="promises__row"><dt>Cash in hand, the same day.</dt><dd>Naira reaches her in hand, not a promise in an inbox.</dd></div>
            <div class="promises__row"><dt>Nobody touches your money until you say so.</dt><dd>Both sides see the rate and the words before anything moves.</dd></div>
            <div class="promises__row"><dt>The rate you agreed is the rate you get.</dt><dd>Locked in words before anything moves. What you confirm is what lands.</dd></div>
            <div class="promises__row"><dt>Buy, sell, settle. Nothing else.</dt><dd>Same screens, same speed, both directions. No side gets a better deal.</dd></div>
        </dl>
    @endif
@endsection
```

- [ ] **Step 4: Add the header's waitlist link so the anchor resolves**

In `app/resources/views/layouts/marketing.blade.php`, inside `<header class="top">`, after the mark anchor:

```blade
            <a class="waitlist-link" href="#waitlist">Join the waitlist</a>
```

- [ ] **Step 5: Drop the dead worked example from the controller**

`workedExample()` is no longer called and `$example` is no longer rendered. Delete the `'example' => $this->workedExample($rates),` line and the `workedExample()` method from `LandingController`. Leave `MoneyFormatter` imported — `sendDemo()` uses it.

- [ ] **Step 6: Run the whole landing and layout suites**

```bash
cd app && php artisan test --filter="LandingPageTest|ResponsiveLayoutTest" --compact
```

Expected: PASS. `ResponsiveLayoutTest::test_the_doors_point_at_anchors_that_exist` passes because `#waitlist` now carries the `id`.

- [ ] **Step 7: Commit**

```bash
git add app/resources/views/public/landing.blade.php app/resources/views/layouts/marketing.blade.php app/app/Http/Controllers/LandingController.php app/tests/Feature/LandingPageTest.php
git commit -m "feat(landing): the phone as hero"
```

---

### Task 4: The device's styles live in the stylesheet

**Files:**
- Modify: `app/resources/css/tokens.css` (add the settled surface tokens — they do not exist yet)
- Modify: `app/resources/css/marketing.css` (append)
- Test: `app/tests/Feature/ResponsiveLayoutTest.php`

- [ ] **Step 1: Write the failing test**

Add to `ResponsiveLayoutTest`:

```php
    public function test_the_device_styling_lives_in_the_stylesheet_and_not_in_the_markup(): void
    {
        $partial = (string) file_get_contents(resource_path('views/public/partials/send-demo.blade.php'));
        $css = (string) file_get_contents(resource_path('css/marketing.css'));

        $this->assertStringNotContainsString('<style', $partial);
        $this->assertStringNotContainsString('style=', $partial);
        $this->assertStringContainsString('.device__frame', $css);
    }
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd app && php artisan test --filter=test_the_device_styling_lives_in_the_stylesheet --compact
```

Expected: FAIL — `.device__frame` is not in `marketing.css` yet.

- [ ] **Step 3: Add the two settled-surface tokens**

`tokens.css` has settled *text* (`--state-settled`) but no settled *background* or settled *ink*,
and the confirmation panel needs both. Add them through the existing three layers — primitives
first, then the semantic aliases:

```css
    /* in Layer 1: primitives, beside --green */
    --green-100: #D7E8DC;
    --green-900: #1B3D28;

    /* in Layer 2: semantic, beside --state-settled */
    --settled-bg: var(--green-100);
    --settled-ink: var(--green-900);
```

- [ ] **Step 4: Append the component styles**

Append to `app/resources/css/marketing.css`. Every colour is a token from `tokens.css`; the bare
`button` rule in this file paints every button plum, so the chips and the ghost button override it
explicitly rather than assuming they are exempt:

```css
/* ─── The playable send ──────────────────────────────────────────────────────
   The device is a preview, not the product: it carries the real rate and the
   real figures, and it is labelled as a demo wherever it appears. Money is ink
   on bone with tabular figures — a plum surface never holds a number, and the
   green panel is a panel, never a button. */
.device{display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:52px}
.device__frame{width:320px;background:var(--plum-900);border-radius:38px;padding:12px;
  box-shadow:0 26px 60px -32px rgba(51,25,49,.45)}
.device__screen{background:var(--bone);border-radius:28px;height:470px;display:flex;
  flex-direction:column;overflow:hidden;position:relative}
.device__status{font-size:14px;color:var(--ink-soft);padding:13px 20px 0;
  font-variant-numeric:tabular-nums lining-nums}
.device__steps{list-style:none;display:flex;gap:5px;padding:12px 20px 0}
.device__steps li{flex:1;height:3px;border-radius:2px;background:var(--line)}
.device__steps li.on{background:var(--plum-600)}
.device__panel{display:flex;flex-direction:column;padding:26px 20px 18px;
  position:absolute;inset:0;animation:device-in .18s ease both}
.device__panel[hidden]{display:none!important}
@keyframes device-in{from{opacity:0;transform:translateY(5px)}}
.device__actions{margin-top:auto;display:flex;flex-direction:column;gap:8px}
.device__label{font-size:14px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:14px}
.device__figure{font-size:46px;font-weight:600;letter-spacing:-.03em;line-height:1;
  font-variant-numeric:tabular-nums lining-nums}
.device__note{font-size:14px;line-height:1.5;color:var(--ink-soft);margin-top:14px}
.device__chips{display:flex;gap:8px;margin-top:14px}
.device__chip{height:var(--tap-target);padding:0 14px;border:1px solid var(--line);border-radius:var(--control-radius);
  background:var(--paper);
  font:inherit;font-size:14px;font-weight:600;color:var(--ink);cursor:pointer;
  font-variant-numeric:tabular-nums lining-nums;letter-spacing:-.01em}
.device__chip:hover{background:var(--paper);border-color:var(--line)}
.device__chip.is-on:hover{border-color:var(--plum-600);box-shadow:inset 0 0 0 1px var(--plum-600)}
.device__ghost{background:transparent;border-color:var(--line);color:var(--ink-soft)}
.device__ghost:hover{background:transparent;border-color:var(--ink-soft);color:var(--ink)}
.device__chip.is-on{border-color:var(--plum-600);box-shadow:inset 0 0 0 1px var(--plum-600)}
.device__card{background:var(--paper);border-radius:var(--card-radius);padding:14px 16px;margin-top:14px;
  display:flex;flex-direction:column;gap:10px}
.device__tag{align-self:flex-start;font-size:14px;font-weight:600;color:var(--plum-600);
  border:1px solid var(--plum-600);border-radius:6px;padding:2px 8px}
.device__line{font-size:14px;color:var(--ink-soft)}
.device__line b{font-size:20px;font-weight:600;color:var(--ink);font-variant-numeric:tabular-nums lining-nums}
.device__person{display:flex;align-items:center;gap:12px}
.device__avatar{width:42px;height:42px;border-radius:50%;background:var(--plum-900);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700}
.device__who{display:flex;flex-direction:column;gap:2px}
.device__who b{font-size:16px;font-weight:600}
.device__who span{font-size:14px;color:var(--ink-soft);font-variant-numeric:tabular-nums lining-nums}
.device__words{background:var(--paper);border:1px solid var(--card-border);border-radius:var(--card-radius);padding:16px;
  display:flex;flex-direction:column;gap:9px;font-size:14.5px;line-height:1.5;color:var(--ink-soft)}
.device__words b{color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums lining-nums}
.device__done{background:var(--settled-bg);border-radius:14px;padding:18px;
  display:flex;flex-direction:column;gap:9px}
.device__done-title{font-size:16px;font-weight:600;color:var(--settled-ink)}
.device__done-line{font-size:14.5px;color:var(--state-settled)}
.device__done-line b{font-weight:600;color:var(--settled-ink);font-variant-numeric:tabular-nums lining-nums}
.device__done-note{font-size:14px;line-height:1.5;color:var(--state-settled)}
.device__disclaimer{font-size:14px;line-height:1.5;color:var(--ink-soft);text-align:center;max-width:32ch}

@media (prefers-reduced-motion:reduce){
  .device__panel{animation:none}
}
```

Then add the hero and promise rules for the new structure:

```css
.hero--centred{display:flex;flex-direction:column;align-items:center;text-align:center;padding:64px 0 56px}
.hero--centred h1{font-size:clamp(34px,5.4vw,56px);font-weight:600;letter-spacing:-.03em;line-height:1.06;margin:18px 0 0}
.hero--centred .lead{max-width:52ch;margin:20px auto 0}
.join{border-top:1px solid var(--line);padding:64px 0}
.join h2{font-size:clamp(24px,2.6vw,30px);font-weight:600;letter-spacing:-.025em}
.join > p{font-size:15px;color:var(--ink-soft);margin:8px 0 0}
.promises{border-top:1px solid var(--line)}
.promises__row{display:flex;gap:26px;align-items:baseline;padding:22px 0;border-bottom:1px solid var(--line)}
.promises__row dt{flex:0 0 280px;font-size:15px;font-weight:600}
.promises__row dd{margin:0;font-size:15px;line-height:1.5;color:var(--ink-soft);max-width:56ch}
.waitlist-link{font-size:14px;font-weight:600;color:var(--plum-600);text-decoration:none;
  border:1px solid var(--line);border-radius:999px;padding:9px 16px;background:var(--paper)}
@media (max-width:900px){
  .promises__row{flex-direction:column;gap:6px}
  .promises__row dt{flex:none}
  .device__frame{width:268px;border-radius:32px;padding:10px}
  .device__screen{height:412px;border-radius:24px}
  .device__figure{font-size:40px}
}
```

- [ ] **Step 5: Run the test**

```bash
cd app && php artisan test --filter="ResponsiveLayoutTest" --compact
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/resources/css/tokens.css app/resources/css/marketing.css app/tests/Feature/ResponsiveLayoutTest.php
git commit -m "style(landing): the device and the hero it sits in"
```

---

### Task 5: The device moves

**Files:**
- Create: `app/resources/js/landing-demo.js`
- Modify: `app/resources/js/app.js`
- Test: `app/tests/Feature/ResponsiveLayoutTest.php`

- [ ] **Step 1: Write the failing test**

Add to `ResponsiveLayoutTest`:

```php
    public function test_the_device_javascript_is_loaded_only_where_the_device_is(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.js'));
        $demo = resource_path('js/landing-demo.js');

        $this->assertFileExists($demo);
        $this->assertStringContainsString("./landing-demo", $app);
        $this->assertStringContainsString('data-send-demo', $app);
    }
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd app && php artisan test --filter=test_the_device_javascript_is_loaded_only_where_the_device_is --compact
```

Expected: FAIL — `resources/js/landing-demo.js` does not exist.

- [ ] **Step 3: Create the module**

Create `app/resources/js/landing-demo.js`:

```js
const STATES = ['amount', 'rate', 'receiver', 'confirm', 'sent'];

const money = (value) => value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function initSendDemo(root) {
    const rate = Number(root.dataset.rate);
    const panels = Array.from(root.querySelectorAll('[data-screen]'));
    const steps = Array.from(root.querySelectorAll('.device__steps li'));
    const chips = Array.from(root.querySelectorAll('.device__chip'));

    let amount = Number(chips.find((chip) => chip.classList.contains('is-on')).dataset.amount);

    const paint = () => {
        root.querySelectorAll('[data-bind="send"]').forEach((node) => { node.textContent = '₵' + money(amount); });
        root.querySelectorAll('[data-bind="receive"]').forEach((node) => { node.textContent = '₦' + money(amount * rate); });
    };

    const show = (name) => {
        const index = STATES.indexOf(name);
        if (index < 0) { return; }

        panels.forEach((panel) => { panel.hidden = panel.dataset.screen !== name; });
        steps.forEach((step, position) => { step.classList.toggle('on', position <= index); });
    };

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            amount = Number(chip.dataset.amount);
            chips.forEach((other) => {
                other.classList.toggle('is-on', other === chip);
                other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
            });
            paint();
        });
    });

    root.querySelectorAll('[data-go]').forEach((button) => {
        button.addEventListener('click', () => show(button.dataset.go));
    });

    root.querySelectorAll('[data-back]').forEach((button) => {
        button.addEventListener('click', () => {
            show(STATES[Math.max(0, STATES.indexOf(panels.find((panel) => !panel.hidden).dataset.screen) - 1)]);
        });
    });

    root.querySelector('[data-restart]').addEventListener('click', () => show('amount'));

    root.querySelector('[data-waitlist]').addEventListener('click', () => {
        const field = document.querySelector('#email');
        if (!field) { return; }
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field.focus({ preventScroll: true });
    });

    paint();
    show('amount');
}
```

- [ ] **Step 4: Load it where the device is**

Replace the contents of `app/resources/js/app.js` with:

```js
// The landing device is the only thing on the site that needs scripting, and the trader app never
// has one on the page. Importing it on sight keeps the bundle honest about what ships where.
if (document.querySelector('[data-send-demo]')) {
    import('./landing-demo').then((module) => {
        module.initSendDemo(document.querySelector('[data-send-demo]'));
    });
}
```

- [ ] **Step 5: Run the test, then build the assets**

```bash
cd app && php artisan test --filter="ResponsiveLayoutTest" --compact && npm run build
```

Expected: PASS, then a Vite build with no errors. If the page shows a missing-manifest error, that is what `npm run build` fixes.

- [ ] **Step 6: Verify in a browser**

```bash
cd app && php artisan serve --port=8123
```

Open `http://127.0.0.1:8123/`, then click through: ₵1,000 chip (every figure becomes ₵1,000.00 and ₦148,200.00 at a 148.20 rate), Continue ×4, "Confirm send" (green panel), "Join the waitlist" (the page scrolls to the email field and focuses it), "Run it again". Press Back at each step and confirm it returns one screen, not to the start.

- [ ] **Step 7: Commit**

```bash
git add app/resources/js/landing-demo.js app/resources/js/app.js app/tests/Feature/ResponsiveLayoutTest.php
git commit -m "feat(landing): the device moves"
```

---

### Task 6: The whole thing, checked

**Files:**
- Modify: whatever the checks find

- [ ] **Step 1: Run the full suite**

```bash
./scripts/test.sh
```

Expected: every PHP and SQL check green. Fix anything that fails before continuing.

- [ ] **Step 2: Format and lint**

```bash
cd app && vendor/bin/pint --dirty --format agent && cd .. && node kudilint/kudilint.mjs
```

Expected: Pint clean; kudilint reports its existing result for the repository (`brand/prelaunch` must stay at 0 errors).

- [ ] **Step 3: Look at it at both widths**

Screenshot `/` at 1440×900 and 390×844. Check: the device is fully visible without horizontal scroll at 390px; no text below 14px; every money figure in ink, never plum; the green panel is a panel and no green button exists anywhere.

- [ ] **Step 4: Check the page a bank or a journalist would read**

```bash
cd app && php artisan test --filter="LandingPageTest" --compact && cd .. && rg -in 'escrow|trade|offer|seamless|revolutionary|cutting-edge|world-class|bank-grade|best-in-class' app/resources/views/public/
```

Expected: the suite passes and the search returns nothing.

- [ ] **Step 5: Commit whatever the checks changed**

```bash
git add -A && git commit -m "fix(landing): what the checks caught"
```

If nothing changed, there is nothing to commit — that is a valid outcome, not a failure.

---

## Notes for the implementer

- **The banned words are not stylistic.** `escrow`, `trade` and `offer` are forbidden on this page by
  `LandingPageTest`, because BRAND.md §0 retires escrow: the business moves money, it does not hold
  it. The copy carries the same promise without claiming custody.
- **Never type a figure.** Every number in the device comes from `sendDemo()` or from
  `RateFormatter::format($rates['GHS/NGN'])`. A hardcoded `148.20` in a Blade file is the defect this
  page exists to avoid.
- **`Confirm send` carries no amount.** Plum is the action colour and a plum surface never holds a
  number. The amount is in the sentence above the button.
- **The signup count is real.** `$waiting` is a `count()`; it is never typed into the template and
  must not be.
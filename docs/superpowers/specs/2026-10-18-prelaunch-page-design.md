# Prelaunch page redesign — design spec

**Date:** 18 Oct 2026
**Status:** design approved (composition 1b chosen), not yet implemented
**Chosen direction:** `brand/prelaunch/1b.html` — phone as hero

## 1. The problem

The current landing page (`app/resources/views/public/landing.blade.php`) argues three things at
once — rate, cost, trust — behind a three-link "doors" nav, and never shows the product. Its own
header comment records the reason it has no send screen: *"that flow does not exist yet."* That
reason has expired. `SendFlowTest` covers a real beneficiary flow (`send/create`, `send/show`,
`open → paid_claimed → paid_attested → paid_out → released`). The strongest objection on a money
product is "show me it works", and the page currently answers it with a rate card.

The settled spine, agreed with the owner: **the playable phone is the hero.** A visitor should be
able to tap through a complete send — amount, rate, receiver, confirmation, sent — before they read
a second sentence. The demo's end-cap is the waitlist, which is the only conversion on the page.

## 2. Directions considered

Three complete, screenshot-verified candidates exist in `brand/prelaunch/`, sharing an identical
device, rate and waitlist so that only composition differs.

| id | composition | verdict |
|---|---|---|
| 1a | Type first — "Your market. Your rate." plus a worked receipt, phone beside | Strong argument, weak tap-through |
| 1b | Phone as hero — "Send money home in a minute.", device centred, waitlist underneath | **Chosen.** It is the spine taken literally |
| 1c | Scroll story — five beats drive the phone as you scroll | Best pacing, longest page |

Rejected: a hybrid "animated hero + demo below the fold" (the demo stays an afterthought, which is
the current failure), and a copy-first editorial page (argues instead of demonstrating).

## 3. What ships

The 1b composition, rebuilt on the real stack:

1. Header — wordmark, "Join the waitlist" anchor.
2. Hero — status line **"Not open yet"**, `h1` "Send money home in a minute.", one-line lead.
3. The playable device, centred, five screens, live amounts.
4. Waitlist band — the existing form, unchanged in behaviour.
5. Four ruled promise rows, led by the three mechanically-true promises the page already asserts.
6. Footer — "Not a terminal. Not a casino. Not a bank."

Removed from the current page: the three doors nav, the abstract rate table, and the "and back"
inverse-rate row. The rate card's **fee line is kept and moved into the device** — a fee that
disappears with the redesign is a fee nobody can check.

## 4. The device

Five screens, tappable, ending in the waitlist:

`amount → rate → receiver → confirm → sent`

- Amount chips: ₵500 (default), ₵1,000, ₵3,000. Each chip re-renders every figure in the flow.
- Rate screen: the live GHS/NGN rate, a "Rate locked" tag, the naira the receiver gets, and — only
  when `$feePercent` is not null — a fee row reading "Our fee 1.5%". The fee is stated, never
  applied: the device shows no net-of-fee figure, because no fee arithmetic is invented here. When
  no fee is configured the row is absent, the same rule the buy screens follow.
- Receiver screen: a named beneficiary. **Amara Okafor, +234 803 555 0142** — a fictional person,
  chosen because a cedi→naira send carrying a Ghanaian name reads wrong. The production seed's
  Kwesi Okafor is test data and stays test data.
- Confirm screen: the confirmation written in words — "You're sending ₵500.00 to Amara Okafor. She
  receives ₦74,100.00." Primary button reads "Confirm send" and carries **no amount**, because a
  plum surface must never hold a number.
- Sent screen: settled green panel (never a button), "Join the waitlist" and "Run it again".

Labelled on the page: "Demo — no real money moves. The rate is today's."

## 5. Constitution compliance

Enforced by `kudilint/kudilint.mjs` and `LandingPageTest`; the candidates pass at zero errors.

- **Banned on this page** (`LandingPageTest` line 72): `escrow`, `trade`, `offer`. BRAND.md §0
  retires escrow — the business moves money, it does not hold it. The first draft of these
  candidates used the word throughout and was corrected. The three promises carry the same meaning
  without claiming custody.
- **Banned adjectives** (line 64): seamless, revolutionary, cutting-edge, world-class, bank-grade,
  best-in-class.
- **Required strings**: "Not open yet", "Cash in hand, the same day.", "Nobody touches your money
  until you say so.", "The rate you agreed is the rate you get."
- Tokens only, from `kudilint/kudi.config.json`. Plum = action/pending, green = confirmation only,
  red = loss/destructive. Money is ink on bone with `tabular-nums lining-nums`; never plum on a
  number.
- No font below 14px. Instrument Sans, Google Fonts' 400–700 (the API silently clamps 300/800, so
  the wordmark uses `kudi` 400 / `market` 700).
- Ruled rows, not equal cards. No gradients, no accent quote blocks, no emoji icons.

## 6. Implementation shape

| file | change |
|---|---|
| `app/resources/views/public/landing.blade.php` | Rebuild hero to 1b; keep the existing form contract |
| `app/resources/views/public/partials/send-demo.blade.php` | New: the device, server-rendered |
| `app/resources/css/marketing.css` | Device + hero styles (no inline `<style>` in views) |
| `app/resources/js/landing-demo.js` | Screen state machine, chip recalculation, waitlist focus |
| `app/app/Http/Controllers/LandingController.php` | Pass demo rate and the three chip figures |

- The rate reaches the template from the live ledger exactly as the worked example does today —
  through `RateFormatter::format($rates['GHS/NGN'])`. **No figure in the device is typed in.** At a
  rate of 150.5 the ₵500 chip must read ₦75,250.00.
- The default (₵500) figures are server-rendered so the page is correct without JavaScript; the
  other two chips are recomputed client-side from a `data-rate` attribute on the device.
- Controller-supplied values stay as they are: `$rates`, `$feePercent`, `$waLink`, `$tgLink`,
  `$referrer`, `$waiting`.
- The form keeps `action="{{ route('landing.subscribe') }}"`, `@csrf`, the referral hidden input,
  `old('email')`, the `@error` block, the real signup count, and the WhatsApp/Telegram links.

## 7. Tests to change, and new ones

Existing, which must keep passing:

- `LandingPageTest::test_the_page_shows_the_real_number_waiting_and_never_an_invented_one` — the
  signup count stays, and stays real.
- `ResponsiveLayoutTest::test_the_doors_point_at_anchors_that_exist` — every `href="#…"` needs a
  matching `id`; the new `#waitlist` anchor must exist.
- `ResponsiveLayoutTest::test_the_printed_rate_is_no_longer_hand_stripped` — `RateFormatter` only.

Must be updated:

- `LandingPageTest` lines 41–42 assert the current `h1` ("Your market." / "Your rate."). 1b's
  `h1` is "Send money home in a minute."; the tagline returns under the device instead.
- `test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded` keeps its intent but its
  figures move with the new default amount: ₵500 at 150.5 is 75,250.00, not 15,050.00. The test
  name still fits; the numbers in it change.

New:

- The device's default figures equal the rate card's live figures.
- Each chip's naira equals the amount times the live rate.

## 8. Risks and open questions

- **`/about` vs the form.** The route the form posts to is `landing.subscribe` (`POST /about`) and
  `LandingPageTest` posts there directly. The page must not be changed to post anywhere else.
- **The device is a preview.** It must never be the only path to the conversion; the waitlist band
  sits directly beneath it and the header link points at the same field.
- **Amara is fictional.** A named beneficiary with a real-looking number could read as a real
  customer. The screen carries the "Demo — no real money moves" label directly beneath it.
- **JavaScript off.** The default screen is server-rendered; only the two non-default chips and the
  screen transitions need JS.
- The mark and favicon remain unresolved and stay out of scope.

## 9. Rollout

Build behind the existing landing route, verify at 1440×900 and 390×844, run
`scripts/test.sh`, then deploy the view, partial, CSS, JS build and controller together. The
candidate files in `brand/prelaunch/` stay in the repo as the record of what was considered.
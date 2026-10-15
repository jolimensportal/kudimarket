# KudiMarket Design System Foundation — Design

**Date:** 15 October 2026
**Status:** Approved for implementation planning
**Scope:** The shared design system only. The landing page and trader app are
rebuilt on it in two follow-on projects.

---

## 1. Why this exists

The live landing page at `kudimarket.com` reads as a mobile page served into a
desktop browser window. Measured at a 1440×900 viewport:

- Content column is ~1000px inside a 1440px viewport, leaving ~220px of empty
  background on each side in every section.
- Two 375px-wide phone mockups are the centrepiece of the middle of the page —
  illegible at desktop width, and they announce "phone app" to someone already
  on a phone.
- The rate card prints `0.006756756756756756` (18 decimal places) on a page whose
  job is to make the reader trust the number.
- `148.2` appears in one place and `148.20` in another, on the same page.
- Sections are headings over hairline dividers with no grid and no density. It
  reads as a document, not a landing page.

The root cause is in code, not in the design file.
`resources/views/layouts/trader.blade.php` sets `main { max-width: 720px }` and
defines no desktop breakpoint at all. Both layouts carry their own 200–300 line
inline `<style>` block. Tailwind 4 is installed but barely used; hand-written CSS
does the real work. There is no component layer to build a desktop layout on.

The copy is not the problem. "What we charge, before you ask" is a strong line.
The failure is structural.

---

## 2. Goals

1. One design system serving both surfaces — the public landing page and the
   closed trader app — at every width from 375px to 1440px and above.
2. Release the 720px mobile cap so a desktop gets a desktop layout.
3. Keep the tokens as the single source, so when the external designer delivers
   the final brand it is a token swap, not a rewrite.
4. Preserve every deliberate decision already encoded in the existing CSS.

## 3. Non-goals

- No brand change. The plum palette and Instrument Sans ship as they are today.
- No new marketing copy, no new product features, no route changes.
- No conversion of every existing view to utilities in this project.
- No JavaScript framework. The system is CSS-first; JS only where behaviour
  requires it.

---

## 4. Scope and decomposition

This is two projects, not one. Attempting all three at once produces a
half-built system and two half-converted surfaces.

| # | Project | Deliverable |
|---|---|---|
| 1 | **Foundation** (this spec) | Tokens, layout system, component library |
| 2 | Landing rebuild | Public page rebuilt on the foundation, desktop-native |
| 3 | Trader app rebuild | Closed app rebuilt on the foundation, desktop-native |

This spec covers project 1 only.

---

## 5. Token architecture

The existing single flat block of 25 lines is split into three layers. The split
is what makes a later brand swap cheap.

### 5.1 Layer 1 — Primitives

Raw values, taken from `brand/BRAND.md`. **Only the designer edits these.**

```
--plum-900  #331931
--plum-800  #462444
--plum-600  #5E2D5A
--plum-400  #743F6F
--plum-300  #8B6187
--bone      #FCF6EE
--paper     #FFFFFF
--green     #185B37
--red       #933331
```

`--plum-300` reaches AA rather than AAA and is for large text only, never body
copy.

**Known discrepancy — must be resolved, do not silently pick one.** The values
above are what ships in `partials/tokens.blade.php` today. `brand/BRAND.md`
records settled green as `#2F7D4F` and oxblood as `#822B2A`. The code and the
brand document disagree. This project builds on the **code**, because the brief
is to build on the current brand and the code is what is live. The brand
document should be corrected to match, or the code changed to match the brand —
that is an owner decision and is tracked in §12. Do not change either value
inside this project.

### 5.2 Layer 2 — Semantic

Meaning, not colour. Derived from primitives with `color-mix(in oklch, …)` so a
primitive swap propagates automatically.

| Token | Derivation | Meaning |
|---|---|---|
| `--ink` | `--plum-900` | Primary text |
| `--ink-mid` | plum-900 74% over bone | Secondary text |
| `--ink-soft` | plum-900 52% over bone | Tertiary text, captions |
| `--surface` | bone | Page background |
| `--surface-raised` | paper | Cards, raised surfaces |
| `--line` | plum-900 12% over bone | Dividers, card borders |
| `--focus` | `--plum-600` | Focus ring |
| `--action-primary` | `--plum-600` | Primary action |
| `--action-primary-hover` | `--plum-400` | Primary action hover |
| `--action-destructive` | `--red` | Cancel, decline, delete, dispute, failed |
| `--state-settled` | `--green` | Delivered, complete — **state only, never brand** |

### 5.3 Layer 3 — Component

What components consume. Kept separate so a component can change shape without
touching the palette.

```
--card-bg         var(--surface-raised)
--card-border     var(--line)
--card-radius     12px
--control-height  44px
--control-radius  10px
--tap-target      44px
```

### 5.4 Three scales (new — this is the desktop fix)

**Type scale.** Each entry is `font-size / line-height`. Both ship as tokens;
neither is inferred.

```
12 / 16 · 13 / 18 · 14 / 20 · 16 / 24 · 18 / 26 · 21 / 28
24 / 32 · 30 / 38 · 38 / 44 · 48 / 52 · 60 / 60
```

**Space scale.** Ten steps, used by every gap, pad and margin in the system.

```
4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96 · 128
```

**Container.**

```
--container-max   1120px at lg, 1200px at xl and above
--container-pad   16px below md, 24px at md, 32px at lg and above
```

### 5.5 Rules that do not change

1. **The data rule.** Plum is never on a number. Rates, balances and counts are
   always ink on surface. The brand colour is the frame; the data is never
   dressed in it.
2. **Never green for a primary action.** `--state-settled` is a state, never a
   brand colour and never a CTA.
3. **Destructive sits alone.** Oxblood and plum never appear in the same button
   group. Destructive actions sit in their own row, below a divider, or behind a
   confirm step.
4. **Third-party brand colours are the only exceptions.** WhatsApp green and
   Telegram blue appear only on those vendors' buttons, never on a KudiMarket
   action.

---

## 6. Layout system

This section is the fix for the reported problem.

### 6.1 Breakpoints

```
sm   640px
md   768px
lg   1024px
xl   1280px
2xl  1536px
```

### 6.2 Container behaviour

| Range | Width | Pad |
|---|---|---|
| below `md` | full width | `--space-4` (16px) each side |
| `md` – `lg` | full width | `--space-6` (24px) |
| `lg` – `xl` | centred, max 1120px | `--space-8` (32px) |
| `xl` and above | centred, max 1200px | `--space-8` (32px) |

Pad has exactly three steps — 16, 24, 32. There is no fourth value. A 40px pad
was drafted here and removed in self-review because it did not exist in the
space scale, which is the kind of drift this system is meant to prevent.

`--container-max` is defined per breakpoint explicitly (`1120px` at `lg`,
`1200px` at `xl` and above), not left implicit.

### 6.3 The rule

**No layout is capped below `lg`.** The current `main { max-width: 720px }`
becomes a mobile-only value and is released at `lg`. A phone layout stays a
phone layout; a desktop gets a desktop layout.

### 6.4 Grid primitives

Layout never relies on inline-flow siblings, which break when content is
reordered.

| Class | Behaviour |
|---|---|
| `.stack` | Vertical flow with `gap` from the space scale |
| `.grid-auto` | `repeat(auto-fit, minmax(min(100%, 260px), 1fr))` |
| `.split` | Two columns at `lg`, one below |

### 6.5 Preserved constraints

These are deliberate and must survive the migration:

- **Safe-area insets.** `env(safe-area-inset-*)` on header, page edges and the
  last control. Cost nothing on desktop, required on a notched phone.
- **Tabular lining figures.** `.num, output { font-variant-numeric: tabular-nums }`.
  A rate board with proportional digits looks like a typo.
- **16px minimum input font size** so iOS does not zoom the viewport on focus.
- **44×44px minimum tap target** on every interactive control.
- **Zero webfonts.** Instrument Sans with a `system-ui` fallback stack only.

---

## 7. Component inventory

Nine components, shared by both surfaces. Each has a mobile and a desktop
behaviour, and each has loading, empty and error states.

### 7.1 `Button`

Variants: `primary`, `ghost`, `destructive`. Minimum height 44px. Primary is
plum; destructive is oxblood and never sits beside a primary. Wraps to
full-width rows below 360px.

### 7.2 `Card`

Raised paper surface, 1px `--line` border. Filled variant `.ours` in plum with
inverted controls. Card stacks use `gap`, never `margin` on siblings.

### 7.3 `Field`

Label above, input, optional help text below. 44px control height. Error state
names the consequence, never a bare "required".

### 7.4 `RateRow`

The number component. Label above, value in tabular figures, ink not plum.

**Precision rule — this is a defect fix, not a preference.** Every rate renders
at a **fixed, stored precision**. Never a raw float.

- Direct rates (value ≥ 1): **2 decimal places.** `148.20`, never `148.2`.
- Inverse rates (value < 1): **6 decimal places.** `0.006757`, never
  `0.006756756756756756`.
- Trailing zeros are always printed. A rate never appears two ways on the same
  page.

Four decimal places was drafted here and rejected in self-review: it would
render `0.00675675…` as `0.0068`, which destroys the value. Six is the minimum
that keeps an inverse cedi/naira rate usable. The precision is stored with the
rate so a corridor can override the default without changing the component.

### 7.5 `StatusBadge`

States: open, locked, disputed, settled. Colour is state, never decoration.

### 7.6 `SectionHeader`

Eyebrow, title, optional lede. Desktop may place the lede in a second column.

### 7.7 `PhoneFrame`

Product screenshot container. Sized by breakpoint — this is what stops the
375px mockup from being a stamp on desktop. Below `lg` it may be decorative;
at `lg` and above it must be legible or it is removed.

### 7.8 `StatBand`

The "what we charge" band. A `grid-auto` band of labelled figures, tabular,
with a fixed precision per figure so the same number never appears two ways.

### 7.9 `Nav`

Mobile: single bar. Desktop: a navigation rail for the trader app, a header with
a real content width for the landing page.

---

## 8. Surface application

How each surface uses the foundation. Detailed surface specs belong to projects
2 and 3.

### 8.1 Landing page (project 2)

- Hero becomes a two-column split at `lg`: copy left, product right at ~320px
  instead of a stamp.
- The rate section becomes a `StatBand` with tabular figures and fixed precision.
- Product screens render at legible size at `lg` and above, or are removed.
- Content max-width 1120px, centred.

### 8.2 Trader app (project 3)

- Two columns at `lg`: a navigation rail, and a content column capped near
  880px so rates and trade rows still read.
- The rates board becomes a desktop table with tabular numerals.
- Trade detail becomes a split view.

---

## 9. Error handling and states

Every asynchronous surface defines four states: loading, empty, error, success.

Money errors name the amount and the consequence:

> "Cancel this transfer and return ₦1,742,000 to your balance?"

Never a bare "Are you sure?".

---

## 10. Migration

1. Move the two inline `<style>` blocks out of `layouts/marketing.blade.php` and
   `layouts/trader.blade.php` into real stylesheets served through Vite.
2. Keep `partials/tokens.blade.php` as the token source during migration, then
   replace it with the three-layer stylesheet once both layouts consume it.
3. Wire the semantic tokens into Tailwind's `@theme` so responsive utilities
   drive layout.
4. Existing views keep their hand-written component classes. Do not mass-convert
   to utilities in this project — that is churn with no payoff for a codebase
   this careful.
5. Extend `DesignTokensTest` to enforce the three layers.
6. Retain the `kudilint` palette check.

---

## 11. Testing

| Check | Method |
|---|---|
| No horizontal overflow | Measure `scrollWidth` vs `clientWidth` at 375, 768, 1024, 1440 |
| Tap targets | Assert every interactive control ≥ 44×44 at 375px |
| Token layers | `DesignTokensTest` extended to assert semantic tokens derive from primitives |
| Palette | `kudilint` against `BRAND.md` |
| Numerals | Assert `.num, output` carry `tabular-nums` |
| Precision | Assert no rate renders with more than 6 decimal places, and that inverse rates render exactly 6 |

---

## 12. Open items

- **Colour discrepancy between code and brand document.** `partials/tokens.blade.php`
  ships settled green `#185B37` and destructive red `#933331`.
  `brand/BRAND.md` records `#2F7D4F` and `#822B2A`. This project builds on the
  code values, because the code is what is live. The owner must decide which is
  authoritative and align the other. Not resolvable inside this project, and
  neither value may be changed here.
- The external designer may change the palette and the wordmark. Because
  primitives are the only values the designer edits, that lands as a token swap.
  If the designer changes the *number* of steps in a scale, the component layer
  must be revisited.
- Desktop density for the trader app is a product question, not a layout
  question, and is deferred to project 3.

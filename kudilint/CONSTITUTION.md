# KudiMarket Constitution

Machine-enforced by `kudilint`. Edit `kudi.config.json`, never a rule in prose.

---

## Why this document exists

**"Be less generic" is not a rule.** It has no failure condition, so it cannot
fail a build, so it cannot survive a deadline with two other things on fire.

Every rule here can fail. That is the entire design.

Each one traces to a specific failure — usually one of ours, caught in this
project. Where a rule exists because we got it wrong, that is stated.

---

## The one-line version

> Measure, don't assume. Sample, don't remember. Show, don't describe.
> One colour, one job. One number, one meaning.

---

## I. The brand, fixed

Not negotiable. Everything else serves this.

| | |
|---|---|
| **Name** | KudiMarket |
| **Domain** | kudimarket.com |
| **Tagline** | Your market. Your rate. |
| **Typeface** | Instrument Sans — locked, not merely preferred |
| **Brand colour** | Plum Market `#5E2D5A` — oklch(0.380 0.096 330) |
| **Wordmark** | `kudi` light in Plum Deep / `market` at 800 in Plum Market |

### Type

**Instrument Sans, and only Instrument Sans.** Three things make this locked rather than preferred:

1. `brand.approvedTypeface` in `kudi.config.json` is enforced by `kudilint.mjs`, which rewrites any
   banned family to it. Twenty families are banned, including the three LLM defaults (Inter, Roboto,
   Arial) and the 2020-23 trend faces.
2. **Licence verified 7 Oct 2026: SIL Open Font License 1.1.** Designed by Rodrigo Fuenzalida with
   direction from Jordan Egstad, open-sourced 2023. OFL lets us use, embed, self-host and bundle it
   commercially and modify it. The only limits are that we cannot sell the font on its own, and a
   derivative cannot reuse the reserved names. So there is no licence event that can unseat this type.
3. It was chosen on its numerals and its status strings — a rate, a falling rate, a settled status —
   not on a headline. That is the test that keeps it.

**The honest caveat, recorded rather than forgotten:** Instrument Sans was designed *for* Instrument,
a software company. It is not a face we commissioned, so it is not exclusive to us. It is free,
distinctive enough to read as ours, and nobody in Ghanaian or Nigerian fintech is running with it
that we know of — but that last claim is a sampling judgement, not a measurement, because the
competitor sites would not resolve for automated checking. If a competitor ever ships it, the answer
is to modify it, which OFL permits, not to panic.

### The three-state colour law
```
PLUM   = PENDING / ACTION      A choice, not a verdict
GREEN  = CONFIRMATION ONLY     Never a button. Ever.
RED    = LOSS / DESTRUCTIVE    Cancel, decline, failed, closed
```

**A colour has exactly one job. Two jobs kills it.**

This law is not stylistic. It exists because oxblood-as-brand meant red was both
*the brand* and *your loss*, and a falling rate read as the product bleeding.
Green is a confirmation state rather than a button because every green that holds
white text is within 0.16–0.67 OKLab units of a Nigerian bank. As a panel it
reads as system feedback. As a button it reads as a bank.

---

## II. Colour

### R1 · Competitor colours are banned
`no-competitor-colour` — **error**

Binance `#F0B90B` · KuCoin `#01BC8D` · CoinGate `#827EF7` · Paxful `#00A5EF` ·
Union Bank `#00694B` · Claude `#D97757` — and 14 more, in the config.

**Why:** we shipped deep pine `#1E4E2E`. It looked good in a swatch and sat
**0.09 OKLab units** from Union Bank — the same colour to the eye. A competitor
or a bank, not a brand.

### R2 · Crowded hue bands are forbidden
`forbidden-hue` — **error**

Gold 78–100 · teal 158–178 · cyan 195–225 · blue 240–275 · violet 276–300 ·
telco red 20–32.

**Why:** hue is the crowded axis. Green appears 11 times across the 38 measured
brands, blue 8. Depth is open. Hue is not.

### R3 · Every colour is a token
`hardcoded-hex` — **warn**

A hex outside `approvedTokens.colors` is a colour nobody measured. Adding one
requires a recorded WCAG ratio.

### R4 · Contrast is measured, never eyeballed
`contrast-required` — **error**

4.5:1 body · 3:1 large and UI components. Run `node kudilint.mjs --audit`.

**Known and documented:** `plum-light` is 2.92:1 and `loss-fill` is 4.30:1. Both
are **fills and indicators only**, never text. `settled-btn` is 0.16 from
Bitstamp — permitted **because it is never a button**. These are decisions on the
record, not accidents.

---

## III. Type

### R5 · One typeface
`banned-font` — **error**

Bricolage Grotesque · Inter · Roboto · Fraunces · Poppins · and 14 more.

**Why:** Bricolage was flagged as a 2021 trend face in our own audit and then
shipped anyway. It was described back to us as "the worst font in market systems
history," which was correct.

### R6 · Judge type on numerals, never on headlines
*Process rule, not machine-checkable — hold yourself to it.*

Every typeface in `type.html` is rendered against the same three strings a money
app must handle: a rate, a falling rate, a settled status. A face can look
beautiful in a hero and fall apart in `₦1,742,000 ▼ 3.1%`.

### R7 · Currency is tabular
`tabular-figures-required` — **error**

Proportional numerals make a column of naira figures jitter as they update.

### R8 · 14px floor
`min-body-size` — **error**

Most of this market reads on budget Android panels in daylight.

---

## IV. Layout

### R9 · No three equal columns
`no-three-equal-columns` — **error**

The single most recognisable AI layout signature. Asymmetry carries hierarchy.

### R10 · Not everything is centred
`no-uniform-center` — **error**

Centred text has no left edge for the eye to return to.

### R11 · No accent quote blocks
`no-accent-quote-block` — **error**

`border-left: 3px` plus a tinted background. It appears in nearly every
generated response, which is exactly why it reads as one.

### R12 · Gradients are not identity
`no-gradient-as-brand` — **error**

A gradient as a brand means there was no brand. Depth comes from contrast and
space.

### R13 · Rhythm needs three steps
`no-single-spacing-value` — **warn**

One gap everywhere reads as unconsidered. Scale: 4 / 8 / 16 / 32 / 64.

### R14 · No emoji as interface
`emoji-as-icon` — **error**

Genuine pictographs only. Check marks, crosses and triangles used as status
markers are legitimate UI and are not flagged.

---

## V. Language

### R15 · No filler
`filler-copy` — **error**

Seamless · effortless · empower · unlock · robust · elevate · delightful ·
best-in-class and 35 more.

**Words carrying zero information are the clearest signal of machine authorship.**

### R16 · No invented data
`placeholder-data` — **error**

Lorem · John Doe · TODO · "Trusted by 10,000+".

**A demo number becomes a real number somebody relies on.**

### R17 · A name needs a positive reason
`no-availability-as-merit` — **error**

This is the rule that produced the current name, and it is the one we broke most.
150 candidates were checked, nearly all taken. KudiMarket won because it was
**available** — not because it was best. It is a decent name, not a proven one.

If you propose a replacement, it needs a reason that survives the domain being
available. This is exactly the bar the tagline currently fails, which is why the
tagline is the weakest asset we own.

---

## V-bis. The screens themselves

`product-preview/index.html` is the reference surface for the trader-facing app: the seller's
position, setting an offer, accepting a request, waiting then releasing; and the buyer's board, the
opened trade, held-and-waiting, and raising a dispute. It is the thing an implementation is measured
against, so it is linted with the same rules as everything else.

Four rules came out of drawing it, and none of them are linter-enforceable, which is exactly why they
need writing down:

- **A locked control states its unlock condition.** "Release to Ama" with the time printed under it,
  not a greyed button and silence. A disabled control with no reason is where trust quietly leaks,
  because the user assumes a bug.
- **Green is only ever what we hold.** The held screen says plainly that we confirmed the cedi
  arrived and a human attested it, and that we cannot vouch for naira we have not yet received. If a
  future screen needs a reassuring green, it must be earned by something we actually hold.
- **The two tiers are stated as consequences, not badges.** *Handled* says the money comes back and
  it is our loss, not a claim you have to win. *Checked* says the desk still decides but settlement
  is not guaranteed. The badge is the smallest part of the sentence.
- **A dispute form warns that the text is permanent**, visible to both sides and to a regulator, and
  must not contain a MoMo PIN. It is a legal record wearing a form.

---

## VI. Process

### R18 · Measure, don't assert
`measure-dont-assert` — **error**

Every colour claim in this project carries a computed OKLab or WCAG value. That
is why we know pine collided with Union Bank and Claude's `#D97757` fails white
text at 3.12:1. Neither was guessable by eye.

### R19 · Sample competitors, never recall them
`sample-dont-assume` — **error**

Read competitor CSS from live DOM. Memory is not a measurement, and the four
competitor hexes in this project were sampled in-browser for that reason.

### R20 · Verify before claiming
*Verification rule.*

Every deliverable in this project was rendered and inspected before it was
described. This caught a stale hex in a wordmark label, a proof table
contradicting its own prose, a nav class that never matched its CSS, and a
confirmation panel that silently rendered unstyled because the class it used was
never defined in that file.

**None of those were visible from the source. All of them were visible from the
render.**

---

## VII. Enforcement

```bash
npm run lint:design                      # the whole repo — the one that matters
node kudilint/kudilint.mjs src app       # lint a tree — exits 1 on error
node kudilint/kudilint.mjs --audit       # palette contrast + collision audit
node kudilint/kudilint.mjs --explain R5  # why a rule exists, and what it replaced
```

Wired into `.husky/pre-commit` and `.github/workflows/design-gate.yml`.

**Verified failing on 7 Oct 2026**, because a rule that cannot fail is decoration. Each of these was
introduced into `product-preview/index.html`, caught, and reverted:

| Introduced | Caught as |
|---|---|
| `font-family: Inter` | error — banned typeface |
| `--plum-600: #964D6F` | error — retired plum, hue 352 reads pink |
| one `font-size: 9px` | error — below the 14px floor |
| `--plum-400: #6E9F5B` | warning — a colour nobody has measured |

That last row is why the ratio gate on the 14px rule was removed the same day. It fired only when
more than 35% of type was too small, so a third of the product could have been 9px and the build
stayed green. A floor with an exemption threshold is a suggestion.

**Adding a rule:** add to `kudi.config.json`, add the reasoning to this file, and
confirm it fails on something real. A rule that never fires is documentation, not
a rule.

---

## What this constitution cannot catch

Stated plainly, because a constitution that pretends to be total is worse than
one that admits its edges:

- **Whether the brand is any good.** A linter cannot tell you KudiMarket is a
  strong name. It can only stop you shipping a known-bad one.
- **Whether a layout is right for the user's task.** It bans three equal columns.
  It cannot tell you the hierarchy is correct.
- **Whether a decision is honest.** The tagline is measurable and still weak. The
  rules can flag `seamless`; they cannot flag a line that is technically specific
  and still says nothing.
- **Taste.** The final call is still a person arguing with another person. That
  is not a defect in the system. That is the system.
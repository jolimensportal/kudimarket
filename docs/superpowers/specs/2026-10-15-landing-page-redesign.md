# KudiMarket Landing Page — Desktop Redesign

**Date:** 15 October 2026
**Status:** Approved. Design chosen visually (option D); copy decisions confirmed.
**Scope:** The public landing page only. The trader app is project 3.

---

## 1. What is wrong with the current page

Three content defects, found while diagnosing the desktop layout:

1. **It proves the wrong product.** Every screenshot says "Sell to us", "Buy from
   us", "Your trades", "We are the other side of this trade". That is a P2P
   exchange photographed inside a remittance page. No caption repairs the
   contradiction, because it is visible in the image.
2. **The promise block is escrow.** "Escrow you can see", "Your cedi sits in an
   account in your own name". `BRAND.md` §0 retires escrow explicitly: the
   business moves money, it does not hold it on a customer's behalf.
3. **Three dead anchors.** The doors point at `#send`, `#earn` and `#trust`.
   No element has those ids.

And the layout defect this project exists to fix: at 1440px the content is one
tall column with dead space, because the page was designed at 390px and widened.

## 2. Decisions made with the owner

| Decision | Choice |
|---|---|
| Which product the page is for | **Remittance only.** The exchange framing is cut. |
| Structure | **Option D** — A's section order, no screenshots. |
| Proof | The **live rate card**, read from the ledger, replaces the phone screenshots. |
| Timing promise | **"Same day", not "in minutes".** Minutes cannot be guaranteed yet. |
| Worked example | **100 GHS → 14,820 NGN.** A normal family remittance. |

## 3. Why the rate card is the proof

The old page argued for itself with a photograph. The photograph was of the
wrong product. The rate card is better on every axis:

- It is the claim the business actually makes: *your rate is the rate you get*.
- It is live, read from `rate_card`, so it cannot be stale — which was already
  the stated reason for showing it.
- It is honest. A screenshot of a screen that does not exist yet would not be.
- It removes the need to caption around an image that contradicts the copy.

**Cost of this choice, stated plainly:** the page shows no screens of the
application at all. "Show me it works" is the strongest objection on a money
product, and the rate answers it with a number rather than an interface. That
argument weakens over time and should be revisited the moment a send screen
exists. It is recorded here so the omission is a decision, not an oversight.

## 4. Section order

1. **Header** — wordmark only.
2. **Hero** (7/5 at `lg`) — status, tagline, lead, doors, email form;
   **live rate card** on the right.
3. **What it costs, before you ask** — a worked example at 100 GHS, not a rate
   table. Sender wants to see their own transaction, not three abstract rates.
4. **What we promise, and how you could check it** — three promises, three
   columns at `lg`.
5. **Footer split** — "What we do with your address" beside the second ask.

The trade-history screenshot is dropped: an empty state screen used as proof is
self-defeating.

## 5. Copy

**Locked, unchanged:** "Your market. Your rate."

**Lead:** You send your side, we send ours. Nothing waits on a stranger to turn
up, because we are the other side.

**Doors** (now pointing at real anchors):

| # | Text | Target |
|---|---|---|
| 01 | I am sending money | `#cost` |
| 02 | I want to know what it costs first | `#cost` |
| 03 | I do not trust apps that took my money | `#promise` |

**Rate card:** "Today's rate" · "Live from the ledger" · `GHS → NGN` ·
`148.20` · "1 cedi is 148.20 naira" · And back `0.006757` · Our fee `1.25%` ·
"Every rate we have ever shown stays on file. Nothing here is edited in place."

**Cost band:** "You send 100.00 GHS" · "They receive 14,820.00 NGN" ·
"Our fee 1.25% — 125 bps, charged on the send" · "Worked example at today's
rate. Yours is priced at the rate you are shown, not a rate that moves while
you look."

**The three promises:**

1. **Cash in hand, the same day.** The recipient is holding it, not watching a
   progress bar. You confirm when they have it.
2. **Nobody touches your money until you say so.** Operations cannot confirm a
   delivery on your behalf, and neither can we.
3. **The rate you agreed is the rate you get.** We quote our rate and you see it
   before you commit. The transfer is priced at that number.

**Removed entirely:** every mention of escrow, offers, trading, buying from us,
selling to us, and the door about earning on your money.

## 6. Banned words

Unchanged from `BRAND.md`: seamless, revolutionary, cutting-edge, world-class,
bank-grade, best-in-class. Plus, for this page: **escrow, trade, trading,
offer**. The banned-word test already exists and must keep passing.

## 7. Constraints that carry over

Unchanged and still enforced: 44px tap targets, tabular figures on every number,
16px inputs, safe-area insets, no invented numbers, no testimonials, the
"Not open yet" status, the licensing line stated once, WhatsApp/Telegram as the
only third-party brand colours, no soft-launch date until instructed.

## 8. Tests

`LandingPageTest` has three assertions that must change because the copy changed:

| Test | Currently asserts | Becomes |
|---|---|---|
| `test_it_makes_three_promises_and_every_one_is_mechanically_true` | "Escrow you can see." | "Cash in hand, the same day." |
| `test_the_page_shows_a_live_rate` | `150.5` | `150.50` — two decimals, `RateFormatter` |
| `test_the_fee_is_quoted_in_basis_points_not_only_as_a_percentage` | passes | passes |

The banned-word test is extended with escrow/trade/offer and must still pass.

New assertions: the doors resolve to anchors that exist; the worked example
amounts are derived from the live rate rather than hard-coded.

## 9. What this does not do

- No change to routes, controllers, the waitlist, or the referral mechanic.
- No change to the trader app.
- No new imagery. `board.png`, `buy.png` and `history.png` stay in the repo and
  stop being referenced by the landing page; they are used in the trader app's
  own documentation.
- Nothing is deployed without an explicit instruction.
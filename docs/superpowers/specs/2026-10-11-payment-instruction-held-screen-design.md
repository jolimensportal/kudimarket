# The Held screen: what the money is doing after a claim

Date: 2026-10-11
Item: Bucket 2, item 7, part 2. Part 1 (the payment reference) shipped in `3cba6e0`.

## What this is

The mockup's step 7, "Held, and waiting on the window": after a buyer claims, the trade screen says
nothing about the two legs or about what KudiMarket can and cannot tell them. It says one sentence
("We have your claim and are checking for the payment") and then the event list.

Item 7 exists to replace the phone call. A trader who has just sent their cedi and is staring at this
page is exactly the person who phones the desk to ask *is my money there? when do I get the naira? is
this real?* The screen has to answer those three questions without the phone.

## The three questions, and the honest answer to each

1. **Has my money arrived?** Only somebody who did not pay can say. At `paid_claimed` the answer is
   nobody knows yet. At `paid_attested` the answer is yes, and who said so depends on the tier: on our
   own book it is a member of operations who saw it reach KudiMarket; on a vendor trade it is the
   seller, who is the recipient and therefore the only party entitled to the claim.
2. **When do I get the other currency?** When the claim window closes, after which the sweep releases
   it. Nobody has to press anything.
3. **Is this money real?** The leg we can see is real to the extent one human's confirmation is real.
   The leg on the other end, in somebody else's account, is not real to us until it moves, and saying
   otherwise is the consumer-protection failure this product exists to avoid.

## The design

One status card and one straight-answer card in `app/resources/views/trade/show.blade.php`, rendered
only in the two waiting states — `paid_claimed` and `paid_attested`. Not at `open` (nothing has been
sent), not at `disputed` (release is not pending on a window while a dispute is open; saying "waiting
on the window" there would be a different lie), and not after release.

### Card 1 — "Your money"

Two rows, one per leg, named from the figures the controller already passed in. The amounts are the
same `$figures` strings the top of the page renders. Nothing is recalculated in the template.

| State | You pay (send leg) | You receive (receive leg) |
|---|---|---|
| `paid_claimed` | **Checking** — we have your claim; a human has not confirmed it | **Waiting on the window** |
| `paid_attested` | **Received** — named confirmer below | **Waiting on the window** |

Who the confirmer is named, because it is the whole difference between the tiers:

- handled (we are the counterparty): *a human confirmed it* — operations, recorded against a staff
  identity, because our money is at risk and delivery is ours.
- checked, vendor buyer: *the seller confirmed it* — the seller is the recipient and the attestation
  is theirs to make. Not us. Calling it our confirmation would put us behind every peer-to-peer trade.
- checked, vendor seller: *you confirmed it* — the seller attests their own receipt, and the ledger
  records it against them.

Under the rows, the release fact, stated once:

> The {receiveName} is released once the claim window closes. No one has to press anything.

### Card 2 — "Straight answer"

State-aware, one paragraph, no colour carrying the meaning.

- `paid_claimed`, either tier: *We have your claim and a person is checking for the payment. We will
  only call it received when somebody has seen it.*
- `paid_attested`, handled: *We can tell you the {sendName} arrived and that a human confirmed it. We
  cannot tell you anything about the {receiveName} we have not yet received.*
- `paid_attested`, vendor: *We can tell you the {sendName} arrived and the seller confirmed it. We
  cannot tell you anything about the {receiveName} until it reaches you.*

## What this deliberately does not do

**No countdown.** The mockup shows `15:52 / Left to dispute. Your naira is not released to Adwoa until
this reaches zero.` Two separate errors. The window gates *release*, not disputes — `paid_claimed →
disputed` and `paid_attested → disputed` carry no time limit, and a counterparty who has not been paid
is exactly who needs to be able to complain. And a ticking number goes stale on a metered connection
and names an instant the sweep cannot promise, because it runs on a one-minute boundary. The release
fact is stated in words instead.

**No new data.** Everything rendered is either `$figures` (computed in the controller) or the trade's
state. No migration, no new route, no new table.

**No attestation control on the trader screen.** Confirming that somebody else's money arrived is the
one thing a payer may never do.

## The comment that was wrong

`trade/show.blade.php` carried this reasoning for refusing automatic-settlement copy:

> `paid_attested -> released has actor 'system', and there is no scheduler registered, so nothing
> settles on its own. It only becomes true when the release job exists.`

The release job existed. `4c6ed04` (Oct 9) added `release_due_trades()`, `trades:release-due` and the
`everyMinute()` schedule; `70a78f2` (Oct 11) added the comment and is a descendant of it. The refusal
was correct when first written and was never revisited after the sweep landed. The comment is being
replaced with the truth, and the honesty principle it was reaching for is preserved: the copy still
does not name an instant, and `TradeScreenTest::test_it_never_says_a_trade_settles_by_itself` still
fails on that phrase.
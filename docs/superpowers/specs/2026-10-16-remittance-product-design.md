# Remittance product — design

Build the thing the landing page now advertises: a sender in Ghana pays cedi, a
named beneficiary in Nigeria receives naira, the same day, in cash.

## What already exists, and what is missing

**A remittance send is structurally a `platform_inventory` trade, and that type
already works.** `platform_inventory` is the trader buying currency from us: the
trader is the buyer, declares the payment, ops confirms it arrived, the system
settles. That is the remittance loop with the middle step relabelled.

Three things are missing.

**1. A beneficiary.** `trade` has `seller` and `buyer` as emails. A remittance
sends to a named person with a phone number, and that person is the reason for
the transaction. There is nowhere to put them.

**2. A payout step.** The current chain goes `paid_attested → released` and the
system settles. For a P2P exchange that is correct: the buyer collects their cash
from the seller directly and no physical money crosses our hands. For remittance
the delivery *is* the product. Ops must hand over cash, and that handover must be
a recorded, attributable step before anything settles.

**3. The float decrement and the physical handover are the same event — and
nothing currently keeps them that way.**

Measured, not assumed. A `platform_inventory` settlement of NGN 1,482,000:

```
platform NGN float : before=-21703000  after-attest=-21703000  after-release=-23185000
trader    NGN bal  : before=0           after-release=0
```

Two things follow.

The float moves at **`released`**, not at attestation. The settlement posting is
`platform external -= delivered`. There is no separate trader's balance to
inspect afterwards; the entire movement is on the platform's own account.

And that posting **is** the record of the notes leaving the till. So no new
posting is needed, and none should be added — a separate
`buyer_available → external` leg on top of it would double-count the outflow and
quietly corrupt every float balance in the ledger.

Which leaves the whole product resting on one invariant:

> The naira may only leave the platform's float account when the naira has
> actually left the till.

Nothing enforces that today. `released` is reachable by a background job, so the
books would record the delivery, the automated integrity checks would pass —
the postings balance — and the notes would still be in the drawer. That failure
is worse than a crash: it is invisible to `verify_chain` and `check_balances`,
and it errs in the direction of understating our liability to customers.

## The sweeper, and a claim that turned out to be wrong

`release_due_trades()` is a background job. It finds every trade sitting in
`paid_attested` past its claim window and attempts to release it:

```sql
where e.to_state = 'paid_attested'
  and x.state = 'paid_attested'
  and v_now >= e.created_at + pol.claim_window
```

It does not ask the trade type whether `paid_attested → released` is a legal
move.

That looked, immediately, like the whole design. The reasoning was: add
`paid_out` as a gate after `paid_attested`, and an unattended job will step
straight over it — the window lapses overnight, the sweeper releases, the float
falls by the delivered amount, the beneficiary holds nothing, and `verify_chain`
certifies it because the postings really do balance.

**It does not work like that, and the first honest test disproved it.**
`_apply_transition` looks the move up in `trade_rule` and raises
`illegal transition` when no rule exists. `remittance` deliberately has no
`paid_attested → released` rule, so the attempt is refused before any state or
posting can change. Measured against the unmodified sweeper:

```
released / already_settled = 0 / 1
send state : paid_attested -> paid_attested
NGN float  : 1482000       -> 1482000
```

The state machine was already doing the work. No float could be drained by the
sweeper, and the alarmist version of this spec was fiction.

### Why that matters more than the bug it replaced

The first version of the test asserted the send was still in `paid_attested`
and the float had not moved. **It passed against the unmodified sweeper.** It
could not fail. A test that cannot fail is worse than no test, because it reads
as coverage and it was written specifically to catch this — so it would have
been trusted, in a file whose whole purpose is being trusted about money.

So the test now asserts the thing that actually changes:

```sql
select already_settled from release_due_trades();   -- expect 0
```

Without the constraint the sweeper attempts the move, `_apply_transition`
throws, and the throw is counted as a settled trade. Every unpaid send sitting
past its window inflated that counter indefinitely.

### What the fix is worth

Not money. `already_settled` is the only instrument this codebase has for
telling an operator whether an unattended job is healthy, and it was reporting
correctly-waiting sends as failures. That is a real bug — a silently wrong
instrument — and it was invisible precisely *because* the system was otherwise
correct. The sweep is now constrained to types that carry the rule, which also
means any future type needing a human step is safe by construction.

The three existing types are unaffected: all three carry the rule.

## Design

### A new trade type, not a reused one

`remittance`, with one extra state:

```
open → paid_claimed → paid_attested → paid_out → released
                buyer      ops           ops      system
```

The actor column is the whole point:

- `open → paid_claimed` is the **buyer**, which is the sender. The person who
  paid is the only one who may declare it.
- `paid_claimed → paid_attested` is **ops**, not `seller`. We are the recipient
  of the cedi, so only we confirm it arrived. Same invariant as `018`, stated
  for the type where the trader sits on the paying side.
- `paid_attested → paid_out` is **ops**. They delivered the cash and recorded it.
- `paid_out → released` is **system**. Settlement, now inseparable from payout.

Cancels and disputes match the other types.

### No new posting — the existing settlement is the cash-out

There is deliberately no extra ledger leg for the payout. The
`platform external -= delivered` movement that settlement already makes **is**
the record of the notes leaving, and it happens at `paid_out → released`, which
is gated on a human having recorded the handover.

The design constraint is therefore the gate, not the posting. That is the
opposite of the usual instinct, and it is why the `paid_out` state exists at all
rather than being tidied away.

This also settles the netting question. There is no credit-then-debit pair,
therefore no interval in which the obligation is discharged while the money is
still in the building. One event, one movement.

### The payout is a real record, not a state name

`remittance_send` — one row per trade, holding what only a send needs:

```sql
create table remittance_send (
  trade_id            bigint primary key references trade(id),
  beneficiary_name    text not null check (length(beneficiary_name) between 2 and 120),
  beneficiary_phone   text not null check (beneficiary_phone ~ '^\+?[0-9]{7,15}$'),
  corridor            text not null,
  delivered_amount    numeric(78,0),        -- null until paid_out
  delivered_reference text,                 -- null until paid_out
  delivered_by        text,                 -- ops email; null until paid_out
  delivered_at        timestamptz           -- null until paid_out
);
```

The three delivery columns are null until `paid_out` and the function that
performs the transition is the only thing that may set them. `trade.state`
remains the authority; this table is the evidence behind it.

## What v1 does not do

- **No automated payout.** Manual delivery recorded by ops. The physical
  mechanism is unresolved and the licence position depends on it, so automating
  it would harden an undecided design into the ledger.
- **No beneficiary accounts.** The beneficiary is a recipient of physical cash,
  not a Kudi account holder. There is nothing for them to log into.
- **No SMS.** Delivery confirmation is ops' record. Messaging is a later layer.

## Build order

1. Widen `trade.state` and `trade_transition` for `paid_out`.
2. Add the `remittance` policy and its rules.
3. Constrain the sweeper to types that permit `paid_attested → released`, so
   `already_settled` stops counting correctly-waiting sends as failures. The
   test asserts the counter and fails against the unmodified sweeper.
4. `remittance_send` table, plus the core function that creates a send.
5. Ops transition to `paid_out`, which writes the delivery columns and refuses
   to run without them.
6. Tests for every transition, every actor, and the float movement.
7. Laravel: controller, resources, views, routes.

## Open decisions for the user

- Ops email or agent id on the delivery record? Currently `delivered_by` is text.
- Should a send be cancellable after attestation? Currently no — once we hold the
  cedi, the only exits are delivery or a dispute.
# An explicit commission, charged in the open

Date: 2026-10-11
Item: Bucket 2, item 7, third piece. The mockup's "KudiMarket fee ₵0.00" row (product-preview/index.html:490).

## Why a fee row at all

A trader reading a rate needs to know whether the number they were shown already contains our margin or
whether money is taken on top. The mockup answers that by itemising it. Item 7 exists to stop the phone
call, and "what is your fee" is the first question of it.

The row could not be built before, because there was nothing behind it. No fee anywhere: the `fee`
balance kind was reserved in `001_core.sql:25` and never posted to; there was no spread, commission or
margin concept in the ledger; a trade was one amount plus a held rate, and the rate was the whole price.

## What a commission changes about the law

Taking a margin inside the rate is a **spread**. Charging a separate, itemised amount is a
**commission**, and `brand/REGULATORY.md:248` records why the distinction is not cosmetic — the BoG FX
Broker conditions require a broker to *"operate on commission basis and should not include their
commissions as spreads in pricing trades"*. The mode we pick here is a statement made to the regulator
and to every trader who reads the row, so it is recorded here rather than inferred from a number.

This does not settle the dealer-vs-broker fork in `REGULATORY.md` §5b, which needs Ghanaian counsel and
blocks launch rather than building. It is compatible with both routes: a dealer may charge commission,
and a broker is required to.

## The design

### Frozen at creation, like the rate

A trade's rate is held, and the fee has to be frozen the same way: a trader who agreed a price cannot
be charged a different one at settlement. So the fee becomes columns on the trade, written at creation
and hashed into the chain.

    trade_policy.fee_bps            -- the current setting per trade type
    trade.fee_bps                   -- frozen at creation
    trade.fee_bearer                -- 'buyer' | 'kudimarket'
    trade.fee_minor_units           -- frozen at creation
    trade.fee_asset                 -- frozen at creation

Both the ledger posting and the trader's screen read the **stored** column. This is not tidiness: the
rate direction was once discarded by the creation paths and `011_rate_direction.sql` records that the
result told a real buyer to send ₦500,000's worth of the wrong currency, through 286 passing tests. A
computed-on-read fee would be the same class of bug waiting to happen.

### Basis: bps of what the buyer sends

`fee_minor_units = div(base_minor × fee_bps, 10000)`, in the base asset — the currency the buyer
actually hands over. One basis, so the fee line on the payment instruction is in the same currency as
the "You send" figure above it. The alternative (a slice of the receive amount) charges the buyer in a
different currency than they pay, which is harder to explain and harder to reconcile.

Integer minor units throughout. No floats: the codebase's rule, and `div()` keeps it.

### Who pays

An admin sets `fee_bearer` when the trade is created, from the default in `trade_policy`. It cannot be
changed afterwards for the same reason the rate cannot.

`buyer` — the fee is added to what the buyer sends. This is a real charge: the trader pays more than
the quoted conversion.

`kudimarket` — the fee is absorbed out of our own margin. The buyer pays exactly the quoted amount and
receives exactly the quoted amount; the posting splits what we receive into commission income and
trading margin. The trader's experience is identical to a no-fee world. This is the honest form of "the
seller receives": on our own book KudiMarket *is* the seller and collects the commission either way, so
the real question is never who receives it but **who pays for it**.

### Posting, on release, our book only

`platform_inventory` settles today as a four-legged split between `platform` and `chain` external
accounts (`004_state_chain.sql:284-298`). The commission is a fifth posting in the base asset.

    buyer pays:
        chain.base        -(base + fee)
        platform.base     +base
        platform.fee      +fee
        platform.quote    -fiat
        chain.quote       +fiat

    kudimarket absorbs:
        chain.base        -base
        platform.base     +(base - fee)
        platform.fee      +fee
        platform.quote    -fiat
        chain.quote       +fiat

Both sum to zero per asset, which is what `check_balances()` and the value-conservation test require.
The commission is taken **on release**, not on claim: the money is not ours until the trade settles, and
taking it earlier would mean taking it from a trade that might still be refunded.

### Not charged on Checked trades

A `vendor_vendor` trade posts nothing — the money goes trader to trader without touching us
(`004_state_chain.sql:280-283`, which says so in terms). There is no leg for a fee to move along, and
posting one would invent a debt between two people we do not owe. The fee stays zero there.

**The counterparty row stays omitted.** The mockup has "Counterparty fee ₦0" at line 382. We cannot
print it: the seller sets a walk-away rate that may embed any margin, we never see it, and asserting
zero would be inventing a fact about a third party. That is the same failure as telling a buyer to
"use the reference we gave you" when no reference existed.

## Default

`fee_bps = 0` everywhere until the pricing desk sets a number. The mechanism ships correct and
charging nothing, so the ₵0.00 row becomes a fact the ledger enforces rather than a string the view
prints.

## Left open, deliberately

- **Whether the commission is itself a reportable item.** `016_cdd.sql` assesses the CTR on
  `fiat_amount`, which a fee does not change. Whether commission revenue is separately reportable is a
  regulatory question for counsel. It does not block building the mechanism.
- **What the rate actually is.** The two directions of a pair are set independently and nothing
  reconciles them: the fixtures hold GHS→NGN at 148.2 and NGN→GHS at 0.0067567, and 1/148.2 is
  0.0067456, so a trader can round-trip for a small gain. Commission makes the pricing model explicit,
  which is most of the fix, but the cross-rate consistency guard is separate work.
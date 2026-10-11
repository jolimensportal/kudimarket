# KudiMarket — Regulatory Position

**Status:** research brief, not legal advice. Written 10 Oct 2026 from primary sources.
**Owner action required:** Ghanaian financial-services counsel must confirm §§1–3 before launch.
**Supersedes:** nothing. `brand/product.html` still carries an open licensing question that this
brief now answers more accurately — see §5.

---

## 0. What KudiMarket actually does today

Written first because it is what counsel will ask for, and because everything below is an argument about
these six rows. Every line is drawn from the shipped code, not from the product description — where the
two differ, that is noted, because a difference is usually the interesting part.

| # | What the product does | Enforced at | Tier |
|---|---|---|---|
| 1 | A trader publishes a **sell offer**: "Naira, at not less than *x* cedi per naira" | `009` `publish_offer` | — |
| 2 | A **checked** trader takes that offer, opening a trade with a checked trader as counterparty | `009` `accept_offer` | **Checked** — we are *not* a party |
| 3 | Both sides post a **10% deposit in cedi**, escrowed to that trade | `004` `_apply_transition` | Checked |
| 4 | The buyer says they paid the naira; **the seller** confirms the naira arrived; deposits return | `004` `paid_claimed → paid_attested → released` | Checked |
| 5 | A trader **buys currency from KudiMarket's own book**, cedi against naira, **both directions** | `TradeDesk::openAgainstUs` | **KudiMarket handled** — we *are* the counterparty |
| 6 | Operations may resolve a dispute and decide where the money goes | `TradeLedger::resolve` | Both |

**Two facts that decide how this is read, and both are deliberate:**

- **Row 5 is us buying and selling currency out of our own float.** That is Act 723 s.3(4)(a),
  "purchase and sale of foreign currency", in plain words.
- **Row 2 is us not being a party.** The Checked tier exists precisely so that a peer-to-peer trade is
  not one we guarantee. `009` refuses to let an unchecked trader stand on the vendor board, and
  `004` refuses to let operations attest a trade we are not party to.

### What we do *not* do, as built

- **We do not touch virtual assets in the shipped market.** The USDT leg exists in the core and in the
  test suite, and no trader-facing screen reaches it. `brand/product.html` records the position itself:
  *"nothing exotic is in our book"*, cedi and naira both move by mobile money, and *"Dollars, pounds,
  euros, the CFA franc and the Chinese yuan are all vendor supply from day one, and we never hold
  them."* `brand/BRAND.md` builds the name's recall on *"Zero crypto vocabulary."*
- **We do not take deposits from the public.** No trader funds an account with us in the manner of a
  deposit-taking institution. `ledger_deposit()` is a test and float-seeding function, granted to
  `kudi_chain` alone — the suite asserts that *operations cannot mint deposits* and that a trader cannot.
- **We hold no client wallet.** `brand/product.html` answers the crypto question *"Our wallets, a fresh
  address per trade"* — and that is the line this brief cannot verify, because it describes a capability
  the shipped code does not have.

### The activity map counsel needs

| Statutory activity | Where KudiMarket sits | Rows |
|---|---|---|
| Act 723 s.3(4)(a) purchase and sale of foreign currency | **Our own book, both directions** | 5 |
| Act 723 s.3(4)(b) receipt or payment of foreign currency | **Escrowed cedi deposits; off-system cedi/naira legs** | 3, 4 |
| Act 723 s.3(4)(c) importation / exportation of FX | Not engaged — both currencies are our own book | — |
| Act 723 s.3(4)(d) lending or borrowing of foreign currency | Not engaged | — |
| Act 930 s.18(1)(h) FX trading for own account | **Would apply if we were a bank** | 5 |
| Act 930 s.18(2) SDI bar on FX | **Closes the GH¢15m route** | — |
| Act 1154 VASP service | **Not engaged by the shipped market** | — |

**The single question this map exists to put to counsel:**

> Rows 1–4 (a venue for checked traders to trade with each other, with escrowed deposits and an
> operations-resolved dispute process) and row 5 (our own book, both directions). Which of these
> requires a licence under Act 723, and which are the rest?

Because the answer is almost certainly **"row 5"** — and because if it is, the product already draws
its own line. The Checked tier is the part that is arguably a venue, and it is the part that is already
built to guarantee nothing.

---

## 1. The statute that binds us is not the one we have been thinking about

`brand/product.html` carries the open question:

> *What licence do we apply for? **Trading platform, not exchange.** It is the honest description of
> the product, and it matches six admitted firms. The SEC decides, not us.*

That is an **SEC-shaped** question — it optimises how we describe ourselves to a securities regulator.
It is not the question the statute asks.

The statute that binds the cedi/naira business is the **Foreign Exchange Act, 2006 (Act 723)**.

### Section 3(1)

> *"A person shall not engage in the business of dealing in foreign exchange without a licence issued
> under this Act."*

### Section 3(4) — what that business includes

> *"The business of dealing in foreign exchange includes the (a) **purchase and sale of foreign
> currency**, (b) receipt or payment of foreign currency, (c) importation and exportation of foreign
> currency, and (d) lending and borrowing of foreign currency."*

### The Act's own definition

> *"foreign exchange business means the business of **buying, selling, borrowing, lending, receiving or
> paying foreign exchange**."*

### Against our own product description

`brand/product.html`, on the biggest change in the business:

> *"KudiMarket buys currency from traders and sells to other traders out of its own float."*

Purchase and sale of foreign currency. **On the face of Act 723 s.3 that is inside the definition.**

**What this is not.** It is not a conclusion that KudiMarket needs a licence — that is for counsel,
and the answer may turn on the peer-to-peer side, on the licence category, or on how the float is held.
What it is: the licensing question in the product doc is probably the wrong question, and it cannot be
deferred to a launch checklist because it goes to whether the core product may operate at all.

---

## 2. The middle route is closed

There is an obvious cheaper-looking answer and the statute rules it out.

**Banks and Specialised Deposit-Taking Institutions Act, 2016 (Act 930), s.18(2):**

> *"Despite subsection (1), a specialised deposit-taking institution **shall not engage in the trading of
> foreign exchange or offer services denominated in a foreign currency**."*

FX trading is a permissible activity for a **bank** — Act 930 s.18(1)(h), *"trading for own account or
for account of customers in … (ii) foreign exchange"* — and explicitly not for an SDI.

| Route | Minimum paid-up capital | Initial licensing fee |
|---|---|---|
| Bank (Act 930) | GH¢ 400,000,000 | GH¢ 100,000 |
| Specialised deposit-taking institution (Act 930) | GH¢ 15,000,000 | GH¢ 10,000 |
| **FX dealer under Act 723** | **prescribed by BoG — not yet located** | **prescribed — not yet located** |

So it is a bank licence, or an Act 723 dealer licence, or nothing. **The GH¢15m SDI route cannot carry
this product.**

---

## 3. Act 1154 is the crypto Act, and it bites on the USDT leg

**Virtual Asset Service Providers Act, 2025 (Act 1154).** Bill 140 of 2025, passed by Parliament
12 Dec 2025, assented 24 Dec 2025. Regulators: Bank of Ghana (new Virtual Assets Department), SEC,
and the FIC for AML coordination.

This governs the **virtual asset leg only**. It is not the licensing route for the cedi/naira market.

### Two requirements that are ours, not hypothetical

**Client-asset segregation.** Act 1154's custody provisions require a licence holder to *"place the
assets of clients in trust or in a separate bankruptcy remote account so that the assets of clients are
separate and distinct from the assets or estate of the holder of the virtual asset service licence."*

That is the same requirement as the one `brand/product.html` gives as the reason BoG is our first
regulator. We now measure it: `kudi-core/014` adds `segregation_report()`, and **every fiat trade in
the product is counted in `fiat_legs_unposted`** because the naira and cedi that actually change hands
are not posted to the ledger at all. That number is the gap.

**The 20 February 2026 joint BoG/SEC notice.** VASPs, including sandbox participants, are directed to:

> refrain from mass marketing or public promotional campaigns unless expressly authorised;

> treat virtual asset advocacy as a regulated activity requiring registration with both BoG and SEC;

> and note the Act's transitional arrangements for existing VASPs.

This is the directive previously carried in our notes without a citation. It constrains **marketing**,
not just operation — which bears on launch communications.

---

## 4. What BoG expects to see operationally

The February 2026 directive on FX Trading Licence renewal for Licensed Dealer Banks makes continued
validity conditional on *"multi-layered authorisation processes, comprehensive customer identification,
clear **segregation of duties**, and appropriate AML/CFT checks"* — and names lapses in *"verification
protocols, segregation of duties, and AML/CFT procedures"* as the material weaknesses found in
supervisory examinations.

We already have one of these and did not build it for this reason:

- **`/pricing`** — a separate Filament panel writing through the `pgsql_pricing` connection, which is
  the only role granted `set_rate()`. An address holding a pricing row is refused the operations
  console, so it cannot attest a payment or resolve a dispute. `kudi-core/006` names this as a
  property of the rate card, not a nicety.
- **`/admin/trader-checks`** — the checking queue, with the decision recorded against the operator's
  identity in a hash chain.
- **`/admin/ledger-integrity`** — five hash chains plus the segregation figures.

None of that satisfies a regulator. It is evidence that the control exists.

---

## 5. Open questions, for counsel — in the order worth asking

1. **Does our own book require an Act 723 dealer licence?** "Buys currency from traders and sells to
   other traders out of its own float" reads as purchase and sale of foreign currency. This is the
   question the whole business turns on.
2. **Does operating a venue for peers differ from taking the other side ourselves?** The Checked tier
   is vendor-to-vendor and we are not a party. If a licensed dealer is required for our own book, is a
   venue model a different question? If so, is it licensed under the same Act, or another?
3. **Which licence category and fee schedule applies to a non-bank under Act 723?** The Act says the
   Bank *"shall prescribe"* the competent bodies and the fees. We have not located a current schedule.
   BoG's regulatory framework page lists forex bureaux under its supervision without figures.
4. **Does the shipped market engage Act 1154 at all?** §0 shows it does not: no trader-facing screen
   reaches the crypto leg, and `brand/product.html` already says nothing exotic is in our book. So
   Act 1154 is very probably *inert for the product as built* — it would bite only on the line
   *"Our wallets, a fresh address per trade."*

   An earlier draft of this brief recommended removing the crypto leg as "the cheapest compliance
   decision available". **On the activity map, that recommendation does not survive its own logic**, for
   two reasons worth stating rather than quietly dropping:

   - **It does not unblock anything.** Act 723 binds rows 1–5 regardless of whether USDT exists. A
     large refactor behind a blocker that is still standing is a large refactor for a second-order gain.
   - **It rests on law this brief could not verify.** §6 says the gazetted text of Act 1154 was not
     located. Deleting a product capability on an unverified reading is the wrong trade.

   The right question is not "should we delete the crypto leg". It is **"should we delete the sentence
   about our wallets"** — because that sentence, and only that sentence, is what puts Act 1154 in scope.
   Deleting a line of brand copy is cheap and reversible. Deleting a trade type, an escrow path and a
   large part of the test suite is neither.
5. **Do we need the VASP Act at all before we hold a wallet?** `brand/product.html` says *"Our wallets,
   a fresh address per trade."* Custody is the most heavily regulated thing on that page.

---

## 5b. THE FORK THAT DECIDES EVERYTHING: BROKER OR DEALER

This is the sharpest thing in the brief and it was not visible until the BoG's own FX Broker
post-authorisation conditions were read. They forbid, in terms, the exact economics of the
**KudiMarket handled** tier.

> **v.** The FX Broker shall not take the other side of customer trade.
> **vi.** An FX Broker shall not buy or sell foreign exchange for its own account, nor hold, borrow or
> lend foreign exchange to an authorized market participant.
> **viii.** FX Brokers shall operate on commission basis and should not include their commissions as
> spreads in pricing trades.
> **vii.** An FX Broker is required to be an intermediary between banks on the Interbank Foreign
> Exchange Market.

Read those against `product.html`'s own words — *"KudiMarket buys currency from traders and sells to
other traders out of its own float"* — and the collision is total:

| The tier | What it does | Broker condition that forbids it |
|---|---|---|
| **KudiMarket handled** | We are the counterparty, we take the spread, we hold inventory | v, vi **and** viii |
| **Checked** | A venue where two traders deal with each other | vii — brokers must intermediate *between banks*, not host retail traders |

**So there are two genuinely different businesses here, and they are not variations on one:**

- **A licensed DEALER under Act 723 s.3.** May buy and sell foreign currency (§3(4)(a)) and earn a
  spread. That is what the own-book tier *is*, and it is the ordinary bureau de change model. It requires
  a licence, and whatever capital and fee the prescribed notice sets.
- **An authorised FX BROKER on the Interbank Market.** May not take the other side, may not trade for
  own account, must earn commission rather than spread, and must sit between banks. Yearly prior
  approval, every calendar year.

KudiMarket cannot be both, and the **KudiMarket handled** tier is not available under the broker route at
any price. This is a bigger question than "which licence" — it is **"which business are we."**

The current authorised list, effective 1 January to 31 December 2026, is **twelve FX Brokers** (IC
Securities, SIC Brokers, Serengeti, Sarpong Capital, Terika Financial Services, Savvy Africa, GFX
Brokers, Regulus, MB&CO Capital, ICAP, Obsidian Achernar, TAW Impact Capital) plus **three** Cross-Border
Payments and Financial Services Providers (Crown Agents Bank, CSL Capital, StoneX Financial). An earlier
draft of `product.html` cited "six admitted firms"; that number is wrong and has been corrected.

Note also that every condition on that list is *post-authorisation*: the broker is already licensed.
Condition (x) — *"shall quote exchange rates that are based on actual transactions within the domestic
economy in Ghana"* — is one we would have to evidence on request.

---

## 5c. AML/CFT/CPF: A SECOND, HARDER OBLIGATION SET THAT WE HAVE NONE OF

This is the largest single gap between what KudiMarket is and what the law requires, and it is worse than
the licensing question because it is not a question at all. It is a list of duties that attach to Foreign
Exchange Bureaux under the **Anti-Money Laundering Act, 2020 (Act 1044) s.52(e)(i)** and the BoG/FIC
**AML/CFT/CPF Guideline for Foreign Exchange Bureaux, September 2025 revision** — in force on issue and
**replacing** Notice BG/GOV/SEC/2025/04 (the 2024 guidelines). BoG is the designated supervisory body and
**must approve the appointed AMLRO**. L.I. 1987, the AML Regulations 2011, also applies. (The 2025 revision
is what is cited here, verified against the published PDF on bog.gov.gh; an earlier draft cited the
superseded 2024 notice and has been corrected.)

What the Guidelines actually require:

| Duty | Detail | State at KudiMarket |
|---|---|---|
| **Ghana Card, every transaction** | Collect the customer's Ghana Card and verify through NIA's Identity Verification System Platform (IVSP) | **absent** |
| **Biometric at USD 10,000+** | Single transaction at or above **USD 10,000** (or equivalent): must capture Ghana Card details **and biometrically verify** the customer | **absent** |
| **Source of funds and purpose** | Enquire purpose, occupation/business activity, source of funds | **absent** |
| **CTR** | File with the FIC for every sale or purchase at or above **GHS 20,000** or foreign equivalent | **absent** |
| **STR** | File with the FIC where CDD fails through customer non-cooperation — **within 24 hours** | **absent** |
| **STR, any amount** | All suspicious transactions reported regardless of value; staff may never tell a customer a report was filed (tipping-off is prohibited) | **absent** |
| **Records, 5 years** | Customer and transaction records, readily available to BoG or FIC on request | hash chain exists; **no retention policy or regulator export** |
| **AMLRO** | A designated officer approved by BoG, reporting to directors, filing STRs/CTRs, running training. No outsourcing of the AML function without prior BoG approval | **absent** |
| **Independent annual audit** | The AML/CFT programme tested by an internal or external auditor; report submitted to BoG/FIC **by 15 January** each year | **absent** |
| **Electronic receipt** | Mandatory for customers | **absent** |
| **Sanctions screening** | Screen every customer against sanctions lists | **absent** |
| **Training** | Mandatory, with evidence submitted to BoG and FIC by **31 December** each year | **absent** |

Act 1044 adds two statutory reporting duties of its own: **s.41** (conveyance of currency into or out of
the country must be declared in accordance with Act 723 and its Regulations — which is what a cedi/naira
cross-border book is) and **s.42** (an electronic transfer exceeding a prescribed BoG amount must be
reported to the FIC **within 24 hours**).

Cross-border wires additionally require originator and beneficiary data — name, account or unique
reference, address, Ghana Card number — **and the purpose of the transaction**, captured on transfers at
or above USD 1,000 equivalent.

**And a nine-day-old notice that lands on us specifically.** On **9 October 2026** BoG amended the
Guidelines on the Importation and Exportation of Foreign Currency as AML measures, issued under Act 723,
Act 1044 **and** the Customs Act, 2015 (Act 891) as amended by Act 1014. A GHS↔NGN book is an
importation and exportation of foreign currency. It should be read before anything else in this section.

**THE POINT THAT MATTERS MOST, and it is unflattering:** the verification flow built in `kudi-core/010` —
apply to be checked, staff approve, publish offers — **is not CDD and must never be described as
compliance.** It is a trust signal between traders, and it is a good one. Act 1044 wants identity verified
against the national ID system, source of funds, purpose, and a filing obligation to the FIC. Those are
different things with different regulators and different consequences. Presenting the Checked tier as an
AML control would be the single most dangerous thing this product could do.

Penalties for non-compliance run to suspension or revocation of licence plus administrative penalties.

---

## 6. What could not be verified

Stated plainly, because the opposite would be a fabricated citation.

- **The gazetted primary text of Act 1154 was not located online.** Multiple secondary sources
  assembled this brief explicitly flag that it could not be found and that the gazetted text must be
  verified before relying on it. Everything in §3 comes from BoG and SEC summaries and from a
  secondary compilation. **Check it against the Gazette.**
- **The Act 723 primary text is public and has now been read.** GhaLII carries the full Act at
  `ghalii.org/akn/gh/act/2006/723`. Everything in §1 and §2 above is quoted from it, not from a summary.
  An earlier draft of this brief left the Act's availability open; it is settled, and only §3's caveat remains.
- **The Act 723 non-bank fee and capital schedule** is prescribed by notice and no current notice was
  found. §5 says so and asks for it. Act 723 s.5(1) does at least tell us what the application turns on:
  the Bank must grant within sixty days *if* the conditions are satisfied and *the prescribed fee is paid*.
- **NIPO trademark classes 36/42/9** remain unsearched (`brand/BRAND.md` §8).
- The logo mark has not been produced; `brand/BRAND.md` records this as outstanding.

---

## 7. Sources

Primary, retrieved 10 Oct 2026:

| Source | Reference |
|---|---|
| Foreign Exchange Act, 2006 (Act 723) | s.3, s.15, s.19 — [GhaLII](https://ghalii.org/akn/gh/act/2006/723/eng@2006-12-29), Parliament bill text |
| Banks and Specialised Deposit-Taking Institutions Act, 2016 (Act 930) | s.7, s.9, s.18 — [BoG PDF](https://www.bog.gov.gh/wp-content/uploads/2019/09/BANKS-AND-SPECIALISED-DEPOSIT-ACT-2016.pdf) |
| Act 930 licensing requirements | BoG Banking Supervision — bank GH¢400m / SDI GH¢15m, fees |
| Virtual Asset Service Providers Act, 2025 (Act 1154) | BoG Virtual Assets page; BoG/SEC **VASP Act FAQ, February 2026** (primary) |
| BoG/SEC notice on unauthorised advertising | **20 February 2026** joint notice (primary) |
| BoG regulatory framework | [BoG](https://www.bog.gov.gh/supervision-regulation/regulatory-framework/) — Act 612, 918, 930, 774, 992, forex bureaux |
| IMTO Guidelines, Dec 2025 | BoG Payment Systems — Act 612 s.4(1)(e), Act 723 s.2(3), Act 987 |

Secondary, used only to locate the above and flagged where relied on:

- CryptoSlate compilation of Act 1154 — the source that first identified **Act 1154 = VASP Act** and
  which itself warns the gazetted text was not located.
- February 2026 BoG directive on FX Trading Licence renewal (reported via Fitch commentary) — for the
  supervisory expectations in §4 only.
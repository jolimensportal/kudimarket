# KudiMarket — decision documents for review

Five documents, plain text, in the order they should be read. Every regulatory claim carries
its document, clause number and retrieval date. Where a fact could not be pinned to a primary
source, the document says so in those words.

## Verification note, please read first

The last review was checked clause by clause against a machine-assisted transcription of the SEC
guidelines. This version was then checked against the guidelines PDF itself, downloaded and
text-extracted directly (SEC/GUI/001/03/2026, 42 pages, 7 Oct 2026). Concretely:

- Clause numbers below were read off that extraction. Page numbers are deliberately not cited,
  because the transcription's pagination could not be relied on.
- Corrections from that pass are listed in the footer of Document 1, clause by clause, so you can
  see what moved rather than taking the current numbers on trust.
- One sourcing trap is flagged in Document 1's footer: a similarly titled 2025 virtual-assets
  statute with a licensing prohibition and an advertising ban belongs to a different jurisdiction.

## What changed since the last review

- Caps: the applicant-set user limits and transaction caps are 3(3)(b) in the eligibility
  section, not 4(3)(b); 4(3) is about containing the consequences of failure, and 4(2) only
  says who may apply. 9(1) and 9(2) are safeguards the SEC may impose, so Annex B is cited for
  the firmer ground. The Consumer Risk Statement is in Annex E even though 13(1) refers to an
  Annex D template, and the guidelines contradict themselves there.
- The custody model is an application requirement, not a later design choice: 3(3)(e)(i) and
  the Annex A-Addendum's independent third-party smart-contract audit. It now sits ahead of the
  ledger work.
- Pre-submission engagement with the SEC is mandatory (4(5)); the approval clock is ninety days
  from deemed completeness and lapse is not approval (6(1)); a licence lapses if business is not
  commenced within three months (11(a)); 3(3)(d) contemplates prior proof-of-concept testing.
- Capital adequacy, minimum paid-up capital and licence fees are relaxable inside the sandbox
  (Annex B), so "exchange-grade capital" overstates the sandbox burden; the bite is at exit.
- The register is rebuilt: twenty participants, six trading platforms (our likely category, five
  of them in the March cohort), five exchanges, five tokenisations, three brokerages, one
  real-world asset custodian.
- Annex D 2.1's category KPIs assume an order book: bid-ask spread ≤ 50bps, failed settlement
  ≤ 0.1%, and a suspicious-transaction target that reads literally as reporting every flagged
  transaction.
- The loss rule is a waterfall starting with the party that benefited, not a choice between three
  parties; release tiers gained step-down triggers and a cap on trade-size jumps between tiers.
- Four harms added to the fiat-leg model: tipping off versus transparency, stolen crypto in
  escrow and the omnibus-wallet freeze risk, money-mule structuring, and social engineering via
  fake support.
- Section 24(1) of the Data Protection Act permits retention required by law, so the claim that
  an append-only store cannot comply was overstated; the real conflict is AML record-keeping.

## What would be most useful to be told

- Any claim that still does not match the source cited beside it, especially clause numbers and
  quotations. If a clause reference is wrong, say which section of the PDF it sits in.
- Whether "the sandbox is the only route in" is adequately hedged. It is currently an inference
  drawn from the sandbox guidelines and a Bank of Ghana FAQ, because Act 1154's own
  licensing-prohibition section has not been obtained.
- Whether the waterfall is the right shape, and whether the tier rules close the farming route.
- Whether the custody-versus-contract decision is framed as an application prerequisite
  accurately enough, or whether it still hides a dependency.
- Anything in the harm catalogue that is still missing, given that it is built around traders
  deceiving each other, stolen funds, and social engineering rather than around the platform
  failing.

---

# Document 1 of 5 — Regulatory brief and counsel questions

```
# The only SEC route open today, and three questions gate everything.

Read clause by clause from the SEC's own guidelines PDF (SEC/GUI/001/03/2026, 42 pages) on 7 Oct 2026, with the clause number against every quote. Four findings survive the text: SEC licensing is not open yet, and the sandbox is the only SEC route to it; category 1 is "Virtual Asset Exchanges and Trading Platforms" and six admitted firms already hold it; the application decides things we had queued as later design choices, starting with the custody model, the chain, and the vendors; and capital adequacy is relaxable inside the sandbox while minimum paid-up capital is decided case by case. One caveat matters more than any of them: the guidelines contain no exclusivity clause, so "the sandbox is the only route in" was our inference and the Bank of Ghana's own track is unexamined.

  

The position in six lines

  

    

**The route in**
SEC licensing is not open; the sandbox is how you get to it. The notice of 23 January 2026 opens licensing for seven categories "upon the completion of the regulatory sandbox framework", and the August notice says the pilot is collecting data to finalise activity-specific licensing guidelines. 6(7) and 9(9): approval "shall not be construed as an endorsement, authorisation, or permanent licence". 10(5): on exit the entity "shall cease all Sandbox activities unless otherwise authorised". The guidelines contain no exclusivity clause, and the Bank of Ghana appears once in 42 pages, in a consumer disclaimer, so whether a second track exists is a question we have not answered.

    

**The category**
Not open, and not ours. Category 1 of the licence list is "Virtual Asset Exchanges and Trading Platforms", and six admitted firms hold exactly that activity. The guidelines define neither term, so the definitions live in Act 1154's Schedule, which we still do not have.

    

**The incumbents**
Twenty participants, and the crowding is in our category. Six trading platforms, five exchanges, five tokenisations, three brokerages, one real-world asset custodian. Five of the six trading platforms were in the first cohort of 10 March 2026. The binding tests are not 7(1)(a): 8(1)(d) allows rejection for "uncertainty relating to regulatory classification", 8(1)(f) for failure to demonstrate AML, fraud-prevention and sanctions compliance or "unacceptable risk of illicit financial activity or consumer harm", and 8(1)(h) for activity that "cannot be appropriately controlled, supervised, suspended or terminated in an orderly manner".

    

**The application**
It decides what we thought was ours to decide. 3(3)(b): the applicant's own testing plan sets "user limits, transaction caps and geographic scope". 3(3)(e)(i): "a clearly defined custody or asset control model". 3(5): a "winding up and failure management plan". 4(5): "An Applicant shall engage the SEC prior to submission". 6(1): ninety days "from the date the SEC determines that the application is complete and satisfactory for assessment", and lapse is "not construed as automatic approval". 11(1)(a): termination may follow where the entity "fails to commence the business" within three months of issue, and 11(2) lets the SEC "terminate a Regulatory Sandbox Licence at any time by written notice".

    

**Cost**
Read Annex B's two tables separately. "Possible to Relax" ends at licence fees: asset maintenance, board composition, cash balances, credit rating, financial soundness, capital adequacy, licence fees. A separate heading, "SEC's Guidelines that may be applicable in each case and on case-by-case basis", covers minimum liquid assets, minimum paid-up capital, relative size, reputation and track record, so minimum paid-up capital is not simply waived. Annex D also asks every VASP for a ratio of liquid assets to liabilities each quarter. 15(5): we bear audits, independent assurance and technical assessments.

    

**Marketing**
The 20 February 2026 directive makes virtual-asset advocacy a regulated activity. Whether honest product education counts as advocacy is untested, and the primary notice is not yet in our hands. The guidelines themselves contain no advertising clause, only disclosure duties.

  

## What a sandbox licence is, in the regulator's words

  

**Verbatim quotes and where they sit**

  

    

6(7) "Approval to participate in the Sandbox shall not be construed as an endorsement, authorisation, or permanent licence to conduct regulated activities outside the Sandbox, unless otherwise expressly granted by the SEC."

    

6(1) "The approval process shall not exceed ninety (90) days from the date the SEC determines that the application is complete and satisfactory for assessment." With 6(2): the SEC may "suspend, defer, or discontinue the approval" process.

    

9(9) "A Regulatory Sandbox Licence shall be limited in scope and duration and shall not constitute a full licence, permanent authorisation, or approval to conduct regulated activities beyond the Sandbox unless otherwise expressly granted by the SEC."

    

10(3) "the Sandbox Entity may deploy the product or service on a broader scale, subject to obtaining all required licences, registrations, approvals, or authorisations under applicable laws"

    

11(1) Termination grounds "may include where", and 11(1)(a) is failure "to commence the business for which the Regulatory Sandbox Licence was issued within three (3) months of the issuance of the licence". 11(2): the SEC "may terminate a Regulatory Sandbox Licence at any time by written notice".

    

12(1) "A Sandbox Entity shall not cease operations, suspend testing, or exit the Sandbox without the prior written approval of the SEC, except where termination is directed by the SEC under these Guidelines."

    

BoG FAQ "The Act provides for regulatory transition arrangements, allowing existing operators time to comply once the law takes effect. Operating without authorisation after this period will attract sanctions."

  

## The application decides more than we assumed

  

**Section 3 is eligibility, and it is where our design choices now live**

  

    
      
- (3) 3(3) is the testing plan. An Applicant "shall provide a detailed testing plan including: (a) clear objectives, methodologies and timelines; (b) defined parameters such as user limits, transaction caps and geographic scope; (c) measurable expected outcomes and success criteria; (d) evidence of prior proof-of-concept testing where applicable".
      
- (3) 3(3)(e) is the custody, chain and vendor clause. For virtual-asset solutions the Applicant must demonstrate "(i) a clearly defined custody or asset control model, including whether assets are held in self-custody, hosted custody, or through third-party arrangements; (ii) governance arrangements for Private Key Management, transaction authorisation, and operational continuity; (iii) systems and controls for transaction monitoring, blockchain or distributed ledger analytics, and sanctions screening; and (iv) full disclosure of all material third-party service providers". Every one of those vendors is named in the application, so the contracts come before the filing.
      
- (3) 3(5) is a failure plan, and 7(1)(k) asks for it again. The Applicant must submit a "winding up and failure management plan demonstrating how the Applicant will: (a) cease operations in an orderly manner; (b) settle all outstanding customer obligations; (c) safeguard customer assets and data; and (d) notify customers and the SEC immediately".
      
- (3) 3(3)(d) asks for prior proof-of-concept testing "where applicable". What "where applicable" covers is not ours to decide, and reading it as permission to run a live market would be an over-reading.
      
- (4) 4(2) says who may apply, 4(3) is about containing failure, 4(5) makes pre-submission engagement mandatory. 4(3): "The Sandbox shall include appropriate safeguards to contain the consequences of failure and maintain the overall safety and soundness of the capital market." 4(5): "An Applicant shall engage the SEC prior to submission to clarify regulatory expectations and suitability for Sandbox testing. Such engagement shall not be construed as approval or endorsement by the SEC."
      
- (5) The audit may not be optional. The addendum's evidence list asks for a "Tokenomics whitepaper or equivalent technical documentation" and "Smart contract audit reports", with no "where applicable" qualifier, unlike the node-governance item. Read literally, a keys-in-wallet design still has to address it. Ask the SEC rather than assume.
      
- (5) And segregation cuts against a pooled wallet. The addendum asks for evidence of "segregation of client assets from proprietary funds", which is in tension with one omnibus escrow wallet for every trade. Either segregate, or write the case for a pooled account and let the SEC rule on it.
      
- (8) Three rejection grounds do the real work. 8(1)(d) "uncertainty relating to regulatory classification, supervisory authority, enforceability, or regulatory treatment"; 8(1)(f) AML, fraud-prevention and sanctions compliance or unacceptable risk of illicit financial activity or consumer harm; 8(1)(h) activity that "cannot be appropriately controlled, supervised, suspended or terminated in an orderly manner". 7(1)(a) also requires the product to be "not substantially similar to products or services already available for which market operators are licensed by the SEC", but sandbox participants are not licensed operators, and six trading platforms were admitted anyway, so it is a weaker test than it first reads.
    
  

## Caps are ours to propose. Everything else is a safeguard the SEC may impose.

  

    

The word matters. 9(1) reads "The SEC may issue a Regulatory Sandbox Licence subject to such safeguards, terms and conditions, including requiring the Applicant to:", so the sub-clauses are conditions the SEC may impose rather than automatic statutory duties. Where we need firmer ground, Annex B is the better citation: the SEC intends to maintain confidentiality of customer information and data handling, fit and proper criteria, "handling of customer's moneys and assets by intermediaries", prevention of money laundering and countering the financing of terrorism, corporate governance, and environmental requirements.

    

Relaxable, per the first Annex B table: asset maintenance requirement, board composition, cash balances, credit rating, financial soundness, capital adequacy, licence fees. Case by case, per the second table in the same annex: minimum liquid assets, minimum paid-up capital, relative size, reputation, track record. Reading those two lists as one flat list of relaxations was an error in the previous version, and it flattered the sandbox budget.

    

Liquidity is still measured. Annex D's universal KPIs include "Capital/liquidity adequacy vs. requirements", measured as "Ratio of liquid assets to liabilities", quarterly, for every VASP category.

    

Insurance is a safeguard, not an Annex B entry. It appears in 9(2)(c), where the SEC may require "asset protection, safeguarding, segregation, escrow, custody, trust, insurance, or equivalent arrangements where customer assets or funds are involved".

    

Who pays: 15(5) puts audits, independent assurance, technical assessments, reporting and safeguard implementation on the Sandbox Entity, and 15(4) makes the licence fee non-refundable.

    

And the reporting clock: 13(3) requires notification of a material incident, "including system failure, cyber-incident, data breach, fraud event, operational disruption or significant customer impact", within twenty-four hours. A frozen-account case caused by scam proceeds is likely to be both a fraud event and significant customer impact, so this is a live clock, not a formality.

  

## The KPI table assumes an order book

  

**Annex D, section 2.1: Virtual Asset Exchanges and Trading Platforms**

  

    
      
- (KPI) Suspicious transactions: measured as "SARs filed as % of total transactions", target "≥100% flagged activity reported". Read literally that is a report on every flagged transaction, which is a stricter standard than the phrase suggests. Ask which table applies to us.
      
- (KPI) Liquidity: "Average bid-ask spread for top 5 pairs", target "≤ 50 bps". A P2P marketplace between two people has no order book and no spread, so this target cannot describe our business.
      
- (KPI) Settlement efficiency: "Failed settlement rate (%)", target "≤ 0.1%". Our dispute and reversal rate will plausibly exceed 0.1% at launch. If we land in this category, we must either explain the metric or breach it on day one.
      
- (KPI) Consumer protection: "≥90%" of users completing a risk awareness quiz.
      
- (→) Universal KPIs apply to every VASP category: transaction volume and value monthly, active users, incident notification within 24 hours, complaints and resolution rate, the quarterly liquid-assets ratio, uptime monthly, and client demographics.
    
    

Read together, the category question is not academic. Choosing "trading platform" brings exchange-shaped metrics that a marketplace cannot meet, and 8(1)(f) allows rejection for unacceptable risk of illicit financial activity or consumer harm.

  

## Twenty participants, and where the crowding is

  

| # | Participant | Approved pilot activity |
| --- | --- | --- |
| 1 | Africoin Ghana | Asset tokenisation: gold |
| 2 | Vaulta Digital Assets | Asset tokenisation: securities |
| 3 | GFX Brokers | Asset tokenisation: T-bills |
| 4 | One Africa Securities | Asset tokenisation: bonds |
| 5 | WEWire Ghana | Asset tokenisation: trade finance |
| 6 | Mansu Technologies | Virtual asset brokerage |
| 7 | Sage Advance Global Services | Virtual asset brokerage |
| 8 | Mojo Pay Ghana | Virtual asset brokerage |
| 9 | Yellow Card Ghana | Virtual asset exchange |
| 10 | Hyro Exchange | Virtual asset exchange |
| 11 | Whitebit Ghana | Virtual asset exchange |
| 12 | Koinkoin Ghana | Virtual asset exchange |
| 13 | Ghana Commodities Exchange | Virtual asset exchange: commodities |
| 14 | Ghana Gold Board | Real world asset custodian: gold |
| 15 | Xchain Technologies | Virtual asset trading platform |
| 16 | Hanypay Ghana | Virtual asset trading platform |
| 17 | The Blue Penguin Company | Virtual asset trading platform |
| 18 | HSB Global Fintech | Virtual asset trading platform |
| 19 | Mamoru Digital Ghana | Virtual asset trading platform |
| 20 | BSystems | Virtual asset trading platform |

Counts, corrected: six trading platforms, five exchanges, five tokenisations, three brokerages, one real-world asset custodian. The earlier version of this brief listed thirteen rows, called them "four exchanges", and said no activity covered a marketplace. Five of the six trading platforms (Xchain, Hanypay, Blue Penguin, HSB Global, BSystems) appear in the first cohort named in the SEC's press release of 10 March 2026, so the activity we would apply for was largely filled at the sandbox's opening. Register: SEC Public Notice No. SEC/PN/001/08/2026, dated 19 August 2026.

## Data protection, restated more carefully

  

**Data Protection Act, 2012 (Act 843)**

  

    
      
- (24) An append-only store is not automatically unlawful. Section 24(1) bars keeping personal data longer than the purpose requires, subject to four exceptions: where law requires or authorises the retention, where it is reasonably necessary for a lawful purpose related to a function or activity, where a contract between the parties requires it, or where the data subject consents. AML record-keeping falls inside that list, so the earlier claim that write-once evidence cannot satisfy section 24 was wrong. (The four limbs are quoted here in paraphrase deliberately; the subsection itself should be read in full before it is relied on in a filing.)
      
- (24) The real tension is with AML records. Section 24(4) sets retention for records used to make a decision about a data subject, 24(5) and (6) require destruction or de-identification at the end of the period preventing reconstruction in intelligible form, and section 44 lets the Commission order erasure and require notice to third parties. So the question is not immutability versus privacy but which retention period governs a dispute record that is also an AML record, and Annex B keeps "confidentiality of customer information and data handling" among the requirements the SEC intends to maintain.
      
- (→) Design consequence: keep personal data erasable, hash-chain the evidence, and anchor digests rather than records. Then agree the retention periods with counsel against the AML record-keeping obligation rather than against a guessed privacy rule. Detail in the fiat-leg document.
      
- (46) Registration is its own obligation. The Act establishes a Data Protection Register and bars processing without registration for assessable processing. Whether we are in scope on day one is question seven.
    
  

## Questions for counsel, and for the SEC's pre-submission channel

  
- Which category, and what follows from it? *Trading platform, exchange, or both, plus custodial wallet provider because we hold traders' crypto. Which Annex D KPI table attaches, and how do we answer a bid-ask spread target and a 0.1% failed-settlement ceiling in a marketplace with no order book? Definitions live in Act 1154's Schedule, which we need.*
  
- What custody model, chain, and vendor set do we file? *3(3)(e)(i) requires a defined custody or asset control model at application, 3(3)(e)(iii)-(iv) names our analytics, sanctions and custody vendors, the addendum asks us to describe the digital tokens involved, and its evidence list includes a tokenomics whitepaper and smart-contract audit reports without an "if applicable" qualifier. So chain choice, vendor contracts and the audit question all land here: does a keys-in-wallet design still have to file an audit, and does one omnibus escrow wallet survive the segregation-evidence requirement?*
  
- Is our admission viable against six admitted trading platforms? *The live grounds are 8(1)(d) classification uncertainty, 8(1)(f) illicit-finance and consumer-harm risk, and 8(1)(h) orderly control, suspension and termination. What is our differentiator on paper, and does it survive those rather than the 7(1)(a) test?*
  
- Are we a foreign applicant? *The 30% local-participation threshold, the physical Ghana office and the resident senior or compliance officer apply to foreign virtual asset service providers. The answer depends on how the entity is structured and changes the application.*
  
- Is there a second route, and which regulator owns what? *The guidelines contain no exclusivity clause and mention the Bank of Ghana once, in a consumer disclaimer. Whether the BoG operates a parallel authorisation or sandbox track for payment or custody activities, and which regulator owns which part of our product, is unanswered.*
  
- Is product education "advocacy" under the 20 February 2026 directive? *And can we get the primary notice text rather than a report of it.*
  
- Retention, and the Data Protection Register. *Reconcile section 24(4) retention with the AML record-keeping obligation, tell us which period governs a dispute record, and whether the registration duty bites at application or at launch.*
  
- Send us Act 1154: the licensing prohibition, the penalties, and the Schedule definitions. *Our conclusion that real money needs authorisation rests on the sandbox guidelines plus a BoG FAQ, not on the Act. The Act's own short title appears in three forms across these guidelines, which is worth confirming against the enacted text.*
  
- What is the perimeter for naira, CFA and RMB? *Cedi-to-dollar is foreign exchange under BoG rules, "other financial instruments" may be securities, and RMB rails prohibit virtual-currency transactions. Can one product serve four corridors under one authorisation?*
  
- Timeline, intake, and the fee. *Next cohort dates, how long pre-submission engagement takes in practice, and confirmation of the non-refundable licence fee. Ninety days runs from deemed completeness, and 6(2) lets the SEC suspend or defer, so the queue before that clock matters more than the clock.*

## What happens next

  
- Open the pre-submission channel with the SEC (mandated by 4(5)) and put counsel questions one, two and three to them in the same week. Everything else is sequenced behind those answers.
  
- Obtain Act 1154 in full: licensing prohibition, penalties, and the Schedule category definitions.
  
- Draft the testing plan, because 3(3)(b) makes our tiered release design the numbers the SEC approves, and 3(3)(d) asks for evidence of prior proof-of-concept testing "where applicable".
  
- Write the failure plan first. 3(5) and 7(1)(k) both ask for it, 12(1) stops us ceasing operations without SEC approval, and 11(2) lets the SEC terminate at any time on written notice. Design what happens to in-flight trades when we are switched off, ideally a refund path that still works when our servers do not.
  
- Assemble the admission file: business registration; a three-to-five-year business plan with a feasibility study covering the activity, forecasts, capital structure, funding sources and regulatory constraints (5(1)(b)-(c)); three years of audited accounts or an audited statement of affairs (5(1)(d)); promoters, beneficial owners and directors (5(1)(e)); a winding-up and failure management plan (3(5)); the custody model, key-management and transaction-authorisation arrangements, monitoring, blockchain analytics and sanctions-screening controls, and third-party vendor disclosure (3(3)(e)); tax clearance certificate; details of shareholders holding five percent or more; director declarations covering fraud or dishonesty convictions within the last ten years; the licence fee receipt; and the addendum's technical and evidence items. For a new entity, the audited statement of affairs and the director checks are the long poles.

---

Primary source, read directly. Securities Industry (Regulatory Sandbox Licensing) Guidelines 2026, SEC/GUI/001/03/2026, 42 pages, downloaded and text-extracted on 7 Oct 2026: sec.gov.gh [https://sec.gov.gh/wp-content/uploads/Final-Regulatory-Laws/Guidelines/Guidelines_Regulatory_Sandbox%20Licensing_2026.pdf]. Clause numbers here were read off that extraction, not off a transcription. SEC Notice on the Virtual Asset Sandbox, SEC/VASP/002/01/2026, 23 Jan 2026 (category list). Press Release SEC/PR/001/03/2026, 10 Mar 2026 (first cohort of eleven). Public Notice No. SEC/PN/001/08/2026, 19 Aug 2026 (full register). Bank of Ghana FAQ on Act 1154: bog.gov.gh [https://www.bog.gov.gh/wp-content/uploads/2026/03/VASP-Act-FAQs.pdf]. Data Protection Act, 2012 (Act 843): ghalii.org [https://ghalii.org/akn/gh/act/2012/843].
 Not yet in hand, and not to be asserted: Act 1154's licensing-prohibition and penalty sections and its Schedule category definitions; the joint BoG–SEC marketing directive of 20 February 2026; page numbers for any quote (clause numbers only, because a machine-assisted transcription of the guidelines was used for an earlier pass and its pagination could not be relied on); and any statement about the Bank of Ghana's own track. Annex B's two tables are separated here on the basis of the printed layout on page 34, which text extraction alone does not preserve.
 Sourcing trap. A similarly titled 2025 virtual-assets statute with its own licensing prohibition, advertising ban and record-custodian duties belongs to a different jurisdiction. It is easy to find, it is not Ghana's Act, and it must not be cited here. Competitor claims, such as Yellow Card's licence history, come from the company's own pages and are labelled as such.
 Corrections made to the previous version of this brief, so you can see what moved rather than trust it: 6(1) was misquoted and lost the words "and satisfactory for assessment", which is what puts the clock's start in the SEC's judgment; 11(1)(a) was described as a lapse when it is a termination ground, with 11(2) allowing termination at any time by written notice; Annex B's two tables were read as one, overstating what is relaxed (capital adequacy yes, minimum paid-up capital case by case); "the sandbox is the only route in" was stated flat where the guidelines contain no exclusivity clause, and it is now "the only SEC route open today"; 7(1)(a) was overweighted against 8(1)(d), (f) and (h); insurance was cited to Annex B when it appears in 9(2)(c); 3(3)(d) was glossed as "not a live market" when the clause only reads "where applicable"; Act 1154's title appears in three forms in the guidelines, not two; section 24(1) was quoted with two of its four exceptions; and stale cross-references between the documents were fixed.
```

---

# Document 2 of 5 — The fiat leg

```
# The fiat leg: what we can prove, and who pays when we are wrong.

No ledger can check this half of a trade. Every guarantee KudiMarket makes ends the moment a person taps send on their phone, and the operators involved publish no status API to us. This document covers the four payment corridors in our brief, the harms that fall on people who are not party to a trade, the only honest definition of green, and the loss rule that has to exist before any release button does.

  

Four answers, none of them comfortable

  

    

**What we can see**
Almost nothing that a liar cannot fake. Mobile-money and wallet rails give no third party a way to confirm that a payment settled. A transaction reference and an SMS are both claims by the party we are checking.

    

**What green means**
Attested, never verified. The seller confirms receipt in-app, the paying name matches the registered trader where the rail exposes it, the dispute window has elapsed, no dispute is open. The interface must say exactly that, and no screen may call it verified.

    

**Who eats a reversal**
Undecided, and it is the largest open decision in the product. Three candidate loss rules below, none chosen. Mostro's "when in doubt, release" cannot be adopted without one.

    

**The harm that is worse**
Not traders lying to each other. A buyer paying with a scam victim's stolen funds freezes the seller's bank account and makes the platform an exhibit in a police case, with the seller asked to repay the victim. The clearest account of that chain comes from a single law-firm article on the RMB corridor, not from a court decision or a regulator, and it is labelled that way wherever it appears in this document.

  

## Four corridors, four behaviours

The brief covers cedi, naira, CFA and RMB, and each rail reverses, freezes and reports differently. The status column is a recommendation, not a plan of record.

  

| Corridor | Instruments | Reversal and freeze behaviour | What we can observe | Status |
| --- | --- | --- | --- | --- |
| Cedi | MTN Mobile Money, Telecel, AirtelTigo, bank transfer | A completed mobile-money payment is not reversible at the sender's request; reversals arrive through the operator's fraud-claim process, and operators or banks freeze balances on suspicion or on a police or Financial Intelligence Centre request. Window length unknown: industry reports say 24 to 48 hours, unverified. | Transaction reference where the receiving app shows one, payer name, seller attestation. Nothing a third party can check. | launch |
| Naira | Bank transfer, fintech wallets and agents | Banks freeze on suspicious-activity reports and police requests; recall after settlement is discretionary rather than a right. Documented pattern: an account frozen, then funds deducted after the holder's account of the money did not match the evidence. | Payer account name and transfer reference on the receiving statement. More than mobile money, still not a settlement guarantee. | launch, subject to counsel on serving naira from Ghana |
| CFA | Orange Money, Wave, Moov Money | Wallets regulated in the WAEMU zone, not in Ghana. Reversal, freeze and reporting behaviour not yet researched in this project, and said to be thin in practice. | Reference and name only. | research first |
| RMB | Alipay, WeChat Pay, Chinese bank accounts | Highest risk in our set, and it lands on the trader. Alipay's stated policy prohibits virtual-currency transactions and stops the payment; accounts used for them can have collection restricted; receivers of upstream fraud proceeds are frozen, and linked accounts with them. One case described in a law-firm article of July 2026: a year-long freeze and RMB 700,000 deducted after statements that did not match the evidence. | Payer name, then a freeze. | exclude at launch |

Why RMB is excluded rather than deferred. On every other rail the freeze lands on a trading wallet. On the RMB corridor the freeze lands on a bank account holding someone's salary, and the account holder then owes restitution to a fraud victim. Promising a corridor whose dominant failure mode is harm to the counterparty is a promise we cannot keep. This is a recommendation for counsel, not a finding of law.

## Who gets hurt, and how

  

**The harm catalogue**

  

    
      
- (a) The forged screenshot. Either party can edit a chat in their browser, photograph it, and no mediator can tell which version is authentic. The server must write the chat once; even then an uploaded payment screenshot proves nothing, so the evidence rules have to say what counts.
      
- (b) The reversal. Funds arrive, escrow releases, and days later the payment is clawed back through the operator's fraud process. Needs a settlement window and monitoring, not a faster release.
      
- (c) The third-party payer, and the victim behind them. A buyer sends money that was stolen from a scam victim. The seller's account is frozen, the platform's records enter a police file, and the seller is asked to repay. Three consequences for design: the payer-name check becomes mandatory, KYC depth has to cover the buying side, and a freeze-handling procedure must exist that tells traders what happens and who to contact. If a counterparty asks to pay from a third party's account, the documented advice in the RMB corridor is to cancel.
      
- (d) The SIM swap. The confirmation comes from a hijacked number, so the person attesting is not the person who traded.
      
- (e) What green can honestly mean. The operator exposes nothing to third parties, SMS confirmations are a standing scam in Ghana, and an immutable chat does not make a screenshot true. So green is attested: the seller confirms receipt in-app, the paying name matches where the rail exposes it, the window elapsed, no dispute open. The interface says so. Anything stronger claims data we do not have.
      
- (f) The freeze we cause. Our own compliance hold, taken while a suspicious payment is investigated, is the same instrument as an operator freeze. It collides with the loss rule and with the retention rules below, and it needs its own notice, its own clock and its own appeal path.
      
- (g) Telling the trader may be tipping off. Transparency is good service until it breaches anti-money-laundering law: as a matter of general principle, tipping off is prohibited in most regimes, and Ghana's position must be confirmed with counsel. So "we will notify you and give you an appeal" may be a promise we cannot keep in the exact case where the trader most needs it. The procedure needs a counsel-approved line between informing and tipping off.
      
- (h) Stolen crypto, not just stolen cedi. A seller can escrow USDT that is itself the proceeds of crime. The issuer can freeze the address, and one omnibus escrow wallet means one freeze stops every trade at once. The guidelines require "transaction monitoring, blockchain or distributed ledger analytics, and sanctions screening" (3(3)(e)(iii)), so per-trade segregated addresses are the safer architecture, with the cost and the operational weight that implies.
      
- (i) Mules and structuring. The seller is not always an innocent party: splitting many transactions just under our caps is the standard laundering pattern, and it is invisible per trade. Transaction monitoring has to look across trades, which is another argument for the analytics requirement being an application item rather than a later feature.
      
- (j) Social engineering. A fake "KudiMarket support" account telling a seller to release escrow early, and the buyer pushing the conversation to WhatsApp where we cannot see it, are reported to be common in this market. The claim's source strength is weak, so treat the frequency as unverified and the pattern as real enough to design against: state plainly that support never asks anyone to release escrow, keep every consequential message inside the app, and authenticate in-app messages so a support request can be verified rather than trusted.
      
- (k) Our own failure or termination. The application requires a "winding up and failure management plan" (3(5)), and 12(1) bars ceasing operations, suspending testing or exiting without prior SEC approval, while 11(2) lets the SEC terminate on written notice at any time. So the in-flight trade is a designed state, not an edge case: what a trader's crypto does if we are switched off, and whether refunds can complete without our servers, has to be answered before launch, not after the first incident.
      
- (l) Identity we have not chosen yet. No KYC source or liveness check is named anywhere in this document, and that is a gap rather than an omission: mobile-money registration names often differ from legal names, so the mandatory payer-name check will reject honest trades. That check is one of our few observables, so the matching rule has to be designed with the identity source, not bolted onto it.
      
- (m) Free options. Lock a price, wait for the market to move, then let the timeout cancel the trade. The cost is a stale quote rather than stolen money, but it is the cheapest abuse available to anyone who watches the screen.
      
- (n) Where the crypto goes. Release to a buyer-supplied address is an outgoing transfer we control, so destination screening and sanctions screening apply (3(3)(e)(iii)), as does travel-rule compliance (addendum, AML policies item vi). The screening happens on the way out, not only on the way in.
    
  

  

**Open decision · the loss rule**

  

    

Mostro's house rule, when in doubt release, plus a reversal means the seller can lose the crypto and the cedi. That slogan cannot be adopted until someone is assigned the loss, and the answer is not a choice between three parties. In a reversal the buyer keeps the crypto and recovers the cedi, so recovery starts with the party that benefited.

    

The waterfall: first, the buyer's held balance or bond, because that is where the loss landed; second, the platform reserve, sized from the capped exposure we propose to the SEC rather than from rates we cannot have before launch; third, insurance or an equivalent safeguard, which 9(2)(c) lists among the arrangements the SEC may require where customer funds are involved; and only last, the seller absorbing it as a priced cost. A design where the party who gained is the last to pay is a design that invites the harm.

    

Four holes in that waterfall, stated rather than papered over. Step one depends on an unknown: the buyer's held balance only exists if withdrawal holds outlast the reversal window, and that window is unverified for every rail. The tail is uncovered: the worst harm, a freeze plus a victim's restitution claim, arrives after holds expire, so nothing in the ladder pays for it. The reserve can be drained by collusion: a seller paired with a friendly "buyer" who reverses would repeat until the reserve is gone, so recovery needs the operator's written reversal confirmation rather than the payer's word, and a per-account lifetime claim cap. Step three may be empty: if no insurer covers this, the seller pays. Say that in the terms rather than discovering it in a dispute.

    

Release tiers follow from that rule, and three of the first draft's were wrong. Only the party receiving crypto can be held. A new buyer's crypto is held to protect the seller, which does nothing about a seller who falsely claims non-receipt: the buyer's fiat risk needs bonds, evidence rules and dispute outcomes instead of a hold. "Established" needs a definition that cannot be farmed. Count distinct independent counterparties and elapsed time, not trade count, or two accounts trading with each other will climb the tier on their own. Cap how much a single trade may jump between tiers for the same reason. And the step-down trigger has to match the attack: a SIM swap keeps the same number, so watching for a changed number will not fire. Bind to device, apply a delay to consequential actions from a new device, and use a telco swap signal if Ghana exposes one we can lawfully obtain.

    

What survives from the first draft: new and low-volume accounts hold for the full reversal window with a per-account cap, established accounts release earlier, and withdrawal holds run longer than release windows because the exposure outlives the trade. The window lengths are still unknown per rail and must come from the operators' published fraud-claim processes, in writing, before they become numbers here.

    

One constraint from outside: the applicant sets "user limits, transaction caps and geographic scope" in its own testing plan and the SEC approves them (guidelines 3(3)(b), 9(1)(c) as a condition it may impose). These tiers are the numbers in that application. See legal.html.

  

## Evidence that survives both a court and the Data Protection Act

  

**Write-once chat, erasable people**

  

    
      
- (§) Retention has a rule attached to decisions. Data Protection Act, 2012 (Act 843), section 24(4): a record used to make a decision about a data subject must be kept for a period prescribed by law or a code of conduct, or absent one, long enough to afford the subject an opportunity to request access. Dispute chat and rulings are exactly such records.
      
- (§) And an ending, but not the ending we assumed. Sections 24(5) and (6) require destruction or de-identification at the end of the period, preventing reconstruction in intelligible form, and section 44 lets the Commission order erasure. Section 24(1) is the part the earlier draft got wrong: retention longer than necessary is allowed where law requires it, or where it is reasonably necessary for a lawful purpose. AML record-keeping is such a purpose, so an append-only store is not automatically unlawful. The live question is which retention period governs a dispute record that is also an AML record.
      
- (→) So the shape is this: personal data lives in an erasable store; the write-once log holds digests, with each entry committing to the previous one; the head digest is anchored outside the database. The record proves that nothing was altered while it existed, without making the person undeletable. Retention periods and the Data Protection Register question both go to counsel as question seven in legal.html [legal.html].
    
  

## The dispute desk, without invented numbers

  

**The dispute desk, decided by rule rather than by invented numbers**

  

    
      
- (1) Coverage follows the rails, not the office. English only at launch; French when the CFA corridor is admitted, Chinese when RMB is admitted. Escrow and timeouts run around the clock, so first response has to land inside the shortest release window we get approved, whatever that window turns out to be. No case-count figure is asserted here, because none has been measured.
      
- (2) The judge stays away from the money. Haveno's rule, copied deliberately: arbitrators decide, they never move funds.
      
- (3) Collusion is a bribery problem, not a theft problem. Random case assignment; no standing relationship with either party; the written rationale stored in the write-once log; sampled audits; a stake forfeited on misconduct. Keeping the judge away from the funds does not stop a bribe to rule the wrong way.
      
- (4) Freeze cases are not disputes. A third-party police or court freeze is escalated to counsel and escalated fast; arbitrators do not adjudicate them, and traders are told what is happening in plain language to the extent we lawfully can. Note the reporting clock on the way: a scam-proceeds freeze or any material fraud event or significant customer impact must reach the SEC within twenty-four hours (13(3)), so the desk and the compliance function share one queue, not two.
    
  

## What happens next

  
- Obtain the operators' fraud-claim windows in writing, per rail, from MTN, Telecel, AirtelTigo and the banks. Every window in this document is marked unknown because of it.
  
- Research the CFA rails before promising the corridor: Orange Money, Wave and Moov, their freeze practice, and whether a Ghanaian platform can serve them at all.
  
- Take the loss rule to a decision with numbers, then to counsel alongside the sandbox testing plan, because the tiers are the caps we ask to have approved.
  
- Read the prior art for the parts that transfer: lnp2pBot end to end, Mostro's anti-abuse bond document, RoboSats issue 39 on forged screenshots.
  
- Write the freeze-handling procedure before launch, not after the first one: who is notified, within what time, with what documents, and who pays the lawyer.

---

Sources, retrieved 6 Oct to 7 Oct 2026. Sandbox clauses cited here (3(3)(b) applicant-set caps, 3(3)(e)(iii) transaction monitoring, blockchain analytics and sanctions screening, 9(1)(c) operating within approved limits, 13(3) twenty-four-hour incident notification, Annex B safeguards where customer money is handled): SEC Securities Industry (Regulatory Sandbox Licensing) Guidelines 2026, SEC/GUI/001/03/2026, read directly from the PDF. Retention, destruction and erasure duties, including the section 24(1) allowance this document previously overstated: Data Protection Act, 2012 (Act 843), sections 24 and 44. Ghana mobile-money reversal behaviour and fraud advisories: Cyber Security Authority Ghana and Ghanaian industry reporting; the 24 to 48 hour figure in circulation is unverified and is treated as unknown. Alipay's prohibition of virtual-currency transactions and account restriction practice, and the freeze and restitution case on the RMB corridor: legal-practice and industry reporting, not a published rule. Naira bank freeze and recall behaviour: Nigerian banking and enforcement reporting, still to be confirmed against primary sources.
 Revision note. Three corrections since the first draft: the reversal window was stated as fact when it is unknown; green was defined more strongly than the data supports; and the harm to third parties was missing entirely. Two more from the last review pass: the loss rule is a waterfall rather than a choice between parties, and the release tiers needed step-down triggers and a cap on how much a trade can jump between them. One source discipline change: claims resting on a single secondary article now say so in the sentence rather than in the footer.
```

---

# Document 3 of 5 — Build or adopt: the open-source landscape

```
# Nothing to build on. Five things to learn from.

Five live open-source projects run this product's shape: two people trade, fiat moves between them off-platform, crypto sits in escrow, a human settles the disagreement. None of them can be forked into KudiMarket: four are copyleft, every escrow mechanism is native to Bitcoin or Monero, and the token this market actually trades is USDT. This page is only the build-versus-adopt decision and the ledger question behind it. The threat model lives in the fiat-leg document; the law lives in the regulatory brief.

  

The three decisions on this page

  

    

**The pattern**
A textbook, not a codebase. Bisq, Haveno, RoboSats, Mostro and lnp2pBot prove the flow works end to end: escrow, bonds, timeouts, dispute desks. Take the flow and the dispute design. What they cannot give us is a fiat leg our users will accept, an escrow mechanism for tokens on Tron or Ethereum, or a licence we can build on.

    

**The ledger**
Undecided, and it decides other things. Four candidates. The pick determines how many sources of truth a trade has, whether the dual-write problem exists at all, and where the concurrency bugs live. Two-week spike, with the tests named below.

    

**The real risk**
Not here. The ledger is a solved-in-the-open problem with four candidates. The fiat leg, where assertions come from people who can lie and no rail gives us a status API, is the product, and it has its own document.

  

## The prior art is real. Read it; do not build on it.

Every row was read from that project's own repository page on 6 Oct 2026. Star counts drift and are here to show scale of attention, not health.

  

| Project | Stars | Licence | Chain | Pattern worth stealing |
| --- | --- | --- | --- | --- |
| Bisq | 5,100 | AGPL-3.0 | Bitcoin | Multisig escrow with human arbitration. Account-age witness: reputation is tied to a hash of the trader's bank account, so a serial scammer keeps losing the account, not just the profile. |
| Haveno | 1,400 | AGPL-3.0 | Monero | Arbitration where the arbitrator never has access to funds. The judge reads the case; the money was never theirs to move. |
| RoboSats | 1,021 | AGPL-3.0 | Bitcoin · Lightning | Fidelity bonds instead of reputation. Both sides lock value; the loser's pays the counterparty. Bonds raise the price of cheating but do not forbid it, and the 50/50 split for unresolvable disputes has a failure mode of its own. Both below. |
| Mostro | 334 | GPL-3.0-or-later | Bitcoin · Lightning | Bond is a separate row and lifecycle from escrow, never conflated. Encrypted chat written once and never edited, so it works as dispute evidence. House rule: when in doubt, release, a slogan we cannot adopt until the loss rule exists. |
| lnp2pBot | 292 | MIT | Bitcoin · Lightning | The whole flow, smallest and readable: both parties must confirm, a timeout runs, then a human solver sees everything. Cancel is only legal when both sides agree. |

Licences: four of five are copyleft; only lnp2pBot is MIT, and Mostro moved from MIT to GPL-3.0-or-later, so only its old releases keep MIT terms. Chains: four are Bitcoin or Lightning, Haveno is Monero-only, so both reasons not to fork hold independently: the licences block it, and the mechanisms would not port even if they did.

  

**Why a bond does not forbid cheating**

  

    

Expected value of cheating: EV = p·V − (1−p)·B, where p is the chance of getting away with it, V the value stolen, B the bond forfeited.

    

A bond sized at 3% of the trade only loses the cheater money when their chance of getting away sits under about 3%, which needs near-certain detection. At any realistic success rate a bond is a cost of doing business, not a wall. And an unresolvable dispute settled 50/50 pays whichever party is better at manufacturing unresolvable disputes.

    

An earlier version of this page called bonds "net-negative by arithmetic". Retracted. Bonds tax cheating; they do not forbid it. Size the bond against trade value, publish the forfeiture rule, price a residual loss rate into the fee, and never treat the bond as a substitute for the loss rule.

  

## The mechanisms do not transfer. Custody is still a choice.

This market trades USDT, not bitcoin. Stablecoins are roughly 43% of sub-Saharan crypto transaction volume, and Nigerian P2P, the liquidity Ghana's market will pull from, runs about 40% stablecoin.

  

**What that means for the escrow**

  

    
      
- (a) Bitcoin multisig is a feature of Bitcoin script. Two-of-three escrow with an arbitrator key, and Lightning hold invoices, exist because that chain has them. USDT on Tron has no hold invoice. On Ethereum the equivalents are contracts: a Safe-style multisig, an escrow contract, a hash-time lock.
      
- (b) Keys in our wallet or a contract is not a free design choice. It changes the key-theft surface, and it is filed. The sandbox application requires "a clearly defined custody or asset control model" (3(3)(e)(i)), and if the answer is a contract, the addendum requires "independent third-party audit reports for all smart contracts, protocols, or automated execution systems". So this decision has cost and lead time, and it belongs before the ledger work rather than after it. It does not change the fiat-leg fraud model: forged proofs, reversals and third-party payers exist either way.
    
  

## Custody decisions, restored after they went missing in the document split.

Escrow means our keys hold a trader's crypto between match and release. These five were written for the first version of this page and then dropped when the page was split, which is exactly how a decision disappears. They are back, with the application requirements attached.

  

**Custody checklist, first cut**

  

    
      
- (a) Hot/cold split and a daily hot-wallet ceiling sized against real trade sizes, not averages.
      
- (b) Two-person approval on withdrawals above a threshold; no single human can empty the escrow. 3(3)(e)(ii) asks for governance arrangements on private-key management and transaction authorisation, so this is filed, not internal.
      
- (c) Withdrawal holds longer than release windows, because a reversal can arrive after the trade is green.
      
- (d) Reconciliation twice a day, on-chain against ledger, with an alert when the two disagree. 3(3)(e)(iii) adds transaction monitoring, blockchain analytics and sanctions screening, and those vendors are named in the application.
      
- (e) Segregation versus one pooled wallet. The addendum asks for evidence that client assets are segregated from proprietary funds, and a single omnibus escrow wallet is the opposite of that. Per-trade addresses, or a written case for pooling that the SEC rules on.
      
- (f) Failure and termination. 3(5) requires a winding-up and failure management plan, 12(1) stops us ceasing operations without SEC approval, and 11(2) lets the SEC terminate on written notice at any time. What happens to in-flight trades when we are switched off belongs in this checklist.
    
  

## And the system we build on, which nobody has picked yet.

Everything above decides what to build. None of it decides what to build it with, and that decision is open: the current scaffold is a hand-rolled TypeScript monorepo with a ledger package and tests, and no application framework behind it. This is a build-versus-adopt question of the same kind as the one this page answers for escrow, and it is not yet researched.

  

**Open decision · the system under the product**

  

    

Criteria that follow from the rest of the project rather than from taste: a licence we can build on without a copyleft obligation reaching into our own code; self-hostable, because a regulated operator cannot depend on a vendor's managed tier for its trade ledger; PostgreSQL-compatible, since the ledger decision above assumes one database is plausible; a write-once event log we can hash-chain for dispute evidence; server-rendered or progressively enhanced so the product works on budget Android handsets over poor mobile data; and an audit trail we can show a regulator, which is a vendor feature as much as ours.

    

What is not yet done: no shortlist has been researched, and nothing should be chosen before the custody and ledger decisions land, because both constrain what the application framework has to support. This is the next research task, not a conclusion.

  

## The ledger pick decides where the hard problems live

  

**Sources of truth, and the concurrency that has to be right**

  

    
      
- (1) Three copies, not two. The app database holds trade state, a ledger service holds balances, and a contract escrow holds on-chain state. Co-locating Postgres and the app removes the dual-write, but the chain is still a third copy that can diverge from both.
      
- (2) Postgres is not "a few hundred lines". The deferred constraint trigger is the easy part. The hard parts are non-negative balances under concurrent releases, idempotent retries, and pending or held funds, which is two-phase escrow. Formance and TigerBeetle ship those primitives; we would be writing them.
      
- (3) Immutability by privilege does not survive a DBA. Revoking UPDATE and DELETE stops application code, and an invariant enforced in JavaScript stops nobody with a database console. Hash-chain the entries and anchor the head outside the database, with personal data kept erasable: that is the Data Protection Act problem [fiat-leg.html] as much as a security one.
    
  

## Four candidates. Two of them are serious.

  

    

**Plain Postgres
 no new service
 lowest ops cost**

    

      
        
- (→) A deferred constraint trigger refuses any posting that unbalances an account, in the database, below application code.
        
- (→) One database means balance posting and the trade-state transition commit together, so there is no dual-write to lose sync.
        
- (!) Concurrent releases, retries and two-phase holds are ours to write and to test. That is the cost of the boring option, and it is not small.
        
- (!) You own the semantics: no Numscript, no built-in bi-temporality, no ecosystem.
      
    

    
Why it might win anyway. Our volume is thousands of trades a day. The other options buy guarantees we may not need at a price we pay from day one.

  

  

    

**Formance Ledger
 MIT · Postgres
 richest semantics**

    

      
        
- (✓) Licence: MIT, read from the repository's licence file and v2 release note on 6 Oct 2026. The licence-change worry does not apply.
        
- (✓) Double-entry at the storage layer, Numscript for declarative escrow postings, bi-temporality for backfills.
        
- (!) Production usage is supported through the official Kubernetes operator, per the repository's own documentation. That is a specialised cluster from day one, the exact cost used to dismiss TigerBeetle.
        
- (!) It is a separate service, so every trade becomes a dual-write and the ownership rule becomes mandatory rather than avoidable.
      
    

    
Verdict: the strongest semantics, with an operational price that must be counted honestly. Worth a spike before it is worth an adoption.

  

  

    

**Blnk
 Apache-2.0 · Go
 closest shape, unproven**

    

      
        
- (✓) Double-entry ledger built for wallets and payment systems: inflight balances, snapshots, reconciliation, overdraft control.
        
- (✓) Postgres 16 underneath, Apache-2.0, self-hostable, at v0.15.4 when read on 6 Oct 2026.
        
- (!) Requires Redis for distributed balance locks: an extra service whose failure stalls the ledger.
        
- (!) Young project with a commercial cloud behind it. Scale track record not established; read its issues before trusting it with balances.
      
    

    
Verdict: the closest match to our domain of the four, and the one nobody has read yet. Vetting it costs a day.

  

  

    

**TigerBeetle
 Apache 2.0 · Zig
 overkill at our volume**

    

      
        
- (✓) Append-only immutability in the database itself, correctness fuzzed in CI by deterministic simulation testing.
        
- (✓) Two-phase transfers and linked atomic groups are primitives, which is what escrow is. This is the concurrency answer.
        
- (!) No SQL, no HTTP, custom binary protocol, no authentication at all. Reach the port and you can write.
        
- (!) Separate ledger per asset; managed hosting is invitation-only. Built for a million transfers a second.
      
    

    
Verdict: extraordinary engineering aimed at a problem we do not have yet. Revisit if volume ever demands it.

  

## What happens next, in order.

  
- Ten questions to counsel and to the SEC's pre-submission channel, three of which gate everything: which activity category P2P escrow falls under, what custody model and chain we file, and whether admission is viable against six admitted trading platforms. Written up in legal.html [legal.html].
  
- Finish the fiat-leg rules: per-rail reversal and freeze behaviour, the loss rule with numbers, release tiers that fit inside caps the SEC will approve. fiat-leg.html [fiat-leg.html] holds the open parts.
  
- Settle the stablecoin custody model, chain, and vendor set, because the application needs all three. Keys in our wallet or a contract that can only pay buyer-or-seller; Tron or Ethereum, which changes the audit scope and the freeze exposure; and the analytics, sanctions and custody vendors we are required to name (3(3)(e)). This sits ahead of the ledger spike, and ahead of the framework decision below, because both depend on it.
  
- Two-week ledger spike: plain Postgres against Formance on one suite that must pass concurrent double-release without a negative balance, retried requests that do not double-post, and a crash mid-trade that leaves no half-released escrow. Pick on what fails first, then write the ownership rule the winner implies into the constitution.
  
- Read the prior art for what transfers: lnp2pBot end to end, Mostro's anti-abuse bond document, RoboSats issue 39 on forged screenshots.

---

Sources, retrieved 6 Oct 2026. Star counts, licences and chains read from each project's repository page on that date. Stablecoin share of regional volume: Chainalysis sub-Saharan Africa reports (2024, 2025) and IMF Article IV analysis for Nigeria (Jun 2026). Regulatory facts, licences, AML obligations, sandbox caps and the Act 843 retention duties are cited clause by clause in legal.html [legal.html] rather than repeated here.
 Revision note: this page replaced an original that claimed no open-source P2P escrow existed, recommended a payment orchestrator this product cannot use, and never mentioned Act 1154. An outside review caught all three. Two later reviews caught more: a headline that implied the five projects were buildable, a false "every one is Bitcoin-only", bond economics described as arithmetic rather than as a calculation with terms in it, a reversal window stated as fact when it is unknown, a green definition stronger than the data supports, a ledger-before-state ordering that was backwards, and this page doing five documents' work at once. The threat model, the dispute desk and the legal brief now live in their own pages.
```

---

# Document 4 of 5 — The system we build on

```
# Everything above decides what to build. None of it decides what to build it with.

Sixteen candidate systems checked against the project's own constraints, licences read from each repository's licence file and star counts and last-push dates read from the GitHub API on 7 Oct 2026. The marketplace-shaped platforms all fail on licence or staleness, which removes the obvious candidates. What survives is a choice between two application frameworks, and the deciding question is not the trader-facing app: it is which one builds a dispute, freeze and suspicious-report console fastest, on a licence that does not reach into our code.

  

The recommendation in five lines

  

    

**The pick**
Laravel with Filament, on one PostgreSQL database. Filament is the strongest permissively-licensed operations console in open source, and this product's bottleneck is operations: disputes, freezes, suspicious-report queues, reconciliation, and a regulator's inspection.

    

**The runner-up**
Payload 3, if staying in TypeScript matters more than console velocity. MIT, self-hostable, generated admin panel, PostgreSQL through Drizzle. Its admin is shaped like content management, so every dispute and AML queue is custom, and the framework is younger with a v2-to-v3 breaking-change history.

    

**The market builders**
All three fail. Vendure core is GPLv3, Medusa's role-based access control sits behind a commercial licence, and Sharetribe is under a bespoke community licence that is not OSI open source and has not been pushed since May.

    

**The cost of the pick**
The TypeScript scaffold is replaced, and PHP joins the stack. The scaffold is small (one ledger package and twenty-one tests) and its arithmetic was already flagged as belonging outside application code, so little is lost. Crypto signing stays in a small separate service either way.

    

**What it does not decide**
The ledger, the chain, and the custody model. This page assumes PostgreSQL stays the system of record, which keeps the co-located-ledger option open. Choosing Formance later would split the database, and choosing a contract escrow would put on-chain state in front of both.

  

## Criteria, in the order they bite

  

**Derived from the product, not from taste**

  

    
      
- (1) A licence that does not reach into our code. We intend to keep the product proprietary. Copyleft is not disqualifying in principle, but it converts every future hire and investor conversation into a licence question, so it costs real money in this market.
      
- (2) Self-hostable, with no mandatory managed tier. A regulated operator showing an inspector its trade ledger cannot depend on a vendor's hosted database for the record of truth.
      
- (3) An operations console, not just an API. Disputes, freezes, suspicious-transaction reporting, reconciliation and customer complaints are all queue-and-form work behind a login. That is the majority of internal tooling in this product.
      
- (4) PostgreSQL, because the ledger decision assumes it. Co-locating balances and trade state in one transaction is the cheapest way to remove the dual-write problem, and that option disappears if the framework only speaks to something else.
      
- (5) An append-only event log we control. Dispute evidence has to be hash-chainable and erasable on a schedule, which is a data-model choice, and the framework must not fight it.
      
- (6) Mobile-first on poor connections. Budget Android handsets, intermittent data, small bundles. An HTML-first or progressively enhanced front end beats a heavy client-rendered app.
      
- (7) Localisation, scoped to what we actually serve. English at launch, one locale. French only if the CFA corridor is admitted, Chinese only if RMB is admitted. Building three empty locale files is not localisation.
      
- (8) Queue and job infrastructure. Reconciliation, notifications, freeze timers and the twenty-four-hour reporting clock are all scheduled work, and they are table stakes rather than differentiators.
    
  

## The evidence

  

| System | Licence | Stars | Last push | Verdict |
| --- | --- | --- | --- | --- |
| Laravel + Filament | MIT (framework and Filament) | 85,053 / 32,205 | 7 Oct 2026 | Recommended. Filament is the best-licensed operations console available; queues, policies, notifications and localisation are first-class. |
| Payload 3 | MIT | 45,116 | 7 Oct 2026 | Runner-up. TypeScript, PostgreSQL through Drizzle, self-host anywhere, admin translated into thirty-plus locales. Admin shaped like a CMS. |
| Django | BSD-3-Clause | 91,338 | 7 Oct 2026 | Viable. The admin panel is free and batteries-included, and PostgreSQL is first-class. Weaker for bespoke queue workflows than Filament, and Python is a second stack language for this team. |
| Vendure | GPLv3 core | 8,505 | 6 Oct 2026 | Excluded. Changed from MIT to GPLv3 at v3 in June 2024; the last MIT release reached end of life on 31 December 2024. Modifications must be released under GPLv3. |
| Medusa | MIT core, enterprise RBAC | 36,629 | 6 Oct 2026 | Excluded. Open-core: the role-based access control we would want for a dispute and compliance console is enterprise-licensed material. Commerce-shaped anyway. |
| Sharetribe | Community Public Licence 1.0 | 2,448 | 11 May 2026 | Excluded. A bespoke source-available licence, not OSI open source, with trademarks reserved, and no push since May. |
| Saleor | BSD-3-Clause | 23,418 | 6 Oct 2026 | Licence is clean, but it is a storefront and catalogue system with GraphQL at the centre. Trade escrow, disputes and chat are all custom, and so is the console. |
| ERPNext / Frappe | GPL-3.0 | 39,856 | 6 Oct 2026 | Excluded for the product. Worth revisiting for the company's own books after launch, where copyleft does not reach into anything we sell. |
| Next.js | MIT | 143,218 | 7 Oct 2026 | A rendering layer, not an application framework with an operations console. Relevant only as Payload's host. |
| NestJS | MIT | 76,797 | 7 Oct 2026 | Clean licence and excellent structure, but no admin console, so every internal tool is hand-built. |
| AdonisJS | MIT | 19,143 | 5 Oct 2026 | Viable TypeScript alternative, smaller ecosystem, no operations console. |
| Keystone | MIT | 9,977 | 28 Sep 2026 | Content-management shaped with a quieter maintenance cadence. Wrong shape for a trade console. |
| Hyperledger Cactus | Apache-2.0 | 416 | 6 Oct 2026 | Not a competitor: an interconnector framework for regulated blockchain gateways. Possible fit later if the custody architecture needs connectors. |

How this table was produced. Licence, star count and last-push date read from the GitHub REST API for each repository on 7 Oct 2026, and the licence text itself read from each repository's licence file. Stars measure attention, not health, and drift daily. Three verdicts above rest on licence text rather than the API's field: Medusa's licence file carves out "Enterprise Edition materials identified in ENTERPRISE-LICENSE.md", Vendure's own licence FAQ states GPLv3 applies absent a separate written agreement, and Sharetribe's file is the Sharetribe Community Public License 1.0.

## Why the operations console decides it

  

    

Read the harm catalogue in the fiat-leg document and count how much of it lands on a human behind a login: disputes, freeze handling and its appeal path, the tipping-off boundary, suspicious-transaction review, the twenty-four-hour reporting clock, reconciliation, complaints, and regulator inspection. That is the product's real internal surface, and it is a CRUD-plus-queue application, not a marketplace front end.

    

That is why the commerce platforms lose on their own terms. Their advantage is catalogue, cart and checkout, none of which we need: there is no basket, and the settlement is escrow. Their cost is an admin console that is either copyleft, enterprise-licensed, or content-shaped. Meanwhile the trade itself is the part every one of them leaves to us, because escrow, disputes and evidence rules do not exist as products to buy.

  

## What the pick actually commits us to

  

**If we take Laravel with Filament**

  

    
      
- (→) One database. PostgreSQL holds trades, escrow state, balances and the hash-chained event log, so the co-located ledger option stays open and the dual-write problem does not exist yet.
      
- (→) Two deployment targets, not one. A Laravel application and console, plus a small crypto-signing service (TypeScript or Go) that holds keys behind a cloud KMS and answers release requests over an internal API. It is separated on purpose: it is the part that must survive a compromise of everything else.
      
- (→) The trader front end is server-rendered and progressively enhanced. Listings, trade view, chat and dispute filing work as HTML over poor mobile data, with JavaScript as an enhancement rather than a requirement.
      
- (→) One locale at launch: English, wired into the interface framework, so adding French or Chinese later is a translation file rather than an audit of the whole product.
      
- (→) Queues do the waiting. Reconciliation, freeze timers, notifications and the reporting clock are jobs, not cron hacks.
      
- (→) What we lose: the TypeScript scaffold and its tests, and a second language in the stack. Both are cheap now and expensive later, which is the argument for paying them now.
    
  

## What happens next

  
- One week, one vertical slice, in the recommended stack: a trade that goes pending, a dispute that gets filed, an arbiter view that lists it, and a resolution that posts a hash-chained entry. The test is whether the console is faster to build than the same thing in Payload, not which one is nicer.
  
- Decide the chain and custody model first if the week is to be spent well: the signing service's interface depends on it, and the application asks for it in the same form.
  
- Keep the ledger question separate and later. PostgreSQL first, because co-location is the cheapest correct answer at our volume, with the spike tests named in the build-versus-adopt document.
  
- Do not buy a marketplace engine. Write down, before the slice starts, which parts of the vertical slice came from a library and which we wrote, so this page can be judged in six months rather than argued about now.

---

Sources, retrieved 7 Oct 2026. GitHub REST API for licence field, star count and last-push date across the repositories listed; licence text read from each repository's licence file where the API's field was ambiguous or absent. Payload's PostgreSQL adapter and self-hosting claims from the Payload documentation for version 3. Vendure's licence position from the licence FAQ in the Vendure repository and the vendor's own announcement of the MIT-to-GPLv3 change. Medusa's open-core split from the repository licence file and README. Sharetribe's licence from its licence file.
 Limits of this page. No system here has been run by us; this is a licence, shape and activity assessment, not a benchmark. Performance on budget Android, queue behaviour under load, and the real ergonomics of building a dispute console are exactly what the one-week slice is for. Laravel's framework and Filament were assessed from their repositories and from our own production experience with that stack elsewhere, which is a genuine advantage and also a bias worth naming. The ledger, custody and chain decisions are assumed, not settled, and each one can invalidate parts of this.
```

---

# Document 5 of 5 — The business, as decided

```
# The business, as decided. Not as it was described.

Twelve decisions, taken in one sitting on 7 Oct 2026 and recorded here so nobody has to remember a conversation. Three of them overturned what the earlier documents assumed. KudiMarket is not a marketplace that matches strangers: it is the first vendor, buying and selling currency out of its own pool, with a hand-vetted vendor network around it. It trades seven currencies, not one. And it quotes its own rates rather than listing prices.

  

What changed, and why it matters

  

    

**We are the counterparty**
The biggest change. KudiMarket buys currency from traders and sells to other traders out of its own float. That makes us a bureau de change and money-remittance business, not a matching platform, and it moves the first regulator to the Bank of Ghana with customer funds to be segregated from our own money.

    

**Seven currencies**
Cedi, naira, CFA, RMB, dollars, pounds and euros. An earlier version of this work said cedi only, and another said exclude RMB. Both were wrong: the demand is a Nigerian in Ghana selling naira, and an exporter paying a Chinese supplier.

    

**Our book, two currencies**
We hold and quote cedi and naira. Dollars, pounds, euros, the CFA franc and the Chinese yuan are all vendor supply from day one, and we never hold them. RMB was briefly added to our own book and taken back out: the flow barely happens, and holding it means carrying a Chinese-side receiving arrangement for a rare trade. Cedi is the pivot; everything is quoted against it.

    

**A closed, vetted start**
Vendors are admitted by hand, starting with the team and a short list of mobile-money agents who already trade this way offline. Automation comes later, once there is volume and capacity. A closed supply side is also the easiest shape to put in front of a regulator as a pilot.

    

**Two promises, not one**
"Checked" and "KudiMarket handled". A checked vendor is one whose identity, accounts and history we verified, and we do not promise delivery. A KudiMarket-handled trade is one we took the other side of, so our money is at risk and delivery is ours. At launch, the team holds the second label.

  

## The decision log

  

| Question | Decided | Why |
| --- | --- | --- |
| Who buys? | Anyone who registers, including non-Ghanaians living here | The volume is a Nigerian resident selling naira. It also means every buyer is a customer we must identify, which is the price of being a money business. |
| What is KudiMarket's own side? | We are the first vendor, with a modest float | It solves the cold-start problem that kills marketplaces: nobody will list if they think nobody will trade. Our own book is the first liquidity. |
| How do we earn? | A spread on the rate we quote | It is how every Ghanaian already buys currency, so it needs no explanation, and it scales with volume rather than with fees. |
| Which currencies do we hold? | Cedi and naira only | The pair that actually moves here, both directions, both sides on mobile money. RMB was tried and dropped for the same reason: negligible volume against a real compliance dependency. |
| Which pairs do we quote? | Cedi against naira, both directions | A two-way board is what a bureau de change has always had. Dollars are the next candidate for our book, but on evidence of real volume rather than in advance. |
| How does a trade settle? | Both ways: fiat straight across, or crypto held in escrow | Some traders trust each other with money moving directly; some will not. The escrow route is the one the SEC licence covers, so it is gated separately. |
| Which blockchain? | Tron only, for USDT | One chain, cheap transfers, no contract of our own. Still owed research: whether local USDT actually sits on Tron. |
| Who holds the crypto? | Our wallets, a fresh address per trade | Nothing to audit for the regulator, and one frozen address cannot stop every trade on the platform. |
| What licence do we apply for? | Trading platform, not exchange | It is the honest description of the product, and it matches six admitted firms. The SEC decides, not us; the pre-submission conversation decides it fastest. |
| What stops someone walking away? | A small deposit from both sides, once vendor trading starts | Not needed on our own book, because we are the risk. For vendor-to-vendor trades it is the only thing that makes cheating more expensive than honesty. |
| What does a badge mean? | Two levels: checked, and KudiMarket handled | One badge that reads "safe to send money to" is a guarantee we have not priced. Two labels let us sell the strong promise on the trades we control and be factual on the rest. |
| Who gets admitted as a vendor? | Hand-vetted individuals and registered businesses | Identity, address, own-name account, source of funds, Ghana Card match, and company details. Mobile-money agents with years of this trade offline are the natural first pool. |

One rule that follows from the company decision. A sole-proprietor registration in Ghana is not a separate legal person: the money goes to a personal account, our payer-name check must match that person's name rather than the trading name, and any claim against them is a claim against them personally. So the badge records the legal form and the exact account name that must appear as the payer.

## What this does to the regulatory plan

  

    

The order has changed. The Bank of Ghana comes first, because the launch business is buying and selling currency with our own money, which is the activity the FX and remittance rules cover. The Securities and Exchange Commission matters second and specifically, because it covers the escrow route and because we hold traders' crypto. The sandbox application is still worth filing, and 4(5) still requires the pre-submission conversation before it, but it no longer gates the main business.

    

Three obligations follow from being the counterparty and none of them exist in the marketplace version: customers' money must be segregated from our own float and reconciled; every buyer and vendor is a customer to be identified, monitored and, where suspicious, reported; and our own treasury positions in three currencies are a business risk the licence application will ask about.

    

And nothing exotic is in our book. Cedi and naira both move by mobile money, which is why they are the right two. Every other currency, RMB included, is somebody else's inventory: the platform lists it, the vendor supplies it, and we never hold it. That keeps the treasury in two currencies, the licence narrow, and the Chinese-side question out of the launch entirely.

  

## Still open, and the order I would take them in

  
- Whether dollars come into our book, and on what evidence: which currency a trader asks for most often that we currently cannot fill, and how often. This is the only plausible next currency, and it should be added to the board on volume rather than in advance.
  
- How a non-mobile-money leg reaches the trader, since dollars, euros and pounds do not arrive by MoMo: bank wire, card, wallet or cash hand-off, and what the trader is told to expect and when.
  
- The claim window and the dispute desk: how long a trader has to raise a problem, who decides, in which hours, and in which languages. Our own harm catalogue says this is the product's real internal surface.
  
- Identity: which KYC source and what liveness check, now that every buyer is a customer. Mobile-money registration names often differ from legal names, and our payer-name matching has to be designed with that, not bolted on after.
  
- Limits: per-trade and per-vendor caps, which are also the numbers we would propose to a regulator.
  
- Vendor-to-vendor fees: zero to grow the network, or a small fee that pays for the dispute desk.
  
- Entity and ownership, because it decides whether we are a foreign applicant under the 30% rule and what the Bank of Ghana application looks like.

---

Provenance. Every row was decided in an interactive session on 7 Oct 2026, and this page exists because those decisions were otherwise living only in conversation. Where a decision overrode earlier written work, the footer of the affected document should be updated; where this page and another document disagree, this page is the newer statement of the product and the other document is the older reasoning.
 Not yet decided, and therefore not built: the claim window, the desk's hours and languages, the identity source, the limits, vendor fees, the entity, and the Chinese-side RMB arrangement. This document is a record of decisions taken, not of a finished plan.
```

---


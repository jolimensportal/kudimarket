-- 014: whose money is this?
--
-- WHY THIS FILE EXISTS
--
-- brand/product.html says the first regulator is the Bank of Ghana, and gives the reason: KudiMarket holds
-- customer funds that must be segregated from its own money. "We are a bureau de change and money
-- remittance business... which moves the first regulator to the Bank of Ghana with customer funds to be
-- segregated from our own money."
--
-- Segregation of funds is the FIRST question that regime asks, and nothing in the product could answer
-- it. check_balances() proves the ledger adds up. integrity_report proves the hash chains. Between them
-- they say that the arithmetic is right, and not one thing about WHOSE money is being held.
--
-- WHAT CAN BE PROVEN, and it is worth something
--
-- One question about this ledger has a real answer: does value ever move between us and a customer
-- without passing through a trade escrow account?
--
-- If it never does, then customer money is ring-fenced BY CONSTRUCTION rather than by policy, and that is
-- a stronger statement than a policy document makes. Every movement goes platform -> trade:<id>:escrow ->
-- counterparty, or counterparty -> trade:<id>:*_deposit -> platform. The escrow is the only door.
--
-- commingled_transfers counts any ledger transaction where value arrives at one side and leaves at the
-- other with no trade escrow in the transaction. Zero is the answer we want and the one worth being able
-- to prove.
--
-- WHAT CANNOT BE PROVEN, and saying so is the point
--
-- The fiat leg is not posted to this ledger at all. A trade with no crypto leg moves no money here: the
-- deposits are escrowed, and the naira and cedi that actually change hands arrive off-system and are
-- attested by operations. So for the two currencies that are actually the business -- cedi and naira --
-- there is no in-ledger record to point a regulator at, and this report says so in its own words rather
-- than letting a reader assume the question was fully answered.
--
-- fiat_legs_posted is 0 and is meant to be alarming. It becomes 1 when fiat movements are posted, and
-- until then it is the honest measure of the gap.


create or replace function segregation_report()
returns table(
  asset text,
  customer_available numeric,
  customer_escrow numeric,
  our_float numeric,
  commingled_transfers bigint,
  fiat_legs_unposted bigint
)
language sql stable as $$
  with money as (
    select a.asset, a.owner, a.kind, a.balance
      from account a
     -- The chain account is the outside world, not a holder of anything: it is the other side of every
     -- real bank and mobile-money movement. Counting it as customer money would make every total a
     -- fiction, and counting it as our float would make the float a function of the day.
     where a.owner <> 'chain'
  ),
  ours as (
    select asset, coalesce(sum(balance), 0) as float
      from money
     where owner = 'platform'
     group by asset
  ),
  customers as (
    select
      asset,
      coalesce(sum(balance) filter (where kind = 'available'), 0) as available,
      -- An escrow account is named for the trade it belongs to. That IS the ring-fence: deposits for
      -- trade 7 sit in trade:7:*_deposit and can only be moved by a transition on trade 7.
      coalesce(sum(balance) filter (where kind = 'escrow'), 0) as escrow
      from money
     where owner <> 'platform'
     group by asset
  ),
  -- A transaction that puts value on one side of the counterparty line and takes it off the other, with
  -- no trade escrow taking part, is commingling: money went between us and a customer outside the door
  -- that is supposed to be the only one.
  commingled as (
    select p1.asset, count(*) as transfers
      from ledger_tx l
      join posting p1 on p1.tx_id = l.id and p1.amount > 0
      join account a on a.id = p1.account_id
      join posting p2 on p2.tx_id = l.id and p2.amount < 0
      join account b on b.id = p2.account_id
     where (a.owner = 'platform') <> (b.owner = 'platform')
       and a.owner not like 'trade:%'
       and b.owner not like 'trade:%'
       -- NOT commingling: platform <-> chain is our own float meeting the outside world, which is what
       -- funding and paying out the float looks like. The first version of this counted it, and the count
       -- came back at 7 -- every float funding in the suite -- which would have made the number meaningless
       -- and the alarm permanent.
       and a.owner <> 'chain'
       and b.owner <> 'chain'
       and not exists (
         select 1 from posting p3
           join account c on c.id = p3.account_id
          where p3.tx_id = l.id and c.owner like 'trade:%'
       )
     group by p1.asset
  ),
  -- How many trades have a settlement this ledger cannot evidence at all?
  --
  -- Not "are there fiat postings" -- deposits ARE posted, and they are cedi. The thing that is missing is
  -- the LEG: on a trade with no crypto leg, nothing here records that the naira reached the seller or the
  -- cedi reached the buyer. Operations attests it, off-system.
  --
  -- Counting fiat postings conflates the deposit with the settlement and produced 36, which sounds like a
  -- ledger that does record fiat legs. It does not. This counts the trades it cannot speak for, which is
  -- the honest number and is every trade in the product today.
  fiat as (
    select count(*) as legs from trade where crypto_amount is null
  )
  select
    coalesce(c.asset, o.asset, m.asset) as asset,
    coalesce(c.available, 0) as customer_available,
    coalesce(c.escrow, 0) as customer_escrow,
    coalesce(o.float, 0) as our_float,
    coalesce(m.transfers, 0) as commingled_transfers,
    (select legs from fiat) as fiat_legs_unposted
    from customers c
    full outer join ours o on o.asset = c.asset
    full outer join commingled m on m.asset = coalesce(c.asset, o.asset)
   order by 1;
$$;

comment on function segregation_report() is
  'Whose money the ledger holds, per asset. customer_escrow is ring-fenced by trade:<id>:escrow accounts and commingled_transfers must be 0. fiat_legs_unposted counts trades whose settlement this ledger cannot evidence at all -- every fiat trade, because the leg changes hands off-system and operations attests it.';

revoke all on function segregation_report() from public;
grant execute on function segregation_report() to kudi_app, kudi_ops;


-- ---------------------------------------------------------------- the integrity page
--
-- Dropped and recreated rather than CREATE OR REPLACE, for the same reason 009 and 012 did: REPLACE may
-- only append columns, and these belong next to the other money figures rather than at the end of a list
-- nobody reads. EVERY existing column is written out again, including 010's, because a view that forgets
-- one does not fail -- it just stops showing it.
drop view integrity_report;

create view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select verify_offer_chain()) as offer_chain_ok,
       (select verify_rate_chain())  as rate_chain_ok,
       -- 010's column, dropped in the first draft of this file. Rebuilding a view means writing out every
       -- column it had, not the ones this file is about, and forgetting one removes it from the page for
       -- everyone -- which is how a verification chain quietly stopped being visible on the integrity
       -- report.
       (select verify_verification_chain()) as verification_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs,
       -- Should always be zero. Non-zero means a trade was created outside the constructors above,
       -- which is the one way to hold a state no event chain vouches for.
       (select count(*) from trade t
         where not exists (select 1 from trade_event e where e.trade_id = t.id)) as unanchored_trades,
       (select count(*) from offer)     as offers,
       (select count(*) from rate_card) as rates,
       (select count(*) from verification_event where event = 'applied')   as verification_pending,
       (select count(*) from verification_event where event = 'verified') as verification_verified,
       -- Segregation. The COUNTS only: commingled movements and unevidenced fiat legs are numbers of
       -- events, so a total across assets is meaningful.
       --
       -- The MONEY deliberately does not appear here, and the first version of this file added it as
       -- sum(customer_available) over every asset. That summed 199,838 pesewas to 29,000 USDT minor units
       -- and printed 228,838 -- a figure in a currency that does not exist. Segregation is asked per
       -- currency, and segregation_report() answers per currency; a scalar column cannot, and a wrong total
       -- on a regulator-facing page is worse than no total. Read it from segregation_report() directly.
       (select coalesce(sum(commingled_transfers), 0) from segregation_report()) as commingled_transfers,
       -- Every fiat trade in the product, and it should be alarming. See the header of this file.
       (select coalesce(max(fiat_legs_unposted), 0) from segregation_report()) as fiat_legs_unposted;

revoke all on integrity_report from public;
grant select on integrity_report to kudi_app, kudi_ops, kudi_trader;
-- 009: the offer book — the supply side of the market.
--
-- WHY THIS FILE EXISTS
-- Everything built so far is one side. KudiMarket sits in the middle as the seller, a trader buys, and
-- that is the whole product. But KudiMarket does not hold every cedi and every naira that will change
-- hands, so the other side has to exist: a trader who has naira and wants cedi, naming a price they will
-- accept. Without this, KudiMarket is the only seller and its float is the only supply.
--
-- WHAT AN OFFER IS, AND WHAT IT IS NOT
-- An offer is a statement of intent with an expiry. It moves no money, holds nothing, and is not a trade.
-- It is NOT a price that KudiMarket must honour at that number: brand/product.html is explicit that
-- "KudiMarket is not a marketplace that matches strangers... it quotes its own rates rather than listing
-- prices", and that this is what makes the business a bureau de change rather than a matching platform,
-- which is what puts the Bank of Ghana first rather than the SEC.
--
-- So min_rate is a WALK-AWAY, not a price. It answers "below what will I say no", and the actual rate on
-- any trade comes from KudiMarket's rate card at the moment the offer is accepted. The locked product
-- preview says the same thing in the only wording it uses: an offer set "0.7% below KudiMarket's rate"
-- carries the warning "You are very likely to wait longer" -- a warning about SPEED, not about getting a
-- different price. An offer below the board is still cleared at the board, later.
--
-- FOUR PROPERTIES, each from a specific way this gets attacked:
--
-- 1. Append-only. An offer is never updated or deleted. Cancelling inserts a cancellation; the original
--    statement "I will sell ₦500,000 at no worse than 0.00670" survives forever. Editing it in place
--    would let a seller rewrite what they said after a buyer had already acted on it.
-- 2. Hash-chained per seller, like trade_event and rate_card. An offer that can be quietly rewritten is
--    the cleanest scam in a peer-to-peer book: move your walk-away to whatever clears, then hide the move.
-- 3. Nothing is escrowed at listing time. Listing is not a commitment and holds no balance, because a
--    listing that took custody of customer funds would be the custody question brand/legal.html is still
--    open with the SEC about. Deposits happen when a trade is created, and only on vendor-to-vendor.
-- 4. Backdating refused, exactly like set_rate, for the same reason: an offer that was "live" when you
--    wanted it to be.
--
-- Also in this file, because it is the same defect this file exposes:
--
-- 009. widening trade.rate to match the rate card
-- trade.rate was numeric(38,10) while rate_card.rate is numeric(38,18). An agreement priced from the card
-- was therefore STORED at a different number than the card held -- the NGN/GHS leg came back as
-- 0.0067567568 instead of 0.006756756756756756. Not a money bug at 148.20, where ten places is ample,
-- and not a chain break, because canonical_event never included the rate. But "the trade is priced by the
-- card" is a claim this product makes in writing, and here the stored figure quietly disagreed with it.
-- Widening, not tightening: no existing value changes meaning and no existing test may break.
-- trade_view selects t.* and trade_quote is built on trade_view, so Postgres refuses the widening while
-- they exist ("cannot alter type of a column used by a view or rule"). Both are dropped and rebuilt here,
-- verbatim, with their grants reissued. integrity_report reads trade directly and is untouched.
drop view trade_quote;
drop view trade_view;

alter table trade alter column rate type numeric(38,18);

create view trade_view as
select t.*,
       case when state in ('released','resolved_release')            then 'green'
            -- disputed is deliberately absent: it is an open question, not a verdict. The brand
            -- law is plum for pending and red only for money actually going back to one side,
            -- which is what cancelled and resolved_refund mean.
            when state in ('cancelled','resolved_refund')             then 'red'
            else 'plum' end as colour
  from trade t;

create view trade_quote as
select id, trade_type, seller, buyer, state, crypto_asset, crypto_amount, fiat_asset, fiat_amount, rate,
       case when crypto_asset is null then null::numeric
            else round(fiat_amount::numeric / crypto_amount, 12) end as actual_rate,
       fiat_amount / NULLIF(rate, 0::numeric) as crypto_implied,
       colour
  from trade_view;

-- Grants on the dropped views went with them. Reissued rather than assumed, because a missing grant shows
-- up as "permission denied for table trade_quote" on an operations page and nothing else.
grant select on trade_view, trade_quote to kudi_app, kudi_ops, kudi_trader;


-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


-- ---------------------------------------------------------------- 1. the table
create table offer (
  id             bigserial primary key,
  seller         text         not null check (length(trim(seller)) > 0),
  -- What the seller is putting up, and what they want for it. "I have NGN, I want GHS."
  base_asset     text         not null references asset(code),
  quote_asset    text         not null references asset(code),
  -- Minor units of base, matching how every other amount in this schema is stored. One place converts
  -- to major units, and it is the application at quote time, because the decimals bug lives there.
  amount         numeric(78,0) not null check (amount > 0),
  -- Whole units of quote per one whole unit of base. The walk-away, NOT the price that will be used.
  min_rate       numeric(38,18) not null check (min_rate > 0),
  created_at     timestamptz  not null,
  expires_at     timestamptz  not null,
  prev_hash      bytea,
  hash           bytea,
  constraint offer_pair_distinct check (base_asset <> quote_asset),
  -- An offer that expires at the moment it was made is not an offer.
  constraint offer_expires_after_creation check (expires_at > created_at)
);

create index offer_by_seller on offer(seller, created_at desc);
create index offer_pair on offer(base_asset, quote_asset, created_at desc);

-- Append-only, exactly like the ledger, the event log and the rate card.
create trigger offer_append_only before update or delete on offer
  for each row execute function forbid_mutation();
create trigger offer_no_truncate before truncate on offer
  for each statement execute function forbid_mutation();


-- ---------------------------------------------------------------- 2. the chain
-- One canonical string per offer, written and verified through this one function, so the two can never
-- disagree about what was said.
--
-- The numeric scale is normalised the way canonical_event does it, and for the same reason found by a
-- failing test rather than by reading: the literal 0.0067 arrives with a short scale and the column hands
-- it back with 18, so the hash written on the way in would never match the hash read on the way out.
create function canonical_offer(p_id bigint, p_seller text, p_base text, p_quote text,
                                p_amount numeric, p_min_rate numeric, p_created timestamptz,
                                p_expires timestamptz)
returns text language sql immutable as $$
  select p_id::text || '|' || p_seller || '|' || p_base || '|' || p_quote
         || '|' || p_amount::numeric(78,0)::text
         || '|' || p_min_rate::numeric(38,18)::text
         || '|' || extract(epoch from p_created)::text
         || '|' || extract(epoch from p_expires)::text
$$;


-- ---------------------------------------------------------------- 3. verify
-- Per seller, so one busy seller's history cannot make another's unverifiable. The first offer for a
-- seller has no predecessor, which is a starting point rather than a gap.
create or replace function verify_offer_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare r record; v_prev bytea; v_expect bytea; v_seller text;
begin
  -- v_prev is reset at the start of each seller's run.
  --
  -- The first version carried one v_prev through a single loop ordered by seller, id and never cleared
  -- it, so the first offer of every seller after the first was compared against the PREVIOUS seller's
  -- last hash and reported a break. kudi-core's own tests did not catch it because they used one seller
  -- per section; the application test publishes as three different traders and found it immediately.
  -- A per-seller chain needs a per-seller running hash, and the reset is the whole of it.
  for r in select seller, id, base_asset, quote_asset, amount, min_rate, created_at, expires_at,
                   hash, prev_hash
             from offer order by seller, id loop
    if r.seller is distinct from v_seller then
      v_prev := null;
      v_seller := r.seller;
    end if;
    if r.prev_hash is distinct from v_prev then return false; end if;
    v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                 convert_to(canonical_offer(r.id, r.seller, r.base_asset, r.quote_asset, r.amount,
                                            r.min_rate, r.created_at, r.expires_at), 'UTF8'),
                 'sha256');
    if v_expect is distinct from r.hash then return false; end if;
    v_prev := r.hash;
  end loop;
  return true;
end $$;


-- ---------------------------------------------------------------- 4. publish and cancel
-- Publishing. p_min_rate is the walk-away; nothing is held and no balance moves.
create function publish_offer(p_seller text, p_base text, p_quote text, p_amount numeric,
                              p_min_rate numeric, p_lifetime interval default interval '15 minutes')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_prev bytea; v_hash bytea; v_id bigint; v_now timestamptz := clock_timestamp();
begin
  if p_seller is null or trim(p_seller) = '' then
    raise exception 'seller required: an offer with nobody''s name on it cannot be defended later';
  end if;
  if p_base = p_quote then raise exception 'a pair needs two different assets'; end if;
  if not exists (select 1 from asset where code = p_base) then raise exception 'unknown base %', p_base; end if;
  if not exists (select 1 from asset where code = p_quote) then raise exception 'unknown quote %', p_quote; end if;
  if p_amount is null or p_amount <= 0 then raise exception 'offer amount must be positive'; end if;
  if p_min_rate is null or p_min_rate <= 0 then raise exception 'minimum rate must be positive'; end if;
  if p_lifetime is null or p_lifetime <= interval '0 seconds' then
    raise exception 'an offer needs a lifetime; one that never expires is a standing instruction to hold funds';
  end if;
  if p_lifetime > interval '1 day' then
    raise exception 'an offer may live at most one day; these move faster than that';
  end if;

  -- Backdating guard, same five seconds of clock skew set_rate allows and for the same reason.
  if v_now < now() - interval '5 seconds' then raise exception 'backdating refused'; end if;

  select hash into v_prev from offer where seller = p_seller order by id desc limit 1;

  -- The id is taken from the sequence BEFORE the insert so the hash can be computed first and written
  -- with the row. The obvious alternative -- insert, then update the hash -- is refused by this file's
  -- own append-only trigger, which is the trigger doing its job: an offer row is never updated, not even
  -- by the function that made it. Found by the first run of the offer tests, not by reading.
  v_id := nextval('offer_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_offer(v_id, p_seller, p_base, p_quote, p_amount,
                                              p_min_rate, v_now, v_now + p_lifetime), 'UTF8'),
                   'sha256');

  insert into offer(id, seller, base_asset, quote_asset, amount, min_rate, created_at, expires_at,
                    prev_hash, hash)
       values (v_id, p_seller, p_base, p_quote, p_amount, p_min_rate, v_now, v_now + p_lifetime,
               v_prev, v_hash);
  return v_id;
end $$;


-- A WITHDRAWAL IS ITS OWN RECORD, not an UPDATE to the offer.
--
-- The first version set offer.cancelled_at and then rewrote offer.hash, which meant two UPDATE
-- statements against a table whose own trigger forbids updates -- solved, badly, by disabling the trigger
-- inside the function. Disabling an append-only trigger from application code is the exact thing the
-- trigger exists to prevent, and a function that raises between the disable and the enable leaves the
-- table permanently writable.
--
-- This way the offer row is NEVER updated. The statement the seller made survives untouched, and the
-- withdrawal sits beside it as a second immutable fact, which is also the honest shape: "I offered this"
-- and "I took it back" are two events, not one row with a field that changed.
create table offer_withdrawal (
  offer_id     bigint primary key references offer(id),
  withdrawn_by text        not null check (length(trim(withdrawn_by)) > 0),
  withdrawn_at timestamptz not null default now(),
  -- Why it left the book. 'withdrawn' by the seller, 'filled' by an acceptance.
  reason       text        not null check (reason in ('withdrawn', 'filled'))
);

create trigger offer_withdrawal_append_only before update or delete on offer_withdrawal
  for each row execute function forbid_mutation();
create trigger offer_withdrawal_no_truncate before truncate on offer_withdrawal
  for each statement execute function forbid_mutation();


-- Cancelling. Inserts a fact; touches nothing that already exists.
create function cancel_offer(p_offer bigint, p_actor text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare o offer%rowtype;
begin
  if p_actor is null or trim(p_actor) = '' then raise exception 'actor required'; end if;

  select * into o from offer where id = p_offer;
  if not found then raise exception 'no such offer %', p_offer; end if;
  if o.seller <> p_actor then
    raise exception 'actor % may not withdraw an offer belonging to %', p_actor, o.seller;
  end if;

  -- The primary key does the work: a second withdrawal of the same offer collides, so an offer cannot be
  -- taken back twice and cannot be withdrawn after it has been filled.
  insert into offer_withdrawal(offer_id, withdrawn_by, reason) values (p_offer, p_actor, 'withdrawn');
exception when unique_violation then
  raise exception 'offer % is no longer open', p_offer;
end $$;


-- Accepting. This is where an offer becomes a trade, and where the walk-away is finally tested against
-- the board: if the rate card has moved past it, the offer does not clear and the seller keeps their
-- currency. The trade is priced by the CARD, never by min_rate -- that is the whole point of the
-- distinction, and a bug here would quietly turn KudiMarket into a matching platform.
create function accept_offer(p_offer bigint, p_buyer text, p_actor text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare o offer%rowtype; v_rate numeric; v_id bigint; v_now timestamptz := clock_timestamp();
begin
  if p_actor is null or trim(p_actor) = '' then raise exception 'actor required'; end if;
  if p_buyer is null or trim(p_buyer) = '' then raise exception 'buyer required'; end if;

  select * into o from offer where id = p_offer;
  if not found then raise exception 'no such offer %', p_offer; end if;
  if exists (select 1 from offer_withdrawal where offer_id = p_offer) then
    raise exception 'offer % is no longer open', p_offer;
  end if;
  if o.expires_at <= v_now then raise exception 'offer % expired at %', p_offer, o.expires_at; end if;
  if o.seller = p_buyer then raise exception 'you cannot accept your own offer'; end if;

  select rate into v_rate from current_rate
   where base_asset = o.base_asset and quote_asset = o.quote_asset;
  if v_rate is null then raise exception 'we have not published a rate for %/%', o.base_asset, o.quote_asset; end if;

  -- The walk-away, tested. Below it the seller does not trade, and the buyer is told why rather than
  -- being quietly matched to a worse price than the seller agreed to.
  if v_rate < o.min_rate then
    raise exception 'offer % will not clear: the rate is % and the seller asked for no less than %',
      p_offer, v_rate, o.min_rate;
  end if;

  -- Priced by the card.
  v_id := create_fiat_trade(o.seller, p_buyer, null, null, o.base_asset, o.amount, v_rate,
                            'vendor_vendor', o.quote_asset);

  -- Accepting consumes the offer, so the same liquidity cannot be sold twice. Recorded as 'filled', which
  -- says what happened rather than calling every exit a withdrawal.
  insert into offer_withdrawal(offer_id, withdrawn_by, reason) values (p_offer, p_actor, 'filled');
  return v_id;
end $$;


-- The live board. Expired offers are excluded without being deleted, because an offer that quietly
-- vanished is indistinguishable from one that never existed.
create or replace view open_offer as
  select o.id, o.seller, o.base_asset, o.quote_asset, o.amount, o.min_rate, o.created_at, o.expires_at,
         (o.expires_at <= now()) as expired
    from offer o
   where o.expires_at > now()
     and not exists (select 1 from offer_withdrawal w where w.offer_id = o.id);


-- ---------------------------------------------------------------- 5. privileges
do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_chain') then create role kudi_chain nologin; end if;
end $$;

grant select on offer, offer_withdrawal, open_offer, current_rate, rate_card, asset to kudi_app, kudi_ops, kudi_trader;

-- The offer chain joins integrity_report, so a rewritten walk-away shows on the regulator-facing page
-- rather than only in a function nobody calls.
--
-- A VIEW. This file first tried to add the column with CREATE OR REPLACE FUNCTION, which created a SECOND
-- object sharing the name: a function and a view can coexist because they live in different namespaces,
-- so nothing errored, "select offer_chain_ok from integrity_report" went on resolving to the view with its
-- old columns, and the failure read as "009 did not load" rather than "you addressed the wrong kind of
-- object". The stray function is dropped and the column added to the view.
drop function if exists integrity_report();

-- Dropped rather than replaced, because CREATE OR REPLACE VIEW may only APPEND columns: it refused with
-- "cannot change name of view column balance_mismatches to offer_chain_ok" when the chain flags were put
-- together. The order matters to whoever reads this page, so all four chain flags stay adjacent and the
-- grants are reissued.
drop view integrity_report;

create view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select verify_offer_chain()) as offer_chain_ok,
       (select verify_rate_chain())  as rate_chain_ok,
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
       (select count(*) from rate_card) as rates;

-- Replacing the view took its grants with it.
revoke all on integrity_report from public;
grant select on integrity_report to kudi_app, kudi_ops, kudi_trader;

-- A trader may publish and withdraw their own offer, and may accept somebody else's. Neither publishes
-- a price: publish_offer takes a walk-away, and the price on any trade comes from the rate card, which
-- only kudi_pricing can write.
grant execute on function publish_offer(text, text, text, numeric, numeric, interval),
                          cancel_offer(bigint, text),
                          accept_offer(bigint, text, text),
                          verify_offer_chain() to kudi_trader;

-- The app has no business publishing offers on anybody's behalf, and none for cancelling either.
grant execute on function verify_offer_chain() to kudi_app, kudi_ops;
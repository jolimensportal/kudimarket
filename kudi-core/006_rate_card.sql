-- 006: the rate card — KudiMarket's own price, versioned and provable.
--
-- WHY THIS FILE EXISTS
-- Every screen in the product hangs off one number. The board puts KudiMarket in the middle at our
-- rate; every trade we open as the counterparty is priced from it. There was no place for that number
-- to live: trade.rate records what was agreed on a single trade, and trade_policy carries windows and
-- caps. Nothing held our own price, so nothing could prove what it was.
--
-- Four properties, each of which exists because of a specific way this gets attacked:
--
-- 1. Append-only. A rate is never updated. Inserting a new one supersedes the old by effective_from,
--    so "the rate was 148.20 at 14:03" survives forever. Updating in place would make today's price
--    the only price that ever existed.
-- 2. Hash-chained per pair, like trade_event. A price that can be quietly rewritten after the fact is
--    the cleanest scam in an FX book: move the rate to fill your own trade badly, then hide the move.
--    Same defence as the ledger, so it gets the same treatment and a slot in integrity_report.
-- 3. kudi_pricing, separate from kudi_ops. Ops resolve disputes; pricing sets our price. If one
--    account could do both, a compromised desk account could reprice the book and then judge its own
--    trades. Segregation of duties, enforced in the database rather than in a policy document.
-- 4. Backdating refused. Without this, anyone with set_rate() could insert a rate that was "live" at
--    the moment of a trade they had just filled badly.
--
-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


-- ---------------------------------------------------------------- 1. the table
create table rate_card (
  id             bigserial primary key,
  base_asset     text not null references asset(code),
  quote_asset    text not null references asset(code),
  -- Whole units of quote per one whole unit of base: 1 GHS = 148.20 NGN. Dimensionless on purpose.
  -- Minor units are a property of the asset, not of the price, and converting here would spread the
  -- decimals bug across every row of the card instead of confining it to one place at quote time.
  rate           numeric(38,18) not null check (rate > 0),
  effective_from timestamptz  not null default now(),
  set_by         text         not null check (length(trim(set_by)) > 0),
  note           text,
  prev_hash      bytea,
  hash           bytea,
  constraint rate_pair_distinct check (base_asset <> quote_asset)
);

-- One current rate per pair is a consequence of "greatest effective_from wins", not a constraint.
-- Two rows sharing an effective_from would make "current" ambiguous, so that is refused outright.
create unique index rate_card_one_per_moment
  on rate_card(base_asset, quote_asset, effective_from);

create index rate_card_pair_lookup on rate_card(base_asset, quote_asset, effective_from desc);

-- Append-only, exactly like the ledger and the trade event log.
create trigger rate_card_append_only before update or delete on rate_card
  for each row execute function forbid_mutation();
create trigger rate_card_no_truncate before truncate on rate_card
  for each statement execute function forbid_mutation();


-- ---------------------------------------------------------------- 2. the chain
-- One canonical string per rate, written and verified through this one function. The chain is per
-- pair: GHS/NGN changing does not invalidate NGN/GHS history.
--
-- The cast to numeric(38,18) is load-bearing and was found by a failing test, not by reading. The
-- literal 148.20 arrives with scale 2, so p_rate::text is "148.20"; the column hands it back as
-- "148.200000000000000000". Same number, different text, so the hash written on the way in never
-- matches the hash recomputed on the way out and the chain reads as broken from the first row.
-- Normalise the scale here and the two can never disagree. The ledger's canonical_postings does the
-- same thing with numeric(78,0).
create function canonical_rate(p_base text, p_quote text, p_rate numeric, p_from timestamptz, p_who text)
returns text language sql immutable as $$
  select p_base || '|' || p_quote || '|' || p_rate::numeric(38,18)::text || '|'
         || extract(epoch from p_from)::text || '|' || p_who
$$;


-- ---------------------------------------------------------------- 3. publishing a rate
-- SECURITY DEFINER so kudi_pricing can insert without write access to the table, which keeps the
-- table itself unwritable from any application role.
create function set_rate(p_base text, p_quote text, p_rate numeric, p_actor text,
                         p_effective_from timestamptz default null, p_note text default null)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare
  v_from timestamptz; v_prev bytea; v_hash bytea; v_id bigint;
  v_tolerance interval := interval '5 seconds';
begin
  if p_actor is null or trim(p_actor) = '' then
    raise exception 'actor required: a rate with nobody''s name on it cannot be defended later';
  end if;
  if p_base = p_quote then raise exception 'a pair needs two different assets'; end if;
  if p_rate is null or p_rate <= 0 then raise exception 'rate must be positive'; end if;
  if not exists (select 1 from asset where code = p_base) then raise exception 'unknown base %', p_base; end if;
  if not exists (select 1 from asset where code = p_quote) then raise exception 'unknown quote %', p_quote; end if;

  -- The backdating guard. Small tolerance for clock skew between us and the database, no more.
  v_from := coalesce(p_effective_from, now());
  if v_from < now() - v_tolerance then
    raise exception 'backdating refused: % is more than %s in the past', v_from, v_tolerance;
  end if;

  select hash into v_prev from rate_card
   where base_asset = p_base and quote_asset = p_quote
   order by effective_from desc, id desc limit 1;

  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
              convert_to(canonical_rate(p_base, p_quote, p_rate, v_from, p_actor), 'UTF8'), 'sha256');

  insert into rate_card(base_asset, quote_asset, rate, effective_from, set_by, note, prev_hash, hash)
       values (p_base, p_quote, p_rate, v_from, p_actor, p_note, v_prev, v_hash)
    returning id into v_id;

  return v_id;
end $$;


-- ---------------------------------------------------------------- 4. reading it
-- "Current" is derived, never stored. Nothing to keep in sync, nothing to get wrong.
create view current_rate as
select distinct on (base_asset, quote_asset)
       base_asset, quote_asset, rate, effective_from, set_by, id
  from rate_card
 where effective_from <= now()
 order by base_asset, quote_asset, effective_from desc, id desc;

-- Both directions of a pair, because the board shows a cedi buyer and a naira seller, not one of them.
create view rate_board as
select c.base_asset, c.quote_asset, c.rate, c.effective_from, c.set_by,
       case when d.base_asset is null then null
            else (select rate from current_rate r
                   where r.base_asset = c.quote_asset and r.quote_asset = c.base_asset)
       end as inverse_rate
  from current_rate c
  left join current_rate d
    on d.base_asset = c.quote_asset and d.quote_asset = c.base_asset;


-- ---------------------------------------------------------------- 5. verification
create or replace function verify_rate_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare
  pair record; e record; v_prev bytea; v_expect bytea;
begin
  for pair in select distinct base_asset, quote_asset from rate_card loop
    v_prev := null;
    for e in select * from rate_card
              where base_asset = pair.base_asset and quote_asset = pair.quote_asset
              order by effective_from, id loop
      if e.prev_hash is distinct from v_prev then return false; end if;
      v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_rate(e.base_asset, e.quote_asset, e.rate, e.effective_from, e.set_by), 'UTF8'),
                   'sha256');
      if v_expect is distinct from e.hash then return false; end if;
      v_prev := e.hash;
    end loop;
  end loop;
  return true;
end $$;


-- One call for the regulator, and the console, answers: does anything in here fail to prove itself?
-- rate_chain_ok is appended last on purpose: adding a column is safe, changing one is not.
create or replace view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs,
       (select count(*) from trade t
         where not exists (select 1 from trade_event e where e.trade_id = t.id)) as unanchored_trades,
       (select verify_rate_chain())  as rate_chain_ok,
       (select count(*) from rate_card) as rates;


-- ---------------------------------------------------------------- PRIVILEGES, LAST AND COMPLETE
-- A new SECURITY DEFINER function means PUBLIC gets EXECUTE unless revoked, and this one writes rows
-- to a table nobody else may write. Revoke everything, then grant the minimum to each role.
revoke execute on all functions in schema public from public;
revoke all on rate_card, current_rate, rate_board from public;

-- Read-only. The board is a read surface; the app must not be able to reprice anything.
grant select on rate_card, current_rate, rate_board to kudi_app, kudi_ops;
-- A function is EXECUTE, never SELECT, however much it reads.
grant execute on function verify_rate_chain() to kudi_app, kudi_ops;

-- kudi_pricing may publish a rate and nothing else. It cannot settle a trade, resolve a dispute,
-- mint a deposit, or read balances — a pricing decision should not be a balance-reading decision.
do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_pricing') then create role kudi_pricing nologin; end if;
end $$;
grant execute on function set_rate(text, text, numeric, text, timestamptz, text) to kudi_pricing;
-- 010: seller verification -- how a trader becomes somebody you can trade with.
--
-- WHY THIS FILE EXISTS
-- 009 made publish_offer refuse an unverified seller, which is where the "Checked" tier stops being
-- decoration. But nothing anywhere could ever SET trader.verified_at: no application path, no console
-- screen. So every real trader was permanently unverified, could never list an offer, and was told
-- "you need to be checked" with no way forward and nobody who could action it. The gate existed and the
-- door did not.
--
-- What a seller is checked against is brand/product.html, not invention: "Identity, address, own-name
-- account, source of funds, Ghana Card match, and company details", and the rule that follows from the
-- company decision -- a sole proprietor is not a separate legal person, so "the money goes to a personal
-- account, our payer-name check must match that person".
--
-- A RECORD, NOT A FLAG
-- trader.verified_at is a single timestamp that says nothing about who checked, when, or on what. A
-- verification is the thing a trader will later point at when somebody asks why they were trusted, and
-- it is the thing a regulator would ask about. So the substance is an append-only, hash-chained log per
-- trader, the same treatment the ledger, the trade events, the rate card and the offer book all get.
--
--   applied   the trader asked, with their details
--   verified  operations checked them and said so
--   refused   operations said no, and WHY -- a refusal with no reason is a dead end
--
-- THE CHAIN IS THE TRUTH. trader.verified_at is written in the same statement as the decision so queries
-- and screens can read one column, but every check that decides anything reads the chain, and a test
-- asserts the two never disagree. Two sources of truth is how a system starts lying about one of them.


-- ---------------------------------------------------------------- 1. the table
create table verification_event (
  id           bigserial primary key,
  trader_email text        not null references trader(email),
  event        text        not null check (event in ('applied', 'verified', 'refused')),
  -- The details on an application; the reason on a refusal. Never overwritten, so the exact words a
  -- trader wrote when they applied survive whatever was decided about it later.
  narrative    text,
  -- Whoever acted: the trader applying, or a staff identity deciding. Never a role, always a name.
  actor        text        not null check (length(trim(actor)) > 0),
  created_at   timestamptz not null,
  prev_hash    bytea,
  hash         bytea,
  -- A refusal must explain itself, and an application must carry something to check.
  constraint verification_narrative_present
    check (event = 'applied' or narrative is null or length(trim(narrative)) > 0)
);

create index verification_by_trader on verification_event(trader_email, id);

create trigger verification_event_append_only before update or delete on verification_event
  for each row execute function forbid_mutation();
create trigger verification_event_no_truncate before truncate on verification_event
  for each statement execute function forbid_mutation();


-- ---------------------------------------------------------------- 2. the chain
create function canonical_verification(p_id bigint, p_trader text, p_event text, p_narrative text,
                                       p_actor text, p_created timestamptz)
returns text language sql immutable as $$
  select p_id::text || '|' || p_trader || '|' || p_event || '|' || coalesce(p_narrative, '')
         || '|' || p_actor || '|' || extract(epoch from p_created)::text
$$;


create or replace function verify_verification_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare r record; v_prev bytea; v_expect bytea; v_trader text;
begin
  -- v_prev resets per trader, the same lesson verify_offer_chain learned the hard way.
  for r in select trader_email, id, event, narrative, actor, created_at, hash, prev_hash
             from verification_event order by trader_email, id loop
    if r.trader_email is distinct from v_trader then
      v_prev := null;
      v_trader := r.trader_email;
    end if;
    if r.prev_hash is distinct from v_prev then return false; end if;
    v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                 convert_to(canonical_verification(r.id, r.trader_email, r.event, r.narrative,
                                                  r.actor, r.created_at), 'UTF8'),
                 'sha256');
    if v_expect is distinct from r.hash then return false; end if;
    v_prev := r.hash;
  end loop;
  return true;
end $$;


-- Where a trader stands. Derived, never stored: "latest event wins" is a query, and a column to keep in
-- step with a chain is a column that will eventually not be in step.
create or replace view verification_status as
select d.trader_email, d.event, d.narrative, d.actor, d.created_at,
       case when d.event = 'verified' then true else false end as is_verified,
       case when d.event = 'applied' then true else false end as is_pending
  from verification_event d
  join (select trader_email, max(id) as id from verification_event group by trader_email) m
    on m.id = d.id;


-- ---------------------------------------------------------------- 3. apply and decide
-- The trader applies. Once, and only while unverified: a second application while one is pending is
-- refused rather than stacking, because two open applications and no way to tell which is live is how a
-- queue gets lost.
create function apply_for_verification(p_trader text, p_details text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_prev bytea; v_hash bytea; v_id bigint; v_now timestamptz := clock_timestamp();
declare v_status text;
begin
  if p_trader is null or trim(p_trader) = '' then raise exception 'trader required'; end if;
  if not exists (select 1 from trader where email = p_trader) then
    raise exception 'no such trader %', p_trader;
  end if;
  if p_details is null or length(trim(p_details)) < 40 then
    raise exception 'details required: at least 40 characters of what you would check: identity, address, own-name account, source of funds, Ghana Card';
  end if;

  select event into v_status from verification_status where trader_email = p_trader;
  if v_status = 'applied' then
    raise exception 'already applied: you have an application open';
  end if;
  if v_status = 'verified' then
    raise exception 'already verified';
  end if;

  select hash into v_prev from verification_event where trader_email = p_trader order by id desc limit 1;
  v_id := nextval('verification_event_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_verification(v_id, p_trader, 'applied', trim(p_details),
                                                    p_trader, v_now), 'UTF8'),
                   'sha256');

  insert into verification_event(id, trader_email, event, narrative, actor, created_at, prev_hash, hash)
       values (v_id, p_trader, 'applied', trim(p_details), p_trader, v_now, v_prev, v_hash);
  return v_id;
end $$;


-- Operations decides. Not granted to any trader -- the whole point is that a trader cannot check
-- themselves, which kudi-core/007 asserts and 008 enforces by refusing UPDATE on verified_at.
create function decide_verification(p_trader text, p_verified boolean, p_reason text, p_staff text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_prev bytea; v_hash bytea; v_id bigint; v_now timestamptz := clock_timestamp();
declare v_status text; v_event text;
begin
  if p_staff is null or trim(p_staff) = '' then
    raise exception 'actor required: a decision must name the person who made it';
  end if;
  if not exists (select 1 from trader where email = p_trader) then
    raise exception 'no such trader %', p_trader;
  end if;

  select event into v_status from verification_status where trader_email = p_trader;
  if v_status is distinct from 'applied' then
    raise exception 'nothing to decide: % is %', p_trader, coalesce(v_status, 'not applied');
  end if;

  v_event := case when p_verified then 'verified' else 'refused' end;

  -- A refusal with no reason is a dead end for the trader, and a decision nobody can appeal.
  if not p_verified and (p_reason is null or length(trim(p_reason)) < 10) then
    raise exception 'reason required: say what is missing, so the trader can fix it';
  end if;

  select hash into v_prev from verification_event where trader_email = p_trader order by id desc limit 1;
  v_id := nextval('verification_event_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_verification(v_id, p_trader, v_event, trim(p_reason),
                                                    p_staff, v_now), 'UTF8'),
                   'sha256');

  insert into verification_event(id, trader_email, event, narrative, actor, created_at, prev_hash, hash)
       values (v_id, p_trader, v_event, nullif(trim(p_reason), ''), p_staff, v_now, v_prev, v_hash);

  -- The column, kept in step for the screens that read one field. The chain stays authoritative for every
  -- decision, and a test asserts these two never disagree.
  update trader set verified_at = case when p_verified then v_now else null end, updated_at = now()
   where email = p_trader;

  return v_id;
end $$;


-- ---------------------------------------------------------------- 4. privileges
-- Postgres grants EXECUTE on a new function to PUBLIC by default, and 002's blanket revoke ran BEFORE
-- this file. So without this, decide_verification was callable by anyone -- including a trader deciding
-- their own verification, which is the one thing this whole file exists to prevent. Caught by the test
-- "a trader cannot decide their own application", which failed with an id instead of a refusal.
-- Revoked BY NAME, not with the blanket "revoke execute on all functions" that 002 uses. The blanket form
-- here also took away the grants 001-009 had already been given -- publish_offer, accept_offer, the
-- transition functions -- and the offer suite went from green to 46 failures for no reason connected to
-- verification. Only the functions THIS file creates carry the PUBLIC default, so only those are named.
revoke execute on function apply_for_verification(text, text),
                          decide_verification(text, boolean, text, text),
                          verify_verification_chain(),
                          canonical_verification(bigint, text, text, text, text, timestamptz) from public;

grant select on verification_event, verification_status to kudi_app, kudi_ops, kudi_trader;

-- A trader may ask to be checked. They may not decide anything: decide_verification is not granted here,
-- which is the enforcement rather than the intention.
grant execute on function apply_for_verification(text, text), verify_verification_chain() to kudi_trader;
grant execute on function decide_verification(text, boolean, text, text),
                          verify_verification_chain() to kudi_ops;

-- integrity_report gains the chain. The report is a VIEW, and CREATE OR REPLACE VIEW may only append
-- columns, so it is dropped and rebuilt with the flags together -- see 009 for why that is the only way.
drop view integrity_report;

create view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select verify_offer_chain()) as offer_chain_ok,
       (select verify_rate_chain())  as rate_chain_ok,
       (select verify_verification_chain()) as verification_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs,
       (select count(*) from trade t
         where not exists (select 1 from trade_event e where e.trade_id = t.id)) as unanchored_trades,
       (select count(*) from offer)     as offers,
       (select count(*) from rate_card) as rates,
       (select count(*) from verification_status where is_pending) as verification_pending,
       (select count(*) from verification_status where is_verified) as verification_verified;

revoke all on integrity_report from public;
grant select on integrity_report to kudi_app, kudi_ops, kudi_trader;
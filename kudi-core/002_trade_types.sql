-- 002: trade types, per-type claim windows, deposits, and who-may-do-what.
-- Applies on top of 001_core.sql. Same rules: money moves only through post_tx().
--
-- Trade types
--   p2p                two vendors/users; seller's crypto is escrowed.
--   platform_inventory KudiMarket is one side (owner 'platform', account kind 'available' = our float).
--                      DEVIATION FROM THE BRIEF, on purpose: when the platform is the seller the float
--                      is RESERVED (moved to the trade's reserve account) at 'escrowed'. It is our own
--                      crypto, not third-party escrow. Without it the same float can be promised to two
--                      users, one pays fiat, and the release then fails. Remove the reserve = one branch.
--   vendor_vendor      both sides post a deposit (deposit_bps of the amount). Returned on success.
--                      On a proven failure the at-fault side's deposit goes to the other side.
--
-- Trust boundary: the DB cannot know which human is calling. The app role passes the authenticated
-- user id as p_actor. The DB then enforces WHO may take each step (buyer claims, seller attests, ...)
-- and that platform-side and dispute-resolution steps are only reachable by the ops role.


-- ---------------------------------------------------------------- policy (PLACEHOLDER NUMBERS)
-- The real windows, caps and deposit sizes belong to the fiat-leg document. These are test values.
create table trade_policy (
  trade_type   text primary key check (trade_type in ('p2p','platform_inventory','vendor_vendor')),
  claim_window interval not null,                 -- from paid_attested until release is allowed
  deposit_bps  int not null default 0 check (deposit_bps between 0 and 10000),  -- per side
  max_amount   numeric(78,0)                      -- optional per-trade cap, minor units
);
insert into trade_policy(trade_type, claim_window, deposit_bps, max_amount) values
  ('p2p',                '60 minutes', 0,    null),
  ('platform_inventory', '15 minutes', 0,    null),
  ('vendor_vendor',      '30 minutes', 1000, null);


alter table trade add column trade_type text not null default 'p2p' references trade_policy(trade_type);
alter table trade add constraint trade_platform_party
  check ((trade_type = 'platform_inventory') = (seller = 'platform' or buyer = 'platform'));


alter table trade_event add column actor text;
alter table trade_event add column fault text;


-- ---------------------------------------------------------------- who may do each step
create table trade_rule (
  trade_type text not null references trade_policy(trade_type),
  from_state text not null,
  to_state   text not null,
  actor      text not null check (actor in ('seller','buyer','either','ops','system')),
  primary key (trade_type, from_state, to_state)
);
insert into trade_rule(trade_type, from_state, to_state, actor)
select tp.trade_type, v.f, v.t, v.a
  from trade_policy tp
 cross join (values
   ('open','escrowed','either'),         ('open','cancelled','either'),
   ('escrowed','paid_claimed','buyer'),  ('escrowed','cancelled','either'),
   ('paid_claimed','paid_attested','seller'), ('paid_claimed','disputed','either'),
   ('paid_attested','released','system'),     ('paid_attested','disputed','either'),
   ('disputed','resolved_release','ops'),     ('disputed','resolved_refund','ops')
 ) v(f, t, a);
-- 'seller'/'buyer' mean the party that is that side of THIS trade. If that party is 'platform',
-- only the ops path may act for it.


drop function transition_trade(bigint, text, text, text);
drop function create_trade(text, text, text, numeric);
drop table trade_transition;


drop view trade_view;
create view trade_view as
select t.*,
       case when state in ('released','resolved_release')            then 'green'
            -- A dispute is an open question, not a verdict, so it reads plum like every other
            -- pending state. Red is reserved for money actually going back to one side.
            when state in ('cancelled','resolved_refund')             then 'red'
            else 'plum' end as colour
  from trade t;


create function create_trade(p_seller text, p_buyer text, p_asset text, p_amount numeric,
                             p_type text default 'p2p')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_cap numeric; v_id bigint;
begin
  select max_amount into v_cap from trade_policy where trade_type = p_type;
  if not found then raise exception 'unknown trade type %', p_type; end if;
  if v_cap is not null and p_amount > v_cap then
    raise exception 'amount % exceeds cap % for %', p_amount, v_cap, p_type;
  end if;
  insert into trade(seller, buyer, asset, amount, trade_type)
       values (p_seller, p_buyer, p_asset, p_amount, p_type) returning id into v_id;
  return v_id;
end $$;


create function _p(p_acc bigint, p_asset text, p_amt numeric) returns jsonb language sql immutable as $$
  select jsonb_build_array(jsonb_build_object('account', p_acc, 'asset', p_asset, 'amount', p_amt))
$$;


-- ---------------------------------------------------------------- the one transition function
-- Internal. Not granted to anyone; reached only through transition_trade / ops_transition below.
--   p_ops = false : app path. p_actor must BE the required party and must not be 'platform'.
--   p_ops = true  : ops path. May act for the platform's own side, and for 'ops'/'system' steps.
--   p_fault       : only on resolutions of vendor_vendor trades: 'none' | 'seller' | 'buyer'.
create function _apply_transition(p_trade bigint, p_from text, p_to text, p_idem text,
                                  p_actor text, p_ops boolean, p_fault text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t trade%rowtype; r trade_rule%rowtype; pol trade_policy%rowtype;
  v_ok boolean; v_fault text := coalesce(p_fault, 'none'); v_attested timestamptz;
  s_av bigint; b_av bigint; esc bigint; sd bigint; bd bigint; d numeric := 0; v_post jsonb := '[]'::jsonb;
begin
  if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;    -- replay
  if p_actor is null or p_actor = '' then raise exception 'actor required'; end if;


  select * into t from trade where id = p_trade;
  if not found then raise exception 'no such trade %', p_trade; end if;
  select * into r from trade_rule where trade_type = t.trade_type and from_state = p_from and to_state = p_to;
  if not found then raise exception 'illegal transition % -> % for %', p_from, p_to, t.trade_type; end if;
  select * into pol from trade_policy where trade_type = t.trade_type;


  -- authorisation
  if r.actor = 'ops' then
    if not p_ops then raise exception 'ops only: % -> %', p_from, p_to; end if;
  elsif r.actor = 'system' then
    if not p_ops and p_actor <> 'system' then raise exception 'system step: % -> %', p_from, p_to; end if;
  else
    if p_ops then
      v_ok := (r.actor = 'seller' and t.seller = 'platform')
           or (r.actor = 'buyer'  and t.buyer  = 'platform')
           or (r.actor = 'either' and 'platform' in (t.seller, t.buyer));
    else
      v_ok := p_actor <> 'platform' and case r.actor
                when 'seller' then p_actor = t.seller
                when 'buyer'  then p_actor = t.buyer
                else p_actor in (t.seller, t.buyer) end;
    end if;
    if not v_ok then
      raise exception 'actor % may not do % -> % on trade %', p_actor, p_from, p_to, p_trade;
    end if;
  end if;


  -- fault is only meaningful on vendor_vendor resolutions
  if v_fault not in ('none','seller','buyer') then raise exception 'bad fault %', v_fault; end if;
  if v_fault <> 'none' and (t.trade_type <> 'vendor_vendor' or p_to not in ('resolved_release','resolved_refund')) then
    raise exception 'fault applies only to vendor_vendor resolutions';
  end if;
  if (p_to = 'resolved_release' and v_fault = 'buyer') or (p_to = 'resolved_refund' and v_fault = 'seller') then
    raise exception 'fault % is inconsistent with %', v_fault, p_to;
  end if;


  -- the release guard: claim window per trade type
  if p_to = 'released' then
    select max(created_at) into v_attested from trade_event where trade_id = t.id and to_state = 'paid_attested';
    if v_attested is null or clock_timestamp() < v_attested + pol.claim_window then
      raise exception 'claim window open until %', v_attested + pol.claim_window;
    end if;
  end if;


  update trade set state = p_to, updated_at = now()
   where id = p_trade and state = p_from returning * into t;                 -- compare-and-swap
  if not found then
    if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
    raise exception 'trade % is not in state %', p_trade, p_from;
  end if;
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault)
       values (p_trade, p_idem, p_from, p_to, p_actor, case when v_fault = 'none' then null else v_fault end);


  s_av := get_account(t.seller, 'available', t.asset);
  b_av := get_account(t.buyer,  'available', t.asset);
  esc  := get_account('trade:' || t.id, 'escrow', t.asset);
  if t.trade_type = 'vendor_vendor' then
    d := div(t.amount * pol.deposit_bps, 10000);
    if d > 0 then
      sd := get_account('trade:' || t.id || ':seller_deposit', 'escrow', t.asset);
      bd := get_account('trade:' || t.id || ':buyer_deposit',  'escrow', t.asset);
    end if;
  end if;


  if p_to = 'escrowed' then
    v_post := _p(s_av, t.asset, -t.amount) || _p(esc, t.asset, t.amount);
    if d > 0 then
      v_post := v_post || _p(s_av, t.asset, -d) || _p(sd, t.asset, d)
                       || _p(b_av, t.asset, -d) || _p(bd, t.asset, d);
    end if;
  elsif p_to in ('released','resolved_release') then
    v_post := _p(esc, t.asset, -t.amount) || _p(b_av, t.asset, t.amount);
    if d > 0 then
      v_post := v_post || _p(sd, t.asset, -d) || _p(bd, t.asset, -d)
                       || _p(case when v_fault = 'seller' then b_av else s_av end, t.asset, d)
                       || _p(b_av, t.asset, d);
    end if;
  elsif p_to in ('cancelled','resolved_refund') and p_from <> 'open' then
    v_post := _p(esc, t.asset, -t.amount) || _p(s_av, t.asset, t.amount);
    if d > 0 then
      v_post := v_post || _p(sd, t.asset, -d) || _p(bd, t.asset, -d)
                       || _p(s_av, t.asset, d)
                       || _p(case when v_fault = 'buyer' then s_av else b_av end, t.asset, d);
    end if;
  end if;


  if jsonb_array_length(v_post) > 0 then
    perform post_tx(p_idem || ':ledger', 'trade_' || p_to, t.id, v_post);
  end if;
end $$;


-- App path: p_actor is the authenticated end user (or 'system' for the release job).
create function transition_trade(p_trade bigint, p_from text, p_to text, p_idem text,
                                 p_actor text, p_fault text default null)
returns void language sql security definer set search_path = public, pg_temp as $$
  select _apply_transition(p_trade, p_from, p_to, p_idem, p_actor, false, p_fault)
$$;


-- Ops path: Filament console. p_ops_user is the staff member, recorded in trade_event.actor.
create function ops_transition(p_trade bigint, p_from text, p_to text, p_idem text,
                               p_ops_user text, p_fault text default null)
returns void language sql security definer set search_path = public, pg_temp as $$
  select _apply_transition(p_trade, p_from, p_to, p_idem, p_ops_user, true, p_fault)
$$;


-- ---------------------------------------------------------------- privileges
do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_ops') then create role kudi_ops nologin; end if;
end $$;
revoke execute on all functions in schema public from public;
grant select on trade_view, trade, trade_event, trade_policy, trade_rule, account, ledger_tx, posting to kudi_app, kudi_ops;
grant execute on function create_trade(text,text,text,numeric,text),
                          transition_trade(bigint,text,text,text,text,text),
                          verify_chain(), check_balances() to kudi_app;
grant execute on function ops_transition(bigint,text,text,text,text,text),
                          verify_chain(), check_balances() to kudi_ops;
grant execute on function ledger_deposit(text,text,numeric,text) to kudi_chain;

-- 005: every trade is chained from birth, so no state is ever self-asserted.
--
-- WHY THIS FILE EXISTS
-- 004 chained trade_event and pinned each trade's live state to its last event. But a trade that had
-- never moved had no events at all, so there was nothing to compare against: rewriting the state of
-- any freshly created trade with superuser rights left verify_trade_chain() returning true and the
-- integrity page reporting a clean ledger. Found by testing the claim in 004 rather than by a
-- failing test, which is the only reason anyone noticed.
--
-- kudi_app is SELECT-only on trade, trade_event, ledger_tx, posting and account -- confirmed with
-- has_table_privilege, not assumed -- so the two constructors below are the only ordinary door to a
-- new trade. The fix is for them to write the first event themselves: seq 1, from_state 'none' to
-- whatever state the row actually received, hashed and linked like every other event. Afterwards no
-- trade has an unauthenticated state.
--
-- That makes 004's escape hatch the wrong default and it is removed below. verify_trade_chain() used
-- to shrug at a trade with no events, on the reasoning that an untouched trade would otherwise read
-- as tampering. Now that every trade is born chained, that reasoning is stale in both directions: a
-- trade with no events can only mean the constructors were bypassed, and it must fail. The count is
-- still reported, so a failure explains itself instead of just going red.
--
-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


-- ---------------------------------------------------------------- 1. create_fiat_trade writes its own first event
-- Signature unchanged, so callers, grants and tests are untouched. Only the tail is new.
drop function if exists create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text);

create function create_fiat_trade(p_seller text, p_buyer text, p_crypto_asset text,
                                  p_crypto_amount numeric, p_fiat_asset text, p_fiat_amount numeric,
                                  p_rate numeric, p_type text default 'platform_inventory',
                                  p_deposit_asset text default 'GHS')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_cap numeric; v_id bigint; v_state text; v_now timestamptz; v_ev bytea;
begin
  if p_crypto_asset is not null and (p_crypto_amount is null or p_crypto_amount <= 0) then
    raise exception 'crypto leg needs an amount';
  end if;
  if p_crypto_asset is not null and p_rate is null then
    raise exception 'a crypto leg needs the agreed rate';
  end if;
  if p_fiat_amount is null or p_fiat_amount <= 0 then
    raise exception 'a trade needs a positive fiat amount';
  end if;
  select max_amount into v_cap from trade_policy where trade_type = p_type;
  if not found then raise exception 'unknown trade type %', p_type; end if;
  if v_cap is not null and coalesce(p_fiat_amount, p_crypto_amount) > v_cap then
    raise exception 'amount % exceeds cap % for %', coalesce(p_fiat_amount, p_crypto_amount), v_cap, p_type;
  end if;
  -- state comes back from the row, not from a literal, so the event cannot disagree with whatever
  -- the column default did. There is no trade.created_at to copy -- the column does not exist, the
  -- table has updated_at -- so the event timestamps itself.
  insert into trade(seller, buyer, asset, amount, crypto_asset, crypto_amount,
                    fiat_asset, fiat_amount, rate, deposit_asset, trade_type)
       values (p_seller, p_buyer, p_crypto_asset, p_crypto_amount,
               p_crypto_asset, p_crypto_amount, p_fiat_asset, p_fiat_amount, p_rate,
               p_deposit_asset, p_type)
    returning id, state into v_id, v_state;
  v_now := now();

  -- The first event. 'none' is the sentinel for "no state yet": trade_event.from_state is NOT NULL,
  -- so it cannot be left empty. Actor 'platform' records who opened the trade record, which on a
  -- vendor-to-vendor trade is the matching engine, not either party.
  v_ev := digest(convert_to(canonical_event(v_id, 1, 'none', v_state, 'platform', null, v_now), 'UTF8'),
                 'sha256');
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault, created_at, seq, prev_hash, hash)
       values (v_id, 'trade:' || v_id || ':created', 'none', v_state, 'platform', null, v_now, 1, null, v_ev);

  return v_id;
end $$;


-- ---------------------------------------------------------------- 2. same for the legacy constructor
-- create_trade() is what most of the suite and the original Claude code call, so leaving it
-- unanchored would have kept the hole wide open.
drop function if exists create_trade(text, text, text, numeric, text);

create function create_trade(p_seller text, p_buyer text, p_asset text, p_amount numeric,
                             p_type text default 'p2p')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_cap numeric; v_id bigint; v_state text; v_now timestamptz; v_ev bytea;
begin
  select max_amount into v_cap from trade_policy where trade_type = p_type;
  if not found then raise exception 'unknown trade type %', p_type; end if;
  if v_cap is not null and p_amount > v_cap then
    raise exception 'amount % exceeds cap % for %', p_amount, v_cap, p_type;
  end if;
  insert into trade(seller, buyer, asset, amount, trade_type,
                    crypto_asset, crypto_amount, fiat_asset, fiat_amount, rate, deposit_asset)
       values (p_seller, p_buyer, p_asset, p_amount, p_type,
               p_asset, p_amount, p_asset, p_amount, 1, p_asset)
    returning id, state into v_id, v_state;
  v_now := now();

  v_ev := digest(convert_to(canonical_event(v_id, 1, 'none', v_state, 'platform', null, v_now), 'UTF8'),
                 'sha256');
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault, created_at, seq, prev_hash, hash)
       values (v_id, 'trade:' || v_id || ':created', 'none', v_state, 'platform', null, v_now, 1, null, v_ev);

  return v_id;
end $$;


-- ---------------------------------------------------------------- 3. every trade must have a chain
-- Same body as 004 except for the last check, which no longer lets an empty event list pass. A
-- trade whose only event was deleted now fails instead of being waved through.
create or replace function verify_trade_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare
  tr record; e record; v_prev bytea; v_expect bytea; v_state text;
begin
  for tr in select id, state from trade order by id loop
    v_prev := null;
    for e in select * from trade_event where trade_id = tr.id order by seq loop
      if e.prev_hash is distinct from v_prev then return false; end if;
      v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_event(e.trade_id, e.seq, e.from_state, e.to_state, e.actor,
                                              e.fault, e.created_at), 'UTF8'), 'sha256');
      if v_expect is distinct from e.hash then return false; end if;
      v_prev := e.hash;
    end loop;
    -- The live state must equal the last event. When there is no event at all v_state is null, which
    -- is distinct from any real state, so an unchained trade fails here instead of passing.
    select e2.to_state into v_state from trade_event e2 where e2.trade_id = tr.id order by e2.seq desc limit 1;
    if v_state is distinct from tr.state then return false; end if;
  end loop;
  return true;
end $$;


-- ---------------------------------------------------------------- 4. nothing unanchored can hide
create or replace view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs,
       -- Should always be zero. Non-zero means a trade was created outside the constructors above,
       -- which is the one way to hold a state no event chain vouches for.
       (select count(*) from trade t
         where not exists (select 1 from trade_event e where e.trade_id = t.id)) as unanchored_trades;


-- PRIVILEGES, LAST AND COMPLETE.
-- postgres grants EXECUTE on a new function to PUBLIC. create_fiat_trade and create_trade were just
-- dropped and recreated, which took their grants with them, so they are restored here. kudi_ops is
-- deliberately absent: operations resolve disputes, they do not open trades.
grant execute on function create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text) to kudi_app;
grant execute on function create_trade(text, text, text, numeric, text) to kudi_app;
revoke all on integrity_report from public;
grant select on integrity_report to kudi_app, kudi_ops;

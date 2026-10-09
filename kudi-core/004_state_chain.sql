-- 004: tamper evidence for trade state, and idempotency that checks what it applied.
--
-- WHY THIS FILE EXISTS
-- Two holes in 001-003, both found by review rather than by a failing test:
--
-- 1. The hash chain covered postings only. ledger_tx and posting are append-only by trigger, and
--    a superuser who disables that trigger still breaks the chain. But `trade.state` had no chain
--    at all, and trade_event was only append-only by trigger. So the one thing the operator console
--    reads to decide whether a trade is green could be rewritten, and verify_chain() would still
--    return true. That is unacceptable: a dispute decision has to be provable after the fact.
--
--    Fix: hash-chain trade_event per trade, include the event hash in the ledger transaction that
--    accompanies it, and verify that the trade's current state equals the last event's to_state.
--    Rewriting the state alone breaks rule 3 below. Rewriting the event breaks the chain. Doing
--    both breaks the link between them.
--
-- 2. post_tx returned the original transaction for a repeated idempotency key without looking at
--    what was asked for. A retry with different postings reported success and applied nothing.
--    Fix: store a hash of the applied payload and refuse a key that comes back with a different one.
--
-- Additive. The 113 existing tests must still pass.


-- ---------------------------------------------------------------- 1. ledger_tx remembers what it applied
alter table ledger_tx add column payload_hash bytea not null default '\x'::bytea;
alter table ledger_tx add column link_hash    bytea;      -- the trade_event hash this tx belongs to


-- ---------------------------------------------------------------- 2. trade_event gets its own chain
alter table trade_event add column seq       int  not null default 0;
alter table trade_event add column prev_hash bytea;
alter table trade_event add column hash       bytea;

create unique index trade_event_seq_uniq on trade_event(trade_id, seq);

-- One canonical string per event. Write and verify MUST use this exact function.
-- The trader's own account of a dispute.
--
-- nullable, because only a dispute carries one: creation, claims and attestations have nothing to
-- narrate. Before this existed, TradeDesk hashed the trader's reason into the idempotency key and then
-- threw the text away -- a dispute recorded THAT somebody disputed and WHO, with no record of what they
-- said, while the screen promised "this is permanent, a regulator can read it".
--
-- It lives in trade_event rather than in a side table because a dispute narrative that is not in the
-- hash chain is editable, and an editable statement of what happened is not evidence of anything.
alter table trade_event add column narrative text;


-- The canonical form every event hash is computed over, so an edit to any field -- including the
-- narrative -- breaks the chain.
--
-- p_narrative is appended ONLY when there is one. Appending unconditionally would change the hash of
-- every event ever written and invalidate every chain already verified in production; appending only
-- on presence leaves every narrative-free event hashing exactly as it did before. The seven-argument
-- callers in 005 still work because the eighth parameter has a default.
drop function if exists canonical_event(bigint, int, text, text, text, text, timestamptz);
create function canonical_event(p_trade bigint, p_seq int, p_from text, p_to text,
                                p_actor text, p_fault text, p_created timestamptz,
                                p_narrative text default null)
returns text language sql immutable as $$
  select p_trade::text || '|' || p_seq::text || '|' || coalesce(p_from,'') || '|' || coalesce(p_to,'')
         || '|' || coalesce(p_actor,'') || '|' || coalesce(p_fault,'')
         || '|' || extract(epoch from p_created)::text
         || case when coalesce(p_narrative,'') = '' then ''
                 else '|' || p_narrative end
$$;


drop function if exists post_tx(text, text, bigint, jsonb);
create function post_tx(p_idem text, p_kind text, p_trade bigint, p_postings jsonb,
                        p_link bytea default null)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_id bigint; v_prev bytea; v_hash bytea; v_pay bytea; v_pay_check bytea; e jsonb;
begin
  v_pay := digest(canonical_postings(p_postings), 'sha256');

  select id, payload_hash into v_id, v_pay_check from ledger_tx where idem_key = p_idem;
  if found then
    if v_pay_check is distinct from v_pay then
      raise exception 'idempotency key % was already used with a different payload', p_idem;
    end if;
    return v_id;                                                    -- honest replay
  end if;

  perform pg_advisory_xact_lock(727001);                            -- one writer keeps the chain linear
  select id, payload_hash into v_id, v_pay_check from ledger_tx where idem_key = p_idem;
  if found then
    if v_pay_check is distinct from v_pay then
      raise exception 'idempotency key % was already used with a different payload', p_idem;
    end if;
    return v_id;                                                    -- replay that raced us
  end if;

  select hash into v_prev from ledger_tx order by id desc limit 1;
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
            convert_to(p_idem || '|' || p_kind || '|' || coalesce(p_trade::text, '') || '|'
                       || canonical_postings(p_postings) || '|' || coalesce(encode(p_link,'hex'),''), 'UTF8'),
            'sha256');
  insert into ledger_tx(idem_key, kind, trade_id, prev_hash, hash, payload_hash, link_hash)
       values (p_idem, p_kind, p_trade, v_prev, v_hash, v_pay, p_link)
    returning id into v_id;
  for e in select x from jsonb_array_elements(p_postings) as t(x)
           order by (x->>'account')::bigint loop                      -- stable lock order
    insert into posting(tx_id, account_id, asset, amount)
         values (v_id, (e->>'account')::bigint, (e->>'asset'), (e->>'amount')::numeric(78,0));
  end loop;
  return v_id;
end $$;


-- ---------------------------------------------------------------- 3. the transition writes a chained event, then the postings
drop function if exists _apply_transition(bigint, text, text, text, text, boolean, text);
create function _apply_transition(p_trade bigint, p_from text, p_to text, p_idem text,
                                  p_actor text, p_ops boolean, p_fault text,
                                  p_narrative text default null)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t trade%rowtype; r trade_rule%rowtype; pol trade_policy%rowtype;
  v_ok boolean; v_fault text := coalesce(p_fault, 'none'); v_attested timestamptz;
  s_av bigint; b_av bigint; esc bigint; sd bigint; bd bigint;
  d numeric := 0; v_post jsonb := '[]'::jsonb;
  da text;
  v_seq int; v_prev bytea; v_ev bytea; v_now timestamptz := clock_timestamp();
begin
  if p_actor is null or p_actor = '' then raise exception 'actor required'; end if;


  select * into t from trade where id = p_trade;
  if not found then raise exception 'no such trade %', p_trade; end if;
  select * into r from trade_rule where trade_type = t.trade_type and from_state = p_from and to_state = p_to;
  if not found then raise exception 'illegal transition % -> % for %', p_from, p_to, t.trade_type; end if;
  select * into pol from trade_policy where trade_type = t.trade_type;
  da := coalesce(t.deposit_asset, t.crypto_asset, t.fiat_asset);


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
           -- NO VENDOR ATTESTATION EXCEPTION, and one was briefly added here and then removed.
           --
           -- On a vendor-to-vendor trade the seller is the RECIPIENT of the buyer's payment, so 'it
           -- arrived' is the seller's to say -- which is exactly right for a "Checked" trade, where the
           -- buyer is relying on our verification of the seller and NOT on a guarantee from us. That is
           -- the tier brand/product.html describes: "Two labels let us sell the strong promise on the
           -- trades we control and be factual on the rest."
           --
           -- Letting operations attest every vendor trade would have made KudiMarket the guarantor of
           -- every peer-to-peer trade -- collapsing both tiers into the one we have explicitly not
           -- priced, and taking the other side on customer funds, which is the thing that puts the Bank
           -- of Ghana first rather than the SEC. Operations attesting is correct when WE are the
           -- counterparty, and only then.
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


  -- Idempotent replay. Deliberately AFTER authorisation, not before it.
  --
  -- The application derives the key from the trade and the step, not from the caller, so the buyer's
  -- claim and anybody else's claim for that same trade produce the SAME key. That is deliberate --
  -- it is what makes a double tap settle once without the client cooperating -- but it means the key
  -- is not a secret and cannot be treated as proof of who sent the request.
  --
  -- With this check above the authorisation block, an unauthorised caller who reused a key that
  -- already existed got a silent success instead of a refusal: their claim was swallowed as a replay
  -- and never named in the record. Silence there reads as "your claim was accepted". The key decides
  -- only whether a move is applied twice, never whether the caller may make it.
  if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;


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

  if t.crypto_amount is null and p_to = 'escrowed' then
    raise exception 'trade % has no crypto leg to escrow', p_trade;
  end if;


  update trade set state = p_to, updated_at = now()
   where id = p_trade and state = p_from returning * into t;                 -- compare-and-swap
  if not found then
    if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
    raise exception 'trade % is not in state %', p_trade, p_from;
  end if;


  -- chain the event BEFORE the postings, so the ledger transaction can carry its hash
  select coalesce(max(seq), 0) + 1, (array_agg(hash order by seq desc))[1]
    into v_seq, v_prev
    from trade_event where trade_id = p_trade;
  v_ev := digest(coalesce(v_prev, '\x'::bytea) ||
                 convert_to(canonical_event(p_trade, v_seq, p_from, p_to, p_actor,
                                            case when v_fault = 'none' then null else v_fault end, v_now,
                                            p_narrative), 'UTF8'),
                 'sha256');
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault, created_at, seq, prev_hash, hash, narrative)
       values (p_trade, p_idem, p_from, p_to, p_actor,
               case when p_fault = 'none' then null else p_fault end, v_now, v_seq, v_prev, v_ev,
               nullif(p_narrative, ''));


  s_av := get_account(t.seller, 'available', coalesce(t.crypto_asset, da));
  b_av := get_account(t.buyer,  'available', coalesce(t.crypto_asset, da));
  if t.crypto_amount is not null then
    esc := get_account('trade:' || t.id, 'escrow', t.crypto_asset);
  end if;
  if t.trade_type = 'vendor_vendor' then
    d := div(t.fiat_amount * pol.deposit_bps, 10000);
    if d > 0 then
      sd := get_account('trade:' || t.id || ':seller_deposit', 'escrow', da);
      bd := get_account('trade:' || t.id || ':buyer_deposit',  'escrow', da);
    end if;
  end if;


  if p_to = 'escrowed' and t.crypto_amount is not null then
    v_post := _p(s_av, t.crypto_asset, -t.crypto_amount) || _p(esc, t.crypto_asset, t.crypto_amount);
  elsif p_to in ('released','resolved_release') and t.crypto_amount is not null then
    v_post := _p(esc, t.crypto_asset, -t.crypto_amount) || _p(b_av, t.crypto_asset, t.crypto_amount);
  elsif p_to in ('cancelled','resolved_refund') and t.crypto_amount is not null and p_from <> 'open' then
    v_post := _p(esc, t.crypto_asset, -t.crypto_amount) || _p(s_av, t.crypto_asset, t.crypto_amount);
  end if;

  if d > 0 and (p_to = 'escrowed' or (p_from = 'open' and p_to = 'paid_claimed')) then
    v_post := v_post || _p(s_av, da, -d) || _p(sd, da, d)
                     || _p(b_av, da, -d) || _p(bd, da, d);
  elsif d > 0 and p_to in ('released','resolved_release') then
    v_post := v_post || _p(sd, da, -d) || _p(bd, da, -d)
                     || _p(case when v_fault = 'seller' then b_av else s_av end, da, d)
                     || _p(b_av, da, d);
  elsif d > 0 and p_to in ('cancelled','resolved_refund') then
    v_post := v_post || _p(sd, da, -d) || _p(bd, da, -d)
                     || _p(s_av, da, d)
                     || _p(case when v_fault = 'buyer' then s_av else b_av end, da, d);
  end if;


  if jsonb_array_length(v_post) > 0 then
    perform post_tx(p_idem || ':ledger', 'trade_' || p_to, t.id, v_post, v_ev);
  end if;
end $$;


-- ---------------------------------------------------------------- 4. verification
-- Trade state, per trade: the event chain must link, and the trade's live state must equal the
-- last event. A superuser who rewrites trade.state without touching the events fails the second
-- check; one who rewrites both fails the first.
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
                                              e.fault, e.created_at, e.narrative), 'UTF8'), 'sha256');
      if v_expect is distinct from e.hash then return false; end if;
      v_prev := e.hash;
    end loop;
    -- A trade that has never moved has no events and therefore no chain to violate. Only compare
    -- once an event exists, otherwise every untouched trade reads as tampering.
    select e2.to_state into v_state from trade_event e2 where e2.trade_id = tr.id order by e2.seq desc limit 1;
    if v_state is not null and v_state is distinct from tr.state then return false; end if;
  end loop;
  return true;
end $$;


-- The ledger chain now includes the event hash it was written with.
create or replace function verify_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare r record; v_prev bytea := null; v_expect bytea; v_canon text;
begin
  for r in select * from ledger_tx order by id loop
    if r.prev_hash is distinct from v_prev then return false; end if;
    select coalesce(string_agg(format('%s:%s:%s', account_id, asset, amount),
             ',' order by account_id, asset, amount), '') into v_canon from posting where tx_id = r.id;
    v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                convert_to(r.idem_key || '|' || r.kind || '|' || coalesce(r.trade_id::text, '') || '|'
                           || v_canon || '|' || coalesce(encode(r.link_hash,'hex'),''), 'UTF8'),
                'sha256');
    if v_expect <> r.hash then return false; end if;
    v_prev := r.hash;
  end loop;
  return verify_trade_chain();          -- postings AND state must both be intact
end $$;


-- One call for the regulator: does anything in here fail to prove itself?
create or replace view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs;

-- PRIVILEGES, LAST AND COMPLETE.
-- Postgres grants EXECUTE on a newly created function to PUBLIC by default. The blanket revoke in
-- 001/002 ran before the functions in this file existed, so post_tx (SECURITY DEFINER) would have
-- been callable by the app role again -- which is exactly the ability to post arbitrary entries.
-- So: revoke everything, then grant only what each role needs.
revoke execute on all functions in schema public from public;
revoke all on integrity_report from public;

-- trade_quote belongs here: it was created in 003 and this list is what made the console's read side
-- work. Leaving it off did not break any test, it broke the trade page with a permission error,
-- because quote() was the only reader of it and nothing had called it yet. asset is here for the
-- same reason: it holds each currency's decimals, so the console renders amounts from the database
-- rather than from a list of currency codes hardcoded in PHP.
grant select on trade_view, trade_quote, trade, trade_event, trade_policy, trade_rule,
                account, asset, ledger_tx, posting, integrity_report to kudi_app, kudi_ops;

-- transition_trade, upgraded to carry the trader's own words.
--
-- It has to happen HERE rather than in 002, because the body below passes eight arguments to
-- _apply_transition and the eight-argument version of that function does not exist until the line
-- above has run. Loading 002 first, this was an error at load time.
--
-- Dropped rather than create-or-replaced: in Postgres a changed parameter list is a different
-- function, so the extra argument cannot be added in place. Dropping takes the kudi_app grant with it,
-- which is why the grant immediately below names the new seven-argument signature.
drop function if exists transition_trade(bigint, text, text, text, text, text);
create function transition_trade(p_trade bigint, p_from text, p_to text, p_idem text,
                                 p_actor text, p_fault text default null,
                                 p_narrative text default null)
returns void language sql security definer set search_path = public, pg_temp as $$
  select _apply_transition(p_trade, p_from, p_to, p_idem, p_actor, false, p_fault, p_narrative)
$$;


grant execute on function create_trade(text,text,text,numeric,text),
                          create_fiat_trade(text,text,text,numeric,text,numeric,numeric,text,text),
                          transition_trade(bigint,text,text,text,text,text,text),
                          verify_chain(), verify_trade_chain(), check_balances() to kudi_app;

grant execute on function ops_transition(bigint,text,text,text,text,text),
                          verify_chain(), verify_trade_chain(), check_balances() to kudi_ops;

-- Deposits mint balance. Chain watcher only. Never the app, never ops.
grant execute on function ledger_deposit(text,text,numeric,text) to kudi_chain;

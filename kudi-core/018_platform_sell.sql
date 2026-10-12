-- 018: the other side of our own book. A trader selling currency TO KudiMarket.
--
-- WHY THIS FILE EXISTS
--
-- The handled tier could only ever move money OUT. openAgainstUs creates a trade with seller = 'platform',
-- so a trader could buy cedi or naira from us and could not sell us either. That is half a bureau de
-- change: the float can never be built, because nothing can put currency into it.
--
-- It is also the half the licence question turns on. REGULATORY.md 5b: a licensed DEALER under Act 723
-- s.3(4)(a) may buy and sell for own account; an authorised FX BROKER may not take the other side at all
-- (condition v), nor trade for its own account (vi), nor hold FX (vi). So the sell side is not a feature
-- that can be added whenever -- it is the thing that decides which of two businesses this is, and the
-- product cannot honestly apply for either licence with only one side of the book implemented.
--
-- WHY THIS IS NOT THE BUY PATH MIRRORED
--
-- The obvious implementation is create_fiat_trade with the roles swapped, and it is wrong in a way that
-- only shows up because the roles swapped.
--
-- trade_rule for platform_inventory is:
--
--     ('open','paid_claimed','buyer')            the buyer declares they sent it
--     ('paid_claimed','paid_attested','seller')  the seller confirms it arrived
--
-- That is safe ONLY while the trader is always the buyer. On a buy, the payer is the trader (the buyer)
-- and the confirmer is platform, so only the ops path may act for it and the trader can never confirm
-- their own money. Reverse the roles and both guarantees invert: the payer becomes the seller, so the
-- "seller confirms" rule hands the ATTESTATION to the trader, and a trader could declare their own
-- payment arrived and unlock the release.
--
-- That is not a hypothetical. It was verified before this file was written:
--
--     select create_fiat_trade('trader@...','platform',null,null,'NGN',20000000,148.2,
--                              'platform_inventory','GHS','NGN','GHS');
--     -- ops records the claim, because the trader may not (open -> paid_claimed is the buyer's)
--     select transition_trade(t,'paid_claimed','paid_attested','x','trader@...');  -- SUCCEEDS
--
-- The trade went to paid_attested on the payer's word. Every screen in this product refuses to let a
-- payer confirm that their own money arrived -- TradeDesk::attestPaid says the recipient's claim is
-- "theirs to say" and TradeResource says attestation is "deliberately not on the trader side" -- so this
-- is a hole the moment a sell flow exists.
--
-- SO THIS IS A SEPARATE TRADE TYPE, with the actors stated the way the roles actually run:
--
--     ('open','paid_claimed','seller')            the trader is the seller here, and declares
--     ('paid_claimed','paid_attested','ops')      KudiMarket confirms, because WE are the recipient
--
-- The invariant is unchanged and is the only thing that matters: THE TRADER MAY ALWAYS DECLARE, AND ONLY
-- OPERATIONS MAY CONFIRM. Which side of the trade the trader sits on changes the words; it does not
-- change who is believed.


-- ---------------------------------------------------------------- 1. a fourth tier

-- trade_policy.trade_type is a check constraint listing all three types by name, and trade.trade_type
-- references it, so both have to widen together. A policy row first: the reference requires it, and the
-- claim window is 15 minutes exactly as on the buy, because the exposure it covers is the same -- our
-- money, arriving by mobile money, exposed to a reversal we cannot prevent.
alter table trade_policy drop constraint trade_policy_trade_type_check;
alter table trade_policy
  add constraint trade_policy_trade_type_check
    check (trade_type in ('p2p','platform_inventory','vendor_vendor','platform_sell'));

insert into trade_policy(trade_type, claim_window, deposit_bps, max_amount, fee_bps, fee_bearer)
     values ('platform_sell', '15 minutes', 0, null, 0, 'buyer')
on conflict (trade_type) do nothing;

comment on table trade_policy is
  'Per-tier pricing and limits. platform_inventory is a trader BUYING from our own book; platform_sell is a trader SELLING to it. They are separate types because the transition rules differ in who is allowed to confirm -- see 018.';


-- ---------------------------------------------------------------- 2. the party rule, widened

-- 002 wrote this as "platform_inventory exactly when KudiMarket is a party". platform_sell is also a
-- KudiMarket trade, so the same has to be true of it -- or a trade could be typed platform_sell with two
-- traders on it and reach the settlement branch below with nobody to pay.
alter table trade drop constraint trade_platform_party;
alter table trade add constraint trade_platform_party
  check ((trade_type in ('platform_inventory','platform_sell')) = (seller = 'platform' or buyer = 'platform'));


-- ---------------------------------------------------------------- 3. the rules, stated for who the parties are

insert into trade_rule(trade_type, from_state, to_state, actor)
values ('platform_sell','open','paid_claimed','seller'),
       ('platform_sell','open','cancelled','either'),
       ('platform_sell','escrowed','paid_claimed','seller'),
       ('platform_sell','escrowed','cancelled','either'),
       ('platform_sell','paid_claimed','paid_attested','ops'),
       ('platform_sell','paid_claimed','disputed','either'),
       ('platform_sell','paid_attested','released','system'),
       ('platform_sell','paid_attested','disputed','either'),
       ('platform_sell','disputed','resolved_release','ops'),
       ('platform_sell','disputed','resolved_refund','ops')
on conflict do nothing;

comment on table trade_rule is
  'Who may perform each transition. platform_sell puts the DECLARATION with the seller, because on a sell the trader is the one who sent the money, and the CONFIRMATION with ops, because on a sell KudiMarket is the recipient. platform_inventory puts both the other way round, and neither may be inferred from the other -- see 018.';


-- ---------------------------------------------------------------- 4. the commission is charged on this tier too

-- 017's freeze trigger zeroes the fee for anything that is not platform_inventory, on the grounds that
-- a vendor trade posts no settlement. That reasoning does not cover platform_sell, which DOES post one:
-- the same platform/chain settlement branch below moves our position in both currencies. A zeroed fee
-- there would mean charging one side of our own book and not the other, which is the sort of thing that
-- looks like a bug to a customer and like a decision to nobody.
create or replace function _set_trade_fee()
returns trigger language plpgsql security definer set search_path = public, pg_temp as $$
declare
  pol record;
  v_bps int; v_bearer text;
  v_base numeric;
begin
  select * into pol from trade_policy where trade_type = new.trade_type;
  if pol is null or new.trade_type not in ('platform_inventory','platform_sell') then
    new.fee_bps := 0;
    new.fee_bearer := 'buyer';
    new.fee_minor_units := null;
    new.fee_asset := null;
    return new;
  end if;

  v_bps := coalesce(nullif(current_setting('kudi.fee_bps', true), '')::int, pol.fee_bps);
  v_bearer := coalesce(nullif(current_setting('kudi.fee_bearer', true), ''), pol.fee_bearer);

  if v_bps < 0 or v_bps > 10000 then
    raise exception 'fee_bps % is out of range', v_bps;
  end if;
  if v_bearer not in ('buyer','kudimarket') then
    raise exception 'fee_bearer % is not buyer or kudimarket', v_bearer;
  end if;

  new.fee_bps := v_bps;
  new.fee_bearer := v_bearer;

  if v_bps = 0 then
    new.fee_minor_units := null;
    new.fee_asset := null;
    return new;
  end if;

  if new.crypto_amount is null and new.rate is not null and new.rate <> 0 and new.rate <> 1
     and new.rate_base_asset is not null then
    v_base := div(new.fiat_amount, new.rate);
  else
    v_base := new.fiat_amount;
  end if;

  new.fee_minor_units := div(v_base * v_bps, 10000);
  new.fee_asset := coalesce(new.rate_base_asset, new.crypto_asset, new.fiat_asset);
  return new;
end $$;


-- ---------------------------------------------------------------- 5. the settlement has to cover it

-- _apply_transition's own-book branch is guarded on trade_type = 'platform_inventory', so a platform_sell
-- trade would settle with no postings at all: the claim window would elapse, the sweep would release it,
-- and no money would move on either side. The whole function is redefined here for that one condition,
-- and the rest of it -- authorisation, idempotent replay, the claim-window guard, the compare-and-swap,
-- the hash-chained event, the direction-aware deposit, the commission -- is carried across unchanged.
--
-- The deposit direction fix and the commission both have to survive the copy, which is the real risk in a
-- file of this shape: a wholesale redefinition that silently drops last week's fix.
drop function if exists _apply_transition(bigint, text, text, text, text, boolean, text, text);

create function _apply_transition(p_trade bigint, p_from text, p_to text, p_idem text,
                                  p_actor text, p_ops boolean, p_fault text,
                                  p_narrative text default null)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t trade%rowtype; r trade_rule%rowtype; pol trade_policy%rowtype;
  v_ok boolean; v_fault text := coalesce(p_fault, 'none'); v_attested timestamptz;
  s_av bigint; b_av bigint; esc bigint; sd bigint; bd bigint;
  d numeric := 0; v_post jsonb := '[]'::jsonb;
  v_pos_base bigint; v_pos_quote bigint; v_chain_base bigint; v_chain_quote bigint;
  v_fee_acct bigint; v_base_minor numeric; v_fee numeric;
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

  -- authorisation. Unchanged from 004 except that platform_sell reaches it: a seller who is a trader
  -- may act as 'seller' (they are the one declaring), and a platform party is only ever represented by ops.
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

  if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;

  if v_fault not in ('none','seller','buyer') then raise exception 'bad fault %', v_fault; end if;
  if v_fault <> 'none' and (t.trade_type <> 'vendor_vendor' or p_to not in ('resolved_release','resolved_refund')) then
    raise exception 'fault applies only to vendor_vendor resolutions';
  end if;
  if (p_to = 'resolved_release' and v_fault = 'buyer') or (p_to = 'resolved_refund' and v_fault = 'seller') then
    raise exception 'fault % is inconsistent with %', v_fault, p_to;
  end if;

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
   where id = p_trade and state = p_from returning * into t;
  if not found then
    if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
    raise exception 'trade % is not in state %', p_trade, p_from;
  end if;

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

  if t.trade_type = 'vendor_vendor' and pol.deposit_bps > 0 then
    -- Direction-aware, from 017. Carried across unchanged.
    if t.rate is null or t.rate = 1 then
      d := div(t.fiat_amount * pol.deposit_bps, 10000);
    elsif da = t.fiat_asset then
      d := div(t.fiat_amount * pol.deposit_bps, 10000);
    elsif da = t.rate_quote_asset and t.fiat_asset = t.rate_base_asset then
      d := div(t.fiat_amount * t.rate * pol.deposit_bps, 10000);
    elsif da = t.rate_base_asset and t.fiat_asset = t.rate_quote_asset then
      d := div(div(t.fiat_amount, t.rate) * pol.deposit_bps, 10000);
    else
      raise exception 'cannot price a deposit for trade % in %: it is neither leg of the trade', t.id, da;
    end if;
    if d > 0 then
      sd := get_account('trade:' || t.id || ':seller_deposit', 'escrow', da);
      bd := get_account('trade:' || t.id || ':buyer_deposit',  'escrow', da);
    end if;
  end if;

  -- The own-book settlement, now reached by BOTH handled tiers.
  --
  -- On a buy the trader is the buyer: they send the base leg and we send the quote leg. On a sell the
  -- trader is the seller, so the base/quote pair is recorded from the other end and this same block
  -- moves our position the other way. The arithmetic does not know or care which party is the trader --
  -- only that KudiMarket is one of them, which the trade_platform_party check now guarantees for both.
  if t.crypto_amount is null
     and t.trade_type in ('platform_inventory','platform_sell')
     and t.rate_base_asset is not null
     and p_to in ('released','resolved_release') then
    v_pos_base := get_account('platform', 'external', t.rate_base_asset);
    v_pos_quote := get_account('platform', 'external', t.fiat_asset);
    v_chain_base := get_account('chain', 'external', t.rate_base_asset);
    v_chain_quote := get_account('chain', 'external', t.fiat_asset);
    v_base_minor := div(t.fiat_amount, t.rate);
    if v_base_minor > 0 then
      v_fee := coalesce(t.fee_minor_units, 0);
      if v_fee > 0 then
        v_fee_acct := get_account('platform', 'fee', t.fee_asset);
        if t.fee_bearer = 'buyer' then
          v_post := v_post || _p(v_chain_base, t.fee_asset, -(v_base_minor + v_fee))
                           || _p(v_pos_base,   t.rate_base_asset,  v_base_minor)
                           || _p(v_fee_acct,    t.fee_asset,        v_fee);
        else
          v_post := v_post || _p(v_chain_base, t.fee_asset, -v_base_minor)
                           || _p(v_pos_base,   t.rate_base_asset,  v_base_minor - v_fee)
                           || _p(v_fee_acct,    t.fee_asset,        v_fee);
        end if;
      else
        v_post := v_post || _p(v_chain_base, t.rate_base_asset, -v_base_minor)
                         || _p(v_pos_base,   t.rate_base_asset,  v_base_minor);
      end if;
      v_post := v_post || _p(v_pos_quote,   t.fiat_asset,     -t.fiat_amount)
                       || _p(v_chain_quote, t.fiat_asset,      t.fiat_amount);
    end if;
  end if;

  if p_to = 'escrowed' and t.crypto_amount is not null then
    v_post := v_post || _p(s_av, t.crypto_asset, -t.crypto_amount) || _p(esc, t.crypto_asset, t.crypto_amount);
  elsif p_to in ('released','resolved_release') and t.crypto_amount is not null then
    v_post := v_post || _p(esc, t.crypto_asset, -t.crypto_amount) || _p(b_av, t.crypto_asset, t.crypto_amount);
  elsif p_to in ('cancelled','resolved_refund') and t.crypto_amount is not null and p_from <> 'open' then
    v_post := v_post || _p(esc, t.crypto_asset, -t.crypto_amount) || _p(s_av, t.crypto_asset, t.crypto_amount);
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
    perform post_tx(p_idem || ':ledger', 'trade_' || p_to, t.id, v_post);
  end if;
end $$;

-- ---------------------------------------------------------------- 6. the privilege, again, by name

-- 017's blanket revoke ran BEFORE this file recreated _apply_transition, so the new copy inherits EXECUTE
-- TO PUBLIC from Postgres rather than from our intent. _apply_transition is SECURITY DEFINER: with the
-- default left standing, a trader could call the function behind every state change directly, with
-- p_ops = true, and move money. The suite catches it as "no such trade 1" -- a seven-argument call BINDS
-- to the eight-argument function through its default, resolves, and runs.
--
-- This is the second time this exact trap has been taken in this ledger, which is why it is written down
-- here rather than left to be rediscovered. Any file that redefines a SECURITY DEFINER function must
-- re-run the revoke and re-grant by name.
revoke execute on all functions in schema public from public;

grant execute on function create_trade(text, text, text, numeric, text),
                          create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text, text, text),
                          transition_trade(bigint, text, text, text, text, text, text),
                          verify_chain(), verify_trade_chain(), verify_rate_chain(),
                          check_balances() to kudi_app;

grant execute on function ops_transition(bigint, text, text, text, text, text),
                          release_due_trades(),
                          verify_chain(), verify_trade_chain(), check_balances() to kudi_ops;

grant execute on function create_trade(text, text, text, numeric, text),
                          create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text, text, text),
                          transition_trade(bigint, text, text, text, text, text, text),
                          verify_chain(), verify_trade_chain(), verify_rate_chain(),
                          submit_cdd(text, text, text, date, date, text, text, text, text) to kudi_trader;

grant execute on function ledger_deposit(text, text, numeric, text) to kudi_chain;
grant execute on function set_rate(text, text, numeric, text, timestamptz, text) to kudi_pricing;
grant execute on function set_commission(text, int, text, text, text) to kudi_pricing;
grant execute on function verify_commission_chain() to kudi_ops, kudi_app;

revoke all on function set_commission(text, int, text, text, text) from public;



-- ---------------------------------------------------------------- 7. the AML view, which was assessing one leg of two
--
-- ctr_candidates (016) unpivots a trade into one row per PARTY per trade and assesses fiat_amount, which
-- is the QUOTE leg: the currency that ARRIVES at the counterparty. On every trade KudiMarket has ever
-- had, the trader is the receiver, so that happened to be the leg in the trader's hands and the view was
-- right by accident.
--
-- The sell side breaks the accident. On a sell the trader is the SELLER: they send the base leg and
-- receive the quote leg. The view already reaches a sell, because it pivots on role rather than on
-- trade_type -- but it only ever assesses the RECEIVED leg, so the money coming INTO the business was
-- never a CTR candidate at all. Verified before this file was written:
--
--     select count(*) filter (where fiat_asset='GHS'), count(*) filter (where fiat_asset='NGN')
--       from ctr_candidates;      --  0 | 198
--
-- Both legs are now unpivoted. A currency exchange reportable on either side is reportable, and a
-- reporting view that silently drops one direction of every transaction is worse than no view at all,
-- because it is a view somebody trusts.
-- DROP AND RECREATE, not CREATE OR REPLACE: a replace may not reorder columns, and this one adds
-- `direction` in the middle of the list. Same rule as trade_view in 017.
drop view ctr_candidates;

create view ctr_candidates as
with legs as (
  -- What the party PAYS: the base leg. This is the inbound leg on a sell, and it was the missing half.
  select t.id as trade_id, t.seller as trader_email, 'seller' as role, 'send' as direction,
         t.rate_base_asset as leg_asset,
         div(t.fiat_amount, t.rate) as leg_amount
    from trade t
   where t.crypto_amount is null and t.rate is not null and t.rate <> 0
  union all
  select t.id, t.buyer, 'buyer', 'send', t.rate_base_asset, div(t.fiat_amount, t.rate)
    from trade t
   where t.crypto_amount is null and t.rate is not null and t.rate <> 0
  union all
  -- What the party RECEIVES: the quote leg, which is what the view has always assessed.
  select t.id as trade_id, t.seller as trader_email, 'seller' as role, 'receive' as direction,
         t.rate_quote_asset as leg_asset, t.fiat_amount as leg_amount
    from trade t
   where t.fiat_amount is not null and t.rate_quote_asset is not null
  union all
  select t.id, t.buyer, 'buyer', 'receive', t.rate_quote_asset, t.fiat_amount
    from trade t
   where t.fiat_amount is not null and t.rate_quote_asset is not null
)
select l.trade_id, l.trader_email, l.role, l.direction,
       l.leg_asset as fiat_asset, l.leg_amount as fiat_amount,
       requires_ctr(l.leg_asset, l.leg_amount) as threshold_call,
       s.doc_type, s.doc_number, s.purpose_code, s.source_of_funds, s.is_cdd_verified
  from legs l
  left join cdd_status s on s.trader_email = l.trader_email
 where l.leg_amount is not null and l.leg_amount > 0
   and l.trader_email <> 'platform'
   and requires_ctr(l.leg_asset, l.leg_amount) in ('reportable','unknown')
 order by l.trader_email, l.trade_id, l.direction;

comment on view ctr_candidates is
  'Every party-leg of every trade that may need a CTR, one row per party per leg. BOTH directions are unpivoted because the received leg alone misses the money arriving, which on a sell is the inbound leg -- and 016 assessed only the received leg, which was right by accident while every trader was a receiver.';

-- 003: the fiat leg.
--
-- WHY THIS FILE EXISTS
-- 001/002 model a trade as ONE asset in escrow: seller locks it, buyer receives it.
-- That is the crypto trade. Our main trade is not that. A buyer in Accra pays 300,000 naira
-- into a seller's Nigerian bank account; the seller owes cedis into the buyer's mobile money.
-- Nothing is escrowed. No crypto is involved. 002 cannot represent it, because trade.asset is
-- NOT NULL and every transition assumes an escrow account exists.
--
-- So: a trade now has an OPTIONAL crypto leg and a REQUIRED fiat leg. Trades that move no
-- crypto through us set crypto_asset/crypto_amount to NULL and the state machine skips the
-- escrow postings entirely.
--
-- Additive only. Nothing in 001/002 is dropped or renamed, so the existing 86 tests must still
-- pass unchanged. If they do not, this file is wrong.


alter table trade
  alter column asset  drop not null,
  alter column amount drop not null;

alter table trade
  add column crypto_asset text references asset(code),
  add column crypto_amount numeric(78,0),
  add column fiat_asset   text not null default 'GHS' references asset(code),
  add column fiat_amount  numeric(78,0),
  add column rate         numeric(38,10),
  add column deposit_asset text references asset(code);

alter table trade
  drop constraint trade_amount_check,
  add constraint trade_amount_check   check (amount is null or amount > 0),
  add constraint trade_crypto_pair     check ((crypto_asset is null) = (crypto_amount is null)),
  add constraint trade_crypto_positive check (crypto_amount is null or crypto_amount > 0),
  add constraint trade_has_fiat        check (fiat_asset is not null and fiat_amount > 0),
  add constraint trade_fiat_positive   check (fiat_amount > 0),
  add constraint trade_has_rate        check (rate > 0);

-- Existing crypto trades: crypto leg == the escrow leg, and the fiat leg is whatever they
-- settle for. Tests create trades through create_trade(), which leaves fiat_amount null, so
-- trade_has_fiat would reject them. Backfill a placeholder fiat leg from the escrow leg and
-- relax the constraint to "fiat present OR legacy row", then re-tighten after create_trade
-- learns about it. Kept explicit so the sequence is auditable.
update trade set fiat_asset = asset, fiat_amount = amount where fiat_amount is null;
update trade set crypto_asset = asset, crypto_amount = amount where asset is not null;
update trade set rate = 1 where rate is null;

alter table trade drop constraint trade_has_fiat;
alter table trade add constraint trade_has_fiat check (fiat_asset is not null and fiat_amount > 0);

update trade set deposit_asset = coalesce(deposit_asset, crypto_asset, fiat_asset);


-- A trade with no crypto leg. rate = fiat units per 1 unit of the crypto leg, and is required
-- whenever there IS a crypto leg, because it is the number the trader agreed to.
create function create_fiat_trade(p_seller text, p_buyer text, p_crypto_asset text,
                                  p_crypto_amount numeric, p_fiat_asset text, p_fiat_amount numeric,
                                  p_rate numeric, p_type text default 'platform_inventory',
                                  p_deposit_asset text default 'GHS')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_cap numeric; v_id bigint;
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
  insert into trade(seller, buyer, asset, amount, crypto_asset, crypto_amount,
                    fiat_asset, fiat_amount, rate, deposit_asset, trade_type)
       values (p_seller, p_buyer, p_crypto_asset, p_crypto_amount,
               p_crypto_asset, p_crypto_amount, p_fiat_asset, p_fiat_amount, p_rate,
               p_deposit_asset, p_type)
    returning id into v_id;
  return v_id;
end $$;


-- The transition function, with the fiat leg. Identical to 002 except:
--   * escrow postings happen only when the trade HAS a crypto leg
--   * deposits are taken in deposit_asset (fiat for a fiat trade), not in the escrow asset
--   * an illegal move (escrowing when there is no crypto leg) is refused explicitly
create or replace function _apply_transition(p_trade bigint, p_from text, p_to text, p_idem text,
                                             p_actor text, p_ops boolean, p_fault text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t trade%rowtype; r trade_rule%rowtype; pol trade_policy%rowtype;
  v_ok boolean; v_fault text := coalesce(p_fault, 'none'); v_attested timestamptz;
  s_av bigint; b_av bigint; esc bigint; sd bigint; bd bigint;
  d numeric := 0; v_post jsonb := '[]'::jsonb;
  da text;                      -- deposit asset: crypto for a crypto trade, fiat otherwise
begin
  if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;    -- replay
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


  -- a trade with no crypto leg cannot be escrowed. It goes open -> paid_claimed directly,
  -- because there is nothing to lock.
  if t.crypto_amount is null and p_to = 'escrowed' then
    raise exception 'trade % has no crypto leg to escrow', p_trade;
  end if;


  update trade set state = p_to, updated_at = now()
   where id = p_trade and state = p_from returning * into t;                 -- compare-and-swap
  if not found then
    if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
    raise exception 'trade % is not in state %', p_trade, p_from;
  end if;
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault)
       values (p_trade, p_idem, p_from, p_to, p_actor, case when v_fault = 'none' then null else v_fault end);


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

  -- Deposits are taken and returned in the deposit asset, whatever that is. They are taken on
  -- the FIRST commitment step, which for a fiat-only trade is open -> paid_claimed because there
  -- is no escrow to lock into. Wiring this to 'escrowed' alone silently took no deposit at all
  -- on a fiat vendor trade, which the tests caught.
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


-- A fiat-only trade needs a route that skips escrow. Add the direct edges; the crypto-only
-- path keeps using open -> escrowed.
insert into trade_rule(trade_type, from_state, to_state, actor) values
  ('platform_inventory','open','paid_claimed','buyer'),
  ('platform_inventory','open','cancelled','either'),
  ('vendor_vendor','open','paid_claimed','buyer'),
  ('vendor_vendor','open','cancelled','either'),
  ('p2p','open','paid_claimed','buyer')
on conflict do nothing;


-- trade_view was created in 002 as "select t.*, ...", and Postgres expands that star at creation
-- time, so it does not expose the columns added above. Recreate it so the new columns flow
-- through to the UI and to anything that reads colour.
drop view trade_view;
create view trade_view as
select t.*,
       case when state in ('released','resolved_release')            then 'green'
            -- disputed is deliberately absent: it is an open question, not a verdict. The brand
            -- law is plum for pending and red only for money actually going back to one side,
            -- which is what cancelled and resolved_refund mean.
            when state in ('cancelled','resolved_refund')             then 'red'
            else 'plum' end as colour
  from trade t;


-- The legacy constructor, unchanged in signature so existing grants and tests keep working,
-- now populates the crypto leg. Without this, a trade made by create_trade() would arrive with
-- crypto_amount null and the escrow transition would (correctly) refuse to lock nothing.
create or replace function create_trade(p_seller text, p_buyer text, p_asset text, p_amount numeric,
                                        p_type text default 'p2p')
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_cap numeric; v_id bigint;
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
    returning id into v_id;
  return v_id;
end $$;


-- The agreed rate, in fiat units per one unit of the crypto leg. Used by the UI and by the
-- regulator-facing record, never to move money.
create or replace view trade_quote as
select id, trade_type, seller, buyer, state,
       crypto_asset, crypto_amount, fiat_asset, fiat_amount, rate,
       case
         when crypto_asset is null then null
         else round(fiat_amount::numeric / crypto_amount, 12)
       end as actual_rate,
       fiat_amount / nullif(rate, 0) as crypto_implied,
       colour
  from trade_view;

-- 012: both ends of the pair, so no screen has to infer one.
--
-- WHY THIS FILE EXISTS
--
-- 011 recorded what trade.rate COUNTS IN (rate_quote_asset). It did not record the other end. So every
-- consumer that needed both currencies had one of them and had to guess the other -- and the guess was
-- hardcoded to cedi, because until now cedi was the only currency a buyer could receive from us.
--
-- brand/product.html settles the product question in a table row: "Which pairs do we quote? Cedi against
-- naira, BOTH DIRECTIONS." A trader who buys cedi with naira receives cedi, and the app had no stored
-- fact that the other leg was naira. The screen would have had to know it.
--
-- WHAT THIS DOES
--
-- rate_base_asset joins 011's rate_quote_asset, and together they are the rate card row the trade was
-- priced from: rate is quote per base. That makes every conversion derivable from the row alone --
--
--   to == quote  ->  multiply     to == base  ->  divide
--
-- -- with no hardcoded currency anywhere, which is the thing that produced four money bugs in a row.
--
-- THE INVARIANT
--
-- Checked against all three existing shapes rather than assumed, because the whole file rests on it:
--
--   vendor (offer NGN/GHS)   buyer pays NGN, receives GHS   pair NGN/GHS
--   platform, buying naira   buyer pays GHS, receives NNG   pair GHS/NGN
--   platform, buying cedi    buyer pays NGN, receives GHS   pair NGN/GHS
--
-- The buyer always pays the BASE leg and receives the QUOTE leg. That is what lets one rule serve every
-- trade instead of a special case per trade type -- the vendor flow stores the naira leg and the platform
-- flow stores the cedi leg, and both fall out of the same sentence. Asserted in the suite, not just here.
--
-- NOT A BREAKING CHANGE. 001-011 untouched. create_fiat_trade grows a trailing OPTIONAL argument.


alter table trade add column rate_base_asset text references asset(code);

comment on column trade.rate_quote_asset is
  'The asset trade.rate counts in: rate = units of rate_quote_asset per one unit of rate_base_asset. Left null only where an older row''s rate matches no pair on the card.';

comment on column trade.rate_base_asset is
  'The other end of the pair: the asset one unit of rate_quote_asset is worth. With rate_quote_asset this is the rate card row the trade was priced from.';

-- Backfill from the card, where the pair is unambiguous. A rate that matches no row, or more than one,
-- keeps whatever it had: a wrong pair invented here would be a wrong amount on a money screen, which is
-- the failure this file exists to stop, not to enable.
update trade t
   set rate_base_asset = c.base_asset
  from (select rate, quote_asset, min(base_asset) as base_asset, count(*) as readings
          from rate_card group by rate, quote_asset) c
 where t.rate is not null
   and t.rate_quote_asset is not null
   and t.rate = c.rate
   and t.rate_quote_asset = c.quote_asset
   and c.readings = 1;

update trade set rate_base_asset = rate_quote_asset where rate = 1 and rate_base_asset is null;

-- A pair whose ends are the same is not a pair, and a rate between a currency and itself says nothing.
-- A single-unit rate is the one exception: it is a placeholder for "no conversion", not a conversion.
alter table trade add constraint trade_rate_pair_distinct
  check (rate is null or rate = 1 or rate_base_asset is distinct from rate_quote_asset);


-- ---------------------------------------------------------------- 1. create_fiat_trade learns the pair
drop function if exists create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text, text);

create function create_fiat_trade(p_seller text, p_buyer text, p_crypto_asset text,
                                  p_crypto_amount numeric, p_fiat_asset text, p_fiat_amount numeric,
                                  p_rate numeric, p_type text default 'platform_inventory',
                                  p_deposit_asset text default 'GHS',
                                  p_rate_quote_asset text default null,
                                  p_rate_base_asset text default null)
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

  -- A pair that is the same currency at both ends would make trade.rate meaningless, and a rate of 1
  -- would sail past the row-level check. Caught here as well because a function that can be handed a
  -- nonsense pair can be handed one directly, and the error belongs where the mistake was made.
  -- The base falls back to the CRYPTO leg when there is one, because a crypto trade's pair is crypto to
  -- fiat and its rate is fiat per crypto -- not two fiat assets. Guessing the fiat asset for both ends is
  -- what this guard first did, and it refused every crypto trade in the suite.
  if coalesce(p_rate_base_asset, p_crypto_asset, p_fiat_asset) = coalesce(p_rate_quote_asset, p_fiat_asset)
     and coalesce(p_rate, 1) <> 1 then
    raise exception 'rate pair must name two different assets, not %', coalesce(p_rate_quote_asset, p_fiat_asset);
  end if;

  insert into trade(seller, buyer, asset, amount, crypto_asset, crypto_amount,
                    fiat_asset, fiat_amount, rate, rate_quote_asset, rate_base_asset,
                    deposit_asset, trade_type)
       values (p_seller, p_buyer, p_crypto_asset, p_crypto_amount,
               p_crypto_asset, p_crypto_amount, p_fiat_asset, p_fiat_amount, p_rate,
               coalesce(p_rate_quote_asset, p_fiat_asset),
               coalesce(p_rate_base_asset, p_crypto_asset, p_fiat_asset),
               p_deposit_asset, p_type)
    returning id, state into v_id, v_state;
  v_now := now();

  v_ev := digest(convert_to(canonical_event(v_id, 1, 'none', v_state, 'platform', null, v_now), 'UTF8'),
                 'sha256');
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault, created_at, seq, prev_hash, hash)
       values (v_id, 'trade:' || v_id || ':created', 'none', v_state, 'platform', null, v_now, 1, null, v_ev);

  return v_id;
end $$;

-- Dropping and recreating it took its grants with it, as in 005 and 011. kudi_ops is absent on purpose.
grant execute on function create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text, text, text)
  to kudi_app, kudi_trader;


-- ---------------------------------------------------------------- 2. accept_offer passes the pair it had
-- 011's function, with one more argument. The offer names both ends of the pair and the rate came off the
-- card for exactly that pair, so both were on the row before this call.
create or replace function accept_offer(p_offer bigint, p_buyer text, p_actor text)
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

  v_id := create_fiat_trade(o.seller, p_buyer, null, null, o.base_asset, o.amount, v_rate,
                            'vendor_vendor', o.quote_asset, o.quote_asset, o.base_asset);

  insert into offer_withdrawal(offer_id, withdrawn_by, reason) values (p_offer, p_actor, 'filled');
  return v_id;
end $$;


-- ---------------------------------------------------------------- 3. the views, again, for the same reason as 011
-- `select t.*` was expanded when the view was created, so a new column does not reach it. Postgres cannot
-- replace a view to reorder columns either, so both are dropped and rebuilt rather than patched.
drop view trade_quote;
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

create view trade_quote as
select id, trade_type, seller, buyer, state,
       crypto_asset, crypto_amount, fiat_asset, fiat_amount,
       rate, rate_quote_asset, rate_base_asset,
       case
         when crypto_asset is null then null
         else round(fiat_amount::numeric / crypto_amount, 12)
       end as actual_rate,
       fiat_amount / nullif(rate, 0) as crypto_implied,
       colour
  from trade_view;

revoke all on trade_view, trade_quote from public;
grant select on trade_view, trade_quote to kudi_app, kudi_ops, kudi_trader;

-- Both halves of the pair, or the derivation has nothing to stand on. Same reasoning as 011: "the column
-- exists" and "the screen can read it" are different claims.
do $$
begin
  if not exists (select 1 from information_schema.columns
                  where table_name = 'trade_view' and column_name = 'rate_base_asset')
     or not exists (select 1 from information_schema.columns
                  where table_name = 'trade_quote' and column_name = 'rate_base_asset') then
    raise exception 'the views do not expose rate_base_asset; no screen could derive the other leg';
  end if;
end $$;
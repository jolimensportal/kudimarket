-- 011: which side of the pair the rate counts in.
--
-- WHY THIS FILE EXISTS
--
-- trade.rate is a bare number, and the card is careful about what its own numbers mean: "Whole units of
-- quote per one whole unit of base". Which means 148.2 and 0.0067567 are BOTH correct rates for the same
-- pair, quoted from opposite ends, and a row storing one does not say which it is.
--
-- The core stores two of them, in one column, on rows that are otherwise identical:
--
--   platform_inventory   openAgainstUs reads the card for (GHS,NGN) -> 148.2 NGN per cedi, then writes
--                        fiat_asset = NGN with a naira amount.   Cedi = naira / 148.2.
--   vendor_vendor        accept_offer  reads the card for (NGN,GHS) -> 0.0067567 cedi per naira, then
--                        writes fiat_asset = NGN with a naira amount.   Cedi = naira * 0.0067567.
--
-- Both rows read "fiat_amount 500000, fiat_asset NGN". The single fact that separates divide from multiply
-- -- what the number counts in -- was discarded by both creation paths, and both of them HAD it: each reads
-- a current_rate row and takes `rate` off it, leaving base_asset and quote_asset sitting on the table.
--
-- What that cost. app/Services/TradeFigures could only assume one convention, took the platform one, and
-- divided. On a vendor trade that is wrong by 1/rate^2: the screen told a real buyer to send C74,000,000 and
-- receive N500,000 on a trade worth C3,378.38, and then handed him a Mobile Money number to send it to.
-- Two hundred and eighty six passing tests missed it because every fixture used a single cedi rate, where
-- the two conventions agree.
--
-- WHAT THIS DOES
--
-- One column. rate_quote_asset is the asset trade.rate counts in, so rate reads as "how many
-- rate_quote_asset per one unit of the other asset in the pair", and given a source and a target:
--
--   target = rate_quote_asset  ->  multiply   (rate is target per source)
--   source = rate_quote_asset  ->  divide     (rate is source per target)
--
-- It is read from the same card row the rate came from, so it cannot drift from the rate it describes.
--
-- NOT A BREAKING CHANGE. 001-010 are untouched; nothing is renamed or dropped. create_fiat_trade grows a
-- trailing OPTIONAL argument, so every existing call site keeps working and its behaviour is unchanged, and
-- the old arity is dropped and re-granted so there is one function to reason about rather than two. The
-- body below is 005's, which is the live definition, with one column added to the insert.


alter table trade add column rate_quote_asset text references asset(code);

comment on column trade.rate_quote_asset is
  'The asset trade.rate counts in: rate = units of rate_quote_asset per one unit of the other asset in the pair. Left null only where an older row''s rate matches no pair on the card.';

-- Backfill from the card, where the match is unambiguous, rather than inventing a direction: a guessed
-- direction puts a wrong number on a money screen, which is the whole failure this file exists to stop.
update trade t
   set rate_quote_asset = c.quote_asset
  from (select rate, min(quote_asset) as quote_asset, count(distinct quote_asset) as readings
          from rate_card group by rate) c
 where t.rate is not null
   and t.rate = c.rate
   and c.readings = 1
   and exists (select 1 from rate_card rc
                where rc.rate = t.rate
                  and (rc.base_asset = t.fiat_asset or rc.quote_asset = t.fiat_asset));

-- A single-unit rate quotes nothing in particular -- it is its own direction, so the fiat asset answers.
update trade set rate_quote_asset = fiat_asset where rate = 1 and rate_quote_asset is null;


-- ---------------------------------------------------------------- 1. create_fiat_trade learns the direction
drop function if exists create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text);

create function create_fiat_trade(p_seller text, p_buyer text, p_crypto_asset text,
                                  p_crypto_amount numeric, p_fiat_asset text, p_fiat_amount numeric,
                                  p_rate numeric, p_type text default 'platform_inventory',
                                  p_deposit_asset text default 'GHS',
                                  p_rate_quote_asset text default null)
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

  -- rate_quote_asset defaults to the fiat asset, which is right for every single-unit trade (rate = 1,
  -- nothing is being quoted) and is the honest answer when a caller has no direction to give. A caller
  -- that DOES mean something else now has to say so, and it shows up on the row.
  insert into trade(seller, buyer, asset, amount, crypto_asset, crypto_amount,
                    fiat_asset, fiat_amount, rate, rate_quote_asset, deposit_asset, trade_type)
       values (p_seller, p_buyer, p_crypto_asset, p_crypto_amount,
               p_crypto_asset, p_crypto_amount, p_fiat_asset, p_fiat_amount, p_rate,
               coalesce(p_rate_quote_asset, p_fiat_asset), p_deposit_asset, p_type)
    returning id, state into v_id, v_state;
  v_now := now();

  v_ev := digest(convert_to(canonical_event(v_id, 1, 'none', v_state, 'platform', null, v_now), 'UTF8'),
                 'sha256');
  insert into trade_event(trade_id, idem_key, from_state, to_state, actor, fault, created_at, seq, prev_hash, hash)
       values (v_id, 'trade:' || v_id || ':created', 'none', v_state, 'platform', null, v_now, 1, null, v_ev);

  return v_id;
end $$;

-- Dropping and recreating it took its grants with it, exactly as in 005. kudi_ops is absent on purpose:
-- operations resolve disputes, they do not open trades.
grant execute on function create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text, text)
  to kudi_app, kudi_trader;


-- ---------------------------------------------------------------- 2. accept_offer passes the direction it had
-- 009's function, copied rather than retyped, with ONE change: create_fiat_trade is told what the rate
-- counts in. The first version of this file rewrote it from memory, invented an offer_withdrawn_at column
-- that does not exist, and broke four passing offer tests. Copy the next edit, do not retype the function.
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

  -- Priced by the card. o.quote_asset is what v_rate counts in, because the rate was read from the card
  -- for exactly this pair -- it was on the row all along, and taking only `rate` is how the direction got
  -- lost. Everything else here is 009's, unchanged.
  v_id := create_fiat_trade(o.seller, p_buyer, null, null, o.base_asset, o.amount, v_rate,
                            'vendor_vendor', o.quote_asset, o.quote_asset);

  insert into offer_withdrawal(offer_id, withdrawn_by, reason) values (p_offer, p_actor, 'filled');
  return v_id;
end $$;

-- ---------------------------------------------------------------- 3. trade_view, which is NOT `select t.*` any more
--
-- This file's first version carried a confident comment saying no view needed touching, because
-- trade_view is written `select t.*, ... colour`. That is wrong, and it is wrong in a way worth recording:
-- Postgres expands `*` into a literal column list WHEN THE VIEW IS CREATED. Adding a column to trade after
-- that does not reach trade_view, and `select * from trade_view` will never show it. So the direction was
-- stored correctly on the row and invisible to the screen that needed it, which looked exactly like the
-- fix not having worked.
--
-- Dropped and recreated rather than CREATE OR REPLACE, for the same reason 009 dropped integrity_report:
-- REPLACE may only append to the end, and here the column belongs with the rest of the money. Recreating
-- re-expands `*` and picks it up in the right place.
--
-- trade_quote is built ON trade_quote's parent, trade_view, and selects `colour` from it, so it has to go
-- first and be rebuilt after. Dropping trade_view alone is refused: "cannot drop view trade_view because
-- other objects depend on it". Rebuilding it verbatim rather than using REPLACE, because REPLACE cannot
-- reorder columns and would drop actual_rate or crypto_implied off the end of an already-ordered list.
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
       crypto_asset, crypto_amount, fiat_asset, fiat_amount, rate, rate_quote_asset,
       case
         when crypto_asset is null then null
         else round(fiat_amount::numeric / crypto_amount, 12)
       end as actual_rate,
       fiat_amount / nullif(rate, 0) as crypto_implied,
       colour
  from trade_view;

-- Dropping them took the grants with it. kudi_trader is included deliberately: a trader may read their own
-- trade, and the app derives the cedi side from this row.
revoke all on trade_view, trade_quote from public;
grant select on trade_view, trade_quote to kudi_app, kudi_ops, kudi_trader;

-- And the check that it actually took, because "the column exists" and "the column is reachable from the
-- view the screen reads" are different claims and only the second one matters here.
do $$
begin
  if not exists (select 1 from information_schema.columns
                  where table_name = 'trade_view' and column_name = 'rate_quote_asset') then
    raise exception 'trade_view does not expose rate_quote_asset; the screen cannot see the direction';
  end if;
end $$;
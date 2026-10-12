-- 019: the trades a trader is a party to, said by the database rather than by the caller.
--
-- WHY THIS FILE EXISTS
--
-- "Where you stand" has always shown the COUNTS -- settled, still open, disputed -- and never the list.
-- A trader could see that they had four trades and no way to reach any of them: each trade lives at its
-- own id, and nothing in the product listed the ids that belong to them. Leaving a trade screen by any
-- route other than the back button was leaving it for good.
--
-- The list cannot be built off an email the application passes in, for the same reason 015 gives for the
-- balance screen: every trader connects as the SAME login role, so a query filtered by a caller-supplied
-- address would answer about whoever the caller named. my_trade_counts() already answers "how many" from
-- the session identity; this is the same question asked per row, so it is the same function shape, taking
-- the same argument: none.
--
-- The predicate -- buyer = current_trader() or seller = current_trader() -- is the one my_trade_counts()
-- already carries. It is repeated here rather than passed in, which is the point: there is one copy of it
-- per function that needs it and no caller can supply a different one.
--
-- NOT A BREAKING CHANGE. A new function alongside the old shape, read on the trader connection.

create or replace function my_trades()
returns table(
  id               bigint,
  trade_type       text,
  state            text,
  colour           text,
  fiat_asset       text,
  fiat_amount      numeric,
  rate             numeric,
  rate_base_asset  text,
  rate_quote_asset text,
  fee_bps          integer,
  fee_bearer       text,
  fee_minor_units  numeric,
  fee_asset        text,
  buyer            text,
  seller           text,
  updated_at       timestamptz
)
language sql stable security definer set search_path = public, pg_temp as $$
  -- Newest first, by id: a trade id is assigned in the order trades were opened, so it is the creation
  -- order the row itself does not store. (trade has updated_at but no created_at; id is the stable
  -- proxy for "which came first", and updated_at is shown to the trader as the last thing that happened.)
  select t.id, t.trade_type, t.state, t.colour,
         t.fiat_asset, t.fiat_amount,
         t.rate, t.rate_base_asset, t.rate_quote_asset,
         t.fee_bps, t.fee_bearer, t.fee_minor_units, t.fee_asset,
         t.buyer, t.seller,
         t.updated_at
    from trade_view t
   where t.buyer = current_trader()
      or t.seller = current_trader()
   order by t.id desc;
$$;

comment on function my_trades() is
  'The signed-in trader''s own trades, newest first, read from the session identity. Takes no trader argument: an argument is the whole vulnerability 015 exists to remove.';

-- PUBLIC gets EXECUTE on a new function by default. Revoked by name, the way 010, 013, 015 and 017 had to
-- do it, and granted only to the role a trader signs in as.
revoke all on function my_trades() from public;
grant execute on function my_trades() to kudi_trader;

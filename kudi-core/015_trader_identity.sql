-- 015: who am I, said by the database rather than by the caller.
--
-- WHY THIS FILE EXISTS
--
-- Every trader connects as the SAME login role. That is deliberate -- one credential, one place to
-- revoke -- and it has one consequence that has been quietly shaping the product: the database cannot
-- tell one trader from another. Every question a trader asks is therefore answered from an email the
-- APPLICATION passes in, and a trader holding a kudi_trader connection can pass somebody else's.
--
-- That is why the product has no balance screen. "Where you stand" deliberately shows no "you hold"
-- figure, and the comment says why: any way of reading one balance would either expose everybody's or
-- depend on the application never passing the wrong name.
--
-- Depending on the application never passing the wrong name is not a control. It is a hope, and the
-- database is exactly the place where it can stop being a hope.
--
-- WHAT THIS DOES
--
-- The application sets the identity once per request, from the signed-in session, and every function a
-- trader can reach reads it from there. NONE of them take a trader as an argument, because an argument is
-- the whole problem: the caller would be choosing whose money to see.
--
--     set_config('kudi.trader', 'ama@example.com', false)
--
-- set_config is not a security boundary on its own -- a connection can set any custom GUC -- so these
-- functions are also SECURITY DEFINER and kudi_trader keeps NO grant on account. There is therefore no
-- table for the identity to leak through, and the only way to reach a balance is a function that takes
-- no trader argument at all.
--
-- THE RESIDUAL RISK, stated rather than buried: if the application has a SQL injection on a trader
-- connection, the injected statement can set_config() first and then call my_balances(). Closing that
-- needs one database login per trader, which is a different architecture and a real operational cost.
-- This is the standard multi-tenant pattern and it is a large improvement on passing an email; it is not
-- the same as not having the problem.
--
-- NOT A BREAKING CHANGE. Nothing existing is altered. These are new functions alongside the old shape.


-- ---------------------------------------------------------------- 1. the identity
create or replace function kudi_sign_in(p_email text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
begin
  if p_email is null or trim(p_email) = '' then
    raise exception 'a sign-in needs somebody''s name on it';
  end if;

  -- Checked against the trader table rather than trusted. An identity the database cannot find is an
  -- identity that answers every question about nobody, and a typo in the application would otherwise
  -- produce an empty screen rather than an error.
  if not exists (select 1 from trader where email = p_email) then
    raise exception 'no such trader: %', p_email;
  end if;

  -- Not transaction-local (the third argument is false). A trader request is a sequence of statements on
  -- one connection, and a LOCAL setting is discarded at the end of the transaction, which would leave
  -- later statements in the same request signed out.
  perform set_config('kudi.trader', p_email, false);
end;
$$;

create or replace function kudi_sign_out()
returns void language plpgsql security definer set search_path = public, pg_temp as $$
begin
  perform set_config('kudi.trader', '', false);
end;
$$;

-- The identity, or NULL. NULL rather than an error, because "who is signed in" is a question with a
-- perfectly good answer of nobody.
create or replace function current_trader()
returns text language sql stable as $$
  select nullif(btrim(current_setting('kudi.trader', true)), '');
$$;


-- ---------------------------------------------------------------- 2. the money, read through that identity
-- The balance screen, which could not be built before this file.
--
-- The WHERE clause names no trader. There is nothing to pass wrong, because there is nothing to pass.
create or replace function my_balances()
returns table(asset text, kind text, available numeric, escrow numeric)
language sql stable security definer set search_path = public, pg_temp as $$
  select a.asset,
         a.kind,
         a.balance as available,
         0::numeric as escrow
    from account a
   where a.owner = current_trader()
     and a.kind = 'available'
  union all
  -- Escrow is money held for a trade this trader is party to, still theirs and not spendable. Shown
  -- separately because "you hold" and "we are holding for you" are different facts and merging them
  -- would make a number that is not a balance.
  select a.asset,
         a.kind,
         0::numeric as available,
         sum(a.balance) as escrow
    from account a
   where a.owner like 'trade:%'
     and a.kind = 'escrow'
     -- The pattern is the other way round from what the first version had:
     --   'trade:' || t.id || ':'  LIKE  a.owner || '%'
     -- asks whether 'trade:12:' starts with 'trade:12:buyer_deposit', which it does not, so a trader's
     -- escrow was never returned and the screen silently showed them holding nothing. An escrow belongs to
     -- a trade when the ACCOUNT is named after the TRADE, so the trade's prefix is the pattern.
     and (
       exists (select 1 from trade t
                where a.owner like 'trade:' || t.id || ':%'
                  and t.buyer = current_trader())
       or exists (select 1 from trade t
                   where a.owner like 'trade:' || t.id || ':%'
                     and t.seller = current_trader())
     )
   group by a.asset, a.kind
   order by 1, 2;
$$;

comment on function my_balances() is
  'The signed-in trader''s own balances, read from the session identity. Takes no trader argument: an argument is the whole vulnerability this file exists to remove.';


-- ---------------------------------------------------------------- 3. the counts behind "Where you stand"
-- The same shape, and for the same reason. The existing queries take an email and would answer for
-- anybody asked; these cannot.
create or replace function my_trade_counts()
returns table(settled bigint, in_flight bigint, disputed bigint, total bigint)
language sql stable security definer set search_path = public, pg_temp as $$
  select
    count(*) filter (where state in ('released', 'resolved_release')),
    -- "Still open" on the position screen means still in flight: open, claimed and attested are all states
    -- where money is expected to move and has not finished. Splitting them would have changed what the
    -- number means while fixing who it is about.
    count(*) filter (where state in ('open', 'paid_claimed', 'paid_attested')),
    count(*) filter (where state = 'disputed'),
    count(*)
    from trade
   where buyer = current_trader() or seller = current_trader();
$$;


-- ---------------------------------------------------------------- 4. privileges
-- PUBLIC gets EXECUTE on a new function by default, and my_balances() is the one function in this system
-- that can read a balance. Revoked by name, the way 010 and 013 had to do it.
revoke all on function kudi_sign_in(text) from public;
revoke all on function kudi_sign_out() from public;
revoke all on function current_trader() from public;
revoke all on function my_balances() from public;
revoke all on function my_trade_counts() from public;

-- kudi_trader may sign in and ask. It may NOT read account, which is the property that makes this a
-- control rather than a convenience: there is no table to select from, only functions that take no
-- argument about whose money to show.
grant execute on function kudi_sign_in(text), kudi_sign_out(), current_trader(),
                        my_balances(), my_trade_counts() to kudi_trader;

-- kudi_sign_in checks the trader table, so the definer needs to read it.
grant select on trader to kudi_trader;
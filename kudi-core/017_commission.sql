-- 017: the commission, charged in the open.
--
-- WHY THIS FILE EXISTS
--
-- The mockup shows a trader a fee row -- "KudiMarket fee 0.00" -- and item 7 is about stopping the
-- phone call, where "what is your fee" is the first question. There was nothing to put in it. The 'fee'
-- balance kind was reserved in 001 and never posted to, there was no spread or margin concept anywhere,
-- and a trade was one amount plus a held rate, with the rate being the whole price. So the row could
-- not be printed honestly: not because the number was wrong, but because nothing held a number.
--
-- WHY A COMMISSION AND NOT A SPREAD
--
-- A margin hidden in the rate is a spread. A separate itemised amount is a commission, and
-- brand/REGULATORY.md:248 records the BoG FX Broker condition that "shall operate on commission basis
-- and should not include their commissions as spreads in pricing trades". The mode is a statement made
-- to the regulator and to every trader who reads the row, so it is chosen here explicitly rather than
-- emerging from whichever number happened to be typed into a rate.
--
-- This does not settle the dealer-vs-broker fork in REGULATORY.md 5b, which needs Ghanaian counsel and
-- blocks launch rather than building. Both routes can charge commission; only one may take a spread.
--
-- FROZEN AT CREATION, LIKE THE RATE
--
-- The rate is held: a trader who agreed a price cannot be charged a different one at settlement, and
-- the fee is exactly that kind of number. So it is written onto the trade when the trade is created and
-- read from there by both the posting and the screen.
--
-- It is computed by a trigger rather than inside the creation functions. 005 and 012 both build the
-- creation anchor, and 012 exists only because the creation paths used to DISCARD the rate direction
-- and 286 passing tests missed it. Editing two delicate functions to carry four more columns is how
-- that bug gets created again. A BEFORE INSERT trigger cannot forget a path, because it does not care
-- which one ran.
--
-- WHY ONLY OUR OWN BOOK
--
-- A vendor_vendor trade posts nothing at all: the money goes trader to trader without touching us
-- (004:280-283 says so in terms). There is no leg for a fee to move along, and posting one would
-- invent a debt between two people we do not owe. So the fee is zero off our book, and the
-- "Counterparty fee" row in the mockup stays unprinted -- we do not know the seller's margin and
-- asserting zero would be inventing a fact about a third party.


-- ---------------------------------------------------------------- 1. the setting

alter table trade_policy
  add column fee_bps int not null default 0 check (fee_bps between 0 and 10000),
  add column fee_bearer text not null default 'buyer'
    check (fee_bearer in ('buyer','kudimarket'));

comment on column trade_policy.fee_bps is
  'Commission in basis points, per ten thousand, of what the BUYER SENDS. Zero by default: the mechanism ships correct and charging nothing, so a printed 0.00 is a fact the ledger enforces rather than a string a view prints.';
comment on column trade_policy.fee_bearer is
  'buyer: the fee is added to what the buyer sends. kudimarket: the fee is absorbed out of our own margin and the buyer''s quoted figures are unchanged. Not "seller receives" -- on our own book KudiMarket IS the seller and collects it either way, so the real question is always who pays.';


-- ---------------------------------------------------------------- 2. the frozen facts on the trade

alter table trade
  add column fee_bps    int not null default 0 check (fee_bps between 0 and 10000),
  add column fee_bearer text not null default 'buyer'
    check (fee_bearer in ('buyer','kudimarket')),
  add column fee_minor_units numeric(78,0),
  add column fee_asset text references asset(code);

-- One of the two, never neither and never both.
alter table trade add constraint trade_fee_complete
  check ((fee_minor_units is null) = (fee_asset is null));

comment on column trade.fee_minor_units is
  'The commission in minor units of fee_asset, frozen at creation. Both the settlement posting and the trader''s screen read this column; neither recomputes it. See 011_rate_direction.sql for what recomputing it instead once cost.';
comment on column trade.fee_bearer is
  'Frozen copy of the policy default, or of what the admin chose when the trade was opened.';


-- ---------------------------------------------------------------- 3. freezing it at creation

create or replace function _set_trade_fee()
returns trigger language plpgsql security definer set search_path = public, pg_temp as $$
declare
  pol record;
  v_bps int; v_bearer text;
  v_base numeric;
begin
  -- Only our own book carries a commission. Everything else is zero by construction rather than by a
  -- branch somebody has to remember.
  select * into pol from trade_policy where trade_type = new.trade_type;
  if pol is null or new.trade_type <> 'platform_inventory' then
    new.fee_bps := 0;
    new.fee_bearer := 'buyer';
    new.fee_minor_units := null;
    new.fee_asset := null;
    return new;
  end if;

  -- The admin may override the policy for this trade, through the session, so that the open call and
  -- the freeze cannot disagree. Absent a session value the policy stands. set_config is used rather
  -- than current_setting(.., true) alone so a missing value reads as null instead of raising.
  --
  -- The session value is CAST before it meets pol.fee_bps, because current_setting returns text and
  -- fee_bps is int, and coalesce(text, int) is "COALESCE types text and integer cannot be matched" --
  -- on every platform trade, at creation, which is the loudest possible failure. An empty string is
  -- treated as absent rather than cast to zero: "no override given" and "override of zero bps" are
  -- different facts, and only one of them means a free trade.
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

  -- The commission is a share of WHAT THE BUYER SENDS, which on this tier is the base leg, and which
  -- the settlement already computes the same way at 004 (div(fiat_amount, rate)). Computing it here
  -- with the identical expression is what lets the two agree without either deferring to the other.
  if new.crypto_amount is null and new.rate is not null and new.rate <> 0 and new.rate <> 1
     and new.rate_base_asset is not null then
    v_base := div(new.fiat_amount, new.rate);
  else
    -- No conversion to do: a legacy or rate-1 trade sends the fiat leg itself.
    v_base := new.fiat_amount;
  end if;

  new.fee_minor_units := div(v_base * v_bps, 10000);
  new.fee_asset := coalesce(new.rate_base_asset, new.crypto_asset, new.fiat_asset);
  return new;
end $$;

create trigger trade_freeze_fee before insert on trade
  for each row execute function _set_trade_fee();


-- ---------------------------------------------------------------- 4. the app can read it

-- trade_view is `select t.*`, but that expansion happened when 012 created it -- before these columns
-- existed. A view stores its expanded column list, so "t.*" means the columns as they were THEN. It has
-- to be rebuilt or the fee is invisible to every screen in the product.
--
-- DROP AND RECREATE, not CREATE OR REPLACE, and 003:231 drops it for the identical reason: a replace
-- may not reorder or rename columns, and these columns are added to the SELECT list after `colour`, so
-- a replace would fail on "cannot change name of view column colour to fee_bps". Dropping first is what
-- the ledger already does when it widens a view; it is not a new habit.
drop view trade_quote;
drop view trade_view;

create view trade_view as
select t.*,
       case when state in ('released','resolved_release')            then 'green'
            when state in ('cancelled','resolved_refund')             then 'red'
            else 'plum' end as colour
  from trade t;

-- trade_quote projects an explicit column list, so the fee has to be added there too or the screen
-- reads a trade with no fee on it -- which is the failure 011 records in full, one layer out.
create view trade_quote as
select id, trade_type, seller, buyer, state,
       crypto_asset, crypto_amount, fiat_asset, fiat_amount,
       rate, rate_quote_asset, rate_base_asset,
       fee_bps, fee_bearer, fee_minor_units, fee_asset,
       case
         when crypto_asset is null then null
         else round(fiat_amount::numeric / crypto_amount, 12)
       end as actual_rate,
       fiat_amount / nullif(rate, 0) as crypto_implied,
       colour
  from trade_view;

revoke all on trade_view, trade_quote from public;
grant select on trade_view, trade_quote to kudi_app, kudi_ops, kudi_trader;
grant select on trade_policy to kudi_app, kudi_ops;

-- The screen must be able to read the commission, and the guarantee that it is projected is the same
-- machine check 012 used for the rate pair.
do $$
begin
  if not exists (select 1 from information_schema.columns
                 where table_name = 'trade_quote' and column_name = 'fee_minor_units') then
    raise exception 'trade_quote does not project the fee, so no screen can read what it is charged';
  end if;
  if not exists (select 1 from information_schema.columns
                 where table_name = 'trade_quote' and column_name = 'fee_bearer') then
    raise exception 'trade_quote does not project who pays the fee';
  end if;
end $$;

-- ---------------------------------------------------------------- 5. charging it at settlement
--
-- The whole _apply_transition is redefined rather than patched, for the reason every version of it in
-- 002/003/004 is a whole redefinition: it is the one function the ledger has, and a fee that lives
-- outside it is a second ledger. Only the settlement block changes; everything above it -- the
-- authorisation, the idempotent replay, the claim-window guard, the compare-and-swap, the hash-chained
-- event -- is carried across byte for byte so that this file cannot quietly relax one of them.
--
-- The fee rides in the SAME post_tx as the settlement because a ledger transaction balances per asset.
-- A separate transaction would need its own counterparty and would be, by construction, a transfer
-- between us and the outside world that touches no trade escrow -- which is exactly what
-- 014_segregation.sql's commingled_transfers counts as an alarm. Same transaction, no alarm.
drop function if exists _apply_transition(bigint, text, text, text, text, boolean, text);
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

  -- Idempotent replay, deliberately after authorisation. See 004 for the reasoning.
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
  if t.trade_type = 'vendor_vendor' and pol.deposit_bps > 0 then
    -- A deposit is a share of what the trade is worth, TAKEN IN A NAMED CURRENCY, and those are two
    -- different numbers. The rate column holds BOTH conventions -- NGN per GHS on one ordered pair, GHS
    -- per NGN on the other -- which is what 011_rate_direction.sql exists to make survivable, so the
    -- conversion is chosen by ASKING THE PAIR rather than assumed.
    --
    -- 004 multiplied, always. Correct when the deposit asset is the quote leg, which is what accept_offer
    -- always produces (it passes offer.quote_asset as the deposit asset and as rate_quote_asset together).
    -- Wrong by a factor of the RATE when the deposit is taken in the base leg: a trade worth C20.24
    -- produced a C44,460 deposit, 22,010 times too much, which the ledger then refuses as an overdraft --
    -- so a trader cannot open a trade at all. Latent rather than live, because the app's own creation path
    -- never asks for a deposit in the base currency, and every deposit test until this one used rate = 1
    -- where multiplying and dividing are both the identity and a share of the wrong number looks right.
    --
    -- Anything that is neither leg is refused rather than guessed at, on the grounds that a wrong deposit
    -- is a real sum of money and a wrong deposit nobody can explain is worse.
    if t.rate is null or t.rate = 1 then
      -- No conversion to do. The eight-argument create_fiat_trade() leaves rate_base_asset and
      -- rate_quote_asset both defaulting to the fiat asset, so the row records no usable pair at all and
      -- a rate of 1 is the placeholder for "no conversion here" rather than a claim that one naira buys
      -- one cedi. Taking the share of the amount as recorded is what the ledger has always done on such
      -- a row, and inventing a conversion for it would be guessing at a rate the row does not have.
      d := div(t.fiat_amount * pol.deposit_bps, 10000);
    elsif da = t.fiat_asset then
      d := div(t.fiat_amount * pol.deposit_bps, 10000);
    elsif da = t.rate_quote_asset and t.fiat_asset = t.rate_base_asset then
      d := div(t.fiat_amount * t.rate * pol.deposit_bps, 10000);                 -- base -> quote multiplies
    elsif da = t.rate_base_asset and t.fiat_asset = t.rate_quote_asset then
      d := div(div(t.fiat_amount, t.rate) * pol.deposit_bps, 10000);             -- quote -> base divides
    else
      raise exception 'cannot price a deposit for trade % in %: it is neither leg of the trade', t.id, da;
    end if;
    if d > 0 then
      sd := get_account('trade:' || t.id || ':seller_deposit', 'escrow', da);
      bd := get_account('trade:' || t.id || ':buyer_deposit',  'escrow', da);
    end if;
  end if;

  -- THE SETTLEMENT, WITH THE COMMISSION IN IT.
  --
  -- Carried across from 004 unchanged apart from the two fee legs. The commission is a slice of what
  -- the BUYER SENDS, and there are exactly two honest ways to book it:
  --
  --   buyer          the chain takes base+fee, platform receives base, and the fee lands in platform's
  --                  own 'fee' account. The buyer pays more than the quoted conversion.
  --   kudimarket     the chain takes base, platform receives base MINUS the fee, and the same fee
  --                  account is credited. Nothing moves between parties: the buyer's quoted figures
  --                  are untouched and the commission is an allocation of our own margin.
  --
  -- Both sum to zero per asset, which is what check_balances() and the conservation test require, and
  -- both are inside the settlement's own post_tx so no transfer of ours ever touches no escrow.
  if t.crypto_amount is null
     and t.trade_type = 'platform_inventory'
     and t.rate_base_asset is not null
     and p_to in ('released','resolved_release') then
    v_pos_base := get_account('platform', 'external', t.rate_base_asset);
    v_pos_quote := get_account('platform', 'external', t.fiat_asset);
    v_chain_base := get_account('chain', 'external', t.rate_base_asset);
    v_chain_quote := get_account('chain', 'external', t.fiat_asset);
    v_base_minor := div(t.fiat_amount, t.rate);
    if v_base_minor > 0 then
      -- The stored fee, not a recomputation. The trigger froze it at creation and the trader was shown
      -- it; anything computed here could disagree with the number on the quote.
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


-- ---------------------------------------------------------------- 6. the privilege the new functions must not keep
--
-- 001 revoked execute from public ONCE, as a snapshot of the functions that existed at that moment.
-- Every function created after it -- all of 002 through 016, and this one -- inherits PostgreSQL's
-- default EXECUTE to PUBLIC. Each file that adds one re-runs the revoke for itself, and this one has to
-- as well.
--
-- It is not a formality here. _apply_transition is SECURITY DEFINER: with EXECUTE left open, a trader
-- could call the function behind every state change directly, with p_ops = true, and move money. The
-- suite asserts this by calling _apply_transition with seven arguments and expecting "permission
-- denied" -- and it stopped being denied in a way that looked like a missing trade rather than a hole:
-- the eight-argument function has a default, so a seven-argument call BINDS to it, resolves, and runs.
revoke execute on all functions in schema public from public;

-- The functions this file adds or replaces get their grants back by name, because the blanket revoke
-- above takes the default away with it. Every signature below is copied from the schema as it actually
-- stands after 016 -- an argument list guessed from memory makes the whole file fail to load with
-- "function does not exist", which is how this block first went wrong.
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

grant select on trade_view, trade_quote to kudi_app, kudi_ops, kudi_trader;
grant select on trade_policy to kudi_app, kudi_ops;


-- ---------------------------------------------------------------- 7. who may set it, and proof they did
--
-- trade_policy is SELECT-only to every application role, which is the right posture for ledger config: it
-- is not a table the app should be able to edit column by column. So the commission is changed the way
-- the rate is -- through one function, granted to one role, recorded against a name.
--
-- This is a configuration log, not a money movement. It is hash-chained anyway, because "who raised our
-- commission and when" is a question a regulator asks, and an unchained settings table answers it only
-- as far as the last person who cared to look.
create table commission_log (
  id          bigserial primary key,
  trade_type  text not null references trade_policy(trade_type),
  fee_bps     int not null check (fee_bps between 0 and 10000),
  fee_bearer  text not null check (fee_bearer in ('buyer','kudimarket')),
  set_by      text not null check (length(trim(set_by)) > 0),
  note        text,
  set_at      timestamptz not null default now(),
  prev_hash   bytea,
  hash        bytea
);

create function canonical_commission(p_type text, p_bps int, p_bearer text, p_at timestamptz, p_who text)
returns text language sql immutable as $$
  select p_type || '|' || p_bps || '|' || p_bearer || '|' || p_at::text || '|' || p_who
$$;

create function set_commission(p_trade_type text, p_fee_bps int, p_fee_bearer text, p_actor text,
                               p_note text default null)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare v_prev bytea; v_hash bytea; v_at timestamptz := now();
begin
  if p_actor is null or btrim(p_actor) = '' then raise exception 'actor required'; end if;
  if p_fee_bps < 0 or p_fee_bps > 10000 then
    raise exception 'fee_bps % is out of range: a commission is between 0 and 10000 basis points', p_fee_bps;
  end if;
  if p_fee_bearer not in ('buyer','kudimarket') then
    raise exception 'fee_bearer % is not buyer or kudimarket', p_fee_bearer;
  end if;
  if not exists (select 1 from trade_policy where trade_type = p_trade_type) then
    raise exception 'unknown trade type %', p_trade_type;
  end if;

  select hash into v_prev from commission_log order by id desc limit 1;
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_commission(p_trade_type, p_fee_bps, p_fee_bearer, v_at, p_actor), 'UTF8'),
                   'sha256');

  -- The setting is effective for trades opened AFTER this row. Trades already open keep the fee they
  -- were quoted, because it was frozen onto them at creation -- this is the whole reason the fee lives
  -- on the trade and not only in the policy.
  update trade_policy set fee_bps = p_fee_bps, fee_bearer = p_fee_bearer where trade_type = p_trade_type;

  insert into commission_log(trade_type, fee_bps, fee_bearer, set_by, note, set_at, prev_hash, hash)
       values (p_trade_type, p_fee_bps, p_fee_bearer, p_actor, nullif(p_note, ''), v_at, v_prev, v_hash);
end $$;

-- A commission cannot be applied to a tier we do not charge on. Not a default of zero here: a silent
-- no-op that looks like success is how a settings screen ends up lying about what it did.
-- SECURITY DEFINER because it calls digest(), and no application role holds EXECUTE on pgcrypto's
-- functions: without it the verification fails with "permission denied for function digest" at the first
-- row, which reads as a broken ledger rather than a broken grant.
create function verify_commission_chain() returns boolean
language plpgsql security definer set search_path = public, pg_temp as $$
declare r record; v_prev bytea; v_ok boolean := true;
begin
  v_prev := null;
  for r in select * from commission_log order by id loop
    if r.prev_hash is distinct from v_prev then return false; end if;
    if r.hash is distinct from digest(coalesce(v_prev, '\x'::bytea) ||
         convert_to(canonical_commission(r.trade_type, r.fee_bps, r.fee_bearer, r.set_at, r.set_by), 'UTF8'),
         'sha256') then return false; end if;
    v_prev := r.hash;
  end loop;
  return v_ok;
end $$;

grant select on commission_log to kudi_app, kudi_ops;

-- TO kudi_pricing, NOT kudi_ops, and this is the whole point of the grant.
--
-- The commission is our price, so it moves with the rate card onto the pricing desk and 006's
-- segregation of duties still holds: one account cannot reprice what we charge AND judge the trades it
-- charged it on. Granting it to ops would have deleted that property in a single line -- ops resolve
-- disputes, and the disputed question is very often the fee. The operations role can READ the setting,
-- which it must in order to answer a trader who calls about one, and cannot change it.
grant execute on function set_commission(text, int, text, text, text) to kudi_pricing;
grant execute on function verify_commission_chain() to kudi_ops, kudi_app;

-- AND BY NAME, because the blanket revoke in section 6 ran BEFORE these functions existed and a
-- function created after it inherits EXECUTE to PUBLIC from Postgres, not from this file's intent.
-- set_commission is SECURITY DEFINER and decides what we charge every trader, so with the default left
-- standing, kudi_app could have set the commission to anything and the suite's "only the pricing desk
-- may" assertion passed on the empty string it returned rather than on a refusal.
revoke all on function set_commission(text, int, text, text, text) from public;


-- ---------------------------------------------------------------- 8. one rule for what a commission IS
--
-- The quote and the charge disagreed by a pesewa, and it is worth being precise about why, because the
-- expression looks identical in both places:
--
--     the trigger   div( div(fiat_amount, rate) * fee_bps, 10000)     -- TRUNCATES
--     TradeFigures  (int) round($converted * 10 ** decimals)          -- ROUNDS
--
-- A real trade reads its FROZEN fee, so it always charged the truncated figure. The preview had no frozen
-- column, so it derived one from the ROUNDED send amount, and the two disagreed at the last digit:
--
--     quote  N149,480.00        trade  N149,479.99
--
-- One pesewa is not a rounding curiosity here. A mobile-money payment is typed to the pesewa, so a trader
-- who sends the quoted total is a pesewa short of the frozen one and the payment cannot settle -- which is
-- exactly the failure this whole feature exists to remove.
--
-- So the rule moves into the ledger, where the trigger already lives, and both callers use it. The app no
-- longer owns a copy of a pricing formula; it asks. This is the same lesson as 011 (one rate column, two
-- meanings) and 012 (a direction discarded at creation), applied to the third place the same mistake could
-- be made: the quote.
create function commission_quote(p_fiat_amount numeric, p_rate numeric, p_fee_bps int)
returns numeric language plpgsql immutable as $$
begin
  if p_fee_bps is null or p_fee_bps <= 0 then
    return 0;
  end if;
  if p_fiat_amount is null or p_rate is null or p_rate = 0 or p_rate = 1 then
    return 0;
  end if;

  -- EXACTLY the trigger's expression, div() included. Written out again here rather than shared, because
  -- the trigger cannot call a function on a row being built without a forward reference; the test below is
  -- what holds the two together.
  return div(div(p_fiat_amount, p_rate) * p_fee_bps, 10000);
end $$;

comment on function commission_quote(numeric, numeric, int) is
  'The commission a trade WOULD carry, in minor units of the sending currency, by the same div() the freeze trigger uses. The quote screen must call this rather than deriving its own: rounding where the ledger truncates produced a one-pesewa disagreement between the amount quoted and the amount charged, which on a payment typed to the pesewa means it cannot settle.';

-- Granted explicitly, and after the section 6 blanket revoke rather than before it: a function created
-- later inherits EXECUTE TO PUBLIC, and PUBLIC has no business asking what we would charge.
grant execute on function commission_quote(numeric, numeric, int) to kudi_app;
revoke all on function commission_quote(numeric, numeric, int) from public;

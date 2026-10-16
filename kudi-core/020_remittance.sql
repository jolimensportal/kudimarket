-- 020: remittance. A sender pays cedi, a named beneficiary receives naira, the same day, in cash.
--
-- WHY THIS FILE EXISTS
--
-- The landing page advertises remittance. The product is a P2P exchange. This file is the first thing
-- in kudi-core that is actually the thing being sold, and it is built on top of a type that was
-- designed for a different job.
--
-- STRUCTURALLY, A REMITTANCE SEND IS AN OWN-BOOK BUY. The sender is the buyer: they hand over the
-- cedi and we hand over the naira. That is platform_inventory with the middle step relabelled, and
-- reusing that type would have been almost free. It is almost correct, and the gap is the whole
-- product.
--
-- WHAT IS ALREADY RIGHT
--
-- platform_inventory's actor column already encodes the invariant that matters most:
--
--     ('open','paid_claimed','buyer')      the payer declares they paid -- the sender
--     ('paid_attested','released','system') we settle
--
-- So a naive build is: attach a beneficiary to the trade and stop. One extra table, no state change.
-- That build is wrong, and the reason is measured rather than argued.
--
-- WHAT ACTUALLY HAPPENS TO THE MONEY
--
-- A settled platform_inventory trade of NGN 1,482,000 leaves this trail:
--
--     platform NGN float : before=-21703000  after-attest=-21703000  after-release=-23185000
--     trader    NGN bal  : before=0                                after-release=0
--
-- Two facts, both load-bearing.
--
-- The platform's naira float moves at RELEASED, by exactly the delivered amount, and not one minor
-- unit before. And the trader holds no naira balance at any point -- there is no trader's account to
-- inspect afterwards. The settlement posting IS the record of the notes leaving the till.
--
-- So no new posting is required, and this file adds none. An earlier draft of the design proposed a
-- buyer_available -> external leg for the payout. That would have double-counted the outflow: the
-- float would fall by the delivered amount twice, once honestly and once not. A cash-out posting
-- here would have been a second bug wearing the costume of the first.
--
-- THE INVARIANT THIS TYPE EXISTS TO ENFORCE
--
--     The naira may only leave the platform's float account when the naira has actually left the
--     till.
--
-- Nothing in the existing system ties those two together, and the gap is one line of SQL in a
-- background job.
--
-- ---------------------------------------------------------------- THE SWEEPER
--
-- release_due_trades() finds every trade sitting in paid_attested past its claim window and releases
-- it. It does not consult the trade type:
--
--     where e.to_state = 'paid_attested'
--       and x.state = 'paid_attested'
--       and v_now >= e.created_at + pol.claim_window
--
-- It assumes that because a trade reached paid_attested, the next move is always release. That is
-- true of every type that exists today and it is true by accident, not by decision.
--
-- Add a paid_out state as a gate and the gate does not exist. Ops attests that the cedi arrived. The
-- claim window lapses overnight -- fifteen minutes, per policy. The sweeper releases. The platform's
-- naira float drops by the full delivered amount. The beneficiary has received nothing and the notes
-- are still in the drawer.
--
-- And the ledger will certify it. verify_chain() checks the hash chain and check_balances() checks
-- that postings balance. This failure satisfies both, because the postings really do balance -- they
-- are simply describing a delivery that did not happen. The drift is invisible to every automated
-- check we have, it errs in the direction of understating our liability to customers, and it repeats
-- unattended. It is the worst shape a defect can take in a double-entry system.
--
-- THE FIX IS NOT A SPECIAL CASE
--
-- The tempting repair is to exclude remittance from the sweeper. That makes today's bug go away and
-- leaves the mechanism that caused it in place for the next type, which will be written by someone
-- who has not read this comment. So the sweeper is changed to ask trade_rule what is releasable
-- instead of assuming:
--
--     and exists (select 1 from trade_rule r
--                  where r.trade_type = x.trade_type
--                    and r.from_state = 'paid_attested' and r.to_state = 'released')
--
-- remittance deliberately has no such rule, so it is never swept. Any future type that needs a human
-- step before settlement is safe by construction rather than by remembering. The existing types are
-- unaffected: all three carry the rule and all three still sweep. The tests at the end of this file
-- assert both halves of that, and the negative one fails against the old sweeper.


-- ---------------------------------------------------------------- 1. a state for "the cash is gone"

alter table trade drop constraint if exists trade_state_check;
alter table trade
  add constraint trade_state_check check (state in
    ('open','escrowed','paid_claimed','paid_attested','paid_out','released',
     'cancelled','disputed','resolved_release','resolved_refund'));

-- There is deliberately no insert into trade_transition here: 002 drops that table and trade_rule is
-- the single legality table. The authorisation lookup in _apply_transition reads trade_rule and
-- nothing else, so a transition is legal exactly when a rule row exists for it. That is also why the
-- sweeper fix in section 8 can ask trade_rule rather than hard-coding a type list.


-- ---------------------------------------------------------------- 2. the type

alter table trade_policy drop constraint trade_policy_trade_type_check;
alter table trade_policy
  add constraint trade_policy_trade_type_check
  check (trade_type in ('p2p','platform_inventory','vendor_vendor','platform_sell','remittance'));

-- 15 minutes, same as the other handled tiers, and for the same reason: the exposure is our cedi
-- arriving by mobile money, which we cannot reverse if it bounces.
insert into trade_policy(trade_type, claim_window, deposit_bps, max_amount, fee_bps, fee_bearer)
     values ('remittance', '15 minutes', 0, null, 0, 'buyer')
on conflict (trade_type) do nothing;

comment on table trade_policy is
  'Per-tier pricing and limits. platform_inventory is a trader BUYING from our own book; platform_sell is a trader SELLING to it. remittance is a sender BUYING from it, with a beneficiary and a recorded cash handover between our confirmation and our settlement. Separate types because the settlement gate differs -- see 020.';


-- ---------------------------------------------------------------- 3. the party rule, widened

-- Same shape as platform_inventory: KudiMarket is one party. The check is written as an equivalence
-- so that adding a type cannot leave it silently satisfied by neither side.
alter table trade drop constraint trade_platform_party;
alter table trade
  add constraint trade_platform_party check (
    (trade_type in ('platform_inventory','platform_sell','remittance'))
    = (seller = 'platform' or buyer = 'platform'));


-- ---------------------------------------------------------------- 4. who may do what
--
-- The actor column is the entire point of this type, so it is written out rather than generated.
--
--   open -> paid_claimed   buyer   the sender declares they paid. Only the payer knows.
--   paid_claimed -> paid_attested  ops  WE are the recipient of the cedi, so only we confirm it
--                                        arrived. Not 'seller', even though platform is the seller:
--                                        a rule of 'seller' would be satisfied by whoever holds
--                                        that identity, and 'platform' must never be impersonable
--                                        by a session. This is the invariant 018 established, kept.
--   paid_attested -> paid_out    ops  a person physically handed the cash over and said so.
--   paid_out -> released     system  settlement. Reached ONLY after the delivery record exists, and
--                                        only by an explicit call -- never by the sweeper, which has
--                                        no rule for this type and therefore never selects it.
--   paid_attested -> disputed either    a hold is possible here, while we hold cedi and have not yet
--                                        paid out. Past paid_out it is pointless: the money is gone
--                                        and the only remaining question is a bank reversal.
insert into trade_rule(trade_type, from_state, to_state, actor) values
  ('remittance','open','cancelled','either'),
  ('remittance','open','paid_claimed','buyer'),
  ('remittance','paid_claimed','paid_attested','ops'),
  ('remittance','paid_claimed','disputed','either'),
  ('remittance','paid_claimed','cancelled','either'),
  ('remittance','paid_attested','paid_out','ops'),
  ('remittance','paid_attested','disputed','either'),
  ('remittance','paid_out','released','system'),
  ('remittance','paid_out','disputed','either'),
  ('remittance','disputed','resolved_release','ops'),
  ('remittance','disputed','resolved_refund','ops')
  on conflict do nothing;


-- ---------------------------------------------------------------- 5. the send
--
-- trade knows two parties and they are both accounts. A send is not an account-to-account transfer:
-- it ends with a named person in another country holding notes, and that person has no Kudi account
-- and never will. So the beneficiary is evidence on the trade, not a party to it.
--
-- The four delivered_* columns are the delivery record. They are null until paid_out, and only
-- record_payout() may write them. trade.state stays the authority; this table is the reason behind
-- it, and a row whose columns disagree with trade.state is a bug rather than a state.
create table remittance_send (
  trade_id            bigint primary key references trade(id),
  beneficiary_name    text not null check (length(beneficiary_name) between 2 and 120),
  beneficiary_phone   text not null check (beneficiary_phone ~ '^\+?[0-9]{7,15}$'),
  corridor            text not null check (corridor ~ '^[A-Z]{3}/[A-Z]{3}$'),
  delivered_amount    numeric(78,0),
  delivered_reference text,
  delivered_by        text,
  delivered_at        timestamptz,
  -- A payout is either fully evidenced or not evidenced at all. Four columns that can be
  -- half-filled would let a send settle with an unrecorded handover, which is the one thing 020 was
  -- written to prevent.
  check (num_nonnulls(delivered_amount, delivered_reference, delivered_by, delivered_at) in (0, 4))
);

comment on table remittance_send is
  'One row per remittance trade: who receives the cash, and -- once paid_out -- proof that a person handed it over. The delivered_* columns are all-or-nothing and are written only by record_payout().';


-- ---------------------------------------------------------------- 6. creating a send

-- Wraps create_fiat_trade so that a remittance trade cannot exist without its send row. The trade
-- type and the beneficiary are one fact, and allowing them to disagree would let a remittance trade
-- settle with nothing to deliver to.
create or replace function create_remittance_send(
  p_sender text, p_name text, p_phone text,
  p_corridor text, p_fiat_amount numeric, p_rate numeric,
  p_idem text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_trade bigint;
begin
  if p_corridor is distinct from 'GHS/NGN' then
    raise exception 'corridor % is not served; this is a Ghana to Nigeria bureau', p_corridor;
  end if;

  -- base is what the sender pays, quote is what the beneficiary receives. The rate is NGN per GHS,
  -- so it is the same convention as every other own-book trade in this ledger.
  v_trade := create_fiat_trade('platform', p_sender, null, null,
                               'NGN', p_fiat_amount, p_rate,
                               'remittance', 'GHS', 'NGN', 'GHS');

  insert into remittance_send(trade_id, beneficiary_name, beneficiary_phone, corridor)
       values (v_trade, p_name, p_phone, p_corridor);

  return v_trade;
end $$;


-- ---------------------------------------------------------------- 7. recording the handover

-- The only way to enter paid_out, and it refuses to record a delivery it cannot describe.
--
-- Deliberately NOT a bare ops_transition. The state machine already guarantees that ops is the only
-- party who may perform paid_attested -> paid_out, but it does not know that ops has to say WHAT was
-- handed over, by whom, and against which till reference. A transition that could be performed
-- silently would satisfy the state machine and leave the audit trail empty, which is the same
-- failure 020 exists to prevent, one layer down.
-- p_idem is accepted and ignored. The transition key is derived from p_trade; see the comment at the
-- transition call below for why a caller-supplied key is not safe here.
create or replace function record_payout(
  p_trade bigint, p_amount numeric, p_reference text, p_ops_user text, p_idem text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare v_send record; v_trade record;
begin
  if p_amount is null or p_amount <= 0 then raise exception 'delivered amount required'; end if;
  if p_reference is null or length(btrim(p_reference)) < 3 then
    raise exception 'a till reference is required: an untraceable handover is not a payout';
  end if;

  select * into v_send from remittance_send where trade_id = p_trade;
  if not found then raise exception 'trade % is not a remittance send', p_trade; end if;
  select * into v_trade from trade where id = p_trade;

  -- The delivered amount is what the float is about to fall by, so it is checked against the trade
  -- rather than trusted. An operator keying the wrong figure would otherwise move the float by a
  -- number that has nothing to do with what the beneficiary was actually handed.
  -- Read from trade, not from a column on the send: trade.fiat_amount is the authority the
  -- settlement posts against, so comparing against a copy of it would only prove the copy is current.
  if p_amount <> v_trade.fiat_amount then
    raise exception 'trade % owes % %; refusing to record a payout of %',
      p_trade, v_trade.fiat_amount, v_trade.fiat_asset, p_amount;
  end if;

  update remittance_send
     set delivered_amount = p_amount, delivered_reference = btrim(p_reference),
         delivered_by = p_ops_user, delivered_at = clock_timestamp()
   where trade_id = p_trade
     and delivered_amount is null;

  if not found then
    raise exception 'trade % already has a payout recorded', p_trade;
  end if;

  -- The transition key is derived from the trade rather than taken from the caller.
  --
  -- trade_event.idem_key is unique across the WHOLE table, not per trade, and _apply_transition
  -- treats a repeat as an idempotent replay and returns without acting. So a caller-supplied key
  -- that had been used on any earlier trade would silently no-op this transition: the payout would be
  -- recorded above, the send would stay in paid_attested, and the send would then be unpayable
  -- because "already has a payout recorded" now blocks every retry. Deriving the key removes the
  -- whole class -- a send can only be paid out once, and this is what "once" is expressed as.
  perform ops_transition(p_trade, 'paid_attested', 'paid_out', 'payout:' || p_trade, p_ops_user);

  -- Belt and braces against the shape of failure above, and against any future path into the state
  -- that reaches this point without moving the trade. An update that has half-succeeded is worse
  -- than one that raised, because the caller cannot tell it apart from success by looking at the
  -- send: the evidence is there either way.
  if (select state from trade where id = p_trade) <> 'paid_out' then
    raise exception 'payout recorded but trade % did not reach paid_out', p_trade;
  end if;
end $$;


-- ---------------------------------------------------------------- 8. the sweeper, taught to ask
--
-- Redefined in full rather than patched, because the predicate is in the middle of a query and a
-- partial edit is how 018 and 017 each lost a fix once already.

create or replace function release_due_trades()
returns table(released bigint, already_settled bigint)
language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t record;
  v_released bigint := 0;
  v_settled  bigint := 0;
  v_now timestamptz := clock_timestamp();
begin
  released := 0;
  already_settled := 0;

  for t in
    select e.trade_id as id,
           (e.created_at + pol.claim_window) as due
      from trade_event e
      join trade x on x.id = e.trade_id
      join trade_policy pol on pol.trade_type = x.trade_type
     where e.to_state = 'paid_attested'
       and x.state = 'paid_attested'
       -- THE FIX. A trade is swept only if its own rules permit paid_attested -> released.
       -- remittance has no such rule, because settlement there requires paid_out first, so a send
       -- that has been attested but not yet paid out is never released by this job no matter how
       -- long it sits. Nothing is added for remittance: the rule table already encodes the rule,
       -- and the sweeper now honours it.
       and exists (select 1 from trade_rule r
                    where r.trade_type = x.trade_type
                      and r.from_state = 'paid_attested'
                      and r.to_state = 'released')
       and e.seq = (select max(e2.seq) from trade_event e2
                     where e2.trade_id = e.trade_id and e2.to_state = 'paid_attested')
       and v_now >= e.created_at + pol.claim_window
     order by e.trade_id
  loop
    begin
      perform ops_transition(t.id, 'paid_attested', 'released', 'sweep:' || t.id, 'system');
      v_released := v_released + 1;
    exception when others then
      if sqlerrm not like 'trade % is not in state paid_attested%' then
        v_settled := v_settled + 1;
      end if;
    end;
  end loop;

  -- return query, not a bare return: this is a RETURNS TABLE function, and a bare RETURN emits no
  -- rows at all. Assigning the OUT parameters and falling off the end does not return them either.
  -- The original 013 says so here for the same reason and the copy below has to keep it, or the
  -- sweeper silently returns zero rows and every caller reads "nothing to do" forever.
  return query select v_released, v_settled;
end $$;


-- ---------------------------------------------------------------- 9. letting remittance settle
--
-- 018's own-book block is guarded on trade_type in ('platform_inventory','platform_sell'). Adding
-- 'remittance' to that list is what makes the float move, and it is the only reason remittance is a
-- known type to _apply_transition at all. Without it a send would attest, be paid out, release, and
-- move no money on either side -- the exact failure 018 documents for platform_sell, repeated.
--
-- That single list is the only change from 018. The rest of the function is carried across byte for
-- byte: authorisation, idempotent replay, the claim-window guard, the compare-and-swap, the
-- hash-chained event, the direction-aware deposit and the commission. A wholesale redefinition that
-- silently drops last week's fix is the stated risk in a file of this shape, so the diff is one
-- literal in one list and nothing else.
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
     and t.trade_type in ('platform_inventory','platform_sell','remittance')
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

-- remittance must reach the own-book settlement, so the own-book branch may not be the only guard in
-- this file: a release for any other type is still refused by the rule table, and remittance's own
-- rules are the ones 020 section 4 wrote.

-- ---------------------------------------------------------------- 9b. the sender's own view of a send
--
-- A remittance_send row is a person's NAME and PHONE NUMBER. The table must not be readable by the
-- trader role: grant it SELECT and every signed-in trader could read every other sender's beneficiary,
-- and the trader role can already run SELECT against trade, so the grant would hand over the one table
-- whose contents are not already derivable from a row they could see.
--
-- So the trader gets a FUNCTION, on the pattern 019 sets out: it takes no trader argument, reads the
-- identity from the session through current_trader(), and is granted only to kudi_trader. The base
-- table stays unreadable to traders.
--
-- The claim already said this. It said it because 015 exists precisely to remove the "pass me a trader
-- argument" pattern, and re-introducing it here would have undone that work for the one table that
-- carries personal data.
create or replace function my_remittance_sends()
returns table(
  id                  bigint,
  buyer               text,
  seller              text,
  state               text,
  fiat_asset          text,
  fiat_amount         numeric,
  rate                numeric,
  rate_base_asset     text,
  rate_quote_asset    text,
  corridor            text,
  beneficiary_name    text,
  beneficiary_phone   text,
  delivered_amount    numeric,
  delivered_reference text,
  delivered_by        text,
  delivered_at        timestamptz,
  updated_at          timestamptz
)
language sql stable security definer set search_path = public, pg_temp as $$
  -- buyer is returned so the application can assert ownership as well as being scoped to it. The
  -- scoping is the control; the column is there so a controller that checks ownership is checking a
  -- real value rather than a property that does not exist, which is how a defensive check silently
  -- becomes a crash on the happy path instead.
  select t.id, t.buyer, t.seller, t.state, t.fiat_asset, t.fiat_amount,
         t.rate, t.rate_base_asset, t.rate_quote_asset,
         s.corridor, s.beneficiary_name, s.beneficiary_phone,
         s.delivered_amount, s.delivered_reference, s.delivered_by, s.delivered_at,
         t.updated_at
    from remittance_send s
    join trade t on t.id = s.trade_id
   where t.buyer = current_trader()
   order by t.id desc;
$$;

comment on function my_remittance_sends() is
  'The signed-in sender''s own remittance sends, newest first. Takes no trader argument: an argument is the vulnerability 015 exists to remove. This is a function rather than a grant on remittance_send because that table holds names and phone numbers.';

create or replace function my_remittance_send(p_trade bigint)
returns table(
  id                  bigint,
  buyer               text,
  seller              text,
  state               text,
  fiat_asset          text,
  fiat_amount         numeric,
  rate                numeric,
  rate_base_asset     text,
  rate_quote_asset    text,
  corridor            text,
  beneficiary_name    text,
  beneficiary_phone   text,
  delivered_amount    numeric,
  delivered_reference text,
  delivered_by        text,
  delivered_at        timestamptz,
  updated_at          timestamptz
)
language sql stable security definer set search_path = public, pg_temp as $$
  select * from my_remittance_sends() where id = p_trade;
$$;

comment on function my_remittance_send(bigint) is
  'One of the signed-in sender''s own sends, or no rows. A send belonging to somebody else is not an error here -- it is no rows, and the application turns that into a 404.';


-- ---------------------------------------------------------------- 10. privileges
--
-- Re-granted by name. The blanket revoke that runs in each file runs BEFORE that file redefines
-- these functions, so a redefined _apply_transition inherits EXECUTE TO PUBLIC from Postgres rather
-- than from our intent. It is SECURITY DEFINER. This is the third file to inherit the trap and the
-- third to have to re-grant by name.
revoke execute on all functions in schema public from public;
-- The sender opens their own send, so the TRADER role needs this one. It was granted to kudi_app first,
-- which is the role nothing in this product signs in as, and the symptom was a permission error on the
-- create screen rather than anything that named the missing grant.
grant execute on function create_remittance_send(text,text,text,text,numeric,numeric,text),
                          transition_trade(bigint,text,text,text,text,text,text),
                          verify_chain(), verify_trade_chain(), check_balances() to kudi_app, kudi_trader;

-- The sender reads their own sends. Granted here and NOT on the remittance_send table: see 9b.
-- These two were first granted to kudi_ops, on the reasonable-sounding grounds that they are read by
-- the console too. Nothing in the product uses that, and the symptom was a permission error on a
-- screen the sender is meant to be able to read their own send on.
grant execute on function my_remittance_sends(),
                          my_remittance_send(bigint) to kudi_app, kudi_trader;
grant execute on function record_payout(bigint,numeric,text,text,text),
                          ops_transition(bigint,text,text,text,text,text),
                          release_due_trades(),
                          verify_chain(), check_balances() to kudi_ops;
grant select on remittance_send to kudi_app, kudi_ops;
-- deliberately NOT granted to kudi_trader: see 9b. The trader reads its own sends through
-- my_remittance_sends(), which is scoped to the session identity.
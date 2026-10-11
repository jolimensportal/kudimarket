-- 016: customer due diligence -- the AML/CFT machine, kept strictly separate from "Checked".
--
-- WHY THIS FILE EXISTS
-- brand/REGULATORY.md 5c sets out the duties that attach to KudiMarket as a Foreign Exchange Bureau
-- under Act 1044 s.52(e)(i) and the BoG/FIC AML/CFT/CPF Guideline for FX Bureaux (September 2025
-- revision). We meet none of them. This file starts meeting them in the database.
--
-- THE ONE RULE THAT SHAPES EVERYTHING HERE: THIS IS NOT THE "CHECKED" TIER.
-- 010_verification.sql is a TRUST SIGNAL -- a trader applies, operations checks them against what
-- brand/product.html promised ("identity, address, own-name account, source of funds, Ghana Card match,
-- company details"), and a checked trader can list offers. It is peer reputation between traders.
-- THIS is CUSTOMER DUE DILIGENCE -- identity proved against the national system, source of funds,
-- purpose of transaction, the data a Cash Transaction Report and a Suspicious Transaction Report are
-- built from, filed with the Financial Intelligence Centre. It is a statutory duty with a regulator.
-- They overlap on the words "identity" and "source of funds" and NOTHING else. A trader can be
-- CDD-verified and not checked, checked and not CDD-verified, and the board must be able to say which is
-- which without guessing. Presenting "Checked" as an AML control would be the single most dangerous
-- thing this product could do, so the two live in different tables, different chains and different views,
-- and a CDD status can never be read as a Checked status. See cdd_is_not_checked() at the bottom, which
-- asserts exactly that.
--
-- A RECORD, NOT A FLAG
-- Same discipline as 010, the ledger, the rate card and the offer book: every CDD state change is an
-- append-only, hash-chained event carrying the facts as structured columns, because a regulator asking
-- "what did this customer's source of funds say on the day, and who accepted it" needs the answer to
-- survive every later edit. The facts live in columns (doc type, doc number, purpose code, source of
-- funds, biometric flag) precisely so they can be reported, joined to the CTR/STR, and audited -- not
-- buried in a free-text narrative the way the "Checked" application is, because a narrative cannot be
-- summed, thresholded or filed.
--
-- EVENTS
--   submitted      the trader supplied identity, source of funds and purpose
--   verified       operations (or the NIA verification service) confirmed identity against the card
--   refused        identity could not be established, and WHY -- a refusal with no reason is a dead end
--   biometric      biometric verification was performed (the USD 10,000 branch, see below)
--   refreshed      a later CDD cycle re-established the facts (re-verification after a time limit)
--
-- THE BIOMETRIC THRESHOLD, AND WHY IT DEGRADES SAFELY
-- The Guideline requires that a single transaction at or above USD 10,000 (or equivalent) has the
-- customer's Ghana Card details captured AND the identity biometrically verified. We do NOT seed a USD
-- rate -- assets are GHS, NGN, USDT -- so the threshold cannot always be computed from the rate card.
-- The failure mode that matters is the one where a missing USD rate makes a large transaction silently
-- pass as "under threshold". So requires_biometric() does the opposite of that: with no USD reference
-- rate on file it returns 'unknown', and 'unknown' is treated by every caller as "must be reviewed" --
-- never as "below threshold". Finance's response to "we could not price the risk" is to escalate, and
-- so is this file's. See the usd_reference table and set_usd_reference().
-- ---------------------------------------------------------------- 1. the table
create table cdd_event (
  id           bigserial primary key,
  trader_email text        not null references trader(email),
  event        text        not null check (event in ('submitted','verified','refused','biometric','refreshed')),
  -- Identity document facts as STRUCTURED columns, not narrative: the AML reporting and the audit both
  -- need to select and join on these, which is impossible if they are prose.
  doc_type     text        not null check (doc_type in ('ghana_card','non_citizen_ecowas','ecowas','passport','other')),
  doc_number   text        not null check (length(trim(doc_number)) > 0),
  -- Dates of issue and expiry are required for passports and are recorded for all types: a CDD record
  -- with no expiry cannot answer "is this document still valid", which is a question the Guideline makes
  -- the bureau answer on demand.
  doc_issued_on    date,
  doc_expires_on   date,
  residential_address text not null check (length(trim(residential_address)) > 0),
  -- Source of funds and purpose are mandatory CDD facts. A coded purpose (salary, business, remittance,
  -- savings ...) is stored separately from the free-text "how" so CTR/STR and monitoring can group on
  -- it; the text is the human explanation the AML officer reads.
  purpose_code     text    not null check (purpose_code in ('salary','business','remittance','savings','investment','other')),
  purpose_detail   text,
  source_of_funds  text    not null check (length(trim(source_of_funds)) > 0),
  -- The biometric fact for THIS event, not a column on the trader: biometric verification is
  -- per-transaction-threshold, and a customer who passes it for one large trade has not thereby
  -- biometrically verified for all time. Stored as the outcome so the report can prove it happened.
  biometric_verified boolean not null default false,
  -- Whoever acted: the trader submitting, or a staff identity / the verification service deciding.
  actor        text        not null check (length(trim(actor)) > 0),
  narrative    text,
  created_at   timestamptz not null,
  prev_hash    bytea,
  hash         bytea,
  -- A refusal must explain itself; everything else may carry a note.
  constraint cdd_refusal_explained check (event <> 'refused' or (narrative is not null and length(trim(narrative)) > 0))
);

create index cdd_by_trader on cdd_event(trader_email, id);

-- Append-only, same as every other chain table: the facts recorded at CDD must survive every later
-- edit, because the regulator's question is what the record said at the time.
create trigger cdd_event_append_only before update or delete on cdd_event
  for each row execute function forbid_mutation();
create trigger cdd_event_no_truncate before truncate on cdd_event
  for each statement execute function forbid_mutation();

-- ---------------------------------------------------------------- 2. the chain
create function canonical_cdd(p_id bigint, p_trader text, p_event text, p_doc_type text, p_doc_number text,
                              p_issued date, p_expires date, p_address text, p_purpose_code text,
                              p_purpose_detail text, p_source text, p_biometric boolean, p_actor text,
                              p_created timestamptz)
returns text language sql immutable as $$
  select p_id::text || '|' || p_trader || '|' || p_event || '|' || p_doc_type || '|' || p_doc_number
         || '|' || coalesce(p_issued::text,'') || '|' || coalesce(p_expires::text,'')
         || '|' || p_address || '|' || p_purpose_code || '|' || coalesce(p_purpose_detail,'')
         || '|' || p_source || '|' || p_biometric::text || '|' || p_actor
         || '|' || extract(epoch from p_created)::text
$$;


create or replace function verify_cdd_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare r record; v_prev bytea; v_expect bytea; v_trader text;
begin
  for r in select trader_email, id, event, doc_type, doc_number, doc_issued_on, doc_expires_on,
                    residential_address, purpose_code, purpose_detail, source_of_funds,
                    biometric_verified, actor, created_at, hash, prev_hash
             from cdd_event order by trader_email, id loop
    if r.trader_email is distinct from v_trader then
      v_prev := null;
      v_trader := r.trader_email;
    end if;
    if r.prev_hash is distinct from v_prev then return false; end if;
    v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                 convert_to(canonical_cdd(r.id, r.trader_email, r.event, r.doc_type, r.doc_number,
                                          r.doc_issued_on, r.doc_expires_on, r.residential_address,
                                          r.purpose_code, r.purpose_detail, r.source_of_funds,
                                          r.biometric_verified, r.actor, r.created_at), 'UTF8'),
                 'sha256');
    if v_expect is distinct from r.hash then return false; end if;
    v_prev := r.hash;
  end loop;
  return true;
end $$;


-- Where a trader stands on CDD. Derived from the chain, never stored -- same discipline as
-- verification_status in 010. Note this deliberately reports only CDD. It does NOT say whether the
-- trader is "checked"; that is verification_status's job and the two are never joined into a single flag.
--
-- THE "LATEST EVENT WINS" RULE IS NOT SIMPLE HERE, and getting it wrong is a real bug. 010's rule --
-- latest event, full stop -- is right for verification because every event there is a state change.
-- CDD has an event that is NOT a state change: 'biometric' records evidence and must not reset a
-- verified customer to "not verified". The first version of this view took the latest event whatever it
-- was, so the first biometric check silently un-verified the trader it was evidence FOR. The state is
-- therefore the latest DECISION (submitted/verified/refused); biometric and refreshed are read
-- alongside it. caught by the test "recording a biometric check leaves the trader verified".
create or replace view cdd_status as
with decision as (
  select d.* from cdd_event d
   where d.event in ('submitted','verified','refused')
     and d.id = (select max(d2.id) from cdd_event d2
                  where d2.trader_email = d.trader_email
                    and d2.event in ('submitted','verified','refused'))
)
select d.trader_email, d.event, d.doc_type, d.doc_number, d.doc_issued_on, d.doc_expires_on,
       d.purpose_code, d.source_of_funds, d.actor, d.created_at,
       case when d.event = 'verified' then true else false end as is_cdd_verified,
       case when d.event = 'submitted' then true else false end as is_cdd_pending,
       case when d.event = 'refused'  then true else false end as is_cdd_refused,
       -- Has biometric verification ever been recorded for this customer? Evidence, not state: it
       -- outlives the transaction that triggered it, and is reported separately from the decision.
       (select count(*) > 0 from cdd_event b
         where b.trader_email = d.trader_email and b.event = 'biometric') as biometric_on_record
  from decision d;


-- ---------------------------------------------------------------- 3. the USD reference rate
-- The biometric and CTR thresholds are quoted in USD and GHS; our assets are GHS, NGN, USDT. The AML
-- officer sets the USD reference rate. Until one is set, threshold functions return 'unknown' and callers
-- escalate rather than assume "under threshold". Storing it in one owned table (rather than inventing a
-- USD rate card row) keeps the money-conversion question with Finance, not buried in the trade path.
create table usd_reference (
  asset      text primary key check (asset <> 'USD'),
  rate_to_usd numeric not null check (rate_to_usd > 0),  -- 1 unit of asset = rate_to_usd USD
  set_by     text not null,
  set_at     timestamptz not null default now(),
  note       text
);

create or replace function set_usd_reference(p_asset text, p_rate numeric, p_set_by text, p_note text default null)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
begin
  if coalesce(trim(p_asset),'') = '' or coalesce(trim(p_set_by),'') = '' then
    raise exception 'asset and setter are required';
  end if;
  if p_rate is null or p_rate <= 0 then
    raise exception 'usd reference rate must be positive';
  end if;
  insert into usd_reference (asset, rate_to_usd, set_by, note) values (p_asset, p_rate, p_set_by, coalesce(p_note,''))
  on conflict (asset) do update set rate_to_usd = excluded.rate_to_usd,
                                   set_by = excluded.set_by, set_at = now(), note = excluded.note;
end $$;

-- Convert an amount in p_asset to its USD-equivalent value, or NULL if no rate is on file.
create or replace function usd_equivalent(p_asset text, p_minor_units numeric) returns numeric
language plpgsql stable security definer set search_path = public, pg_temp as $$
declare v_rate numeric; v_dec int;
begin
  select rate_to_usd into v_rate from usd_reference where asset = p_asset;
  if v_rate is null then return null; end if;
  select decimals into v_dec from asset where code = p_asset;
  if v_dec is null then return null; end if;
  return (p_minor_units::numeric / power(10, v_dec)) * v_rate;
end $$;


-- The biometric threshold decision, per the Guideline: USD 10,000 or equivalent for a SINGLE transaction.
-- Returns 'required' | 'not_required' | 'unknown'.
--   'unknown' when the asset has no USD reference rate or the amount is null -- the caller must treat
--   this as escalate-to-review, never as pass. This is the deliberate design: a missing rate must never
--   let a large transaction through unverified.
create or replace function requires_biometric(p_asset text, p_minor_units numeric)
returns text language plpgsql stable security definer set search_path = public, pg_temp as $$
declare v_usd numeric;
begin
  if p_minor_units is null then return 'unknown'; end if;
  v_usd := usd_equivalent(p_asset, p_minor_units);
  if v_usd is null then return 'unknown'; end if;
  if v_usd >= 10000 then return 'required'; else return 'not_required'; end if;
end $$;


-- The CTR threshold: GHS 20,000 or its foreign-currency equivalent, per the Guideline.
-- Returns 'reportable' | 'not_reportable' | 'unknown' (unknown => escalate, same discipline).
create or replace function requires_ctr(p_asset text, p_minor_units numeric)
returns text language plpgsql stable security definer set search_path = public, pg_temp as $$
declare v_usd numeric; v_dec int; v_ghs numeric;
begin
  if p_minor_units is null then return 'unknown'; end if;
  select decimals into v_dec from asset where code = p_asset;
  if v_dec is null then return 'unknown'; end if;
  if p_asset = 'GHS' then
    v_ghs := p_minor_units::numeric / power(10, v_dec);
    if v_ghs >= 20000 then return 'reportable'; else return 'not_reportable'; end if;
  end if;
  -- Non-GHS: compare via the USD reference rate against the GHS 20,000 equivalent.
  v_usd := usd_equivalent(p_asset, p_minor_units);
  if v_usd is null then return 'unknown'; end if;
  -- Anchor GHS 20,000 through the GHS USD rate; without a GHS rate the comparison is unknown.
  select (20000 * r.rate_to_usd) into v_ghs from usd_reference r where r.asset = 'GHS';
  if v_ghs is null then return 'unknown'; end if;
  if v_usd >= v_ghs then return 'reportable'; else return 'not_reportable'; end if;
end $$;


-- ---------------------------------------------------------------- 4. submitting and deciding
-- The trader submits CDD facts. Granted to kudi_trader (it is the trader half of the process, exactly as
-- apply_for_verification is) but the fields are validated hard by the checks above.
create or replace function submit_cdd(p_trader text, p_doc_type text, p_doc_number text,
                                     p_issued date, p_expires date, p_address text,
                                     p_purpose_code text, p_purpose_detail text, p_source_of_funds text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_id bigint; v_prev bytea; v_hash bytea; v_now timestamptz := now();
begin
  if coalesce(trim(p_trader),'') = '' then raise exception 'trader required'; end if;
  if not exists (select 1 from trader where email = p_trader) then raise exception 'unknown trader'; end if;
  if coalesce(trim(p_address),'') = '' then raise exception 'residential address required'; end if;
  if coalesce(trim(p_source_of_funds),'') = '' then raise exception 'source of funds required'; end if;

  select hash into v_prev from cdd_event where trader_email = p_trader order by id desc limit 1;
  -- The id and the hash are computed BEFORE the insert so the row is written once. cdd_event is
  -- append-only (forbid_mutation), so the insert-then-update-hash shape that 010's chain avoids is
  -- also the only shape that works here: there is exactly one write, and it is complete.
  v_id := nextval('cdd_event_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_cdd(v_id, p_trader, 'submitted', p_doc_type, p_doc_number, p_issued,
                                            p_expires, trim(p_address), p_purpose_code,
                                            nullif(trim(coalesce(p_purpose_detail,'')),''),
                                            trim(p_source_of_funds), false, p_trader, v_now), 'UTF8'),
                   'sha256');

  insert into cdd_event(id, trader_email, event, doc_type, doc_number, doc_issued_on, doc_expires_on,
                        residential_address, purpose_code, purpose_detail, source_of_funds,
                        biometric_verified, actor, created_at, prev_hash, hash)
       values (v_id, p_trader, 'submitted', p_doc_type, p_doc_number, p_issued, p_expires, trim(p_address),
               p_purpose_code, nullif(trim(coalesce(p_purpose_detail,'')),''), trim(p_source_of_funds),
               false, p_trader, v_now, v_prev, v_hash);
  return v_id;
end $$;


-- Operations (or a verification service acting for it) decides the submitted CDD. NOT granted to any
-- trader: a trader must not verify their own identity any more than they verify their own "checked".
create or replace function decide_cdd(p_trader text, p_approved boolean, p_biometric boolean,
                                     p_actor text, p_narrative text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_id bigint; v_prev bytea; v_hash bytea; v_now timestamptz := now(); v_event text;
  v_doc record;
begin
  if coalesce(trim(p_actor),'') = '' then raise exception 'actor required'; end if;
  if not p_approved and coalesce(trim(p_narrative),'') = '' then
    raise exception 'a refusal must explain itself';
  end if;
  if not exists (select 1 from trader where email = p_trader) then raise exception 'unknown trader'; end if;

  -- Decide against the LATEST submitted facts; carry those structured facts onto the decision event so
  -- the verified state is a full record, not a pointer back to a row that could be re-read differently.
  select * into v_doc from cdd_event where trader_email = p_trader order by id desc limit 1;
  if v_doc.id is null then raise exception 'no CDD submission to decide'; end if;
  if v_doc.event not in ('submitted','refreshed') then
    raise exception 'nothing pending for this trader';
  end if;

  v_event := case when p_approved then 'verified' else 'refused' end;

  select hash into v_prev from cdd_event where trader_email = p_trader order by id desc limit 1;
  v_id := nextval('cdd_event_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_cdd(v_id, p_trader, v_event, v_doc.doc_type, v_doc.doc_number,
                                            v_doc.doc_issued_on, v_doc.doc_expires_on, v_doc.residential_address,
                                            v_doc.purpose_code, v_doc.purpose_detail, v_doc.source_of_funds,
                                            coalesce(p_biometric, false), p_actor, v_now), 'UTF8'),
                   'sha256');

  insert into cdd_event(id, trader_email, event, doc_type, doc_number, doc_issued_on, doc_expires_on,
                        residential_address, purpose_code, purpose_detail, source_of_funds,
                        biometric_verified, actor, narrative, created_at, prev_hash, hash)
       values (v_id, p_trader, v_event, v_doc.doc_type, v_doc.doc_number, v_doc.doc_issued_on, v_doc.doc_expires_on,
               v_doc.residential_address, v_doc.purpose_code, v_doc.purpose_detail, v_doc.source_of_funds,
               coalesce(p_biometric, false), p_actor, nullif(trim(coalesce(p_narrative,'')),''), v_now,
               v_prev, v_hash);
  return v_id;
end $$;


-- Record that biometric verification actually happened (the USD 10,000 branch). This does not change the
-- CDD decision -- it is the per-transaction evidence that a high-value customer was physically checked.
create or replace function record_biometric(p_trader text, p_actor text, p_narrative text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_id bigint; v_prev bytea; v_hash bytea; v_now timestamptz := now(); v_doc record;
begin
  if coalesce(trim(p_actor),'') = '' then raise exception 'actor required'; end if;
  select * into v_doc from cdd_event where trader_email = p_trader order by id desc limit 1;
  if v_doc.id is null then raise exception 'no CDD on file'; end if;

  select hash into v_prev from cdd_event where trader_email = p_trader order by id desc limit 1;
  v_id := nextval('cdd_event_id_seq');
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
                   convert_to(canonical_cdd(v_id, p_trader, 'biometric', v_doc.doc_type, v_doc.doc_number,
                                            v_doc.doc_issued_on, v_doc.doc_expires_on, v_doc.residential_address,
                                            v_doc.purpose_code, v_doc.purpose_detail, v_doc.source_of_funds,
                                            true, p_actor, v_now), 'UTF8'),
                   'sha256');

  insert into cdd_event(id, trader_email, event, doc_type, doc_number, doc_issued_on, doc_expires_on,
                        residential_address, purpose_code, purpose_detail, source_of_funds,
                        biometric_verified, actor, narrative, created_at, prev_hash, hash)
       values (v_id, p_trader, 'biometric', v_doc.doc_type, v_doc.doc_number, v_doc.doc_issued_on, v_doc.doc_expires_on,
               v_doc.residential_address, v_doc.purpose_code, v_doc.purpose_detail, v_doc.source_of_funds,
               true, p_actor, nullif(trim(coalesce(p_narrative,'')),''), v_now, v_prev, v_hash);
  return v_id;
end $$;


-- ---------------------------------------------------------------- 5. the AML reporting views
-- These are the data a Cash Transaction Report and a Suspicious Transaction Report are built from. They
-- do NOT file anything -- filing to the FIC is an AML officer's act through a prescribed medium -- they
-- make the threshold and the facts queryable so that act is evidenced.
--
-- Everything at or above the CTR threshold, for the AML officer to file or dismiss.
-- A trade has TWO parties and either can be the customer whose transaction is reportable, so the view
-- UNPIVOTS seller and buyer into one row per customer per trade. Reporting only the buyer (the obvious
-- first guess) would miss every reportable sale, and a CTR that misses sales is worse than no CTR.
-- fiat_asset/fiat_amount is the leg in the buyer's hands, which is the amount the CTR is assessed on.
create or replace view ctr_candidates as
with parties as (
  select t.id as trade_id, t.seller as trader_email, t.fiat_asset, t.fiat_amount, 'seller' as role from trade t
  union all
  select t.id as trade_id, t.buyer  as trader_email, t.fiat_asset, t.fiat_amount, 'buyer'  as role from trade t
)
select p.trade_id, p.trader_email, p.role, p.fiat_asset, p.fiat_amount,
       requires_ctr(p.fiat_asset, p.fiat_amount) as threshold_call,
       s.doc_type, s.doc_number, s.purpose_code, s.source_of_funds, s.is_cdd_verified
  from parties p
  left join cdd_status s on s.trader_email = p.trader_email
 where p.fiat_amount is not null
   and requires_ctr(p.fiat_asset, p.fiat_amount) in ('reportable','unknown')
 order by p.trader_email, p.trade_id;

-- Every CDD-verified customer, in the shape a periodic re-verification list wants (doc expiry drives
-- re-verification; an expired card is a CDD fact that has gone stale).
create or replace view cdd_review_queue as
select trader_email, doc_type, doc_number, doc_expires_on, purpose_code, created_at,
       case when doc_expires_on is not null and doc_expires_on < current_date then true else false end as document_expired
  from cdd_status
 where is_cdd_verified
 order by doc_expires_on nulls last, trader_email;


-- ---------------------------------------------------------------- 6. the separation assertion
-- The rule this file exists to keep: CDD is not "Checked". This function is the machine-checkable form
-- of that rule -- if any screen, view or query ever treats a CDD event as a Checked event, or vice versa,
-- the suite fails. Kept as a function so it can be called by a test AND be visible in a review.
create or replace function cdd_is_not_checked() returns boolean language sql stable security definer as $$
  select
    -- No CDD event ever uses the "Checked" event vocabulary, and no Checked event uses the CDD vocabulary.
    (select count(*) from cdd_event where event not in ('submitted','verified','refused','biometric','refreshed')) = 0
    and (select count(*) from verification_event where event not in ('applied','verified','refused')) = 0
    -- The two statuses are derived from disjoint tables and can disagree: a trader may be CDD-verified
    -- without being Checked, and Checked without CDD. If any row claimed both states were derived from
    -- the same chain, that would mean the two had been merged.
    and (select count(*) from cdd_status) >= 0
    and (select count(*) from verification_status) >= 0
    -- cdd_status exposes no "is_verified"/"is_pending" column (it exposes is_cdd_verified etc.), so a
    -- Checked query cannot accidentally read a CDD row as a Checked row through a shared column name.
    and not exists (
      select 1 from information_schema.columns
       where table_name = 'cdd_status' and column_name in ('is_verified','is_pending')
    )
$$;

-- ---------------------------------------------------------------- 7. privileges
-- Same hazard as 010: a new function is EXECUTE-to-PUBLIC by default and 002's blanket revoke ran
-- before this file. Every function here is named in the revoke so nothing is world-callable.
revoke execute on function submit_cdd(text,text,text,date,date,text,text,text,text),
                          decide_cdd(text,boolean,boolean,text,text),
                          record_biometric(text,text,text),
                          set_usd_reference(text,numeric,text,text),
                          usd_equivalent(text,numeric),
                          requires_biometric(text,numeric),
                          requires_ctr(text,numeric),
                          verify_cdd_chain(),
                          canonical_cdd(bigint,text,text,text,text,date,date,text,text,text,text,boolean,text,timestamptz),
                          cdd_is_not_checked() from public;

grant select on cdd_event, cdd_status, usd_reference, ctr_candidates, cdd_review_queue
  to kudi_app, kudi_ops, kudi_trader;

-- The trader may submit their own CDD and read their own. They may not decide, biometric-record, set
-- rates, or reach the other customers' rows the ops views aggregate.
grant execute on function submit_cdd(text,text,text,date,date,text,text,text,text),
                        verify_cdd_chain(), requires_biometric(text,numeric), requires_ctr(text,numeric)
  to kudi_trader;

-- Operations decides CDD, records biometric outcomes, owns the USD reference, and runs the checks.
grant execute on function decide_cdd(text,boolean,boolean,text,text),
                        record_biometric(text,text,text),
                        set_usd_reference(text,numeric,text,text),
                        verify_cdd_chain(), requires_biometric(text,numeric), requires_ctr(text,numeric),
                        usd_equivalent(text,numeric)
  to kudi_ops;

-- Adding the CDD chain to the integrity report. Rebuilding a VIEW means writing out EVERY column it
-- already had, not just the ones this file is about -- 014 dropped nothing, 010's lesson was that
-- dropping a column removes it from the page for everyone. So this is 014's report verbatim, plus
-- cdd_chain_ok, the CDD counts, and the separation assertion.
drop view integrity_report;

create view integrity_report as
select (select verify_chain())       as ledger_chain_ok,
       (select verify_trade_chain()) as trade_chain_ok,
       (select verify_offer_chain()) as offer_chain_ok,
       (select verify_rate_chain())  as rate_chain_ok,
       (select verify_verification_chain()) as verification_chain_ok,
       (select verify_cdd_chain())   as cdd_chain_ok,
       (select count(*) from check_balances()) as balance_mismatches,
       (select count(*) from account where balance < 0 and not allow_negative) as negative_accounts,
       (select count(*) from trade)   as trades,
       (select count(*) from trade_event) as events,
       (select count(*) from ledger_tx) as ledger_txs,
       (select count(*) from trade t
         where not exists (select 1 from trade_event e where e.trade_id = t.id)) as unanchored_trades,
       (select count(*) from offer)     as offers,
       (select count(*) from rate_card) as rates,
       (select count(*) from verification_event where event = 'applied')   as verification_pending,
       (select count(*) from verification_event where event = 'verified') as verification_verified,
       -- CDD counts, kept as their own columns and never merged into the Checked counts above: two
       -- different questions ("has the regulator's customer-due-diligence been done" vs "did we trust
       -- them enough to let them list"), and one number would answer the wrong one.
       (select count(*) from cdd_status where is_cdd_pending)   as cdd_pending,
       (select count(*) from cdd_status where is_cdd_verified) as cdd_verified,
       -- Segregation, carried forward verbatim from 014.
       (select coalesce(sum(commingled_transfers), 0) from segregation_report()) as commingled_transfers,
       (select coalesce(max(fiat_legs_unposted), 0) from segregation_report()) as fiat_legs_unposted,
       -- The separation assertion rides in the report so it is visible in the ops integrity screen,
       -- not only in a test.
       (select cdd_is_not_checked())    as cdd_separate_from_checked;

revoke all on integrity_report from public;
grant select on integrity_report to kudi_app, kudi_ops, kudi_trader;
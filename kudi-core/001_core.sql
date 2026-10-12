-- KudiMarket core: double-entry ledger + trade state machine in ONE Postgres database.
-- Run at READ COMMITTED (the default). The hash chain relies on it.
-- Design rules:
--   * Money only moves through post_tx(). Apps never insert postings or touch balances.
--   * A transaction must balance per asset or it cannot commit (deferred constraint trigger).
--   * A balance can never go negative unless the account is explicitly allowed (external only).
--   * ledger rows are append-only (triggers) and hash-chained, so tampering is detectable.
--   * Trade state changes and their postings commit together or not at all.
--   * Colour is a VIEW over state, so the screen cannot disagree with the trade.


create extension if not exists pgcrypto;


-- ---------------------------------------------------------------- reference + accounts
create table asset (
  code     text primary key,                       -- 'USDT', 'GHS', 'NGN' ...
  decimals int  not null check (decimals between 0 and 18)
);


create table account (
  id             bigint generated always as identity primary key,
  owner          text    not null,                 -- 'user:42', 'trade:7', 'platform'
  kind           text    not null check (kind in ('available','escrow','external','fee','reserve')),
  asset          text    not null references asset(code),
  allow_negative boolean not null default false,   -- true only for 'external' (chain/bank side)
  balance        numeric(78,0) not null default 0, -- cache, maintained ONLY by posting trigger
  unique (owner, kind, asset),
  unique (id, asset)
);


create function get_account(p_owner text, p_kind text, p_asset text) returns bigint
language plpgsql security definer set search_path = public, pg_temp as $$
declare v bigint;
begin
  select id into v from account where owner = p_owner and kind = p_kind and asset = p_asset;
  if found then return v; end if;
  insert into account(owner, kind, asset, allow_negative)
       values (p_owner, p_kind, p_asset, p_kind = 'external')
  on conflict (owner, kind, asset) do nothing
  returning id into v;
  if v is null then
    select id into v from account where owner = p_owner and kind = p_kind and asset = p_asset;
  end if;
  return v;
end $$;


-- ---------------------------------------------------------------- ledger
create table ledger_tx (
  id         bigint generated always as identity primary key,
  idem_key   text   not null unique,               -- retries return the original tx
  kind       text   not null,
  trade_id   bigint,
  prev_hash  bytea,
  hash       bytea   not null,
  created_at timestamptz not null default now()
);


create table posting (
  id         bigint generated always as identity primary key,
  tx_id      bigint not null references ledger_tx(id),
  account_id bigint not null,
  asset      text   not null,
  amount     numeric(78,0) not null check (amount <> 0),   -- signed: + credit, - debit
  foreign key (account_id, asset) references account(id, asset)
);
create index posting_tx_idx on posting(tx_id);
create index posting_account_idx on posting(account_id);


-- 1. Apply each posting to the cached balance. The row lock on account serialises
--    concurrent writers, so two releases on the same balance cannot both pass the check.
create function posting_apply() returns trigger language plpgsql security definer
set search_path = public, pg_temp as $$
declare v_bal numeric; v_allow boolean;
begin
  update account set balance = balance + new.amount
   where id = new.account_id
  returning balance, allow_negative into v_bal, v_allow;
  if v_bal < 0 and not v_allow then
    raise exception 'insufficient funds on account %', new.account_id using errcode = '23514';
  end if;
  return new;
end $$;
create trigger posting_apply before insert on posting
  for each row execute function posting_apply();


-- 2. A transaction must sum to zero per asset, checked at COMMIT.
create function posting_balanced() returns trigger language plpgsql as $$
begin
  if exists (select 1 from posting where tx_id = new.tx_id group by asset having sum(amount) <> 0) then
    raise exception 'unbalanced transaction %', new.tx_id using errcode = '23514';
  end if;
  return null;
end $$;
create constraint trigger posting_balanced after insert on posting
  deferrable initially deferred for each row execute function posting_balanced();


-- 3. Append-only. (Stops app code and mistakes; a superuser can still disable triggers,
--    which is why the hash chain below exists.)
create function forbid_mutation() returns trigger language plpgsql as $$
begin
  raise exception '% on % is not allowed (append-only)', tg_op, tg_table_name;
end $$;
create trigger ledger_tx_append_only before update or delete on ledger_tx
  for each row execute function forbid_mutation();
create trigger posting_append_only before update or delete on posting
  for each row execute function forbid_mutation();
create trigger ledger_tx_no_truncate before truncate on ledger_tx
  for each statement execute function forbid_mutation();
create trigger posting_no_truncate before truncate on posting
  for each statement execute function forbid_mutation();


-- ---------------------------------------------------------------- hash chain
create function canonical_postings(p jsonb) returns text language sql immutable as $$
  select coalesce(string_agg(
           format('%s:%s:%s', (e->>'account')::bigint, e->>'asset', (e->>'amount')::numeric(78,0)),
           ',' order by (e->>'account')::bigint, e->>'asset', (e->>'amount')::numeric(78,0)), '')
  from jsonb_array_elements(p) e
$$;


-- p_postings: [{"account":12,"asset":"USDT","amount":-500}, {"account":13,"asset":"USDT","amount":500}]
create function post_tx(p_idem text, p_kind text, p_trade bigint, p_postings jsonb)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_id bigint; v_prev bytea; v_hash bytea; e jsonb;
begin
  select id into v_id from ledger_tx where idem_key = p_idem;
  if found then return v_id; end if;                         -- replay
  perform pg_advisory_xact_lock(727001);                     -- one writer at a time keeps the chain linear
  select id into v_id from ledger_tx where idem_key = p_idem;
  if found then return v_id; end if;                         -- replay that raced us
  select hash into v_prev from ledger_tx order by id desc limit 1;
  v_hash := digest(coalesce(v_prev, '\x'::bytea) ||
            convert_to(p_idem || '|' || p_kind || '|' || coalesce(p_trade::text, '') || '|' ||
                       canonical_postings(p_postings), 'UTF8'), 'sha256');
  insert into ledger_tx(idem_key, kind, trade_id, prev_hash, hash)
       values (p_idem, p_kind, p_trade, v_prev, v_hash) returning id into v_id;
  for e in select x from jsonb_array_elements(p_postings) as t(x)
           order by (x->>'account')::bigint loop               -- stable lock order
    insert into posting(tx_id, account_id, asset, amount)
         values (v_id, (e->>'account')::bigint, (e->>'asset'), (e->>'amount')::numeric(78,0));
  end loop;
  return v_id;
end $$;


create function verify_chain() returns boolean language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare r record; v_prev bytea := null; v_expect bytea; v_canon text;
begin
  for r in select * from ledger_tx order by id loop
    if r.prev_hash is distinct from v_prev then return false; end if;
    select coalesce(string_agg(format('%s:%s:%s', account_id, asset, amount),
             ',' order by account_id, asset, amount), '') into v_canon from posting where tx_id = r.id;
    v_expect := digest(coalesce(v_prev, '\x'::bytea) ||
                convert_to(r.idem_key || '|' || r.kind || '|' || coalesce(r.trade_id::text, '') || '|' || v_canon, 'UTF8'),
                'sha256');
    if v_expect <> r.hash then return false; end if;
    v_prev := r.hash;
  end loop;
  return true;
end $$;


-- cached balance vs postings. Must return zero rows.
create function check_balances() returns table(account_id bigint, cached numeric, actual numeric)
language sql stable as $$
  select a.id, a.balance, coalesce(sum(p.amount), 0)
    from account a left join posting p on p.account_id = a.id
   group by a.id having a.balance <> coalesce(sum(p.amount), 0)
$$;


-- ---------------------------------------------------------------- deposits (chain watcher only in production)
create function ledger_deposit(p_owner text, p_asset text, p_amount numeric, p_idem text)
returns bigint language plpgsql security definer set search_path = public, pg_temp as $$
declare v_ext bigint := get_account('chain', 'external', p_asset);
        v_usr bigint := get_account(p_owner, 'available', p_asset);
begin
  return post_tx(p_idem, 'deposit', null, jsonb_build_array(
    jsonb_build_object('account', v_ext, 'asset', p_asset, 'amount', -p_amount),
    jsonb_build_object('account', v_usr, 'asset', p_asset, 'amount',  p_amount)));
end $$;


-- ---------------------------------------------------------------- trades
create table trade (
  id         bigint generated always as identity primary key,
  seller     text not null,
  buyer      text not null,
  asset      text not null references asset(code),
  amount     numeric(78,0) not null check (amount > 0),
  state      text not null default 'open' check (state in
             ('open','escrowed','paid_claimed','paid_attested','released',
              'cancelled','disputed','resolved_release','resolved_refund')),
  updated_at timestamptz not null default now(),
  check (seller <> buyer)
);


create table trade_transition (
  from_state text not null, to_state text not null, primary key (from_state, to_state)
);
insert into trade_transition values
  ('open','escrowed'), ('open','cancelled'),
  ('escrowed','paid_claimed'), ('escrowed','cancelled'),
  ('paid_claimed','paid_attested'), ('paid_claimed','disputed'),
  ('paid_attested','released'), ('paid_attested','disputed'),
  ('disputed','resolved_release'), ('disputed','resolved_refund');


create table trade_event (
  id         bigint generated always as identity primary key,
  trade_id   bigint not null references trade(id),
  idem_key   text   not null unique,
  from_state text   not null,
  to_state   text   not null,
  created_at timestamptz not null default now()
);
create trigger trade_event_append_only before update or delete on trade_event
  for each row execute function forbid_mutation();


-- Colour derives from state. Nothing else decides it.
-- Green = reached 'released'/'resolved_release' (attested + window elapsed + no dispute).
-- Red = money actually going back to one side ('cancelled','resolved_refund').
-- A dispute is an open question, not a verdict, so it reads plum like every other pending state.
-- 002_trade_types.sql rebuilds this view and SETTLES the rule; this case is kept in step with it so the
-- foundational file does not contradict the files that follow it.
create view trade_view as
select t.*,
       case when state in ('released','resolved_release')            then 'green'
            when state in ('cancelled','resolved_refund')             then 'red'
            else 'plum' end as colour
  from trade t;


create function create_trade(p_seller text, p_buyer text, p_asset text, p_amount numeric)
returns bigint language sql security definer set search_path = public, pg_temp as $$
  insert into trade(seller, buyer, asset, amount) values (p_seller, p_buyer, p_asset, p_amount) returning id
$$;


-- One call = one state change + its postings, atomically.
--   same idem key  -> no-op replay
--   other caller already moved the trade -> error (exactly one winner)
create function transition_trade(p_trade bigint, p_from text, p_to text, p_idem text)
returns void language plpgsql security definer set search_path = public, pg_temp as $$
declare t trade%rowtype; v_seller bigint; v_escrow bigint; v_buyer bigint;
begin
  if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
  if not exists (select 1 from trade_transition where from_state = p_from and to_state = p_to) then
    raise exception 'illegal transition % -> %', p_from, p_to;
  end if;


  update trade set state = p_to, updated_at = now()
   where id = p_trade and state = p_from returning * into t;     -- compare-and-swap
  if not found then
    if exists (select 1 from trade_event where idem_key = p_idem) then return; end if;
    raise exception 'trade % is not in state %', p_trade, p_from;
  end if;
  insert into trade_event(trade_id, idem_key, from_state, to_state) values (p_trade, p_idem, p_from, p_to);


  v_seller := get_account(t.seller, 'available', t.asset);
  v_escrow := get_account('trade:' || t.id, 'escrow', t.asset);
  v_buyer  := get_account(t.buyer, 'available', t.asset);


  if p_to = 'escrowed' then
    perform post_tx(p_idem || ':ledger', 'escrow_lock', t.id, jsonb_build_array(
      jsonb_build_object('account', v_seller, 'asset', t.asset, 'amount', -t.amount),
      jsonb_build_object('account', v_escrow, 'asset', t.asset, 'amount',  t.amount)));
  elsif p_to in ('released','resolved_release') then
    perform post_tx(p_idem || ':ledger', 'escrow_release', t.id, jsonb_build_array(
      jsonb_build_object('account', v_escrow, 'asset', t.asset, 'amount', -t.amount),
      jsonb_build_object('account', v_buyer,  'asset', t.asset, 'amount',  t.amount)));
  elsif p_to in ('cancelled','resolved_refund') and p_from <> 'open' then
    perform post_tx(p_idem || ':ledger', 'escrow_refund', t.id, jsonb_build_array(
      jsonb_build_object('account', v_escrow, 'asset', t.asset, 'amount', -t.amount),
      jsonb_build_object('account', v_seller, 'asset', t.asset, 'amount',  t.amount)));
  end if;
end $$;


-- ---------------------------------------------------------------- privileges
do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_app') then create role kudi_app nologin; end if;
end $$;
revoke all on all tables in schema public from public;
revoke execute on all functions in schema public from public;
grant select on trade_view, trade, trade_event, account, ledger_tx, posting to kudi_app;
grant execute on function create_trade(text,text,text,numeric),
                          transition_trade(bigint,text,text,text),
                          verify_chain(), check_balances() to kudi_app;


-- Deposits mint balance, so ONLY the chain watcher may call them. Never the app role.
do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_chain') then create role kudi_chain nologin; end if;
end $$;
grant execute on function ledger_deposit(text,text,numeric,text) to kudi_chain;

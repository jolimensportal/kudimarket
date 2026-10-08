-- DEV ONLY. Rebuilds the console's demo ledger from nothing, through the real constructors.
--
-- WHY THIS IS A SCRIPT AND NOT A MIGRATION
-- 2026_01_01_000300_seed_reference_data.php has a down() that deletes trade rows but not the events,
-- postings and transactions pointing at them, so it is not its inverse. Until that migration is
-- properly reversible, this script is how the demo data is rebuilt. It runs as the cluster superuser
-- because the app role cannot write account or ledger tables.
--
-- It is a copy of the statements in that migration, in the same order, for the same reason: the
-- console should only ever show data that the shipped seed code would produce. Where the trader app
-- now owns real identities, those rows are inserted as traders first so the demo shows the same
-- people the console will authenticate.

begin;

-- Clear the demo ledger. Accounts survive with their balances zeroed, because get_account() reuses
-- them; truncating the ledger rows is enough to put balances and postings back in agreement.
--
-- The append-only and no-truncate triggers have to be switched off to do this, and that is the point
-- of noting it: posting, ledger_tx and trade_event all refuse to be truncated or edited. Wiping demo
-- data is a superuser act, exactly as it should be. Triggers are re-enabled below.
alter table posting     disable trigger posting_append_only;
alter table posting     disable trigger posting_no_truncate;
alter table ledger_tx   disable trigger ledger_tx_append_only;
alter table ledger_tx   disable trigger ledger_tx_no_truncate;
alter table trade_event disable trigger trade_event_append_only;
truncate table posting, trade_event, ledger_tx, trade restart identity cascade;
delete from trader;
update account set balance = 0;
alter table posting     enable trigger posting_append_only;
alter table posting     enable trigger posting_no_truncate;
alter table ledger_tx   enable trigger ledger_tx_append_only;
alter table ledger_tx   enable trigger ledger_tx_no_truncate;
alter table trade_event enable trigger trade_event_append_only;

-- The people on the board. Real identities, because the trader app authenticates on these addresses
-- and a demo that shows 'ama' while the login expects an email teaches nobody anything.
insert into trader(name, email, phone, password, kind, verified_at) values
  ('Ama Serwaa',    'ama@example.com',    '0240000001', 'x', 'person',   now()),
  ('Kwesi Boateng', 'kwesi@example.com',  '0240000002', 'x', 'person',   now()),
  ('Adwoa Mensah',  'adwoa@example.com',  '0240000003', 'x', 'person',   now()),
  ('KudiMarket',    'pricing@kudimarket.com', '0240000000', 'x', 'business', now());

-- Opening balances, identical to the seed migration.
select ledger_deposit('ops-1', 'USDT', 25000, 'seed-ops-usdt');
select ledger_deposit('platform', 'GHS', 120000, 'seed-platform-ghs');
select ledger_deposit('ama@example.com', 'USDT', 4000, 'seed-ama-usdt');
select ledger_deposit('ama@example.com', 'GHS', 50000, 'seed-ama-ghs');
select ledger_deposit('kwesi@example.com', 'GHS', 90000, 'seed-kwesi-ghs');
select ledger_deposit('adwoa@example.com', 'GHS', 60000, 'seed-adwoa-ghs');

-- Our own book settles behind a claim window; it is opened only so the first trade below can be
-- shown already settled, exactly as in the migration.
update trade_policy set claim_window = '0 seconds' where trade_type = 'platform_inventory';

-- One settled, one waiting on its window, one disputed, one open vendor offer on the board.
select create_fiat_trade('platform', 'ama@example.com', null, null, 'NGN', 200000, 0.10, 'platform_inventory');
select create_fiat_trade('platform', 'kwesi@example.com', null, null, 'NGN', 85000, 0.105, 'platform_inventory');
select create_fiat_trade('adwoa@example.com', 'ama@example.com', null, null, 'NGN', 120000, 0.10, 'vendor_vendor');
select create_fiat_trade('kwesi@example.com', 'adwoa@example.com', null, null, 'GHS', 150000, 1, 'vendor_vendor');

-- Trade 1, settled end to end.
select transition_trade(1, 'open', 'paid_claimed', 's1', 'ama@example.com');
select ops_transition(1, 'paid_claimed', 'paid_attested', 's2', 'ops@kudimarket.com');
select transition_trade(1, 'paid_attested', 'released', 's3', 'system');

-- Trade 2 stops at attested, so the release guard is visibly pending.
select transition_trade(2, 'open', 'paid_claimed', 'v1', 'kwesi@example.com');
select ops_transition(2, 'paid_claimed', 'paid_attested', 'v2', 'ops@kudimarket.com');

-- Trade 3 disputed, so the console has a case to read.
select transition_trade(3, 'open', 'paid_claimed', 'd1', 'ama@example.com');
select transition_trade(3, 'paid_claimed', 'disputed', 'd2', 'adwoa@example.com');

update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory';

commit;
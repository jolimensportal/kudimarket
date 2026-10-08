-- DEV ONLY. Rebuilds the console's demo ledger from nothing, through the real constructors.
--
-- WHY THIS IS A SCRIPT AND NOT A MIGRATION
-- 2026_01_01_000300_seed_reference_data.php has a down() that deletes trade rows only, which is not
-- its inverse: the trades it created have events, postings and ledger transactions pointing at them.
-- Until that migration is made properly reversible, this script is how the demo data is rebuilt.
-- It runs as the cluster superuser because the app role cannot write account or ledger tables.
--
-- It is a copy of the statements in that migration, in the same order, for the same reason: the
-- console should only ever show data that the shipped seed code would produce.

begin;

-- Clear the demo ledger. Accounts survive with their balances zeroed, because get_account() reuses
-- them; truncating the ledger rows is enough to put balances and postings back in agreement.
--
-- The append-only and no-truncate triggers have to be switched off to do this, and that is the
-- point of noting it: posting, ledger_tx and trade_event all refuse to be truncated or edited.
-- Wiping demo data is a superuser act, exactly as it should be. Triggers are re-enabled below.
alter table posting     disable trigger posting_append_only;
alter table posting     disable trigger posting_no_truncate;
alter table ledger_tx   disable trigger ledger_tx_append_only;
alter table ledger_tx   disable trigger ledger_tx_no_truncate;
alter table trade_event disable trigger trade_event_append_only;
truncate table posting, trade_event, ledger_tx, trade restart identity cascade;
update account set balance = 0;
alter table posting     enable trigger posting_append_only;
alter table posting     enable trigger posting_no_truncate;
alter table ledger_tx   enable trigger ledger_tx_append_only;
alter table ledger_tx   enable trigger ledger_tx_no_truncate;
alter table trade_event enable trigger trade_event_append_only;

-- Opening balances, identical to the seed migration.
select ledger_deposit('ops-1', 'USDT', 25000, 'seed-ops-usdt');
select ledger_deposit('platform', 'GHS', 120000, 'seed-platform-ghs');
select ledger_deposit('ama', 'USDT', 4000, 'seed-ama-usdt');
select ledger_deposit('ama', 'GHS', 50000, 'seed-ama-ghs');
select ledger_deposit('kwesi', 'GHS', 90000, 'seed-kwesi-ghs');
select ledger_deposit('adwoa', 'GHS', 60000, 'seed-adwoa-ghs');

-- Our own book settles behind a claim window; it is opened only so the first trade below can be
-- shown already settled, exactly as in the migration.
update trade_policy set claim_window = '0 seconds' where trade_type = 'platform_inventory';

-- One settled, one waiting on its window, one disputed, one open.
select create_fiat_trade('platform', 'ama', null, null, 'NGN', 200000, 0.10, 'platform_inventory');
select create_fiat_trade('platform', 'kwesi', null, null, 'NGN', 85000, 0.105, 'platform_inventory');
select create_fiat_trade('adwoa', 'ama', null, null, 'NGN', 120000, 0.10, 'vendor_vendor');
select create_fiat_trade('kwesi', 'adwoa', null, null, 'GHS', 150000, 1, 'vendor_vendor');

-- Trade 1, settled end to end.
select transition_trade(1, 'open', 'paid_claimed', 's1', 'ama');
select ops_transition(1, 'paid_claimed', 'paid_attested', 's2', 'ops@kudimarket.com');
select transition_trade(1, 'paid_attested', 'released', 's3', 'system');

-- Trade 2 stops at attested, so the release guard is visibly pending.
select transition_trade(2, 'open', 'paid_claimed', 'v1', 'kwesi');
select ops_transition(2, 'paid_claimed', 'paid_attested', 'v2', 'ops@kudimarket.com');

-- Trade 3 disputed, so the console has a case to read.
select transition_trade(3, 'open', 'paid_claimed', 'd1', 'ama');
select transition_trade(3, 'paid_claimed', 'disputed', 'd2', 'adwoa');

update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory';

commit;

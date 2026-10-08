-- 008: traders.
--
-- WHY THIS FILE EXISTS
-- The trader app needs people. It does NOT use the `users` table, because that belongs to the
-- operations console: a trader in Accra and an operator at a desk are different people who should not
-- share a table, a session or a credential. Keeping them apart is the first half of segregation of
-- duties; the kudi_trader role in 007 is the second.
--
-- What is deliberately NOT here: balance. A trader's money position lives in `account`, and it is
-- reached through the ledger. A balance column on this table would be a second source of truth for
-- exactly the number the product exists to get right, and the two would disagree the first time a
-- transaction raced a request.
--
-- `kind` separates a person from a business without a second table. The product sells to registered
-- businesses as readily as to individuals, and the difference matters for KYC later.
--
-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


create table trader (
  id            bigserial primary key,
  name          text not null check (length(trim(name)) > 0),
  -- The address the ledger records as the counterparty. This is the join between a login and a
  -- trade row, so it must be unique: two traders sharing one address would share one balance.
  email         text not null unique check (position('@' in email) > 1),
  -- Ghana's numbers are ten digits, no plus. Checked loosely on purpose: a hard format check here
  -- rejects valid numbers the moment a carrier does something new, and a rejected signup is a
  -- trader lost. Normalise to E.164 at the edge instead.
  phone         text unique check (phone ~ '^[0-9]{9,15}$'),
  password      text not null,
  -- Laravel's Authenticatable expects this. Without it a sign-in with "keep me signed in" checked
  -- authenticates and then fails while writing the token, which is the worst possible moment to
  -- discover a missing column: the password was right and the user is told it was wrong.
  remember_token text,
  kind          text not null default 'person' check (kind in ('person','business')),
  -- Set by operations, not by the trader. Unverified is a real state and the most common one, and the
  -- board must be able to show it rather than guess.
  verified_at   timestamptz,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index trader_unverified on trader(created_at) where verified_at is null;


-- ---------------------------------------------------------------- PRIVILEGES, LAST AND COMPLETE
-- The trader role may read its own row and write nothing. It cannot verify itself, cannot change its
-- own kind, and cannot become a business without operations looking at the paperwork. Reading the
-- whole table is necessary because the board shows who is trading; it is a small table of public-facing
-- trading history, not a private record.
grant select on trader to kudi_trader;

-- Column-level UPDATE, and only for the one column a trader legitimately writes themselves: the
-- remember-me token. Not table-level UPDATE, because that would let a signed-in trader rewrite their
-- own verified_at and become verified without operations ever looking at the paperwork. Postgres can
-- grant a single column, so there is no reason to grant the row.
grant update (remember_token) on trader to kudi_trader;

-- Operations verifies and edits. It cannot write a balance here either, because there is no balance
-- column to write.
grant select, insert, update on trader to kudi_ops;
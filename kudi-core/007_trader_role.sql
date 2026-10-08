-- 007: the trader role.
--
-- WHY THIS FILE EXISTS
-- The trader app needs an identity that can open a trade, claim it paid, raise a dispute and read the
-- board -- and cannot resolve a dispute, publish a rate, mint a deposit, or write to any ledger table.
--
-- Why not reuse kudi_app: that is the role Laravel's own code runs as, including the Filament console.
-- A trader in Accra and an operator at a desk are different people with different blast radius, and
-- they should not share a credential. Naming the role is the first half of segregation of duties; the
-- grants are the second.
--
-- A trader cannot ATTEST. Attestation means "a human confirmed this money arrived", and that human is
-- operations, not the payer. Letting a payer attest their own payment would make the one statement in
-- the system that carries evidence worth nothing. The test below asserts this against kudi_trader
-- specifically, because asserting it against kudi_ops would pass for the wrong reason: ops cannot
-- attest either.
--
-- There is no INSERT, UPDATE or DELETE grant anywhere below, and deliberately no matching revoke.
-- Nothing above grants a write, so there is nothing to take back, and writing the revoke out would
-- suggest the grant had been made first and withdrawn. If you are reading this file while adding a
-- write grant, stop and read this header.
--
-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_trader') then
    create role kudi_trader nologin;
  end if;
end $$;


-- ---------------------------------------------------------------- PRIVILEGES, LAST AND COMPLETE
-- Read only: the board, our own prices, and the trades a trader can see. SELECT on trade is
-- deliberate and is not a leak -- trade carries no money, and a trader is shown their own trade by
-- id after the application has checked ownership.
grant select on trade_view, trade, trade_event, rate_card, current_rate, rate_board, asset,
                trade_quote, integrity_report to kudi_trader;

-- Write, but only by asking the database to do it. Every state change below re-checks the state
-- machine, the actor, and the claim window. This role cannot move a trade by any other route.
-- The six-argument signatures, matching how 002 declares them and how 004 grants them. The trailing
-- p_fault has a default, so callers pass five arguments and Postgres fills it -- but GRANT needs the
-- full signature or it fails with "function does not exist", which is exactly what happened the first
-- time this was written with five.
grant execute on function create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text),
                          create_trade(text, text, text, numeric, text),
                          transition_trade(bigint, text, text, text, text, text, text),
                          verify_chain(), verify_trade_chain(), verify_rate_chain() to kudi_trader;

-- Explicitly NOT granted, and asserted by the suite rather than left for a reader to notice:
--   ops_transition        resolving a dispute is operations' job alone
--   set_rate              pricing has its own role and its own credential
--   ledger_deposit        minting balance is the chain watcher's job
--   post_tx               writing a posting is not a thing any application role may do
--   _apply_transition     the internal function behind the transitions
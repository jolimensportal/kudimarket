-- 013: the thing that settles a trade nobody has to chase.
--
-- WHY THIS FILE EXISTS
--
-- paid_attested -> released has actor 'system'. Nothing performs it.
--
-- There is no scheduler in the application, no command, and no function that finds the trades due. So
-- every trade that gets past the attest sits at paid_attested for ever: our money is in escrow, the
-- counterparty has been paid by somebody, and the other side never receives anything. The trade screen's
-- copy was changed once to stop promising automatic settlement, which was honest and also papered over a
-- missing mechanism rather than adding one.
--
-- It mattered least while the only route to a trade was a test. It matters now that a trader can open one
-- against our own book, because that is the tier where the money is ours and delivery is ours.
--
-- WHAT THIS DOES
--
-- release_due_trades() finds every attested trade whose claim window has closed and runs the SAME
-- transition_trade() the operations console uses. Not a second implementation of the release: the whole
-- value of this design is that the ledger posts, the event chains and the idempotency keys all come from
-- one place. A sweeper that wrote postings itself would be a second ledger.
--
-- Idempotent by construction. It only looks at trades still in paid_attested, so a second run in the same
-- second finds nothing to do, and a key that has already been used returns the original event rather than
-- appending a second one. Run it as often as you like.
--
-- The claim window is why this is not instant. Our own book receives fiat by mobile money and is exposed
-- to reversals, so a buyer needs time to say the money never arrived. 004 already refuses the release
-- inside that window; this respects the same boundary rather than second-guessing it.


create or replace function release_due_trades()
returns table(released bigint, already_settled bigint)
language plpgsql security definer set search_path = public, pg_temp as $$
declare
  t record;
  v_released bigint := 0;
  v_settled  bigint := 0;
  v_now timestamptz := clock_timestamp();
begin
  -- Two numbers back, because "ran and did nothing" and "ran and found nothing to do" are different
  -- answers and an operator watching this needs to tell them apart.
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
       -- The LAST attest, not any attest: a trade that was disputed and re-attested carries both, and only
       -- the later one starts the window. 004's guard measures from the attest the RELEASE is based on,
       -- so measuring from an older row would settle money the window was never open for.
       and e.seq = (select max(e2.seq) from trade_event e2
                     where e2.trade_id = e.trade_id and e2.to_state = 'paid_attested')
       and v_now >= e.created_at + pol.claim_window
     order by e.trade_id
  loop
    -- One release may fail and must not stop the rest. A trade whose window we misjudged, or one with a
    -- fault recorded against it, is left exactly where it is and reported, rather than aborting the sweep
    -- and stranding every trade behind it in the queue.
    begin
      perform transition_trade(t.id, 'paid_attested', 'released',
                               'sweep:' || t.id || ':released', 'system');
      v_released := v_released + 1;
    exception when others then
      v_settled := v_settled + 1;
    end;
  end loop;

  return query select v_released, v_settled;
end;
$$;

-- kudi_pricing writes the card and kudi_trader opens trades; neither settles money. kudi_ops is the role
-- that already attests and resolves, so the sweeper runs as it. PUBLIC gets EXECUTE by default on a new
-- function, and this one moves money -- so that is revoked by name, the way 010 had to do it.
revoke all on function release_due_trades() from public;
grant execute on function release_due_trades() to kudi_ops;

comment on function release_due_trades() is
  'Releases every paid_attested trade whose claim window has closed, through transition_trade(). Idempotent. Returns (released, already_settled).';
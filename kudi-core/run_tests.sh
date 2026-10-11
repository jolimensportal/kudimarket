#!/usr/bin/env bash
# Needs a Postgres superuser connection (PGHOST/PGUSER/PGPASSWORD env, or run as the postgres OS user).
# Creates and drops its own database "kudi_test". Usage: bash run_tests.sh
set -u
DB=kudi_test
HERE="$(cd "$(dirname "$0")" && pwd)"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1  (got: $2)"; }
sql()  { psql -X -q -t -A -v ON_ERROR_STOP=1 -d "$DB" -c "$1" 2>&1; }
asrole(){ printf 'set role %s;\n%s\n' "$1" "$2" | psql -X -q -t -A -v ON_ERROR_STOP=1 -d "$DB" 2>&1; }  # keeps psql's exit status
asapp(){ asrole kudi_app "$1"; }
asops(){ asrole kudi_ops "$1"; }
asprice(){ asrole kudi_pricing "$1"; }
astrader(){ asrole kudi_trader "$1"; }
expect_eq()   { [ "$2" = "$3" ] && ok "$1" || bad "$1" "'$2' expected '$3'"; }
expect_fail() { if echo "$2" | grep -qi "$3"; then ok "$1"; else bad "$1" "$2"; fi; }
bal()  { sql "select balance from account where owner='$1' and asset='USDT' and kind='${2:-available}';"; }
tt()   { sql "select transition_trade($1,'$2','$3','$4','$5');" >/dev/null; }          # trade from to idem actor
ops()  { sql "select ops_transition($1,'$2','$3','$4','$5'${6:+,'$6'});" >/dev/null; }   # trade from to idem staff [fault]


dropdb --if-exists "$DB" >/dev/null 2>&1; createdb "$DB" || exit 1
for f in 001_core.sql 002_trade_types.sql 003_fiat_leg.sql 004_state_chain.sql 005_creation_anchor.sql 006_rate_card.sql 007_trader_role.sql 008_traders.sql 009_offer_book.sql 010_verification.sql 011_rate_direction.sql 012_trade_pair.sql 013_release_sweeper.sql 014_segregation.sql 015_trader_identity.sql; do
  psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f "$HERE/$f" >/dev/null || { echo "schema $f failed"; exit 1; }
done
sql "insert into asset values ('USDT',6),('GHS',2);" >/dev/null
sql "update trade_policy set claim_window = '0 seconds';" >/dev/null     # windows are tested explicitly below


echo "== ledger invariants"
A=$(sql "select get_account('alice','available','USDT');"); B=$(sql "select get_account('bob','available','USDT');")
out=$(sql "select post_tx('t-unbal','x',null,jsonb_build_array(jsonb_build_object('account',$A,'asset','USDT','amount',5),jsonb_build_object('account',$B,'asset','USDT','amount',4)));")
expect_fail "unbalanced transaction is rejected at commit" "$out" "unbalanced"
out=$(sql "select post_tx('t-over','x',null,jsonb_build_array(jsonb_build_object('account',$A,'asset','USDT','amount',-5),jsonb_build_object('account',$B,'asset','USDT','amount',5)));")
expect_fail "overdraft is rejected" "$out" "insufficient funds"
expect_eq "failed attempts left no trace" "$(sql "select count(*) from ledger_tx;")" "0"
sql "select ledger_deposit('alice','USDT',1000,'dep-1');" >/dev/null
id1=$(sql "select ledger_deposit('alice','USDT',1000,'dep-1');"); id2=$(sql "select ledger_deposit('alice','USDT',1000,'dep-1');")
expect_eq "replaying the same idempotency key returns the original tx" "$id1" "$id2"
expect_eq "replay did not double-credit" "$(sql "select balance from account where id=$A;")" "1000"


echo "== append-only and privileges"
expect_fail "UPDATE on posting is blocked"  "$(sql "update posting set amount = 1;")" "append-only"
expect_fail "DELETE on ledger_tx is blocked" "$(sql "delete from ledger_tx;")" "append-only"
expect_fail "TRUNCATE on posting is blocked" "$(sql "truncate posting;")" "append-only"
expect_fail "app role cannot insert postings" "$(asapp "insert into posting(tx_id,account_id,asset,amount) values (1,$A,'USDT',1);")" "permission denied"
expect_fail "app role cannot edit balances"   "$(asapp "update account set balance = 999999 where id=$A;")" "permission denied"
expect_fail "app role cannot call post_tx"    "$(asapp "select post_tx('x','x',null,'[]'::jsonb);")" "permission denied"
expect_fail "app role cannot mint deposits"   "$(asapp "select ledger_deposit('mallory','USDT',1000000,'steal-1');")" "permission denied"
expect_fail "ops role cannot mint deposits"   "$(asops "select ledger_deposit('mallory','USDT',1000000,'steal-2');")" "permission denied"
expect_fail "app role cannot call ops_transition" "$(asapp "select ops_transition(1,'a','b','x','ops-1');")" "permission denied"
expect_fail "ops role cannot call transition_trade" "$(asops "select transition_trade(1,'a','b','x','u');")" "permission denied"
expect_fail "app role cannot touch the internal transition fn" "$(asapp "select _apply_transition(1,'a','b','x','u',true,null);")" "permission denied"
# Every view the console reads has to be readable by both roles. trade_quote was missing from the
# grant list and the only symptom was a 500 on the trade page, because nothing had read it yet.
expect_eq "app role can read the quote view"  "$(asapp  "select count(*) from trade_quote;")"  "0"
expect_eq "ops role can read the quote view"  "$(asops  "select count(*) from trade_quote;")"  "0"
expect_eq "app role can read the trade view"  "$(asapp  "select count(*) from trade_view;")"  "0"
expect_eq "app role can read the integrity report" "$(asapp "select count(*) from integrity_report;")" "1"
expect_eq "app role can read currency decimals" "$(asapp "select decimals from asset where code='GHS';")" "2"
expect_eq "ops role can read currency decimals" "$(asops "select decimals from asset where code='USDT';")" "6"


echo "== p2p lifecycle, colour derives from state"
T=$(sql "select create_trade('alice','bob','USDT',1000);")
expect_eq "new trade is plum" "$(sql "select colour from trade_view where id=$T;")" "plum"
tt $T open escrowed e-$T alice
expect_eq "escrow locks seller funds" "$(sql "select balance from account where id=$A;")" "0"
expect_fail "illegal jump escrowed->released rejected" "$(sql "select transition_trade($T,'escrowed','released','x-$T','system');")" "illegal transition"
tt $T escrowed paid_claimed pc-$T bob; tt $T paid_claimed paid_attested pa-$T alice
expect_eq "attested but not released is still plum" "$(sql "select colour from trade_view where id=$T;")" "plum"
tt $T paid_attested released r-$T system
expect_eq "released is green" "$(sql "select colour from trade_view where id=$T;")" "green"
expect_eq "buyer received exactly the amount" "$(sql "select balance from account where id=$B;")" "1000"
expect_eq "escrow account is empty" "$(bal trade:$T escrow)" "0"


echo "== who may do what"
sql "select ledger_deposit('au1','USDT',100,'dep-au1');" >/dev/null
T=$(sql "select create_trade('au1','au2','USDT',100);")
expect_fail "a stranger cannot escrow someone's trade" "$(sql "select transition_trade($T,'open','escrowed','a1','mallory');")" "may not"
tt $T open escrowed a2 au1
expect_fail "seller cannot claim the buyer's payment" "$(sql "select transition_trade($T,'escrowed','paid_claimed','a3','au1');")" "may not"
tt $T escrowed paid_claimed a4 au2
expect_fail "buyer cannot attest their own payment" "$(sql "select transition_trade($T,'paid_claimed','paid_attested','a5','au2');")" "may not"
expect_fail "nobody can release before attestation" "$(sql "select transition_trade($T,'paid_claimed','released','a6','system');")" "illegal transition"
tt $T paid_claimed disputed a7 au1
expect_eq "a disputed trade is plum, not red" "$(sql "select colour from trade_view where id=$T;")" "plum"
expect_fail "app path cannot resolve a dispute" "$(sql "select transition_trade($T,'disputed','resolved_refund','a8','au1');")" "ops only"
expect_fail "fault is rejected on p2p resolutions" "$(sql "select ops_transition($T,'disputed','resolved_refund','a9','ops-1','buyer');")" "fault applies only"
asops "select ops_transition($T,'disputed','resolved_refund','a10','ops-1');" >/dev/null
expect_eq "ops resolution refunded the seller" "$(bal au1)" "100"
expect_eq "trade_event records the staff actor" "$(sql "select actor from trade_event where idem_key='a10';")" "ops-1"
expect_eq "disputed-then-refunded trade is red" "$(sql "select colour from trade_view where id=$T;")" "red"


echo "== platform_inventory (we are the counterparty)"
expect_fail "platform trade needs the platform as a party" "$(sql "select create_trade('x','y','USDT',1,'platform_inventory');")" "trade_platform_party"
expect_fail "p2p trade cannot involve the platform"        "$(sql "select create_trade('platform','y','USDT',1,'p2p');")" "trade_platform_party"
sql "select ledger_deposit('platform','USDT',5000,'dep-plat');" >/dev/null
T=$(sql "select create_trade('platform','ivy','USDT',1000,'platform_inventory');")
tt $T open escrowed p1 ivy
expect_eq "float is reserved when we sell" "$(bal platform)" "4000"
expect_eq "reserve holds exactly the amount" "$(bal trade:$T escrow)" "1000"
tt $T escrowed paid_claimed p2 ivy
expect_fail "the user cannot attest their own fiat"     "$(sql "select transition_trade($T,'paid_claimed','paid_attested','p3','ivy');")" "may not"
expect_fail "the app path cannot act as the platform"   "$(sql "select transition_trade($T,'paid_claimed','paid_attested','p4','platform');")" "may not"
asops "select ops_transition($T,'paid_claimed','paid_attested','p5','ops-2');" >/dev/null
tt $T paid_attested released p6 system
expect_eq "user received the crypto" "$(bal ivy)" "1000"
expect_eq "reserve is empty after release" "$(bal trade:$T escrow)" "0"
expect_eq "platform trade is green"        "$(sql "select colour from trade_view where id=$T;")" "green"
# float cannot be sold twice
ok_n=0; last=""
for i in 1 2 3 4 5; do
  t=$(sql "select create_trade('platform','buyer$i','USDT',1000,'platform_inventory');")
  r=$(sql "select transition_trade($t,'open','escrowed','os-$t','buyer$i');")
  if echo "$r" | grep -qi error; then last="$r"; else ok_n=$((ok_n+1)); fi
done
expect_eq "float oversell: exactly four of five reservations succeed" "$ok_n" "4"
expect_fail "float oversell: the fifth is refused" "$last" "insufficient funds"
expect_eq "float oversell: float is zero, not negative" "$(bal platform)" "0"
# platform buys from a user (the user's crypto IS locked in this direction)
sql "select ledger_deposit('jon','USDT',200,'dep-jon');" >/dev/null
T=$(sql "select create_trade('jon','platform','USDT',200,'platform_inventory');")
tt $T open escrowed pb1 jon
expect_eq "platform buy: the user's crypto is locked" "$(bal trade:$T escrow)" "200"
expect_fail "platform buy: the user cannot claim for the platform" "$(sql "select transition_trade($T,'escrowed','paid_claimed','pb2','jon');")" "may not"
ops $T escrowed paid_claimed pb3 ops-3
tt $T paid_claimed paid_attested pb4 jon
tt $T paid_attested released pb5 system
expect_eq "platform buy: float received the crypto" "$(bal platform)" "200"


echo "== vendor_vendor deposits (10% placeholder, per side)"
for n in 1 2 3 4 5 6 7; do sql "select ledger_deposit('va$n','USDT',2000,'dv-a$n'); select ledger_deposit('vb$n','USDT',2000,'dv-b$n');" >/dev/null; done
vv() { sql "select create_trade('va$1','vb$1','USDT',1000,'vendor_vendor');"; }
T=$(vv 1); tt $T open escrowed v1e va1
expect_eq "deposit: seller locked amount + deposit" "$(bal va1)" "900"
expect_eq "deposit: buyer locked deposit"            "$(bal vb1)" "1900"
tt $T escrowed paid_claimed v1c vb1; tt $T paid_claimed paid_attested v1a va1; tt $T paid_attested released v1r system
expect_eq "success: seller deposit returned"  "$(bal va1)" "1000"
expect_eq "success: buyer got crypto + deposit back" "$(bal vb1)" "3000"
expect_eq "success: deposit accounts empty" "$(sql "select coalesce(sum(balance),0) from account where owner like 'trade:$T:%';")" "0"


T=$(vv 2); tt $T open escrowed v2e va2; tt $T escrowed paid_claimed v2c vb2; tt $T paid_claimed disputed v2d vb2
ops $T disputed resolved_release v2x ops-1 seller
expect_eq "seller at fault: seller loses crypto and deposit" "$(bal va2)" "900"
expect_eq "seller at fault: buyer gets crypto + both deposits" "$(bal vb2)" "3100"


T=$(vv 3); tt $T open escrowed v3e va3; tt $T escrowed paid_claimed v3c vb3; tt $T paid_claimed disputed v3d va3
ops $T disputed resolved_refund v3x ops-1 buyer
expect_eq "buyer at fault: seller gets crypto + both deposits" "$(bal va3)" "2100"
expect_eq "buyer at fault: buyer loses deposit" "$(bal vb3)" "1900"


T=$(vv 4); tt $T open escrowed v4e va4; tt $T escrowed paid_claimed v4c vb4; tt $T paid_claimed disputed v4d va4
ops $T disputed resolved_refund v4x ops-1
expect_eq "unresolvable: seller made whole" "$(bal va4)" "2000"
expect_eq "unresolvable: buyer made whole"  "$(bal vb4)" "2000"


T=$(vv 5); tt $T open escrowed v5e va5; tt $T escrowed paid_claimed v5c vb5; tt $T paid_claimed disputed v5d va5
expect_fail "fault that contradicts the outcome is rejected" "$(sql "select ops_transition($T,'disputed','resolved_release','v5x','ops-1','buyer');")" "inconsistent"
expect_fail "bad fault value is rejected" "$(sql "select ops_transition($T,'disputed','resolved_release','v5y','ops-1','everyone');")" "bad fault"


sql "select ledger_deposit('vc6','USDT',50,'dv-c6');" >/dev/null
T=$(sql "select create_trade('va6','vc6','USDT',1000,'vendor_vendor');")
expect_fail "buyer who cannot cover the deposit blocks the lock" "$(sql "select transition_trade($T,'open','escrowed','v6e','va6');")" "insufficient funds"
expect_eq "failed lock rolled back: seller untouched" "$(bal va6)" "2000"
expect_eq "failed lock rolled back: trade still open" "$(sql "select state from trade where id=$T;")" "open"


T=$(vv 7); tt $T open escrowed v7e va7; tt $T escrowed cancelled v7x va7
expect_eq "cancel returns seller crypto + deposit" "$(bal va7)" "2000"
expect_eq "cancel returns buyer deposit"           "$(bal vb7)" "2000"


sql "update trade_policy set max_amount = 5000 where trade_type='vendor_vendor';" >/dev/null
expect_fail "per-type amount cap is enforced" "$(sql "select create_trade('cap1','cap2','USDT',6000,'vendor_vendor');")" "exceeds cap"
sql "update trade_policy set max_amount = null where trade_type='vendor_vendor';" >/dev/null


echo "== claim window is enforced per trade type (p2p 2s, vendor 5s, platform 0s)"
sql "update trade_policy set claim_window='2 seconds' where trade_type='p2p';
     update trade_policy set claim_window='5 seconds' where trade_type='vendor_vendor';
     update trade_policy set claim_window='0 seconds' where trade_type='platform_inventory';" >/dev/null
sql "select ledger_deposit('w1','USDT',100,'dw1'); select ledger_deposit('wv1','USDT',2000,'dwv1'); select ledger_deposit('wv2','USDT',2000,'dwv2'); select ledger_deposit('platform','USDT',300,'dep-plat2');" >/dev/null
P=$(sql "select create_trade('w1','w2','USDT',100,'p2p');")
V=$(sql "select create_trade('wv1','wv2','USDT',1000,'vendor_vendor');")
F=$(sql "select create_trade('platform','w3','USDT',100,'platform_inventory');")
tt $P open escrowed w1 w1; tt $P escrowed paid_claimed w2 w2; tt $P paid_claimed paid_attested w3 w1
tt $V open escrowed wv1 wv1; tt $V escrowed paid_claimed wv2 wv2; tt $V paid_claimed paid_attested wv3 wv1
tt $F open escrowed wf1 w3; tt $F escrowed paid_claimed wf2 w3; ops $F paid_claimed paid_attested wf3 ops-1
# WIDENED FIRST, ON PURPOSE. These two assert that a window is STILL OPEN, which used to be a race: if the
# four setup lines above took longer than the two-second p2p window on a loaded machine, the window closed,
# the release SUCCEEDED, and the test failed for a reason that had nothing to do with the code. It failed
# twice in a row here before anybody read why, which is exactly what a flaky test costs.
#
# Setting the window to something that cannot have elapsed turns "immediate" into a fact instead of a hope.
sql "update trade_policy set claim_window = '2 minutes' where trade_type = 'p2p';" >/dev/null
expect_fail "p2p: immediate release is refused"      "$(sql "select transition_trade($P,'paid_attested','released','wr1','system');")" "claim window open"
sql "update trade_policy set claim_window = '2 minutes' where trade_type = 'vendor_vendor';" >/dev/null
expect_fail "vendor: immediate release is refused"   "$(sql "select transition_trade($V,'paid_attested','released','wr2','system');")" "claim window open"

# Back to the real windows for the timed half. These still sleep, which is the right way to test elapsed
# time -- a sleep guarantees it; a fast machine does not.
sql "update trade_policy set claim_window = '2 seconds' where trade_type = 'p2p';" >/dev/null
sql "update trade_policy set claim_window = '5 seconds' where trade_type = 'vendor_vendor';" >/dev/null
expect_eq   "platform: zero window releases at once" "$(sql "select transition_trade($F,'paid_attested','released','wr3','system'); select state from trade where id=$F;" | tail -1)" "released"
sleep 2.6
expect_eq   "p2p: releases after its 2s window"      "$(sql "select transition_trade($P,'paid_attested','released','wr4','system'); select state from trade where id=$P;" | tail -1)" "released"
expect_fail "vendor: still closed at 2.6s (needs 5s)" "$(sql "select transition_trade($V,'paid_attested','released','wr5','system');")" "claim window open"
sleep 2.8
expect_eq   "vendor: releases after its 5s window"   "$(sql "select transition_trade($V,'paid_attested','released','wr6','system'); select state from trade where id=$V;" | tail -1)" "released"
expect_fail "the release job must identify as system" "$(sql "select transition_trade($V,'paid_attested','released','wr7','alice');")" "not in state\|system step"


echo "== concurrency (parallel sessions)"
sql "update trade_policy set claim_window = '0 seconds';" >/dev/null
# 1. twenty different callers try to release the same trade: exactly one wins
sql "select ledger_deposit('carol','USDT',500,'dep-c');" >/dev/null
T2=$(sql "select create_trade('carol','dave','USDT',500);")
tt $T2 open escrowed s1-$T2 carol; tt $T2 escrowed paid_claimed s2-$T2 dave; tt $T2 paid_claimed paid_attested s3-$T2 carol
rm -f /tmp/kudi_res_*; for i in $(seq 1 20); do
  ( asapp "select transition_trade($T2,'paid_attested','released','race-$T2-$i','system');" >/tmp/kudi_res_$i 2>&1 && echo ok >>/tmp/kudi_res_$i || echo err >>/tmp/kudi_res_$i ) &
done; wait
expect_eq "double-release race: exactly one winner" "$(grep -l '^ok$' /tmp/kudi_res_* 2>/dev/null | wc -l | tr -d " ")" "1"
expect_eq "double-release race: buyer paid once" "$(bal dave)" "500"
# 2. same idempotency key from ten parallel retries: all succeed, effect applied once
sql "select ledger_deposit('erin','USDT',300,'dep-e');" >/dev/null
T3=$(sql "select create_trade('erin','frank','USDT',300);")
rm -f /tmp/kudi_res_*; for i in $(seq 1 10); do
  ( asapp "select transition_trade($T3,'open','escrowed','same-key-$T3','erin');" >/tmp/kudi_res_$i 2>&1 && echo ok >>/tmp/kudi_res_$i || echo err >>/tmp/kudi_res_$i ) &
done; wait
expect_eq "parallel retries with one key: all return ok" "$(grep -l '^ok$' /tmp/kudi_res_* | wc -l | tr -d " ")" "10"
expect_eq "parallel retries with one key: locked once" "$(bal trade:$T3 escrow)" "300"
# 3. one seller, ten 500-unit trades, only 1000 available: exactly two can escrow
sql "select ledger_deposit('gina','USDT',1000,'dep-g');" >/dev/null
TS=(); for i in $(seq 1 10); do TS+=("$(sql "select create_trade('gina','hank$i','USDT',500);")"); done
rm -f /tmp/kudi_res_*; for i in $(seq 1 10); do t=${TS[$((i-1))]}
  ( asapp "select transition_trade($t,'open','escrowed','g-$t','gina');" >/tmp/kudi_res_$i 2>&1 && echo ok >>/tmp/kudi_res_$i || echo err >>/tmp/kudi_res_$i ) &
done; wait
expect_eq "overdraft race: exactly two escrows succeed" "$(grep -l '^ok$' /tmp/kudi_res_* | wc -l | tr -d " ")" "2"
expect_eq "overdraft race: seller balance is zero, not negative" "$(bal gina)" "0"
expect_eq "losing trades did not change state" "$(sql "select count(*) from trade where seller='gina' and state='open';")" "8"
# 4. the platform float under parallel load: 5 users race for 500 of float, 200 each: exactly two win
sql "select ledger_deposit('platform','USDT',500,'dep-plat3');" >/dev/null; before=$(bal platform)
TP=(); for i in 1 2 3 4 5; do TP+=("$(sql "select create_trade('platform','pu$i','USDT',$((before/2+1)),'platform_inventory');")"); done
rm -f /tmp/kudi_res_*; for i in 1 2 3 4 5; do t=${TP[$((i-1))]}
  ( asapp "select transition_trade($t,'open','escrowed','pf-$t','pu$i');" >/tmp/kudi_res_$i 2>&1 && echo ok >>/tmp/kudi_res_$i || echo err >>/tmp/kudi_res_$i ) &
done; wait
expect_eq "float race: only one reservation fits" "$(grep -l '^ok$' /tmp/kudi_res_* | wc -l | tr -d " ")" "1"
expect_eq "no non-external account is negative" "$(sql "select count(*) from account where balance<0 and not allow_negative;")" "0"


# ============================================================ fiat leg (003)
# Our real trade: cedi <-> naira with NO crypto in escrow. The ledger must track the state
# machine and any fiat deposit, and must refuse to pretend there is an escrow.
echo
echo "== fiat leg: a trade with no crypto"
sql "insert into asset values ('NGN',2);" >/dev/null
sql "update trade_policy set claim_window='0 seconds';" >/dev/null

expect_fail "a fiat-only trade cannot be escrowed" \
  "$(sql "select transition_trade((select create_fiat_trade('f1','f2',null,null,'NGN',300000,1,'p2p')),'open','escrowed','fx0','f2');")" "no crypto leg"

T=$(sql "select create_fiat_trade('platform','f3',null,null,'NGN',300000,1,'platform_inventory');")
expect_eq "fiat trade is plum on open" "$(sql "select colour from trade_view where id=$T;")" "plum"
expect_eq "fiat trade records the naira amount" "$(sql "select fiat_amount from trade where id=$T;")" "300000"
expect_eq "fiat trade has no crypto leg" "$(sql "select coalesce(crypto_asset,'none') from trade where id=$T;")" "none"
tt $T open paid_claimed fc-$T f3
expect_eq "buyer may claim straight from open" "$(sql "select state from trade where id=$T;")" "paid_claimed"
expect_fail "only ops may attest on a platform trade" \
  "$(sql "select transition_trade($T,'paid_claimed','paid_attested','fx1','f3');")" "may not"
asops "select ops_transition($T,'paid_claimed','paid_attested','fx2','ops-9');" >/dev/null
tt $T paid_attested released fr-$T system
expect_eq "fiat trade settles green" "$(sql "select colour from trade_view where id=$T;")" "green"
-- This assertion used to read "no posting was made for a fiat-only trade". It passed for 346 tests and
-- it was wrong: it was asserting the absence of the one posting that should have been there. KudiMarket is
-- the counterparty on a platform trade, so the money really does change hands through us, and our_float in
-- segregation_report was a function of the seed rather than of the business. Now there is a settlement.
--
-- This particular trade is NGN against NGN at rate 1, so the four legs net to nothing on both accounts.
-- The two-currency case below is the one that proves the direction.
expect_eq "a platform fiat trade posts a settlement" \
  "$(sql "select count(*) from posting p join ledger_tx x on x.id=p.tx_id where x.trade_id=$T;")" "4"
expect_eq "the settlement nets to nothing when both legs are the same currency" \
  "$(sql "select coalesce(sum(amount),0) from posting p join account a on a.id=p.account_id
          join ledger_tx x on x.id=p.tx_id where x.trade_id=$T and a.owner='platform';")" "0"
expect_eq "ledger is still balanced after fiat settlements" "$(sql "select count(*) from check_balances();")" "0"


echo "== KudiMarket's own position moves when it is the counterparty (no pre-funded float)"
# Under no-float the position accounts are booked 'external' so they may go negative: being short the
# currency we just sold is the normal state of a bureau that does not pre-fund, not a fault. Booking them
# 'available' instead would mean inventing a float we do not hold; allowing CUSTOMER available accounts to
# go negative would have destroyed the ring-fence the escrow depends on. This is the assertion that the
# compromise did not quietly weaken the customer invariant.
bg=$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='GHS' and kind='external';")
bn=$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='NGN' and kind='external';")
cu=$(sql "select coalesce((select sum(balance) from account where owner='pz1'),0);")
# The buyer RECEIVES 300000 minor units of NGN at 148.2 NGN per GHS, so we are paid 2024 minor units of
# GHS -- div() truncates, because a posting cannot be fractional.
T=$(sql "select create_fiat_trade('platform','pz1',null,null,'NGN',300000,148.2,'platform_inventory','GHS','NGN','GHS');")
tt $T open paid_claimed pzc-$T pz1
asops "select ops_transition($T,'paid_claimed','paid_attested','pza-$T','ops-9');" >/dev/null
tt $T paid_attested released pzr-$T system
expect_eq "our position in the currency we were PAID rises" \
  "$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='GHS' and kind='external';")" "$((bg+2024))"
expect_eq "our position in the currency we SOLD goes short, because we hold no float" \
  "$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='NGN' and kind='external';")" "$((bn-300000))"
expect_eq "the buyer holds no ledger balance: the goods left the ledger entirely" \
  "$(sql "select coalesce((select sum(balance) from account where owner='pz1'),0);")" "$cu"
expect_eq "no customer escrow was disturbed by the settlement" \
  "$(sql "select coalesce(sum(balance) filter (where kind='escrow'),0) from account
          where owner like 'trade:' || $T || '%';")" "0"

echo "== a Checked (vendor) trade must move nothing of ours -- it is the one posting that would be a lie"
bg2=$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='GHS' and kind='external';")
bn2=$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='NGN' and kind='external';")
sql "update trade_policy set deposit_bps=0 where trade_type='vendor_vendor';" >/dev/null
T=$(sql "select create_fiat_trade('vz1','vz2',null,null,'NGN',300000,148.2,'vendor_vendor','GHS','NGN','GHS');")
tt $T open paid_claimed vzc-$T vz2
tt $T paid_claimed paid_attested vza-$T vz1
tt $T paid_attested released vzr-$T vz1
expect_eq "a vendor trade leaves our position untouched in the currency we were paid" \
  "$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='GHS' and kind='external';")" "$bg2"
expect_eq "a vendor trade leaves our position untouched in the currency that changed hands" \
  "$(sql "select coalesce(sum(balance),0) from account where owner='platform' and asset='NGN' and kind='external';")" "$bn2"
expect_eq "a vendor trade posts no settlement at all" \
  "$(sql "select count(*) from posting p join ledger_tx x on x.id=p.tx_id
          where x.trade_id=$T and exists (select 1 from account a where a.id=p.account_id and a.owner='platform');")" "0"
sql "update trade_policy set deposit_bps=1000 where trade_type='vendor_vendor';" >/dev/null

echo "== fiat leg: vendor deposits are taken in cedi, not in the escrowed asset"
sql "update trade_policy set deposit_bps=1000 where trade_type='vendor_vendor';" >/dev/null
sql "select ledger_deposit('fa1','GHS',200000,'df-a1'); select ledger_deposit('fb1','GHS',200000,'df-b1');" >/dev/null
T=$(sql "select create_fiat_trade('fa1','fb1',null,null,'NGN',300000,1,'vendor_vendor');")
tt $T open paid_claimed fvc-$T fb1; tt $T paid_claimed paid_attested fva-$T fa1
tt $T paid_attested released fvr-$T system
expect_eq "success: seller cedi deposit returned" \
  "$(sql "select balance from account where owner='fa1' and asset='GHS' and kind='available';")" "200000"
expect_eq "success: buyer gets the cedi deposit back, and no crypto exists to release" \
  "$(sql "select balance from account where owner='fb1' and asset='GHS' and kind='available';")" "200000"
expect_eq "deposit accounts emptied" \
  "$(sql "select coalesce(sum(balance),0) from account where owner like 'trade:$T:%';")" "0"

sql "select ledger_deposit('fa2','GHS',200000,'df-a2'); select ledger_deposit('fb2','GHS',200000,'df-b2');" >/dev/null
T=$(sql "select create_fiat_trade('fa2','fb2',null,null,'NGN',300000,1,'vendor_vendor');")
tt $T open paid_claimed fv2c fb2; tt $T paid_claimed disputed fv2d fb2
ops $T disputed resolved_refund fv2x ops-1 buyer
expect_eq "buyer at fault: buyer loses the ten percent cedi deposit" \
  "$(sql "select balance from account where owner='fb2' and asset='GHS' and kind='available';")" "170000"
expect_eq "buyer at fault: seller is made whole plus the forfeited deposit" \
  "$(sql "select balance from account where owner='fa2' and asset='GHS' and kind='available';")" "230000"
expect_eq "ngn was never debited: no money moved in our book for a fiat trade" \
  "$(sql "select coalesce(sum(balance),0) from account where asset='NGN';")" "0"


echo "== fiat leg: a crypto trade keeps both legs and its rate"
sql "update trade_policy set claim_window='0 seconds';" >/dev/null
sql "select ledger_deposit('cr1','USDT',5000,'dcr1');" >/dev/null
T=$(sql "select create_fiat_trade('cr1','cr2','USDT',2000,'GHS',28000,14,'p2p');")
# 18 places, not 10: 009 widened trade.rate to match rate_card.rate. Same number.
expect_eq "rate is recorded" "$(sql "select rate from trade where id=$T;")" "14.000000000000000000"
expect_eq "quote view computes the actual rate" "$(sql "select actual_rate from trade_quote where id=$T;")" "14.000000000000"
expect_eq "fiat amount is stored" "$(sql "select fiat_amount from trade where id=$T;")" "28000"
tt $T open escrowed cr-e cr1; tt $T escrowed paid_claimed cr-c cr2; tt $T paid_claimed paid_attested cr-a cr1
tt $T paid_attested released cr-r system
expect_eq "crypto leg still moves" "$(sql "select balance from account where owner='cr2' and asset='USDT' and kind='available';")" "2000"
expect_eq "no ghs posting for this trade: fiat never moves through the ledger" \
  "$(sql "select count(*) from posting p join ledger_tx x on x.id=p.tx_id where x.trade_id=$T;")" "4"
expect_eq "crypto escrow account empty after release" \
  "$(bal trade:$T escrow)" "0"

expect_fail "a crypto leg without a rate is refused" \
  "$(sql "select create_fiat_trade('cr3','cr4','USDT',100,'GHS',1000,null,'p2p');")" "agreed rate"
expect_fail "a trade without a fiat amount is refused" \
  "$(sql "select create_fiat_trade('cr5','cr6','USDT',100,'GHS',null,14,'p2p');")" "positive fiat amount"


echo "== fiat leg: invariants still hold"
expect_eq "cached balances equal postings" "$(sql "select count(*) from check_balances();")" "0"
expect_eq "value conserved per asset" "$(sql "select count(*) from (select asset from account group by asset having sum(balance)<>0) x;")" "0"
expect_eq "no non-external account negative" "$(sql "select count(*) from account where balance<0 and not allow_negative;")" "0"
expect_eq "hash chain still verifies" "$(sql "select verify_chain();")" "t"

#dropdb (kept for inspection)

# ============================================================ tamper evidence for STATE (004)
echo
echo "== idempotency checks the payload it applied"
sql "select ledger_deposit('ip1','USDT',500,'dip1'); select ledger_deposit('ip2','USDT',500,'dip2');" >/dev/null
IP1=$(sql "select get_account('ip1','available','USDT');"); IP2=$(sql "select get_account('ip2','available','USDT');")
out=$(sql "select post_tx('same-key','x',null,jsonb_build_array(jsonb_build_object('account',$IP1,'asset','USDT','amount',-100),jsonb_build_object('account',$IP2,'asset','USDT','amount',100)));")
expect_eq "first use of a key succeeds" "$(echo "$out" | grep -c '^[0-9]')" "1"
expect_eq "same key, same payload, returns the original" \
  "$(sql "select post_tx('same-key','x',null,jsonb_build_array(jsonb_build_object('account',$IP1,'asset','USDT','amount',-100),jsonb_build_object('account',$IP2,'asset','USDT','amount',100)));")" "$out"
expect_fail "same key, DIFFERENT payload, is refused" \
  "$(sql "select post_tx('same-key','x',null,jsonb_build_array(jsonb_build_object('account',$IP1,'asset','USDT','amount',-400),jsonb_build_object('account',$IP2,'asset','USDT','amount',400)));")" "different payload"
expect_eq "the refused retry moved nothing" "$(bal ip1)" "400"
expect_eq "the balance matches what was actually applied" "$(sql "select count(*) from check_balances();")" "0"


echo "== trade state is tamper-evident, not just postings"
# Tampering is done inside a transaction and rolled back, so the suite can keep going against an
# intact database. The point is only that the verifier notices.
tamper_expect() {   # $1 = tamper statements, $2 = expected verify_trade_chain, $3 = label
  got=$(sql "begin; set session_replication_role = replica; $1 select verify_trade_chain(); rollback;" 2>&1 | tail -1)
  [ "$got" = "$2" ] && ok "$3" || bad "$3" "got '$got' expected '$2'"
}
tamper_chain_expect() {   # same, but asks verify_chain (postings AND state)
  got=$(sql "begin; set session_replication_role = replica; $1 select verify_chain(); rollback;" 2>&1 | tail -1)
  [ "$got" = "$2" ] && ok "$3" || bad "$3" "got '$got' expected '$2'"
}

sql "select ledger_deposit('tc1','USDT',900,'dtc1');" >/dev/null
T=$(sql "select create_trade('tc1','tc2','USDT',900);")
tt $T open escrowed tc-e tc1; tt $T escrowed paid_claimed tc-c tc2
tt $T paid_claimed paid_attested tc-a tc1
expect_eq "state before tampering" "$(sql "select state from trade where id=$T;")" "paid_attested"
expect_eq "chain verifies before tampering" "$(sql "select verify_chain();")" "t"

tamper_expect "update trade set state='released' where id=$T;" f "rewriting trade.state alone is detected"
tamper_expect "update trade set state='released' where id=$T;" f "  ...and so is verify_chain"
tamper_expect "update trade_event set to_state='released' where trade_id=$T and to_state='paid_attested';" f "rewriting a trade event is detected"
tamper_expect "delete from trade_event where trade_id=$T and seq=(select max(seq) from trade_event where trade_id=$T);" f "deleting an event is detected"
tamper_expect "update trade set state='released' where id=$T; update trade_event set to_state='released' where trade_id=$T and to_state='paid_attested';" f "rewriting the state AND the event is still detected"
tamper_chain_expect "update posting set amount = amount + 1 where id = (select min(id) from posting);" f "rewriting a posting is detected by a superuser who bypassed the trigger"

expect_eq "nothing survived the rollbacks" "$(sql "select state from trade where id=$T;")" "paid_attested"
expect_eq "the chain verifies again after every rollback" "$(sql "select verify_chain();")" "t"
expect_eq "and the report agrees" \
  "$(sql "select ledger_chain_ok and trade_chain_ok and balance_mismatches=0 and negative_accounts=0 and unanchored_trades=0 from integrity_report;")" "t"


echo "== a trade that has never moved is anchored too"
# The case 004 missed. With no events at all there was nothing to compare the live state against, so
# a superuser rewrite of a freshly created trade still read as clean. 005 anchors every trade from
# birth, and this is the test that should have caught the gap the first time.
sql "select ledger_deposit('tc3','USDT',500,'dtc3');" >/dev/null
FRESH=$(sql "select create_trade('tc3','tc4','USDT',500);")
expect_eq "a fresh trade is born with one chained event" "$(sql "select count(*) from trade_event where trade_id=$FRESH;")" "1"
expect_eq "and it records none -> open, not an empty state" \
  "$(sql "select from_state||' '||to_state from trade_event where trade_id=$FRESH and seq=1;")" "none open"
expect_eq "no trade is left unanchored" "$(sql "select unanchored_trades from integrity_report;")" "0"
tamper_expect "update trade set state='released' where id=$FRESH;" f "rewriting a trade that never moved is detected"
tamper_chain_expect "update trade set state='released' where id=$FRESH;" f "  ...and so is verify_chain"
tamper_expect "delete from trade_event where trade_id=$FRESH;" f "deleting a creation event is detected"
expect_eq "the fresh trade is intact again" "$(sql "select state from trade where id=$FRESH;")" "open"

# ============================================================ rate card (006)
echo
echo "== our own price, versioned"
asprice "select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com');" >/dev/null
expect_eq "the rate is readable" "$(asapp "select rate from current_rate where base_asset='GHS';")" "148.200000000000000000"
expect_eq "and it names who set it" "$(asapp "select set_by from current_rate where base_asset='GHS';")" "pricing@kudimarket.com"
expect_eq "the inverse pair is not invented by asking" "$(asapp "select coalesce(inverse_rate::text,'none') from rate_board where base_asset='GHS';")" "none"

# A rate is stored to eighteen places whatever scale it arrived with, so the board and the ledger
# quote the identical number. The two legs are set independently and are deliberately NOT required to
# be reciprocals: pricing sets both, and a stale leg is a pricing decision, not a rounding artefact.
asprice "select set_rate('NGN','GHS',0.006709158000670916,'pricing@kudimarket.com');" >/dev/null
expect_eq "the reverse leg now has its own rate" "$(asapp "select rate from current_rate where quote_asset='GHS';")" "0.006709158000670916"
expect_eq "the board pairs the two legs" "$(asapp "select inverse_rate from rate_board where base_asset='GHS';")" "0.006709158000670916"
# Two rows, one per direction, each carrying its own inverse. A board that hid one leg would leave the
# trader quoting a rate we are not actually offering in that direction.
expect_eq "the board lists both directions, each with its inverse" \
  "$(asapp "select count(*) from rate_board where inverse_rate is not null;")" "2"

asprice "select set_rate('GHS','NGN',149.05,'pricing@kudimarket.com',now(),'morning move');" >/dev/null
expect_eq "a newer rate supersedes without touching the old row" "$(sql "select count(*) from rate_card where base_asset='GHS';")" "2"
expect_eq "current is the newest, not the first" "$(asapp "select rate from current_rate where base_asset='GHS';")" "149.050000000000000000"
expect_eq "the superseded rate is still on file" "$(sql "select rate from rate_card where base_asset='GHS' order by effective_from, id limit 1;")" "148.200000000000000000"

expect_fail "a rate needs an actor"        "$(asprice "select set_rate('GHS','NGN',150,'  ');")" "actor required"
expect_fail "a rate must be positive"      "$(asprice "select set_rate('GHS','NGN',0,'pricing');")" "must be positive"
expect_fail "a rate must be positive (neg)" "$(asprice "select set_rate('GHS','NGN',-1,'pricing');")" "must be positive"
expect_fail "a pair needs two assets"      "$(asprice "select set_rate('GHS','GHS',1,'pricing');")" "two different"
expect_fail "an unknown asset is refused"  "$(asprice "select set_rate('GHS','XYZ',1,'pricing');")" "unknown quote"
expect_fail "backdating is refused"        "$(asprice "select set_rate('GHS','NGN',999,'pricing',now() - interval '1 hour');")" "backdating refused"
expect_eq   "the refused backdate changed nothing" "$(sql "select count(*) from rate_card;")" "3"
# Probed as the superuser, because the pricing role deliberately cannot read the card. That is the
# point of the role, so the test must not quietly borrow an operator's eyes to check it.
expect_fail "two rates cannot share a moment" \
  "$(sql "insert into rate_card(base_asset,quote_asset,rate,effective_from,set_by) select 'GHS','NGN',150,min(effective_from),'dup' from rate_card where base_asset='GHS';")" "duplicate key"

echo "== our price is tamper-evident"
tamper_rate_expect() {   # $1 = tamper statements, $2 = expected verify_rate_chain, $3 = label
  got=$(sql "begin; set session_replication_role = replica; $1 select verify_rate_chain(); rollback;" 2>&1 | tail -1)
  [ "$got" = "$2" ] && ok "$3" || bad "$3" "got '$got' expected '$2'"
}
expect_eq "the rate chain verifies" "$(sql "select verify_rate_chain();")" "t"
tamper_rate_expect "update rate_card set rate = 900 where base_asset='GHS';" f "rewriting a rate is detected"
tamper_rate_expect "update rate_card set set_by = 'nobody' where base_asset='GHS';" f "rewriting who set a rate is detected"
tamper_rate_expect "delete from rate_card where base_asset='GHS' and id=(select min(id) from rate_card where base_asset='GHS');" f "deleting the earliest rate is detected"
tamper_rate_expect "update rate_card set effective_from = effective_from - interval '1 hour' where base_asset='GHS';" f "moving a rate in time is detected"
# The link break targets a row that HAS a predecessor. NGN/GHS has only one rate so far and its
# prev_hash is already null; nulling it again changes nothing and the test would pass for free.
tamper_rate_expect "update rate_card set prev_hash = null where base_asset='GHS' and prev_hash is not null;" f "breaking the link on one pair is detected"
expect_eq "and the chain verifies again after every rollback" "$(sql "select verify_rate_chain();")" "t"

echo "== pricing is separated from everything else"
expect_fail "the app cannot publish a rate"  "$(asapp  "select set_rate('GHS','NGN',150,'app');")" "permission denied"
expect_fail "ops cannot publish a rate"      "$(asops  "select set_rate('GHS','NGN',150,'ops-1');")" "permission denied"
expect_fail "pricing cannot settle a trade"  "$(asprice "select ops_transition(1,'x','y','k','z');")" "permission denied"
expect_fail "pricing cannot mint a deposit"  "$(asprice "select ledger_deposit('pricing','USDT',999999,'steal-price');")" "permission denied"
expect_fail "pricing cannot read balances"  "$(asprice "select balance from account limit 1;")" "permission denied"
expect_fail "the app cannot insert a rate row directly" "$(asapp "insert into rate_card(base_asset,quote_asset,rate,set_by) values ('GHS','NGN',1500,'app');")" "permission denied"
expect_fail "the app cannot update a rate row" "$(asapp "update rate_card set rate = 1500;")" "permission denied"
expect_eq "the board is readable by both roles" "$(asops "select count(*) from rate_board;")" "2"
expect_eq "the rate chain is verifiable by both" "$(asapp "select verify_rate_chain();")" "t"
expect_eq "and the report now carries it" "$(asapp "select rate_chain_ok from integrity_report;")" "t"


echo "== a trader can trade and cannot judge"
asprice "select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com');" >/dev/null
sql "select ledger_deposit('tr1','GHS',500000,'dtr1');" >/dev/null
TRD=$(sql "select create_fiat_trade('platform','tr1',null,null,'NGN',200000,148.20,'platform_inventory','GHS','NGN','GHS');")
expect_eq "trader can open a trade against us" "$(sql "select state from trade where id=$TRD;")" "open"
expect_eq "trader can claim their own payment" \
  "$(astrader "select transition_trade($TRD,'open','paid_claimed','tc1','tr1'); select state from trade where id=$TRD;" | tail -1)" "paid_claimed"

# The idempotency key is derived from the trade and the step, so it is NOT a secret: anybody who knows
# the trade id can produce tc1. If the replay check ran before authorisation, a stranger replaying that
# key got a silent SUCCESS -- which reads to the caller as "your claim was accepted" while nothing was
# recorded in their name. The key decides whether a move applies twice, never who may make it.
expect_eq "the claim was recorded exactly once" \
  "$(sql "select count(*) from trade_event where trade_id=$TRD and to_state='paid_claimed';")" "1"
expect_fail "a stranger cannot replay somebody else's claim key" \
  "$(astrader "select transition_trade($TRD,'open','paid_claimed','tc1','mallory@example.com');")" "may not"
expect_eq "and the stranger added no event" \
  "$(sql "select count(*) from trade_event where trade_id=$TRD;")" "2"
expect_eq "and the claim still belongs to the trader who paid" \
  "$(sql "select actor from trade_event where trade_id=$TRD and to_state='paid_claimed';")" "tr1"

# ---------------------------------------------------------------- the dispute narrative
# A dispute that records THAT somebody disputed and WHO, but not what they said, is not a dispute. The
# words are the evidence, so they go in the chain: if the narrative can be edited without breaking the
# hash, it proves nothing to anybody reading it later.
# A separate trade: disputing this one would leave $TRD in a state the assertions below expect.
sql "select ledger_deposit('tr1','GHS',500000,'dtr2');" >/dev/null
DSP=$(sql "select create_fiat_trade('platform','tr1',null,null,'NGN',200000,148.20,'platform_inventory','GHS','NGN','GHS');")
astrader "select transition_trade($DSP,'open','paid_claimed','dp1','tr1');" >/dev/null
expect_eq "a dispute reaches the disputed state" \
  "$(astrader "select transition_trade($DSP,'paid_claimed','disputed','dp2','tr1',null,'The naira has not arrived at all.'); select state from trade where id=$DSP;" | tail -1)" "disputed"
expect_eq "the trader's own words are stored" \
  "$(sql "select narrative from trade_event where trade_id=$DSP and to_state='disputed';")" "The naira has not arrived at all."
expect_eq "and the trade chain still verifies" "$(sql "select verify_trade_chain();")" "t"
expect_eq "an event with no narrative stores none" \
  "$(sql "select coalesce(narrative,'<null>') from trade_event where trade_id=$DSP and to_state='paid_claimed';")" "<null>"
expect_eq "and a narrative-free event still verifies" "$(sql "select verify_trade_chain();")" "t"

# Rewriting the trader's words as the database owner -- the only actor who can do it -- must break the
# chain. This is the whole reason the narrative is hashed rather than stored beside it.
sql "alter table trade_event disable trigger trade_event_append_only; update trade_event set narrative = 'They simply did not pay' where trade_id=$DSP and to_state='disputed';" >/dev/null
expect_eq "editing the trader's words is detected" "$(sql "select verify_trade_chain();")" "f"
expect_eq "and the integrity report says the trade chain is broken" "$(sql "select trade_chain_ok from integrity_report;")" "f"
# Put it back the way the trader said it, by hand. This is the one repair that is legitimate: an operator
# correcting a database error, as opposed to a person rewriting what a counterparty claimed.
sql "update trade_event set narrative = 'The naira has not arrived at all.' where trade_id=$DSP and to_state='disputed';" >/dev/null
expect_eq "restoring the true words restores the chain" "$(sql "select verify_trade_chain();")" "t"

# A trade is disputed or it is not. It does not collect several grievances, so the state machine refuses
# a second one whatever the reason.
expect_fail "a trade cannot be disputed twice" \
  "$(astrader "select transition_trade($DSP,'disputed','disputed','dp3','tr1',null,'and also this');")" "illegal transition"

# Each of these asserts something the trader role must NOT be able to do. Using astrader rather than
# asops matters: asops would pass for the wrong reason, because ops cannot set a rate either. Only
# astrader proves the grant did not leak to the wrong account.
expect_fail "a trader cannot attest their own payment" \
  "$(astrader "select transition_trade($TRD,'paid_claimed','paid_attested','tc2','tr1');")" "system step\|may not"
expect_fail "a trader cannot resolve a dispute" \
  "$(astrader "select ops_transition($TRD,'paid_claimed','disputed','tc3','tr1');")" "permission denied\|ops only\|may not"
expect_fail "a trader cannot publish a rate" \
  "$(astrader "select set_rate('GHS','NGN',999,'tr1');")" "permission denied"
expect_fail "a trader cannot mint a deposit" \
  "$(astrader "select ledger_deposit('tr1','GHS',999999,'steal-trader');")" "permission denied"
expect_fail "a trader cannot call the internal transition function" \
  "$(astrader "select _apply_transition($TRD,'paid_claimed','disputed','tc9','tr1',true,null);")" "permission denied"
expect_fail "a trader cannot write a trade row" \
  "$(astrader "insert into trade(seller,buyer,asset,amount) values ('platform','x','GHS',1);")" "permission denied"
expect_fail "a trader cannot write a posting" \
  "$(astrader "insert into posting(tx_id,account_id,asset,amount) values (1,(select min(id) from account),'GHS',1);")" "permission denied"
expect_fail "a trader cannot edit a balance" \
  "$(astrader "update account set balance = 999999 where id=(select min(id) from account);")" "permission denied"
# Both directions are on the card by this point, and rate_board lists one row per direction, so the
# count is 2. Asserting 1 here would have been a test written to match the number I expected rather
# than the number the product produces.
expect_eq "a trader can read the board" "$(astrader "select count(*) from rate_board;")" "2"
expect_eq "a trader can read their own trade" "$(astrader "select state from trade where id=$TRD;")" "paid_claimed"
expect_eq "the ledger still balances" "$(sql "select count(*) from check_balances();")" "0"


echo "== traders"
sql "insert into trader(name,email,password) values ('Trader One','t1@example.com','x');" >/dev/null
expect_fail "trader email must be unique" "$(sql "insert into trader(name,email,password) values ('A','dup@example.com','x'); insert into trader(name,email,password) values ('B','dup@example.com','x');")" "duplicate key"
expect_fail "trader email must look like an address" "$(sql "insert into trader(name,email,password) values ('C','notanemail','x');")" "trader_email_check\|violates check"
expect_fail "trader needs a name" "$(sql "insert into trader(name,email,password) values ('  ','blank@example.com','x');")" "trader_name_check\|violates check"
expect_fail "trader phone must be digits" "$(sql "insert into trader(name,email,phone,password) values ('D','d@example.com','+233-not-a-number','x');")" "violates check"
expect_fail "a trader cannot verify themselves" "$(astrader "update trader set verified_at = now() where email='t1@example.com';")" "permission denied"
expect_eq "a trader can write its own remember token" "$(astrader "update trader set remember_token='probe' where email='t1@example.com';")" ""
expect_fail "but that is the ONLY column it may write" "$(astrader "update trader set name='Someone Else' where email='t1@example.com';")" "permission denied"
expect_fail "a trader cannot rewrite their own kind" "$(astrader "update trader set kind='business' where email='t1@example.com';")" "permission denied"
expect_fail "a trader cannot invent another trader" "$(astrader "insert into trader(name,email,password) values ('E','e@example.com','x');")" "permission denied"
expect_eq "a trader can read the trader list" "$(astrader "select count(*) from trader;")" "1"
expect_eq "trader table holds no balance column" "$(sql "select count(*) from information_schema.columns where table_name='trader' and column_name like '%balance%';")" "0"
expect_eq "and the ledger still balances" "$(sql "select count(*) from check_balances();")" "0"

echo "== the offer book"
# Traders, and they are VERIFIED -- through the real path, not by setting a column.
#
# publish_offer reads the verification CHAIN rather than trader.verified_at, because the column is a cache
# and a cache is what drifts. That means the offer cases below cannot simply UPDATE verified_at any more,
# which is the point: the only way to be checked is to be checked.
sql "insert into trader(name,email,password) values
       ('Kwesi Boateng','kwesi@example.com','x'),
       ('Ama Serwaa','ama@example.com','x'),
       ('Adwoa Mensah','adwoa@example.com','x'),
       ('Unchecked Person','unchecked@example.com','x')
     on conflict (email) do nothing;" >/dev/null
# astrader to apply, asops to decide: a TRADER applies and OPERATIONS decides. Seeding through asapp failed
# with "permission denied for function apply_for_verification", which is the grant working -- kudi_app is
# deliberately not allowed to apply on anybody's behalf, the same way it cannot mint or attest.
for who in kwesi ama adwoa; do
  astrader "select apply_for_verification('$who@example.com',
      'Adwoa Serwaa, Ghana Card GHA-000000000-1-1, 12 Boundary Road Accra, own-name MTN 0240000001 at KudiMarket, funds from trading.');" >/dev/null
  asops "select decide_verification('$who@example.com', true, 'checked against Ghana Card and own-name account', 'ops@kudimarket.com');" >/dev/null
done
# The supply side. Everything here is about a seller being unable to change what they said after a buyer
# acted on it, and about an offer never being a price KudiMarket must honour.
sql "select ledger_deposit('ama','NGN',5000000,'naira-for-ama');" >/dev/null
sql "select ledger_deposit('kwesi','GHS',900000,'cedi-for-kwesi');" >/dev/null

OF1=$(sql "select publish_offer('kwesi@example.com','NGN','GHS',200000,0.0060);")
expect_eq "a seller can publish an offer" "$(sql "select seller from offer where id=$OF1;")" "kwesi@example.com"
expect_eq "it is open and not expired" "$(sql "select expired from open_offer where id=$OF1;")" "f"
expect_eq "nothing is escrowed by listing" "$(sql "select count(*) from account where owner like 'offer:%';")" "0"
expect_eq "the offer chain verifies" "$(sql "select verify_offer_chain();")" "t"

# Two sellers in sequence, in the SAME verification pass. One seller per section is what hid the bug where
# the running hash was carried across sellers: a single-seller chain passes either way.
sql "select publish_offer('ama@example.com','NGN','GHS',90000,0.0060);" >/dev/null
sql "select publish_offer('ama@example.com','NGN','GHS',90000,0.0060);" >/dev/null
expect_eq "two sellers chain independently and both still verify" "$(sql "select verify_offer_chain();")" "t"
expect_eq "and the regulator-facing report says so" "$(sql "select offer_chain_ok from integrity_report;")" "t"
expect_fail "a seller cannot publish an offer for themselves in both directions" \
  "$(astrader "select publish_offer('kwesi@example.com','GHS','GHS',1000,1);")" "two different assets"
expect_fail "an offer needs a positive amount" \
  "$(astrader "select publish_offer('kwesi@example.com','NGN','GHS',0,0.0060);")" "must be positive"
expect_fail "an offer needs a positive walk-away rate" \
  "$(astrader "select publish_offer('kwesi@example.com','NGN','GHS',1000,0);")" "must be positive"
expect_fail "an offer needs a lifetime" \
  "$(astrader "select publish_offer('kwesi@example.com','NGN','GHS',1000,0.0060,interval '0 seconds');")" "lifetime"
expect_fail "an offer may not live forever" \
  "$(astrader "select publish_offer('kwesi@example.com','NGN','GHS',1000,0.0060,interval '2 days');")" "one day"
expect_fail "an offer needs somebody's name on it" \
  "$(astrader "select publish_offer('  ','NGN','GHS',1000,0.0060);")" "seller required"

# APPEND-ONLY. This is the property the whole file exists for: a seller who can edit their walk-away after
# a buyer acted on it can rewrite the terms of a deal that already happened.
expect_fail "an offer cannot be edited" \
  "$(sql "update offer set min_rate = 0.0001 where id=$OF1;")" "append.only\|immutable"
expect_fail "an offer cannot be deleted" \
  "$(sql "delete from offer where id=$OF1;")" "append.only\|immutable"
# The trigger stays off across BOTH the tamper and the restore, and is switched back on only once the row
# holds what the seller said. Re-enabling between them made the restore UPDATE itself get refused, so the
# walk-away stayed rewritten and every later chain assertion in this file failed.
sql "alter table offer disable trigger offer_append_only; update offer set min_rate = 0.0001 where id=$OF1;" >/dev/null
expect_eq "rewriting the walk-away breaks the chain" "$(sql "select verify_offer_chain();")" "f"
# Restore first, THEN re-enable. Written the other way round the update is refused by the very trigger
# being restored, the walk-away stays rewritten, and the next assertion fails for a reason that looks like
# a bug in the chain rather than a refused UPDATE.
sql "update offer set min_rate = 0.0060 where id=$OF1; alter table offer enable trigger offer_append_only;" >/dev/null
expect_eq "restoring the walk-away restores the chain" "$(sql "select verify_offer_chain();")" "t"

# A WITHDRAWAL is an insert of a fact, not the erasure of the original statement.
OF2=$(sql "select publish_offer('kwesi@example.com','NGN','GHS',150000,0.0060);")
astrader "select cancel_offer($OF2,'kwesi@example.com');" >/dev/null
expect_eq "a withdrawn offer keeps its original amount on the record" "$(sql "select amount from offer where id=$OF2;")" "150000"
expect_eq "and is marked withdrawn" "$(sql "select reason from offer_withdrawal where offer_id=$OF2;")" "withdrawn"
expect_eq "and is off the board" "$(sql "select count(*) from open_offer where id=$OF2;")" "0"
expect_eq "and the chain still verifies" "$(sql "select verify_offer_chain();")" "t"
expect_fail "a stranger cannot withdraw somebody else''s offer" \
  "$(astrader "select cancel_offer($OF2,'ama@example.com');")" "may not withdraw"
expect_fail "an offer cannot be withdrawn twice" \
  "$(astrader "select cancel_offer($OF2,'kwesi@example.com');")" "no longer open"

# THE WALK-AWAY IS NOT A PRICE. This is the distinction brand/product.html turns on: KudiMarket quotes its
# own rate and is not a matching platform. An acceptance is priced by the CARD, so a seller whose
# walk-away is met still trades at the board rate, not at the number they typed.
asprice "select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com');" >/dev/null
# Both parties funded, because a vendor claim takes a deposit from each side and the deposit is 10% of
# the BASE leg taken in cedi -- for a N120,000 trade that is C12,000, charged in a currency the naira
# seller may not hold. See the deposit defect noted in the application suite: this is the same arithmetic
# making a real trade impossible, and it is why these cases have to fund both sides at all.
sql "select ledger_deposit('kwesi','GHS',9000000,'attest-kwesi');" >/dev/null
sql "select ledger_deposit('ama','GHS',9000000,'attest-ama');" >/dev/null
OF3=$(sql "select publish_offer('kwesi@example.com','NGN','GHS',200000,0.0060);")
TRD_V=$(astrader "select accept_offer($OF3,'ama@example.com','ama@example.com');" 2>&1 | grep -oE '[0-9]+$' | head -1)
expect_eq "accepting an offer creates a vendor_to_vendor trade" \
  "$(sql "select trade_type from trade where id=$TRD_V;")" "vendor_vendor"
expect_eq "priced by the rate card, not by the walk-away" \
  "$(sql "select rate from trade where id=$TRD_V;")" "0.006756756756756756"
expect_eq "the seller is the offer's seller" "$(sql "select seller from trade where id=$TRD_V;")" "kwesi@example.com"
expect_eq "the buyer is whoever accepted" "$(sql "select buyer from trade where id=$TRD_V;")" "ama@example.com"
expect_eq "accepting consumes the offer" "$(sql "select count(*) from open_offer where id=$OF3;")" "0"
expect_eq "and records that it was filled, not withdrawn" "$(sql "select reason from offer_withdrawal where offer_id=$OF3;")" "filled"
expect_eq "the offer chain still verifies" "$(sql "select verify_offer_chain();")" "t"

# ---------------------------------------------------------------- the two assurance tiers
# brand/product.html: "Two levels: checked, and KudiMarket handled. One badge that reads 'safe to send
# money to' is a guarantee we have not priced. Two labels let us sell the strong promise on the trades we
# control and be factual on the rest."
#
# So on a VENDOR trade the recipient confirms the money arrived. The buyer is relying on our verification
# of the seller, not on a guarantee from us, and putting operations in the middle of every trade would
# make KudiMarket the guarantor of every one of them -- the priced tier, and the reason the Bank of Ghana
# rather than the SEC is the first regulator. An exception allowing exactly that was written here and then
# removed; these assertions are what it must never become again.
asprice "select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com');" >/dev/null
# Both parties funded: a vendor claim takes a deposit from each side, and the deposit is 10% of the BASE
# leg taken in cedi. See the deposit defect noted in the application suite -- the same arithmetic that makes
# a real vendor trade impractical today.
# Under the TRADER addresses, not the bare names the ledger sections above use. Funded 'kwesi' and 'ama'
# -- the account owners in the earlier sections -- and the claim then failed on insufficient funds while the
# assertion blamed the state machine.
sql "select ledger_deposit('kwesi@example.com','GHS',9000000,'tier-kwesi');" >/dev/null
sql "select ledger_deposit('ama@example.com','GHS',9000000,'tier-ama');" >/dev/null
sql "" >/dev/null

# Prints the TRADE id and nothing else: an offer id is not a trade id, and echoing both produced
# "where id = 1 2" syntax errors that read as empty results.
vend() { local o t; o=$(sql "select publish_offer('kwesi@example.com','NGN','GHS',120000,0.0060);"); t=$(astrader "select accept_offer($o,'ama@example.com','ama@example.com');" | tail -1); echo "$t"; }

# The RECIPIENT confirms -- the seller, on the trader role. Written with ops() first, which the previous
# commit made legal and which is now correctly refused, so the "recipient confirms" case was quietly
# testing operations instead of the seller.
T1=$(vend); astrader "select transition_trade($T1,'open','paid_claimed','t1','ama@example.com');" >/dev/null
astrader "select transition_trade($T1,'paid_claimed','paid_attested','t2','kwesi@example.com');" >/dev/null
expect_eq "the recipient confirms a vendor payment" "$(sql "select state from trade where id=$T1;")" "paid_attested"
expect_eq "recorded against the seller, not against us" \
  "$(sql "select actor from trade_event where trade_id=$T1 and to_state='paid_attested';")" "kwesi@example.com"

T2=$(vend); astrader "select transition_trade($T2,'open','paid_claimed','t3','ama@example.com');" >/dev/null
expect_fail "operations may NOT confirm it -- we are not the counterparty" \
  "$(asops "select ops_transition($T2,'paid_claimed','paid_attested','t4','ops@kudimarket.com');")" "may not do"
expect_fail "and neither may the payer, who is owed the money" \
  "$(astrader "select transition_trade($T2,'paid_claimed','paid_attested','t5','ama@example.com');")" "may not do"
expect_eq "the trade sits where the tiers say it should" "$(sql "select state from trade where id=$T2;")" "paid_claimed"

# An UNVERIFIED trader cannot list. Verification is what unlocks being a counterparty; without it the only
# trade available is against our own book, where we are the counterparty and do guarantee.
# 'unchecked', the one trader the seed does NOT verify. kwesi and adwoa are both verified by the loop
# above, and apply_for_verification refuses a verified trader -- correctly, so testing a refusal on either
# proved nothing at all while looking like it tested something.
astrader "select apply_for_verification('unchecked@example.com','Unchecked Person, Ghana Card, own-name MTN 0240000000, funds from trading.');" >/dev/null
sql "select decide_verification('unchecked@example.com', false, 'Ghana Card number does not match the name on your account.', 'ops@kudimarket.com');" >/dev/null
expect_fail "a REFUSED trader cannot publish an offer" \
  "$(astrader "select publish_offer('unchecked@example.com','NGN','GHS',120000,0.0060);")" "not verified"
# And the refusal carries its reason, which is the difference between a decision and a dead end.
expect_eq "the refusal says what is missing" \
  "$(sql "select narrative from verification_status where trader_email='unchecked@example.com';")" \
  "Ghana Card number does not match the name on your account."
# Re-applying and being checked again is how a trader comes back after fixing it.
astrader "select apply_for_verification('unchecked@example.com','Unchecked Person, Ghana Card GHA-000000000-0-0, own-name MTN 0240000000, funds from trading.');" >/dev/null
asops "select decide_verification('unchecked@example.com', true, 're-checked, card now matches', 'ops@kudimarket.com');" >/dev/null
expect_eq "and can publish once checked" "$(astrader "select publish_offer('kwesi@example.com','NGN','GHS',120000,0.0060);" | tail -1 | grep -cE '^[0-9]+$')" "1"

expect_eq "the trade chain verifies through all of it" "$(sql "select verify_trade_chain();")" "t"
expect_fail "the same offer cannot be sold twice" \
  "$(astrader "select accept_offer($OF3,'ama@example.com','ama@example.com');")" "no longer open"

# A walk-away the board no longer meets means the seller does not trade. This is the guard that stops a
# seller being quietly filled at a price worse than the one they agreed to accept.
OF4=$(sql "select publish_offer('kwesi@example.com','NGN','GHS',100000,0.0090);")
expect_fail "an offer whose walk-away the board does not meet will not clear" \
  "$(astrader "select accept_offer($OF4,'ama@example.com','ama@example.com');")" "will not clear"
expect_eq "and it is still on the board for later" "$(sql "select count(*) from open_offer where id=$OF4;")" "1"
OF5=$(sql "select publish_offer('ama@example.com','NGN','GHS',100000,0.0060);")
expect_fail "a seller cannot accept their own offer" \
  "$(astrader "select accept_offer($OF5,'ama@example.com','ama@example.com');")" "own offer"
expect_fail "an expired offer cannot be accepted" \
  "$(astrader "select accept_offer((select id from offer where seller='kwesi@example.com' and cancelled_at is null and expires_at <= now() limit 1),'ama@example.com','ama@example.com');")" "expired\|does not exist"

# Permissions. The walk-away is the seller's own; the PRICE is not anybody's but pricing's.
expect_fail "a trader cannot publish somebody else''s rate card" \
  "$(astrader "select set_rate('NGN','GHS',9,'tr1');")" "permission denied"
expect_eq "the offer book holds no balance column" \
  "$(sql "select count(*) from information_schema.columns where table_name='offer' and column_name like '%balance%';")" "0"
expect_eq "and the ledger still balances" "$(sql "select count(*) from check_balances();")" "0"

echo "== verification"
# How a trader becomes somebody you can trade with. 009 gates publishing on this, and before 010 nothing
# in the entire system could ever set it -- so the gate existed and the door did not.
sql "insert into trader(name,email,password) values ('Solo Trader','solo@example.com','x') on conflict do nothing;" >/dev/null
expect_fail "an unverified trader cannot publish an offer" \
  "$(astrader "select publish_offer('solo@example.com','NGN','GHS',1000,0.006);")" "not verified"

expect_fail "an application needs something to actually check" \
  "$(astrader "select apply_for_verification('solo@example.com','too short');")" "details required"
expect_fail "and it must be a real trader" \
  "$(astrader "select apply_for_verification('nobody@example.com','a very long set of details that would pass the length check');")" "no such trader"

EV=$(astrader "select apply_for_verification('solo@example.com','Solo Trader, Ghana Card GHA-111111111-1-1, 9 High Street Accra, own-name MTN 0245550111, funds from trading.');")
expect_eq "a trader can apply to be checked" "$(sql "select event from verification_status where trader_email='solo@example.com';")" "applied"
expect_eq "and it shows as pending" "$(sql "select is_pending from verification_status where trader_email='solo@example.com';")" "t"
expect_eq "applied is not verified" "$(sql "select is_verified from verification_status where trader_email='solo@example.com';")" "f"
expect_fail "a second application while one is open is refused" \
  "$(astrader "select apply_for_verification('solo@example.com','another long set of details that passes the length check');")" "already applied"
expect_fail "a trader cannot decide their own application" \
  "$(astrader "select decide_verification('solo@example.com', true, null, 'solo@example.com');")" "permission denied\|nothing to decide"
expect_fail "and cannot mark themselves verified in the table either" \
  "$(astrader "update trader set verified_at = now() where email = 'solo@example.com';")" "permission denied"
expect_fail "a refusal with no reason is refused" \
  "$(asops "select decide_verification('solo@example.com', false, null, 'ops@kudimarket.com');")" "reason required"
expect_fail "a refusal with a one-word reason is refused" \
  "$(asops "select decide_verification('solo@example.com', false, 'no', 'ops@kudimarket.com');")" "reason required"
expect_fail "a decision must name the person who made it" \
  "$(asops "select decide_verification('solo@example.com', true, null, '');")" "actor required"

asops "select decide_verification('solo@example.com', true, 'checked against Ghana Card and own-name account', 'ops@kudimarket.com');" >/dev/null
expect_eq "operations can check a trader" "$(sql "select event from verification_status where trader_email='solo@example.com';")" "verified"
expect_eq "recorded against the staff member" \
  "$(sql "select actor from verification_status where trader_email='solo@example.com';")" "ops@kudimarket.com"
expect_eq "and the column is written in the same statement" \
  "$(sql "select verified_at is not null from trader where email='solo@example.com';")" "t"
expect_eq "the trader can now publish" "$(astrader "select publish_offer('solo@example.com','NGN','GHS',1000,0.006);" | tail -1 | grep -cE '^[0-9]+$')" "1"
expect_fail "there is nothing left to decide" \
  "$(asops "select decide_verification('solo@example.com', true, 'again', 'ops@kudimarket.com');")" "nothing to decide"

# The chain is the record, and the column is a cache that must never drift from it.
expect_eq "the verification chain verifies" "$(sql "select verify_verification_chain();")" "t"
expect_eq "and the report carries it" "$(sql "select verification_chain_ok from integrity_report;")" "t"
expect_eq "the column and the chain agree" \
  "$(sql "select count(*) from trader t where (t.verified_at is not null) is distinct from coalesce((select is_verified from verification_status v where v.trader_email = t.email), false);")" "0"
# Scoped to the 'verified' row. Without "and event='verified'" this rewrote BOTH of the trader's events,
# so the restore could only put one right and the chain stayed broken -- which looks like a bug in the
# chain rather than in the test's own cleanup.
sql "alter table verification_event disable trigger verification_event_append_only; update verification_event set narrative = 'we checked everything, trust us' where trader_email='solo@example.com' and event='verified';" >/dev/null
expect_eq "rewriting what a trader wrote is detected" "$(sql "select verify_verification_chain();")" "f"
expect_eq "and the report says so" "$(sql "select verification_chain_ok from integrity_report;")" "f"
sql "update verification_event set narrative = 'checked against Ghana Card and own-name account' where trader_email='solo@example.com' and event='verified'; alter table verification_event enable trigger verification_event_append_only;" >/dev/null
expect_eq "restoring the true words restores the chain" "$(sql "select verify_verification_chain();")" "t"
expect_fail "an application cannot be edited away" \
  "$(sql "delete from verification_event where trader_email='solo@example.com';")" "append.only\|immutable"
expect_eq "and the ledger still balances" "$(sql "select count(*) from check_balances();")" "0"

echo "== the deposit is a share of the trade's VALUE, not of the base leg"
# Every deposit test above this line uses rate = 1 with deposit_asset = fiat_asset, so the conversion is a
# no-op and a deposit as a share of the wrong number looks identical to a correct one. That is how a
# fourteen-fold overcharge sat in 004 through 286 passing tests: nothing ever priced a vendor trade off the
# rate card and then asked for a deposit.
#
# A real vendor trade is priced from the card, so its two legs are in different currencies: the buyer sends
# naira, the seller owes cedi, and the deposit is taken in cedi. The number the deposit is a share of has
# to be the trade's value IN CEDI, not the naira figure.
sql "insert into asset values ('NGN',2);" >/dev/null
sql "select ledger_deposit('dv1','GHS',100000,'dep-dv1'); select ledger_deposit('dw1','GHS',100000,'dep-dw1');" >/dev/null
# 500,000 minor naira at 0.0067567... cedi per naira is 3,378 minor cedi, so a 10% deposit is 337.
DV=$(sql "select create_fiat_trade('dv1','dw1',null,null,'NGN',500000,0.006756756756756756,'vendor_vendor','GHS','GHS','NGN');")
# The deposit is taken when the buyer claims, which is where a fiat-only trade first has both parties on
# the hook. There is no crypto leg to escrow, so open -> escrowed is refused outright.
tt $DV open paid_claimed dvc dw1
expect_eq "deposit is 10% of the trade's value in the deposit asset" \
  "$(sql "select balance from account where owner='trade:$DV:seller_deposit' and asset='GHS';")" "337"
expect_eq "and it is taken from the seller in cedi" \
  "$(sql "select balance from account where owner='dv1' and asset='GHS' and kind='available';")" "99663"
expect_eq "and from the buyer in cedi too" \
  "$(sql "select balance from account where owner='dw1' and asset='GHS' and kind='available';")" "99663"
# The exact figure above is the whole defence: 50,000 here is 14.8x the entire trade, so any drift back to
# a share of the naira figure shows up as a wrong number rather than needing a second assertion.
tt $DV paid_claimed paid_attested dva dv1; tt $DV paid_attested released dvr system
expect_eq "success: both deposits come back" \
  "$(sql "select coalesce(sum(balance),0) from account where owner like 'trade:$DV:%';")" "0"
expect_eq "success: nobody lost anything" \
  "$(sql "select balance from account where owner='dv1' and asset='GHS' and kind='available';")" "100000"

echo "== a rate is only a number until you know which side of the pair it counts"
# trade.rate was stored with two opposite meanings and nothing recorded which. openAgainstUs reads the card
# for (GHS,NGN) and gets 148.2 NGN PER CEDI; accept_offer reads it for (NGN,GHS) and gets 0.0067567 CEDI PER
# NAIRO. Both rows then read fiat_asset = NGN with fiat_amount in naira, so they are indistinguishable, and
# anything deriving the cedi side has to GUESS. It guessed the platform way, which is 21904x wrong for vendor
# trades -- the screen told a buyer to send C74,000,000 on a trade worth C3,378.38.
sql "insert into asset values ('NGN',2);" >/dev/null
DT=$(sql "select create_fiat_trade('dw2','dw3',null,null,'NGN',500000,0.006756756756756756,'vendor_vendor','GHS','GHS');")
expect_eq "a rate-priced trade records the asset the rate counts in" \
  "$(sql "select rate_quote_asset from trade where id=$DT;")" "GHS"
expect_eq "and it is the quote asset of the pair the offer was priced from" \
  "$(sql "select rate_quote_asset from trade where id=$DT and rate = 0.006756756756756756;")" "GHS"

# The platform shape: rate quoted in the fiat asset itself.
PT=$(sql "select create_fiat_trade('platform','dw5',null,null,'NGN',200000,148.2,'platform_inventory','GHS','NGN','GHS');")
expect_eq "a nine-argument caller is untouched and records its own fiat asset" \
  "$(sql "select rate_quote_asset from trade where id=$PT;")" "NGN"

expect_eq "the two shapes are now distinguishable from the row alone" \
  "$(sql "select count(distinct rate_quote_asset) from trade where id in ($DT,$PT);")" "2"
# The old nine-argument call still works, unchanged, and records its own fiat asset as the direction.
OT=$(sql "select create_fiat_trade('dw6','dw7',null,null,'NGN',300000,1,'vendor_vendor');")
expect_eq "a nine-argument caller is untouched and gets its fiat asset" \
  "$(sql "select rate_quote_asset from trade where id=$OT;")" "NGN"
expect_eq "and its deposit asset is still cedi, which 011 must not have cost it" \
  "$(sql "select deposit_asset from trade where id=$OT;")" "GHS"

echo "== the pair, so both currencies on a trade are named rather than inferred"
# 011 recorded what the rate COUNTS IN. It did not record the other end of the pair, which left every
# consumer inferring it -- and the inference was hardcoded to cedi, because until now cedi was the only
# currency a buyer could receive from us. brand/product.html settles the product question: "Which pairs do
# we quote? Cedi against naira, BOTH DIRECTIONS." A trader who buys cedi with naira receives a currency
# the app had no stored fact about.
#
# The invariant this file relies on, checked against all three existing shapes rather than assumed:
#
#   vendor (offer NGN/GHS)   buyer pays NGN, receives GHS   pair NGN/GHS
#   platform buy naira       buyer pays GHS, receives NGN   pair GHS/NGN
#   platform buy cedi        buyer pays NGN, receives GHS   pair NGN/GHS
#
# The buyer always pays the BASE leg. That is not a coincidence to be leaned on quietly -- it is what
# makes one rule serve every trade, and it is asserted below rather than described.
sql "insert into asset values ('NGN',2);" >/dev/null
set_rate() { sql "select set_rate('$1','$2',$3,'pricing@kudimarket.com');" >/dev/null; }
set_rate GHS NGN 148.2
set_rate NGN GHS 0.006756756756756756

# The trader has to exist before it can apply to be checked, so the insert comes first.
sql "insert into trader(email,name,phone,password) values ('pair@e.com','Pair Test','0240000000','x') on conflict (email) do nothing;" >/dev/null
sql "select apply_for_verification('pair@e.com','Ghana Card GHA-1. 12 Boundary Road Accra. Own-name MTN 0240000000. Funds from trading.');" >/dev/null
sql "select decide_verification('pair@e.com',true,null,'ops@kudimarket.com');" >/dev/null

# Buying NAIRA with cedi: pays GHS, receives NGN.
sql "select publish_offer('pair@e.com','NGN','GHS',50000000,0.0060,interval '15 minutes');" >/dev/null
sql "select accept_offer((select max(id) from offer),'pair-buyer@e.com','pair-buyer@e.com');" >/dev/null
NV=$(sql "select max(id) from trade where trade_type = 'vendor_vendor';")
expect_eq "a vendor trade names both ends of its pair" \
  "$(sql "select rate_base_asset||'/'||rate_quote_asset from trade where id=$NV;")" "NGN/GHS"
expect_eq "the base is the currency the buyer pays" \
  "$(sql "select rate_base_asset from trade where id=$NV;")" "NGN"

# Buying NAIRA from us: pays GHS, receives NGN. The pair is the other way round.
BN=$(sql "select create_fiat_trade('platform','pair-buyer@e.com',null,null,'NGN',200000,148.2,'platform_inventory','GHS','NGN','GHS');")
expect_eq "buying naira with cedi names the cedi-naira pair" \
  "$(sql "select rate_base_asset||'/'||rate_quote_asset from trade where id=$BN;")" "GHS/NGN"

# Buying CEDI from us: pays NGN, receives GHS. Same rate card row as the vendor trade, opposite side of it.
BC=$(sql "select create_fiat_trade('platform','pair-buyer@e.com',null,null,'GHS',1350,0.006756756756756756,'platform_inventory','GHS','GHS','NGN');")
expect_eq "buying cedi with naira names the naira-cedi pair" \
  "$(sql "select rate_base_asset||'/'||rate_quote_asset from trade where id=$BC;")" "NGN/GHS"
expect_eq "and the cedi amount it received is in the cedi leg" \
  "$(sql "select fiat_amount = 1350 and fiat_asset = 'GHS' from trade where id=$BC;")" "t"

# Naira to naira at 148.2 is the kind of mistake that looks fine on a form: both ends filled in, a real
# rate, and no pair at all. A rate of 1 is exempt, because 1 is the placeholder for "no conversion here"
# rather than a conversion between a currency and itself.
expect_fail "a pair whose two ends are the same is refused, because a rate would mean nothing" \
  "$(sql "select create_fiat_trade('platform','pair-buyer@e.com',null,null,'NGN',200000,148.2,'platform_inventory','GHS','NGN','NGN');")" \
  "different assets"

EX1=$(sql "select create_fiat_trade('platform','pair-buyer@e.com',null,null,'NGN',300000,1,'platform_inventory','GHS','NGN','NGN');")
expect_eq "a single-unit rate is exempt, being a placeholder rather than a conversion" \
  "$(sql "select rate::text from trade where id=$EX1;")" "1.000000000000000000"


echo "== something has to settle the trade, or it sits here for ever"
# paid_attested -> released has actor 'system' and NOTHING performed it. No scheduler, no command, no
# function. The copy was softened once to stop promising automatic settlement, which was honest and also
# hid a missing mechanism instead of adding one.
#
# The claim window is why this is not instant: our book receives fiat by mobile money and is exposed to
# reversals, so a buyer needs time to say it never arrived.
sql "insert into asset values ('NGN',2);" >/dev/null
# One statement per call, so this returns ONE id. The first version ran two and captured both, so every
# caller got a two-line string where a trade id was expected -- and the failures read as the sweeper being
# broken rather than as a broken fixture.
setup_claim() { sql "select create_trade('platform','sw$1','USDT',1000,'platform_inventory');"; }
# The float belongs to PLATFORM, not the buyer. On our own book we are the seller, so it is our balance
# that is reserved on escrow -- funding the buyer instead fails with "insufficient funds on account 5",
# which is a confusing way to learn whose money moves first.
fund_claim() { sql "select ledger_deposit('platform','USDT',20000,'swplat-$1');" >/dev/null; }

# Three attested trades. The window is 15 minutes for our own book, so all three start outside it.
sql "update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory';" >/dev/null
for n in 1 2 3; do
  fund_claim $n
  T=$(setup_claim $n)
  tt $T open escrowed "swe$n" "sw$n"
  tt $T escrowed paid_claimed "swc$n" "sw$n"
  asops "select ops_transition($T,'paid_claimed','paid_attested','swa$n','ops-1');" >/dev/null
done
# Scoped to this block's own trades. The sweeper is global and does its job on everything due, so a
# global count here would be reporting on other tests' fixtures rather than on this one -- and it did,
# releasing two of them, which looked like the window had been ignored.
expect_eq "an attested trade is not released while the claim window is open" \
  "$(sql "select count(*) from trade where state = 'paid_attested' and buyer like 'sw%';")" "3"
expect_eq "and the sweeper leaves it there rather than forcing it" \
  "$(sql "select count(*) from release_due_trades() r, trade t where t.buyer like 'sw%' and t.state = 'released' and r.released >= 0;")" "0"
expect_eq "so they are all still attested" \
  "$(sql "select count(*) from trade where state = 'paid_attested' and buyer like 'sw%';")" "3"

# Open the window, which is the only thing that should change anything.
sql "update trade_policy set claim_window = '0 seconds';" >/dev/null
# The returned count is GLOBAL -- the sweeper settles whatever is due, including fixtures earlier in this
# suite left over -- so asserting on it would be asserting on other tests. What is being checked here is
# that these three got settled, which the next line asks directly.
sql "select released from release_due_trades();" >/dev/null
expect_eq "the sweeper settles every attested trade whose window has closed" \
  "$(sql "select count(*) from trade where buyer like 'sw%' and state = 'released';")" "3"
expect_eq "and they are all released" "$(sql "select count(*) from trade where state = 'paid_attested' and buyer like 'sw%';")" "0"
expect_eq "and they are green" "$(sql "select count(*) from trade_view where state='released' and colour='green' and buyer like 'sw%';")" "3"

expect_eq "running it again settles nothing new, because nothing is left to settle" \
  "$(sql "select released from release_due_trades();")" "0"
expect_eq "and no trade event was appended by the empty run" \
  "$(sql "select count(*) from trade_event where to_state='released' and trade_id in (select id from trade where buyer like 'sw%');")" "3"
expect_eq "the ledger still balances after moving three escrows" \
  "$(sql "select count(*) from check_balances();")" "0"
expect_eq "and every buyer's crypto arrived rather than being left in escrow" \
  "$(sql "select coalesce(sum(balance),0) from account where owner like 'sw%' and kind='available' and asset='USDT';")" "3000"

# A disputed-and-re-attested trade: only the LAST attest starts the window, so measuring from an older
# row would settle money the window was never open for.
sql "update trade_policy set claim_window = '15 minutes';" >/dev/null
fund_claim 4
T4=$(setup_claim 4)
tt $T4 open escrowed swe4 sw4
tt $T4 escrowed paid_claimed swc4 sw4
asops "select ops_transition($T4,'paid_claimed','paid_attested','swa4a','ops-1');" >/dev/null
sql "select transition_trade($T4,'paid_attested','disputed','swd4','sw4');" >/dev/null
asops "select ops_transition($T4,'disputed','resolved_release','swr4','ops-1');" >/dev/null
expect_eq "a resolved dispute is released by the resolution, not by the sweeper" \
  "$(sql "select state from trade where id=$T4;")" "resolved_release"
sql "select released from release_due_trades();" >/dev/null
expect_eq "and the sweeper does not touch it, because it is not paid_attested" \
  "$(sql "select state from trade where id=$T4;")" "resolved_release"

expect_fail "a trader cannot run the sweeper, because it moves money" \
  "$(astrader "select release_due_trades();")" "permission denied"

echo "== what a regulator asks first: is customer money kept apart from ours"
# brand/product.html says the first regulator is the Bank of Ghana BECAUSE customer funds must be
# segregated from our own. Until now nothing in the product could answer that. check_balances() proves the
# ledger adds up; it says nothing about WHOSE money it is holding.
#
# There is exactly one question worth asking of the ledger, and it is answerable: does value ever move
# between us and a customer without passing through a trade escrow? If every movement goes through
# trade:<id>:*_deposit, then a customer's deposits are ring-fenced by construction and not by policy.
# Every assertion here is a DELTA. This database is shared and survives between runs, so a bare count of
# customer escrow or of our float is reporting on whatever the last hundred tests left behind -- which is
# exactly what the first draft of this block did, and it produced numbers that were confidently wrong.
ESC_BEFORE=$(sql "select coalesce(customer_escrow,0) from segregation_report() where asset='GHS';")
FLOAT_BEFORE=$(sql "select coalesce(our_float,0) from segregation_report() where asset='GHS';")

# Both sides need cedi to post a vendor deposit. Without it the claim is refused for want of funds and the
# escrow never appears -- which is how the first draft of this block reported a deposit of zero and looked
# like a broken report rather than an underfunded fixture.
sql "select ledger_deposit('sg1','GHS',50000,'sg1-fund'); select ledger_deposit('sg2','GHS',50000,'sg2-fund');" >/dev/null
DT2=$(sql "select create_fiat_trade('sg1','sg2',null,null,'NGN',500000,0.006756756756756756,'vendor_vendor','GHS','GHS','NGN');")
expect_eq "a fresh trade holds no customer money yet" \
  "$(sql "select coalesce(customer_escrow,0) from segregation_report() where asset='GHS';")" "$ESC_BEFORE"

sql "select transition_trade($DT2,'open','paid_claimed','sg-claim','sg2');" >/dev/null
# 500,000 naira at 0.0067567 cedi per naira is 3,378 cedi, and a 10% deposit is 337 each side.
expect_eq "and the deposits are counted as customer escrow, ring-fenced from our float" \
  "$(sql "select coalesce(customer_escrow,0) from segregation_report() where asset='GHS';")" "$((ESC_BEFORE + 674))"
expect_eq "our own float is counted separately, and did not move" \
  "$(sql "select coalesce(our_float,0) from segregation_report() where asset='GHS';")" "$FLOAT_BEFORE"

expect_eq "no value has moved between us and a customer without passing through a trade escrow" \
  "$(sql "select coalesce(sum(commingled_transfers),0) from segregation_report();")" "0"

# The check has to be able to FAIL, or it is decoration. Move value straight from our float to a customer
# account, through the sanctioned ledger path rather than by forging rows, and the report must notice --
# because that is exactly what commingling looks like.
sql "insert into trader(email,name,phone,password) values ('sg-victim@e.com','Victim','0240000000','x') on conflict (email) do nothing;" >/dev/null
sql "select ledger_deposit('platform','GHS',50000,'sg-float');" >/dev/null
P=$(sql "select get_account('platform','available','GHS');"); V=$(sql "select get_account('sg-victim@e.com','available','GHS');")
sql "select post_tx('sg-commingle','commingled',null,jsonb_build_array(
       jsonb_build_object('account',$P,'asset','GHS','amount',-500),
       jsonb_build_object('account',$V,'asset','GHS','amount', 500)));" >/dev/null
expect_eq "and a commingled movement IS detected when one exists" \
  "$(sql "select coalesce(sum(commingled_transfers),0) from segregation_report();")" "1"

# Undoing it is ALSO commingling, and the ledger cannot know that one undoes the other. So this is an
# EVER-HAPPENED count, not a live alarm -- which is the right shape for an auditor's figure and the wrong
# shape for an alert. Asserting it "goes quiet again" is asserting something untrue, and the first draft of
# this block did exactly that.
sql "select post_tx('sg-uncommingle','commingled',null,jsonb_build_array(
       jsonb_build_object('account',$V,'asset','GHS','amount',-500),
       jsonb_build_object('account',$P,'asset','GHS','amount', 500)));" >/dev/null
expect_eq "and reversing it is counted too, because the ledger cannot tell a reversal from a fresh leak" \
  "$(sql "select coalesce(sum(commingled_transfers),0) from segregation_report();")" "2"

# Our own float meeting the outside world is NOT commingling, and counting it would have made the number
# meaningless: funding the float is platform <-> chain, and a bureau's float is supposed to do that. The
# first version of this query counted it and came back at 7 -- every float funding in the suite.
expect_eq "funding our own float from the bank is not counted as commingling" \
  "$(sql "select coalesce(sum(commingled_transfers),0) from segregation_report() where asset = 'GHS';")" "2"

# The honest part: what this CANNOT prove. The fiat leg is not posted to the ledger at all -- there is no
# crypto leg to post, and the deposit is the only thing escrowed -- so a trader's cedi and naira arrive
# off-system and are attested by operations. That is a gap in what we can show a regulator, and the report
# says so rather than leaving a reader to assume the question was fully answered.
expect_eq "the report counts the trades whose settlement the ledger cannot evidence" \
  "$(sql "select fiat_legs_unposted from segregation_report() limit 1;")" \
  "$(sql "select count(*) from trade where crypto_amount is null;")"
expect_eq "and it is every FIAT trade, which is the whole product as it stands" \
  "$(sql "select fiat_legs_unposted from segregation_report() limit 1;")" \
  "$(sql "select count(*) from trade where trade_type in ('vendor_vendor','platform_inventory') and crypto_amount is null;")"
expect_eq "while a trade WITH a crypto leg is not in that count, because the ledger does see it" \
  "$(sql "select count(*) from trade where crypto_amount is not null and trade_type = 'vendor_vendor';")" \
  "$(sql "select count(*) from trade where trade_type = 'vendor_vendor' and crypto_amount is not null;")"

expect_eq "the integrity page carries the segregation COUNTS" \
  "$(sql "select commingled_transfers is not null and fiat_legs_unposted is not null from integrity_report;")" "t"

# The MONEY must not be on there as a single figure. Summing minor units across currencies gave 228,838
# -- 199,838 pesewas plus 29,000 satoshi-scale units -- which is an amount in a currency nobody has.
expect_fail "no cross-currency total of customer money is offered as one number" \
  "$(sql "select customer_funds from integrity_report;")" "column.*does not exist"
expect_eq "because segregation is asked per currency, and this answers it" \
  "$(sql "select count(*) from segregation_report();")" \
  "$(sql "select count(distinct asset) from account where owner <> 'chain';")"

echo "== who am I, said by the database rather than by the caller"
# Every trader connects as the SAME login role, so the database cannot tell them apart. Everything a
# trader can see is therefore decided by an email the APPLICATION passes in -- and a trader holding a
# kudi_trader connection can simply pass somebody else's. That is not a theoretical worry: it is why the
# balance screen does not exist and why "Where you stand" deliberately shows no figures.
#
# 015 gives the database the identity instead. The application sets it once per request from the signed-in
# session; the functions below read it and take NO trader argument, because an argument would be the whole
# problem again.
# Asserted, not assumed. These two were failing silently into /dev/null, so every later line in this
# block was testing an identity that had never been created and reporting it as a problem with 015.
#
# The phone numbers are in their own high range because trader_phone is UNIQUE and the obvious choices are
# already taken by earlier tests -- which is what made the insert fail in the first place.
expect_eq "the two traders exist" \
  "$(sql "insert into trader(email,name,phone,password) values ('me@e.com','Me','02490000901','x') on conflict (email) do nothing;
          insert into trader(email,name,phone,password) values ('them@e.com','Them','02490000902','x') on conflict (email) do nothing;
          select count(*) from trader where email in ('me@e.com','them@e.com');")" "2"
expect_eq "and both are funded, differently, so one cannot stand in for the other" \
  "$(sql "select ledger_deposit('me@e.com','GHS',50000,'me-fund'); select ledger_deposit('them@e.com','GHS',90000,'them-fund');
          select (select balance from account where owner='me@e.com' and asset='GHS')
               || '|' || (select balance from account where owner='them@e.com' and asset='GHS');" | tail -1)" "50000|90000"

expect_eq "with no identity set, the database cannot answer" \
  "$(astrader "select coalesce(current_trader(),'<none>');")" "<none>"

# One request is ONE connection, so each of these runs in a single psql session. Running them as separate
# invocations looked like the identity was not working -- set_config is session-scoped, so it correctly
# disappears when the connection closes, and a real request never closes it half way through.
# tail -1 because kudi_sign_in returns void, so psql prints a blank line before the answer. Comparing the
# whole output to "me@e.com" fails on that blank line and looks like a broken identity.
expect_eq "after the application sets it, the database knows who this is" \
  "$(astrader "select kudi_sign_in('me@e.com'); select current_trader();" | tail -1)" "me@e.com"

# The point of the whole file: one trader's connection cannot ask about another, because there is no
# argument to ask with. Inventing one is the mistake, so these are refusals.
expect_fail "a balance function will not take a trader's name as an argument" \
  "$(astrader "select my_balances('them@e.com');")" "does not exist"
expect_fail "nor will the counts" \
  "$(astrader "select my_trade_counts('them@e.com');")" "does not exist"

expect_eq "and the balance it will answer with is its own" \
  "$(astrader "select kudi_sign_in('me@e.com'); select available from my_balances() where asset='GHS';" | tail -1)" "50000"
expect_eq "with nobody else's in it" \
  "$(astrader "select kudi_sign_in('me@e.com'); select count(*) from my_balances();" | tail -1)" "1"

# THE SAME CONNECTION AS SOMEBODY ELSE. If the identity were not being consulted, this would return the
# other trader's balance. It is the assertion that the mechanism works rather than merely being present.
expect_eq "and the same connection signed in as somebody else sees that somebody else" \
  "$(astrader "select kudi_sign_in('them@e.com'); select available from my_balances() where asset='GHS';" | tail -1)" "90000"

# Switching identity is the application switching sessions, which it must be able to do -- but the address
# is checked against the trader table, so an invented one gets nothing and leaves the previous in place.
expect_fail "an invented identity is refused" \
  "$(astrader "select kudi_sign_in('nobody@e.com');")" "no such trader"
# A refused sign-in raises and aborts the statement, so psql prints an error plus CONTEXT lines and the
# identity afterwards is best asserted on its own connection rather than salvaged from that output.
expect_eq "and a refused sign-in leaves a fresh connection signed out, not silently signed in" \
  "$(astrader "select coalesce(current_trader(),'<none>');")" "<none>"

expect_eq "signing out leaves nobody" \
  "$(astrader "select kudi_sign_in('me@e.com'); select kudi_sign_out(); select coalesce(current_trader(),'<none>');" | tail -1)" "<none>"

expect_fail "a trader still has no direct access to the account table" \
  "$(astrader "select balance from account;")" "permission denied"
expect_fail "nor may it read the ledger" \
  "$(astrader "select count(*) from ledger_tx;")" "permission denied"

expect_eq "and the counts behind 'Where you stand' are readable the same way" \
  "$(astrader "select kudi_sign_in('me@e.com'); select settled from my_trade_counts();" | tail -1)" "0"
expect_eq "and they count BOTH sides, because a trader can be the seller of one of these" \
  "$(astrader "select kudi_sign_in('them@e.com'); select total from my_trade_counts();" | tail -1)" \
  "$(sql "select count(*) from trade where buyer='them@e.com' or seller='them@e.com';")"

echo "== integrity"
expect_eq "cached balances equal sum of postings" "$(sql "select count(*) from check_balances();")" "0"
expect_eq "value is conserved (sum of all balances per asset = 0)" "$(sql "select count(*) from (select asset from account group by asset having sum(balance)<>0) x;")" "0"
expect_eq "hash chain verifies" "$(sql "select verify_chain();")" "t"
sql "alter table posting disable trigger posting_append_only; update posting set amount = amount + 1 where id = (select min(id) from posting);" >/dev/null
expect_eq "tampering as superuser is detected by the chain" "$(sql "select verify_chain();")" "f"

dropdb "$DB" >/dev/null 2>&1
echo; echo "passed: $PASS   failed: $FAIL"; [ "$FAIL" -eq 0 ]

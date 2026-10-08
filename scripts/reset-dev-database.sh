#!/usr/bin/env bash
# Rebuild the development database from the schema files, then seed it with the demo state.
#
# Usage: bash scripts/reset-dev-database.sh
#
# WHY THIS EXISTS
# kudi-core/001..008 are the source of truth for the schema. When one of them changes, an existing
# development database is simply out of date -- and the failure that produces is nasty: the application
# calls transition_trade with seven arguments, the database still only has the six-argument version, and
# the trader is told "the ledger refused that dispute" with no idea why. That is exactly what happened
# when the dispute narrative was added.
#
# So the development database is rebuilt rather than patched. Nothing here is precious: it is a scratch
# database with four demo traders and a handful of trades. Real data does not live in it.
#
# Traders and trades are created through the application's own models and services, not by INSERT, so
# passwords hash the way the application hashes them and trades are opened through the code path that
# enforces the rules.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB=kudi_dev
export PGHOST="${PGHOST:-/tmp}"
export PGPORT="${PGPORT:-5433}"
export PGUSER="${PGUSER:-kudi}"

say() { printf '\n== %s\n' "$1"; }

say "dropping and recreating $DB"
psql -q -d postgres -c "drop database if exists ${DB};"
psql -q -d postgres -c "create database ${DB};"
psql -q -d postgres -c "grant connect on database ${DB} to kudi_app_login, kudi_ops_login, kudi_pricing_login, kudi_trader_login"

say "loading the schema, 001 through 008"
for f in "$ROOT"/kudi-core/00*.sql; do
    printf '   %s\n' "$(basename "$f")"
    psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f "$f" >/dev/null
done

say "assets and grants"
psql -q -d "$DB" -c "insert into asset values ('GHS',2),('NGN',2),('USDT',6)"
psql -q -d "$DB" -c "grant select, insert, update on trader to kudi_app, kudi_ops"
psql -q -d "$DB" -c "grant usage, select on sequence trader_id_seq to kudi_app, kudi_ops"

say "seeding traders and trades through the application"
cd "$ROOT/app"
php artisan tinker --execute '
use App\Models\Trader;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// The demo traders. Same password for all of them so a tester can swap between accounts without
// signing out, and the password is a fixed dev-only string rather than anything a human chose.
//
// Provisioned on the ADMIN connection on purpose. The trader role is not permitted to insert traders --
// kudi-core/007 asserts that a trader cannot invent another trader -- so seeding through the trader
// model fails by design. Creating an account is an operator action; trading is a trader action.
foreach ([
    ["Ama Serwaa", "ama@example.com", "0240000001"],
    ["Kwesi Boateng", "kwesi@example.com", "0240000002"],
    ["Adwoa Mensah", "adwoa@example.com", "0240000003"],
] as [$name, $email, $phone]) {
    $admin = DB::connection("pgsql_admin");
    $admin->table("trader")->updateOrInsert(
        ["email" => $email],
        ["name" => $name, "phone" => $phone, "password" => Hash::make("kudi-dev-only"), "updated_at" => now(), "created_at" => now()]
    );
}

// A KudiMarket counterparty record. Rates are published as kudi_pricing, which is its own role: a
// trader must not be able to move the price even from inside the application.
DB::connection("pgsql_pricing")->selectOne("select set_rate(?, ?, ?, ?)", ["GHS", "NGN", 148.2, "pricing@kudimarket.com"]);
DB::connection("pgsql_pricing")->selectOne("select set_rate(?, ?, ?, ?)", ["NGN", "GHS", 1 / 148.2, "pricing@kudimarket.com"]);

// One open trade for Ama, so the trade screen and the claim button have something to show on a fresh
// database. Opened through TradeDesk, not by INSERT, so the rate comes from the card and the state
// machine sees the same call the application makes.
$ama = Trader::where("email", "ama@example.com")->firstOrFail();
Auth::guard("trader")->setUser($ama);
$open = DB::connection("pgsql_trader")->select("select id from trade where buyer = ? and state = ?", [$ama->email, "open"]);
if (! count($open)) {
    echo "   opened trade #" . app(TradeDesk::class)->openAgainstUs($ama->email, 75000, 148.2) . "\n";
}
'

say "checking the chains"
psql -q -t -A -F' | ' -d "$DB" -c \
  "select 'ledger '||ledger_chain_ok, 'trade '||trade_chain_ok, 'rate '||rate_chain_ok, 'unanchored '||unanchored_trades from integrity_report;"

say "done"
cat <<'TEXT'
   trader app   http://127.0.0.1:8101/sign-in
   ama@example.com / kudi-dev-only
   ops console  http://127.0.0.1:8100/admin/ledger-integrity
   ops@kudimarket.com / kudi-dev-only
TEXT
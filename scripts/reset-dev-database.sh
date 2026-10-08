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

say "Laravel migrations"

cd "$ROOT/app"
# kudi-core is the FINANCIAL schema. The application has its own tables -- users, sessions, cache, the
# Filament tables -- and those come from Laravel migrations. Loading kudi-core alone leaves the ops console
# with no users table at all, which is exactly what happened: the script rebuilt the database from the core
# files and silently threw away every application table with it.
#
# Migrations run AFTER kudi-core because the grant migration grants to the roles that the core files create.
# As the schema owner, not as the application. The app connects as kudi_app_login, which is right: it has
# no CREATE on schema public, so the application cannot alter its own schema. Creating tables is an
# operator action. DB_USERNAME is overridden for this one command only.
DB_USERNAME="${PGUSER}" DB_PASSWORD="${PGPASSWORD:-}" php artisan migrate --force --no-interaction

say "seeding traders and trades through the application"
php artisan tinker --execute '
use App\Models\Trader;
use App\Models\User;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// The operations staff account for the console. NOT optional: attestation and release both happen in the
// console, so a database without an ops user cannot complete a single trade, and the console just bounces
// you to a login that cannot succeed. It was missing here the first time this script ran, and the symptom
// was an ops login that silently did nothing.
// (No apostrophes in this comment: the whole block sits inside a bash single-quoted string, and one of
// them ends the string early. That happened, and it produced "line 57: //: is a directory".)
// Assigned one attribute at a time, not through an array. The User model fillable list is
// name/email/password only, so email_verified_at passed in a mass-assignment array is dropped SILENTLY --
// which is how every staff account here was created unverified and could not reach the console.
$ops = \App\Models\User::firstOrNew(["email" => "ops@kudimarket.com"]);
$ops->name = "KudiMarket Operations";
$ops->password = Hash::make("kudi-dev-only");
$ops->email_verified_at = now();
$ops->save();

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
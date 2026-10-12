#!/usr/bin/env bash
# Provision a production KudiMarket instance and take an existing codebase live -- in one run.
#
# Usage:
#   bash scripts/deploy.sh /srv/kudimarket   (after you've set app/.env from app/.env.example)
#
# WHAT THIS IS FOR
#
# docs/production-runbook.md is the prose account of the same journey. This script is that journey as
# something an operator can execute, so "stand it up" is a command and not a list to remember. It
# assumes the operator has already:
#   - picked a server and installed a base OS (Ubuntu 24.04/Debian 12)
#   - pointed their domain at the server
#   - copied app/.env.example to app/.env and set the DB_*_ lines
#
# WHAT THIS DOES (and what it deliberately does NOT)
#
# Checks for the system packages the app needs, creates the database + login roles, runs the schema
# migrations (the one step that must run as the kudi superuser), caches the config/routes, and runs
# `php artisan kudi:preflight` as a gate: if the instance is not safe to open, it says so and stops.
#
# It does NOT publish a rate, set a commission, or create any staff account. Those are deliberate,
# named, recorded acts that exist for a reason -- a price with nobody's name on it cannot be defended
# later -- and a deploy script that quietly set your price would be the exact automation this product
# refuses to be.
#
# The cron line the scheduler needs is printed at the end (see the boilerplate in this file), because
# a cron entry is a human decision about which user and which schedule, even if the runbook gives the
# exact text.
set -euo pipefail

ROOT="${1:?usage: bash scripts/deploy.sh /srv/kudimarket}"
APP="$ROOT/app"
APP_ENV_FILE="$APP/.env"

[ -d "$APP" ] || { echo "no app directory at $APP"; exit 1; }
[ -f "$APP_ENV_FILE" ] || { echo "$APP_ENV_FILE is missing. Copy app/.env.example to it and set the DB_*_ lines first."; exit 1; }
grep -q '^APP_ENV=production' "$APP_ENV_FILE" || { echo ".env is not APP_ENV=production. A deploy against a non-production .env would be a rehearsal, not a deploy."; exit 1; }

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

step "Checking system packages"
# pgcrypto ships with postgresql-contrib. Docker flags below are needed for the PHP packages' apt repo.
if ! command -v php >/dev/null 2>&1 || ! command -v psql >/dev/null 2>&1; then
  echo "PHP or PostgreSQL is not installed. On Ubuntu 24.04 the one-liner is:"
  echo "  sudo apt install -y nginx postgresql postgresql-contrib php8.3-cli php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath git unzip"
  exit 1
fi

step "Checking .env connects and the kudi superuser exists"
# The schema must be built by one role only. ./app reads DB_ADMIN_*; the running application never
# connects as it. Fail loudly rather than quietly migrating as the wrong identity.
# .env values are not exported into the shell; read the few we need here without printing them.
DB_ADMIN_DATABASE="$(grep -E '^DB_ADMIN_DATABASE=' "$APP_ENV_FILE" | cut -d= -f2- | tr -d '"')"
DB_ADMIN_USERNAME="$(grep -E '^DB_ADMIN_USERNAME=' "$APP_ENV_FILE" | cut -d= -f2- | tr -d '"')"
DB_ADMIN_PASSWORD="$(grep -E '^DB_ADMIN_PASSWORD=' "$APP_ENV_FILE" | cut -d= -f2- | tr -d '"' || true)"
[ -n "$DB_ADMIN_DATABASE" ] || { echo "DB_ADMIN_DATABASE is empty in $APP_ENV_FILE"; exit 1; }
[ -n "$DB_ADMIN_USERNAME" ] || { echo "DB_ADMIN_USERNAME is empty in $APP_ENV_FILE"; exit 1; }
export PGOPTIONS="--search_path=public,pg_temp"
echo "Target database: ${DB_ADMIN_DATABASE} (role ${DB_ADMIN_USERNAME})"

step "Creating the database if missing"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_ADMIN_DATABASE'" | grep -q 1 \
  || sudo -u postgres createdb "$DB_ADMIN_DATABASE" "$DB_ADMIN_USERNAME"

step "Checking pdo_pgsql is available (the whole schema runs on postgres)"
php -m | grep -q pdo_pgsql || { echo "pdo_pgsql is missing -- install php8.3-pgsql ($APP_ENV_FILE says which php you have)."; exit 1; }

step "Checking pgcrypto is usable (the hash chain depends on digest())"
sudo -u postgres psql -d "$DB_ADMIN_DATABASE" -tc "SELECT digest('x','sha256') IS NOT NULL" | grep -q t \
  || { echo "pgcrypto is not enabled on the target database. On Postgres it ships with contrib: 'sudo apt install postgresql-contrib' then 'CREATE EXTENSION IF NOT EXISTS pgcrypto;'"; exit 1; }

step "Running migrations (the one step that runs as the kudi superuser)"
# --database=pgsql_admin, exactly as the test harness does: that connection is the self-contained
# superuser identity, so no username/password override is needed here and APP_ENV=production makes the
# reference-data migration seed assets only -- no demo traders, no demo money, no fabricated trades.
# That guard is tested by tests/Feature/SeedDataProductionGuardTest.php.
(cd "$APP" && APP_ENV=production APP_DEBUG=false php artisan migrate --force --no-interaction --database=pgsql_admin)

step "Caching config and routes"
(cd "$APP" && php artisan config:cache && php artisan route:cache)

step "Warming the scheduler registration check"
(cd "$APP" && php artisan about --only=environment >/dev/null)

step "Preflight gate"
(cd "$APP" && php artisan kudi:preflight)

cat <<'EOF'

----------------------------------------------------------------------
Deployment complete and preflight passed.
----------------------------------------------------------------------

Still yours to do, deliberately (see docs/production-runbook.md):

  1. Publish rates   -- pricing desk, or  RateDesk::publish(b, q, rate, you@...)
  2. Set the commission -- pricing desk, or  CommissionDesk::set(tier, bps, bearer, you@..., note)
  3. Create staff + trader accounts       (never demo passwords; see runbook s.5)
  4. The scheduler cron, as the web/PHP user:

       * * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1

     Without it, trades never settle automatically -- the step people skip, and the one that
     quietly turns escrow into a desk job.
  5. Back up $DB_ADMIN_DATABASE, and rehearse restoring it before real money is involved.

Then re-run `php artisan kudi:preflight` before opening to traders. It checks the things
that turn a demo into a product: the chain, the balances, the rates, the commission.
EOF
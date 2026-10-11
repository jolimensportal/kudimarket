#!/usr/bin/env bash
# Bring up the PostgreSQL cluster KudiMarket expects, with the roles the schema grants to.
#
# Usage: bash scripts/bootstrap-pg.sh
#
# WHY THIS EXISTS
#
# kudi-core/001..015 create the GROUP roles themselves (kudi_app, kudi_ops, kudi_pricing, kudi_trader,
# kudi_chain -- all `if not exists then create`). What they cannot create are the LOGIN roles, because
# those need a superuser, and there is no superuser to run the file as. So `kudi` plus the four
# `*_login` roles were created by hand, once, and never scripted.
#
# That is fine right up until the cluster is not on the machine any more. This session began with a
# 5433 that would not answer, and a cluster whose only role list was empty -- so nothing could connect,
# run_tests.sh silently hung on a password prompt for ten minutes, and the failure presented as "the
# tests are slow". It is not that the tests are slow. The database is gone.
#
# Safe to run repeatedly. It only creates what is missing and never drops or alters anything that is
# already there.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/app/.env"
PGDATA="${PGDATA:-$HOME/.local/pgdata}"
export PGHOST="${PGHOST:-/tmp}"
export PGPORT="${PGPORT:-5433}"
export PGUSER="${PGUSER:-kudi}"

# Postgres.app keeps its binaries under a versioned path; fall back to whatever is on PATH.
PGBIN="${PGBIN:-/Applications/Postgres.app/Contents/Versions/latest/bin}"
[ -x "$PGBIN/pg_ctl" ] || PGBIN="$(dirname "$(command -v pg_ctl)")"

say() { printf '\n== %s\n' "$1"; }

say "cluster at $PGDATA on port $PGPORT"
if ! "$PGBIN/pg_ctl" -D "$PGDATA" status >/dev/null 2>&1; then
  "$PGBIN/pg_ctl" -D "$PGDATA" -o "-p $PGPORT" -l /tmp/kudi-pg.log start >/dev/null
  sleep 4
fi
say "listening"

# The superuser cannot be created by connecting as the superuser, so this one statement goes through
# postgres' single-user backend, which is the only context where CREATE ROLE can bootstrap a cluster.
if ! psql -q -d postgres -tAc "select 1 from pg_roles where rolname='$PGUSER'" >/dev/null 2>&1; then
  say "creating superuser $PGUSER"
  "$PGBIN/pg_ctl" -D "$PGDATA" -m immediate stop >/dev/null 2>&1 || true
  sleep 2
  printf "create role %s superuser login;\n" "$PGUSER" \
    | "$PGBIN/postgres" --single -D "$PGDATA" postgres >/dev/null 2>&1
  "$PGBIN/pg_ctl" -D "$PGDATA" -o "-p $PGPORT" -l /tmp/kudi-pg.log start >/dev/null
  sleep 4
fi

say "login roles"
# Passwords are read from .env and never printed: this script has no business putting a credential on a
# terminal, and a password echoed into a scrollback is a leaked one.
while IFS=' ' read -r user_var pass_var; do
  u=$(grep "^${user_var}=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r"')
  p=$(grep "^${pass_var}=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r"')
  [ -n "$u" ] || continue
  psql -q -d postgres -tAc "select 1 from pg_roles where rolname='$u'" >/dev/null 2>&1 && {
    printf '   %-22s already present\n' "$u"; continue
  }
  # create role cannot take bind parameters, so the identifier is quoted with format's %I and the
  # password with %L. Interpolation would put a password from .env straight into SQL text.
  psql -q -d postgres -tAc \
    "select format('create role %I login password %L', '$u', '$p') where not exists (select from pg_roles where rolname='$u')" \
    | psql -q -d postgres >/dev/null
  printf '   %-22s created\n' "$u"
done <<'ROLES'
DB_USERNAME DB_PASSWORD
DB_OPS_USERNAME DB_OPS_PASSWORD
DB_PRICING_USERNAME DB_PRICING_PASSWORD
DB_TRADER_USERNAME DB_TRADER_PASSWORD
ROLES

say "reachable as $PGUSER"
psql -q -d postgres -tAc "select '   ' || current_user || ' on ' || version()" | head -1
say "done -- now: bash scripts/test.sh"
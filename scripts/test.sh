#!/usr/bin/env bash
# Run the whole test suite in the one order that works, because the order is load-bearing.
#
# Usage: bash scripts/test.sh
#
# WHY THIS EXISTS
#
# The PHP suite and the SQL suite are not independent, and nothing said so until it cost me an hour.
#
# kudi-core/run_tests.sh opens by DROPPING and recreating kudi_test, then loading 001..015 straight into
# it. The PHP suite does not: it connects to the same kudi_test and expects that schema to already be
# there. So:
#
#   php artisan test                    -> fails with "function set_rate(...) does not exist"
#   php artisan test                    -> leaves ~250 trades behind
#   php artisan test                    -> ReleaseDueTradesTest fails, trades stuck at 'open'
#
# That third failure is the dangerous one. It is not a real regression: the sweeper is querying a
# database full of other tests' fixtures, finds nothing it recognises as due, and leaves the trade at
# 'open'. I spent a cycle chasing it as though my change had broken the release path. It had not.
# kudi_test is PERSISTENT, and every direct `php artisan test` grows it.
#
# So the SQL suite runs first and always. It rebuilds the database from the schema files, which is both
# what the PHP suite needs and what stops the accumulation. If you only want the PHP suite and the
# database already exists, run run_tests.sh first anyway -- it is the only thing that guarantees the
# schema matches the files on disk.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PGHOST="${PGHOST:-/tmp}"
export PGPORT="${PGPORT:-5433}"
export PGUSER="${PGUSER:-kudi}"

say() { printf '\n== %s\n' "$1"; }

say "SQL: kudi-core (rebuilds kudi_test from kudi-core/*.sql)"
cd "$ROOT/kudi-core"
bash run_tests.sh

# Only worth running if the SQL suite passed, and set -e means we would already have stopped.
say "PHP: application (runs against the kudi_test the SQL suite just rebuilt)"
cd "$ROOT/app"
php artisan test

say "design lock"
cd "$ROOT"
npm run lint:design

say "done"
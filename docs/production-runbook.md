# Production runbook: standing KudiMarket up

**Status: this has never been done. Every database in the repository currently points at a local
Postgres socket (`/tmp`) to a database called `kudi_dev`, and `APP_ENV=local`. There is no production
server and no deployment. The domain `kudimarket.com` is owned (purchased 13 Oct 2026) but points
nowhere — no DNS, no TLS. This document is a rehearsal, not a record.**

This is deliberately written to be followed by a competent person who is not the author. Where it
names a file or a setting, that is the real file or the real setting, checked while writing it.

---

## 0. Before you start — the honest gate

This runbook gets a **working, rehearsed instance** serving real screens and a real ledger. It does
**not** mean "take customers' money". Those are different things and the second has a prerequisite
that engineering cannot supply:

> **Before KudiMarket takes real customer funds, the licence position must be settled.** A trader
> selling cedi to KudiMarket is KudiMarket buying for its own account. The Bank of Ghana FX Broker
> conditions forbid a broker doing exactly that (they may not take the other side, nor trade for own
> account), while a licensed **dealer** under Act 723 s.3(4)(a) may buy and sell for own account. Which
> one we are decides what we may legally do with this software. See `brand/REGULATORY.md` §5b. This
> needs Ghanaian counsel, not a code change.

It is entirely reasonable to stand up the instance, run it in front of nobody, and rehearse every
step below. That is what this runbook is for.

---

## 1. What you need

- A Linux server (any provider — this document names none, deliberately). Ubuntu 24.04 or Debian 12
  are the paths of least resistance.
- PostgreSQL 15 or 16, running on the same server or a managed instance you control.
- A domain you control, pointed at the server's IP. `kudimarket.com` is owned as of 13 Oct 2026 but
  has no DNS yet; buy the defensive set listed in `brand/BRAND.md` §4 too, and point an A record for
  `kudimarket.com` (and `www` if you serve it) at the server's address before running certbot.
- This repository.

---

## 2. Server preparation

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx postgresql-16 php8.3-cli php8.3-fpm php8.3-pgsql \
                    php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath git unzip
```

PostgreSQL extensions — **`pgcrypto` is not optional.** The entire tamper-evidence story is
`digest(...)` over a hash chain, used in ten of the `kudi-core` files. You do **not** need to install
it by hand: `kudi-core/001_core.sql:12` runs `create extension if not exists pgcrypto` as part of the
schema, and the migration runs as the `kudi` superuser, so it will create itself. But be aware:

- If your Postgres instance is **managed** (RDS, Cloud SQL, Neon), the bootstrap user may not be
  allowed to `create extension`. Enable `pgcrypto` on the instance *before* migrating, or the very
  first migration will fail on that line and every hash-chained insert after it will error.
- Verify with `select digest('x','sha256');` — a permission error there means the extension is present
  but your app roles cannot use it, which is a different failure from "not installed".

The app also needs these PHP extensions: `pdo_pgsql` (the only database), `mbstring`, `xml`, `curl`,
`bcmath`, plus the usual `tokenizer`/`ctype`/`json`/`openssl` that ship with the CLI package.
`bcmath` is not used for money (money is integer minor units throughout) but is harmless to have.

---

## 3. The database and its roles

The application **never** connects as a superuser. The schema defines five distinct roles, and the
separation is enforced in the database, not in a policy document (`kudi-core/006`, `007`):

| Role | May |
|---|---|
| `kudi` (superuser) | build the schema, mint starting balance. **Migrations and seeding only.** |
| `kudi_pricing` | set our rate, set our commission |
| `kudi_app` | read |
| `kudi_ops` | attest, resolve disputes, run the release sweep |
| `kudi_trader` | open trades, claim, dispute |
| `kudi_chain` | mint balance (the chain watcher) |

Create the database and let the migrations build the roles — they are created by the schema, so do
not hand-roll them:

```sql
create database kudi_prod;
```

Build the schema by running the migrations against that database as `kudi` (see §4). The three lists
that decide which SQL files load — `kudi-core/run_tests.sh`, the migration
`database/migrations/2026_01_01_000100_create_kudi_core.php`, and
`tests/Support/BuildsLedgerDatabase.php` — must all agree, and there is a test that fails if they
drift. Do not add a file to one and not the others.

---

## 4. Get the code, and the one command that stands it up

The whole journey in §5-§10 below is also scripted. `scripts/deploy.sh` runs it as a single
command — it checks the system packages, creates the database if missing, verifies `pgcrypto`,
migrates, caches config, and gates on the preflight command:

```bash
git clone <your-remote> /srv/kudimarket
cd /srv/kudimarket/app
composer install --no-dev --optimize-autoloader
bash ../scripts/deploy.sh /srv/kudimarket
```

Or, step by step by hand:

```bash
cd /srv/kudimarket/app
php artisan key:generate --force
APP_ENV=production APP_DEBUG=false php artisan migrate --force --database=pgsql_admin
```

The migration runs as the `pgsql_admin` connection (`kudi`, a cluster superuser). That is the intended
shape: schema creation is a superuser action; the running application is not. It is not a reason to
run the *app* as superuser, and it must not become one.

> **Do not seed demo data by hand in production.** The `seed_reference_data` migration creates demo
> traders with funded balances, a fabricated rate, and four trades including a *disputed* one —
> "so the console has something real to show". A fresh production database needs none of that. The
> migration is guarded: under `APP_ENV=production` it seeds only the `asset` rows (the currencies
> themselves) and returns. That guard is pinned by `tests/Feature/SeedDataProductionGuardTest.php`.
> Nothing in production should ever be told "here is a dispute to read" about a trade that never
> happened.

Verify the schema landed, including the extension and the roles:

```sql
-- in kudi_prod
select count(*) from pg_proc where proname = 'commission_quote';   -- expect 1
select rolname from pg_roles where rolname like 'kudi%';           -- expect the five + logins
```

Then run the readiness gate once the environment is set (§5) — it checks the hash chain, the
balances, the published rates and the set commission:

```bash
php artisan kudi:preflight
```

`kudi:preflight` is the checklist at the end of this document (§10) as executable checks. It fails
non-zero when anything is not ready, so a deploy or a human gate can rely on its exit code. On a
fresh instance it will correctly fail on "a rate is published" and "a commission is set" until you do
§6 — which is its job, not a bug.

---

## 5. Environment

Copy `.env.example` to `.env` and set, at minimum:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kudimarket.com

DB_ADMIN_HOST=/var/run/postgresql        ; DB_ADMIN_DATABASE=kudi_prod ; DB_ADMIN_USERNAME=kudi
DB_OPS_HOST=/var/run/postgresql         ; DB_OPS_DATABASE=kudi_prod   ; DB_OPS_USERNAME=kudi_ops_login
DB_TRADER_HOST=/var/run/postgresql      ; DB_TRADER_DATABASE=kudi_prod ; DB_TRADER_USERNAME=kudi_trader_login
DB_PRICING_HOST=/var/run/postgresql     ; DB_PRICING_DATABASE=kudi_prod ; DB_PRICING_USERNAME=kudi_pricing_login
```

The committed `.env.example` is a real production template, not Laravel's stock `sqlite` default. It
declares the four role-scoped Postgres connections the application actually works through, plus the
default `pgsql` connection (which is where the framework's own `users`/`sessions`/`cache` tables live,
and which runs as `kudi_app_login` — never as the superuser). Do not copy Laravel's upstream example
over it; that has no `DB_OPS_*`/`DB_TRADER_*`/`DB_PRICING_*`/`DB_ADMIN_*` lines at all, and the app
would quietly point at a database that does not exist.

Leave `DB_OPS_PASSWORD`, `DB_TRADER_PASSWORD`, `DB_PRICING_PASSWORD` empty to use peer/socket auth for
local-only Postgres, or set strong per-role passwords if Postgres is not on the same host.

> **Use Redis or a real session/cache store, or at minimum be deliberate.** The dev `.env` uses
> `SESSION_DRIVER=file` and `CACHE_STORE=database`. File sessions do not survive a deploy and do not
> work across more than one web server. `database` for cache is acceptable at this scale. There is no
> queue worker to run — the application dispatches no queued jobs.

> **NEVER run `scripts/reset-dev-database.sh` against production.** It creates accounts with the
> password `kudi-dev-only`, seeds demo traders, and publishes a rate as `1 / 148.2` — a PHP float
> division, the exact bug class this ledger has been bitten by twice. It is a demo reset script.

---

## 6. Set the two prices that must exist before anything works

There is no default pricing. Both are set through the pricing role, and both are append-only and
recorded:

```php
// publish our rate (RateDesk::publish / the pricing desk UI)
php artisan tinker --execute 'app(App\Services\RateDesk::class)
    ->publish("GHS","NGN","148.2","you@kudimarket.com");'
php artisan tinker --execute 'app(App\Services\RateDesk::class)
    ->publish("NGN","GHS","0.006756756756756756","you@kudimarket.com");'

// set the commission (CommissionDesk::set / the pricing desk UI)
php artisan tinker --execute 'app(App\Services\CommissionDesk::class)
    ->set("platform_inventory", 100, "buyer", "you@kudimarket.com", "launch commission");'
```

**Read `brand/REGULATORY.md` before you set a real commission.** The BoG broker condition
(viii) requires a broker to "operate on commission basis" and not hide commission in the rate — which
is why this product charges an explicit, itemised commission rather than a spread. Which regime you
are licensed under determines whether you may charge a spread at all.

**The commission is changeable at any time, by you, in the product, from the pricing desk
(`/pricing/commissions`).** It applies to trades opened *after* the change; every trade freezes its
own commission the moment it is opened. Every change is appended to a hash-chained log with your
name, the time, and a reason. You will never need a deploy or an engineer to change your price.

---

## 7. Web server and TLS

Point nginx at `public/`, run PHP-FPM, terminate TLS with certbot. Standard Laravel; nothing in this
app is unusual here. Two things the app *does* care about:

- `APP_DEBUG=false` and `APP_ENV=production` are not optional in a `.env` a human can reach.
- `storage/` and `bootstrap/cache/` must be writable by the PHP user.

---

## 8. The scheduler — the step people forget, and the one that breaks the product

```cron
* * * * * cd /srv/kudimarket/app && php artisan schedule:run >> /dev/null 2>&1
```

This runs `trades:release-due` every minute (`routes/console.php`), which calls
`release_due_trades()` in `kudi-core/013_release_sweeper.sql`. It is what makes settlement
**automatic**.

To be precise, because the distinction matters when something goes wrong: a trade in
`paid_attested` can also be released by hand — operations has a Release button in the console
(`app/Filament/Resources/Trades/TradeResource.php:99`), and it is likewise refused before the claim
window closes. So an absent cron does not lose anybody's money; it means **every settlement waits for
a human to notice it**, which defeats the promise the product is sold on ("your money arrives without
you chasing anyone") and quietly becomes an ops-desk job nobody signed up for.

If you skip every other step in this document, skip this one and you have shipped a product that only
works while somebody is watching a screen.

---

## 9. Backups — non-negotiable for this system

This ledger is **append-only and hash-chained**. `verify_chain()` and the ops integrity page
(`/admin/ledger-integrity`) exist to prove nobody edited history. That guarantee is worth nothing if
the database itself is not recoverable. Back up `kudi_prod` on a schedule, and test that you can
restore it. A backup you have never restored is not a backup.

---

## 10. Rehearsal checklist

Before you would ever point a real trader at this, run these on the rehearsal instance. The machine
checks are the same thing `php artisan kudi:preflight` executes, so the operator's step and the
product's gate are not two different lists that can drift:

```bash
# the executable checklist: chain, balances, rates, commission, scheduler, env.
# Fails non-zero when anything is not ready.
php artisan kudi:preflight
```

- [ ] `php artisan kudi:preflight` passes clean.
- [ ] Sign in as a trader; open a **buy** against KudiMarket. Check the fee line and the exact total.
- [ ] Pay, claim, and have operations confirm (`/admin`). Watch the trade reach `paid_attested`.
- [ ] Wait out the claim window (15 minutes on our own book) and watch the cron settle it to
      `released`. **This is the step that proves the scheduler is running.**
- [ ] Repeat for a **sell** to KudiMarket, confirming the trader still cannot self-attest.
- [ ] Check `/admin/ledger-integrity` reports clean chains and zero balance mismatches.
- [ ] Change the commission on the pricing desk; open a new trade; confirm the new rate applies and an
      older trade kept the old one.
- [ ] Raise a dispute on a held trade and confirm it can still be raised (the claim window gates
      *release*, never a complaint).

---

## What this document deliberately does not cover

- Choosing a hosting provider. That decision is yours and it is the one thing here with no default.
- TLS specifics beyond "terminate it".
- The licence question. That is counsel, and it gates real money — not this instance.
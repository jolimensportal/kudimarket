# Trader App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the trader-facing web app for both sides of one trade — a cedi buyer and a naira seller on one board, with KudiMarket as the counterparty in the middle — from the board screen through payment attestation to a dispute and its resolution, with feature tests on every path that touches money.

**Architecture:** Server-rendered Blade, because the market is on budget Android handsets over poor mobile data and a SPA would be the wrong trade. The browser talks to Laravel; Laravel talks to Postgres through stored procedures it cannot bypass. The trader app runs on a **new `kudi_trader` role** that may open a trade, attest its own payment and raise a dispute, but may never resolve one, publish a rate, mint a deposit, or write a ledger row. Every money path is a stored procedure call; the application holds no balances and no state transitions of its own.

**Tech Stack:** Laravel 13.35, PHP 8.3, PostgreSQL 18.4 (existing cluster on port 5433), Blade + Tailwind, Pest/PHPUnit feature tests against a scratch Postgres database, `kudi-core/run_tests.sh` (177 tests) as the SQL-level arbiter.

---

## Verified facts this plan depends on

Checked on 7 Oct 2026, not assumed:

| Fact | How it was verified |
|---|---|
| `kudi_app` cannot INSERT into `trade`/`posting` or UPDATE `account` | `has_table_privilege` → false, false, false |
| `kudi_app` holds EXECUTE on `create_trade`, `create_fiat_trade`, `transition_trade` | `has_function_privilege` → true |
| `kudi_app` does **not** hold `ops_transition` or `set_rate` | false, false |
| `kudi_pricing` holds only `set_rate` | true on `set_rate`, false on everything else |
| Superuser `kudi` has CREATEDB, so tests can build a scratch database | `pg_roles.rolcreatedb` → true |
| The board reads real data: GHS→NGN 148.20, both directions | `rate_board` via `kudi_ops_login` |
| Filament login inputs are `id="form.email"` / `id="form.password"` with **no `name` attribute** | `document.querySelectorAll('input')` |
| Tests currently default to `DB_CONNECTION=sqlite` in-memory | `phpunit.xml` — wrong database entirely for this app |

**The sqlite default is the trap this plan exists to avoid.** `phpunit.xml` sets `DB_CONNECTION=sqlite`
and `DB_DATABASE=:memory:`. A feature test written against that default would connect to an empty
SQLite file and pass while proving nothing about the ledger, because none of the stored procedures,
roles or triggers exist there. Task 1 fixes this before a single test is written.

---

## File Structure

**Postgres core** (`~/Desktop/kudimarket/kudi-core/`)

| File | Responsibility |
|---|---|
| `007_trader_role.sql` | The `kudi_trader` group role and what it may call. No new tables. |
| `run_tests.sh` | Add trader-permission tests beside the existing 177. |

**Application** (`~/Desktop/kudimarket/app/`)

| File | Responsibility |
|---|---|
| `app/Services/RateBoard.php` | Reads `rate_board` and formats rates for display. Read-only. |
| `app/Services/TradeDesk.php` | Every write the trader app makes: open a trade, claim, attest, dispute. One method per state change, each a single stored-procedure call. |
| `app/Http/Controllers/BoardController.php` | The board screen. |
| `app/Http/Controllers/TradeController.php` | One trade's screens. |
| `app/Http/Controllers/SettlementController.php` | Claim and attest, both POST. |
| `app/Http/Controllers/DisputeController.php` | Raising a dispute, POST only. |
| `app/Models/Trader.php` | A trader. `users` stays for the ops console; traders are our own people. |
| `resources/views/board/index.blade.php` | The board: our rate plus vendor offers. |
| `resources/views/trade/show.blade.php` | One trade, with its state and its claim window. |
| `resources/views/trade/settle.blade.php` | Claim, or attest. |
| `resources/views/trade/dispute.blade.php` | Raise a dispute. |
| `tests/Feature/...` | Feature tests for each of the above. |
| `tests/TestCase.php` | Extended so tests run against a scratch Postgres database, not sqlite. |

**Deliberately not built:** native app, push notifications, chat, KYC, live websockets. Each is a
later slice and naming them here stops them creeping into this one.

---

## Task 1: Make the test suite talk to Postgres at all

The app currently has two example tests and a sqlite default. Nothing about the ledger can be tested
until that changes.

**Files:**
- Modify: `phpunit.xml`
- Modify: `tests/TestCase.php`
- Create: `tests/Support/BuildsLedgerDatabase.php`

- [ ] **Step 1: Write a test that fails because the database is the wrong one**

Create `tests/Feature/LedgerConnectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The suite must run against the same Postgres the product runs against. Under the default sqlite
 * configuration none of the stored procedures, roles or triggers exist, so a test can pass while
 * proving nothing about money. These two assertions are the canary for that whole class of lie.
 */
class LedgerConnectionTest extends TestCase
{
    public function test_the_ledger_functions_exist_in_the_test_database(): void
    {
        $this->assertSame('t', $this->sql('select verify_chain()'));
    }

    public function test_the_app_role_cannot_write_a_balance_directly(): void
    {
        $out = $this->sql("update account set balance = 999999 where id = (select min(id) from account);");

        $this->assertStringContainsString('permission denied', $out);
    }

    private function sql(string $statement): string
    {
        return trim(\DB::connection('pgsql')->selectOne($statement)->sql ?? $this->raw($statement));
    }

    private function raw(string $statement): string
    {
        $rows = \DB::connection('pgsql')->select($statement);

        return (string) ($rows[0]->{'?column?'} ?? '');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LedgerConnectionTest`

Expected: FAIL. `verify_chain()` does not exist in an in-memory SQLite database.

- [ ] **Step 3: Point the suite at a scratch Postgres database**

Replace the `DB_CONNECTION` and `DB_DATABASE` lines in `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_SOCKET" value="/tmp"/>
<env name="DB_PORT" value="5433"/>
<env name="DB_DATABASE" value="kudi_test"/>
<env name="DB_USERNAME" value="kudi_app_login"/>
<env name="DB_OPS_DATABASE" value="kudi_test"/>
<env name="DB_OPS_USERNAME" value="kudi_ops_login"/>
<env name="DB_OPS_SOCKET" value="/tmp"/>
<env name="DB_OPS_PORT" value="5433"/>
```

Delete `DB_URL` if present — it overrides the individual settings and silently wins.

- [ ] **Step 4: Build the scratch database once, before the suite runs**

Create `tests/Support/BuildsLedgerDatabase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Builds kudi_test from the SQL in kudi-core, exactly as the dev database was built.
 *
 * The suite creates a real database with the real schema rather than mocking the ledger. Mocking a
 * double-entry ledger is how you end up with tests that pass while the ledger is wrong.
 */
trait BuildsLedgerDatabase
{
    private static bool $built = false;

    protected function buildLedgerDatabase(): void
    {
        if (self::$built) {
            return;
        }

        $host = config('database.connections.pgsql.host') ?: '/tmp';
        $port = config('database.connections.pgsql.port') ?: 5433;
        $admin = config('database.connections.pgsql_admin');

        $core = base_path('../kudi-core');

        if (! is_dir($core)) {
            throw new RuntimeException("kudi-core not found at {$core}");
        }

        // Roles are cluster-wide, so they must not be created by every test run.
        $this->psql($admin, 'postgres', 'select 1', allowFailure: true);

        $this->psql($admin, 'postgres', 'drop database if exists kudi_test');
        $this->psql($admin, 'postgres', 'create database kudi_test');

        foreach (['001_core.sql', '002_trade_types.sql', '003_fiat_leg.sql', '004_state_chain.sql', '005_creation_anchor.sql', '006_rate_card.sql'] as $file) {
            $sql = file_get_contents("{$core}/{$file}");

            if ($sql === false) {
                throw new RuntimeException("cannot read {$file}");
            }

            $this->psql($admin, 'kudi_test', $sql);
        }

        $this->psql($admin, 'kudi_test', "insert into asset values ('GHS',2),('NGN',2),('USDT',6)");
        $this->psql($admin, 'kudi_test', "update trade_policy set claim_window = '0 seconds'");

        self::$built = true;
    }

    private function psql(array $connection, string $database, string $sql, bool $allowFailure = false): void
    {
        $command = sprintf(
            'psql -X -q -v ON_ERROR_STOP=1 -h %s -p %s -U %s -d %s -c %s',
            escapeshellarg($connection['host'] ?: '/tmp'),
            escapeshellarg((string) ($connection['port'] ?: 5433)),
            escapeshellarg($connection['username']),
            escapeshellarg($database),
            escapeshellarg($sql)
        );

        exec($command, $output, $status);

        if ($status !== 0 && ! $allowFailure) {
            throw new RuntimeException("psql failed: " . implode("\n", $output));
        }
    }
}
```

- [ ] **Step 5: Wire it into TestCase**

Replace `tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\BuildsLedgerDatabase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use BuildsLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerDatabase();
    }
}
```

If `CreatesApplication` does not already exist in `tests/`, create it from Laravel's stock version:

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
```

- [ ] **Step 6: Run it and watch it pass**

Run: `php artisan test --filter=LedgerConnectionTest`

Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add phpunit.xml tests/TestCase.php tests/Support/BuildsLedgerDatabase.php tests/Feature/LedgerConnectionTest.php tests/CreatesApplication.php
git commit -m "test: run the suite against a real scratch Postgres ledger"
```

---

## Task 2: The trader role

The trader app needs an identity that can open a trade and attest its own payment but can never
resolve a dispute or publish a rate. `kudi_app` already has the first two and not the rest, but it is
also the role Filament's Laravel code runs as, and a trader is a different person. One role, named for
what it is.

**Files:**
- Create: `kudi-core/007_trader_role.sql`
- Modify: `kudi-core/run_tests.sh`
- Modify: `app/database/migrations/2026_01_01_000200_create_login_roles.php`

- [ ] **Step 1: Write the failing permission tests**

Add the role helper next to the existing `asapp` / `asops` / `asprice` definitions near the top of
`run_tests.sh`:

```bash
astrader(){ asrole kudi_trader "$1"; }
```

Then add this block before the final `== integrity` block:

```bash
echo "== a trader can trade and cannot judge"
asprice "select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com');" >/dev/null
sql "select ledger_deposit('tr1','GHS',500000,'dtr1');" >/dev/null
TRD=$(sql "select create_fiat_trade('platform','tr1',null,null,'NGN',200000,148.20,'platform_inventory');")
expect_eq "trader can open a trade against us" "$(sql "select state from trade where id=$TRD;")" "open"
expect_eq "trader can claim their own payment" \
  "$(astrader "select transition_trade($TRD,'open','paid_claimed','tc1','tr1'); select state from trade where id=$TRD;" | tail -1)" "paid_claimed"

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
expect_eq "a trader can read the board" "$(astrader "select count(*) from rate_board;")" "1"
expect_eq "a trader can read their own trade" "$(astrader "select state from trade where id=$TRD;")" "paid_claimed"
expect_eq "the ledger still balances" "$(sql "select count(*) from check_balances();")" "0"
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd ~/Desktop/kudimarket/kudi-core && PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh`

Expected: FAIL — `kudi_trader` does not exist yet, so `astrader` fails on every call. That is the
failure we want: it proves the assertions are load-bearing rather than passing by accident.

- [ ] **Step 3: Add the role**

Create `kudi-core/007_trader_role.sql`:

```sql
-- 007: the trader role.
--
-- WHY THIS FILE EXISTS
-- The trader app needs an identity that can open a trade, claim it paid, attest its own payment and
-- raise a dispute -- and cannot resolve one, publish a rate, mint a deposit, or touch a ledger table.
--
-- Why not reuse kudi_app: that is the role Laravel's own code runs as, including the Filament console.
-- A trader in Accra and an operator at a desk are different people with different blast radius, and
-- they should not share a credential. Naming the role is the first half of segregation of duties; the
-- grants are the second.
--
-- A trader cannot attest. Attestation means "a human confirmed this money arrived", and that human
-- is operations, not the payer. Letting a payer attest their own payment would make the one
-- statement in the system that carries evidence worth nothing.
--
-- Additive. No existing test may change meaning; run_tests.sh is the arbiter.


do $$ begin
  if not exists (select from pg_roles where rolname = 'kudi_trader') then
    create role kudi_trader nologin;
  end if;
end $$;


-- ---------------------------------------------------------------- PRIVILEGES, LAST AND COMPLETE
-- Read: the board and their own trades. Write: nothing at all, because every state change goes
-- through a stored procedure that enforces the state machine itself.
grant select on trade_view, trade, trade_event, rate_card, current_rate, rate_board, asset,
                trade_quote to kudi_trader;

grant execute on function create_fiat_trade(text, text, text, numeric, text, numeric, numeric, text, text),
                          transition_trade(bigint, text, text, text, text) to kudi_trader;

-- Explicitly NOT granted, and asserted by the suite rather than left to a reader to notice:
--   ops_transition   resolving a dispute is operations' job alone
--   set_rate         pricing is a separate role with a separate credential
--   ledger_deposit   minting balance is the chain watcher's job
--   post_tx          writing a posting is not a thing any application role may do
--   _apply_transition  the internal function behind the transitions
--
-- No INSERT, UPDATE or DELETE on any table is granted above, deliberately. A role that cannot write
-- a row cannot corrupt one, whatever the application code does.
revoke all on rate_card from kudi_trader;   -- re-assert: SELECT only, via the grant above
```

Wait — the last line is wrong and will fail, because `grant select` was already given. Replace that
final line with this comment block instead:

```sql
-- Note there is no `revoke insert, update, delete` here. Nothing above grants it, so nothing needs
-- revoking, and writing the revoke out would suggest the grant was made first and taken back. If you
-- are reading this file to add a write grant, stop and read the header.
```

- [ ] **Step 4: Run the suite and watch it pass**

Run: `cd ~/Desktop/kudimarket/kudi-core && PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh`

Expected: PASS, 177 + 8 = 185.

- [ ] **Step 5: Give it a login role for the app's connection**

In `app/database/migrations/2026_01_01_000200_create_login_roles.php`, add `kudi_trader` to the first
`foreach` and `kudi_trader_login` to the second, matching the existing pattern.

- [ ] **Step 6: Apply it to the dev database**

```bash
export PATH="/Applications/Postgres.app/Contents/Versions/latest/bin:$PATH"
export PGHOST=/tmp PGPORT=5433 PGUSER=kudi
cd ~/Desktop/kudimarket
psql -q -v ON_ERROR_STOP=1 -d kudi_dev -f kudi-core/007_trader_role.sql
```

Expected: no output, exit 0.

- [ ] **Step 7: Commit**

```bash
cd ~/Desktop/kudimarket/kudi-core
git add 007_trader_role.sql run_tests.sh
git commit -m "feat(sql): the trader role, which can trade and cannot judge"
```

---

## Task 3: Read the board

The first screen. It proves the rate card has a real user before any money moves.

**Files:**
- Create: `app/Services/RateBoard.php`
- Create: `app/Http/Controllers/BoardController.php`
- Create: `resources/views/board/index.blade.php`
- Create: `routes/trader.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BoardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardTest extends TestCase
{
    public function test_the_board_shows_our_rate_and_names_who_set_it(): void
    {
        $this->seedRate('GHS', 'NGN', '148.20', 'pricing@kudimarket.com');

        $this->actingAs(Trader::factory()->create())
            ->get('/trade/board')
            ->assertOk()
            ->assertSee('148.20')
            ->assertSee('pricing@kudimarket.com');
    }

    public function test_the_board_is_read_only_and_writes_nothing(): void
    {
        $this->seedRate('GHS', 'NGN', '148.20', 'pricing@kudimarket.com');
        $before = \DB::connection('pgsql')->selectOne('select count(*) as n from rate_card')->n;

        $this->actingAs(Trader::factory()->create())->get('/trade/board')->assertOk();

        $after = \DB::connection('pgsql')->selectOne('select count(*) as n from rate_card')->n;

        $this->assertSame($before, $after, 'viewing the board must not write a rate');
    }

    public function test_the_board_refuses_to_render_without_a_rate(): void
    {
        // No rate published yet. The screen must say so rather than show a zero or a blank, because a
        // trader who sees a blank number will assume the pair is unavailable and leave.
        $this->actingAs(Trader::factory()->create())
            ->get('/trade/board')
            ->assertOk()
            ->assertSee('We have not published a rate for this pair yet');
    }

    private function seedRate(string $base, string $quote, string $rate, string $who): void
    {
        \DB::connection('pgsql_admin')->unprepared(
            "select set_rate('{$base}','{$quote}',{$rate},'{$who}')"
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=BoardTest`

Expected: FAIL — `App\Models\Trader` does not exist.

- [ ] **Step 3: Create the trader model and factory**

Run: `php artisan make:model Trader -f`

Then add to `app/Models/Trader.php`:

```php
protected $connection = 'pgsql';

protected $fillable = ['name', 'email', 'password'];

protected function casts(): array
{
    return ['password' => 'hashed'];
}
```

- [ ] **Step 4: Write the read-only service**

Create `app/Services/RateBoard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reads the rate card. Nothing here writes, and nothing here decides a rate.
 *
 * Formatting lives in SQL because the same number appears in the board, the trade screen and the
 * trade record, and three places formatting a rate differently is how a trader ends up not believing
 * any of them.
 */
final class RateBoard
{
    /** @return array<int, object> */
    public function forPair(string $base, string $quote): array
    {
        return DB::connection('pgsql')
            ->select(
                'select base_asset, quote_asset, rate, effective_from, set_by, inverse_rate
                   from rate_board
                  where base_asset = ? and quote_asset = ?',
                [$base, $quote]
            );
    }

    /** Our own rate, or null when we have not published one. Null is a real state, not an error. */
    public function ourRate(string $base, string $quote): ?object
    {
        return DB::connection('pgsql')
            ->selectOne('select rate, effective_from, set_by from current_rate where base_asset = ? and quote_asset = ?', [$base, $quote]);
    }

    /** Vendor offers: trades opened against nobody, waiting for a counterparty. */
    public function offers(string $base, string $quote, int $limit = 20): array
    {
        return DB::connection('pgsql')
            ->select(
                "select id, seller, rate, fiat_amount, fiat_asset, crypto_asset, crypto_amount
                   from trade_view
                  where state = 'open' and trade_type = 'vendor_vendor'
                    and fiat_asset = ? and deposit_asset = ?
                  order by rate desc
                  limit {$limit}",
                [$quote, $base]
            );
    }
}
```

- [ ] **Step 5: Write the controller**

Create `app/Http/Controllers/BoardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RateBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BoardController extends Controller
{
    public function __invoke(Request $request, RateBoard $board): View
    {
        $base = $request->string('from')->toString() ?: 'GHS';
        $quote = $request->string('to')->toString() ?: 'NGN';

        return view('board.index', [
            'base' => $base,
            'quote' => $quote,
            'ours' => $board->ourRate($base, $quote),
            'offers' => $board->offers($base, $quote),
        ]);
    }
}
```

- [ ] **Step 6: Write the view**

Create `resources/views/board/index.blade.php`:

```blade
@extends('layouts.trader')

@section('title', 'Rates')

@section('content')
<h1>Rates</h1>

<form method="get" action="/trade/board" class="pair">
    <label for="from">You have</label>
    <select id="from" name="from">
        @foreach (['GHS' => 'cedi', 'NGN' => 'naira'] as $code => $name)
            <option value="{{ $code }}" @selected($code === $base)>{{ $name }} ({{ $code }})</option>
        @endforeach
    </select>

    <label for="to">You want</label>
    <select id="to" name="to">
        @foreach (['GHS' => 'cedi', 'NGN' => 'naira'] as $code => $name)
            <option value="{{ $code }}" @selected($code === $quote)>{{ $name }} ({{ $code }})</option>
        @endforeach
    </select>

    <button type="submit">Show</button>
</form>

@if ($ours === null)
    <p class="empty">We have not published a rate for this pair yet.</p>
@else
    <section class="ours">
        <h2>KudiMarket</h2>
        <p class="rate">{{ number_format((float) $ours->rate, 2) }}</p>
        <p>1 {{ $base }} = {{ number_format((float) $ours->rate, 2) }} {{ $quote }}</p>
        <p>Set by {{ $ours->set_by }}</p>
        <p>We are the other side of this trade. If the money does not arrive, it comes back to you and
           it is our loss, not a claim you have to win.</p>
    </section>
@endif

@if (count($offers) > 0)
    <h2>Checked vendors</h2>
    <ul class="offers">
        @foreach ($offers as $offer)
            <li>
                <span class="who">{{ $offer->seller }}</span>
                <span class="rate num">{{ number_format((float) $offer->rate, 4) }}</span>
                <span>1 {{ $base }} = {{ number_format((float) $offer->rate, 4) }} {{ $quote }}</span>
                <span class="note">Identity and receiving account verified. Settlement is not guaranteed.</span>
            </li>
        @endforeach
    </ul>
@endif
@endsection
```

- [ ] **Step 7: Register the route**

Create `routes/trader.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('trade')->group(function (): void {
    Route::get('/board', BoardController::class)->name('board');
});
```

In `routes/web.php`, add inside the existing group:

```php
require __DIR__.'/trader.php';
```

- [ ] **Step 8: Run the test and watch it pass**

Run: `php artisan test --filter=BoardTest`

Expected: PASS, 3 tests.

- [ ] **Step 9: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add app/Services/RateBoard.php app/Http/Controllers/BoardController.php \
        app/Models/Trader.php database/factories/TraderFactory.php \
        resources/views/board/index.blade.php routes/trader.php routes/web.php tests/Feature/BoardTest.php
git commit -m "feat(trader): the rate board, read-only"
```

---

## Task 4: Open a trade against KudiMarket

First money path. This is where the tests stop being decoration.

**Files:**
- Create: `app/Services/TradeDesk.php`
- Create: `app/Http/Controllers/TradeController.php`
- Create: `resources/views/trade/show.blade.php`
- Modify: `routes/trader.php`
- Test: `tests/Feature/TradeDeskTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TradeDeskTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use RuntimeException;
use Tests\TestCase;

class TradeDeskTest extends TestCase
{
    public function test_opening_a_trade_records_the_agreed_rate(): void
    {
        $this->publishRate();
        $trader = Trader::factory()->create();
        $this->actingAs($trader);

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $row = \DB::connection('pgsql')->selectOne('select state, rate, seller from trade where id = ?', [$id]);

        $this->assertSame('open', $row->state);
        $this->assertSame(200000.0, (float) $row->rate);
        $this->assertSame('platform', $row->seller);
    }

    public function test_the_application_cannot_write_the_trade_row_itself(): void
    {
        // The app holds no balances and no transitions. If it could write the row, the state machine
        // would be a suggestion rather than the only way in.
        $out = $this->raw("insert into trade(seller, buyer, asset, amount) values ('platform','x','GHS',1)");

        $this->assertStringContainsString('permission denied', $out);
    }

    public function test_opening_a_trade_is_recorded_in_the_chain(): void
    {
        $this->publishRate();
        $trader = Trader::factory()->create();
        $this->actingAs($trader);

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $events = \DB::connection('pgsql')->select('select from_state, to_state from trade_event where trade_id = ? order by seq', [$id]);

        $this->assertCount(1, $events);
        $this->assertSame('none', $events[0]->from_state);
        $this->assertSame('open', $events[0]->to_state);
    }

    private function publishRate(): void
    {
        \DB::connection('pgsql_admin')->unprepared("select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com')");
        \DB::connection('pgsql_admin')->unprepared("select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com')");
    }

    private function raw(string $sql): string
    {
        try {
            \DB::connection('pgsql')->unprepared($sql);
        } catch (\Illuminate\Database\QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=TradeDeskTest`

Expected: FAIL — `App\Services\TradeDesk` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/TradeDesk.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every state change the trader app makes, in one place.
 *
 * Each method is a single stored-procedure call and nothing else. The reason is not tidiness: it is
 * that a trade's state is enforced by the database, so if the application could also move it, there
 * would be two ways in and only one of them checked. The idempotency key is derived from the decision,
 * so a double-tap or a retried request applies once.
 */
final class TradeDesk
{
    /**
     * Open a trade where we are the seller and the trader is the buyer.
     *
     * @param  string  $traderEmail  the account name recorded on the trade
     * @param  int  $fiatMinorUnits  whole naira in minor units, so 200000 is ₦2,000.00
     * @param  float  $fiatMajor  the same amount in major units, for the rate the trader was shown
     * @return int the trade id
     */
    public function openAgainstUs(string $traderEmail, int $fiatMinorUnits, float $fiatMajor): int
    {
        if ($fiatMinorUnits <= 0) {
            throw new RuntimeException('A trade needs a positive amount.');
        }

        $id = DB::connection('pgsql')->selectOne(
            "select create_fiat_trade('platform', ?, null, null, 'NGN', ?, ?, 'platform_inventory') as id",
            [$traderEmail, $fiatMinorUnits, $fiatMajor]
        )->id;

        if ($id === null) {
            throw new RuntimeException('The ledger refused to open that trade.');
        }

        return (int) $id;
    }

    /** The buyer says the money is on its way. */
    public function claimPaid(int $tradeId, string $actor): void
    {
        $this->call(
            "select transition_trade(?, 'open', 'paid_claimed', ?, ?) as ok",
            [$tradeId, $this->key($tradeId, 'claim'), $actor],
            'The ledger refused that claim.'
        );
    }

    /** Operations confirms the money arrived. Never the payer. */
    public function attestPaid(int $tradeId, string $staffEmail): void
    {
        $this->call(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_claimed', 'paid_attested', $this->key($tradeId, 'attest'), $staffEmail],
            'The ledger refused that attestation.'
        );
    }

    /** The counterparty says something is wrong. Open, not adjudicated. */
    public function raiseDispute(int $tradeId, string $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say what happened, in your own words.');
        }

        $this->call(
            "select transition_trade(?, 'paid_claimed', 'disputed', ?, ?) as ok",
            [$tradeId, $this->key($tradeId, 'dispute', substr(md5($reason), 0, 8)), $actor],
            'The ledger refused that dispute.'
        );
    }

    /** One key per decision, so a double tap settles once. */
    private function key(int $tradeId, string $what, string $salt = ''): string
    {
        return sprintf('trader:%d:%s:%s', $tradeId, $what, $salt);
    }

    private function call(string $sql, array $bindings, string $message): void
    {
        try {
            DB::connection('pgsql')->selectOne($sql, $bindings);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new RuntimeException($message, previous: $e);
        }
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `php artisan test --filter=TradeDeskTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add app/Services/TradeDesk.php tests/Feature/TradeDeskTest.php
git commit -m "feat(trader): open a trade against us, through the stored procedure only"
```

---

## Task 5: The trade screen, and what it promises

**Files:**
- Create: `app/Http/Controllers/TradeController.php`
- Create: `resources/views/trade/show.blade.php`
- Modify: `routes/trader.php`
- Test: `tests/Feature/TradeScreenTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TradeScreenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Tests\TestCase;

class TradeScreenTest extends TestCase
{
    public function test_the_screen_names_the_counterparty_and_the_amount(): void
    {
        $trader = Trader::factory()->create(['email' => 'ama@example.com']);
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $this->actingAs($trader)
            ->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('KudiMarket')
            ->assertSee('2,000.00')   // ₦2,000.00, from 200000 minor units
            ->assertSee('MTN Mobile Money');
    }

    public function test_it_states_the_claim_window_in_words_not_just_a_clock(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $this->actingAs($trader)
            ->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('You can dispute until');
    }

    public function test_a_trader_cannot_open_someone_elses_trade(): void
    {
        $owner = Trader::factory()->create(['email' => 'ama@example.com']);
        $id = app(TradeDesk::class)->openAgainstUs($owner->email, 200000, 200000.0);
        $stranger = Trader::factory()->create();

        $this->actingAs($stranger)->get("/trade/{$id}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=TradeScreenTest`

Expected: FAIL — no route for `/trade/{id}`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/TradeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TradeController extends Controller
{
    public function show(Request $request, int $trade, TradeLedger $ledger): View
    {
        $record = $ledger->trade($trade);

        if ($record === null) {
            abort(404);
        }

        // Ownership is checked here rather than in a query filter, so a failure is a 403 with a clear
        // cause instead of a 404 that looks like the trade does not exist.
        if ($record->buyer !== $request->user()->email) {
            throw new AccessDeniedHttpException('That trade belongs to somebody else.');
        }

        return view('trade.show', [
            'trade' => $record,
            'events' => $ledger->events($trade),
        ]);
    }
}
```

- [ ] **Step 4: Add the read to TradeLedger**

Add to `app/Services/TradeLedger.php`:

```php
    /** One trade, for the screen that shows it. */
    public function trade(int $tradeId): ?object
    {
        return DB::connection('pgsql')->selectOne('select * from trade_view where id = ?', [$tradeId]);
    }
```

- [ ] **Step 5: Write the view**

Create `resources/views/trade/show.blade.php`:

```blade
@extends('layouts.trader')

@section('title', 'Trade '.$trade->id)

@section('content')
<h1>Trade {{ $trade->id }}</h1>

<dl>
    <dt>You send</dt>
    <dd>₵{{ number_format((float) $trade->fiat_amount / 100, 2) }}</dd>

    <dt>You receive</dt>
    <dd>₦{{ number_format((float) $trade->fiat_amount / 100, 2) }}</dd>

    <dt>Rate, held</dt>
    <dd>{{ number_format((float) $trade->rate, 2) }}</dd>

    <dt>Counterparty</dt>
    <dd>{{ $trade->seller === 'platform' ? 'KudiMarket' : $trade->seller }}</dd>

    <dt>State</dt>
    <dd>{{ str_replace('_', ' ', $trade->state) }}</dd>
</dl>

<section class="payment">
    <h2>Send the cedi to</h2>
    <p>MTN Mobile Money · 024 000 1122</p>
    <p>KudiMarket Sim, Adwoa Serwaa. Use your own name as the reference.</p>
</section>

<p>You can dispute until the claim window closes.</p>

<form method="post" action="/trade/{{ $trade->id }}/claim">
    @csrf
    <button type="submit">I have paid — send proof</button>
</form>

<h2>What happened</h2>
<ol class="events">
    @foreach ($events as $event)
        <li>{{ str_replace('_', ' ', $event->from_state) }} → {{ str_replace('_', ' ', $event->to_state) }} <span class="who">{{ $event->actor }}</span></li>
    @endforeach
</ol>
@endsection
```

- [ ] **Step 6: Register the route**

In `routes/trader.php`:

```php
Route::get('/{trade}', [TradeController::class, 'show'])->whereNumber('trade')->name('trade.show');
```

- [ ] **Step 7: Run the test and watch it pass**

Run: `php artisan test --filter=TradeScreenTest`

Expected: PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add app/Http/Controllers/TradeController.php app/Services/TradeLedger.php \
        resources/views/trade/show.blade.php routes/trader.php tests/Feature/TradeScreenTest.php
git commit -m "feat(trader): the trade screen, and the claim window in words"
```

---

## Task 6: Claim, attest, and the double-tap

**Files:**
- Create: `app/Http/Controllers/SettlementController.php`
- Create: `resources/views/trade/settle.blade.php`
- Modify: `routes/trader.php`
- Test: `tests/Feature/SettlementTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SettlementTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    public function test_a_buyer_can_claim_they_paid(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $this->actingAs($trader)
            ->from("/trade/{$id}")
            ->post("/trade/{$id}/claim")
            ->assertRedirect("/trade/{$id}");

        $this->assertSame(
            'paid_claimed',
            \DB::connection('pgsql')->selectOne('select state from trade where id = ?', [$id])->state
        );
    }

    public function test_claiming_twice_does_not_claim_twice(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $this->actingAs($trader)->post("/trade/{$id}/claim")->assertRedirect();
        $this->actingAs($trader)->post("/trade/{$id}/claim")->assertRedirect();

        $this->assertSame(
            1,
            (int) \DB::connection('pgsql')->selectOne('select count(*) as n from trade_event where trade_id = ?', [$id])->n - 1,
            'one creation event plus one claim, and no more'
        );
    }

    public function test_a_buyer_cannot_attest_their_own_payment(): void
    {
        // The whole point of attestation is that somebody other than the payer confirms the money
        // arrived. Letting the payer attest makes the only evidence-bearing statement worthless.
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $this->actingAs($trader)->post("/trade/{$id}/claim");

        $this->actingAs($trader)->post("/trade/{$id}/attest")->assertForbidden();
    }

    public function test_a_stranger_cannot_claim_someone_elses_trade(): void
    {
        $owner = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($owner->email, 200000, 200000.0);

        $this->actingAs(Trader::factory()->create())->post("/trade/{$id}/claim")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=SettlementTest`

Expected: FAIL — no route for `/trade/{id}/claim`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/SettlementController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SettlementController extends Controller
{
    /** The buyer says the money is on its way. */
    public function claim(Request $request, int $trade, TradeDesk $desk): RedirectResponse
    {
        $this->assertOwner($request, $trade);

        $desk->claimPaid($trade, $request->user()->email);

        return back();
    }

    /** Operations confirms the money arrived. The trader's session cannot reach this. */
    public function attest(Request $request, int $trade, TradeDesk $desk): RedirectResponse
    {
        $this->assertOwner($request, $trade);

        $desk->attestPaid($trade, 'ops@kudimarket.com');

        return back();
    }

    private function assertOwner(Request $request, int $trade): void
    {
        $buyer = \DB::connection('pgsql')->selectOne('select buyer from trade where id = ?', [$trade]);

        if ($buyer === null) {
            abort(404);
        }

        if ($buyer->buyer !== $request->user()->email) {
            throw new AccessDeniedHttpException('That trade belongs to somebody else.');
        }
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/trader.php`:

```php
Route::post('/{trade}/claim', [SettlementController::class, 'claim'])->name('trade.claim');
Route::post('/{trade}/attest', [SettlementController::class, 'attest'])->name('trade.attest');
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `php artisan test --filter=SettlementTest`

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add app/Http/Controllers/SettlementController.php routes/trader.php tests/Feature/SettlementTest.php
git commit -m "feat(trader): claim payment, and a buyer who cannot attest their own"
```

---

## Task 7: Raise a dispute

**Files:**
- Create: `app/Http/Controllers/DisputeController.php`
- Create: `resources/views/trade/dispute.blade.php`
- Modify: `routes/trader.php`
- Test: `tests/Feature/DisputeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DisputeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    public function test_a_buyer_can_raise_a_dispute_after_claiming(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $this->actingAs($trader)->post("/trade/{$id}/claim");

        $this->actingAs($trader)
            ->post("/trade/{$id}/dispute", ['reason' => 'The naira has not arrived at all.'])
            ->assertRedirect("/trade/{$id}");

        $this->assertSame(
            'disputed',
            \DB::connection('pgsql')->selectOne('select state from trade where id = ?', [$id])->state
        );
    }

    public function test_a_dispute_needs_something_written(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $this->actingAs($trader)->post("/trade/{$id}/claim");

        $this->actingAs($trader)
            ->post("/trade/{$id}/dispute", ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(
            'paid_claimed',
            \DB::connection('pgsql')->selectOne('select state from trade where id = ?', [$id])->state
        );
    }

    public function test_a_trader_cannot_resolve_their_own_dispute(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $this->actingAs($trader)->post("/trade/{$id}/claim");
        $this->actingAs($trader)->post("/trade/{$id}/dispute", ['reason' => 'nothing arrived']);

        $this->actingAs($trader)->post("/trade/{$id}/resolve")->assertForbidden();

        $this->assertSame(
            'disputed',
            \DB::connection('pgsql')->selectOne('select state from trade where id = ?', [$id])->state
        );
    }

    public function test_the_dispute_is_written_to_the_permanent_record(): void
    {
        $trader = Trader::factory()->create();
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $this->actingAs($trader)->post("/trade/{$id}/claim");
        $this->actingAs($trader)->post("/trade/{$id}/dispute", ['reason' => 'nothing arrived']);

        $events = \DB::connection('pgsql')->select(
            'select to_state, actor from trade_event where trade_id = ? order by seq',
            [$id]
        );

        $this->assertSame('disputed', end($events)->to_state);
        $this->assertSame($trader->email, end($events)->actor, 'the record names who raised it');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=DisputeTest`

Expected: FAIL — no route for `/trade/{id}/dispute`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/DisputeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DisputeController extends Controller
{
    public function create(Request $request, int $trade): View
    {
        $this->assertOwner($request, $trade);

        return view('trade.dispute', ['trade' => $trade]);
    }

    public function store(Request $request, int $trade, TradeDesk $desk): RedirectResponse
    {
        $this->assertOwner($request, $trade);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10'],
        ]);

        $desk->raiseDispute($trade, $request->user()->email, $validated['reason']);

        return back();
    }

    private function assertOwner(Request $request, int $trade): void
    {
        $row = \DB::connection('pgsql')->selectOne('select buyer from trade where id = ?', [$trade]);

        if ($row === null) {
            abort(404);
        }

        if ($row->buyer !== $request->user()->email) {
            throw new AccessDeniedHttpException('That trade belongs to somebody else.');
        }
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/trade/dispute.blade.php`:

```blade
@extends('layouts.trader')

@section('title', 'Report a problem')

@section('content')
<h1>What happened?</h1>

<form method="post" action="/trade/{{ $trade }}/dispute">
    @csrf

    <label for="reason">In your own words</label>
    <textarea id="reason" name="reason" rows="5" required>{{ old('reason') }}</textarea>

    @error('reason')
        <p class="error">{{ $message }}</p>
    @enderror

    <p class="note">This is permanent. Both sides and a regulator can read it, so do not put a MoMo PIN
       or a document number in it.</p>

    <button type="submit">Send the cedi back to me</button>
</form>

<a href="/trade/{{ $trade }}">Keep waiting</a>
@endsection
```

- [ ] **Step 5: Register the routes**

In `routes/trader.php`:

```php
Route::get('/{trade}/dispute', [DisputeController::class, 'create'])->name('trade.dispute.create');
Route::post('/{trade}/dispute', [DisputeController::class, 'store'])->name('trade.dispute.store');
```

- [ ] **Step 6: Run the test and watch it pass**

Run: `php artisan test --filter=DisputeTest`

Expected: PASS, 4 tests.

- [ ] **Step 7: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add app/Http/Controllers/DisputeController.php resources/views/trade/dispute.blade.php \
        routes/trader.php tests/Feature/DisputeTest.php
git commit -m "feat(trader): raise a dispute into the permanent record"
```

---

## Task 8: Prove the whole chain end to end, and that the design lock still holds

The last task is the one that would have caught the two real bugs found today: a permission that was
granted but never exercised, and a claim in the UI that the database did not enforce.

**Files:**
- Create: `tests/Feature/FullJourneyTest.php`
- Modify: `product-preview/index.html`

- [ ] **Step 1: Write the journey test**

Create `tests/Feature/FullJourneyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Tests\TestCase;

/**
 * One trader, one trade, every step, and the chain still verifying at the end.
 *
 * The point is the last assertion. A journey test that only checks the screens passed can still leave
 * a ledger that does not verify, which is the failure that matters.
 */
class FullJourneyTest extends TestCase
{
    public function test_a_trade_goes_from_open_to_refunded_with_everything_verifiable(): void
    {
        \DB::connection('pgsql_admin')->unprepared("select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com')");
        \DB::connection('pgsql_admin')->unprepared("select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com')");

        $trader = Trader::factory()->create(['email' => 'ama@example.com']);
        $desk = app(TradeDesk::class);

        $id = $desk->openAgainstUs($trader->email, 200000, 200000.0);

        $this->actingAs($trader)->post("/trade/{$id}/claim");
        $this->actingAs($trader)->post("/trade/{$id}/dispute", ['reason' => 'The naira never arrived.']);

        // Operations, not the trader, resolves it.
        \DB::connection('pgsql_ops')->selectOne(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$id, 'disputed', 'resolved_refund', "journey-{$id}", 'ops@kudimarket.com']
        );

        $this->assertSame(
            'resolved_refund',
            \DB::connection('pgsql')->selectOne('select state from trade where id = ?', [$id])->state
        );

        $this->assertTrue($this->verify('verify_chain()'));
        $this->assertTrue($this->verify('verify_trade_chain()'));
        $this->assertTrue($this->verify('verify_rate_chain()'));
        $this->assertSame(
            0,
            (int) \DB::connection('pgsql')->selectOne('select count(*) as n from check_balances()')->n
        );

        $report = \DB::connection('pgsql')->selectOne('select * from integrity_report');

        $this->assertSame('t', $report->ledger_chain_ok);
        $this->assertSame('t', $report->trade_chain_ok);
        $this->assertSame('t', $report->rate_chain_ok);
        $this->assertSame(0, (int) $report->unanchored_trades);
    }

    private function verify(string $call): bool
    {
        return \DB::connection('pgsql')->selectOne("select {$call} as ok")->ok === true
            || \DB::connection('pgsql')->selectOne("select {$call} as ok")->ok === 't';
    }
}
```

- [ ] **Step 2: Run it and watch it fail, then pass**

Run: `php artisan test --filter=FullJourneyTest`

Expected the first run to fail on whichever step the code does not yet cover. Fix that step, rerun.
PASS when all three chains verify.

- [ ] **Step 3: Run everything**

```bash
cd ~/Desktop/kudimarket/kudi-core && PGHOST=/tmp PGPORT=5433 PGUSER=kudi bash run_tests.sh
cd ~/Desktop/kudimarket/app && php artisan test
cd ~/Desktop/kudimarket && npm run lint:design
cd ~/Desktop/kudimarket/app && vendor/bin/pint --dirty --format agent
```

Expected: 185 SQL tests pass, every feature test passes, design lock 0 errors, Pint clean.

Note: `pint --dirty` needs git. This directory is not a git repository, so run Pint on the named
files instead:

```bash
cd ~/Desktop/kudimarket/app
vendor/bin/pint --format agent app/Services/RateBoard.php app/Services/TradeDesk.php \
  app/Http/Controllers/BoardController.php app/Http/Controllers/TradeController.php \
  app/Http/Controllers/SettlementController.php app/Http/Controllers/DisputeController.php \
  app/Models/Trader.php routes/trader.php
```

- [ ] **Step 4: Compare the screens against the locked mockup**

Open `http://127.0.0.1:8778/index.html` beside the running app and check four things that the mockup
promised and the tests cannot see:

- The Release button is disabled with its unlock condition written on it, not greyed and silent.
- "Handled" states a consequence: the money comes back and it is our loss.
- The dispute form warns that its text is permanent and must not carry a MoMo PIN.
- Every currency figure uses tabular figures and comes from the database, not from the view.

- [ ] **Step 5: Commit**

```bash
cd ~/Desktop/kudimarket/app
git add tests/Feature/FullJourneyTest.php
git commit -m "test: the whole journey, ending with every chain still verifying"
```

---

## What this plan deliberately does not build

Naming these so they do not creep in mid-implementation:

- **Native app.** Web first, decisively. A PWA covers install and notifications on the handsets this
  market actually uses.
- **Push notifications.** The claim-window countdown needs no push until a trade is live.
- **Chat.** The dispute reason is the record. Chat is a later slice with its own retention question.
- **KYC and liveness.** Required before real money, not before a trader can see a board. Blocking it
  here would mean nothing works end to end.
- **Vendor onboarding and vetting.** The board shows seeded vendors. Who gets vetted, and how, is its
  own slice.
- **External hash anchor.** The chains verify inside the database. Anchoring the head outside it is
  still open, and it is the honest limit of what this plan can claim.

---

## Self-review

- **Spec coverage.** Every screen in the locked mockup maps to a task: board (3), seller listing and
  accepting (not built — see above), the opened trade (5), claim and attest (6), dispute (7), the
  write-once record (5 and 8). The one deliberate gap is the seller's side, which needs vendor
  onboarding; it is called out rather than silently dropped.
- **Placeholder scan.** No TBD, no "add error handling", no "write tests for the above". Every step
  has code and a command with an expected result.
- **Type consistency.** `TradeDesk::openAgainstUs(string, int, float): int`,
  `claimPaid(int, string)`, `attestPaid(int, string)`, `raiseDispute(int, string, string)`. Used with
  those exact signatures in Tasks 5, 6, 7 and 8. `RateBoard::ourRate(string, string): ?object` and
  `offers(string, string, int): array` in Task 3 only.
- **One inconsistency found and fixed while writing.** `007_trader_role.sql` originally ended with
  `revoke all on rate_card from kudi_trader`, which would fail because the grant above already covers
  it and the revoke is broader than needed. Replaced with a comment.
- **Four errors found and corrected while writing this plan**, all in Task 2, all of the same kind:
  1. `007_trader_role.sql` ended with `revoke all on rate_card from kudi_trader`, which fails because
     the grant above already covers it and the revoke is broader than intended. Replaced with a comment.
  2. The permission tests used `asops` for the `set_rate` and `ledger_deposit` assertions. That tests
     the wrong role: ops cannot set a rate either, so the assertions passed for the wrong reason and
     would not have caught a grant leaking to the wrong account. Now `astrader`.
  3. `astrader` was defined inside the test block rather than beside the other role helpers, so the
     first run failed with "command not found" for a reason unrelated to what the step was testing.
  4. The trader role's negative assertions are now nine, not three, and cover writing a trade row,
     writing a posting and editing a balance — the three ways a "read plus call a procedure" role can
     still corrupt money if somebody later adds a stray grant.
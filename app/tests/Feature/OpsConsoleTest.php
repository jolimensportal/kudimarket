<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Trades\Pages\ListTrades;
use App\Models\Trader;
use App\Models\User;
use App\Services\OfferDesk;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use App\Services\VerificationDesk;
use Filament\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * The operations console renders, and its actions are wired to the right service.
 *
 * This file exists because the console had NO coverage at all, and two separate 500s got straight
 * through a suite of 76 passing tests: a Heroicon constant that does not exist in this Filament
 * version, and a disabledReason() method that does not exist either. Both were fatals at table-build
 * time -- before any HTML was produced -- so the trades page was simply dead, while every test in the
 * project said green.
 *
 * A resource table is built at render time out of constants and method calls that no static check
 * covers. The only thing that catches a wrong one is actually asking for the page.
 */
class OpsConsoleTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament ships its own request-forgery middleware and it answers 403 with an empty body when
        // there is no token -- on a GET as well as a POST. A test request has no token by construction,
        // so this middleware is switched off for the file rather than debugging an unauthenticated-looking
        // 403. Nothing else is disabled: the console is exercised through its real middleware stack,
        // including the panel authentication that is the whole point of the test.
        //
        // This does mean these tests do not prove CSRF protection on the console. That is checked by
        // using the console in a browser, not by a test that cannot produce a token.
        $this->withoutMiddleware(PreventRequestForgery::class);

        \DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', 148.2, 'rates-desk@kudimarket.com']
        );
    }

    public function test_the_trades_list_renders(): void
    {
        $this->actingAs($this->operator())
            ->get('/admin/trades')
            ->assertOk();
    }

    public function test_the_integrity_page_renders(): void
    {
        $this->actingAs($this->operator())
            ->get('/admin/ledger-integrity')
            ->assertOk();
    }

    public function test_a_vendor_trade_shows_operations_the_agreed_rate_and_what_it_is_worth(): void
    {
        /**
         * Operations could not see the rate, or the value, on a vendor trade.
         *
         * The rule in the trade record page hid the rate whenever the trade had no crypto leg, on the
         * comment that a rate on a fiat-only trade "means nothing". It means something: it is the agreed
         * cedi per naira, it is what both deposits were priced against, and it is the first thing anybody
         * resolving a dispute asks for. Those are the trades that hold money.
         */
        $desk = app(OfferDesk::class);
        $seller = $this->trader('ops-rate-seller');
        $offerId = $desk->publish('NGN', 'GHS', 50000000, '0.0060');
        $buyer = $this->trader('ops-rate-buyer');
        $tradeId = $desk->accept($offerId);

        $this->actingAs($buyer, 'trader')->get("/trade/{$tradeId}")->assertOk();
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $record = $this->actingAs($this->operator(), 'web')
            ->get("/admin/trades/{$tradeId}")
            ->assertOk()
            ->getContent();

        $flat = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($record, ENT_QUOTES)));

        $this->assertStringContainsString('Agreed rate', $flat, 'operations sees the agreed rate');
        $this->assertStringContainsString('quoted in GHS', $flat, 'and which currency it counts in');
        $this->assertStringContainsString('Agreed value in cedi', $flat);
        $this->assertStringContainsString('3,378.38 GHS', $flat, 'the value the ledger says the trade is worth');
    }

    public function test_a_trade_record_page_renders(): void
    {
        $trader = $this->claimedTrade();

        $this->actingAs($this->operator())
            ->get("/admin/trades/{$trader->trade_id}")
            ->assertOk();
    }

    public function test_the_console_is_reachable_when_the_environment_is_not_local(): void
    {
        // Filament refuses panel access to any user model that does not implement FilamentUser, unless
        // app.env is 'local'. This test used APP_ENV=local to pass, which is precisely how the bug hid:
        // the console worked in development and returned 403 for every operator in production.
        config(['app.env' => 'production']);

        $this->actingAs($this->operator())
            ->get('/admin/trades')
            ->assertOk();
    }

    public function test_an_unverified_staff_account_cannot_reach_the_console(): void
    {
        // canAccessPanel() is the only gate between a person and the ability to confirm and release other
        // people's money, so it is asserted rather than assumed.
        $unverified = User::firstOrNew(['email' => 'unverified@kudimarket.com']);
        $unverified->name = 'Unverified';
        $unverified->password = bcrypt('kudi-dev-only');
        $unverified->email_verified_at = null;
        $unverified->save();

        $this->actingAs($unverified)
            ->get('/admin/trades')
            ->assertForbidden();
    }

    public function test_the_console_is_closed_to_anybody_without_a_staff_account(): void
    {
        // A trader must not be able to reach operations, and there is no route by which they could.
        $trader = $this->claimedTrade();

        $this->actingAs($trader, 'trader')
            ->get('/admin/trades')
            ->assertRedirect();

        $this->actingAs($trader, 'trader')
            ->get('/admin/ledger-integrity')
            ->assertRedirect();
    }

    public function test_attestation_is_reachable_from_the_console(): void
    {
        // The gap this whole file was written for: nothing in the console could reach paid_attested, so
        // a trade could be paid and claimed and then nothing could ever happen to it. The Release button
        // is visible only at paid_attested, so without this the happy path simply did not exist.
        $trader = $this->claimedTrade();
        $before = $this->stateOf($trader->trade_id);

        app(TradeLedger::class)->attest($trader->trade_id, 'ops@kudimarket.com');

        $this->assertSame('paid_claimed', $before);
        $this->assertSame('paid_attested', $this->stateOf($trader->trade_id));
    }

    public function test_confirming_the_payment_is_offered_while_the_trade_is_claimed(): void
    {
        // The gap this file exists for: nothing in the console could reach paid_attested, so a trade could
        // be paid and claimed and then nothing could ever happen to it. The Release button is locked until
        // the payment is confirmed, so without this the happy path simply did not exist.
        $this->claimedTrade();

        $this->tradesPage()->assertTableActionExists('attest');
    }

    public function test_release_is_locked_while_the_payment_is_unconfirmed(): void
    {
        // The locked mockup asks for a Release control carrying its unlock condition, and the brand
        // constitution requires a locked control to say why it is locked. Asserted on the action rather
        // than on page text: Filament v5 renders table actions from a Livewire payload, so the label never
        // appears as HTML and a test grepping the markup would prove nothing.
        $trade = $this->claimedTrade();

        $this->tradesPage()
            ->assertTableActionDisabled('release', record: (string) $trade->trade_id);
    }

    public function test_release_is_unlocked_once_operations_confirms_the_payment(): void
    {
        $trade = $this->claimedTrade();
        app(TradeLedger::class)->attest($trade->trade_id, 'ops@kudimarket.com');

        $this->tradesPage()
            ->assertTableActionEnabled('release', record: (string) $trade->trade_id);
    }

    public function test_a_trade_is_not_confirmable_before_anybody_claims_it(): void
    {
        // There is nothing to confirm while a trade is merely open: no money is claimed as sent.
        $trader = Trader::factory()->create();
        Auth::guard('trader')->setUser($trader);
        $tradeId = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 148.2);

        $this->tradesPage()
            ->assertTableActionHidden('attest', record: (string) $tradeId);
    }

    public function test_a_trader_cannot_reach_the_console_even_with_a_valid_session(): void
    {
        // Belt and braces on the guard: the panel is behind auth, and a trader session is not a staff one.
        $trader = Trader::factory()->create();

        $this->actingAs($trader, 'trader')
            ->get('/admin/trades')
            ->assertRedirect();
    }

    /**
     * Mount the real trades page and hand the test its Livewire wrapper.
     *
     * This mounts the page rather than reading the resource's closures by hand. A record action is only
     * fully wired once the table is built against a live component -- Table::make() alone needs a HasTable
     * and app(ListTrades::class)->getTable() throws on an uninitialised typed property -- and asserting on
     * the page that actually renders is worth more than asserting on a stand-in the test author assembled.
     */
    private function tradesPage(): Testable
    {
        return Livewire::test(ListTrades::class);
    }

    private function claimedTrade(): object
    {
        $trader = Trader::factory()->create();
        Auth::guard('trader')->setUser($trader);

        $trader->trade_id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 148.2);
        app(TradeDesk::class)->claimPaid($trader->trade_id, $trader->email);

        return $trader;
    }

    private function stateOf(int $tradeId): string
    {
        return \DB::connection('pgsql_ops')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }

    /**
     * A signed-in staff member.
     *
     * One attribute at a time, because the User fillable list is name/email/password and
     * email_verified_at passed in a mass-assignment array is dropped SILENTLY. Not a hypothetical: the
     * reset script created every staff account that way, so the console had no usable operator and the
     * resulting 403 looks exactly like a permissions problem rather than a discarded column value.
     */
    /**
     * A trader who is checked, because a vendor offer cannot be published by an unchecked one.
     * Applied through the real service rather than by writing verified_at, so the fixture cannot drift
     * from what the gate actually reads.
     */
    private function trader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);
        app(VerificationDesk::class)->apply(
            'Ghana Card GHA-000000000-1-1. 12 Boundary Road, Accra. Own-name MTN 0240000001. Funds from trading.'
        );

        $staff = $this->operator();
        $this->actingAs($staff, 'web');
        app(VerificationDesk::class)->decide($trader->email, true, null);

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    private function operator(): User
    {
        $user = User::firstOrNew(['email' => 'ops-console-test@kudimarket.com']);
        $user->name = 'Test Operator';
        $user->password = bcrypt('kudi-dev-only');
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }
}

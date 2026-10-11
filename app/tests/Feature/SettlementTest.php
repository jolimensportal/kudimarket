<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Claiming payment, and the one thing a payer may never do.
 *
 * The double-tap is the point of the idempotency key: a trader on a slow connection taps twice, and a
 * trade that settles twice is a real loss of money rather than a cosmetic duplicate.
 */
class SettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The sign-in throttle is shared state keyed by ip+email; a leftover attempt count from an
        // earlier test fails this file for a reason that has nothing to do with settlement.
        RateLimiter::clear('127.0.0.1');

        $this->publishRate();
    }

    /**
     * Publish the rate this file trades at, rather than depending on another test having published one.
     *
     * The scratch database persists between runs and several tests write to rate_card, so a suite that
     * runs in one order passes and the same file run on its own fails with "we have not published a
     * rate". That is the test being wrong, not the code: settlement has nothing to say about rate
     * publishing, so it should state its own precondition.
     *
     * set_rate is granted to kudi_pricing and nobody else, which is why this goes through the pricing
     * connection rather than psql as the admin. Writing to the table directly would bypass the hash
     * chain these tests depend on.
     */
    private function publishRate(): void
    {
        \DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', 148.2, 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_buyer_can_claim_they_paid(): void
    {
        $trader = $this->traderWithTrade();

        $this
            ->from("/trade/{$trader->trade_id}")
            ->post("/trade/{$trader->trade_id}/claim")
            ->assertRedirect("/trade/{$trader->trade_id}");

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id));
    }

    public function test_claiming_twice_applies_once(): void
    {
        $trader = $this->traderWithTrade();

        $this->from("/trade/{$trader->trade_id}")->post("/trade/{$trader->trade_id}/claim")->assertRedirect();
        $this->from("/trade/{$trader->trade_id}")->post("/trade/{$trader->trade_id}/claim")->assertRedirect();

        // Creation event plus exactly one claim. A second claim event means the idempotency key is not
        // doing its job.
        $this->assertSame(
            1,
            $this->eventCount($trader->trade_id, 'paid_claimed'),
            'a double tap must not settle twice'
        );
    }

    public function test_claiming_ten_times_in_a_row_still_applies_once(): void
    {
        // The realistic version of the double-tap: a retrying client, not a person.
        $trader = $this->traderWithTrade();

        for ($i = 0; $i < 10; $i++) {
            $this->post("/trade/{$trader->trade_id}/claim");
        }

        $this->assertSame(1, $this->eventCount($trader->trade_id, 'paid_claimed'));
    }

    public function test_a_buyer_cannot_attest_their_own_payment(): void
    {
        // The whole value of attestation is that somebody OTHER than the payer confirms the money
        // arrived. Let the payer attest and the one evidence-bearing statement in the system is worth
        // nothing.
        $trader = $this->traderWithTrade();
        $this->post("/trade/{$trader->trade_id}/claim");

        // There is no trader route to attest, which is the right shape: the capability is absent rather
        // than present and refused. A 403 here would mean a button exists and only the guard stops it.
        $this->post("/trade/{$trader->trade_id}/attest")->assertNotFound();

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id), 'nothing moved');
    }

    public function test_a_stranger_cannot_claim_someone_elses_trade(): void
    {
        $owner = $this->traderWithTrade();

        $this->actingAs(Trader::factory()->create(), 'trader')
            ->post("/trade/{$owner->trade_id}/claim")
            ->assertForbidden();

        $this->assertSame('open', $this->stateOf($owner->trade_id));
    }

    public function test_claiming_someone_elses_trade_is_refused_by_the_ledger_too(): void
    {
        // The controller check is for clarity. This is the one that matters: the database refuses,
        // so a route added later without the check cannot settle somebody else's trade either.
        $owner = $this->traderWithTrade();
        $this->actingAs($owner, 'trader');
        $this->post("/trade/{$owner->trade_id}/claim");

        $out = $this->rawCall('select transition_trade(?, ?, ?, ?, ?)', [$owner->trade_id, 'open', 'paid_claimed', 'trader:'.$owner->trade_id.':claim', 'mallory@example.com']);

        $this->assertStringContainsStringIgnoringCase('may not', $out, "ledger said: {$out}");
        $this->assertSame('paid_claimed', $this->stateOf($owner->trade_id));
    }

    public function test_the_state_machine_refuses_a_second_claim_with_a_fresh_key(): void
    {
        // The other half of idempotency. The same key twice is a replay and returns quietly; a
        // DIFFERENT key for the same move must be refused by the state machine, because the trade is no
        // longer open. Going through psql as the admin is the point: it is the only way to produce a
        // fresh key, which is exactly what a rewritten client or a bug elsewhere would do.
        $trader = $this->traderWithTrade();
        $this->post("/trade/{$trader->trade_id}/claim");

        $out = $this->rawCall(
            'select transition_trade(?, ?, ?, ?, ?)',
            [$trader->trade_id, 'open', 'paid_claimed', 'a-completely-different-key', $trader->email]
        );

        $this->assertStringContainsStringIgnoringCase('not in state', $out, "ledger said: {$out}");
        $this->assertSame(1, $this->eventCount($trader->trade_id, 'paid_claimed'));
    }

    public function test_a_trade_needs_a_signed_in_trader_to_claim(): void
    {
        $trader = $this->traderWithTrade();

        // The helper signs the trader in, because openAgainstUs insists on it. Signing out again is the
        // only honest way to test the unauthenticated path -- without it this test was asserting against
        // a session that was still there, and the claim went through.
        $this->post('/sign-out');

        $this->post("/trade/{$trader->trade_id}/claim")->assertRedirect(route('login'));

        $this->assertSame('open', $this->stateOf($trader->trade_id));
    }

    public function test_claiming_writes_the_claimant_into_the_permanent_record(): void
    {
        // The event names who claimed. A dispute six months later has to be able to say who said they
        // had paid, without asking anybody.
        $trader = $this->traderWithTrade();
        $this->post("/trade/{$trader->trade_id}/claim");

        $actor = \DB::connection('pgsql_trader')->selectOne(
            'select actor from trade_event where trade_id = ? and to_state = ? order by seq desc limit 1',
            [$trader->trade_id, 'paid_claimed']
        )->actor;

        $this->assertSame($trader->email, $actor);
    }

    public function test_the_claim_button_is_on_the_screen_while_the_trade_is_open(): void
    {
        $trader = $this->traderWithTrade();

        $this->get("/trade/{$trader->trade_id}")
            ->assertOk()
            ->assertSee('I have sent the cedi')
            ->assertSee('it is safe');
    }

    public function test_the_claim_button_is_gone_once_the_claim_is_in(): void
    {
        // A control that can only fail is worse than no control: the trader pays a round trip on a
        // metered connection to be told it was too late.
        $trader = $this->traderWithTrade();
        $this->post("/trade/{$trader->trade_id}/claim");

        $this->get("/trade/{$trader->trade_id}")
            ->assertOk()
            ->assertDontSee('I have sent the cedi')
            ->assertSee('do not need to do anything');
    }

    public function test_a_refusal_is_shown_to_the_trader_rather_than_a_crash(): void
    {
        // Claiming an already-claimed trade comes back as "someone already moved this", not a 500.
        $trader = $this->traderWithTrade();
        $this->post("/trade/{$trader->trade_id}/claim");

        $this->post("/trade/{$trader->trade_id}/claim")
            ->assertRedirect()
            ->assertSessionHas('notice');
    }

    public function test_the_screen_never_claims_the_money_can_be_released(): void
    {
        // The claim is a statement, not a settlement. The screen must not imply otherwise: "release" is
        // operations' word, and a trader reading it would think their money had moved.
        //
        // The assertion below moved from the literal phrase "we are the counterparty" to the MEANING
        // ("our loss") when the guarantee sentence moved into partials/assurance.blade.php. Asserting a
        // fixed string across a refactor is how a wording test quietly stops testing the thing it was
        // written for -- "our loss" is the part that carries the promise, and it is what must survive.
        $trader = $this->traderWithTrade();

        $this->get("/trade/{$trader->trade_id}")
            ->assertOk()
            ->assertSee('our loss')
            ->assertDontSee('release');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A signed-in trader with one open trade against KudiMarket.
     *
     * The sign-in is part of the setup, not an afterthought: openAgainstUs refuses to open a trade in
     * any name other than the signed-in trader's, which is why the first draft of this helper failed
     * all nine tests. That refusal is the feature working.
     */
    private function traderWithTrade(): object
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $trader->trade_id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        return $trader;
    }

    private function stateOf(int $tradeId): string
    {
        return \DB::connection('pgsql_trader')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }

    private function eventCount(int $tradeId, string $toState): int
    {
        return (int) \DB::connection('pgsql_trader')->selectOne(
            'select count(*) as n from trade_event where trade_id = ? and to_state = ?',
            [$tradeId, $toState]
        )->n;
    }

    /**
     * Call a stored procedure directly as the database owner, and return the refusal text.
     *
     * Used for the two things the application will not let us reach: a stranger's claim, and a fresh
     * idempotency key. Both need to bypass the service on purpose, so they are tested here rather than
     * wished for in a controller test.
     */
    private function rawCall(string $sql, array $bindings): string
    {
        try {
            \DB::connection('pgsql_admin')->select($sql, $bindings);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }
}

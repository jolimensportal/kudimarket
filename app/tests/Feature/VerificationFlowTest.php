<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Models\User;
use App\Services\OfferDesk;
use App\Services\VerificationDesk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * The whole door, end to end: a trader asks to be checked, operations decide, and the answer changes what
 * that trader may do.
 *
 * The last assertion is the one that matters. A verification flow that records a decision without
 * changing anybody's ability to trade is a queue, not a door -- and 009 already proved the gate existed
 * with nothing behind it.
 */
class VerificationFlowTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['NGN', 'GHS', '0.006756756756756756', 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_trader_who_has_never_asked_cannot_list_anything(): void
    {
        // The problem this whole file exists to fix. The gate was real; the door was not.
        $trader = $this->trader();

        $refused = '';
        try {
            app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertStringContainsString('need to be checked', $refused);
    }

    public function test_a_trader_can_apply_and_operations_can_check_them(): void
    {
        $trader = $this->trader();

        $this->actingAs($trader, 'trader')->post(route('trade.verification.store'), [
            'details' => 'Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.',
        ])->assertRedirect(route('trade.verification'));

        $this->assertSame('applied', $this->eventFor($trader->email));
        $this->assertFalse($this->isVerified($trader->email));

        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, true, null);

        $this->assertSame('verified', $this->eventFor($trader->email));
        $this->assertTrue($this->isVerified($trader->email));
    }

    public function test_being_checked_is_what_actually_unlocks_listing(): void
    {
        $trader = $this->trader();
        $desk = app(OfferDesk::class);

        // Before: refused, with the reason in words rather than a database code.
        try {
            $desk->publish('NGN', 'GHS', 50000000, '0.0060');
            $this->fail('an unchecked trader was allowed to list');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('need to be checked', $e->getMessage());
        }

        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');
        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, true, null);

        // After: the same call succeeds. Nothing else changed, and nothing else had to be switched on --
        // which is the point. The gate reads the record.
        $id = $desk->publish('NGN', 'GHS', 50000000, '0.0060');

        $this->assertGreaterThan(0, $id);
    }

    public function test_a_trader_cannot_decide_their_own_check(): void
    {
        $trader = $this->trader();
        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');

        $refused = false;
        try {
            DB::connection('pgsql_trader')->selectOne(
                'select decide_verification(?, ?, ?, ?) as id',
                [$trader->email, true, null, $trader->email]
            );
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a trader must not be able to check themselves');
        $this->assertSame('applied', $this->eventFor($trader->email), 'still waiting, not checked');
    }

    public function test_a_refusal_carries_the_reason_and_the_trader_sees_it(): void
    {
        $trader = $this->trader();
        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');

        $reason = 'Ghana Card number does not match the name on your account.';

        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, false, $reason);

        $this->assertSame('refused', $this->eventFor($trader->email));

        $page = $this->actingAs($trader, 'trader')->get(route('trade.verification'))->assertOk();

        // The whole reason this screen exists: somebody told "no" must be told WHICH no.
        $page->assertSee($reason);
        $page->assertSee('Fix it and ask again');
    }

    public function test_a_refusal_with_no_reason_is_refused(): void
    {
        $trader = $this->trader();
        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');

        $this->expectException(\RuntimeException::class);

        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, false, 'no');
    }

    public function test_a_refused_trader_can_apply_again_and_be_checked(): void
    {
        // The recovery path. A refusal that ends the process is not a decision, it is a rejection.
        $trader = $this->trader();
        $desk = app(VerificationDesk::class);

        $desk->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');
        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, false, 'Ghana Card does not match the account name.');

        $desk->apply('Adwoa Mensah. Ghana Card GHA-000000000-3-3 now matching my account. 12 Boundary Road Accra. MTN 0240000003 in my own name.');
        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, true, 're-checked, card now matches');

        $this->assertSame('verified', $this->eventFor($trader->email));

        $history = $desk->history($trader->email);
        $this->assertSame(['applied', 'refused', 'applied', 'verified'], array_map(fn ($h) => $h->event, $history));
    }

    public function test_an_application_needs_something_to_actually_check(): void
    {
        $trader = $this->trader();

        $this->actingAs($trader, 'trader')
            ->post(route('trade.verification.store'), ['details' => 'too short'])
            ->assertSessionHasErrors('details');

        $this->assertNull($this->eventFor($trader->email));
    }

    public function test_a_checked_trader_is_not_sent_back_to_the_form(): void
    {
        $trader = $this->trader();
        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');
        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, true, null);

        $this->actingAs($trader, 'trader')
            ->get(route('trade.verification.apply'))
            ->assertRedirect(route('trade.offer.position'));
    }

    public function test_the_decision_is_recorded_against_the_signed_in_staff_member(): void
    {
        // The console had a bug once where decisions recorded a hardcoded ops-1 instead of the real
        // identity. Asserted here so a return to that is caught here rather than in an audit.
        $trader = $this->trader();
        app(VerificationDesk::class)->apply('Adwoa Serwaa. Ghana Card GHA-000000000-1-1. 12 Boundary Road Accra. MTN 0240000001 in my own name. Funds from trading.');

        $this->signInAsOperator();
        app(VerificationDesk::class)->decide($trader->email, true, null);

        $history = app(VerificationDesk::class)->history($trader->email);
        $decision = end($history);

        $this->assertSame('ops-test@kudimarket.com', $decision->actor);
        $this->assertNotSame('ops-1', $decision->actor);
    }

    public function test_the_verification_chain_verifies_after_a_whole_day_of_deciding(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $trader = $this->trader("day-{$i}");
            $desk = app(VerificationDesk::class);
            $desk->apply("Trader {$i}, Ghana Card GHA-000000000-{$i}-1, 1 Test Road Accra, own-name MTN 024000000{$i}, funds from trading.");
            $this->signInAsOperator();
            app(VerificationDesk::class)->decide($trader->email, $i % 2 === 0, $i % 2 === 0 ? null : 'Card does not match the account name.');
        }

        $ok = DB::connection('pgsql_ops')->selectOne('select verify_verification_chain() as ok')->ok;
        $this->assertTrue($this->postgresTrue($ok));
    }

    // ---------------------------------------------------------------- helpers

    private function trader(string $label = 't'): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        $this->actingAs($trader, 'trader');

        return $trader;
    }

    /**
     * Sign the test client in as a staff member.
     *
     * Its own method because actingAs() returns $this -- writing ->decide() after it called the method on
     * the TEST CASE, which is a confusing way to get "Call to undefined method".
     */
    private function signInAsOperator(): void
    {
        $this->actingAs($this->operator());
    }

    /** A verified staff member, signed in on the ops guard the way the console signs in. */
    private function operator(): User
    {
        $user = User::firstOrNew(['email' => 'ops-test@kudimarket.com']);
        $user->name = 'Test Operator';
        $user->password = bcrypt('kudi-dev-only');
        $user->email_verified_at = now();
        $user->save();

        $this->actingAs($user, 'web');

        return $user;
    }

    private function eventFor(string $email): ?string
    {
        return DB::connection('pgsql_ops')->selectOne(
            'select event from verification_status where trader_email = ?', [$email]
        )->event ?? null;
    }

    private function isVerified(string $email): bool
    {
        $row = DB::connection('pgsql_ops')->selectOne(
            'select is_verified from verification_status where trader_email = ?', [$email]
        );

        return (bool) ($row->is_verified ?? false);
    }
}

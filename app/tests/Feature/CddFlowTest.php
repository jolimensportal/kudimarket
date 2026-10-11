<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\CddDesk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\InterpretsPostgresBooleans;
use Tests\TestCase;

/**
 * The CDD door, end to end: a trader gives their identity and where the money comes from, operations
 * decide, and the record survives as a chain.
 *
 * The separation assertion at the bottom is the point of the whole file. CDD and the Checked tier are
 * two different doors to two different rooms. This walks a trader through CDD and then tries to make
 * them list an offer, to prove that being established is an AML fact and NOT permission to trade. Only
 * the Checked tier opens the board, and it is a separate application with a separate door.
 */
class CddFlowTest extends TestCase
{
    use InterpretsPostgresBooleans;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');
    }

    private string $email = '';

    public function test_a_trader_with_no_cdd_has_no_status(): void
    {
        $this->trader();

        $this->get('/trade/id')->assertOk()
            ->assertSee('Give us these details');
    }

    public function test_a_trader_submits_their_details_and_they_are_pending_not_verified(): void
    {
        $this->trader();

        $this->post('/trade/id/new', $this->validPayload())->assertRedirect(route('trade.cdd.show'));

        $status = (new CddDesk)->status();

        $this->assertNotNull($status);
        $this->assertTrue($this->postgresTrue($status->is_cdd_pending), 'a fresh submission is pending');
        $this->assertFalse($this->postgresTrue($status->is_cdd_verified), 'pending is not the same as established');
    }

    public function test_the_aml_rule_is_enforced_before_anything_is_saved(): void
    {
        $this->trader();

        // Source of funds is mandatory under the guideline. It must be refused, and refused with words a
        // trader can act on, not a column name.
        $payload = $this->validPayload();
        unset($payload['source_of_funds']);

        $this->from('/trade/id/new')
            ->post('/trade/id/new', $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('source_of_funds');
    }

    public function test_operations_establishes_a_customer_and_the_structured_facts_survive(): void
    {
        $this->trader();
        $this->post('/trade/id/new', $this->validPayload());

        app(CddDesk::class)->decide($this->email, true, false, 'ops@kudimarket.com', 'Ghana Card matched the NIA record');

        $status = (new CddDesk)->status();

        $this->assertTrue($this->postgresTrue($status->is_cdd_verified));
        // The facts are columns, not prose: a CTR is assembled by selecting these, not by reading prose.
        $this->assertSame('GHA-000999888-1-1', $status->doc_number);
        $this->assertSame('business', $status->purpose_code);
        $this->assertStringContainsString('Shop takings', (string) $status->source_of_funds);
    }

    public function test_recording_a_biometric_check_does_not_unverify_the_customer(): void
    {
        $this->trader();
        $this->post('/trade/id/new', $this->validPayload());
        app(CddDesk::class)->decide($this->email, true, false, 'ops@kudimarket.com', 'matched');

        app(CddDesk::class)->recordBiometric($this->email, 'ops@kudimarket.com', 'in person at the counter');

        $status = (new CddDesk)->status();
        $this->assertTrue($this->postgresTrue($status->is_cdd_verified), 'a biometric record is evidence, not a new state that un-verifies the customer');
        $this->assertTrue($this->postgresTrue($status->biometric_on_record));
    }

    public function test_the_biometric_threshold_escalates_when_the_risk_cannot_be_priced(): void
    {
        $this->trader();
        $desk = app(CddDesk::class);

        // With no USD reference rate set, the threshold cannot be computed, because the biometric rule is
        // quoted in USD. Every transaction therefore returns 'unknown' and the service collapses it to
        // "required": a missing rate must never let ANY transaction through unverified. This is the
        // fail-safe, and it is why isBiometricRequired() cannot return false until Finance has set a rate.
        $this->assertTrue($desk->isBiometricRequired('GHS', 20_000_000), 'a large transaction escalates when unpriceable');
        $this->assertTrue($desk->isBiometricRequired('GHS', 100_000), 'and so does a small one -- unknown is not "below threshold"');

        // CTR is different: GHS is the threshold currency, so it is judged on its face with no rate needed.
        $this->assertTrue($desk->isReportable('GHS', 20_000_000), 'GHS 200,000 is reportable');
        $this->assertFalse($desk->isReportable('GHS', 100_000), 'GHS 1,000 is not reportable');

        // Once a rate exists, the biometric threshold becomes decidable and a small trade is let through.
        DB::connection('pgsql_ops')->selectOne(
            'select set_usd_reference(?, ?, ?, ?) as x',
            ['GHS', '0.13', 'finance@kudimarket.com', 'test rate']
        );
        $this->assertFalse($desk->isBiometricRequired('GHS', 100_000), 'with a rate, a small trade needs no biometric check');
        $this->assertTrue($desk->isBiometricRequired('GHS', 8_000_000), 'and a large one still does');
    }

    public function test_being_established_does_not_let_a_trader_list_an_offer(): void
    {
        // The separation, walked end to end. CDD is an AML fact; it is NOT permission to trade. This is
        // the assertion that keeps "Checked" from ever being presented as an AML control.
        $this->trader();
        $this->post('/trade/id/new', $this->validPayload());
        app(CddDesk::class)->decide($this->email, true, false, 'ops@kudimarket.com', 'matched');

        $this->assertTrue($this->postgresTrue((new CddDesk)->status()->is_cdd_verified));

        $this->post('/trade/sell/new', [
            'have_asset' => 'NGN',
            'want_asset' => 'GHS',
            'have_amount' => '1000',
            'min_rate' => '0.006',
        ])->assertSessionHasErrors();
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'doc_type' => 'ghana_card',
            'doc_number' => 'GHA-000999888-1-1',
            'residential_address' => '5 Station Road, Tema',
            'purpose_code' => 'business',
            'purpose_detail' => 'Trading cedi and naira for my shop.',
            'source_of_funds' => 'Shop takings, my own MTN number.',
        ];
    }

    private function trader(): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('cdd-%s@example.com', bin2hex(random_bytes(4))),
        ]);
        $this->email = $trader->email;

        $this->actingAs($trader, 'trader');

        return $trader;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TradeScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The sign-in throttle is shared state keyed by ip+email, so a test that signs in twice -- or
        // a suite that runs after one that did -- trips it for the next test and fails for a reason
        // that has nothing to do with the screen under test.
        RateLimiter::clear('127.0.0.1');
    }

    public function test_the_screen_names_the_counterparty_and_both_amounts(): void
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('KudiMarket')
            // The naira leg: 200000 minor units read through the asset table's decimals.
            ->assertSee('2,000.00 NGN')
            // The cedi leg is DERIVED from the rate, not a second stored amount:
            // 2,000.00 / 148.20 = 13.4953, which rounds to 13.50. Getting this wrong by a factor of
            // a hundred -- minor units divided by a major-per-major rate -- is the failure mode here.
            ->assertSee('13.50 GHS')
            ->assertSee('MTN Mobile Money');
    }

    public function test_the_two_sides_are_different_numbers(): void
    {
        // The one that matters. A cedi buyer sending the naira figure would be short by 148x, and the
        // screen is the only place they read that number from.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $body = $this->get("/trade/{$id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('2,000.00 GHS', $body, 'the naira figure must not be labelled cedi');
    }

    public function test_it_says_plainly_that_disputing_does_not_move_money(): void
    {
        // The rule the brand constitution records: a locked control must say why it is locked, and must not
        // promise an outcome the system does not produce. This assertion used to pin "You can dispute
        // until the claim window closes", which was simply untrue: the window gates release, not disputes,
        // and disputes carry no time limit. It now pins the sentence that is true.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        // An OPEN trade cannot be disputed: there is no trade_rule row from open to disputed, so the ledger
        // would refuse it. This card used to say "You can report a problem while we hold the money" and
        // offer no link -- which is the worst of both, a promise the ledger does not keep AND no way to act
        // on it. It now says plainly that nothing has been sent yet, and offers nothing, because that is
        // the truth.
        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('Nothing has been sent yet')
            ->assertDontSee('Report a problem</a>');

        $this->post("/trade/{$id}/claim");

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('Report a problem')
            ->assertSee('does not move your money');
    }

    public function test_it_never_says_a_trade_settles_by_itself(): void
    {
        // paid_attested -> released has actor 'system'. There was no scheduler for it, and this test is
        // why the copy never promised it: telling a trader it will settle on its own is how they come back
        // believing their money is in flight when it is sitting in an escrow row nobody has touched.
        //
        // A sweep now exists (kudi-core/013, trades:release-due, every minute), but it waits out the claim
        // window and the copy does not name an instant it cannot keep. What must never come back is a
        // sentence asserting that something happens by itself with no window and no operator involved.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertDontSee('settles on its own');
    }

    public function test_it_shows_the_write_once_event_record(): void
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertSee('no state yet')
            ->assertSee('open')
            ->assertSee('platform');
    }

    public function test_a_trader_cannot_open_someone_elses_trade(): void
    {
        $owner = Trader::factory()->create();
        $this->actingAs($owner, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($owner->email, 200000, 'NGN');

        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get("/trade/{$id}")
            ->assertForbidden();
    }

    public function test_a_missing_trade_is_not_found_rather_than_forbidden(): void
    {
        // 404 for a trade that does not exist, 403 for one that belongs to somebody else. Merging them
        // would either leak which ids are real, or make a typo look like an attack.
        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get('/trade/999999')
            ->assertNotFound();
    }

    public function test_the_screen_needs_a_signed_in_trader(): void
    {
        $owner = Trader::factory()->create();
        $this->actingAs($owner, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($owner->email, 200000, 'NGN');
        $this->post('/sign-out');

        $this->get("/trade/{$id}")->assertRedirect(route('login'));
    }

    public function test_the_screen_offers_no_settlement_button_before_payment(): void
    {
        // The claim button belongs to Task 6. Until it exists, the screen must not imply a trader can
        // settle a trade they have not paid for.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertDontSee('Send proof');
    }
}

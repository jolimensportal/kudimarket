<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\OfferDesk;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use App\Services\VerificationDesk;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Raising a dispute, which is the one moment where a trader's word IS the evidence.
 *
 * The plan for this task tested only that the state became `disputed` and that the event named the
 * trader. Both passed while the reason was being thrown away, so the suite would have been green over a
 * dispute that recorded that somebody complained without recording the complaint.
 */
class DisputeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        \DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', 148.2, 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_buyer_can_raise_a_dispute_after_claiming(): void
    {
        $trader = $this->claimed();

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'The naira has not arrived at all.'])
            ->assertRedirect("/trade/{$trader->trade_id}");

        $this->assertSame('disputed', $this->stateOf($trader->trade_id));
    }

    public function test_the_traders_own_words_are_kept(): void
    {
        // THE assertion. A dispute that does not record what was said is not a dispute: nobody can
        // assess it, nobody can answer it, and the trader has no evidence anything happened.
        $trader = $this->claimed();
        $reason = 'I sent the cedi at 14:02 from my own MTN number and nothing has arrived.';

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => $reason]);

        $stored = \DB::connection('pgsql_trader')->selectOne(
            'select narrative from trade_event where trade_id = ? and to_state = ?',
            [$trader->trade_id, 'disputed']
        )->narrative;

        $this->assertSame($reason, $stored);
    }

    public function test_the_words_are_inside_the_hash_chain(): void
    {
        // Stored is not enough. If the narrative can be edited without breaking the chain it is a
        // comment, not evidence, and the whole design of this ledger is that nothing can be edited.
        $trader = $this->claimed();
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'The naira never arrived.']);

        $this->assertTrue(
            (bool) \DB::connection('pgsql_trader')->selectOne('select verify_trade_chain() as ok')->ok,
            'the chain verifies before any tampering'
        );

        $this->rewriteTheNarrative($trader->trade_id, 'They simply did not pay', function (): void {
            // Asserted INSIDE the tamper window. The first version put this assertion after the helper
            // returned -- but the helper restores in a finally, so by the time this ran the narrative was
            // already back to the trader's words and the chain correctly verified. The test was
            // asserting that a repair worked, and reporting it as evidence that tampering is undetected.
            $this->assertFalse(
                (bool) \DB::connection('pgsql_trader')->selectOne('select verify_trade_chain() as ok')->ok,
                "editing the trader's words must break the chain, or the narrative is just a comment"
            );
        });

        $this->assertTrue(
            (bool) \DB::connection('pgsql_trader')->selectOne('select verify_trade_chain() as ok')->ok,
            'and restoring the trader\'s own words restores the chain'
        );
    }

    public function test_the_trader_role_cannot_resolve_a_dispute(): void
    {
        // Not TradeLedger::resolve(): that runs on the ops connection by design, because resolving is
        // operations' job. The invariant is that the TRADER's own role is refused by the database, which
        // is what stops a route added later from adjudicating a trade.
        $trader = $this->claimed();
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'Nothing has arrived at all.']);

        $refused = false;

        try {
            \DB::connection('pgsql_trader')->selectOne(
                'select transition_trade(?, ?, ?, ?, ?) as ok',
                [$trader->trade_id, 'disputed', 'resolved_refund', 'trader:'.$trader->trade_id.':resolve', $trader->email]
            );
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertTrue($refused, 'the trader role must not be able to resolve a dispute');
        $this->assertSame('disputed', $this->stateOf($trader->trade_id));
    }

    public function test_the_record_names_who_raised_it(): void
    {
        $trader = $this->claimed();
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'Nothing arrived.']);

        $event = \DB::connection('pgsql_trader')->selectOne(
            'select to_state, actor from trade_event where trade_id = ? order by seq desc limit 1',
            [$trader->trade_id]
        );

        $this->assertSame('disputed', $event->to_state);
        $this->assertSame($trader->email, $event->actor);
    }

    public function test_a_seller_can_report_a_problem_on_their_own_trade(): void
    {
        /**
         * The same bug as the seller being locked out of the trade screen, in a second place.
         *
         * TradeController owned a private copy of the party check and DisputeController owned another. The
         * first was fixed -- a seller was getting "That trade belongs to somebody else" on a trade they are
         * named on. The second was not, so a seller could still not report a problem.
         *
         * And a seller is frequently the party who needs to: on a vendor trade they are the recipient, and
         * the whole of the Checked tier rests on nobody else being able to say the money reached them. A
         * rule that let only the buyer complain is a rule that lets the wrong person be robbed quietly.
         */
        $offerDesk = app(OfferDesk::class);
        $seller = $this->checkedTrader('seller-disputes');
        $offerId = $offerDesk->publish('NGN', 'GHS', 20000000, '0.0060');

        $buyer = $this->checkedTrader('buyer-for-seller-dispute');
        $tradeId = $offerDesk->accept($offerId);

        // Both sides must be able to post the vendor deposit before the buyer can claim, or the claim is
        // refused for want of funds and never reaches paid_claimed.
        $this->fund($seller->email);
        $this->fund($buyer->email);

        // The BUYER sends the naira and claims; the SELLER is the party who has not been paid.
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $this->actingAs($seller, 'trader')
            ->get("/trade/{$tradeId}/dispute")
            ->assertOk();

        $this->actingAs($seller, 'trader')
            ->post("/trade/{$tradeId}/dispute", ['reason' => 'The naira has not reached my account.'])
            ->assertRedirect();

        $this->assertSame('disputed', $this->stateOf($tradeId));
    }

    public function test_a_trade_that_is_attested_still_offers_a_way_to_report_a_problem(): void
    {
        /**
         * The state a trader reaches when the payment has been attested and the money still has not
         * arrived -- and the one state where disputing is legal and the screen said nothing about it.
         *
         * The card said "you can report a problem" in five states and linked it in one. paid_attested is
         * the state where somebody most needs to complain and could not find the link.
         */
        \DB::connection('pgsql_admin')->statement("update trade_policy set claim_window = '0 seconds'");
        $buyer = Trader::factory()->create();
        $this->actingAs($buyer, 'trader');
        $tradeId = app(TradeDesk::class)->openAgainstUs($buyer->email, 200000, 'NGN');
        $this->post("/trade/{$tradeId}/claim");
        app(TradeLedger::class)->attest($tradeId, 'ops@kudimarket.com');

        $this->assertSame('paid_attested', $this->stateOf($tradeId));

        $screen = $this->actingAs($buyer, 'trader')->get("/trade/{$tradeId}")->assertOk()->getContent();

        $this->assertStringContainsString('Report a problem', $screen,
            'an attested, unreleased trade must still offer a way to complain');
        $this->assertStringContainsString('/dispute', $screen, 'and the link must be a real one');
    }

    public function test_a_dispute_needs_something_written(): void
    {
        $trader = $this->claimed();

        // Whitespace only. Laravel's TrimStrings turns this into an empty string, so 'required' refuses
        // it -- which is the right outcome, because a dispute with nothing in it tells nobody anything.
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id));
    }

    public function test_a_dispute_needs_more_than_a_single_word(): void
    {
        $trader = $this->claimed();

        // Ten characters minimum. "lost" is a feeling, not something an operator can investigate.
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'lost'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id));
    }

    public function test_a_trader_cannot_resolve_their_own_dispute(): void
    {
        $trader = $this->claimed();
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'Nothing has arrived.']);

        // 404, not 403: there is no trader route to resolve a dispute at all. The capability is absent
        // rather than present-and-refused, which is the stronger of the two.
        $this->post("/trade/{$trader->trade_id}/resolve")->assertNotFound();

        $this->assertSame('disputed', $this->stateOf($trader->trade_id));
    }

    public function test_a_stranger_cannot_dispute_someone_elses_trade(): void
    {
        $owner = $this->claimed();

        $this->actingAs(Trader::factory()->create(), 'trader')
            ->post("/trade/{$owner->trade_id}/dispute", ['reason' => 'I am not the payer here.'])
            ->assertForbidden();

        $this->assertSame('paid_claimed', $this->stateOf($owner->trade_id));
    }

    public function test_a_trade_cannot_be_disputed_before_payment_is_claimed(): void
    {
        // Disputing "I sent nothing" is not a thing. A dispute means the money was sent and did not
        // arrive, and claiming first is what says so.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->post("/trade/{$id}/dispute", ['reason' => 'I am claiming something untrue.']);

        $this->assertSame('open', $this->stateOf($id));
    }

    public function test_a_dispute_needs_a_signed_in_trader(): void
    {
        $trader = $this->claimed();
        $this->post('/sign-out');

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'Nothing has arrived at all.'])
            ->assertRedirect(route('login'));

        $this->assertSame('paid_claimed', $this->stateOf($trader->trade_id));
    }

    public function test_disputing_twice_does_not_overwrite_the_first_account(): void
    {
        // A trader who disputes, then thinks of something better, must not be able to rewrite what they
        // first said. The first statement is the one that was made under stress.
        $trader = $this->claimed();
        $first = 'I sent the cedi at 14:02 and nothing has arrived.';

        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => $first]);
        $this->post("/trade/{$trader->trade_id}/dispute", ['reason' => 'Actually I sent it to the wrong number.']);

        $stored = \DB::connection('pgsql_trader')->select(
            'select narrative from trade_event where trade_id = ? and to_state = ?',
            [$trader->trade_id, 'disputed']
        );

        $this->assertCount(1, $stored, 'a trade is disputed once, not repeatedly');
        $this->assertSame($first, $stored[0]->narrative);
    }

    public function test_the_dispute_screen_warns_about_what_not_to_write(): void
    {
        // The narrative is read by operations and handed to a regulator. Telling a trader to keep
        // secrets out of it is part of the feature, not a disclaimer.
        $trader = $this->claimed();

        $this->get("/trade/{$trader->trade_id}/dispute")
            ->assertOk()
            ->assertSee('PIN')
            ->assertSee('Both sides');
    }

    public function test_the_dispute_screen_does_not_promise_the_money_comes_back(): void
    {
        // The plan's button said "Send the cedi back to me". Filing a dispute moves no money; it asks
        // somebody else to look. A label that says otherwise is how a trader comes back believing their
        // cedi is on its way when it is sitting in an escrow row nobody has touched.
        $trader = $this->claimed();

        $this->get("/trade/{$trader->trade_id}/dispute")
            ->assertOk()
            ->assertDontSee('Send the cedi back to me')
            ->assertSee('does not move your money');
    }

    // ---------------------------------------------------------------- helpers

    /** A signed-in trader with a trade that has been paid and claimed. */
    private function claimed(): object
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $trader->trade_id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');
        $this->post("/trade/{$trader->trade_id}/claim");

        return $trader;
    }

    /**
     * Rewrite a dispute narrative as the database owner, the way an attacker or a careless operator would.
     *
     * Every caller restores the original in a finally block. This database is shared between tests and
     * SURVIVES between runs, so a tamper left behind by a test that failed mid-way breaks every
     * chain assertion that comes after it, in this run and in the next one. It happened: an earlier
     * version of this file left "They simply did not pay" sitting in trade 3, and every later
     * verify_trade_chain() in the suite returned false for a reason that had nothing to do with the
     * code under test.
     */
    private function rewriteTheNarrative(int $tradeId, string $tampered, callable $whileTampered): void
    {
        $original = \DB::connection('pgsql_admin')->selectOne(
            'select narrative from trade_event where trade_id = ? and to_state = ?',
            [$tradeId, 'disputed']
        )->narrative;

        // Both the trigger and the row are restored, and the trigger is restored FIRST.
        //
        // The trigger half matters more than it looks. kudi_test is shared between tests and survives
        // between runs, so disabling trade_event_append_only and not putting it back left every later
        // test -- and every later run -- writing to the event log with append-only protection switched
        // off, silently. Nothing failed. That is the worst kind of test damage: the failure is invisible
        // and permanent. Read the trigger state back and assert on it rather than trusting this file.
        \DB::connection('pgsql_admin')->statement('alter table trade_event disable trigger trade_event_append_only');

        try {
            \DB::connection('pgsql_admin')->update(
                "update trade_event set narrative = ? where trade_id = ? and to_state = 'disputed'",
                [$tampered, $tradeId]
            );

            $whileTampered();
        } finally {
            \DB::connection('pgsql_admin')->update(
                "update trade_event set narrative = ? where trade_id = ? and to_state = 'disputed'",
                [$original, $tradeId]
            );
            \DB::connection('pgsql_admin')->statement('alter table trade_event enable trigger trade_event_append_only');

            $enabled = \DB::connection('pgsql_admin')->selectOne(
                "select tgenabled from pg_trigger where tgrelid = 'trade_event'::regclass and tgname = 'trade_event_append_only'"
            )->tgenabled;

            // 'A' is the default, which is also enabled (fires on insert and update). Only 'D' and 'R'
            // mean disabled, and a test that leaves the chain writable has corrupted every later run.
            $this->assertNotSame('D', $enabled, 'the append-only trigger was left disabled');
            $this->assertNotSame('R', $enabled, 'the append-only trigger was left disabled');
        }
    }

    /**
     * A checked trader, because publishing a vendor offer requires one.
     *
     * Applied through the real service rather than by writing verified_at, so a fixture cannot drift from
     * what the gate actually reads.
     */
    /**
     * Just enough cedi to post a vendor deposit, minted on the operator identity because kudi_ops cannot
     * mint and the suite asserts that on purpose.
     */
    private function fund(string $email): void
    {
        \DB::connection('pgsql_admin')->selectOne(
            'select ledger_deposit(?, ?, ?, ?) as id',
            [$email, 'GHS', 33784, 'fund-'.bin2hex(random_bytes(6))]
        );
    }

    private function checkedTrader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);
        app(VerificationDesk::class)->apply(
            'Ghana Card GHA-000000000-1-1. 12 Boundary Road, Accra. Own-name MTN 0240000001. Funds from trading.'
        );
        \DB::connection('pgsql_ops')->selectOne(
            'select decide_verification(?, ?, ?, ?) as ok',
            [$trader->email, true, null, 'ops@kudimarket.com']
        );

        return $trader;
    }

    private function stateOf(int $tradeId): string
    {
        return \DB::connection('pgsql_trader')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }
}

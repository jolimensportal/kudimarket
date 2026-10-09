<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
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

    private function stateOf(int $tradeId): string
    {
        return \DB::connection('pgsql_trader')->selectOne('select state from trade where id = ?', [$tradeId])->state;
    }
}

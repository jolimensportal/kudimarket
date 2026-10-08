<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\RateBoard;
use Tests\TestCase;

class BoardTest extends TestCase
{
    public function test_the_board_shows_our_rate_and_names_who_set_it(): void
    {
        $this->publishRate();

        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get('/trade/board')
            ->assertOk()
            ->assertSee('148.20')
            ->assertSee('pricing@kudimarket.com');
    }

    public function test_the_board_is_read_only_and_writes_nothing(): void
    {
        $this->publishRate();
        $before = \DB::connection('pgsql')->selectOne('select count(*) as n from rate_card')->n;

        $this->actingAs(Trader::factory()->create(), 'trader')->get('/trade/board')->assertOk();

        $after = \DB::connection('pgsql')->selectOne('select count(*) as n from rate_card')->n;

        $this->assertSame((int) $before, (int) $after, 'viewing the board must not write a rate');
    }

    public function test_the_board_refuses_to_render_without_a_rate(): void
    {
        // No rate published for this pair yet. The screen must say so rather than show a zero or a
        // blank, because a trader who sees a blank number will assume the pair is unavailable and leave.
        //
        // Asserted by reading the rate_card directly rather than assuming the database is empty. The
        // suite shares one scratch database, so another test file may already have published GHS/NGN;
        // this test is about the *unpublished* path, so it uses a pair nothing has touched.
        $rows = \DB::connection('pgsql')->selectOne(
            "select count(*) as n from rate_card where base_asset = 'USD' and quote_asset = 'NGN'"
        );

        $this->assertSame(0, (int) $rows->n, 'nothing should have published this pair');

        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get('/trade/board?from=USD&to=NGN')
            ->assertOk()
            ->assertSee('We have not published a rate for this pair yet');
    }

    public function test_a_trader_must_be_signed_in_to_see_the_board(): void
    {
        $this->get('/trade/board')->assertRedirect();
    }

    public function test_the_board_offers_both_directions_of_the_pair(): void
    {
        $this->publishRate();

        $response = $this->actingAs(Trader::factory()->create(), 'trader')->get('/trade/board');

        $response->assertOk();
        // Asserted against what the formatter actually produces, not a hand-rounded figure. The point
        // is that the reverse leg is on the page at all: an earlier query matched `base = ? or
        // quote = ?`, which found GHS/NGN and missed NGN/GHS entirely.
        $response->assertSee('GHS &rarr; NGN', escape: false);
        $response->assertSee('NGN &rarr; GHS', escape: false);
        $response->assertSee('148.20', escape: false);
        $response->assertSee(RateBoard::formatRate(0.006756756756756756), escape: false);
    }

    public function test_a_rate_is_formatted_with_a_floor_and_not_a_cap(): void
    {
        // 148.2 and 148.20 are the same price, so the floor keeps the trailing zero. The reverse leg
        // needs six places to mean anything, so the floor must not also be a width -- a capped version
        // of this rendered the whole naira-to-cedi leg as 0.00.
        $this->assertSame('148.20', RateBoard::formatRate(148.2));
        $this->assertSame('0.0067567568', RateBoard::formatRate(0.006756756756756756));
        $this->assertSame('0.50', RateBoard::formatRate(0.5));
        $this->assertSame('1.00', RateBoard::formatRate(1.0));
    }

    /**
     * Publishes both directions of the pair as the pricing role, because that is the only role that
     * can. Going through set_rate() rather than inserting into the table is the point: a test that
     * seeds the card behind the same door the product uses cannot pass while the door is shut.
     */
    private function publishRate(): void
    {
        // Through the pricing connection, because that is the only role that can set a rate. NOT with
        // `set role` on a shared connection: SET ROLE is session state, so it would leave every
        // later query on that connection running as pricing -- which is how this file's own trader
        // factory started failing with "permission denied for table trader".
        $pricing = \DB::connection('pgsql_pricing');

        $pricing->select("select set_rate('GHS','NGN',148.20,'pricing@kudimarket.com')");
        $pricing->select("select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com')");
    }
}

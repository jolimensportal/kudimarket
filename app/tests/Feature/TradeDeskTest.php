<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\RateBoard;
use App\Services\TradeDesk;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;

/**
 * The first money path.
 *
 * Every assertion here checks something the ledger did, not something the application believes. The
 * application holds no balances and no state transitions, so a passing test means the database moved.
 */
class TradeDeskTest extends TestCase
{
    public function test_opening_a_trade_records_the_agreed_rate_and_us_as_the_seller(): void
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $row = \DB::connection('pgsql_trader')->selectOne('select * from trade_view where id = ?', [$id]);

        $this->assertSame('open', $row->state);
        $this->assertSame('platform', $row->seller, 'KudiMarket is the other side, so we are the seller');
        $this->assertSame($trader->email, $row->buyer);
        $this->assertSame(148.2, (float) $row->rate);
        $this->assertSame(200000, (int) $row->fiat_amount);
        $this->assertSame('NGN', $row->fiat_asset);
        $this->assertSame('platform_inventory', $row->trade_type);
    }

    public function test_the_trade_is_born_with_a_chained_creation_event(): void
    {
        // This is the 005 anchor. A trade with no event is a trade whose state nothing vouches for, so
        // the very first row of the chain is part of opening a trade, not an afterthought.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        $events = \DB::connection('pgsql_trader')->select(
            'select seq, from_state, to_state, actor from trade_event where trade_id = ? order by seq',
            [$id]
        );

        $this->assertCount(1, $events);
        $this->assertSame(1, (int) $events[0]->seq);
        $this->assertSame('none', $events[0]->from_state);
        $this->assertSame('open', $events[0]->to_state);
        $this->assertSame('platform', $events[0]->actor);
    }

    public function test_opening_a_trade_moves_no_money(): void
    {
        // Both parties have paid nothing yet. Asserted because "no posting" is a claim, and the row
        // count is the only thing that makes it true rather than likely.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);

        // Read as the admin connection, and that is the point: a trader cannot read `posting` at all, which
        // is why the assertions about what money did not move cannot be made from the trader's own
        // session. Proving "nothing moved" from a role that is structurally unable to see movement is
        // the strongest form of that assertion available.
        $postings = (int) \DB::connection('pgsql_admin')->selectOne(
            'select count(*) as n from posting p join ledger_tx x on x.id = p.tx_id where x.trade_id = ?',
            [$id]
        )->n;

        $this->assertSame(0, $postings, 'opening a trade must not move value');
        $this->assertSame(
            0,
            (int) \DB::connection('pgsql_admin')->selectOne('select count(*) as n from check_balances()')->n
        );
    }

    public function test_the_application_cannot_write_the_trade_row_itself(): void
    {
        // The whole design rests on there being one way in. If the app could INSERT into trade, the
        // state machine would be a suggestion.
        $out = $this->raw("insert into trade(seller, buyer, asset, amount) values ('platform','x','GHS',1)");

        $this->assertStringContainsString('permission denied', $out);
    }

    public function test_a_zero_or_negative_amount_is_refused_before_the_ledger_is_asked(): void
    {
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');
        $desk = app(TradeDesk::class);

        $this->expectException(RuntimeException::class);
        $desk->openAgainstUs($trader->email, 0, 0.0);
    }

    public function test_the_amount_is_stored_in_minor_units(): void
    {
        // 200000 minor units is 2,000.00. If this ever reads back as 200000 major units a trader pays
        // a hundred thousand times too much, so the conversion is asserted from both ends.
        $trader = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 200000.0);
        $row = \DB::connection('pgsql_trader')->selectOne('select fiat_amount, fiat_asset from trade where id = ?', [$id]);

        $this->assertSame(200000, (int) $row->fiat_amount);
        $this->assertSame(2, RateBoard::decimalsFor('NGN'));
        $this->assertSame('2,000.00', number_format((int) $row->fiat_amount / 100, 2));
    }

    public function test_a_trader_cannot_open_a_trade_in_someone_elses_name(): void
    {
        // The constructor takes the counterparty as an argument, so nothing stops a caller passing
        // somebody else's address. The service refuses to.
        $trader = Trader::factory()->create();
        $victim = Trader::factory()->create();
        $this->actingAs($trader, 'trader');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/in your own name/i');

        app(TradeDesk::class)->openAgainstUs($victim->email, 200000, 200000.0);
    }

    private function raw(string $sql): string
    {
        try {
            \DB::connection('pgsql_trader')->unprepared($sql);
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }
}

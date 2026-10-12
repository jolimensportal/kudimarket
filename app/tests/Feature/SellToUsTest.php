<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeDesk;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Selling currency TO KudiMarket: the other half of the handled tier.
 *
 * openAgainstUs could only ever move money out, so the float could not be built and the tier the whole
 * business rests on was half a bureau de change. REGULATORY.md 5b records why that is not a feature gap:
 * a licensed DEALER may buy and sell for own account, an authorised FX BROKER may not take the other side
 * at all. We cannot honestly apply for either with one side of the book.
 *
 * The property this file exists to pin is ONE test. On a buy, attestation is structurally out of the
 * trader's reach, because the confirmer is the seller and the trader is the buyer. Reverse the roles and
 * that stops being structural, so it has to be stated in the rules and asserted here: the trader DECLARES
 * that they sent the money, and only operations CONFIRM it arrived. A trader who can confirm their own
 * payment can settle a trade they never paid for.
 */
final class SellToUsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', '148.2', 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_trader_can_sell_currency_to_us(): void
    {
        $trader = $this->signedIn('sell-one');
        $id = app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');

        $trade = DB::connection('pgsql_ops')->selectOne('select * from trade_view where id = ?', [$id]);

        $this->assertSame('platform_sell', $trade->trade_type);
        $this->assertSame($trader->email, $trade->seller, 'the trader is the one selling');
        $this->assertSame('platform', $trade->buyer, 'KudiMarket is the one buying');
        $this->assertSame('20000000', (string) $trade->fiat_amount);
    }

    public function test_the_sell_screen_shows_the_price_before_the_trader_commits(): void
    {
        $trader = $this->signedIn('sell-quote');
        app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');

        $this->actingAs($trader, 'trader')->get('/trade/sell-to-us')->assertOk();
    }

    public function test_the_trader_declares_that_they_sent_it(): void
    {
        $trader = $this->signedIn('sell-declare');
        $id = app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');

        $this->post("/trade/{$id}/claim")->assertRedirect();

        $this->assertSame('paid_claimed', $this->stateOf($id));
    }

    public function test_the_trader_may_not_confirm_their_own_payment(): void
    {
        /**
         * THE test. Mirroring platform_inventory instead of adding a tier was tried, and verified to let
         * this through: that tier's rule reads "the seller confirms", and on a sell the seller is the
         * payer. The trade reached paid_attested on the payer's own word.
         *
         * This is refused by the ledger, not by a hidden field or a controller check, so no screen, no
         * console and no future code path can talk it round.
         */
        $trader = $this->signedIn('sell-self-attest');
        $id = app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');
        $this->post("/trade/{$id}/claim");

        // Through the LEDGER, not through a route. There is deliberately no attest endpoint for a trader,
        // so a screen-level test would pass on a 404 and prove nothing about the property that matters.
        // This asks the question the only way that cannot be answered by a missing page: may the payer
        // attest that their own money arrived?
        $refused = false;
        try {
            DB::connection('pgsql_trader')->selectOne(
                "select transition_trade(?, 'paid_claimed', 'paid_attested', ?, ?) as ok",
                [$id, 'self-attest-'.$id, $trader->email]
            );
        } catch (\Throwable $e) {
            $refused = str_contains($e->getMessage(), 'ops only')
                || str_contains($e->getMessage(), 'permission denied')
                || str_contains($e->getMessage(), 'may not do');
        }

        $this->assertTrue($refused, 'the ledger must refuse a payer confirming their own payment');
        $this->assertSame('paid_claimed', $this->stateOf($id), 'the money is still not confirmed');
    }

    public function test_operations_confirm_the_money_and_the_trade_settles(): void
    {
        $trader = $this->signedIn('sell-settle');
        $id = app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');
        $this->post("/trade/{$id}/claim");

        app(TradeLedger::class)->attest($id, 'ops@kudimarket.com');
        $this->assertSame('paid_attested', $this->stateOf($id));

        DB::connection('pgsql_admin')->statement(
            "update trade_policy set claim_window = '0 seconds' where trade_type = 'platform_sell'"
        );
        app(TradeLedger::class)->release($id, 'ops@kudimarket.com');

        $this->assertSame('released', $this->stateOf($id), 'a sell settles like a buy');
    }

    public function test_the_trade_screen_reads_the_other_side_correctly(): void
    {
        // The assurance card keys off seller === 'platform'. On a sell that is false, so the screen must
        // not tell a trader who is selling us currency that "another verified trader is the other side".
        $trader = $this->signedIn('sell-screen');
        $id = app(TradeDesk::class)->sellToUs($trader->email, 20000000, 'NGN');

        $screen = $this->actingAs($trader, 'trader')->get("/trade/{$id}")->assertOk()->getContent();

        $this->assertStringContainsString('KudiMarket handled', $screen);
        $this->assertStringNotContainsString('Another verified trader is the other side', $screen);
    }

    private function stateOf(int $tradeId): string
    {
        return (string) DB::connection('pgsql_ops')
            ->selectOne('select state from trade where id = ?', [$tradeId])
            ->state;
    }

    private function signedIn(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }
}

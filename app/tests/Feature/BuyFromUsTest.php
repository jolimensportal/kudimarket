<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Buying currency from KudiMarket's own book.
 *
 * brand/product.html: "KudiMarket buys currency from traders and sells to other traders out of its own
 * float", and "Which pairs do we quote? Cedi against naira, BOTH DIRECTIONS."
 *
 * openAgainstUs existed and was tested before any of this, and nothing in the product called it. A function
 * that only tests can reach is the same as a function that does not exist, and this is the tier we actually
 * guarantee -- the one where our money is at risk and delivery is ours.
 */
final class BuyFromUsTest extends TestCase
{
    public function test_a_trader_can_buy_naira_with_cedi(): void
    {
        $trader = $this->trader('buy-naira');

        $this->get('/trade/buy')->assertOk();

        $this->post('/trade/buy', [
            'receive_asset' => 'NGN',
            'receive_amount' => '500000',
        ])->assertRedirect();

        $trade = DB::connection('pgsql_trader')->selectOne(
            "select * from trade where buyer = ? and trade_type = 'platform_inventory' order by id desc limit 1",
            [$trader->email]
        );

        $this->assertNotNull($trade, 'the trade was opened');
        $this->assertSame('platform', $trade->seller, 'KudiMarket is the counterparty');
        $this->assertSame('open', $trade->state);

        // Cedi in, naira out. N500,000 at 148.2 naira per cedi is C3,373.82.
        $this->assertSame('50000000', $trade->fiat_amount);
        $this->assertSame('NGN', $trade->fiat_asset);
        $this->assertSame('GHS', $trade->rate_base_asset, 'the base is what the buyer pays');
        $this->assertSame('NGN', $trade->rate_quote_asset, 'the quote is what the buyer receives');

        $figures = TradeFigures::forTrade($trade, 'buyer');
        $this->assertSame('3,373.82 GHS', $figures['send'], 'they pay cedi');
        $this->assertSame('500,000.00 NGN', $figures['receive'], 'and receive naira');
    }

    public function test_a_trader_can_buy_cedi_with_naira(): void
    {
        /**
         * The other direction, which is the half that did not exist.
         *
         * openAgainstUs used to hardcode a GHS/NGN pair, so a trader could buy naira with cedi and nothing
         * else -- half of what brand/product.html says we sell. Getting the pair wrong here is not a display
         * quirk: the trade screen derives both legs from it, so a wrong pair is a wrong amount.
         */
        $trader = $this->trader('buy-cedi');

        $this->post('/trade/buy', [
            'receive_asset' => 'GHS',
            'receive_amount' => '33.78',
        ])->assertRedirect();

        $trade = DB::connection('pgsql_trader')->selectOne(
            "select * from trade where buyer = ? and trade_type = 'platform_inventory' order by id desc limit 1",
            [$trader->email]
        );

        $this->assertSame('GHS', $trade->fiat_asset, 'the stored leg is the cedi they receive');
        $this->assertSame('NGN', $trade->rate_base_asset, 'they pay naira');
        $this->assertSame('GHS', $trade->rate_quote_asset, 'and receive cedi');

        $figures = TradeFigures::forTrade($trade, 'buyer');
        // C33.78 at 0.0067567 cedi per naira is N4,999.44 -- which is the same money as the first test's
        // N5,000, one rounding step away. Neither figure is derivable from the other without knowing which
        // end of the pair the rate counts in, which is the whole of kudi-core/011 and 012.
        $this->assertSame('4,999.44 NGN', $figures['send'], 'they pay naira');
        $this->assertSame('33.78 GHS', $figures['receive'], 'and receive cedi');
    }

    public function test_the_buy_screen_shows_the_price_before_the_trader_commits(): void
    {
        $this->trader('buy-quote');

        $screen = $this->flat($this->get('/trade/buy')->assertOk()->getContent());

        // Both legs, named with their currency, before anything is submitted.
        $this->assertStringContainsString('You pay', $screen);
        $this->assertStringContainsString('You receive', $screen);
        $this->assertStringContainsString('GHS', $screen);
        $this->assertStringContainsString('NGN', $screen);
    }

    public function test_the_buy_screen_is_closed_to_anybody_not_signed_in(): void
    {
        // There is no field for a trader's name anywhere on this form. The controller reads it from the
        // session and TradeDesk::openAgainstUs refuses anything else, so a trade cannot be opened in
        // somebody else's name -- the original question this replaces, answered by the absence of the
        // parameter rather than by a second check.
        Auth::guard('trader')->logout();
        $this->post('/sign-out');

        $this->get('/trade/buy')->assertRedirect(route('login'));
        $this->post('/trade/buy', ['receive_asset' => 'NGN', 'receive_amount' => '1000'])
            ->assertRedirect(route('login'));
    }

    public function test_a_currency_we_do_not_hold_is_refused_by_name(): void
    {
        $this->trader('buy-dollar');

        // brand/product.html: "Dollars, pounds, euros, the CFA franc and the Chinese yuan are all vendor
        // supply from day one, and we never hold them." So this must not become a trade, and the message
        // has to say why rather than failing on an unknown asset three layers down.
        $this->actingAs($this->trader('buy-dollar-b'), 'trader')
            ->post('/trade/buy', ['receive_asset' => 'USD', 'receive_amount' => '1000'])
            ->assertSessionHasErrors('receive_asset');
    }

    public function test_a_nonsense_amount_is_refused_rather_than_becoming_a_trade(): void
    {
        $this->trader('buy-nonsense');

        foreach (['-100', '1e5', '12,000', 'abc', ''] as $bad) {
            $this->actingAs($this->trader('buy-n-'.md5($bad)), 'trader')
                ->post('/trade/buy', ['receive_asset' => 'NGN', 'receive_amount' => $bad])
                ->assertSessionHasErrors('receive_amount');
        }
    }

    public function test_the_bought_trade_completes_through_claim_and_attest(): void
    {
        /**
         * The screen existing is not the feature. The trade has to be walkable, and it walks a different
         * path from a vendor one: there is no crypto leg to escrow, so it goes open -> paid_claimed ->
         * paid_attested -> released, and the attest is OURS because we are the seller.
         */
        $trader = $this->trader('buy-complete');
        $this->post('/trade/buy', ['receive_asset' => 'NGN', 'receive_amount' => '5000']);

        $tradeId = (int) DB::connection('pgsql_trader')->selectOne(
            "select id from trade where buyer = ? and state = 'open' order by id desc limit 1",
            [$trader->email]
        )->id;

        $this->actingAs($trader, 'trader')->post("/trade/{$tradeId}/claim")->assertRedirect();
        $this->assertSame('paid_claimed', app(TradeLedger::class)->trade($tradeId)->state);

        // We are the seller, so the attest is ours: a trader cannot confirm their own payment, and on our
        // own book nobody else can either.
        app(TradeLedger::class)->attest($tradeId, 'ops@kudimarket.com');
        $this->assertSame('paid_attested', app(TradeLedger::class)->trade($tradeId)->state);

        // Release waits out the claim window. Our book receives fiat by mobile money and is exposed to
        // reversals, so the window is the reason a buyer has time to say the money never arrived. Pinned
        // because it is a real guarantee and not a delay in the UI.
        $this->expectException(\RuntimeException::class);
        try {
            app(TradeLedger::class)->release($tradeId, 'ops@kudimarket.com');
        } finally {
            DB::connection('pgsql_ops')->statement("update trade_policy set claim_window = '0 seconds'");
            app(TradeLedger::class)->release($tradeId, 'ops@kudimarket.com');
            DB::connection('pgsql_ops')->statement(
                "update trade_policy set claim_window = '15 minutes' where trade_type = 'platform_inventory'"
            );
        }

        $this->assertSame('released', app(TradeLedger::class)->trade($tradeId)->state);
    }

    private function flat(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html) ?? $html;

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES)) ?? $text);
    }

    private function trader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }
}

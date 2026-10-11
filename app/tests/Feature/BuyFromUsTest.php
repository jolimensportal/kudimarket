<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\RateBoard;
use App\Services\TradeDesk;
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

    public function test_a_buyer_on_our_book_is_told_the_reference_and_the_receipt_rule(): void
    {
        /**
         * Buying from us is the KudiMarket handled tier, and the locked reference for that screen
         * ("Use your own name as the reference. A payment from a number that is not registered to you is a
         * payment we cannot give you a receipt for.") is the whole point of it: the payer-name check is
         * how we match the payment, so the trader has to KNOW their name is the reference before they make
         * it. The seller branch of this same screen has had that rule; the handled-buyer branch dropped it.
         */
        $trader = $this->trader('handled-ref');
        $this->post('/trade/buy', [
            'receive_asset' => 'NGN',
            'receive_amount' => '5000',
        ]);

        $tradeId = (int) DB::connection('pgsql_trader')->selectOne(
            "select id from trade where buyer = ? and state = 'open' order by id desc limit 1",
            [$trader->email]
        )->id;

        $screen = $this->flat(
            $this->actingAs($trader, 'trader')->get("/trade/{$tradeId}")->assertOk()->getContent()
        );

        $this->assertStringContainsString(
            'Use your own name as the reference',
            $screen,
            'the buyer is told their own name is the reference'
        );
        $this->assertStringContainsString(
            'A payment from a number that is not registered to you is a payment we cannot give you a receipt for',
            $screen,
            'the handled-buyer branch carries the mockup\'s receipt rule, like the seller branch does'
        );
    }

    public function test_a_buyer_on_our_book_is_shown_the_commission_they_will_pay(): void
    {
        // The fee row the mockup draws. It has to be the amount the ledger froze, in the currency the
        // buyer is handing over, and it has to be visible BEFORE they commit -- not a surprise on the
        // settlement screen, which is how a fee becomes a complaint.
        DB::connection('pgsql_admin')->statement("update trade_policy set fee_bps = 100 where trade_type = 'platform_inventory'");
        $trader = $this->trader('fee-buyer');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $screen = $this->get("/trade/{$id}")->assertOk()->getContent();
        $flat = $this->flat($screen);

        // 200000 NGN at 148.2 => base leg div(200000, 148.2) = 1349 minor units of cedi. 1% = 13.
        $frozen = DB::connection('pgsql_ops')->selectOne('select fee_minor_units, fee_asset, fee_bearer from trade_view where id = ?', [$id]);
        $this->assertSame('GHS', $frozen->fee_asset, 'the commission is in the currency the buyer pays');
        $this->assertGreaterThan(0, (int) $frozen->fee_minor_units, 'a configured commission freezes a real amount');

        $this->assertStringContainsString('KudiMarket fee', $flat);
        $this->assertStringContainsString(
            RateBoard::formatMoney((int) $frozen->fee_minor_units, 'GHS'),
            $flat,
            'the fee on the screen is the fee the ledger will charge, not a number typed beside it'
        );

        // ONE number to type. "Send 13.50 plus the fee" is how a mobile-money payment arrives a few
        // pesewas short, and a short payment cannot settle -- so the total is computed in whole minor
        // units and stated, not left as an addition for a trader to do on a phone.
        $figures = TradeFigures::forTrade(
            DB::connection('pgsql_ops')->selectOne('select * from trade_view where id = ?', [$id]),
            'buyer'
        );
        $this->assertSame(
            $figures['send_minor'] + (int) $frozen->fee_minor_units,
            $figures['send_total_minor'],
            'the total is exactly the send leg plus the commission, in minor units'
        );
        $this->assertStringContainsString(
            'Send '.$figures['send_total'].' in total',
            $flat,
            'the instruction names the whole amount, so nobody has to add it up'
        );
    }

    public function test_no_commission_configured_means_no_fee_row(): void
    {
        // The default is zero, and a row reading "KudiMarket fee 0.00" on every trade is a row nobody
        // learns to read. If we are not charging, the screen says nothing.
        DB::connection('pgsql_admin')->statement("update trade_policy set fee_bps = 0 where trade_type = 'platform_inventory'");
        $trader = $this->trader('no-fee-buyer');
        $id = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->get("/trade/{$id}")
            ->assertOk()
            ->assertDontSee('KudiMarket fee');
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

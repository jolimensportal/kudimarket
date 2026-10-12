<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Models\User;
use App\Services\OfferDesk;
use App\Services\TradeDesk;
use App\Services\VerificationDesk;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The list a trader reads to find a trade they left.
 *
 * "Where you stand" already counted a trader's trades and never named them, so once a trade screen was
 * left by any route other than the back button there was nothing to return to. This screen is that list,
 * and the assertion that matters most is the one about WHOSE trades it shows: every trader connects as
 * one login role, so the list is only safe because it comes from my_trades() (kudi-core/019), which
 * answers from the session identity and takes no email. A test that only checked "a trade appears"
 * would pass with an email read out of a query string -- which is the vulnerability 015 exists to close.
 */
class TradeHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        // Both directions of the one pair the card carries. publish/accept price a vendor-to-vendor trade
        // from the card and openAgainstUs derives the cedi leg from it, so without these the screens under
        // test cannot be built at all.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['NGN', 'GHS', '0.006756756756756756', 'rates-desk@kudimarket.com']
        );
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', '148.2', 'rates-desk@kudimarket.com']
        );
    }

    public function test_a_trader_sees_a_trade_they_are_a_party_to_and_a_stranger_does_not(): void
    {
        // A vendor-to-vendor trade has two parties. Both must find it; a third trader must not.
        $seller = $this->signIn();
        $offerId = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');

        $buyer = $this->anotherTrader('buyer');
        $tradeId = app(OfferDesk::class)->accept($offerId);

        $url = route('trade.show', $tradeId);
        $marker = 'Trade #'.$tradeId;

        $this->actingAs($seller, 'trader')->get('/trade/history')
            ->assertOk()
            ->assertSee($marker)
            ->assertSee($url);

        $this->actingAs($buyer, 'trader')->get('/trade/history')
            ->assertOk()
            ->assertSee($marker)
            ->assertSee($url);

        // The control. A trader who is party to nothing must not see a trade they are not in, and must be
        // told the truth about why the list is empty rather than shown a blank page.
        $stranger = $this->anotherTrader('stranger');

        $this->actingAs($stranger, 'trader')->get('/trade/history')
            ->assertOk()
            ->assertDontSee($url)
            ->assertDontSee($marker)
            ->assertSee('You have not opened a trade yet');
    }

    public function test_the_history_shows_the_figures_for_this_traders_side_not_the_sellers(): void
    {
        // The direction bug a template defaulting to the seller would reintroduce: the trader receiving
        // naira must read the naira as what they RECEIVE and the cedi as what they SEND. The figures come
        // from TradeFigures::forTrade() for this trader's role, computed in the controller, never in Blade.
        $trader = $this->signIn();
        $tradeId = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->actingAs($trader, 'trader')->get('/trade/history')
            ->assertOk()
            ->assertSee('You send')
            ->assertSee('You receive')
            // The naira leg is what they get; the cedi leg is what they give, derived from the card
            // (2,000.00 / 148.20 = 13.50). Getting this by a factor of a hundred is the failure mode.
            ->assertSee('2,000.00 NGN')
            ->assertSee('13.50 GHS');
    }

    public function test_the_state_reads_in_words_not_ledger_codes(): void
    {
        // paid_attested and resolved_release are the ledger's vocabulary. A trader reading their own
        // history should read a sentence, not a code -- and the raw code must not leak into the page.
        $trader = $this->signIn();
        $tradeId = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $this->actingAs($trader, 'trader')->get('/trade/history')
            ->assertOk()
            ->assertSee('Waiting for the money')
            ->assertDontSee('paid_attested');
    }

    public function test_it_links_every_trade_and_names_the_other_side(): void
    {
        $seller = $this->signIn();

        // The handled trade first, while the guard is still the seller: openAgainstUs refuses to open a
        // trade in a name other than the signed-in one, and creating the buyer below moves the guard.
        $handledId = app(TradeDesk::class)->openAgainstUs($seller->email, 200000, 'NGN');

        $offerId = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');
        $buyer = $this->anotherTrader('buyer');
        $tradeId = app(OfferDesk::class)->accept($offerId);

        $this->actingAs($seller, 'trader')->get('/trade/history')
            ->assertOk()
            // Both trades are the seller's, and each links to its own screen.
            ->assertSee(route('trade.show', $tradeId))
            ->assertSee(route('trade.show', $handledId))
            // The peer trade names the other trader; the handled one names us. The rule is by party, not
            // by "is the seller", because on a handled buy KudiMarket is the seller.
            ->assertSee($buyer->email)
            ->assertSee('KudiMarket');
    }

    public function test_the_history_requires_a_signed_in_trader(): void
    {
        Auth::guard('trader')->logout();

        $this->get('/trade/history')->assertRedirect(route('login'));
    }

    public function test_every_screen_a_trader_lands_on_links_back_to_the_history(): void
    {
        // A list nobody can find is not a way back to a trade. Each screen a trader actually stands on --
        // the board they land on, the position screen that counts their trades, a trade itself -- names it.
        $trader = $this->signIn();
        $tradeId = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        $history = route('trade.history');

        $this->actingAs($trader, 'trader');
        $this->get(route('trade.board'))->assertOk()->assertSee($history);
        $this->get(route('trade.offer.position'))->assertOk()->assertSee($history);
        $this->get(route('trade.show', $tradeId))->assertOk()->assertSee($history);
    }

    public function test_my_trades_returns_nothing_when_no_identity_is_set(): void
    {
        // The database control, tested at the database. With no session identity, my_trades() has no
        // trader to answer about and returns nothing -- there is no argument to fall back on. This is
        // the same door my_balances() and my_trade_counts() close.
        DB::connection('pgsql_trader')->selectOne('select kudi_sign_out()');

        $count = (int) DB::connection('pgsql_trader')
            ->selectOne('select count(*) as c from my_trades()')->c;

        $this->assertSame(0, $count, 'no session identity must mean no trades, never somebody else\'s');
    }

    /**
     * A verified trader, signed in on the trader guard.
     *
     * Verification runs the real check, because the counterparty gate reads the verification CHAIN and no
     * longer trusts a column: a trader made a party by the test harness alone would be a party no real
     * path can create.
     */
    private function signIn(): Trader
    {
        $trader = Trader::factory()->create();
        $this->check($trader);

        return $trader;
    }

    /**
     * A trader whose email has not been used in this run.
     *
     * The scratch database survives between runs, so a fixed address collides on the second run and the
     * failure reads as a product bug rather than a test that assumes it is alone.
     */
    private function anotherTrader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);
        $this->check($trader);

        return $trader;
    }

    private function check(Trader $trader): void
    {
        // Sign the trader in first: apply_for_verification reads the trader guard and refuses to apply on
        // anybody's behalf, which is the service working as intended.
        Auth::guard('trader')->setUser($trader);

        $desk = VerificationDesk::class;
        app($desk)->apply('Checked in tests. Ghana Card GHA-000000000-9-9, 1 Test Road Accra, own-name MTN 0240000009, funds from trading.');

        $staff = User::firstOrNew(['email' => 'ops-fixtures@kudimarket.com']);
        $staff->name = 'Fixtures';
        $staff->password = bcrypt('kudi-dev-only');
        $staff->email_verified_at = now();
        $staff->save();

        // Two guards, two identities, and the order matters: deciding signs the staff member in.
        $this->actingAs($staff, 'web');
        app($desk)->decide($trader->email, true, null);

        // Back to the trader, who the caller is about to act as.
        Auth::guard('trader')->setUser($trader);
    }
}

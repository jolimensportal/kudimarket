<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\OfferDesk;
use App\Services\VerificationDesk;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The balance screen, and the thing that made it possible.
 *
 * "Where you stand" carried, for months of this build, a comment explaining why it showed no "you hold"
 * figure: every trader shared one database login, so the database could not tell them apart, and reading a
 * balance meant either exposing everybody's or depending on the application never passing the wrong name.
 *
 * That reason is gone. kudi-core/015 puts the identity in the database, set from the session once per
 * request, and my_balances() takes no trader argument at all -- there is no name in the query to get
 * wrong, and kudi_trader still has no grant on account.
 *
 * These tests are mostly about the second half of that: that it holds.
 */
final class TraderBalanceTest extends TestCase
{
    public function test_a_trader_sees_their_own_balance_and_nobody_elses(): void
    {
        [$me, $them] = $this->twoFundedTraders();

        $this->assertSame(
            ['50000'],
            $this->balancesFor($me, 'GHS'),
            'a trader sees exactly what they hold',
        );
    }

    public function test_two_traders_never_see_each_other(): void
    {
        [$me, $them] = $this->twoFundedTraders();

        // Funded differently on purpose, so a swapped identity cannot pass by being symmetric.
        $this->assertSame(['50000'], $this->balancesFor($me, 'GHS'));
        $this->assertSame(['90000'], $this->balancesFor($them, 'GHS'));
    }

    public function test_the_balance_screen_renders_the_signed_in_traders_own_figure(): void
    {
        [$me, $them] = $this->twoFundedTraders();

        $screen = $this->flat($this->actingAs($me, 'trader')->get('/trade/sell')->assertOk()->getContent());

        $this->assertStringContainsString('What you hold', $screen);
        $this->assertStringContainsString('500.00', $screen, 'their own balance');
        $this->assertStringNotContainsString('900.00', $screen, 'and nobody else\'s');
    }

    public function test_the_database_connection_still_cannot_read_any_balance_directly(): void
    {
        // The control underneath the screen. A function with no argument can only be safe if there is no
        // table to go around it with.
        $this->expectException(QueryException::class);

        DB::connection('pgsql_trader')->select('select balance from account');
    }

    public function test_a_balance_function_takes_no_trader_argument_to_get_wrong(): void
    {
        // The shape that made the old version impossible to trust. If a trader argument ever appears here
        // again, the caller is choosing whose money to see and this is the test that says so.
        $this->expectException(QueryException::class);

        DB::connection('pgsql_trader')->select('select * from my_balances(?)', ['someone-else@example.com']);
    }

    public function test_money_being_held_for_a_trade_is_shown_apart_from_what_they_can_use(): void
    {
        // "You hold" and "we are holding for you" are different facts. Merging them produces a number that
        // is not a balance and looks like one.
        // Both checked, because publishing an offer requires it -- applied through the real service rather
        // than by writing verified_at, so the fixture cannot drift from what the gate actually reads.
        $seller = $this->checkedTrader('balance-escrow-seller');
        $buyer = $this->checkedTrader('balance-escrow-buyer');
        $this->actingAs($seller, 'trader');

        $offerId = app(OfferDesk::class)->publish('NGN', 'GHS', 50000000, '0.0060');
        $this->fund($seller->email);
        $this->fund($buyer->email);

        $this->actingAs($buyer, 'trader');
        $tradeId = app(OfferDesk::class)->accept($offerId);
        $this->actingAs($buyer, 'trader')->post("/trade/{$tradeId}/claim");

        $held = $this->actingAs($seller, 'trader')->get('/trade/sell')->assertOk()->getContent();

        $this->assertStringContainsString(
            'held for a trade in progress',
            $this->flat($held),
            'escrowed deposits are visible and labelled as held',
        );
    }

    /**
     * @return array{Trader, Trader} two traders with different balances
     */
    private function checkedTrader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);
        app(VerificationDesk::class)->apply(
            'Ghana Card GHA-000000000-1-1. 12 Boundary Road, Accra. Own-name MTN 0240000001. Funds from trading.'
        );
        DB::connection('pgsql_ops')->selectOne(
            'select decide_verification(?, ?, ?, ?) as ok',
            [$trader->email, true, null, 'ops@kudimarket.com']
        );
        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    private function twoFundedTraders(): array
    {
        // Random suffixes, like every other fixture here: the scratch database survives between runs, so
        // a fixed email is a duplicate the second time this test is executed.
        $me = Trader::factory()->create([
            'email' => 'balance-me-'.bin2hex(random_bytes(4)).'@example.com',
        ]);
        $them = Trader::factory()->create([
            'email' => 'balance-them-'.bin2hex(random_bytes(4)).'@example.com',
        ]);

        $this->fund($me->email, 50000);
        $this->fund($them->email, 90000);

        return [$me, $them];
    }

    /** @return list<string> the minor-unit balances the named trader is shown */
    private function balancesFor(Trader $trader, string $asset): array
    {
        // Two statements, two calls: PDO refuses more than one command in a prepared statement, and the
        // error is "cannot insert multiple commands into a prepared statement" rather than anything about
        // money.
        $db = DB::connection('pgsql_trader');
        $db->select('select kudi_sign_in(?)', [$trader->email]);

        return array_values(array_map(
            static fn ($row): string => (string) $row->available,
            $db->select('select available from my_balances() where asset = ?', [$asset])
        ));
    }

    /** Minted on the operator identity, because kudi_ops cannot mint and the suite asserts that on purpose. */
    private function fund(string $email, int $minorUnits = 33784): void
    {
        DB::connection('pgsql_admin')->selectOne(
            'select ledger_deposit(?, ?, ?, ?) as id',
            [$email, 'GHS', $minorUnits, 'fund-'.bin2hex(random_bytes(6))]
        );
    }

    private function flat(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html) ?? $html;

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES)) ?? $text);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PricingStaff;
use App\Models\User;
use App\Services\RateDesk;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publishing our own rate.
 *
 * "KudiMarket quotes its own rates" is the central claim in brand/product.html, and until this the price
 * of every trade was 148.2 hardcoded in scripts/reset-dev-database.sh. Nothing in the product could move
 * it.
 *
 * THE CONSTRAINT, which is why this is a separate desk and not a page on the operations console.
 * kudi-core/006 lists it as a property of the rate card:
 *
 *   "kudi_pricing, separate from kudi_ops. Ops resolve disputes; pricing sets our price. If one account
 *    could do both, a compromised desk account could reprice the book and then judge its own trades.
 *    Segregation of duties, enforced in the database rather than in a policy document."
 *
 * Adding a rate button to the operations console would have deleted the third property. So it is not one,
 * and these tests pin both directions of the separation rather than assuming it.
 */
final class RateCardScreenTest extends TestCase
{
    public function test_pricing_desk_can_publish_a_rate_and_the_trade_prices_from_it(): void
    {
        $this->actingAs($this->pricingStaff(), 'web')->get('/pricing/rates')->assertOk();

        $staff = $this->pricingStaff();
        $this->publish(['base_asset' => 'NGN', 'quote_asset' => 'GHS', 'rate' => '0.00675'], $staff->email);

        // And the number that matters: the price the product now quotes.
        $published = DB::connection('pgsql_ops')->selectOne(
            "select rate from current_rate where base_asset = 'NGN' and quote_asset = 'GHS'"
        );

        $this->assertNotNull($published);
        $this->assertEqualsWithDelta(0.00675, (float) $published->rate, 0.0000001);
        $this->assertSame(
            $staff->email,
            DB::connection('pgsql_ops')->selectOne(
                "select set_by from rate_card where base_asset='NGN' and quote_asset='GHS' order by id desc limit 1"
            )->set_by,
            'the rate records who set it, because a price with nobody\'s name on it cannot be defended'
        );
    }

    public function test_operations_cannot_reach_the_pricing_desk(): void
    {
        // The direction that matters most: operations resolve disputes. An operations account that could
        // also move the price could reprice the book and then judge its own trades.
        $ops = $this->operator();

        $this->actingAs($ops, 'web')->get('/pricing')->assertForbidden();
        $this->actingAs($ops, 'web')->get('/pricing/rates')->assertForbidden();
    }

    public function test_pricing_cannot_reach_the_operations_console(): void
    {
        // The other direction. A pricing desk that could attest a payment or resolve a dispute could
        // approve its own price by calling its own work correct.
        $staff = $this->pricingStaff();

        $this->actingAs($staff, 'web')->get('/admin')->assertForbidden();
        $this->actingAs($staff, 'web')->get('/admin/trades')->assertForbidden();
    }

    public function test_one_person_cannot_hold_both_desks(): void
    {
        // Both rows on one address: the operations console must go, because that is where attesting and
        // resolving live. Their own desk stays, and that is the right outcome rather than a compromise --
        // refusing both would make the split unusable without stopping anyone, since the same person
        // could reach /pricing under another account. What must not be possible is ONE person both moving
        // the price and judging the trades.
        $staff = $this->pricingStaff();

        $this->assertDatabaseHas('users', ['email' => $staff->email]);
        $this->assertDatabaseHas('pricing_staff', ['email' => $staff->email]);

        // Direct, so a failure names the rule rather than a route.
        $this->assertTrue(PricingStaff::holds($staff->email), 'the pricing row is visible to the app role');
        $this->assertFalse(
            $staff->fresh()->canAccessPanel(Filament::getPanel('admin')),
            'a pricing desk may not use the operations console'
        );

        $this->actingAs($staff->fresh(), 'web')->get('/admin')->assertForbidden();

        // And they can still do their job, which is the point of the split rather than an inconvenience.
        $this->actingAs($staff->fresh(), 'web')->get('/pricing/rates')->assertOk();
    }

    public function test_the_database_refuses_set_rate_to_the_operations_role(): void
    {
        /**
         * The control itself, not the screens.
         *
         * 006 says the segregation is "enforced in the database rather than in a policy document", so this
         * is the assertion that keeps that sentence true. A Filament page is a convenience; kudi_ops not
         * being able to call set_rate is the control. If somebody bolts a rate button onto the ops console
         * next year, the database says no regardless of what the screen offers.
         */
        $this->expectException(QueryException::class);

        DB::connection('pgsql_ops')->selectOne('select set_rate(?, ?, ?, ?)', ['NGN', 'GHS', 0.006, 'ops@kudimarket.com']);
    }

    public function test_a_rate_that_already_passed_is_never_rewritten(): void
    {
        /**
         * Append-only. Publishing 0.00675 must not touch the 0.0067567 that came before it, because "what
         * was the rate when I was filled" has to survive.
         */
        $before = DB::connection('pgsql_ops')->selectOne(
            "select count(*) from rate_card where base_asset = 'NGN' and quote_asset = 'GHS'"
        )->count;

        $this->publish(['base_asset' => 'NGN', 'quote_asset' => 'GHS', 'rate' => '0.00670']);

        $rows = DB::connection('pgsql_ops')->select(
            "select rate from rate_card where base_asset = 'NGN' and quote_asset = 'GHS' order by id"
        );

        $this->assertCount((int) $before + 1, $rows, 'a row was added, not swapped');
        $this->assertEqualsWithDelta(0.00670, (float) end($rows)->rate, 0.0000001);
    }

    public function test_the_rate_chain_still_verifies_after_publishing_from_a_screen(): void
    {
        $this->publish(['base_asset' => 'GHS', 'quote_asset' => 'NGN', 'rate' => '148.5']);

        // A BOOLEAN, not the 't' psql prints for one. Asserting the string was my error, not the code's.
        $this->assertTrue(
            (bool) DB::connection('pgsql_ops')->selectOne('select rate_chain_ok from integrity_report')->rate_chain_ok,
            'a price published through a screen commits to the same chain as one published by SQL'
        );
    }

    public function test_a_rate_must_be_positive_and_the_pair_must_differ(): void
    {
        $this->actingAs($this->pricingStaff(), 'web');

        // Each refused. A rate of zero, a negative rate, or a pair of the same currency is a price that
        // means nothing, and set_rate refuses all three -- with the ledger's own words, because a pricing
        // desk needs to know what to fix.
        foreach ([['NGN', 'NGN', '148'], ['NGN', 'GHS', '0'], ['NGN', 'GHS', '-1']] as [$base, $quote, $rate]) {
            $before = (int) DB::connection('pgsql_ops')->selectOne('select count(*) from rate_card')->count;

            try {
                $this->publish(['base_asset' => $base, 'quote_asset' => $quote, 'rate' => $rate]);
                $this->fail("set_rate accepted {$base}/{$quote} at {$rate}");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), 'and it says why');
            }

            $this->assertSame(
                $before,
                (int) DB::connection('pgsql_ops')->selectOne('select count(*) from rate_card')->count,
                'and wrote nothing'
            );
        }
    }

    public function test_backdating_is_refused_from_a_screen_too(): void
    {
        // 006's fourth property. Without it, anybody with set_rate could insert a rate that was "live" at
        // the moment of a trade they had just filled badly.
        $before = (int) DB::connection('pgsql_ops')->selectOne(
            "select count(*) from rate_card where base_asset = 'GHS' and quote_asset = 'NGN'"
        )->count;

        $before = (int) DB::connection('pgsql_ops')->selectOne(
            "select count(*) from rate_card where base_asset = 'GHS' and quote_asset = 'NGN'"
        )->count;

        try {
            $this->publish([
                'base_asset' => 'GHS',
                'quote_asset' => 'NGN',
                'rate' => '150',
                'effective_from' => now()->subDay()->toDateTimeString(),
            ]);
            $this->fail('a rate from yesterday was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('backdating refused', $e->getMessage());
        }

        $this->assertSame(
            $before,
            (int) DB::connection('pgsql_ops')->selectOne(
                "select count(*) from rate_card where base_asset = 'GHS' and quote_asset = 'NGN'"
            )->count,
            'nothing was written: the ledger refuses the row, so the screen has nothing to show'
        );
    }

    /**
     * Publish the way the screen does.
     *
     * The screen is a Filament component, so driving it here means Livewire::test() -- and Filament resets
     * the current panel on a Livewire synthetic request, so the component renders with the ADMIN panel's
     * route names and dies on "filament.admin.resources.rates.index not defined" no matter how the panel is
     * set beforehand. Rather than fight the framework's request lifecycle, the RULES are asserted against
     * the same call the screen makes -- RateDesk::publish, which holds no SQL of its own and calls
     * set_rate() -- and the SCREEN is verified in a browser.
     *
     * That split is honest about what is covered. What it does not prove is that the button is wired to the
     * call; the browser pass is what proves that.
     */
    private function publish(array $data, ?string $actor = null): ?int
    {
        $staff = $actor === null ? $this->pricingStaff() : null;

        return app(RateDesk::class)->publish(
            base: (string) $data['base_asset'],
            quote: (string) $data['quote_asset'],
            rate: (string) $data['rate'],
            actor: $actor ?? $staff->email,
            effectiveFrom: $data['effective_from'] ?? null,
            note: $data['note'] ?? null,
        )->id;
    }

    private function pricingStaff(): User
    {
        $email = 'pricing-'.bin2hex(random_bytes(4)).'@kudimarket.com';

        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Pricing Desk';
        $user->password = bcrypt('kudi-dev-only');
        $user->email_verified_at = now();
        $user->save();

        PricingStaff::firstOrCreate(['email' => $email], ['name' => 'Pricing Desk']);

        return $user;
    }

    private function operator(): User
    {
        $email = 'ops-'.bin2hex(random_bytes(4)).'@kudimarket.com';

        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Operations';
        $user->password = bcrypt('kudi-dev-only');
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }
}

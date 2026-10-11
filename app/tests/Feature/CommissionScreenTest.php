<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PricingStaff;
use App\Models\Trader;
use App\Models\User;
use App\Services\CommissionDesk;
use App\Services\TradeDesk;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Setting what we charge, from the pricing desk.
 *
 * The commission is our price, so it lives beside the rate card and behind the same segregation. This
 * file exists to pin two things that a screen can quietly undo:
 *
 *   - WHO may change it. Operations resolve disputes, and a disputed question is very often the fee. An
 *     operations console that could also set the commission would let one account decide how much we
 *     take and then judge the trades it took it on — the property 006 exists to keep, which 017 repeats.
 *   - WHEN it takes effect. The fee is frozen onto each trade when the trade is opened, so changing the
 *     setting changes the NEXT trade and nothing already agreed. A test that does not prove this will
 *     happily let a pricing desk believe it has repriced the open book.
 */
final class CommissionScreenTest extends TestCase
{
    public function test_the_pricing_desk_reaches_the_commission_screen(): void
    {
        $this->actingAs($this->pricingStaff(), 'web')->get('/pricing/commissions')->assertOk();
    }

    public function test_operations_cannot_reach_the_commission_screen(): void
    {
        $user = User::firstOrNew(['email' => 'ops-'.bin2hex(random_bytes(4)).'@kudimarket.com']);
        $user->name = 'Operations';
        $user->password = bcrypt('kudi-dev-only');
        $user->email_verified_at = now();
        $user->save();

        $this->actingAs($user, 'web')->get('/pricing/commissions')->assertForbidden();
    }

    public function test_setting_a_commission_records_it_and_the_chain_verifies(): void
    {
        $staff = $this->pricingStaff();
        $this->actingAs($staff, 'web');

        app(CommissionDesk::class)->set('platform_inventory', 250, 'buyer', $staff->email, 'a test');

        $policy = DB::connection('pgsql_ops')->selectOne(
            "select fee_bps, fee_bearer from trade_policy where trade_type = 'platform_inventory'"
        );
        $this->assertSame(250, (int) $policy->fee_bps);
        $this->assertSame('buyer', $policy->fee_bearer);

        $this->assertTrue(
            (bool) DB::connection('pgsql_ops')->selectOne('select verify_commission_chain() as ok')->ok,
            'the settings log is hash-chained'
        );

        // Leave the ledger as the rest of the suite expects it.
        app(CommissionDesk::class)->set('platform_inventory', 0, 'buyer', $staff->email, 'reset');
    }

    public function test_a_commission_is_frozen_so_an_open_trade_is_not_repriced(): void
    {
        $staff = $this->pricingStaff();

        // A trade opened with no commission.
        \DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 0 where trade_type = 'platform_inventory'"
        );
        $trader = Trader::factory()->create();
        Auth::guard('trader')->setUser($trader);
        $openedAtZero = app(TradeDesk::class)->openAgainstUs($trader->email, 200000, 'NGN');

        // Raise the commission for the NEXT trade.
        app(CommissionDesk::class)->set('platform_inventory', 500, 'buyer', $staff->email, 'raised after the fact');

        $stillZero = DB::connection('pgsql_ops')->selectOne(
            'select coalesce(fee_minor_units, 0) as f from trade where id = ?',
            [$openedAtZero]
        );
        $this->assertSame(0, (int) $stillZero->f, 'a trade already agreed keeps the commission it was quoted');

        app(CommissionDesk::class)->set('platform_inventory', 0, 'buyer', $staff->email, 'reset');
    }

    public function test_the_operations_role_cannot_change_the_commission_from_the_database(): void
    {
        // Belt and braces to the screen's middleware: the screen is a convenience, this is the control.
        $this->expectExceptionMessageMatches('/permission denied/');
        DB::connection('pgsql_ops')->selectOne(
            "select set_commission('platform_inventory', 500, 'buyer', 'mallory') as ok"
        );
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
}

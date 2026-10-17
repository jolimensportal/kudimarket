<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Where a trader ends up, and where they end up when they are already inside.
 *
 * These exist because a real sign-in once left somebody staring at Laravel's stock welcome page. The
 * password was right, the session was created, and the only thing they saw was a framework splash
 * screen -- which is indistinguishable from a failed login to anyone who does not read HTTP.
 */
class RootRedirectTest extends TestCase
{
    public function test_the_root_shows_a_signed_out_visitor_the_pitch(): void
    {
        // This used to assert a redirect to sign-in. It could not tell the two apart -- a root that
        // sends a stranger to a login form, and a root that sends a trader to the board, were the same
        // branch with a flag, and one flip of that flag took the pitch off the front door and handed a
        // visitor a sign-in form for a product they had not been told about yet.
        $this->get('/')
            ->assertOk()
            ->assertSee(route('landing'), escape: false);
    }

    public function test_the_root_sends_a_signed_in_trader_to_the_board(): void
    {
        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get('/')
            ->assertRedirect(route('trade.board'));
    }

    public function test_an_already_signed_in_trader_is_not_shown_the_sign_in_form(): void
    {
        // The guest middleware bounces them to the root, and the root now knows where the board is.
        // Before, this landed on Laravel's welcome page.
        $this->actingAs(Trader::factory()->create(), 'trader')
            ->get('/sign-in')
            ->assertRedirect('/');
    }

    public function test_no_route_serves_the_framework_welcome_page(): void
    {
        // Asserted as a file check rather than a response check, because the failure was a 200 with
        // the wrong page on it -- which no status-code assertion would ever catch.
        $this->assertFileDoesNotExist(
            resource_path('views/welcome.blade.php'),
            'the stock welcome page must not come back'
        );
    }

    public function test_a_trader_can_sign_in_with_keep_me_signed_in(): void
    {
        // This path wrote remember_token on login and failed on a column that did not exist. No test
        // had ticked the box, so nothing caught it until a person used the app like a person.
        // A unique address, because these tests share one scratch database and a fixed email makes
        // the second run fail on the unique index rather than on the behaviour under test.
        $trader = Trader::factory()->create([
            'email' => 'remember-'.uniqid().'@example.com',
        ]);

        $this->post('/sign-in', [
            'email' => $trader->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('trade.board'));

        $token = \DB::connection('pgsql_admin')->selectOne(
            'select remember_token from trader where email = ?',
            [$trader->email]
        )->remember_token;

        $this->assertNotNull($token, 'ticking the box must store a remember token');
    }

    public function test_a_wrong_password_says_so_rather_than_redirecting(): void
    {
        $trader = Trader::factory()->create(['email' => 'wrong-'.uniqid().'@example.com']);

        $this->from('/sign-in')
            ->post('/sign-in', ['email' => $trader->email, 'password' => 'wrong'])
            ->assertRedirect('/sign-in')
            ->assertSessionHasErrors('email');
    }

    public function test_signing_out_returns_to_sign_in(): void
    {
        $this->actingAs(Trader::factory()->create(), 'trader')
            ->post('/sign-out')
            ->assertRedirect(route('login'));

        $this->assertFalse(Auth::guard('trader')->check());
    }
}

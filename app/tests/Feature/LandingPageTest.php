<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The public face, and the switch that decides whether it exists.
 *
 * The assertion that matters is the last one. The landing page exists because KudiMarket may not
 * yet be licensed to take the other side for its own account -- brand/REGULATORY.md 5b records the
 * question as open. A page that says "not open yet" is only worth anything if the app behind it is
 * genuinely closed, so the tests below prove that flipping the switch closes sign-in rather than
 * merely drawing a different picture in front of it.
 */
class LandingPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kudi.launching', true);
    }

    public function test_the_root_serves_the_landing_page_while_launching(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Your market.')
            ->assertSee('Your rate.');
    }

    public function test_it_says_plainly_that_we_are_not_open_yet(): void
    {
        // brand/BRAND.md voice rule 3: admit limits out loud before the user finds them. A landing
        // page for a product that cannot legally trade yet, which does not say so, is worse than no
        // page at all -- it is a page that wastes somebody's time.
        $this->get('/about')->assertOk()->assertSee('Not open yet');
    }

    public function test_it_makes_three_promises_and_every_one_is_mechanically_true(): void
    {
        $response = $this->get('/about')->assertOk();

        $response->assertSee('Escrow you can see.');
        $response->assertSee('Nobody touches your money until you say so.');
        $response->assertSee('The rate you agreed is the rate you get.');

        // The banned-words test. brand/BRAND.md is explicit that adjectives doing work a fact could
        // do are the failure mode, and this page is the first thing a regulator, a bank or a
        // journalist will read.
        foreach (['seamless', 'revolutionary', 'cutting-edge', 'world-class', 'bank-grade', 'best-in-class'] as $word) {
            $response->assertDontSee($word, false);
        }
    }

    public function test_an_address_can_be_taken_and_a_second_press_is_a_no_op(): void
    {
        $this->post('/about', ['email' => 'trader@example.com'])
            ->assertRedirect()
            ->assertSessionHas('subscribed');

        $this->assertSame(1, DB::table('launch_signups')->where('email', 'trader@example.com')->count());

        // Somebody on a bad connection will press the button twice. Without this the second press is
        // a unique-constraint 500 -- the one place on the site a visitor can break it.
        $this->post('/about', ['email' => 'trader@example.com'])->assertRedirect();

        $this->assertSame(1, DB::table('launch_signups')->where('email', 'trader@example.com')->count());
    }

    public function test_the_address_is_normalised(): void
    {
        // Case matters in an address and not in a mailbox. Two rows for one person is a person we
        // email twice about going live.
        $this->post('/about', ['email' => '  Trader@Example.COM ']);
        $this->post('/about', ['email' => 'trader@example.com']);

        $this->assertSame(1, DB::table('launch_signups')->count());
    }

    public function test_a_bogus_address_is_refused_with_a_message_not_a_500(): void
    {
        $this->post('/about', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        // Scoped to the address that was refused, not a count of the table. The scratch database is
        // shared across the whole suite and keeps what earlier tests wrote, so "the table is empty" is
        // a claim about the suite rather than about this method.
        $this->assertSame(0, DB::table('launch_signups')->where('email', 'not-an-email')->count());
    }

    public function test_while_launching_the_trader_app_is_closed_and_not_merely_hidden(): void
    {
        // The whole point of the switch. A landing page in front of a working sign-in form would
        // invite trading we may not legally do, one determined visitor at a time.
        $this->get('/sign-in')->assertRedirect(route('landing'));

        $this->post('/sign-in', ['email' => 'ama@example.com', 'password' => 'anything'])
            ->assertRedirect(route('landing'));

        $this->get('/trade/board')->assertRedirect(route('login'));
    }

    public function test_with_launching_off_the_landing_page_is_gone_and_sign_in_works_again(): void
    {
        // The switch has to be reversible in a second, because turning it on is a decision someone
        // may have to undo quickly.
        Config::set('kudi.launching', false);

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/sign-in')->assertOk()->assertSee('Sign in');
    }
}

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

    public function test_the_rate_and_the_fee_are_read_from_the_ledger_not_typed_into_the_page(): void
    {
        // The claim on this page is that we publish our price. A price typed into a template is a claim
        // about the past; a price read out of the rate card is a claim about now. Publish a rate and
        // the page has to show it.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '150.5', 'rates@kudimarket.com']
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee('150.5')
            ->assertSee('GHS to NGN');
    }

    public function test_the_fee_is_quoted_in_basis_points_not_only_as_a_percentage(): void
    {
        // A fee shown only as a percentage is a fee being hidden. 100 bps must appear as 100 bps.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_commission(?, ?, ?, ?, ?) as ok',
            ['platform_inventory', 125, 'buyer', 'rates@kudimarket.com', 'test']
        );

        $this->get('/about')->assertOk()->assertSee('125 bps')->assertSee('1.25%');
    }

    public function test_signing_up_gives_a_shareable_code_and_a_real_position(): void
    {
        $this->post('/about', ['email' => 'first@example.com']);

        $row = DB::table('launch_signups')->where('email', 'first@example.com')->first();

        $this->assertNotNull($row->referral_code);
        // No 0/O or 1/l: these get read aloud and typed off a screenshot.
        $this->assertDoesNotMatchRegularExpression('/[01OI]/', $row->referral_code);

        $this->get('/about')
            ->assertOk()
            ->assertSee('You are number')
            ->assertSee($row->referral_code);
    }

    public function test_two_people_never_get_the_same_code(): void
    {
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->post('/about', ['email' => $email]);
        }

        $codes = DB::table('launch_signups')->pluck('referral_code');
        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    public function test_a_referral_is_recorded_only_when_the_code_is_real(): void
    {
        $this->post('/about', ['email' => 'inviter@example.com']);
        $code = DB::table('launch_signups')->where('email', 'inviter@example.com')->value('referral_code');

        $this->post('/about', ['email' => 'friend@example.com', 'ref' => $code]);
        // The stored value is the CODE, so it must be something that already exists in the table as
        // somebody's referral_code -- there is no value a visitor can supply that is not.
        $this->assertSame($code, DB::table('launch_signups')
            ->where('email', 'friend@example.com')->value('referred_by'));

        // An invented code must not inflate anybody's position. If it did, the queue on the
        // confirmation screen would be decoration, and the queue is the one thing on this page that
        // a trader is asked to believe.
        $this->post('/about', ['email' => 'stranger@example.com', 'ref' => 'ZZZZZZ']);
        $this->assertNull(DB::table('launch_signups')
            ->where('email', 'stranger@example.com')->value('referred_by'));
    }

    public function test_the_page_shows_the_real_number_waiting_and_never_an_invented_one(): void
    {
        // Every teardown warns that inventing a signup count is both illegal and instantly legible.
        // The only number that may appear is the one in the table.
        DB::connection('pgsql_admin')->table('launch_signups')->truncate();
        $this->get('/about')->assertOk()->assertDontSee('already on the list');

        $this->post('/about', ['email' => 'only@example.com']);

        // Flush the session first. Signing up flashes 'subscribed', so without this the next GET
        // renders the confirmation screen -- which is the correct product behaviour and means this
        // test would be asserting about the wrong page entirely.
        $this->flushSession();

        $this->get('/about')->assertOk()->assertSee('1 person is already on the list');
    }
}

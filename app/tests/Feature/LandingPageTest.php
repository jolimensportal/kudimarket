<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Illuminate\Support\Facades\Auth;
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

        // launch_signups is created by a Laravel migration, not by kudi-core, so the SQL rebuild that
        // precedes this suite does not clear it and rows survive from run to run. The assertions
        // below count real rows -- "1 person is already on the list" is a claim about the database,
        // not a string -- so they can only be deterministic from an empty table.
        DB::table('launch_signups')->delete();
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

        $response->assertSee('Cash in hand, the same day.');
        $response->assertSee('Nobody touches your money until you say so.');
        $response->assertSee('The rate you agreed is the rate you get.');

        // The banned-words test. brand/BRAND.md is explicit that adjectives doing work a fact could
        // do are the failure mode, and this page is the first thing a regulator, a bank or a
        // journalist will read.
        foreach (['seamless', 'revolutionary', 'cutting-edge', 'world-class', 'bank-grade', 'best-in-class'] as $word) {
            $response->assertDontSee($word, false);
        }

        // Escrow, offers and trading are cut from this page because the business is a remittance
        // company, and BRAND.md section 0 retires escrow explicitly: the business moves money, it does
        // not hold it on the customer's behalf. Without this, the exchange would creep back into a
        // remittance page one noun at a time, which is exactly how it got there.
        foreach (['escrow', 'trade', 'offer'] as $word) {
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

    public function test_the_homepage_carries_the_configured_whatsapp_and_telegram_links(): void
    {
        // Set through the environment rather than the config facade, because the point of this test is
        // the wiring from the variable name production actually uses. Reading KUDI_WHATSAPP instead of
        // KUDI_WHATSAPP_URL is how real contact details sat in the server environment, unread, while the
        // page quietly rendered nothing.
        $_ENV['KUDI_WHATSAPP_URL'] = 'https://wa.me/233541744222';
        $_ENV['KUDI_TELEGRAM_URL'] = 'https://t.me/kudimarketexchange';
        putenv('KUDI_WHATSAPP_URL=https://wa.me/233541744222');
        putenv('KUDI_TELEGRAM_URL=https://t.me/kudimarketexchange');

        try {
            $this->refreshApplication();

            $this->get('/about')
                ->assertOk()
                ->assertSee('wa.me/233541744222', escape: false)
                ->assertSee('t.me/kudimarketexchange', escape: false)
                ->assertSee('WhatsApp us')
                ->assertSee('Telegram');
        } finally {
            unset($_ENV['KUDI_WHATSAPP_URL'], $_ENV['KUDI_TELEGRAM_URL']);
            putenv('KUDI_WHATSAPP_URL');
            putenv('KUDI_TELEGRAM_URL');
            $this->refreshApplication();
        }
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

    public function test_with_launching_off_the_app_opens_again_in_a_second(): void
    {
        // The switch has to be reversible in a second, because turning it on is a decision someone
        // may have to undo quickly. What it has to restore is the APP being reachable, so that is
        // what this asserts -- previously it stood in for that with "the root redirects to sign-in",
        // which was measuring the front door rather than the gate.
        Config::set('kudi.launching', false);

        $this->get('/sign-in')->assertOk()->assertSee('Sign in');
    }

    public function test_the_public_front_door_is_the_pitch_however_the_app_is_switched(): void
    {
        // The root does not follow the flag. It used to, and that coupled two separate decisions: the
        // flag closed the trader app, but it also decided what a stranger arriving at kudimarket.com
        // saw. Opening the app for one person to look at therefore removed the pitch from the front
        // door and handed a visitor a sign-in form for a product they had not been told about yet --
        // which is the same exposure test_while_launching... exists to prevent, arriving by the other
        // door.
        Config::set('kudi.launching', true);
        $this->get('/')->assertOk()->assertSee('Your market', escape: false);

        Config::set('kudi.launching', false);
        $this->get('/')->assertOk()->assertSee('Your market', escape: false);

        // A signed-in trader is still sent to the board, which is where they were going.
        $trader = Trader::factory()->create();
        Auth::guard('trader')->setUser($trader);

        $this->get('/')->assertRedirect(route('trade.board'));
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
            ->assertSee('150.50')
            // The pair is labelled in words on the device rather than with the arrow the rate card
            // used, so the assertion follows the presentation. The intent is unchanged: publishing a
            // rate must make it appear on the page, labelled with the pair it belongs to.
            ->assertSee('1 cedi = 150.50 naira');
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

    public function test_the_confirmation_screen_keeps_the_same_banned_words_out(): void
    {
        // The banned-words test above only ever reads the landing screen. This is the screen a
        // stranger actually lands on after pressing the button, and it used the word the page is
        // not allowed to use, in the sentence inviting them to bring other people. Nobody checked,
        // because nothing asserted it.
        $this->post('/about', ['email' => 'first@example.com']);

        $this->get('/about')
            ->assertOk()
            ->assertDontSee('trader', false)
            ->assertDontSee('escrow', false)
            ->assertDontSee('offer', false);
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

    public function test_the_worked_example_is_computed_from_the_live_rate_not_hard_coded(): void
    {
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '150.5', 'rates@kudimarket.com']
        );

        // 500 GHS at 150.5 is 75,250 NGN. If the page ever shows a figure that was typed into the
        // template rather than multiplied out of the rate card, this fails.
        $this->get('/about')
            ->assertOk()
            ->assertSee('75,250.00');

        // And the rate moves, so the example moves with it. A worked example frozen at one number is
        // the exact lie this page exists not to tell.
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '200', 'rates@kudimarket.com']
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee('100,000.00')
            ->assertDontSee('75,250.00');
    }

    public function test_the_send_demo_states_figures_taken_from_the_live_rate(): void
    {
        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '150.5', 'rates@kudimarket.com']
        );

        // 500 GHS at 150.5 is 75,250 NGN. Typed into the template it would survive a rate move, which
        // is the one failure this page is built to make impossible.
        $this->get('/about')
            ->assertOk()
            ->assertSee('75,250.00');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id', ['GHS', 'NGN', '200', 'rates@kudimarket.com']
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee('100,000.00')
            ->assertDontSee('75,250.00');
    }

    public function test_the_device_shows_the_send_confirmed_in_words_and_never_on_the_button(): void
    {
        $response = $this->get('/about')->assertOk();

        $response->assertSee('You send');
        $response->assertSee('Amara receives');
        $response->assertSee('Amara Okafor');
        $response->assertSee('Confirm send');
        $response->assertSee('Demo — no real money moves.');

        // The confirm button is a plum surface, and a plum surface never holds a number.
        $this->assertStringNotContainsString(
            '>Confirm send ₵',
            (string) file_get_contents(resource_path('views/public/partials/send-demo.blade.php'))
        );
    }

    public function test_the_device_states_a_configured_fee_and_says_nothing_when_there_is_none(): void
    {
        DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 100 where trade_type = 'platform_inventory'"
        );

        $this->get('/about')->assertOk()->assertSee('Our fee 1%');

        DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 0 where trade_type = 'platform_inventory'"
        );

        $this->get('/about')->assertOk()->assertDontSee('Our fee 1%');
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

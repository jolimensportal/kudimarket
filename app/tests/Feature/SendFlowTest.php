<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\RemittanceDesk;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * Sending money to a named person.
 *
 * The landing page says "Cash in hand, the same day" and names no counterparty. This is the screen that
 * has to make that true, so the tests below are mostly about what the sender is NOT allowed to do:
 * confirm their own money, see a send that is not theirs, or be quoted a naira figure the ledger will
 * not settle against.
 */
final class SendFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', '148.2', 'rates-desk@kudimarket.com']
        );

        DB::connection('pgsql_admin')->statement(
            "update trade_policy set fee_bps = 0, fee_bearer = 'buyer'"
        );
    }

    public function test_a_trader_can_open_a_send_naming_someone(): void
    {
        $trader = $this->trader('send');

        $this->get('/trade/send')->assertOk();

        $this->post('/trade/send', [
            'send_amount' => '10000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();

        $send = $this->latestSend($trader);

        $this->assertNotNull($send, 'the send was opened');
        $this->assertSame('platform', $send->seller, 'KudiMarket is the counterparty');
        $this->assertSame($trader->email, $send->buyer, 'the sender owns it');
        $this->assertSame('remittance', $send->trade_type);
        $this->assertSame('open', $send->state);

        // C10,000 at 148.2 is N1,482,000.
        $this->assertSame('148200000', $send->fiat_amount, 'the beneficiary receives naira');
        $this->assertSame('NGN', $send->fiat_asset);
        $this->assertSame('Kwesi Osei', $send->beneficiary_name);
        $this->assertSame('+2348012345678', $send->beneficiary_phone);
        $this->assertSame('GHS/NGN', $send->corridor);

        $this->assertNull($send->delivered_reference, 'nothing has been handed over yet');
    }

    public function test_the_quoted_naira_is_what_the_ledger_settles_against(): void
    {
        $trader = $this->trader('send-figures');

        $this->post('/trade/send', [
            'send_amount' => '10000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();

        $send = $this->latestSend($trader);
        $figures = TradeFigures::forTrade($send, 'buyer');

        // The figure on the screen and the figure charged come from the same row, so a rounding change
        // cannot make them disagree. This is the assertion that would fail first if the cedi leg were
        // derived in PHP and the naira leg in Postgres by different routes.
        $this->assertSame('10,000.00 GHS', $figures['send']);
        $this->assertSame('1,482,000.00 NGN', $figures['receive']);

        $response = $this->get("/trade/send/{$send->id}")->assertOk();
        $response->assertSee('1,482,000.00 NGN');
        $response->assertSee('Kwesi Osei');
    }

    public function test_a_send_cannot_be_opened_in_somebody_elses_name(): void
    {
        $trader = $this->trader('send-identity');

        // The controller passes the signed-in trader, so this cannot be reached through the form. It is
        // exercised directly because it is the check standing between the session and the ledger.
        $desk = app(RemittanceDesk::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only be opened in your own name');

        $desk->open('someone-else@example.com', 1000000, 'Kwesi Osei', '+2348012345678');
    }

    public function test_a_send_is_not_visible_to_another_trader(): void
    {
        $owner = $this->trader('send-owner');
        $this->post('/trade/send', [
            'send_amount' => '1000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();
        $send = $this->latestSend($owner);

        $this->trader('send-stranger');
        $this->get("/trade/send/{$send->id}")->assertNotFound();
    }

    public function test_a_beneficiary_has_to_be_someone_we_can_reach_and_pay(): void
    {
        $trader = $this->trader('send-validate');

        $this->post('/trade/send', [
            'send_amount' => '1000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => 'call me maybe',
        ])->assertSessionHasErrors('beneficiary_phone');

        $this->post('/trade/send', [
            'send_amount' => '1000.00',
            'beneficiary_name' => 'x',
            'beneficiary_phone' => '+2348012345678',
        ])->assertSessionHasErrors('beneficiary_name');

        // Scoped to this trader. The test database is shared across the suite, so counting the whole
        // table asserts that every other test passed first -- which is a different test, and one that
        // fails for reasons that have nothing to do with this screen.
        $this->assertSame(
            0,
            (int) DB::connection('pgsql_admin')->selectOne(
                "select count(*) as n from trade where buyer = ? and trade_type = 'remittance'",
                [$trader->email]
            )->n,
            'neither attempt opened a send'
        );
    }

    public function test_a_sender_cannot_confirm_their_own_money(): void
    {
        $trader = $this->trader('send-attest');
        $this->post('/trade/send', [
            'send_amount' => '1000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();
        $send = $this->latestSend($trader);

        $this->post("/trade/{$send->id}/claim")->assertRedirect();

        // Re-read. $send was fetched before the claim, so asserting on it here would be asserting the
        // state the send was in when the test started.
        $this->assertSame(
            'paid_claimed',
            $this->latestSend($trader)->state,
            'the sender declaring is the one thing they are allowed'
        );

        // The step after it is operations', and the control is at the connection: the trader role is not
        // granted ops_transition, so no request from a sender can reach it. Asserting that rather than
        // calling TradeLedger::attest(), which runs on the OPERATIONS connection and would succeed no
        // matter whose email is passed to it -- an earlier version of this test asserted that and proved
        // nothing about the sender at all.
        $this->assertFalse(
            DB::connection('pgsql_trader')->selectOne(
                "select has_function_privilege('kudi_trader', 'ops_transition(bigint,text,text,text,text,text)', 'execute') as can"
            )->can,
            'the trader role must not be able to confirm a payment, by any route'
        );

        // And the public surface agrees: at paid_claimed the only control on the send page is the
        // sender's own declaration. Nothing else is offered, and nothing else is reachable.
        $page = $this->get('/trade/send/'.$this->latestSend($trader)->id)->assertOk();

        // Counted by ACTION, not by <form>. The trader layout carries a sign-out form on every page,
        // so counting form tags measured the chrome rather than what this screen offers.
        $this->assertSame(
            1,
            substr_count($page->getContent(), route('trade.claim', $this->latestSend($trader)->id)),
            'the send page offers exactly one action, and it is the sender\'s own declaration'
        );
        $this->assertStringContainsString('I have sent the cedi', $page->getContent());
    }

    public function test_nobody_can_settle_a_send_before_the_cash_has_been_handed_over(): void
    {
        $trader = $this->trader('send-release');
        $this->post('/trade/send', [
            'send_amount' => '1000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();
        $send = $this->latestSend($trader);

        $this->post("/trade/{$send->id}/claim");
        app(TradeLedger::class)->attest($send->id, 'ops@kudimarket.com');

        $ledger = app(TradeLedger::class);

        // Operations themselves cannot skip the handover, and the sweeper is tested for this in the core
        // suite. This is the same invariant from the application's side of the wall.
        try {
            $ledger->release($send->id, 'ops@kudimarket.com');
            $this->fail('operations settled a send that had never been paid out');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has not been handed over yet', $e->getMessage());
        }

        $this->assertSame(
            'paid_attested',
            DB::connection('pgsql_ops')->selectOne('select state from trade where id = ?', [$send->id])->state
        );
    }

    public function test_operations_records_the_handover_and_the_float_falls_only_then(): void
    {
        $trader = $this->trader('send-payout');
        $this->post('/trade/send', [
            'send_amount' => '10000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();
        $send = $this->latestSend($trader);

        $this->post("/trade/{$send->id}/claim");
        app(TradeLedger::class)->attest($send->id, 'ops@kudimarket.com');

        $float = fn (): int => (int) DB::connection('pgsql_ops')->selectOne(
            "select coalesce(balance, 0) as b from account where owner = 'platform' and kind = 'external' and asset = 'NGN'"
        )->b;

        $before = $float();

        app(TradeLedger::class)->recordPayout($send->id, 148200000, 'TILL-4471', 'ops@kudimarket.com');

        $this->assertSame(
            $before,
            $float(),
            'recording the handover moves no money; it only says the cash was given'
        );

        app(TradeLedger::class)->release($send->id, 'ops@kudimarket.com');

        $this->assertSame(
            $before - 148200000,
            $float(),
            'the float falls by exactly what was handed over'
        );

        $payout = app(TradeLedger::class)->payoutFor($send->id);
        $this->assertSame('TILL-4471', $payout->delivered_reference);
        $this->assertSame('ops@kudimarket.com', $payout->delivered_by);
        $this->assertNotNull($payout->delivered_at);

        // And the sender can see it. The handover reference is the record they are entitled to.
        $this->get("/trade/send/{$send->id}")
            ->assertOk()
            ->assertSee('TILL-4471');
    }

    public function test_a_handover_needs_a_reference_and_the_right_amount(): void
    {
        $trader = $this->trader('send-payout-guard');
        $this->post('/trade/send', [
            'send_amount' => '10000.00',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertRedirect();
        $send = $this->latestSend($trader);
        $this->post("/trade/{$send->id}/claim");
        app(TradeLedger::class)->attest($send->id, 'ops@kudimarket.com');

        $ledger = app(TradeLedger::class);

        foreach ([
            [148200000, '', 'till reference'],
            [1482000, 'TILL-1', 'not the amount this send owes'],
        ] as [$amount, $reference, $expected]) {
            try {
                $ledger->recordPayout($send->id, $amount, $reference, 'ops@kudimarket.com');
                $this->fail("a handover of {$amount} with reference '{$reference}' was accepted");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $this->assertSame(
            0,
            (int) DB::connection('pgsql_ops')->selectOne(
                'select count(*) as n from remittance_send where trade_id = ? and delivered_amount is not null',
                [$send->id]
            )->n,
            'neither refused attempt recorded anything'
        );
    }

    public function test_the_send_screen_says_nothing_about_the_old_exchange(): void
    {
        $trader = $this->trader('send-copy');

        foreach ([
            fn () => $this->get('/trade/send'),
            function () use ($trader) {
                $this->post('/trade/send', [
                    'send_amount' => '1000.00',
                    'beneficiary_name' => 'Kwesi Osei',
                    'beneficiary_phone' => '+2348012345678',
                ]);
                $send = $this->latestSend($trader);

                return $this->get("/trade/send/{$send->id}");
            },
        ] as $page) {
            $response = $page()->assertOk();

            // The VISIBLE text, not the source. Every trader route in this application lives under
            // /trade/, so the word appears in every URL on the page and in the sign-out form's action.
            // Checking the raw HTML would fail on the route namespace rather than on anything a sender
            // reads, which is the opposite of what this test is for.
            //
            // A space is opened before every tag, so adjacent elements do not run together:
            // <dt>They receive</dt><dd>148,200.00</dd> would otherwise extract as "receive148,200.00"
            // and stop containing the word "receive" by any boundary test. The space goes BEFORE the
            // tag rather than being substituted for it -- replacing the angle brackets instead left the
            // href attributes as text, so every URL on the page came back as visible copy.
            $visible = trim(preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(strip_tags(str_replace('<', ' <', $response->getContent())))
            ));

            foreach (['escrow', 'offer', 'trade', 'dispute'] as $word) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.preg_quote($word, '/').'\b/i',
                    $visible,
                    "the send screens must not describe an exchange: found '{$word}' in the visible text"
                );
            }

            // And the words they must actually use, so the check above cannot be satisfied by a page
            // that says nothing at all.
            $this->assertMatchesRegularExpression('/\breceive\b/i', $visible);
        }
    }

    private function trader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    /**
     * The sender's own send, read the way the application reads it.
     *
     * Deliberately through my_remittance_sends() and not a direct join on the trader connection: the
     * trader role has no SELECT on remittance_send, so a helper that queried it directly would be
     * testing a privilege the product does not grant and would fail in a way that hid the real one.
     */
    private function latestSend(Trader $trader): ?object
    {
        $id = DB::connection('pgsql_admin')->selectOne(
            "select id from trade where buyer = ? and trade_type = 'remittance' order by id desc limit 1",
            [$trader->email]
        );

        if ($id === null) {
            return null;
        }

        return DB::connection('pgsql_trader')->selectOne(
            "select *, 'remittance' as trade_type from my_remittance_sends() where id = ?",
            [$id->id]
        );
    }
}

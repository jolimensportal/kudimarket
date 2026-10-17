<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The send form as a SENDER sees it, which is not the same thing as the send flow as a TEST sees it.
 *
 * SendFlowTest posts sends and asserts errors exist in the session. Both are true while the page a
 * person is actually looking at says nothing at all, which is what happened: a sender who opened a
 * shared link with an amount in it was quoted a figure, filled in the two fields below it, pressed the
 * button, and got back the same page quoting the same figure with no reason given.
 */
final class SendFormTest extends TestCase
{
    public function test_an_amount_in_the_link_is_the_amount_in_the_field(): void
    {
        $this->signedIn();

        $this->actingAs(Auth::guard('trader')->user())
            ->get('/trade/send?amount=250')
            ->assertOk()
            // The quote is computed from the query string, so the quote and the field were two different
            // numbers: the page promised 250.00 GHS and would have been submitted empty.
            ->assertSee('250.00 GHS')
            ->assertSee('value="250.00"', escape: false);
    }

    public function test_a_refusal_is_visible_on_the_page_and_not_only_in_the_session(): void
    {
        $this->signedIn();
        $trader = Auth::guard('trader')->user();

        $page = $this->actingAs($trader)->post('/trade/send', [
            'send_amount' => '',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ]);

        $page->assertSessionHasErrors('send_amount');

        // The message that went into the session, and that the sender has to be able to READ on the
        // page. Compared against the same string rather than a copy of it, so a wording change does not
        // fail here and the page cannot pass by rendering some other error.
        $message = session('errors')->first('send_amount');
        $this->assertNotEmpty($message);

        // No Referer on purpose. The refusal has to land on the send page because the controller says
        // so, not because a browser happened to send a header a privacy setting can strip.
        $this->followingRedirects()
            ->post('/trade/send', [
                'send_amount' => '',
                'beneficiary_name' => 'Kwesi Osei',
                'beneficiary_phone' => '+2348012345678',
            ])
            ->assertOk()
            ->assertSee($message, escape: false);
    }

    public function test_a_refused_amount_leaves_the_send_unsent(): void
    {
        $this->signedIn();
        $trader = Auth::guard('trader')->user();

        $this->actingAs($trader)->post('/trade/send', [
            'send_amount' => 'not money',
            'beneficiary_name' => 'Kwesi Osei',
            'beneficiary_phone' => '+2348012345678',
        ])->assertSessionHasErrors('send_amount');

        $count = (int) DB::connection('pgsql_admin')->selectOne(
            'select count(*) from trade where buyer = ? and trade_type = ?',
            [$trader->email, 'remittance']
        )->count;

        $this->assertSame(0, $count);
    }

    private function signedIn(): void
    {
        $trader = Trader::factory()->create([
            'email' => 'form-'.bin2hex(random_bytes(4)).'@example.com',
        ]);

        Auth::guard('trader')->setUser($trader);

        DB::connection('pgsql_pricing')->selectOne(
            'select set_rate(?, ?, ?, ?) as id',
            ['GHS', 'NGN', 148.2, 'form-desk@kudimarket.com']
        );
    }
}

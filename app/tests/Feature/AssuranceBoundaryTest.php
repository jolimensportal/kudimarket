<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The assurance boundary: what KudiMarket promises, and what it does not.
 *
 * This is the most consequential sentence in the product. "Checked" and "escrow" both sound like
 * protection, and a trader who reasonably concludes that somebody stands behind their money -- and is
 * wrong -- is a consumer-protection failure rather than a UI bug. So these tests assert the NEGATIVE
 * is stated explicitly, not merely implied by a badge.
 *
 * The four rules the component obeys, each asserted below:
 *   1. the checked side says the word "not"
 *   2. it names WHO the counterparty is
 *   3. it says what DOES happen, so the no is not read as "no recourse"
 *   4. the handled side says the loss is ours
 */
class AssuranceBoundaryTest extends TestCase
{
    public function test_the_checked_side_states_plainly_that_settlement_is_not_guaranteed(): void
    {
        // The sentence the locked product reference uses, and the one the offer board was missing
        // entirely. Asserted on the rendered page, not the template, because the whole point is that a
        // TRADER can see it.
        $seller = $this->checkedTrader('seller');
        $buyer = $this->checkedTrader('buyer');
        $offer = $this->publishOffer($seller);

        $response = $this->actingAs($buyer, 'trader')->get('/trade/offers');

        $response->assertOk()
            ->assertSee('settlement is not guaranteed')
            // The explicit negative. An implication is not a disclosure.
            ->assertSee('not guaranteed')
            // Who is on the other side, by name.
            ->assertSee($seller->email)
            // What we DO do, so this does not read as "you are on your own".
            ->assertSee('we hold both deposits');
    }

    public function test_the_checked_side_is_distinguishable_from_the_handled_side_on_screen(): void
    {
        $seller = $this->checkedTrader('seller');
        $buyer = $this->checkedTrader('buyer');
        $this->publishOffer($seller);

        $html = $this->actingAs($buyer, 'trader')->get('/trade/offers')->assertOk()->getContent();

        // The specific card the buyer is about to act on must be the CHECKED one.
        //
        // Deliberately NOT assertStringNotContainsString('is-handled') on the whole page. That looked
        // like the stronger assertion and is not: the board legitimately offers a route to the handled
        // side, and asserting a class is absent from an entire document couples this test to whatever
        // else the page decides to render. If the checked card ever silently started rendering as
        // handled, the test that must catch it is the one that asserts what THIS card says.
        $this->assertStringContainsString('is-checked', $html);

        // And that this seller's offer card is the one carrying it -- assert on the badge word next to
        // their address, so a page that showed a checked card for a DIFFERENT seller cannot pass.
        $this->assertMatchesRegularExpression(
            '/is-checked.{0,600}'.preg_quote($seller->email, '/').'/s',
            $html,
            'the checked card shown does not name the seller this buyer would be dealing with'
        );
    }

    public function test_the_handled_side_says_the_loss_is_ours(): void
    {
        $buyer = $this->checkedTrader('buyer');

        $this->actingAs($buyer, 'trader')
            ->get('/trade/buy')
            ->assertOk()
            ->assertSee('KudiMarket handled')
            // 'it is <strong>our loss</strong>' -- the markup is in the middle of the phrase, so a
            // plain substring of the sentence can never match. Assert the words on either side.
            ->assertSee('our loss', false)
            ->assertSee('not a claim you have to win', false)
            ->assertSee('is-handled', false);
    }

    public function test_the_handled_screen_points_at_the_other_tier_rather_than_describing_it(): void
    {
        $buyer = $this->checkedTrader('buyer');

        // The handled screen states its own promise and links to the other one. It does not try to
        // explain the checked tier in a paragraph, because a reader who only wants to know who is on
        // the hook should not have to read about the other case first.
        $html = $this->actingAs($buyer, 'trader')->get('/trade/buy')->assertOk()->getContent();

        $this->assertStringContainsString('see what other traders are selling', $html);
        // 'checked' appears inside the <strong> on this screen too; the word is there either way.
        $this->assertStringContainsString('checked', $html);
    }

    public function test_the_boundary_is_not_colour_alone(): void
    {
        // A badge is recognition for somebody already reading. The tier is named in text, so the
        // meaning survives in a screenshot, in a screen reader, and with no colour at all.
        $seller = $this->checkedTrader('seller');
        $buyer = $this->checkedTrader('buyer');
        $this->publishOffer($seller);

        $html = $this->actingAs($buyer, 'trader')->get('/trade/offers')->assertOk()->getContent();

        // The word itself, not only a class or a swatch.
        $this->assertStringContainsString('Checked', $html);
        $this->assertStringNotContainsString('aria-hidden="true" class="badge checked"', $html);
    }

    public function test_the_wording_lives_in_one_file_so_it_cannot_drift(): void
    {
        // The reason this is a partial. The wording existed on four screens and drifted: the board
        // with the most money at risk named WHO the counterparty was and never said nobody guaranteed
        // delivery.
        $partial = (string) file_get_contents(resource_path('views/partials/assurance.blade.php'));

        $this->assertStringContainsString('settlement is not guaranteed', $partial);
        $this->assertStringContainsString('our loss', $partial);

        // And no view should have re-inlined the promise instead of including it.
        foreach (['trade/show', 'buy/create', 'offer/board'] as $view) {
            $contents = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString(
                'partials.assurance',
                $contents,
                "{$view} states the assurance boundary without using the shared partial"
            );
        }
    }

    private function checkedTrader(string $label): Trader
    {
        $trader = Trader::factory()->create([
            'email' => "{$label}-".bin2hex(random_bytes(4)).'@example.com',
        ]);

        // Checked, via the same functions the ops console calls. The database refuses a second
        // application while one is open, so this is deliberately one call, not a retry loop.
        DB::connection('pgsql_admin')->selectOne('select kudi_sign_in(?)', [$trader->email]);
        DB::connection('pgsql_admin')->selectOne(
            'select apply_for_verification(?, ?)',
            [$trader->email, "{$trader->name}, Ghana Card GHA-000000111-1-1, 1 High Street Accra, own-name MTN 0245550111, funds from trading."]
        );
        DB::connection('pgsql_admin')->selectOne(
            'select decide_verification(?, true, ?, ?)',
            [$trader->email, 'checked for the assurance boundary test', 'ops@kudimarket.com']
        );

        return $trader->refresh();
    }

    private function publishOffer(Trader $seller): int
    {
        $result = DB::connection('pgsql_admin')->selectOne(
            'select kudi_sign_in(?)',
            [$seller->email]
        );

        $id = DB::connection('pgsql_admin')->selectOne(
            'select publish_offer(?, ?, ?, ?, ?) as id',
            [$seller->email, 'NGN', 'GHS', 10000000, '0.006']
        );

        return (int) $id->id;
    }
}

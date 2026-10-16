<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trader;
use App\Services\RemittanceDesk;
use App\Services\TradeDesk;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * A sender has to be able to find their sends again.
 *
 * my_remittance_sends() was written and granted and then left unused, which is the same state
 * 016 documents for a function nothing calls: it passes every test and helps nobody. The history page
 * is where a sender looks, and it was showing sends -- because a send IS a trade row to the ledger --
 * under a "Your trades" heading with a counterparty and a Buy/Sell framing.
 */
final class SendListTest extends TestCase
{
    public function test_a_sender_sees_their_sends_in_their_own_words(): void
    {
        $trader = $this->trader();
        $send = $this->send($trader, 'Kwesi Osei');
        $receiveMinor = app(RemittanceDesk::class)->quote(1000000)['receiveMinor'];

        $page = $this->get('/trade/history')->assertOk();

        $page->assertSee('Kwesi Osei');
        $page->assertSee('To Kwesi Osei');
        $page->assertSee(route('trade.send.show', $send), escape: false);
        // Quoted, not hardcoded: a hand-typed figure in a test is a figure that goes stale the moment the
        // rate moves, and then it fails for a reason that has nothing to do with the page.
        $page->assertSee(number_format($receiveMinor / 100, 2).' NGN', escape: false);

        // And NOT described as an exchange transaction. This is the whole reason the two lists are
        // separate: the words are not decoration on a ledger row, they describe who the money is for.
        $visible = $this->visible($page->getContent());
        foreach (['counterparty', 'escrow', 'offer'] as $word) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($word, '/').'\b/i',
                $visible,
                "the sends list must not describe an exchange: found '{$word}'"
            );
        }
    }

    public function test_a_send_is_never_shown_as_a_trade(): void
    {
        $trader = $this->trader();
        $this->send($trader, 'Kwesi Osei');

        $visible = $this->visible($this->get('/trade/history')->assertOk()->getContent());

        // A send is a row in trade and comes back from my_trades() like any other. If it were also
        // rendered in the trade list it would appear twice, and the trade list would describe a person
        // as a counterparty.
        $this->assertSame(1, substr_count($visible, 'Kwesi Osei'));
        $this->assertStringNotContainsString('With platform', $visible);
    }

    public function test_a_sender_sees_only_their_own_sends(): void
    {
        $mine = $this->trader();
        $this->send($mine, 'Kwesi Osei');
        $this->send($this->trader('other'), 'Someone Else');

        // Signed back in as the first trader: trader() leaves the second one signed in, so without this
        // the page under test would be the other person's and the assertion would pass for the wrong
        // reason.
        Auth::guard('trader')->setUser($mine);

        $page = $this->get('/trade/history')->assertOk();

        $page->assertSee('Kwesi Osei');
        $page->assertDontSee('Someone Else');
        $page->assertDontSee('Someone Else');
    }

    public function test_the_state_is_described_rather_than_named(): void
    {
        $trader = $this->trader();
        $send = $this->send($trader, 'Kwesi Osei');

        $this->assertSame('Not started', $this->labelFor($trader, $send));

        app(TradeDesk::class)->claimPaid($send, $trader->email);

        // "paid_claimed" means the cedi is declared and we have not confirmed it. Printing the state name
        // told the sender nothing about whether the money was safe or when it would arrive.
        $this->assertSame('Waiting for us to confirm', $this->labelFor($trader, $send));
    }

    private function labelFor(Trader $trader, int $send): string
    {
        Auth::guard('trader')->setUser($trader);
        $visible = $this->visible($this->get('/trade/history')->assertOk()->getContent());

        foreach ([
            'Not started' => 'open',
            'Waiting for us to confirm' => 'paid_claimed',
            'Arranging the handover' => 'paid_attested',
            'Handed over, finishing up' => 'paid_out',
            'Complete' => 'released',
        ] as $label => $state) {
            if (str_contains($visible, $label)) {
                return $label;
            }
        }

        return 'unknown:'.$state;
    }

    private function trader(string $label = 'send-list'): Trader
    {
        $trader = Trader::factory()->create([
            'email' => sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(4))),
        ]);

        Auth::guard('trader')->setUser($trader);

        return $trader;
    }

    private function send(Trader $trader, string $beneficiary): int
    {
        return (int) app(RemittanceDesk::class)->open($trader->email, 1000000, $beneficiary, '+2348012345678');
    }

    /**
     * Visible text. A space is opened before every tag so adjacent elements do not run together --
     * without it "receive" followed by a figure becomes one token and no boundary test can find it.
     */
    private function visible(string $html): string
    {
        return trim(preg_replace(
            '/\s+/',
            ' ',
            html_entity_decode(strip_tags(str_replace('<', ' <', $html)))
        ));
    }
}

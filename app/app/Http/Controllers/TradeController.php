<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RemittanceDesk;
use App\Services\TradeDesk;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class TradeController extends Controller
{
    /**
     * The trade screen: what was agreed, and what happens next.
     */
    public function show(Request $request, int $trade, TradeLedger $ledger): View
    {
        $trade = $this->ownedTrade($request, $trade, $ledger);
        $email = $request->user('trader')?->email;

        $role = $trade->seller === $email ? 'seller' : 'buyer';

        return view('trade.show', [
            'trade' => $trade,
            'role' => $role,
            'figures' => TradeFigures::forTrade($trade, $role),
            'events' => $ledger->events($trade->id),
        ]);
    }

    /**
     * Every trade this trader is a party to, newest first.
     *
     * "Where you stand" has always said HOW MANY trades a trader has and never which -- settled, open,
     * disputed, on offer, and no list. Each trade lives at its own id, so a trader who left a trade screen
     * by any route other than the back button had no way to reach it again. This is the list to find it
     * from, and the reason a trade id is worth showing on it: the id is how the screen is reached.
     *
     * The rows come from TradeLedger::mine(), which reads my_trades() on the session identity, so the
     * database decides whose trades these are and this screen can only ever show the signed-in trader
     * their own. The figures are computed HERE, for THIS trader's side -- never in the template, which
     * would default to the seller's side and print the wrong direction, the bug trade/show.blade.php's
     * comment keeps on the record.
     */
    public function index(Request $request, TradeLedger $ledger): View
    {
        $email = $request->user('trader')->email;

        $trades = [];
        foreach ($ledger->mine() as $row) {
            // A send is a row in this table and comes back from my_trades() like any other trade. Without
            // this filter it was rendered TWICE -- once as a send, and once here, described as a trade
            // whose counterparty is KudiMarket and whose money is "at risk, not just theirs", for a
            // transaction the sender never entered. Same id, same figures, two incompatible stories.
            if (($row->trade_type ?? null) === 'remittance') {
                continue;
            }

            $role = $row->buyer === $email ? 'buyer' : 'seller';

            // KudiMarket being a party is what makes the tier handled, not being the seller: on a buy we
            // are the seller, on a sell we are the buyer (kudi-core/018). Same rule the trade screen uses.
            $handled = $row->buyer === 'platform' || $row->seller === 'platform';

            $trades[] = [
                'id' => (int) $row->id,
                'handled' => $handled,
                'counterparty' => $handled ? 'KudiMarket' : ($row->buyer === $email ? $row->seller : $row->buyer),
                'figures' => TradeFigures::forTrade($row, $role),
                'state_label' => self::stateLabel($row->state),
                // The badge class follows trade_view's own colour, so the badge and the ledger's idea of
                // the state cannot drift apart. green = money a person has seen arrive, red = money that
                // went back or a trade that never happened, plum = in flight.
                'badge' => match ($row->colour) {
                    'green' => 'badge ok',
                    'red' => 'badge lost',
                    default => 'badge',
                },
                'updated_at' => $row->updated_at,
            ];
        }

        // Sends are their own list, in their own words, and they are NOT merged into the trade list
        // above. A send is a trade row as far as the ledger is concerned -- same table, same state chain
        // -- so my_trades() returns it, and dropping it in would show a sender their remittance under a
        // heading that says "Your trades", with a counterparty and a "Buy"/"Sell" framing describing a
        // transaction model they are not in. Separating them costs one query and keeps every word on
        // both pages honest.
        $sends = array_map(
            fn (object $row) => [
                'id' => (int) $row->id,
                'beneficiary' => $row->beneficiary_name,
                'receive' => TradeFigures::forTrade($row, 'buyer')['receive'],
                'state_label' => self::sendStateLabel($row->state),
                'badge' => match ($row->state) {
                    'released' => 'badge ok',
                    'cancelled', 'resolved_refund' => 'badge lost',
                    default => 'badge',
                },
                // Cast here. The trade rows come back through the Trade model, which casts timestamps;
                // these come back from a plain function as strings, and a template calling
                // diffForHumans() on a string 500s the page.
                'updated_at' => Carbon::parse($row->updated_at),
            ],
            app(RemittanceDesk::class)->mine()
        );

        return view('trade.history', ['trades' => $trades, 'sends' => $sends]);
    }

    /**
     * What a send is doing, in the words of somebody waiting for it.
     *
     * Not the ledger's state names. "paid_attested" on a send means the cedi is confirmed and somebody
     * still has to walk to the till, and rendering that as the raw state told the sender nothing about
     * whether their money was safe or when it would arrive.
     */
    private static function sendStateLabel(string $state): string
    {
        return match ($state) {
            'open' => 'Not started',
            'paid_claimed' => 'Waiting for us to confirm',
            'paid_attested' => 'Arranging the handover',
            'paid_out' => 'Handed over, finishing up',
            'released' => 'Complete',
            'disputed' => 'Under review',
            'cancelled' => 'Cancelled',
            default => str_replace('_', ' ', $state),
        };
    }

    /**
     * The buyer says the money is on its way.
     *
     * The amount is not a parameter. A client that could send the figure it wants recorded would let a
     * trader claim they paid ₦20,000 on a ₦2,000 trade; the ledger knows what was agreed, so the
     * client's copy is ignored entirely.
     *
     * The idempotency key is NOT taken from the request either, and that is deliberate. TradeDesk
     * derives it from the trade and the step, so the same claim lands on the same key every time. A
     * client-supplied key would only be idempotent while the client remembered to resend it -- one
     * page reload and a second tap becomes a second decision.
     */
    public function claim(Request $request, int $trade, TradeLedger $ledger, TradeDesk $desk): RedirectResponse
    {
        $row = $this->ownedTrade($request, $trade, $ledger);

        // The party who SENDS may declare. That is not always the buyer, and assuming it was is a bug the
        // sell side exposed: on a trade where the trader sells currency TO KudiMarket they are the seller,
        // and "only the buyer sends the money" refused the very trader who did. The rule is which party
        // pays, and it is stated per trade type in the ledger's own trade_rule, which is why this mirrors
        // that rather than inventing a second copy of it.
        //
        // The ledger refuses an unauthorized claim regardless, so this is here to give the trader a
        // sentence they can act on instead of a bare 403.
        $email = $request->user('trader')?->email;
        $sends = $row->buyer === $email
            || ($row->trade_type === 'platform_sell' && $row->seller === $email);

        abort_unless(
            $sends,
            403,
            'Only the party that sends the money can say they have sent it.'
        );

        try {
            $desk->claimPaid($row->id, $request->user('trader')->email);
        } catch (RuntimeException $e) {
            // A refusal is information, not a crash: "someone already moved this" is something a
            // trader can act on. Back to the trade, with the current state on screen.
            return redirect()->route($this->pageFor($row), $row->id)->with('notice', $e->getMessage());
        }

        // By name, not back(). back() follows the Referer header, so a request without one -- a
        // retried POST, a reopened tab, anything that did not come from the form -- sent the trader to
        // the site root instead of the trade they just claimed. Success names its destination.
        return redirect()
            ->route($this->pageFor($row), $row->id)
            ->with('notice', 'Noted. We are checking for your payment.');
    }

    /**
     * Where a trader goes after acting on one of their own transactions.
     *
     * A send is a row in the trade table and comes back from my_trades() like any other, so naming
     * trade.show unconditionally dropped a sender onto the exchange screen for a transaction model they
     * never entered -- "Trade #160", counterparties, a claim window. Declaring a remittance payment sent
     * you out of the remittance product and into the old one. This is the third place that has assumed a
     * trade row means an exchange trade; the type decides which page describes it.
     */
    private function pageFor(object $row): string
    {
        return ($row->trade_type ?? null) === 'remittance' ? 'trade.send.show' : 'trade.show';
    }

    /**
     * A trade the signed-in trader is allowed to act on: theirs, and nobody else's.
     *
     * 404 for a trade that does not exist; 403 for one that belongs to somebody else. Deliberately
     * different -- merging them would either leak which trade ids are real, or make a mistyped link
     * look like an attack.
     *
     * Ownership is checked here rather than as a query filter so the refusal has a cause instead of
     * being an empty page. The id in the URL is not a secret, so this is for clarity, not concealment.
     */
    /**
     * A trade the signed-in trader is a party to, or a refusal that says why.
     *
     * This WAS a private method here, and DisputeController had its own copy. The copy here was fixed --
     * a seller was getting "That trade belongs to somebody else" on a trade they are named on -- and the
     * copy there was not, which is how the same bug survived a commit that claimed to fix it. Both now
     * call TradeAccess, so there is one rule to be right.
     *
     * Ownership is a precondition, not a permission: `claim` below still insists the requester is the
     * buyer, because only the buyer sends the money and so only the buyer can say they have sent it.
     */
    private function ownedTrade(Request $request, int $trade, TradeLedger $ledger): object
    {
        return TradeAccess::party($request, $trade, $ledger);
    }

    /**
     * A trade's state in words a trader can act on.
     *
     * The raw state is the ledger's vocabulary -- paid_attested, resolved_release -- and a trader reading
     * their own history should not have to learn it. The list is exhaustive on purpose so a state added to
     * the ledger later falls through to `default` and reads as itself rather than being silently mislabelled.
     * The COLOUR is not chosen here: it comes from trade_view's own case, so the badge and the ledger's
     * idea of the state cannot drift apart.
     */
    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'open' => 'Waiting for the money',
            'escrowed' => 'Currency held',
            'paid_claimed' => 'Payment being checked',
            'paid_attested' => 'Payment received, releasing',
            'released', 'resolved_release' => 'Settled',
            'cancelled' => 'Cancelled',
            'disputed' => 'In dispute',
            'resolved_refund' => 'Refunded',
            default => str_replace('_', ' ', $state),
        };
    }
}

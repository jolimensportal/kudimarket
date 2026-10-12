<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeDesk;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            return redirect()->route('trade.show', $row->id)->with('notice', $e->getMessage());
        }

        // By name, not back(). back() follows the Referer header, so a request without one -- a
        // retried POST, a reopened tab, anything that did not come from the form -- sent the trader to
        // the site root instead of the trade they just claimed. Success names its destination.
        return redirect()
            ->route('trade.show', $row->id)
            ->with('notice', 'Noted. We are checking for your payment.');
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
}

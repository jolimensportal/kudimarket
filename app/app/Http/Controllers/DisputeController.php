<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeDesk;
use App\Services\TradeLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DisputeController extends Controller
{
    /**
     * The dispute form.
     *
     * Reachable from a paid_claimed trade only. Reaching it otherwise would ask a trader to describe a
     * problem with a trade they have not paid for, and the ledger would refuse it after the fact.
     */
    public function create(Request $request, int $trade, TradeLedger $ledger): View
    {
        $row = $this->disputableTrade($request, $trade, $ledger);

        return view('trade.dispute', ['trade' => $row]);
    }

    /**
     * File it.
     *
     * The reason is required and trimmed. It is stored verbatim in the hash chain: this is the one place
     * where the application's opinion does not get to edit what a trader said.
     */
    public function store(Request $request, int $trade, TradeLedger $ledger, TradeDesk $desk): RedirectResponse
    {
        $row = $this->disputableTrade($request, $trade, $ledger);

        $validated = $request->validate([
            // Ten characters, not one. "lost" is a feeling; an operator cannot investigate a feeling,
            // and a trade cannot be judged on one.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $desk->raiseDispute($row->id, $request->user('trader')->email, $validated['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        // Straight to the trade, by name. Not back(): back() follows the Referer header, which means the
        // destination depends on a header the browser controls -- a stale tab or a missing one sends the
        // trader to the site root instead of the trade they are filing about.
        return redirect()
            ->route('trade.show', $row->id)
            ->with('notice', 'Your account of what happened has been recorded. Operations will look at it.');
    }

    /**
     * Trades this request may dispute: the trader's own, already paid and claimed, and not already
     * disputed.
     *
     * The state check is here for a good error message. The ledger refuses all of it anyway -- there is
     * no trade_rule row for open -> disputed, and a trade cannot be disputed twice -- so this is clarity
     * rather than the thing standing between a trader and a broken record.
     */
    private function disputableTrade(Request $request, int $trade, TradeLedger $ledger): object
    {
        // Both parties, and by the same method the trade screen uses. A seller is quite often the party
        // who is waiting to be paid -- on a vendor trade they are the recipient, and the whole of the
        // Checked tier rests on nobody else being able to say the money reached them -- so a rule that let
        // only the buyer report a problem was a rule that let the wrong person be robbed quietly.
        $row = TradeAccess::party($request, $trade, $ledger);

        abort_unless(
            in_array($row->state, ['paid_claimed', 'paid_attested'], true),
            422,
            'You can only report a problem with a trade you have paid for.'
        );

        return $row;
    }
}

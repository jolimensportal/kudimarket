<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RateBoard;
use App\Services\RemittanceDesk;
use App\Services\TradeFigures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Sending money to someone.
 *
 * Separate from BuyController and SellToUsController rather than another mode on either, for the
 * reason those two are separate from each other: every place the words matter differs, and a shared
 * method with a flag is how "We are the counterparty" ends up printed on a screen where KudiMarket
 * is not the counterparty. A send names a person on the other end, so a stray "buyer", "seller",
 * "offer" or "escrow" on this screen is not a word choice -- it describes a transaction model this
 * product does not have.
 *
 * There is no confirm, no release and no payout method here. A sender declares they paid; operations
 * confirm the money arrived; operations record the handover. The first is here, the other two are in
 * TradeLedger, and that split is the reason a sender can never mark their own money as delivered.
 */
final class SendController extends Controller
{
    public function create(Request $request, RemittanceDesk $desk): View
    {
        $sendMinor = $this->minorFromQuery($request->query('amount'));

        try {
            $quote = $sendMinor > 0 ? $desk->quote($sendMinor) : null;
        } catch (RuntimeException $e) {
            // No rate published yet. The screen says so rather than showing a zero, because "GHS 0.00"
            // reads like an offer of nothing and is not one.
            return view('send.create', ['quote' => null, 'sendMinor' => 0, 'notice' => $e->getMessage()]);
        }

        return view('send.create', ['quote' => $quote, 'sendMinor' => $sendMinor, 'notice' => null]);
    }

    public function store(Request $request, RemittanceDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'send_amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            // Shape is validated HERE, on the field it belongs to, rather than thrown from the service
            // and attached to the amount. Routing every refusal to send_amount meant a sender who typed
            // an undialable phone number was told their AMOUNT was wrong, three fields away from the
            // input that caused it.
            'beneficiary_name' => ['required', 'string', 'min:2', 'max:120'],
            'beneficiary_phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ]);

        // A decimal STRING throughout, never a float. 0.1 has no exact binary representation, so a
        // money amount round-tripped through a float loses pesewas -- and this is the leg that leaves
        // the sender.
        $sendMinor = RateBoard::toMinor($data['send_amount'], 'GHS');

        try {
            $tradeId = $desk->open(
                $request->user('trader')->email,
                $sendMinor,
                $data['beneficiary_name'],
                $data['beneficiary_phone'],
            );
        } catch (RuntimeException $e) {
            // Only a refusal about the money is the amount's fault. Everything the field rules above
            // could not catch is still about the amount, so this one key is right -- but the message
            // has to survive to the sender rather than being swallowed into a generic failure.
            return back()->withErrors(['send_amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('trade.send.show', $tradeId)
            ->with('notice', 'Opened. Send the cedi, then tell us it has gone.');
    }

    public function show(Request $request, RemittanceDesk $desk, int $send): View
    {
        $record = $desk->show($send);

        // A send that does not exist and a send that belongs to somebody else are the same answer.
        // Telling them apart would confirm that a given send id is real to anyone who typed it.
        if ($record === null || $record->buyer !== $request->user('trader')->email) {
            abort(404);
        }

        // The figures come from the SAME TradeFigures service the buy and sell screens use, on the
        // row the ledger holds rather than a fresh quote. A send is priced at the rate that was frozen
        // when it was opened, and quoting today's rate on the page would disagree with the trade.
        $figures = TradeFigures::forTrade((object) [
            'id' => $record->id,
            'fiat_amount' => $record->fiat_amount,
            'fiat_asset' => $record->fiat_asset,
            'rate' => $record->rate,
            'rate_base_asset' => $record->rate_base_asset,
            'rate_quote_asset' => $record->rate_quote_asset,
            'quoted_fee_minor' => null,
            'fee_asset' => $record->fiat_asset,
            'fee_bearer' => 'buyer',
            'fee_minor_units' => null,
        ], 'buyer');

        return view('send.show', [
            'send' => $record,
            'figures' => $figures,
        ]);
    }

    /** The amount on the create screen comes off the query string, priced and formatted. */
    private function minorFromQuery(mixed $amount): int
    {
        if (! is_string($amount) || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) !== 1) {
            return 0;
        }

        return RateBoard::toMinor($amount, 'GHS');
    }
}

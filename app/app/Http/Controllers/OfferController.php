<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OfferDesk;
use App\Services\RateBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The seller's side of the market.
 *
 * Three screens: where you stand, what you have on offer, and what other people have on offer. The buy
 * side lives in TradeController and the two meet at a trade, which is the only place either is settled.
 */
class OfferController extends Controller
{
    /**
     * Where you stand.
     *
     * The held balance is deliberately absent -- see OfferDesk::position() for why it cannot be read
     * safely today. Everything here comes from the trades and offers this trader can already see.
     */
    public function position(Request $request, OfferDesk $desk): View
    {
        return view('offer.position', [
            'position' => $desk->position(),
            'mine' => $desk->mine($this->email()),
            'rates' => $this->boardRates(),
        ]);
    }

    /** What other people have on offer, newest first. */
    public function board(OfferDesk $desk): View
    {
        $offers = $desk->board();
        $rates = $this->boardRates();
        $me = $this->email();

        // Whether the board will clear each offer is computed here, once, and the reason is shown either
        // way. An offer a trader cannot take is still worth seeing with its rate attached: an unexplained
        // grey row teaches nothing, and "our rate moved" is more use than a number they cannot act on.
        foreach ($offers as $offer) {
            $board = $rates[$offer->base_asset.'/'.$offer->quote_asset] ?? null;

            $offer->board_rate = $board?->rate;
            $offer->clearable = $board !== null && (float) $board->rate >= (float) $offer->min_rate;
            $offer->will_not_clear = $board !== null && ! $offer->clearable;
        }

        return view('offer.board', [
            'offers' => $offers,
            'rates' => $rates,
            'me' => $me,
        ]);
    }

    /** The form for listing currency for sale. */
    public function create(OfferDesk $desk): View
    {
        return view('offer.create', [
            'rates' => $this->boardRates(),
            'mine' => $desk->mine($this->email()),
        ]);
    }

    /**
     * Publish it.
     *
     * The amount arrives in MINOR units and the walk-away as a decimal string. Neither is cast to a
     * float: a rate that loses its last digit before it reaches the database is a rate that no longer
     * matches the card it was measured against.
     */
    public function store(Request $request, OfferDesk $desk): RedirectResponse
    {
        $validated = $request->validate([
            'base_asset' => ['required', 'string', 'size:3'],
            'quote_asset' => ['required', 'string', 'size:3', 'different:base_asset'],
            // Minor units, so 50000000 means ₦500,000.00. Never a major amount with two decimals typed
            // by a person, which is how a trade ends up for ₦500,000.5.
            'amount_minor' => ['required', 'integer', 'min:1'],
            // A decimal string, compared as a string so nothing reinterprets it.
            'min_rate' => ['required', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
            'lifetime' => ['nullable', 'string', 'in:5 minutes,15 minutes,1 hour'],
        ], [
            'min_rate.regex' => 'A rate is a number, like 0.00675. Not a currency symbol and no thousands separator.',
            'quote_asset.different' => 'An offer needs two different currencies.',
            'amount_minor.min' => 'Say how much you want to sell.',
        ]);

        try {
            $id = $desk->publish(
                $validated['base_asset'],
                $validated['quote_asset'],
                (int) $validated['amount_minor'],
                $validated['min_rate'],
                $validated['lifetime'] ?? '15 minutes'
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['min_rate' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('offer.board')
            ->with('notice', 'Your offer is up. It clears at our rate, not the one you asked for, and it stands for '.($validated['lifetime'] ?? '15 minutes').'.');
    }

    /** Take it back off the board. */
    public function withdraw(Request $request, int $offer, OfferDesk $desk): RedirectResponse
    {
        abort_unless(Auth::guard('trader')->check(), 403);

        $this->assertOwns($desk, $offer);

        try {
            $desk->withdraw($offer);
        } catch (RuntimeException $e) {
            return back()->with('notice', $e->getMessage());
        }

        return back()->with('notice', 'Withdrawn. What you had offered stays on the record.');
    }

    /** Take the other side of somebody else's offer. */
    public function accept(Request $request, int $offer, OfferDesk $desk): RedirectResponse
    {
        abort_unless(Auth::guard('trader')->check(), 403);

        $row = $desk->offer($offer);
        abort_if($row === null, 404);

        try {
            $trade = $desk->accept($offer);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('offer.board')
                ->with('notice', $e->getMessage());
        }

        // Straight to the trade. It exists, it is theirs, and it is the only place either side is settled.
        return redirect()
            ->route('trade.show', $trade)
            ->with('notice', 'Taken. Both sides are in the trade record now.');
    }

    private function email(): string
    {
        return Auth::guard('trader')->user()?->email ?? '';
    }

    /** Trades are the trader's own to see; an offer is not, so ownership is checked before withdrawal. */
    private function assertOwns(OfferDesk $desk, int $offer): void
    {
        $row = $desk->offer($offer);

        abort_if($row === null, 404);
        abort_unless($row->seller === $this->email(), 403, 'That offer is not yours.');
    }

    /**
     * Every rate currently on the card, keyed "BASE/QUOTE".
     *
     * One query for the whole screen rather than one per offer: this board is read on a bad connection,
     * where a query per row is the difference between loading and not.
     *
     * @return array<string, object>
     */
    private function boardRates(): array
    {
        return app(RateBoard::class)->currentRates();
    }
}

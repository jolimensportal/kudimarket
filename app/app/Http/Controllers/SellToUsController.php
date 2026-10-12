<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RateBoard;
use App\Services\TradeDesk;
use App\Services\TradeFigures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Selling currency to KudiMarket.
 *
 * The mirror of BuyController, and deliberately kept a separate class rather than a flag on one: the two
 * screens have different words in every place that matters, and a shared method with a boolean is how
 * "We are the counterparty" ends up printed on the screen where KudiMarket is not the counterparty.
 *
 * Note what this controller does NOT have: an attest action. On a buy the trader declares and operations
 * confirm; on a sell the trader declares and operations confirm. Same split, opposite side of the trade.
 * The confirmation is the one thing a payer may never do, and it stays in the operations console.
 */
final class SellToUsController extends Controller
{
    public function create(Request $request): View
    {
        $receiveAsset = strtoupper((string) $request->query('receive', TradeDesk::BOOK[1]));

        try {
            $quote = $this->quote(RateBoard::toMinor('1000', $receiveAsset), $receiveAsset);
        } catch (RuntimeException) {
            // No rate for that pair yet. The screen says so rather than rendering a zero, because "C0.00"
            // reads like an offer and is not one. Identical to the buy screen's fallback.
            return view('sell-to-us.create', [
                'receiveAsset' => $receiveAsset,
                'figures' => null,
                'notice' => 'We have not published a rate for that pair yet.',
            ]);
        }

        return view('sell-to-us.create', [
            'receiveAsset' => $receiveAsset,
            'figures' => $quote,
            'notice' => null,
        ]);
    }

    /**
     * The price of a sell that has not been opened yet, built through the ONE rule the trade screen uses.
     *
     * A trader selling is a buyer from the quote's point of view -- they receive the leg they named and
     * pay the other -- so forTrade(..., 'buyer') is correct on both screens. That is not a coincidence to
     * be documented and forgotten; it is the same relationship, and the same service, seen from two sides.
     * Two implementations of a currency rule is how one of them goes stale, which is how the rate-direction
     * bug shipped through 286 passing tests.
     *
     * @return array<string, mixed>
     */
    private function quote(int $receiveMinorUnits, string $receiveAsset): array
    {
        $receiveAsset = strtoupper(trim($receiveAsset));
        $payAsset = $receiveAsset === 'NGN' ? 'GHS' : 'NGN';

        // Off the card, never from the request. A trader who can choose their own rate chooses their price.
        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate, base_asset, quote_asset from current_rate where base_asset = ? and quote_asset = ?',
            [$payAsset, $receiveAsset]
        );

        if ($published === null) {
            throw new RuntimeException("We have not published a rate for {$payAsset}/{$receiveAsset} yet.");
        }

        $row = (object) [
            'id' => 0,
            'fiat_amount' => (string) $receiveMinorUnits,
            'fiat_asset' => $receiveAsset,
            'rate' => (string) $published->rate,
            'rate_base_asset' => $published->base_asset,
            'rate_quote_asset' => $published->quote_asset,
        ];

        return TradeFigures::forTrade($row, 'buyer');
    }

    public function store(Request $request, TradeDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'receive_amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'receive_asset' => ['required', 'string', 'in:GHS,NGN'],
        ]);

        $receiveAsset = $data['receive_asset'];

        // A decimal STRING throughout. 0.1 has no exact binary representation, so a money amount
        // round-tripped through a float loses pesewas -- and this is the leg that leaves the trader.
        $receiveMinor = RateBoard::toMinor($data['receive_amount'], $receiveAsset);

        try {
            $tradeId = $desk->sellToUs($request->user('trader')->email, $receiveMinor, $receiveAsset);
        } catch (RuntimeException $e) {
            return back()->withErrors(['receive_amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('trade.show', $tradeId)
            ->with('notice', 'Opened. Send the other side, then tell us it has gone.');
    }
}

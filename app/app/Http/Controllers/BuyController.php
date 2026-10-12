<?php

namespace App\Http\Controllers;

use App\Services\RateBoard;
use App\Services\TradeDesk;
use App\Services\TradeFigures;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Buying currency from KudiMarket's own book. We are the counterparty, so this is the tier we guarantee.
 *
 * brand/product.html: "KudiMarket buys currency from traders and sells to other traders out of its own
 * float." and "Which pairs do we quote? Cedi against naira, BOTH DIRECTIONS."
 *
 * Until this existed, TradeDesk::openAgainstUs was called only from tests. The function was correct, tested
 * and unreachable, which is the same as absent: the tier we actually stand behind had no way in.
 */
final class BuyController extends Controller
{
    /**
     * The quote for a buy that has not been opened yet: what they pay, what they receive, and our rate.
     *
     * Shown before the form is submitted, because a trader is owed the price before they commit to it, and
     * because a number that only appears after pressing a button is a number they cannot check.
     *
     * @return array<string, mixed>
     */
    private function quote(int $receiveMinorUnits, string $receiveAsset): array
    {
        $receiveAsset = strtoupper(trim($receiveAsset));
        $payAsset = $receiveAsset === 'NGN' ? 'GHS' : 'NGN';

        // The rate comes off the card, never from the request. A trader who can choose their own rate can
        // choose their own price.
        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate, base_asset, quote_asset from current_rate where base_asset = ? and quote_asset = ?',
            [$payAsset, $receiveAsset]
        );

        if ($published === null) {
            throw new RuntimeException("We have not published a rate for {$payAsset}/{$receiveAsset} yet.");
        }

        // Built as a row and put through the same code the trade screen uses, so the price shown on the
        // form is produced by the one rule rather than a second copy of it. Two implementations of a
        // currency rule is how one of them goes stale, which is how this bug happened four times.
        $row = (object) [
            'id' => 0,
            'fiat_amount' => (string) $receiveMinorUnits,
            'fiat_asset' => $receiveAsset,
            'rate' => (string) $published->rate,
            'rate_base_asset' => $published->base_asset,
            'rate_quote_asset' => $published->quote_asset,
            // The commission is priced for the PREVIEW from the live policy, because there is no trade
            // yet to have frozen it. A real trade reads its own frozen column and never this.
            // The commission is priced BY THE LEDGER, not here. commission_quote() applies the same
            // div() the freeze trigger applies, so the figure on the form and the figure charged are the
            // same number rather than two implementations that agree until the last digit.
            'quoted_fee_minor' => (int) DB::connection('pgsql_ops')->selectOne(
                'select commission_quote(?, ?, (select fee_bps from trade_policy where trade_type = ?)) as fee',
                [(string) $receiveMinorUnits, (string) $published->rate, 'platform_inventory']
            )->fee,
        ];

        // forTrade derives the commission from fee_bps for a row that has none frozen, which is exactly
        // what a preview is, so the form's total and the opened trade's total come from one rule.
        return ['figures' => TradeFigures::forTrade($row, 'buyer'), 'pay_asset' => $payAsset];
    }

    public function create(Request $request): View|RedirectResponse
    {
        $receiveAsset = strtoupper((string) $request->query('receive', TradeDesk::BOOK[0]));

        try {
            $quote = $this->quote(RateBoard::toMinor('1000', $receiveAsset), $receiveAsset);
        } catch (RuntimeException) {
            // No rate for this pair yet. The screen says so rather than rendering a zero, because "C0.00"
            // reads like an offer and is not one.
            return view('buy.create', [
                'receiveAsset' => $receiveAsset,
                'figures' => null,
                'notice' => 'We have not published a rate for that pair yet.',
            ]);
        }

        return view('buy.create', [
            'receiveAsset' => $receiveAsset,
            'figures' => $quote['figures'],
            'notice' => null,
        ]);
    }

    public function store(Request $request, TradeDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'receive_amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'receive_asset' => ['required', 'string', 'in:GHS,NGN'],
        ]);

        $receiveAsset = $data['receive_asset'];

        // The amount is a string of decimals and never a float. 0.1 is not representable in binary, so
        // round-tripping a money amount through one loses pesewas -- the same class of bug as dividing
        // minor units by a major-per-major rate, which this file exists because of.
        $receiveMinor = RateBoard::toMinor($data['receive_amount'], $receiveAsset);

        try {
            $tradeId = $desk->openAgainstUs($request->user('trader')->email, $receiveMinor, $receiveAsset);
        } catch (RuntimeException $e) {
            return back()->withErrors(['receive_amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('trade.show', $tradeId)
            ->with('notice', 'Opened. Send the other side, then tell us it has gone.');
    }
}

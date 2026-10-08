<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TradeController extends Controller
{
    public function show(Request $request, int $trade, TradeLedger $ledger): View
    {
        $record = $ledger->trade($trade);

        // 404 for a trade that does not exist; 403 for one that belongs to somebody else. Deliberately
        // different. Merging them would either leak which trade ids are real, or make a mistyped link
        // look like an attack.
        if ($record === null) {
            abort(404);
        }

        // Ownership is checked here rather than in a query filter, so the refusal has a cause instead
        // of being an empty page. The id in the URL is the trader's own; it is not a secret, so the
        // check is for clarity rather than concealment.
        if ($record->buyer !== $request->user('trader')?->email) {
            throw new AccessDeniedHttpException('That trade belongs to somebody else.');
        }

        return view('trade.show', [
            'trade' => $record,
            'events' => $ledger->events($trade),
        ]);
    }
}

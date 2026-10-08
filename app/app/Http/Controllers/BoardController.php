<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OfferDesk;
use App\Services\RateBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BoardController extends Controller
{
    public function __invoke(Request $request, RateBoard $board): View
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        // Anything not on the list falls back to the cedi/naira board. A query string is user input,
        // and an unknown pair is not a reason to render a broken screen.
        // Anything we do not trade falls back to the cedi/naira board rather than erroring. A query
        // string is user input, and an unknown pair is not a reason to render a broken screen -- but
        // USD is accepted as an input because "we have not published USD/NGN" is the honest answer
        // for a pair we have not opened yet, and a trader asking about one deserves that answer
        // rather than a cedi board they did not ask for.
        $pairs = ['GHS', 'NGN', 'USD'];

        if (! in_array($from, $pairs, true)) {
            $from = 'GHS';
        }

        if ($to === '' || $from === $to || ! in_array($to, $pairs, true)) {
            $to = match ($from) {
                'GHS' => 'NGN',
                'NGN' => 'GHS',
                default => 'GHS',
            };
        }

        return view('board.index', [
            'base' => $from,
            'quote' => $to,
            'ours' => $board->ourRateForDisplay($from, $to),
            // From the offer book, not from open vendor_vendor trades.
            //
            // The old source read "offers" as any open vendor_vendor trade and ordered them by the RATE
            // the seller had chosen, so the buyer's board displayed seller-set prices. That is a matching
            // platform: the buyer picks a stranger's price. brand/product.html rules it out in writing --
            // KudiMarket quotes its own rate -- so the board now shows what people will ACCEPT, against
            // our rate, which is a different thing and the thing that is actually true.
            'offers' => app(OfferDesk::class)->board(),
            'legs' => $board->forPair($from, $to),
        ]);
    }
}

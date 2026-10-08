<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
            'offers' => $board->offers($to, $from),
            'legs' => $board->forPair($from, $to),
        ]);
    }
}

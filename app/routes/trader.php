<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

/*
 * The trader app. Everything here is behind auth, and every write goes to a stored procedure, so a
 * route being reachable is never the thing that decides whether money moves.
 *
 * The prefix is /trade because these are trades, not a dashboard. The board is where a trader starts
 * and it is the first thing that loads on a bad connection, so it stays simple.
 *
 * This file grows one route group at a time as the plan lands. Nothing is stubbed: a route that exists
 * is a route that has been built and tested, because a 200 from a stub is a screen nobody can finish.
 */
Route::middleware('auth:trader')->prefix('trade')->name('trade.')->group(function (): void {
    Route::get('/board', BoardController::class)->name('board');

    Route::get('/{trade}', [TradeController::class, 'show'])->whereNumber('trade')->name('show');
    Route::post('/{trade}/claim', [TradeController::class, 'claim'])->whereNumber('trade')->name('claim');

    // Disputing is GET to read the form and POST to file it. Deliberately no resolve route for a trader:
    // adjudication is operations' alone, and the capability is absent rather than refused.
    Route::get('/{trade}/dispute', [DisputeController::class, 'create'])->whereNumber('trade')->name('dispute');
    Route::post('/{trade}/dispute', [DisputeController::class, 'store'])->whereNumber('trade')->name('dispute');
});

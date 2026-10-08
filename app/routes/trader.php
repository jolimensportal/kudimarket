<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\OfferController;
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

    // The seller's side. /sell/new rather than /new so the buy path and the sell path stay legible in the
    // URL bar: a trader who mistyped one wants to see which they are on before they commit to anything.
    Route::get('/sell', [OfferController::class, 'position'])->name('offer.position');
    Route::get('/sell/new', [OfferController::class, 'create'])->name('offer.create');
    Route::post('/sell/new', [OfferController::class, 'store'])->name('offer.store');
    Route::get('/offers', [OfferController::class, 'board'])->name('offer.board');
    Route::post('/offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->whereNumber('offer')->name('offer.withdraw');
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept'])->whereNumber('offer')->name('offer.accept');

    Route::get('/{trade}', [TradeController::class, 'show'])->whereNumber('trade')->name('show');
    Route::post('/{trade}/claim', [TradeController::class, 'claim'])->whereNumber('trade')->name('claim');

    // Disputing is GET to read the form and POST to file it. Deliberately no resolve route for a trader:
    // adjudication is operations' alone, and the capability is absent rather than refused.
    Route::get('/{trade}/dispute', [DisputeController::class, 'create'])->whereNumber('trade')->name('dispute');
    Route::post('/{trade}/dispute', [DisputeController::class, 'store'])->whereNumber('trade')->name('dispute');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
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
});

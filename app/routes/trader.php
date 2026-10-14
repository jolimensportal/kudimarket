<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use App\Http\Controllers\BuyController;
use App\Http\Controllers\CddController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\SellToUsController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\SignInAsTrader;
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
// auth:trader first, so the identity is set from a session that already exists. The other order would ask
// the database who is signed in before anybody has signed in.
Route::middleware(['auth:trader', SignInAsTrader::class])
    ->prefix('trade')->name('trade.')->group(function (): void {
        Route::get('/board', BoardController::class)->name('board');

        // The seller's side. /sell/new rather than /new so the buy path and the sell path stay legible in the
        // URL bar: a trader who mistyped one wants to see which they are on before they commit to anything.
        // Getting checked. This is the only route that changes what a trader may DO elsewhere: it decides
        // whether publish_offer will accept them. It is separate from selling so the route says plainly which
        // of the two is being asked for.
        Route::get('/check', [VerificationController::class, 'show'])->name('verification');
        Route::get('/check/new', [VerificationController::class, 'create'])->name('verification.apply');
        Route::post('/check/new', [VerificationController::class, 'store'])->name('verification.store');

        // Customer due diligence. A SEPARATE line from /check above, on purpose: /check is the trust tier
        // that decides whether you may list an offer, and /id is the AML duty that decides whether we can
        // do business with you at all. They are different questions for different regulators, and one URL
        // per question is the cheapest way to stop them being confused.
        Route::get('/id', [CddController::class, 'show'])->name('cdd.show');
        Route::get('/id/new', [CddController::class, 'create'])->name('cdd.submit');
        Route::post('/id/new', [CddController::class, 'store'])->name('cdd.store');

        Route::get('/sell', [OfferController::class, 'position'])->name('offer.position');
        Route::get('/sell/new', [OfferController::class, 'create'])->name('offer.create');
        Route::post('/sell/new', [OfferController::class, 'store'])->name('offer.store');
        Route::get('/offers', [OfferController::class, 'board'])->name('offer.board');
        Route::post('/offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->whereNumber('offer')->name('offer.withdraw');
        Route::post('/offers/{offer}/accept', [OfferController::class, 'accept'])->whereNumber('offer')->name('offer.accept');

        // Buying from KudiMarket's own book: we are the counterparty, so this is the tier we guarantee. The
        // other board routes live above the '/{trade}' wildcard deliberately -- a literal path that starts
        // with a number-free segment still loses to a number wildcard the moment the segment parses as a digit.
        Route::get('/buy', [BuyController::class, 'create'])->name('buy.create');
        Route::post('/buy', [BuyController::class, 'store'])->name('buy.store');

        // Every trade this trader is a party to, newest first. Literal path, above the '/{trade}' wildcard
        // for the same reason the buy/sell routes are: a named segment must not fall through to the number
        // route. 'history' rather than 'trades' because /trades read like it might be everyone's.
        Route::get('/history', [TradeController::class, 'index'])->name('history');

        // Selling TO us, which is the other half of the handled tier and a different promise from selling
        // an offer to other traders below. /sell-to-us rather than /sell, because /sell is taken and means
        // something else entirely: a trader who mistyped one wants to see which they are on before they
        // commit to anything. Same reasoning as the buy/sell split the comment above describes.
        Route::get('/sell-to-us', [SellToUsController::class, 'create'])->name('sell.create');
        Route::post('/sell-to-us', [SellToUsController::class, 'store'])->name('sell.store');

        Route::get('/{trade}', [TradeController::class, 'show'])->whereNumber('trade')->name('show');
        Route::post('/{trade}/claim', [TradeController::class, 'claim'])->whereNumber('trade')->name('claim');

        // Disputing is GET to read the form and POST to file it. Deliberately no resolve route for a trader:
        // adjudication is operations' alone, and the capability is absent rather than refused.
        //
        // The POST is named dispute.store, not dispute. Two routes cannot share a name, and route:cache --
        // which only ever runs on deploy -- refuses to serialise a duplicate with "Another route has already
        // been assigned name [trade.dispute]". Tests never caught it because nothing in the suite caches
        // routes. GET keeps the plain name so the link and the URL stay what they were.
        Route::get('/{trade}/dispute', [DisputeController::class, 'create'])->whereNumber('trade')->name('dispute');
        Route::post('/{trade}/dispute', [DisputeController::class, 'store'])->whereNumber('trade')->name('dispute.store');
    });

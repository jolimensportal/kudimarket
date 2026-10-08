<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * The root is not a landing page.
 *
 * It was Laravel's stock welcome screen, which is how someone who had just signed in ended up looking
 * at "Laravel" and reasonably concluding the login had failed. Two rules:
 *
 *   signed in  -> the board, which is where a trader is trying to get to
 *   signed out -> sign in, which is the only other thing that makes sense
 *
 * There is no marketing page here. KudiMarket's public face is the board, and a trader who lands
 * anywhere else is a trader who leaves.
 */
Route::get('/', function () {
    if (Auth::guard('trader')->check()) {
        return redirect()->route('trade.board');
    }

    return redirect()->route('login');
})->name('home');

/*
 * Where an unauthenticated trader is sent. Named 'login' because that is what the auth middleware
 * looks for by default; without it an expired session is a 500 instead of a redirect, which is a
 * confusing way to learn that your session ended.
 */
Route::get('/sign-in', fn () => view('trader.sign-in'))
    ->name('login')
    ->middleware('guest:trader');

Route::post('/sign-in', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (! Auth::guard('trader')->attempt($credentials, $request->boolean('remember'))) {
        return back()->withErrors(['email' => 'That email and password do not match.'])->onlyInput('email');
    }

    $request->session()->regenerate();

    return redirect()->intended(route('trade.board'));
})->middleware('guest:trader');

Route::post('/sign-out', function (Request $request) {
    Auth::guard('trader')->logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('sign-out')->middleware('auth:trader');

/*
 * The trader app lives in its own file so the operations console and the customer-facing surface can
 * be reasoned about separately. One file each, because they share nothing but the database.
 */
require __DIR__.'/trader.php';

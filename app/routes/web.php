<?php

declare(strict_types=1);

use App\Http\Controllers\LandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * The root, which is either the landing page or the board.
 *
 * It was Laravel's stock welcome screen, which is how someone who had just signed in ended up looking
 * at "Laravel" and reasonably concluding the login had failed. Two rules:
 *
 *   signed in  -> the board, which is where a trader is trying to go
 *   signed out -> sign in, which is the only other thing that makes sense
 *
 * EXCEPT while config('kudi.launching') is true. KudiMarket is not yet licensed to take the other
 * side for its own account -- brand/REGULATORY.md 5b records the question and it is still open --
 * so until that is answered the root is the landing page and the trader app is closed. That is a
 * config switch rather than a branch in the code because turning it on is a decision that has to be
 * reversible in seconds.
 */
Route::get('/', function () {
    if (config('kudi.launching')) {
        return app(LandingController::class)(request());
    }

    if (Auth::guard('trader')->check()) {
        return redirect()->route('trade.board');
    }

    return redirect()->route('login');
})->name('home');

/*
 * The public landing page. Unversioned on purpose: it is what kudimarket.com serves, and a version
 * in the URL would say to a visitor that there is something newer they are not seeing.
 */
Route::get('/about', LandingController::class)->name('landing');

Route::post('/about', [LandingController::class, 'subscribe'])->name('landing.subscribe');

/*
 * While the landing page is up, an unauthenticated trader who follows a link into the app is not
 * going to find a sign-in form they cannot use -- there are no accounts yet, and there should not
 * be. They go back to the page that explains why. Bounced to the root rather than shown a login
 * that cannot succeed.
 */
Route::get('/sign-in', function () {
    if (config('kudi.launching')) {
        return redirect()->route('landing');
    }

    return view('trader.sign-in');
})
    ->name('login')
    ->middleware('guest:trader');

/*
 * Signing in. The POST is closed while the landing page is up for the same reason the GET is: there
 * are no accounts to sign in with, and a form that cannot succeed is worse than no form.
 */
Route::post('/sign-in', function (Request $request) {
    if (config('kudi.launching')) {
        return redirect()->route('landing');
    }

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

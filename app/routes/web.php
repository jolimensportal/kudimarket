<?php

declare(strict_types=1);

use App\Http\Controllers\LandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * The root: the landing page for everyone, and the board only for a signed-in trader.
 *
 * It was Laravel's stock welcome screen, which is how someone who had just signed in ended up looking
 * at "Laravel" and reasonably concluding the login had failed.
 *
 * The landing page here is NOT conditional on config('kudi.launching'), and that used to be a mistake.
 * The flag was doing two jobs: closing the trader app, and deciding whether the public root was the
 * pitch. Those are different decisions, and coupling them meant that opening the app for anyone to look
 * at -- one config flip -- also removed the pitch from the front door. A stranger arriving at
 * kudimarket.com got a sign-in form for a product they had not been told about yet.
 *
 * The flag still closes the app: /sign-in redirects to the pitch while it is true. brand/REGULATORY.md
 * 5b is why it exists at all -- KudiMarket is not yet licensed to take the other side for its own
 * account, and the broker conditions forbid exactly the handled tier's economics. What is licensed is a
 * question about which business this is, so the switch stays a config value rather than a branch in the
 * code: closing it has to be reversible in seconds.
 */
Route::get('/', function () {
    if (Auth::guard('trader')->check()) {
        return redirect()->route('trade.board');
    }

    return app(LandingController::class)(request());
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

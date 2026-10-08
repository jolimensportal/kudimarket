<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The trader app lives in its own file so the operations console and the customer-facing surface can
 * be reasoned about separately. One file each, because they share nothing but the database.
 */
require __DIR__.'/trader.php';

/*
 * Where an unauthenticated trader is sent. Named 'login' because that is what the auth middleware
 * looks for by default; without it an expired session is a 500 instead of a redirect, which is a
 * confusing way to learn that your session ended.
 */
Route::get('/sign-in', function () {
    return view('trader.sign-in');
})->name('login')->middleware('guest:trader');

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

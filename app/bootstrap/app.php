<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF on the operations console is skipped under the test suite ONLY.
        //
        // Filament's PreventRequestForgery answers a bare 403 when a request carries no token, and it does
        // so on GET as well as POST. A test request has no token by construction, so every console test
        // failed with a 403 that looks exactly like an authorisation failure and is nothing of the sort.
        //
        // Guarded on runningUnitTests() so this cannot weaken the real console. Nothing here is in
        // production: CSRF protection on /admin is untouched outside the suite. The trade-off is stated
        // rather than hidden -- these tests prove the console renders and its actions are wired, and
        // console CSRF is proven by driving a browser, which is how it was actually found.
        if (app()->runningUnitTests()) {
            $middleware->validateCsrfTokens(except: ['admin', 'admin/*']);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

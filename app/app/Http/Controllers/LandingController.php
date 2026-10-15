<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The public face, for the period before we are allowed to trade.
 *
 * brand/REGULATORY.md 5b records why this page exists at all: whether KudiMarket may take the other
 * side for its own account is the difference between a licensed dealer and an FX broker, and it is
 * not settled. Until counsel answers, the domain has to do something honest rather than nothing.
 * A bare sign-in form told a visitor nothing; this tells them what the product is and that we are not
 * open yet, and takes an address for the day we are.
 */
final class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('public.landing');
    }

    /**
     * Take an address for the day we open.
     *
     * updateOrInsert rather than insert so pressing the button twice on a bad connection is a no-op
     * instead of a unique-constraint error page. The unique index on email is what makes that
     * correct; without it this would be the one place on the site a user can cause a 500.
     *
     * No mail is sent. There is no mail transport configured and no sequence to send; the address
     * exists so that the day we open, someone can write to the people who asked to be told.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        DB::table('launch_signups')->updateOrInsert(
            ['email' => strtolower(trim($data['email']))],
            ['created_at' => now()]
        );

        return back()->with('subscribed', true);
    }
}

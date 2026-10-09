<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\VerificationDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The trader's half of being checked: ask, and see where you stand.
 */
class VerificationController extends Controller
{
    /**
     * Where you stand.
     *
     * This screen exists for the refusal case as much as the pending one. Somebody told "you need to be
     * checked" and given no way to find out why, or what to do about it, has been handed a dead end --
     * and the refusal reason is the whole answer, so it is on this page in full.
     */
    public function show(Request $request, VerificationDesk $desk): View
    {
        return view('verification.show', [
            'status' => $desk->status(),
            'trader' => $request->user('trader'),
        ]);
    }

    public function create(Request $request, VerificationDesk $desk): View|RedirectResponse
    {
        $status = $desk->status();

        // Someone who is already checked does not need the form, and a verified trader re-applying is
        // refused by the database. Say so here rather than letting them fill it in and hit the wall.
        if ($status !== null && $status->is_verified) {
            return redirect()
                ->route('trade.offer.position')
                ->with('notice', 'You are already checked.');
        }

        return view('verification.apply', ['status' => $status]);
    }

    public function store(Request $request, VerificationDesk $desk): RedirectResponse
    {
        // min:40, matching what the database will accept, so the trader is told here rather than after a
        // round trip. The number is in one place on this screen: the field says how much, the error says
        // how much, and the rule says how much.
        $validated = $request->validate([
            'details' => ['required', 'string', 'min:40', 'max:4000'],
        ], [
            'details.min' => 'Tell us a bit more — at least a sentence. We check identity, address, the account in your own name, where the money came from, and your Ghana Card number.',
        ]);

        try {
            $desk->apply($validated['details']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['details' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('trade.verification')
            ->with('notice', 'Sent. We will be in touch once it has been looked at.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CddDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The trader's half of customer due diligence: give us your identity, where the money comes from, and
 * what you are doing here.
 *
 * Deliberately a separate controller from VerificationController, on a separate URL, with its own
 * screens. They answer different questions to different regulators and merging them is how a trust badge
 * ends up being read as an AML control. Nothing on these screens says "verified" and nothing here affects
 * what a trader may trade -- see the SQL suite, which asserts a fully CDD-verified trader still cannot
 * publish an offer.
 */
class CddController extends Controller
{
    /**
     * Where you stand.
     *
     * Present for the refusal case as much as the pending one. A refusal with no reason is a dead end,
     * so the reason is on this page in full -- the same reasoning as verification/show.
     */
    public function show(CddDesk $desk): View
    {
        return view('cdd.show', [
            'status' => $desk->status(),
        ]);
    }

    public function create(CddDesk $desk): View|RedirectResponse
    {
        $status = $desk->status();

        // Someone already established has no use for this form, and the database would refuse them
        // anyway. Saying so here beats letting them fill in a long form and hit a wall.
        if ($status !== null && $status->is_cdd_verified) {
            return redirect()
                ->route('trade.cdd.show')
                ->with('notice', 'We already have your details on file.');
        }

        return view('cdd.submit', ['status' => $status]);
    }

    public function store(Request $request, CddDesk $desk): RedirectResponse
    {
        // The rules here match the checks in the database so a trader is told now rather than after a
        // round trip -- except the two the AML rule makes mandatory, which are stated in words rather
        // than as a length: telling somebody "at least 20 characters" about where their money comes from
        // is not a rule anyone should be enforcing.
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'in:ghana_card,non_citizen_ecowas,ecowas,passport,other'],
            'doc_number' => ['required', 'string', 'max:64'],
            'doc_issued_on' => ['nullable', 'date_format:Y-m-d'],
            'doc_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'residential_address' => ['required', 'string', 'max:500'],
            'purpose_code' => ['required', 'string', 'in:salary,business,remittance,savings,investment,other'],
            'purpose_detail' => ['nullable', 'string', 'max:2000'],
            'source_of_funds' => ['required', 'string', 'max:2000'],
        ], [
            'doc_type.required' => 'Tell us which document you are giving us.',
            'doc_number.required' => 'We need the document number.',
            'residential_address.required' => 'We need your residential address.',
            'purpose_code.required' => 'Tell us what you are using KudiMarket for.',
            'source_of_funds.required' => 'Tell us where the money comes from. We are required to record this.',
        ]);

        try {
            $desk->submit(
                $validated['doc_type'],
                $validated['doc_number'],
                $validated['doc_issued_on'] ?? null,
                $validated['doc_expires_on'] ?? null,
                $validated['residential_address'],
                $validated['purpose_code'],
                $validated['purpose_detail'] ?? null,
                $validated['source_of_funds'],
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['source_of_funds' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('trade.cdd.show')
            ->with('notice', 'Thank you. We will be in touch once your details have been looked at.');
    }
}

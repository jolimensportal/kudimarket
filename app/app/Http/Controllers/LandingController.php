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
 *
 * The design follows what the converted pre-launch pages have in common rather than what is merely
 * pretty: a real product screen instead of a mockup, the email field above the fold, prices shown
 * rather than implied, and a share link that moves the sharer up a queue. That last one is the whole
 * reason Dropbox reached four million and this page reached one.
 */
final class LandingController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('public.landing', [
            'rates' => $this->publishedRates(),
            'feeBps' => $this->publishedFee(),
            'waiting' => $this->waiting(),
            'referrer' => $this->validReferralCode($request->query('ref')),
        ]);
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

        $email = strtolower(trim($data['email']));

        // A code is only attributed when it actually exists in the list. Without that check a visitor
        // could invent a code and watch their own position inflate, and the position is the one number
        // on this page that has to mean something.
        $referrer = $this->validReferralCode($request->input('ref'));

        DB::table('launch_signups')->updateOrInsert(
            ['email' => $email],
            [
                'created_at' => now(),
                'referral_code' => $this->uniqueCode(),
                'referred_by' => $referrer,
            ]
        );

        $me = DB::table('launch_signups')->where('email', $email)->first();

        return back()->with([
            'subscribed' => true,
            'my_code' => $me->referral_code,
            'my_position' => $this->positionOf($email),
            'my_invites' => DB::table('launch_signups')->where('referred_by', $me->referral_code)->count(),
        ]);
    }

    /**
     * Where this person is in the queue, counting from the front.
     *
     * Rank by id, which is the order people joined. Honest and cheap; it is not a score, it is a
     * queue, and pretending otherwise would be the first lie on a page whose whole argument is that
     * it does not lie.
     */
    private function positionOf(string $email): int
    {
        $id = DB::table('launch_signups')->where('email', $email)->value('id');

        if ($id === null) {
            return 0;
        }

        return (int) DB::table('launch_signups')->where('id', '<=', $id)->count();
    }

    /**
     * The rates we publish, both directions, keyed "GHS/NGN" and "NGN/GHS".
     *
     * Read live rather than typed into the page. A bureau de change that hides its price is the thing
     * this product exists to be different from, and a rate on a landing page that has quietly gone
     * stale is worse than no rate at all -- it would be the first false number on the page.
     *
     * @return array<string, string>
     */
    private function publishedRates(): array
    {
        $rows = DB::connection('pgsql_ops')->select(
            "select distinct on (base_asset, quote_asset) base_asset, quote_asset, rate
             from rate_card
             where (base_asset, quote_asset) in (('GHS','NGN'), ('NGN','GHS'))
             order by base_asset, quote_asset, id desc"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row->base_asset.'/'.$row->quote_asset] = (string) $row->rate;
        }

        return $out;
    }

    /** The commission actually set, in basis points, or null. */
    private function publishedFee(): ?int
    {
        $bps = DB::connection('pgsql_ops')->selectOne(
            'select fee_bps from trade_policy where fee_bps > 0 order by trade_type limit 1'
        );

        return $bps === null ? null : (int) $bps->fee_bps;
    }

    private function waiting(): int
    {
        return (int) DB::table('launch_signups')->count();
    }

    /**
     * Validate an offered referral code, or null when it is unknown or invented.
     *
     * Returns the CODE, not the address behind it. Attribution is then unspoofable: referred_by can
     * only ever be a value that already exists as somebody's referral_code in this same table, so no
     * input a visitor supplies can inflate another person's position.
     */
    private function validReferralCode(mixed $code): ?string
    {
        if (! is_string($code) || ! preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
            return null;
        }

        $exists = DB::table('launch_signups')->where('referral_code', $code)->exists();

        return $exists ? $code : null;
    }

    /**
     * A short code that cannot be guessed and cannot be mistaken for anything else.
     *
     * Uppercase alphanumerics only: no 0/O or 1/l, because these get read aloud and typed off a
     * screenshot on a phone, and an ambiguous character in a referral code costs a real person a
     * real signup.
     */
    private function uniqueCode(): string
    {
        // No I, O, 0 or 1 -- see above.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('launch_signups')->where('referral_code', $code)->exists());

        return $code;
    }
}

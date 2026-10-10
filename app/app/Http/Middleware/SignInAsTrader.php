<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tell the database who is signed in, once per request.
 *
 * Every trader connects as the same login role, so without this the database cannot distinguish them --
 * and every question a trader asks is answered from an email the APPLICATION passes in, which a trader
 * holding that connection can simply change to somebody else's.
 *
 * kudi-core/015 puts the identity in the database instead: kudi_sign_in() sets it on the session and the
 * functions a trader can reach read it from there, taking no trader argument at all. That is the difference
 * between a control and a hope about the application passing the right name.
 *
 * On the TRADER CONNECTION ONLY. kudi_sign_in() is granted to kudi_trader and to nobody else, so signing
 * in on the application connection would raise "permission denied" and 500 every trader route. That is the
 * database doing exactly what this file is for -- the identity belongs to the one role that holds trader
 * data, and is not something the general application role should be able to set.
 *
 * The cost of that tightness is the point: a trader-facing query issued on another connection has no
 * identity to read and fails loudly, rather than quietly answering with the wrong person's money.
 */
final class SignInAsTrader
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->user('trader')?->email;

        foreach (['pgsql_trader'] as $connection) {
            try {
                if ($email === null) {
                    DB::connection($connection)->selectOne('select kudi_sign_out()');
                } else {
                    DB::connection($connection)->selectOne('select kudi_sign_in(?)', [$email]);
                }
            } catch (\Throwable) {
                // A trader route that cannot establish an identity must not proceed on an assumption.
                // Failing here beats a screen that quietly shows a balance it should not have.
                abort(500, 'The ledger could not confirm who this is. Nothing is shown rather than shown wrongly.');
            }
        }

        return $next($request);
    }
}

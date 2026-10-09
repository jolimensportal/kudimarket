<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TradeLedger;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Is this signed-in trader one of the two sides of this trade?
 *
 * This lived in TradeController as a private method, which meant DisputeController grew its own copy. The
 * copy in TradeController was fixed -- a seller was getting "That trade belongs to somebody else" on a
 * trade they are named on -- and the copy in DisputeController was not, so a seller still could not report
 * a problem on a trade where they are the party who is waiting to be paid.
 *
 * Two copies of an authorisation rule is not a style question. One of them was wrong, and there was
 * nothing to notice it except a seller hitting a 403. Hence one method.
 */
final class TradeAccess
{
    /**
     * A trade the signed-in trader is a party to, or a refusal that says why.
     *
     * BOTH sides, deliberately. A party to a trade is a party to it. 404 for a trade that does not exist
     * and 403 for one belonging to somebody else, kept different on purpose: merging them would either leak
     * which trade ids are real or make a mistyped link look like an attack. The id in a URL is not a
     * secret, so this is for clarity, not concealment.
     *
     * Being a party is not permission to do everything. Each action still checks its own side -- claim is
     * buyer-only, and a dispute is open to either party because either party can be the one not paid.
     *
     * @throws NotFoundHttpException
     */
    public static function party(Request $request, int $trade, TradeLedger $ledger): object
    {
        $row = $ledger->trade($trade);
        $email = $request->user('trader')?->email;

        abort_if($row === null, 404);
        abort_unless(
            in_array($email, [$row->buyer, $row->seller], true),
            403,
            'That trade belongs to somebody else.'
        );

        return $row;
    }
}

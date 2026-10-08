<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every state change the trader app makes, in one place.
 *
 * Each method is a single stored-procedure call and nothing else. That is not tidiness: a trade's
 * state is enforced by the database, so if the application could also move it there would be two ways
 * in and only one of them checked. The application holds no balances and no transitions of its own.
 */
final class TradeDesk
{
    /**
     * Open a trade where we are the seller and a trader is the buyer.
     *
     * @param  string  $traderEmail  the counterparty, which must be the signed-in trader
     * @param  int  $fiatMinorUnits  whole naira in minor units: 200000 is ₦2,000.00
     * @param  float  $rate  the agreed rate, quote per one base, as shown to the trader
     * @return int the trade id
     */
    public function openAgainstUs(string $traderEmail, int $fiatMinorUnits, float $rate): int
    {
        if ($fiatMinorUnits <= 0) {
            throw new RuntimeException('A trade needs a positive amount.');
        }

        if ($rate <= 0) {
            throw new RuntimeException('A trade needs a rate above zero.');
        }

        // The constructor takes the counterparty as an argument, so nothing at the database stops a
        // caller passing somebody else's address and opening a trade in their name. Checked here,
        // against the signed-in trader, before the call.
        //
        // The guard is named explicitly. The default guard is the operators', and on a trader route
        // nobody is signed in on it, so auth()->user() returns null and the check would either pass
        // everything or fail everything depending on what else was in the session.
        if (Auth::guard('trader')->user()?->email !== $traderEmail) {
            throw new RuntimeException('A trade can only be opened in your own name.');
        }

        // The rate comes from the rate card, never from the request. A trader who can choose their own
        // rate can choose their own price, and this service is the only thing standing between the
        // query string and the ledger.
        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate from current_rate where base_asset = ? and quote_asset = ?',
            ['GHS', 'NGN']
        );

        if ($published === null) {
            throw new RuntimeException('We have not published a rate for this pair yet.');
        }

        $id = $this->call(
            "select create_fiat_trade('platform', ?, null, null, 'NGN', ?, ?, 'platform_inventory') as id",
            [$traderEmail, $fiatMinorUnits, (float) $published->rate],
            'The ledger refused to open that trade.'
        );

        // A raw Postgres query returns stdClass, and the row is an object rather than a scalar, so the
        // id has to be pulled off it and cast. Casting the object itself throws.
        return (int) ($id->id ?? 0);
    }

    /** The buyer says the money is on its way. */
    public function claimPaid(int $tradeId, string $actor): void
    {
        $this->call(
            "select transition_trade(?, 'open', 'paid_claimed', ?, ?) as ok",
            [$tradeId, $this->key($tradeId, 'claim'), $actor],
            'The ledger refused that claim.'
        );
    }

    /** Operations confirms the money arrived. Never the payer. */
    public function attestPaid(int $tradeId, string $staffEmail): void
    {
        $this->call(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_claimed', 'paid_attested', $this->key($tradeId, 'attest'), $staffEmail],
            'The ledger refused that attestation.'
        );
    }

    /** The counterparty says something is wrong. Open, not adjudicated. */
    public function raiseDispute(int $tradeId, string $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say what happened, in your own words.');
        }

        // The reason's hash goes in the idempotency key so a resubmission of a *different* reason is a
        // different decision rather than a silent no-op replay of the first one.
        $this->call(
            "select transition_trade(?, 'paid_claimed', 'disputed', ?, ?) as ok",
            [$tradeId, $this->key($tradeId, 'dispute', substr(hash('sha256', $reason), 0, 12)), $actor],
            'The ledger refused that dispute.'
        );
    }

    /**
     * One key per decision, so a double tap settles once.
     *
     * Keyed on the trade and the step, not on the row count, because the step is what makes it the
     * same decision. A key derived from time or a random value would make every retry a new decision,
     * which is exactly the bug idempotency exists to prevent.
     */
    private function key(int $tradeId, string $step, string $salt = ''): string
    {
        return sprintf('trader:%d:%s%s', $tradeId, $step, $salt === '' ? '' : ':'.$salt);
    }

    private function call(string $sql, array $bindings, string $message): mixed
    {
        try {
            return DB::connection('pgsql_trader')->selectOne($sql, $bindings);
        } catch (QueryException $e) {
            throw new RuntimeException($this->explain($e), previous: $e);
        }
    }

    /**
     * Turns a database refusal into something a person can act on.
     *
     * The alternative is a raw Postgres message on a phone screen, which tells a trader nothing about
     * what to do next and tells whoever reads the logs more than they need to know.
     */
    private function explain(QueryException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'claim window open') => 'The claim window on this trade is still open. Release is not possible yet.',
            str_contains($message, 'not in state') => 'Someone already moved this trade. Reload to see where it is.',
            str_contains($message, 'may not do') => 'That step is not yours to take on this trade.',
            str_contains($message, 'system step') => 'Operations confirms this one, not you.',
            str_contains($message, 'ops only') => 'This step is restricted to the operations console.',
            str_contains($message, 'illegal transition') => 'That move is not allowed from this state.',
            str_contains($message, 'inconsistent') => 'That fault contradicts the outcome you chose.',
            str_contains($message, 'insufficient funds') => 'A balance involved in this trade cannot cover the move.',
            str_contains($message, 'exceeds cap') => 'That amount is above the limit for this kind of trade.',
            str_contains($message, 'no crypto leg') => 'This trade has no crypto leg, so there is nothing to escrow.',
            default => $this->message,
        };
    }
}

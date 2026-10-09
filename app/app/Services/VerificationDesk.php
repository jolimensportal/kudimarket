<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Becoming somebody other traders can trade with.
 *
 * Two ends of one process. The trader applies with the details an operator would actually check; an
 * operator decides. Neither can do the other's half, and that is enforced by grants in the database
 * rather than by this file: decide_verification is not granted to kudi_trader, and kudi_trader cannot
 * write trader.verified_at.
 *
 * The record is a hash chain, not a flag. "Checked" is a promise a trader will later point at when
 * somebody asks why they were trusted, so who decided, when, and why all survive. A refusal carries a
 * reason for the same reason: a decision with no explanation is a dead end.
 */
final class VerificationDesk
{
    /** Where this trader stands. Null means they have never applied. */
    public function status(): ?object
    {
        return DB::connection('pgsql_trader')->selectOne(
            'select trader_email, event, narrative, actor, created_at, is_verified, is_pending
               from verification_status
              where trader_email = ?',
            [$this->email()]
        );
    }

    /** Apply to be checked. */
    public function apply(string $details): int
    {
        // The TRADER connection, not ops. apply_for_verification is granted to kudi_trader and to nobody
        // else, and this method had the whole service pointed at pgsql_ops -- so every application failed
        // with "permission denied for function apply_for_verification". The two halves of this process run
        // under different roles on purpose, and one connection cannot express that.
        return (int) ($this->call(
            'pgsql_trader',
            'select apply_for_verification(?, ?) as id',
            [$this->email(), trim($details)],
            'We could not send that application.'
        )->id ?? 0);
    }

    /**
     * Everything operations needs to see an application: the trader's own details and their history.
     *
     * Read as operations rather than as the trader, because this is a desk function and a trader calling
     * it must not be handed a list of everybody else's applications.
     */
    public function queue(): array
    {
        return DB::connection('pgsql_ops')->select(
            'select v.trader_email, v.event, v.narrative, v.actor, v.created_at,
                    t.name, t.phone, t.kind, v.is_pending, v.is_verified
               from verification_status v
               join trader t on t.email = v.trader_email
              order by v.is_pending desc, v.created_at desc'
        );
    }

    public function applicationFor(string $traderEmail): ?object
    {
        return DB::connection('pgsql_ops')->selectOne(
            'select trader_email, event, narrative, actor, created_at, is_verified, is_pending
               from verification_status where trader_email = ?',
            [$traderEmail]
        );
    }

    /** Every step in one trader's chain, oldest first. */
    public function history(string $traderEmail): array
    {
        return DB::connection('pgsql_ops')->select(
            'select event, narrative, actor, created_at from verification_event
              where trader_email = ? order by id',
            [$traderEmail]
        );
    }

    /**
     * Decide. Recorded against the signed-in staff member, never a role.
     *
     * A refusal needs a reason; the database refuses one shorter than ten characters, because "no" is
     * not something a trader can act on and the alternative is an appeal we cannot answer.
     */
    public function decide(string $traderEmail, bool $verified, ?string $reason): void
    {
        $staff = Auth::guard('web')->user()?->email;

        if ($staff === null) {
            throw new RuntimeException('You need to be signed in to decide.');
        }

        $this->call(
            'pgsql_ops',
            'select decide_verification(?, ?, ?, ?) as id',
            [$traderEmail, $verified, $verified ? null : trim((string) $reason), $staff],
            'We could not record that decision.'
        );
    }

    private function email(): string
    {
        $email = Auth::guard('trader')->user()?->email;

        if ($email === null) {
            throw new RuntimeException('You need to be signed in.');
        }

        return $email;
    }

    /**
     * @param  string  $connection  which role runs this. The trader's half and the desk's half are granted
     *                              to different roles on purpose, and this is where that is expressed.
     */
    private function call(string $connection, string $sql, array $bindings, string $fallback): mixed
    {
        try {
            return DB::connection($connection)->selectOne($sql, $bindings);
        } catch (QueryException $e) {
            throw new RuntimeException($this->explain($e, $fallback), previous: $e);
        }
    }

    /**
     * A refusal in words a trader can act on.
     *
     * The application-length message is spelled out because a trader who types two words should be told
     * what a real application contains, rather than left guessing what "too short" meant.
     */
    private function explain(QueryException $e, string $fallback): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'details required') => 'Tell us more. We check your identity, your address, the account in your own name, where the money came from, and your Ghana Card number.',
            str_contains($message, 'already applied') => 'You already have an application open. We will be in touch.',
            str_contains($message, 'already verified') => 'You are already checked.',
            str_contains($message, 'nothing to decide') => 'That application has already been decided.',
            str_contains($message, 'reason required') => 'Say what is missing, so they can fix it and apply again.',
            str_contains($message, 'actor required') => 'No staff identity on that decision.',
            str_contains($message, 'no such trader') => 'We have no record of that trader.',
            default => $fallback,
        };
    }
}

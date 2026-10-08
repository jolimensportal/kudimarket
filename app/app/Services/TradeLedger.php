<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way the application touches money.
 *
 * Every state change and every movement of value goes through one of four stored procedures.
 * Nothing here writes a posting, a balance or a trade row, because the app role cannot: it has no
 * INSERT, UPDATE or DELETE on any of those tables. If this class tried, the database would refuse.
 */
final class TradeLedger
{
    /** Reads. Uses the ops connection so the console sees the same rows its actions will touch. */
    public function trades(): array
    {
        return DB::connection('pgsql_ops')->select(
            'select id, trade_type, seller, buyer, crypto_asset, crypto_amount, deposit_asset,
                    fiat_asset, fiat_amount, rate, state, colour, updated_at
               from trade_view order by id desc'
        );
    }

    /**
     * One trade, for the screen that shows it. Null when no such trade exists.
     *
     * Read on the trader connection, so the screen is rendered from the same role that will later be
     * asked to act on it. A screen readable only by the app role would let the UI show something the
     * trader themselves could not see.
     */
    public function trade(int $tradeId): ?object
    {
        return DB::connection('pgsql_trader')
            ->selectOne('select * from trade_view where id = ?', [$tradeId]);
    }

    public function quote(int $tradeId): ?object
    {
        return DB::connection('pgsql_ops')->selectOne(
            'select * from trade_quote where id = ?', [$tradeId]
        );
    }

    /** The write-once record of a trade. This is what a regulator or a dispute reads. */
    public function events(int $tradeId): array
    {
        return DB::connection('pgsql_ops')->select(
            'select seq, from_state, to_state, actor, fault, created_at
               from trade_event where trade_id = ? order by seq', [$tradeId]
        );
    }

    /**
     * Every posting this trade made, netted by account.
     *
     * The amount is rendered from the asset table's own decimals rather than a list of currencies
     * hardcoded in PHP, because a hardcoded list silently misreads every currency someone adds later.
     */
    public function postings(int $tradeId): array
    {
        return DB::connection('pgsql_ops')->select(
            "select a.owner, a.kind, p.asset, sum(p.amount) as amount,
                    trim(trailing '.' from trim(trailing '0' from to_char(sum(p.amount)::numeric / power(10, d.decimals), 'FM9999999990.99999999'))) as amount_display
               from posting p
               join ledger_tx x on x.id = p.tx_id
               join account a on a.id = p.account_id
               join asset d on d.code = p.asset
              where x.trade_id = ?
              group by a.owner, a.kind, p.asset, d.decimals
              order by a.owner, p.asset", [$tradeId]
        );
    }

    /**
     * A staff decision. Recorded against the operator's own identity, never a value from the form.
     *
     * @param  'seller'|'buyer'|null  $fault  only meaningful for a vendor-to-vendor dispute
     */
    public function resolve(int $tradeId, string $from, string $to, string $operator, ?string $fault = null): void
    {
        $operator = $this->requireOperator($operator);

        $this->call(fn () => DB::connection('pgsql_ops')->selectOne(
            'select ops_transition(?, ?, ?, ?, ?, ?) as ok',
            [$tradeId, $from, $to, $this->idempotencyKey($tradeId, $from, $to, $fault), $operator, $fault]
        ));
    }

    /**
     * Operations confirms the money actually arrived.
     *
     * This was missing, and its absence stopped every trade dead. The rules are paid_claimed ->
     * paid_attested for the seller, and for a trade where KudiMarket is the seller that means operations
     * and nobody else. The operations console's Release button is visible only at paid_attested -- so
     * before this method existed, a trade could be opened, paid and claimed, and then nothing could ever
     * happen to it. It would sit at paid_claimed holding the buyer's money with no way out.
     *
     * TradeDesk::attestPaid does the same thing through ops_transition, but it runs on the trader
     * connection, and the trader role is not granted ops_transition -- correctly, since a trader must
     * not confirm their own payment. Attestation is an operations action and belongs here, on the ops
     * connection, beside resolve() and release().
     */
    public function attest(int $tradeId, string $operator): void
    {
        $operator = $this->requireOperator($operator);

        $this->call(fn () => DB::connection('pgsql_ops')->selectOne(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_claimed', 'paid_attested', $this->idempotencyKey($tradeId, 'paid_claimed', 'paid_attested', null), $operator]
        ));
    }

    /**
     * Releases a trade whose claim window has elapsed.
     *
     * paid_attested -> released is a system step: no counterparty may take the buyer's money out of
     * escrow early, and the claim window is the only control that stops it. So this goes through
     * ops_transition, which the database allows the operations role to perform on a system step, and
     * not through transition_trade, which would only accept the literal actor 'system' and throw
     * the staff member away. The claim window still has to have elapsed.
     */
    public function release(int $tradeId, string $operator): void
    {
        $operator = $this->requireOperator($operator);

        $this->call(fn () => DB::connection('pgsql_ops')->selectOne(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_attested', 'released', $this->idempotencyKey($tradeId, 'released', 'released', null), $operator]
        ));
    }

    /** One idempotency key per decision. A double click cannot settle a trade twice. */
    private function idempotencyKey(int $tradeId, string $from, string $to, ?string $fault): string
    {
        return sprintf('ops:%d:%s:%s:%s', $tradeId, $from, $to, $fault ?? '-');
    }

    /**
     * An unattributed decision is worse than a refused one, because the dispute record is supposed
     * to be readable months later by someone who was not in the room.
     */
    private function requireOperator(string $operator): string
    {
        if (trim($operator) === '') {
            throw new RuntimeException('Refusing to record a decision with no operator identity.');
        }

        return $operator;
    }

    /** Turns a database refusal into a message a person in the desk can act on. */
    private function call(callable $work): void
    {
        try {
            $work();
        } catch (QueryException $e) {
            $message = $e->getMessage();

            throw new RuntimeException(match (true) {
                str_contains($message, 'claim window open') => 'The claim window on this trade is still open. Release is not possible yet.',
                str_contains($message, 'not in state') => 'Someone already moved this trade. Reload to see where it is.',
                str_contains($message, 'may not do') => 'That actor is not allowed to take this step on this trade.',
                str_contains($message, 'ops only') => 'This step is restricted to the operations console.',
                str_contains($message, 'illegal transition') => 'That move is not allowed from this state.',
                str_contains($message, 'inconsistent') => 'That fault contradicts the outcome you chose.',
                str_contains($message, 'insufficient funds') => 'A balance involved in this trade cannot cover the move.',
                default => 'The ledger refused that change.',
            }, previous: $e);
        }
    }
}

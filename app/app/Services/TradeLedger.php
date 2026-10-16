<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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

    /**
     * The signed-in trader's own trades, newest first, for the history screen.
     *
     * Read on the trader connection through my_trades() (019), which takes no argument: the database
     * answers from the session identity set at sign-in, so there is no name in this call to get wrong.
     * Same shape 015 gave my_balances() and my_trade_counts(), and the same reason -- every trader shares
     * one login role, so a query filtered by a caller-supplied address would answer about whoever the
     * caller named.
     *
     * The timestamp is parsed here rather than in the template. A raw query returns a string, and calling
     * diffForHumans() on one is a 500 that only appears once a trader actually has a trade -- the trap
     * RateBoard::ourRateForDisplay() documents.
     */
    public function mine(): array
    {
        $rows = DB::connection('pgsql_trader')->select('select * from my_trades()');

        foreach ($rows as $row) {
            $row->updated_at = Carbon::parse((string) $row->updated_at);
        }

        return $rows;
    }

    /** The write-once record of a trade. This is what a regulator or a dispute reads. */
    public function events(int $tradeId): array
    {
        return DB::connection('pgsql_ops')->select(
            'select seq, from_state, to_state, actor, fault, narrative, created_at
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

        // The state this settles FROM depends on the type, and hardcoding paid_attested was wrong for
        // remittance: a send settles from paid_out, because the cash has to have been handed over
        // before the float may fall. Called as written, releasing a send raised 'illegal transition'
        // -- which is at least a refusal rather than a wrong payment, but it meant a send that was
        // ready to complete could not be completed at all.
        $from = $this->releaseStateFor($tradeId);
        $current = (string) (DB::connection('pgsql_ops')->selectOne(
            'select state from trade where id = ?',
            [$tradeId]
        )->state ?? '');

        // Said here rather than left to the ledger's own refusal. Attempting the move and letting
        // ops_transition throw reports "trade N is not in state paid_out", which the message mapper
        // turns into "Someone already moved this trade" -- which is not what happened. Nothing moved.
        // On a send, the honest message names the thing that has not happened yet.
        if ($current !== $from) {
            throw new RuntimeException($this->notYetReleasable($current, $from));
        }

        $this->call(fn () => DB::connection('pgsql_ops')->selectOne(
            'select ops_transition(?, ?, ?, ?, ?) as ok',
            [$tradeId, $from, 'released', $this->idempotencyKey($tradeId, $from, 'released', null), $operator]
        ));
    }

    /**
     * Where a release starts for this trade.
     *
     * Read from the trade's own rules rather than decided here, so adding a type that settles from a
     * third state does not need this file to learn about it.
     */
    private function releaseStateFor(int $tradeId): string
    {
        $row = DB::connection('pgsql_ops')->selectOne(
            "select r.from_state
               from trade_rule r join trade t on t.trade_type = r.trade_type
              where t.id = ? and r.to_state = 'released'
              order by case r.from_state when 'paid_attested' then 0 else 1 end
              limit 1",
            [$tradeId]
        );

        return (string) ($row->from_state ?? 'paid_attested');
    }

    /** What is actually missing, in the words of somebody standing at the till. */
    private function notYetReleasable(string $current, string $releases): string
    {
        return match (true) {
            $current === 'paid_attested' && $releases === 'paid_out' => 'This send is confirmed but the naira has not been handed over yet. Record the payout first.',
            $current === 'paid_claimed' => 'We have not confirmed this one arrived yet.',
            default => "This trade is at {$current}, and it cannot be released from there.",
        };
    }

    /**
     * Records that the cash was physically handed to the beneficiary.
     *
     * Not a state transition on its own. paid_attested -> paid_out is only reachable through
     * record_payout() in kudi-core/020, because that move is the one place the float is allowed to
     * start falling and it must never be reachable without saying what was handed over, to whom and
     * against which till reference. There is deliberately no sibling method that transitions to
     * paid_out directly -- the absence is the control.
     *
     * The amount is passed in rather than read from the form alone because the ledger checks it
     * against what the trade owes and refuses a payout that does not match. An operator keying the
     * wrong figure gets told, rather than moving the float by a number unrelated to what the
     * beneficiary received.
     */
    public function recordPayout(int $tradeId, int $amountMinorUnits, string $reference, string $operator): void
    {
        $operator = $this->requireOperator($operator);

        $this->call(fn () => DB::connection('pgsql_ops')->selectOne(
            'select record_payout(?, ?, ?, ?, ?) as ok',
            [$tradeId, $amountMinorUnits, trim($reference), $operator, $this->idempotencyKey($tradeId, 'paid_out', 'payout', null)]
        ));
    }

    /**
     * Sends that are waiting for a handover, newest first.
     *
     * Only remittance trades, and only the one state where a person has something to do. The list is
     * deliberately not "everything not yet released": a send that has not been attested yet is with
     * the sender's bank transfer, not with operations, and putting it here would train operators to
     * read this queue as work.
     *
     * @return list<object>
     */
    public function awaitingPayout(): array
    {
        return DB::connection('pgsql_ops')->select(
            "select t.id, t.state, t.fiat_asset, t.fiat_amount, t.updated_at,
                    s.beneficiary_name, s.beneficiary_phone, s.corridor,
                    (select max(e.created_at) from trade_event e
                      where e.trade_id = t.id and e.to_state = 'paid_attested') as attested_at
               from trade t
               join remittance_send s on s.trade_id = t.id
              where t.trade_type = 'remittance'
                and t.state = 'paid_attested'
              order by t.id desc"
        );
    }

    /** The evidence on a payout, for the send page and the operations record. */
    public function payoutFor(int $tradeId): ?object
    {
        return DB::connection('pgsql_ops')->selectOne(
            'select delivered_amount, delivered_reference, delivered_by, delivered_at
               from remittance_send where trade_id = ?',
            [$tradeId]
        );
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
                str_contains($message, 'till reference') => 'A till reference is required. An untraceable handover is not a payout.',
                str_contains($message, 'refusing to record a payout') => 'That is not the amount this send owes. Check the figure on the send.',
                str_contains($message, 'already has a payout recorded') => 'Someone already recorded this handover.',
                str_contains($message, 'inconsistent') => 'That fault contradicts the outcome you chose.',
                str_contains($message, 'insufficient funds') => 'A balance involved in this trade cannot cover the move.',
                default => 'The ledger refused that change.',
            }, previous: $e);
        }
    }
}

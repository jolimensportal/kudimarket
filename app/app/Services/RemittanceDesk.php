<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A remittance send: a sender in Ghana pays cedi, a named beneficiary in Nigeria receives naira.
 *
 * The ledger side of this lives in kudi-core/020_remittance.sql and the state machine there is the
 * authority. This class opens the send and nothing else. It deliberately has no method for paying
 * out, confirming, or settling: every one of those is an operations action, and the reason for that
 * split is written into the core file rather than repeated here.
 */
final class RemittanceDesk
{
    /**
     * What we send, and what it costs them to send it. Stated as a pair so the two cannot drift.
     */
    public const CORRIDOR = 'GHS/NGN';

    /**
     * A quote for a send that has not been opened yet.
     *
     * @return array<string, mixed>
     */
    public function quote(int $sendMinorUnits): array
    {
        if ($sendMinorUnits <= 0) {
            throw new RuntimeException('A send needs an amount above zero.');
        }

        // Off the card, never from the request. A sender who chooses their own rate chooses their own
        // price, and the number the beneficiary is told they will receive is built from this one.
        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate, base_asset, quote_asset from current_rate where base_asset = ? and quote_asset = ?',
            ['GHS', 'NGN']
        );

        if ($published === null) {
            throw new RuntimeException('We have not published a rate for GHS/NGN yet.');
        }

        $receiveMinor = $this->receiveMinor($sendMinorUnits, (string) $published->rate);

        return [
            'send' => RateBoard::formatMoney($sendMinorUnits, 'GHS'),
            'sendMinor' => $sendMinorUnits,
            'receive' => RateBoard::formatMoney($receiveMinor, 'NGN'),
            'receiveMinor' => $receiveMinor,
            // The direct rate only. A sender in Ghana wants "one cedi is X naira", which is the
            // forward pair; the inverse is what the Nigeria-facing side reads, and it is on the card
            // in the other direction rather than derived here.
            // RateBoard::formatRate, not a RateFormatter that does not exist. Nothing referenced this
            // path until now: SendFlowTest POSTED a send rather than loading the form, so the one screen
            // a sender actually opens was never rendered by a test, and it 500'd on any amount. RateBoard
            // already formats rates for the board and the trade screen; a second formatter here is how
            // three places end up disagreeing about the same number.
            'rate' => RateBoard::formatRate($published->rate),
        ];
    }

    /**
     * How much naira a given number of pesewas buys.
     *
     * Floor, not round. The ledger re-derives the cedi side by DIVING the naira amount by the rate
     * (kudi-core/018, the own-book settlement block), so rounding up here would let a sender be
     * quoted a naira figure that the ledger settles against one pesewa less than the form promised --
     * a screen that quotes one number and settles another, which is the defect 011 exists to record.
     *
     * Flooring can still drop a pesewa on its own, which openSend() refuses rather than absorbs.
     */
    private function receiveMinor(int $sendMinorUnits, string $rate): int
    {
        // Integer arithmetic on scaled integers. $rate is a decimal string and is never cast to float:
        // 148.20 has no exact binary representation, and a price round-tripped through one is a price
        // that is subtly different from the one on the rate card.
        //
        // The scale is capped rather than taken from the string. The column is a numeric with a wide
        // declared scale, so a GHS/NGN rate comes back as "148.20000000000000000000" -- twenty
        // fractional digits. Using that scale would need 10**20, which PHP evaluates as a FLOAT, and
        // the whole point of the integer path is lost before it starts: the first test to touch a real
        // rate returned a float into intdiv() and took the screen down. Eight places is well past any
        // rate precision we quote, and it keeps the arithmetic inside the range where PHP still does
        // integer maths.
        $scale = 8;

        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $scaleFactor = (int) (10 ** $scale);
        $rateInt = ((int) $whole * $scaleFactor) + (int) $fraction;

        return intdiv($sendMinorUnits * $rateInt, $scaleFactor);
    }

    /**
     * Opens a send. Returns the trade id.
     *
     * @throws RuntimeException on anything the ledger or the shape of the request refuses
     */
    public function open(
        string $senderEmail,
        int $sendMinorUnits,
        string $beneficiaryName,
        string $beneficiaryPhone,
    ): int {
        if ($sendMinorUnits <= 0) {
            throw new RuntimeException('A send needs an amount above zero.');
        }

        // The ledger takes the sender as an argument, so nothing below this line stops a caller
        // opening a send in somebody else's name. Checked against the signed-in trader first.
        // The guard is named explicitly: the default guard is the operators', and on a trader route
        // nobody is signed in on it.
        if (Auth::guard('trader')->user()?->email !== $senderEmail) {
            throw new RuntimeException('A send can only be opened in your own name.');
        }

        $name = trim($beneficiaryName);
        $phone = trim($beneficiaryPhone);

        // Length and shape are checked here as well as in the table, so the sender is told what is
        // wrong at the field rather than being handed a raw Postgres error from a check constraint.
        // The table still checks: this is a courtesy, not the control.
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new RuntimeException('Give the beneficiary a name between 2 and 120 characters.');
        }

        if (preg_match('/^\+?[0-9]{7,15}$/', $phone) !== 1) {
            throw new RuntimeException('Give a beneficiary phone number we can dial, digits and an optional + only.');
        }

        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate from current_rate where base_asset = ? and quote_asset = ?',
            ['GHS', 'NGN']
        );

        if ($published === null) {
            throw new RuntimeException('We have not published a rate for GHS/NGN yet.');
        }

        $rate = (string) $published->rate;
        $receiveMinor = $this->receiveMinor($sendMinorUnits, $rate);

        // The round trip the ledger will perform, performed here first.
        //
        // The ledger derives the cedi leg as DIV(naira, rate). If flooring the naira dropped the
        // cedi leg by even one pesewa, the send would be created and settled against a different
        // amount from the one the sender was shown. Rather than absorb that silently, refuse the
        // amount and let the sender round their own figure. It is unreachable at any realistic
        // remittance size -- it needs a send below roughly one cedi -- and it is here because the
        // failure it prevents is a mismatch between a promise and a settlement.
        if ($this->ledgerDiverges($receiveMinor, $rate, $sendMinorUnits)) {
            throw new RuntimeException('That amount is too small to convert exactly. Try a round number of cedi.');
        }

        $row = $this->call(
            'select create_remittance_send(?, ?, ?, ?, ?, ?, ?) as id',
            [
                $senderEmail,
                $name,
                $phone,
                self::CORRIDOR,
                $receiveMinor,
                $rate,
                // One key per attempt by this sender for this amount and beneficiary, so a double
                // submit cannot open two sends. Keyed on the SENDER and the amount rather than a
                // random value for the same reason: retrying the same form must return the send that
                // the first submit made, not a second one.
                sprintf('send:%s:%d:%s', $senderEmail, $sendMinorUnits, substr(hash('sha256', $name.$phone), 0, 12)),
            ],
            'We could not open that send.'
        );

        return (int) ($row->id ?? 0);
    }

    /**
     * The ledger's own rounding, reproduced exactly.
     *
     * A pure-PHP check is not enough: the question is whether DIV in Postgres agrees with our floor,
     * and that is only answerable by asking Postgres. Kept to one small query on an unreachable
     * branch rather than trusting a mirror of it.
     */
    private function ledgerDiverges(int $receiveMinor, string $rate, int $sendMinorUnits): bool
    {
        $derived = DB::connection('pgsql_trader')->selectOne(
            'select div(?, ?) as ghs',
            [$receiveMinor, $rate]
        );

        return (int) ($derived->ghs ?? 0) !== $sendMinorUnits;
    }

    /**
     * Everything the sender needs to follow their own send.
     *
     * Read through my_remittance_send(), not by querying the table. The trader role is not granted
     * SELECT on remittance_send at all, because that table holds names and phone numbers; the function
     * takes no trader argument and scopes to the session identity instead (kudi-core/020 section 9b).
     * A send belonging to somebody else returns no rows rather than an error, which is what turns into
     * the 404 in SendController::show -- and a 404 rather than a 403, because telling a stranger that an
     * id exists is itself the leak.
     */
    public function show(int $tradeId): ?object
    {
        return DB::connection('pgsql_trader')->selectOne(
            'select * from my_remittance_send(?)',
            [$tradeId]
        );
    }

    /** The signed-in sender's own sends, newest first. */
    public function mine(): array
    {
        return DB::connection('pgsql_trader')->select('select * from my_remittance_sends()');
    }

    /**
     * @template T
     *
     * @param  callable():T  $query
     * @return T
     */
    private function call(string $sql, array $bindings, string $message): mixed
    {
        try {
            return DB::connection('pgsql_trader')->selectOne($sql, $bindings);
        } catch (QueryException $e) {
            throw new RuntimeException($this->explain($e, $message), previous: $e);
        }
    }

    /** The ledger's constraint names, in the words the sender is looking at. */
    private function explain(QueryException $e, string $fallback): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'remittance_send_beneficiary_name_check') => 'Give the beneficiary a name between 2 and 120 characters.',
            str_contains($message, 'remittance_send_beneficiary_phone_check') => 'Give a beneficiary phone number we can dial, digits and an optional + only.',
            str_contains($message, 'remittance_send_corridor_check') => 'We only send cedi to Nigeria.',
            default => $fallback,
        };
    }
}

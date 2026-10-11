<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer due diligence -- the AML half of becoming a customer.
 *
 * This is NOT VerificationDesk, and the two must never be treated as the same thing.
 * VerificationDesk answers "may this trader list an offer": a trust decision between traders, made by
 * operations reading what the trader wrote. CddDesk answers "have we established this customer's
 * identity, and can we report this transaction to the Financial Intelligence Centre if we must": a
 * statutory duty under Act 1044 s.52(e)(i) and the BoG/FIC AML/CFT/CPF Guideline for Foreign Exchange
 * Bureaux.
 *
 * They overlap on the words "identity" and "source of funds" and nothing else. A trader can be CDD
 * verified without being Checked, and (as the SQL suite asserts) a fully CDD-verified trader still
 * cannot publish an offer. Presenting the Checked badge as evidence of AML compliance would be the most
 * dangerous thing this product could do, because it looks like compliance and is not.
 *
 * Every fact here is stored as a structured column on a hash chain rather than as prose, because a
 * Cash Transaction Report is assembled by selecting and joining, not by reading. See kudi-core/016_cdd.sql
 * for the argument; this file is only the PHP edge of it.
 *
 * Two endpoints on the Guideline are reached from here:
 *
 *   requires_biometric()  USD 10,000 or equivalent in a SINGLE transaction -- Ghana Card details
 *                         captured AND the customer biometrically verified. Because USD is not one of
 *                         our assets this sometimes cannot be computed, and it returns 'unknown',
 *                         which means ESCALATE, never "under threshold". See isBiometricRequired().
 *   requires_ctr()        GHS 20,000 or equivalent -- file a Cash Transaction Report with the FIC.
 *                         Filing is an AML officer's act through a prescribed medium, so this produces
 *                         a candidate and a flag, and nothing is ever auto-filed from a trader screen.
 */
final class CddDesk
{
    /**
     * Where this trader stands on CDD. Null means they have never supplied anything.
     *
     * Reads as the trader role, in production, so a trader's reads are checked against the role that
     * actually runs there.
     */
    public function status(): ?object
    {
        return DB::connection('pgsql_trader')->selectOne(
            'select trader_email, event, doc_type, doc_number, doc_issued_on, doc_expires_on,
                    purpose_code, source_of_funds, is_cdd_verified, is_cdd_pending,
                    is_cdd_refused, biometric_on_record, actor, created_at
               from cdd_status
              where trader_email = ?',
            [$this->email()]
        );
    }

    /**
     * Is this customer established, in the sense the AML rule cares about?
     *
     * Separate from status() because it is the question every other part of the product will need to
     * ask, and it should have exactly one answer. Deliberately NOT named isVerified(): it is not a
     * verification, and a method called that would invite exactly the conflation this class exists to
     * prevent.
     */
    public function isEstablished(): bool
    {
        return $this->status()?->is_cdd_verified === true;
    }

    /** The trader supplies their identity facts, their source of funds and the purpose. */
    public function submit(
        string $docType,
        string $docNumber,
        ?string $docIssuedOn,
        ?string $docExpiresOn,
        string $residentialAddress,
        string $purposeCode,
        ?string $purposeDetail,
        string $sourceOfFunds,
    ): int {
        // The TRADER connection, not ops: submit_cdd is granted to kudi_trader and to nobody else, and
        // the two halves of this process run under different roles on purpose -- exactly as with
        // apply_for_verification. VerificationDesk learned this the hard way.
        return (int) ($this->call(
            'pgsql_trader',
            'select submit_cdd(?, ?, ?, ?, ?, ?, ?, ?, ?) as id',
            [
                $this->email(),
                $docType,
                trim($docNumber),
                $this->asDate($docIssuedOn),
                $this->asDate($docExpiresOn),
                trim($residentialAddress),
                $purposeCode,
                $this->asText($purposeDetail),
                trim($sourceOfFunds),
            ],
            'We could not save those details.'
        )->id ?? 0);
    }

    /**
     * Does this transaction need biometric verification before it may proceed?
     *
     * @param  int  $minorUnits  the amount in the asset's own minor units
     * @return bool true when required, false when not, and TRUE when unknown -- deliberately collapsed
     *              upward. A caller asking "may I let this through without a biometric check?" must get
     *              "no" when the risk could not be priced. Returning false for an unknown would let a
     *              large transaction through unverified precisely because we had not set a USD rate.
     */
    public function isBiometricRequired(string $asset, int $minorUnits): bool
    {
        $call = (string) ($this->call(
            'pgsql_trader',
            'select requires_biometric(?, ?) as call',
            [$asset, $minorUnits],
            'We could not check that transaction.'
        )->call ?? 'unknown');

        return $call !== 'not_required';
    }

    /**
     * Does this transaction sit at or above the Cash Transaction Report threshold?
     *
     * Same deliberate collapse as above: unknown means escalate to the AML officer. This never files
     * anything. It tells a screen whether to show the trader that this trade will be reported, because
     * the Guideline's tipping-off rules cut both ways -- the customer is not to be TOLD that a report
     * was filed, and telling them a transaction "will be reported" before it happens is a different
     * statement, made before any report exists. See isBiometricRequired() for the same discipline.
     */
    public function isReportable(string $asset, int $minorUnits): bool
    {
        $call = (string) ($this->call(
            'pgsql_trader',
            'select requires_ctr(?, ?) as call',
            [$asset, $minorUnits],
            'We could not check that transaction.'
        )->call ?? 'unknown');

        return $call !== 'not_reportable';
    }

    /**
     * Operations decides a submitted CDD. Never reached from a trader screen.
     */
    public function decide(string $traderEmail, bool $approved, bool $biometric, string $actor, ?string $narrative): int
    {
        return (int) ($this->call(
            'pgsql_ops',
            'select decide_cdd(?, ?, ?, ?, ?) as id',
            [$traderEmail, $approved, $biometric, $actor, $this->asText($narrative)],
            'We could not record that decision.'
        )->id ?? 0);
    }

    /**
     * Record that a biometric check actually happened.
     *
     * This is evidence, not a decision: it does not verify anybody and does not unverify anybody. It is
     * kept separate from decide() precisely because the first draft of this had it flip the customer's
     * status, which is how a biometric record can silently un-verify the customer it was evidence for.
     */
    public function recordBiometric(string $traderEmail, string $actor, ?string $narrative): int
    {
        return (int) ($this->call(
            'pgsql_ops',
            'select record_biometric(?, ?, ?) as id',
            [$traderEmail, $actor, $this->asText($narrative)],
            'We could not record that check.'
        )->id ?? 0);
    }

    /** Everything the AML officer reviews: one row per customer per trade at or above the threshold. */
    public function ctrCandidates(): array
    {
        return DB::connection('pgsql_ops')->select(
            'select trade_id, trader_email, role, fiat_asset, fiat_amount, threshold_call,
                    doc_type, doc_number, purpose_code, source_of_funds, is_cdd_verified
               from ctr_candidates'
        );
    }

    /** Verified customers whose document has expired -- the periodic re-verification list. */
    public function reviewQueue(): array
    {
        return DB::connection('pgsql_ops')->select(
            'select trader_email, doc_type, doc_number, doc_expires_on, purpose_code, created_at, document_expired
               from cdd_review_queue'
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

    /** Blank is not the same as absent: an empty date field means we were not told, not that it does not exist. */
    private function asDate(?string $value): ?string
    {
        $value = $this->asText($value);

        return $value === null ? null : $value;
    }

    private function asText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

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
     * The AML messages are spelled out because "source of funds required" is a compliance phrase, not
     * something a trader in Accra can act on at eight in the evening.
     */
    private function explain(QueryException $e, string $fallback): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'source of funds required') => 'Tell us where the money comes from — a salary, a business, savings. We have to record it.',
            str_contains($message, 'residential address required') => 'We need your residential address.',
            str_contains($message, 'unknown trader') => 'We have no record of that account.',
            str_contains($message, 'no CDD submission to decide') => 'There is nothing waiting to be checked.',
            str_contains($message, 'must explain itself') => 'Say why, so they can put it right and try again.',
            str_contains($message, 'actor required') => 'No staff identity on that decision.',
            str_contains($message, 'nothing pending for this trader') => 'That has already been looked at.',
            str_contains($message, 'already been decided') => 'That has already been decided.',
            default => $fallback,
        };
    }
}

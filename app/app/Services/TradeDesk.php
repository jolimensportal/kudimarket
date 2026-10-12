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
    /**
     * Buy currency from KudiMarket's own book. We are the counterparty, so this is the tier we guarantee.
     *
     * brand/product.html: "Which pairs do we quote? Cedi against naira, BOTH DIRECTIONS." So the trader
     * says which currency they want to RECEIVE and the service works out the other end. The first version
     * of this took the rate as an argument and hardcoded a GHS/NGN pair, which meant a trader could buy
     * naira with cedi and nothing else -- half of what the brand says we sell.
     *
     * $receiveMinorUnits is the amount of $receiveAsset the trader wants, in minor units. It is the leg
     * they receive, and the one stored on the trade; the leg they pay is derived from the published rate.
     *
     * The $rate argument is GONE rather than ignored. It was passed by nine tests and used by none of
     * them, because the rate always came off the card instead -- a parameter that looks authoritative and
     * is not is how the wrong rate gets set by somebody who believes it is being honoured.
     */
    public function openAgainstUs(string $traderEmail, int $receiveMinorUnits, string $receiveAsset): int
    {
        return $this->openAgainstOurBook($traderEmail, $receiveMinorUnits, $receiveAsset, 'platform_inventory', 'platform', $traderEmail);
    }

    /**
     * Sell currency INTO KudiMarket's own book. The trader is the seller; we are the buyer.
     *
     * This is the other half of the tier. openAgainstUs alone meant the handled book could only ever move
     * money out, so the float could never be built, and REGULATORY.md 5b records that whether we may take
     * the other side for own account at all is the difference between a licensed DEALER and an FX BROKER.
     *
     * The trade_type is 'platform_sell' rather than 'platform_inventory', and that is not cosmetic. The
     * two tiers differ in who may CONFIRM the payment, and the difference is the whole safety property of
     * this screen:
     *
     *   platform_inventory  the trader is the BUYER, so they declare and platform confirms (ops only).
     *   platform_sell        the trader is the SELLER, so they declare and WE confirm (ops only).
     *
     * Both put the DECLARATION with the trader and the CONFIRMATION with operations. Mirroring
     * platform_inventory instead -- swapping the two party columns and keeping the type -- was tried and
     * verified to let a trader attest that their own payment had arrived, because that tier's rule reads
     * "the seller confirms" and on a sell the seller is the payer. See kudi-core/018.
     *
     * Everything else is deliberately identical to the buy: same book, same rate lookup, same derivation
     * of the paid leg from the published card. A trader who can buy naira with cedi can sell cedi for
     * naira at the same rate, and the screen must not imply otherwise.
     */
    public function sellToUs(string $traderEmail, int $receiveMinorUnits, string $receiveAsset): int
    {
        return $this->openAgainstOurBook($traderEmail, $receiveMinorUnits, $receiveAsset, 'platform_sell', $traderEmail, 'platform');
    }

    /**
     * One body, two directions. Kept as one method rather than two so the rate lookup, the currency pair
     * and the derivation cannot drift between the buy and the sell -- and the drift between those two is
     * exactly what kudi-core/011 and 012 exist to document after it happened once.
     *
     * @param  string  $tradeType  'platform_inventory' to buy from us, 'platform_sell' to sell to us
     * @param  string  $seller  who sends the base leg
     * @param  string  $buyer  who receives the quote leg
     */
    private function openAgainstOurBook(
        string $traderEmail,
        int $receiveMinorUnits,
        string $receiveAsset,
        string $tradeType,
        string $seller,
        string $buyer,
    ): int {
        if ($receiveMinorUnits <= 0) {
            throw new RuntimeException('A trade needs a positive amount.');
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

        $receiveAsset = strtoupper(trim($receiveAsset));
        if (! in_array($receiveAsset, self::BOOK, true)) {
            throw new RuntimeException(
                'We only hold cedi and naira. Dollars, pounds, euros and the CFA franc are vendor supply, '
                .'and brand/product.html is explicit that we never hold them.'
            );
        }

        // The one thing we sell is what the trader does NOT receive. Cedi is the pivot and naira is the
        // other leg of our book; there is no third currency to pay in.
        $payAsset = $receiveAsset === 'NGN' ? 'GHS' : 'NGN';

        // The rate comes from the rate card, never from the request. A trader who can choose their own
        // rate can choose their own price, and this service is the only thing standing between the query
        // string and the ledger. base_asset is what the buyer PAYS and quote_asset what they RECEIVE, which
        // is the invariant kudi-core/012 checks for all three trade shapes.
        //
        // Both halves come back and both are recorded, so the row alone can be converted either way without
        // anybody re-deriving them. Reading `rate` and leaving the pair on the table is what made one rate
        // column mean two opposite things; 011 and 012 exist because of it.
        $published = DB::connection('pgsql_trader')->selectOne(
            'select rate, base_asset, quote_asset from current_rate where base_asset = ? and quote_asset = ?',
            [$payAsset, $receiveAsset]
        );

        if ($published === null) {
            throw new RuntimeException(
                "We have not published a rate for {$payAsset}/{$receiveAsset} yet."
            );
        }

        $id = $this->call(
            "select create_fiat_trade(?, ?, null, null, ?, ?, ?, ?, 'GHS', ?, ?) as id",
            [
                // Who sends the base leg and who receives the quote leg. On a buy that is platform and the
                // trader; on a sell it is the trader and platform. Passed as values rather than written into
                // the SQL text, so neither can be influenced by anything but this method.
                $seller,
                $buyer,
                $receiveAsset,
                $receiveMinorUnits,
                // The rate as a DECIMAL STRING, never a float. RateDesk::publish documents this in full:
                // 0.0067567 has no exact binary representation, so round-tripping a price through a float
                // changes what we publish by units in the last place -- on every trade priced from it, on
                // the commission computed from it, and on the chain that commits to it.
                //
                // It mattered visibly here. As a float the card rate 0.006756756756756756 was stored as
                // 0.0067567567567568, which moved the base leg by one minor unit, which moved the
                // commission by one pesewa, which made the figure on the form and the amount charged
                // disagree. The screens were right; the float was wrong.
                (string) $published->rate,
                $tradeType,
                $published->quote_asset,
                $published->base_asset,
            ],
            'The ledger refused to open that trade.'
        );

        // A raw Postgres query returns stdClass, and the row is an object rather than a scalar, so the
        // id has to be pulled off it and cast. Casting the object itself throws.
        return (int) ($id->id ?? 0);
    }

    /**
     * What we hold and quote: cedi against naira, both directions. Everything else is vendor supply.
     *
     * @var list<string>
     */
    public const BOOK = ['GHS', 'NGN'];

    /** The buyer says the money is on its way. */
    public function claimPaid(int $tradeId, string $actor): void
    {
        $this->call(
            "select transition_trade(?, 'open', 'paid_claimed', ?, ?) as ok",
            [$tradeId, $this->key($tradeId, 'claim'), $actor],
            'The ledger refused that claim.'
        );
    }

    /** The counterparty says something is wrong. Open, not adjudicated. */
    public function raiseDispute(int $tradeId, string $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say what happened, in your own words.');
        }

        // The reason's hash goes in the idempotency key so a resubmission of a *different* reason is a
        // different decision rather than a silent no-op replay of the first one.
        //
        // And the reason ITSELF now goes to the database, into the hash chain. It did not used to: the
        // text was hashed into this key and then discarded, so the permanent record held the fact of a
        // dispute and the name of the person who raised it, and nothing about what they said. A dispute
        // whose content is not stored is not a dispute anybody can act on six weeks later.
        $this->call(
            'select transition_trade(?, ?, ?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_claimed', 'disputed', $this->key($tradeId, 'dispute', substr(hash('sha256', $reason), 0, 12)), $actor, null, $reason],
            'The ledger refused that dispute.'
        );
    }

    /**
     * The RECIPIENT confirms the money arrived.
     *
     * I deleted this in Task 8 as a method that "could only ever fail", because it called ops_transition on
     * the trader connection and the trader role is correctly not granted that. That was true of the
     * OPERATIONS attestation and wrong about this one: the seller on a vendor-to-vendor trade is the
     * recipient of the buyer's payment, and saying "it arrived" is theirs to say.
     *
     * This is the "Checked" tier working as brand/product.html describes it -- "Two labels let us sell the
     * strong promise on the trades we control and be factual on the rest." The buyer relies on our
     * verification of the seller, not on a guarantee from us, so the payer is refused by the database and
     * the recipient is not. Operations attesting on a vendor trade would make KudiMarket the guarantor of
     * every peer-to-peer trade: the tier we have explicitly not priced, and the one that would put the
     * Bank of Ghana first rather than the SEC.
     */
    public function attestPaid(int $tradeId, string $recipient): void
    {
        $this->call(
            'select transition_trade(?, ?, ?, ?, ?) as ok',
            [$tradeId, 'paid_claimed', 'paid_attested', $this->key($tradeId, 'attest'), $recipient],
            'The ledger refused that confirmation.'
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
            throw new RuntimeException($this->explain($e, $message), previous: $e);
        }
    }

    /**
     * Turns a database refusal into something a person can act on.
     *
     * The alternative is a raw Postgres message on a phone screen, which tells a trader nothing about
     * what to do next and tells whoever reads the logs more than they need to know.
     */
    private function explain(QueryException $e, string $fallback): string
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
            // $fallback is the caller's own wording. The first version read $this->message here, which
            // is not a property of this class, so every UNMAPPED database error -- attestPaid reaching
            // a permission check, most immediately -- died with "Undefined property: TradeDesk::$message"
            // instead of reaching the trader. The generic branch is the one that matters most, because
            // it is the branch taken by whatever has not been thought about yet.
            default => $fallback,
        };
    }
}

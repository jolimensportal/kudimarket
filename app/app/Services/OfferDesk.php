<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The seller's side: what you hold, what you have on offer, and taking the other side of somebody
 * else's offer.
 *
 * Every method here is one stored-procedure call, for the same reason TradeDesk is: the rules live in
 * the database, so if the application could also enforce them there would be two places to be right.
 *
 * The distinction this file exists to keep straight: an offer's min_rate is a WALK-AWAY, not a price.
 * A trade is priced from the rate card at the moment it is accepted, and accept_offer refuses if the card
 * no longer meets the walk-away. That is the difference between quoting our own rate and being a
 * matching platform, which is the question a regulator asks.
 */
final class OfferDesk
{
    /** The live board, newest first. */
    public function board(): array
    {
        return DB::connection('pgsql_trader')->select(
            'select id, seller, base_asset, quote_asset, amount, min_rate, created_at, expires_at
               from open_offer
              order by created_at desc'
        );
    }

    /** This trader's own open offers. */
    public function mine(string $seller): array
    {
        return DB::connection('pgsql_trader')->select(
            'select o.id, o.base_asset, o.quote_asset, o.amount, o.min_rate, o.created_at, o.expires_at
               from offer o
              where o.seller = ?
                and not exists (select 1 from offer_withdrawal w where w.offer_id = o.id)
                and o.expires_at > now()
              order by o.created_at desc',
            [$seller]
        );
    }

    public function offer(int $offerId): ?object
    {
        return DB::connection('pgsql_trader')->selectOne('select * from offer where id = ?', [$offerId]);
    }

    /**
     * List currency for sale.
     *
     * The lifetime is capped in the database at one day, and the walk-away is checked against nothing
     * here: a seller may name any rate they like, including one the board will never meet, because the
     * cost of naming a high walk-away is waiting, not being harmed.
     *
     * @param  int  $amountMinor  minor units of base, so 50000000 is ₦500,000.00
     * @param  string  $minRate  the walk-away, as a DECIMAL STRING. Never a float: PHP floats are doubles,
     *                           and 0.006756756756756756 round-trips through one as 0.0067567567567568. A
     *                           rate that loses its sixteenth digit on the way to the database is a rate
     *                           that does not match the card it was measured against, and this whole
     *                           design turns on those two being the same number.
     */
    public function publish(
        string $baseAsset,
        string $quoteAsset,
        int $amountMinor,
        string $minRate,
        string $lifetime = '15 minutes'
    ): int {
        $seller = $this->signedInTrader();

        if ($amountMinor <= 0) {
            throw new RuntimeException('Say how much you want to sell.');
        }

        if (! is_numeric($minRate) || (float) $minRate <= 0) {
            throw new RuntimeException('Say the lowest rate you would accept.');
        }

        $row = $this->call(
            'select publish_offer(?, ?, ?, ?, ?, ?) as id',
            [$seller, $baseAsset, $quoteAsset, $amountMinor, (string) $minRate, $this->lifetime($lifetime)],
            'We could not publish that offer.'
        );

        return (int) ($row->id ?? 0);
    }

    /** Take it back off the board. The original statement stays on the record. */
    public function withdraw(int $offerId): void
    {
        $this->call(
            'select cancel_offer(?, ?)',
            [$offerId, $this->signedInTrader()],
            'We could not withdraw that offer.'
        );
    }

    /**
     * Accept somebody else's offer, becoming the buyer on a vendor-to-vendor trade.
     *
     * Priced by the rate card. If the card has moved past the seller's walk-away this is refused, and
     * the seller keeps their currency.
     */
    public function accept(int $offerId): int
    {
        $buyer = $this->signedInTrader();

        return (int) ($this->call(
            'select accept_offer(?, ?, ?) as id',
            [$offerId, $buyer, $buyer],
            'We could not take that offer.'
        )->id ?? 0);
    }

    /**
     * What this trader is owed, and how they have done. Deliberately NOT what they hold.
     *
     * The obvious version of this screen shows "You hold X" straight out of the account table, and it
     * cannot be built safely today. kudi_trader has no grant on `account`, which is right -- a trader must
     * not be able to read anybody's balance, let alone write one. Adding it back is not the fix either:
     * every trader connects as the SAME login role (kudi_trader_login), so the database cannot tell which
     * trader is signed in. A SECURITY DEFINER "my balances" function taking an email would hand out any
     * trader's balances to anyone who passed a different one, which is a disclosure hole that depends on
     * the application never passing the wrong value.
     *
     * Closing that properly means a database identity per trader, which is a much larger change than this
     * task and is worth deciding on its own. Until then the screen shows the numbers derivable from the
     * trades a trader can already read, and the held balance is left out rather than faked or leaked.
     *
     * @return array<string, int>
     */
    public function position(): array
    {
        $db = DB::connection('pgsql_trader');
        $me = $this->signedInTrader();

        return [
            'settled' => (int) $db->selectOne(
                "select count(*) as n from trade
                  where buyer = ? and state in ('released', 'resolved_release')",
                [$me]
            )->n,
            'disputed' => (int) $db->selectOne(
                "select count(*) as n from trade where buyer = ? and state = 'disputed'",
                [$me]
            )->n,
            'open' => (int) $db->selectOne(
                "select count(*) as n from trade
                  where buyer = ? and state in ('open', 'paid_claimed', 'paid_attested')",
                [$me]
            )->n,
            'selling' => (int) $db->selectOne(
                'select count(*) as n from open_offer where seller = ?',
                [$me]
            )->n,
            'trades' => (int) $db->selectOne(
                'select count(*) as n from trade where buyer = ? or seller = ?',
                [$me, $me]
            )->n,
        ];
    }

    /**
     * The signed-in trader, named explicitly.
     *
     * The guard is named because the default guard is the operators', where nobody is signed in on a
     * trader route, and an unqualified user() would return null and quietly pass everything downstream.
     */
    private function signedInTrader(): string
    {
        $email = Auth::guard('trader')->user()?->email;

        if ($email === null) {
            throw new RuntimeException('You need to be signed in.');
        }

        return $email;
    }

    /** Postgres interval, and never one a client invented: the database caps the lifetime at a day. */
    private function lifetime(string $lifetime): string
    {
        return match ($lifetime) {
            '5 minutes' => '5 minutes',
            '1 hour' => '1 hour',
            default => '15 minutes',
        };
    }

    private function call(string $sql, array $bindings, string $fallback): mixed
    {
        try {
            return DB::connection('pgsql_trader')->selectOne($sql, $bindings);
        } catch (QueryException $e) {
            throw new RuntimeException($this->explain($e, $fallback), previous: $e);
        }
    }

    /**
     * A database refusal in words a seller can act on.
     *
     * "will not clear" is the one that matters most and the vaguest as written, so it is spelled out:
     * the rate moved, their walk-away did not, and the offer is still on the board.
     */
    private function explain(QueryException $e, string $fallback): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'will not clear') => 'The rate has moved since you set this. It has not reached the rate you asked for, so nothing has been traded and your offer is still up.',
            str_contains($message, 'no longer open') => 'Somebody already took this offer, or you withdrew it.',
            str_contains($message, 'own offer') => 'You cannot take your own offer.',
            str_contains($message, 'expired') => 'This offer ran out. Publish a new one when you are ready.',
            str_contains($message, 'may not withdraw') => 'That offer is not yours to withdraw.',
            str_contains($message, 'two different assets') => 'An offer needs two different currencies.',
            str_contains($message, 'must be positive') => 'Check the amount and the rate you entered.',
            str_contains($message, 'an offer needs a lifetime') => 'An offer has to say how long it stands.',
            str_contains($message, 'at most one day') => 'An offer can stand for at most a day.',
            str_contains($message, 'exceeds cap') => 'That amount is above the limit for this kind of trade.',
            str_contains($message, 'permission denied') => 'You are signed in as somebody who cannot do that.',
            default => $fallback,
        };
    }
}

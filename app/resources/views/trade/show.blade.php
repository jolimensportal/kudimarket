@extends('layouts.trader')

@section('title', 'Trade #'.$trade->id)

@section('content')
    @php
        // KudiMarket being A PARTY is what makes the tier handled -- not being the seller. On a buy we are
        // the seller; on a sell we are the BUYER (kudi-core/018). Keying this off seller === 'platform' was
        // right only while every trade was a buy, and it told a trader selling US currency that "another
        // verified trader is the other side" -- which is the exact failure the assurance card exists to
        // prevent, printed BY the assurance card. Defined before its first use because Blade runs
        // top to bottom and this is read by the tier card near the top of the page.
        $weAreTheCounterparty = $trade->seller === 'platform' || $trade->buyer === 'platform';
    @endphp
    <p><a class="standalone-link" href="{{ route('trade.board') }}">&larr; Rates</a></p>

    <h1>Trade #{{ $trade->id }}</h1>

    {{--
        One amount is stored: the naira leg, in minor units. The cedi side is DERIVED from the agreed
        rate, because that is what the trader agreed to and deriving it keeps the two sides from
        disagreeing on screen. An earlier version printed the same number for both, which would have
        told a trader to send ₦2,000 when they owe ₦2,000 worth of cedi.
    --}}
    {{--
        The figures come from TradeController, which passes in whose screen this is. The comment here used
        to be true and still is -- both figures come from the service, not from arithmetic in a template --
        but the template was ALSO calling the service itself, with no viewer, which defaults to the seller's
        side. So the view silently discarded the correct figures the controller had handed it and rendered
        the wrong pair, and a fix that was demonstrably correct in the controller looked like it had done
        nothing. One source of truth for money: the controller's.
    --}}

    <div class="card">
        <dl>
            <div>
                <dt>You send</dt>
                <dd class="num">{{ $figures['send'] }}</dd>
            </div>
            <div>
                <dt>You receive</dt>
                <dd class="num">{{ $figures['receive'] }}</dd>
            </div>
            <div>
                <dt>Rate, held</dt>
                <dd class="num">{{ \App\Services\RateBoard::formatRate((float) $trade->rate) }}</dd>
            </div>
            <div>
                <dt>State</dt>
                <dd>{{ str_replace('_', ' ', $trade->state) }}</dd>
            </div>
        </dl>
    </div>

    <div class="card">
        <div class="label">The other side</div>
        @include('partials.assurance', [
            'handled' => $weAreTheCounterparty,
            'counterparty' => $weAreTheCounterparty ? null : $trade->seller,
        ])
    </div>

    {{--
        WHO OWES WHAT, AND TO WHOM. This card was a hardcoded "Send the cedi to MTN Mobile Money" for
        everybody, then patched to say naira for a vendor buyer, and was still wrong twice more: on a buy
        of naira it told the buyer to send cedi, and on a buy of cedi it told a trader to send money rather
        than naming the currency at all.

        All four versions were the same bug. The currency to send is not a property of the trade, it is a
        property of THIS PARTY ON THIS SIDE -- and the figures directly above this card already computed
        it, because forTrade knows who is looking. So it is read from there rather than written out again
        here, and the label can no longer drift from the number above it.

        Three cases, because they genuinely differ:
          - a buyer on a vendor trade sends to the SELLER's bank account, and the seller then owes the
            other currency to the buyer's mobile money.
          - a buyer on our own book sends to US. That is the "KudiMarket handled" tier: we are the
            counterparty and delivery is ours.
          - a seller sends to the BUYER's mobile money, which is the only account on this screen.
    --}}
    @php
        $sendName = ['GHS' => 'cedi', 'NGN' => 'naira'][$figures['send_asset']] ?? $figures['send_asset'];
        $receiveName = ['GHS' => 'cedi', 'NGN' => 'naira'][$figures['receive_asset']] ?? $figures['receive_asset'];
        $isBuyer = $role === 'buyer';
        $isHandled = $weAreTheCounterparty;

        // The two states in which money is in flight and somebody is waiting. Not `open` (nothing has been
        // sent), not `disputed` (release is not pending on a window while a dispute is open), not after
        // release. The card is a statement about held money, so it belongs only where there is held money.
        $awaitingWindow = in_array($trade->state, ['paid_claimed', 'paid_attested'], true);
        $confirmed = $trade->state === 'paid_attested';
    @endphp

    <div class="card">
        @if ($trade->seller === 'platform' && $isBuyer)
            <div class="label">Send the {{ $sendName }} to us</div>
            <p class="num" style="margin-top:4px;color:var(--ink);font-weight:600">MTN Mobile Money &middot; 024 000 1122</p>
            <p>KudiMarket Sim, Adwoa Serwaa. We are the counterparty here, so this {{ $receiveName }} is ours
               to deliver and our money is at risk until it is. Use your own name as the reference. A payment
               from a number that is not registered to you is a payment we cannot give you a receipt for.
               When it has gone, tell us.</p>
        @elseif ($isBuyer)
            <div class="label">Send the {{ $sendName }} to</div>
            <p class="num" style="margin-top:4px;color:var(--ink);font-weight:600">{{ $trade->seller }} &middot; bank account on file</p>
            <p>Use Trade #{{ $trade->id }} as the reference. {{ $trade->seller }} confirms the {{ $sendName }} arrived, and
               then owes the {{ $receiveName }} into your own mobile money account.</p>
        @else
            <div class="label">Send the {{ $sendName }} to</div>
            <p class="num" style="margin-top:4px;color:var(--ink);font-weight:600">MTN Mobile Money &middot; 024 000 1122</p>
            <p>KudiMarket Sim, Adwoa Serwaa. Use your own name as the reference. A payment from a number
               that is not registered to you is a payment we cannot give you a receipt for.</p>
        @endif

        {{--
            THE COMMISSION, if there is one. Two rules, and the second is the one that matters.

            First: it appears only when the ledger holds one. A row reading "KudiMarket fee 0.00" on
            every trade is a row nobody learns to read, and it is a number nobody typed -- the ledger
            froze fee_minor_units at creation, and the settlement charges exactly this. If these two
            ever disagree, the trader has been quoted one amount and charged another, which is the
            whole thing kudi-core/017 exists to make impossible.

            Second: it is OUR fee only, and only when the buyer is the one paying it. We never print a
            counterparty's fee -- the seller sets a walk-away rate that may embed any margin, we never
            see it, and asserting zero would be inventing a fact about a third party. When KudiMarket
            absorbs the commission out of our own margin the row still appears, and says so, because a
            trader should be able to see what they were NOT charged as well as what they were.
        --}}
        @if ($figures['fee'] !== null && $isBuyer && $isHandled)
            <p class="num" style="margin-top:10px;color:var(--ink);font-weight:600">
                KudiMarket fee &mdash; {{ $figures['fee'] }}</p>
            <p>@if ($figures['fee_bearer'] === 'buyer')
                    <strong>Send {{ $figures['send_total'] }} in total</strong> &mdash; that is
                    {{ $figures['send'] }} at the rate above, plus this commission. We charge it in
                    {{ $figures['fee_name'] }} because that is the currency you are paying in, and we take
                    the whole amount in one payment, because a payment a few pesewas short cannot settle.
                @else
                    KudiMarket is paying this one, out of our margin. Your {{ $figures['send'] }} above is
                    the whole amount &mdash; there is nothing extra to send.
                @endif
            </p>
        @endif
    </div>

    {{--
        WHAT THE MONEY IS DOING. This is the screen the mockup draws as "Held, and waiting on the window",
        and it is the one that replaces the phone call: a trader who has just sent money and is staring at
        this page wants to know whether it arrived, when they get paid, and whether any of it is real.

        All three answers are here, and all three are drawn from a stored fact rather than written out:

          - Has my money arrived? Only somebody who did NOT pay can say so. At paid_claimed nobody has said
            yet; at paid_attested they have, and the card names who. Naming the confirmer is the whole
            difference between the tiers -- on our own book it is a member of operations who saw the money
            reach KudiMarket, on a Checked trade it is the SELLER, because the seller is the recipient and
            the attestation is theirs to make. Writing "a human confirmed it" on a vendor trade would put
            KudiMarket behind every peer-to-peer trade: the tier we have deliberately not priced.

          - When do I get paid? Once the claim window closes, and then without anybody pressing anything --
            kudi-core/013's release_due_trades() runs on a one-minute schedule and settles it. Stated in
            words, deliberately without a ticking number: a countdown names an instant the sweep cannot keep,
            and it goes stale the moment a metered connection drops.

          - Is any of it real? The leg we can see is real to the extent one person's confirmation is real.
            The leg in somebody else's account is not, and cannot be, until it moves. That sentence is the
            product, and it is why the card never colours the whole thing green.
    --}}
    @if ($awaitingWindow)
        <div class="card">
            <div class="label">Your money</div>
            <dl class="stack" style="margin-top:8px">
                <div>
                    <dt>You pay &mdash; {{ $figures['send'] }}</dt>
                    <dd>@if ($confirmed)
                            <span class="badge ok">Received</span> @if ($isHandled)
                                A human confirmed it.
                            @elseif ($isBuyer)
                                The seller confirmed it.
                            @else
                                You confirmed it.
                            @endif
                        @else
                            <span class="badge checked">Checking</span> Nobody has confirmed it yet.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>You receive &mdash; {{ $figures['receive'] }}</dt>
                    <dd>Waiting on the window.</dd>
                </div>
            </dl>
            @if ($confirmed)
                <p class="note">The {{ $receiveName }} is released once the claim window closes. Nobody has to
                   press anything &mdash; a sweep releases it on the way past.</p>
            @endif
        </div>

        <div class="card">
            <div class="label">Straight answer</div>
            @if (! $confirmed)
                <p>We have your claim and a person is checking for the payment. We will only call it received
                   when somebody has seen it. Nothing is needed from you while that happens.</p>
            @elseif ($isHandled)
                <p>We can tell you the {{ $sendName }} arrived and that a human confirmed it, because our money
                   is at risk until it is delivered. We cannot tell you anything about the {{ $receiveName }}
                   we have not yet received.</p>
            @else
                <p>We can tell you the {{ $sendName }} arrived and the seller confirmed it. We cannot tell you
                   anything about the {{ $receiveName }} until it reaches you &mdash; that is the trade we
                   have checked the seller for, not one we have put our name to.</p>
            @endif
        </div>
    @endif

    {{--
        Both of these sentences were wrong, and were written before the rules behind them were checked.

        "You can dispute until the claim window closes" is not true: the claim window gates RELEASE, not
        disputes. paid_claimed -> disputed has no time limit, and it should not have one -- money is still
        being held while a trade sits in that state, so a counterparty who has not been paid yet is exactly
        who needs to be able to complain.

        "After that the trade settles on its own" is true NOW, but was written when it was not. The comment
        this replaced said "paid_attested -> released has actor 'system', and there is no scheduler
        registered, so nothing settles on its own." That was already false when it was committed: 4c6ed04
        added kudi-core/013's release_due_trades(), the trades:release-due command and the everyMinute
        schedule on 9 Oct, and that commit is an ancestor of the one that added the sentence. The refusal
        was right, then it stopped being revisited once the release job landed.

        What survived the correction is the honesty rule underneath it: the copy still does not name an
        instant. The sweep runs on a one-minute boundary and a countdown would name a clock time it cannot
        keep, so "Your money" states the window in words and never prints one. TradeScreenTest keeps failing
        on "settles on its own" for exactly this reason.
    --}}
    {{--
        Whether a problem can be reported is a fact about the trade's STATE, and the ledger is the only
        place that knows it: paid_claimed -> disputed and paid_attested -> disputed exist, and there is no
        rule from `open`. This card used to say "you can report a problem" in five states and link it in
        one -- so it promised a route in states where disputing is refused, and stayed silent in
        paid_attested, which is precisely the state a trader reaches when the payment has been attested and
        the money still has not arrived. That is the one state where somebody most needs to complain.
    --}}
    @php
        $canDispute = in_array($trade->state, ['paid_claimed', 'paid_attested'], true);
        $moneyHeld = in_array($trade->state, ['paid_claimed', 'paid_attested', 'disputed'], true);
    @endphp

    <div class="card">
        <div class="label">If something is wrong</div>
        @if ($canDispute)
            <p>Tell us what happened and operations will look at it. Reporting it does not move your money
               &mdash; it puts the trade in front of somebody.</p>
            <p><a class="standalone-link" href="{{ route('trade.dispute', $trade->id) }}">Report a problem</a></p>
        @elseif ($trade->state === 'disputed')
            <p>This one is with operations. They will read both accounts and decide where the money goes,
               and you will see the reason on this page when they have.</p>
        @elseif ($moneyHeld === false && in_array($trade->state, ['released', 'resolved_release'], true))
            <p>This trade has settled, so there is nothing held to dispute. If that is not what you
               expected, report it anyway from
               <a href="{{ route('trade.board') }}">Rates</a> and we will look at what happened.</p>
        @else
            <p>Nothing has been sent yet, so there is no payment to report. If you have already sent
               something, tell us and operations will look at it.</p>
        @endif
    </div>

    <h2>What has happened</h2>
    <p class="note" style="margin:-4px 0 10px">Write-once and hash-chained. Each line commits to the
       one above it, so a line that was edited or removed afterwards is detectable.</p>
    <div class="card">
        <ol class="events">
            @foreach ($events as $event)
                <li>
                    <span class="move">
                        {{ $event->from_state === 'none' ? 'no state yet' : str_replace('_', ' ', $event->from_state) }}
                        &rarr; {{ str_replace('_', ' ', $event->to_state) }}
                    </span>
                    <span class="who">{{ $event->actor }}</span>
                </li>
            @endforeach
        </ol>
    </div>

    {{--
        The claim button appears only while the trade is open. Showing it afterwards would invite a
        trader to press a control that can only fail, and a button that cannot work is worse than no
        button -- especially on a metered connection, where the round trip costs real money.

        "I have paid" rather than "Confirm": the trader is stating a fact about the world, not approving
        ours. Attestation is deliberately absent from this screen. The only way to learn that the money
        arrived is somebody else saying so.
    --}}
    @if ($trade->state === 'open')
        <form method="post" action="{{ route('trade.claim', $trade->id) }}">
            @csrf
            <button type="submit">I have sent the cedi</button>
        </form>
        <p class="note">
            Tap this once you have sent the cedi. If your connection drops, tap again &mdash; it is safe,
            and we will only ever count it once.
        </p>
    @else
        <p class="note">
            @if ($trade->state === 'paid_claimed')
                We have your claim and are checking for the payment. You do not need to do anything.
            @else
                This trade is {{ str_replace('_', ' ', $trade->state) }}. The button is gone because there
                is nothing left for you to press.
            @endif
        </p>
    @endif

    @if (session('notice'))
        <p class="notice" role="status">{{ session('notice') }}</p>
    @endif
@endsection
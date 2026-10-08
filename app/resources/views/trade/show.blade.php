@extends('layouts.trader')

@section('title', 'Trade #'.$trade->id)

@section('content')
    <p><a href="{{ route('trade.board') }}">&larr; Rates</a></p>

    <h1>Trade #{{ $trade->id }}</h1>

    {{--
        One amount is stored: the naira leg, in minor units. The cedi side is DERIVED from the agreed
        rate, because that is what the trader agreed to and deriving it keeps the two sides from
        disagreeing on screen. An earlier version printed the same number for both, which would have
        told a trader to send ₦2,000 when they owe ₦2,000 worth of cedi.
    --}}
    @php
        // Both figures come from the service, not from arithmetic in a template. The cedi leg is
        // derived from the agreed rate rather than stored, because one stored amount plus one agreed
        // rate is the whole record; two stored amounts would eventually disagree.
        $figures = \App\Services\TradeFigures::forTrade($trade);
    @endphp

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
        <p style="margin-top:4px">
            @if ($trade->seller === 'platform')
                <strong>KudiMarket</strong> &mdash; we are the counterparty. If the money does not
                arrive, it comes back to you and it is our loss.
            @else
                {{ $trade->seller }} &mdash; checked, not handled.
            @endif
        </p>
    </div>

    <div class="card">
        <div class="label">Send the cedi to</div>
        <p class="num" style="margin-top:4px;color:var(--ink);font-weight:600">MTN Mobile Money &middot; 024 000 1122</p>
        <p>KudiMarket Sim, Adwoa Serwaa. Use your own name as the reference. A payment from a number
           that is not registered to you is a payment we cannot give you a receipt for.</p>
    </div>

    <div class="card">
        <div class="label">Disputes</div>
        <p>You can dispute until the claim window closes. After that the trade settles on its own.</p>
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
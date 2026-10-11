@extends('layouts.trader')

@section('title', 'What others are selling')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.offer.position') }}">&larr; Where you stand</a></p>

    <h1>What others are selling</h1>

    <p class="note" style="margin:-4px 0 14px">
        These are the lowest rates people will accept. Anything they take clears at <strong>our rate</strong>,
        so a row below our rate still fills &mdash; later.
    </p>

    {{-- Two tiers, two boards, and the difference is the whole of what we promise. Taking a row here is a
         CHECKED trade: we verified that seller, we do not guarantee delivery. Buying from us is KUDIMARKET
         HANDLED: we are the counterparty, our money is at risk and delivery is ours. The vendor board is
         first because it is what this page is, but the buy link is not buried at the bottom -- it is the
         only trade here that somebody can be certain about. --}}
    <div class="card">
        <p>Want currency without relying on another trader being who they say they are?</p>
        <p><a class="standalone-link" href="{{ route('trade.buy.create') }}">Buy from KudiMarket &mdash; we are the counterparty</a></p>
    </div>

    @forelse ($offers as $offer)
        @php
            $mine = $offer->seller === $me;
            $pair = $offer->base_asset.'/'.$offer->quote_asset;
            $board = $rates[$pair] ?? null;
            $clears = $board !== null && (float) $board->rate >= (float) $offer->min_rate;
        @endphp
        <div class="card">
            <dl>
                <div>
                    <dt>{{ $mine ? 'You are selling' : $offer->seller.' is selling' }}</dt>
                    <dd class="num">{{ \App\Services\RateBoard::formatMoney($offer->amount, $offer->base_asset) }}</dd>
                </div>
                <div>
                    <dt>For</dt>
                    <dd class="num">{{ $offer->quote_asset }}</dd>
                </div>
                <div>
                    <dt>No less than</dt>
                    <dd class="num">{{ \App\Services\RateBoard::formatRate($offer->min_rate) }}</dd>
                </div>
                <div>
                    <dt>Our rate now</dt>
                    <dd class="num">{{ $board ? \App\Services\RateBoard::formatRate($board->rate) : 'not published' }}</dd>
                </div>
            </dl>

            @if ($mine)
                <form method="post" action="{{ route('trade.offer.withdraw', $offer->id) }}">
                    @csrf
                    <button type="submit" class="ghost">Withdraw this</button>
                </form>
            @elseif (! $board)
                <p class="note">We have not published a rate for this pair, so nothing here can clear yet.</p>
            @elseif (! $clears)
                <p class="note">
                    Our rate is below what they will accept, so this will not clear until it comes back.
                    Your currency is not at risk and nothing has been traded.
                </p>
            @else
                <form method="post" action="{{ route('trade.offer.accept', $offer->id) }}">
                    @csrf
                    <button type="submit">Take this</button>
                </form>

                {{-- The boundary, at the moment of decision, on the side of the product where it
                     matters most. This card used to say only "opens a trade between you and X" -- naming
                     WHO, and never saying that nobody stands behind them. That omission is the whole
                     consumer-protection risk, on the screen with the most money at stake. --}}
                @include('partials.assurance', [
                    'handled' => false,
                    'counterparty' => $offer->seller,
                    'compact' => true,
                ])
            @endif
        </div>
    @empty
        <div class="card">
            <p>Nobody has anything on offer right now.</p>
            <p><a class="standalone-link" href="{{ route('trade.offer.create') }}">Sell currency instead</a></p>
            <p><a class="standalone-link" href="{{ route('trade.buy.create') }}">Or buy from KudiMarket &mdash; we are the counterparty</a></p>
        </div>
    @endforelse

    @if (session('notice'))
        <p class="notice" role="status">{{ session('notice') }}</p>
    @endif
@endsection

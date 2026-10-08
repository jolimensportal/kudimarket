@extends('layouts.trader')

@section('title', 'Where you stand')

@section('content')
    <h1>Where you stand</h1>

    {{-- No "you hold" figure, and that is a deliberate absence rather than an oversight. It is explained
         in OfferDesk::position(): every trader shares one database login, so the database cannot tell
         which trader is signed in, and any way of reading one balance from here would either expose
         everybody's or depend on the application never passing the wrong name. The counts below all come
         from trades and offers this trader can already see. --}}

    <div class="card">
        <div class="label">Your trades</div>
        <dl>
            <div><dt>Settled</dt><dd class="num">{{ $position['settled'] }}</dd></div>
            <div><dt>Still open</dt><dd class="num">{{ $position['open'] }}</dd></div>
            <div><dt>Disputed</dt><dd class="num">{{ $position['disputed'] }}</dd></div>
            <div><dt>On offer now</dt><dd class="num">{{ $position['selling'] }}</dd></div>
        </dl>
    </div>

    @if (count($rates) > 0)
        <div class="card">
            <div class="label">Our rate today</div>
            @foreach ($rates as $pair => $rate)
                <p class="num" style="margin-top:4px">
                    1 {{ explode('/', $pair)[0] }} = {{ \App\Services\RateBoard::formatRate($rate->rate) }}
                    {{ explode('/', $pair)[1] }}
                </p>
            @endforeach
            <p class="note">Any offer you list clears at our rate, never at a price you set. You set the
               lowest rate you will accept; we quote the rest.</p>
        </div>
    @endif

    <div class="actions two">
        <a href="{{ route('trade.offer.create') }}"><button type="button">Sell currency</button></a>
        <a href="{{ route('trade.offer.board') }}"><button type="button" class="ghost">What others are selling</button></a>
    </div>

    @if (count($mine) > 0)
        <h2>Your offers</h2>
        <div class="card">
            <ol class="events">
                @foreach ($mine as $offer)
                    <li>
                        <span class="move">{{ $offer->base_asset }} &rarr; {{ $offer->quote_asset }}</span>
                        <span class="num">{{ \App\Services\RateBoard::formatMoney($offer->amount, $offer->base_asset) }}</span>
                        <span class="who">no less than {{ \App\Services\RateBoard::formatRate($offer->min_rate) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
@endsection

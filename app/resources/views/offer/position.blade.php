@extends('layouts.trader')

@section('title', 'Where you stand')

@section('content')
    <h1>Where you stand</h1>

    {{--
        The "you hold" figures are HERE, and they were deliberately absent until this commit.

        The reason they were missing is written out below, because it is the thing worth reading twice:
        every trader shared one database login, so the database could not tell them apart, and reading a
        balance meant either exposing everybody's or trusting the application never to pass the wrong name.
        Trusting the application is not a control.

        kudi-core/015 gives the database the identity instead. my_balances() reads the session, so there is
        no trader's name anywhere in the query that could be wrong, and kudi_trader still has no grant on
        account -- there is no table to read, only a function with nothing to choose from.
    --}}

    <div class="card">
        <div class="label">What you hold</div>
        @forelse ($balances as $balance)
            @php
                $decimals = \App\Services\RateBoard::decimalsFor($balance['asset']);
                $available = number_format($balance['available'] / (10 ** $decimals), $decimals);
                $escrow = number_format($balance['escrow'] / (10 ** $decimals), $decimals);
            @endphp
            <dl style="margin-top:6px">
                <div style="display:flex;justify-content:space-between;padding:5px 0">
                    <dt style="opacity:.65">{{ $balance['asset'] }} you can use</dt>
                    <dd class="num">{{ $available }}</dd>
                </div>
                @if ($balance['escrow'] > 0)
                    <div style="display:flex;justify-content:space-between;padding:5px 0">
                        <dt style="opacity:.65">held for a trade in progress</dt>
                        <dd class="num" style="opacity:.75">{{ $escrow }}</dd>
                    </div>
                @endif
            </dl>
        @empty
            <p style="margin:6px 0 0;opacity:.7">Nothing in your account yet.</p>
        @endforelse
        <p style="margin:10px 0 0;opacity:.6">
            Only yours. The database decides that, not this page: your identity is set on the connection
            when you sign in, and the query that fills this in has no trader's name in it to get wrong.
        </p>
    </div>

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

    {{-- Identity details are a separate, mandatory thing from being checked to sell, so they get their
         own quiet line here rather than being folded into the selling flow. --}}
    <p class="note"><a href="{{ route('trade.cdd.show') }}">Your identity details</a></p>

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

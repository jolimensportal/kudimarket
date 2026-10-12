@extends('layouts.trader')

@section('title', 'Rates')

@section('content')
    <h1>Rates</h1>

    <form method="get" action="{{ route('trade.board') }}" class="pair">
        <label for="from">You have</label>
        <select id="from" name="from">
            @foreach (['GHS' => 'cedi', 'NGN' => 'naira'] as $code => $name)
                <option value="{{ $code }}" @selected($code === $base)>{{ $name }} ({{ $code }})</option>
            @endforeach
        </select>

        <label for="to">You want</label>
        <select id="to" name="to">
            @foreach (['GHS' => 'cedi', 'NGN' => 'naira'] as $code => $name)
                <option value="{{ $code }}" @selected($code === $quote)>{{ $name }} ({{ $code }})</option>
            @endforeach
        </select>

        <button type="submit">Show</button>
    </form>

    @if ($ours === null)
        <p class="empty">We have not published a rate for this pair yet.</p>
    @else
        <div class="card ours">
            <div class="label">KudiMarket</div>
            <div class="rate num">{{ \App\Services\RateBoard::formatRate((float) $ours->rate) }}</div>
            <div class="pair-line num">1 {{ $base }} = {{ \App\Services\RateBoard::formatRate((float) $ours->rate) }} {{ $quote }}</div>
            <p>We are the other side of this trade. If the money does not arrive, it comes back to you
               and it is our loss, not a claim you have to win.</p>
            <p class="note">Set by {{ $ours->set_by }}, {{ $ours->effective_from->diffForHumans() }}</p>
            <div class="card-actions">
                <a class="primary" href="{{ route('trade.sell.create') }}">Sell to us</a>
                <a class="ghost" href="{{ route('trade.buy.create') }}">Buy from us</a>
            </div>
        </div>
    @endif

    @if (count($offers) > 0)
        <h2>What people will accept</h2>
        <ul class="offers">
            @foreach ($offers as $offer)
                <li>
                    <span class="who">{{ $offer->seller }}</span>
                    <span class="badge checked">Checked</span>
                    @if ($offer->will_not_clear ?? false)
                        {{-- Shown with its reason rather than hidden. Our rate has moved past what this
                             seller will take, so taking it now would trade them at a price they refused.
                             An unexplained grey row teaches nothing; "our rate moved" does. --}}
                        <span class="note">They want no less than
                            {{ \App\Services\RateBoard::formatRate($offer->min_rate) }}.
                            Our rate has moved below that, so this will not clear until it comes back.</span>
                    @else
                        <span class="r">{{ \App\Services\RateBoard::formatRate($offer->min_rate) }}</span>
                        <span class="num">They will take no less than
                            {{ \App\Services\RateBoard::formatRate($offer->min_rate) }}
                            1 {{ $offer->base_asset }} = {{ \App\Services\RateBoard::formatRate($offer->min_rate) }}
                            {{ $offer->quote_asset }}</span>
                        <span class="note">It clears at our rate, not at the figure above &mdash; that is the
                            lowest they will accept. Identity and receiving account verified. If they fail
                            to deliver, KudiMarket decides the dispute and returns your money, but
                            settlement is not guaranteed.</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (count($legs) > 0)
        <h2>Every rate we have published for this pair</h2>
        <p class="note">Append-only and hash-chained, so a rate we have ever shown stays on file.
           Nothing here is ever edited in place.</p>
        <div class="card">
            <dl>
                @foreach ($legs as $leg)
                    <div>
                        <dt>{{ $leg->base_asset }} &rarr; {{ $leg->quote_asset }}</dt>
                        <dd class="num">{{ \App\Services\RateBoard::formatRate((float) $leg->rate, 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    {{-- The way back to a trade a trader has already opened. Rates is the landing page, so this is the one
         place a trader passes through often enough for the link to be found without hunting. --}}
    <p class="note" style="margin-top:18px">
        <a class="standalone-link" href="{{ route('trade.history') }}">Your trades</a>
    </p>
@endsection
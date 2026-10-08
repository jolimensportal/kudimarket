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
        </div>

        {{-- The button that opens a trade arrives with Task 4. Until then the board is honest about
             what it can do: show a rate, and say who set it. --}}
    @endif

    @if (count($offers) > 0)
        <h2>Checked vendors</h2>
        <ul class="offers">
            @foreach ($offers as $offer)
                <li>
                    <span class="who">{{ $offer->seller }}</span>
                    <span class="badge checked">Checked</span>
                    <span class="r">{{ \App\Services\RateBoard::formatRate((float) $offer->rate) }}</span>
                    <span class="num">1 {{ $base }} = {{ \App\Services\RateBoard::formatRate((float) $offer->rate) }} {{ $quote }}</span>
                    <span class="note">Identity and receiving account verified. If they fail to deliver,
                       KudiMarket decides the dispute and returns your money, but settlement is not
                       guaranteed.</span>
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
@endsection
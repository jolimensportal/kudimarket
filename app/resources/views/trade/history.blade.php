@extends('layouts.trader')

@section('title', 'Your trades')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.board') }}">&larr; Rates</a></p>

    <h1>Your trades</h1>

    {{--
        WHERE THIS LIST COMES FROM. Nothing here reads a name out of a query string. TradeLedger::mine()
        reads my_trades(), which answers from the session identity set at sign-in, so this page can only
        ever show the signed-in trader their own trades -- the same control my_balances() and
        my_trade_counts() rest on (kudi-core/015, /019). The alternative, an email passed in as an
        argument, is the whole vulnerability 015 exists to remove.
    --}}
    <p class="note" style="margin:-4px 0 14px">
        Every trade you are part of, newest first. Open one to see what it is doing.
    </p>

    @forelse ($trades as $trade)
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
                <span class="label">Trade #{{ $trade['id'] }}</span>
                {{-- The badge colour is trade_view's own colour, not a second opinion computed here. --}}
                <span class="{{ $trade['badge'] }}">{{ $trade['state_label'] }}</span>
            </div>

            {{-- The two figures the trade screen leads with, computed by TradeFigures for THIS trader's
                 side. Rendering them the same way here means a trader scanning the list reads the same
                 "you send / you receive" they will read when they open it, rather than a direction that
                 flips because a template defaulted to the seller. --}}
            <dl class="stack" style="margin-top:8px">
                <div style="display:flex;justify-content:space-between;gap:12px;padding:5px 0">
                    <dt style="opacity:.65">You send</dt>
                    <dd class="num">{{ $trade['figures']['send'] }}</dd>
                </div>
                <div style="display:flex;justify-content:space-between;gap:12px;padding:5px 0">
                    <dt style="opacity:.65">You receive</dt>
                    <dd class="num">{{ $trade['figures']['receive'] }}</dd>
                </div>
            </dl>

            <p class="note">
                @if ($trade['handled'])
                    KudiMarket is the other side &mdash; our money is at risk, not just theirs.
                @else
                    With {{ $trade['counterparty'] }}, another trader we checked.
                @endif
                Last change {{ $trade['updated_at']->diffForHumans() }}.
            </p>

            <p style="margin-top:8px">
                <a class="standalone-link" href="{{ route('trade.show', $trade['id']) }}">Open trade #{{ $trade['id'] }}</a>
            </p>
        </div>
    @empty
        <div class="card">
            <p>You have not opened a trade yet.</p>
            <p><a class="standalone-link" href="{{ route('trade.board') }}">See today's rates</a></p>
        </div>
    @endforelse
@endsection

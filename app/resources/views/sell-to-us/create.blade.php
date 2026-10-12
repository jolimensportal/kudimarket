@extends('layouts.trader')

@section('title', 'Sell currency to KudiMarket')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.board') }}">&larr; Rates</a></p>

    <h1>Sell currency to us</h1>

    {{-- Same tier as the buy screen, and the same promise read from the other side: WE are the
         counterparty, so the rate shown is the rate you get and our money is at risk until we deliver. --}}
    @include('partials.assurance', ['handled' => true])

    <p class="note" style="margin:-4px 0 14px">
        Selling a trade to another trader is <strong>checked</strong>, which is a different promise:
        <a href="{{ route('trade.offer.board') }}">see what other traders are selling</a>.
    </p>

    @if ($notice)
        <div class="card"><p>{{ $notice }}</p></div>
    @else
        <form method="post" action="{{ route('trade.sell.store') }}">
            @csrf

            <label for="receive_asset">I want to receive</label>
            <select id="receive_asset" name="receive_asset" required>
                @foreach (['NGN' => 'Naira', 'GHS' => 'Cedi'] as $code => $name)
                    <option value="{{ $code }}" @selected(old('receive_asset', $receiveAsset) === $code)>{{ $name }} ({{ $code }})</option>
                @endforeach
            </select>
            <p class="note" style="margin:-2px 0 14px">
                Both directions, because cedi is our pivot and naira is the other currency we hold.
                Everything else is vendor supply &mdash; we never hold dollars, pounds or the CFA franc.
            </p>

            <label for="receive_amount">How much</label>
            <input id="receive_amount" name="receive_amount" inputmode="decimal" required
                   value="{{ old('receive_amount', '1000.00') }}">
            @error('receive_amount')
                <p class="note" style="color:var(--red)">{{ $message }}</p>
            @enderror

            {{-- The price, before the button. Same rule as the buy screen (TradeFigures), so the figure
                 here and the figure on the trade screen come from one implementation. --}}
            <div class="card" style="margin:16px 0">
                <dl>
                    {{-- The total, for the same reason as the buy screen: the commission is added on top,
                         so the conversion is not the amount that leaves the account. --}}
                    <div>
                        <dt>{{ $figures['fee'] ? 'You send in total' : 'You send' }}</dt>
                        <dd class="num">{{ $figures['fee'] ? $figures['send_total'] : $figures['send'] }}</dd>
                    </div>
                    <div><dt>You receive</dt><dd class="num">{{ $figures['receive'] }}</dd></div>
                </dl>
                @if ($figures['fee'])
                    <dl style="margin-top:10px">
                        <div>
                            <dt>KudiMarket fee</dt>
                            <dd class="num">{{ $figures['fee'] }}</dd>
                        </div>
                    </dl>
                    <p class="note" style="margin-top:10px">
                        That is {{ $figures['send'] }} at today's rate plus the commission above, in
                        {{ $figures['fee_name'] }}
                        because that is the currency you are paying in.
                    </p>
                @endif
                <p class="note">
                    We check that your payment arrived before the {{ $figures['receive'] }} is released.
                    You do not confirm your own payment &mdash; a person who did not send it has to.
                </p>
            </div>

            <button type="submit">Open this trade</button>
        </form>
    @endif
@endsection
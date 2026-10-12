@extends('layouts.trader')

@section('title', 'Buy currency from KudiMarket')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.board') }}">&larr; Rates</a></p>

    <h1>Buy currency from us</h1>

    {{-- The boundary, stated here as the tier it is rather than as a paragraph comparing tiers. This
         screen is unambiguously HANDLED, so it says so plainly and points at the other screen for the
         other case. The old note carried both explanations and split the reader's attention across a
         distinction they do not need on this page. --}}
    @include('partials.assurance', ['handled' => true])

    <p class="note" style="margin:-4px 0 14px">
        Buying a trade from another trader is <strong>checked</strong>, which is a different promise:
        <a href="{{ route('trade.offer.board') }}">see what other traders are selling</a>.
    </p>

    @if ($notice)
        <div class="card"><p>{{ $notice }}</p></div>
    @else
        <form method="post" action="{{ route('trade.buy.store') }}">
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

            {{-- The price, before the button and not only after it. A number that appears when you press a
                 button is a number you cannot check, and the trader is owed the price before they commit.
                 These two figures come from TradeFigures -- the same rule the trade screen uses -- so
                 they cannot drift apart from what the trade will actually say. --}}
            <div class="card" style="margin:16px 0">
                <dl>
                    {{-- The TOTAL, not the conversion. A commission is added on top, so the conversion
                         alone is not the number that leaves the account -- and a trader who commits to the
                         conversion and is charged the difference has been told one thing and charged
                         another. That arrived here only because the commission work stopped at the trade
                         screen: the form said "You pay 1,349.53" and the trade then said "Send 1,363.02
                         in total". Item 7 exists to answer this BEFORE the phone call, so it belongs here. --}}
                    <div>
                        <dt>{{ $figures['fee'] ? 'You send in total' : 'You pay' }}</dt>
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
                        because that is the currency you are paying in. One payment, in one go, because a
                        payment a few pesewas short cannot settle.
                    </p>
                @else
                    <p class="note" style="margin-top:10px">
                        At today's rate, held from the card. Open the trade and the payment details are on
                        the trade screen, with the account and the reference to use.
                    </p>
                @endif
            </div>

            <button type="submit">Open this trade</button>
        </form>
    @endif
@endsection
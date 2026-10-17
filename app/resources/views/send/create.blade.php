@extends('layouts.trader')

@section('title', 'Send money to Ghana')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.history') }}">&larr; Your sends</a></p>

    <h1>Send money home</h1>

    @if ($notice)
        <div class="card"><p>{{ $notice }}</p></div>
    @else
        <form method="post" action="{{ route('trade.send.store') }}">
            @csrf

            <label for="send_amount">How much cedi you are sending</label>
            <input id="send_amount" name="send_amount" inputmode="decimal" required
                   {{-- The link's amount, in the box it belongs to. The quote is computed from the
                        query string, so without this the page promised "You send 250.00 GHS" above an
                        empty field, and submitting it sent nothing. --}}
                   value="{{ old('send_amount', $sendMinor > 0 ? number_format($sendMinor / 100, 2, '.', '') : '') }}"
                   placeholder="1000.00">
            @error('send_amount')
                <p class="note" style="color:var(--red)">{{ $message }}</p>
            @enderror

            {{-- The price, before the button, and before any of it is committed. The words are "they
                 receive" rather than "you receive" because the money is not for the sender: naming a
                 beneficiary on the next two fields and then saying "you receive" on this one would be
                 the screen contradicting itself three lines apart. --}}
            @if ($quote)
                <div class="card" style="margin:16px 0">
                    <dl>
                        <div><dt>You send</dt><dd class="num">{{ $quote['send'] }}</dd></div>
                        <div><dt>They receive</dt><dd class="num">{{ $quote['receive'] }}</dd></div>
                    </dl>
                    <p class="note">
                        At {{ $quote['rate'] }} naira to the cedi, and no fee on top &mdash; the rate above
                        is what they get. The rate is held for this send once you open it.
                    </p>
                </div>
            @endif

            <label for="beneficiary_name">Who is receiving it</label>
            <input id="beneficiary_name" name="beneficiary_name" required
                   value="{{ old('beneficiary_name') }}" placeholder="Their full name">
            @error('beneficiary_name')
                <p class="note" style="color:var(--red)">{{ $message }}</p>
            @enderror

            <label for="beneficiary_phone">Their phone number</label>
            <input id="beneficiary_phone" name="beneficiary_phone" inputmode="tel" required
                   value="{{ old('beneficiary_phone') }}" placeholder="+2348012345678">
            @error('beneficiary_phone')
                <p class="note" style="color:var(--red)">{{ $message }}</p>
            @enderror

            <p class="note" style="margin:14px 0 0">
                They receive the naira in cash, the same day. We confirm your cedi arrived, a person at
                KudiMarket hands the naira over, and only then does the send complete &mdash; so the money
                cannot be marked as sent before it has been handed over.
            </p>

            <button type="submit">Open this send</button>
        </form>
    @endif
@endsection
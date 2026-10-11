@extends('layouts.trader')

@section('title', 'Sell currency')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.offer.position') }}">&larr; Where you stand</a></p>

    <h1>List currency you want to sell</h1>

    {{-- The wording here is the whole design decision, made explicit to the person typing. They are NOT
         setting a price. They are naming the lowest rate they will accept, and the trade clears at our
         rate. Saying so on the form, not only in a footnote, is what stops somebody reading "rate" and
         expecting to set one. --}}
    <p class="note" style="margin:-4px 0 14px">
        You are not setting a price. You are saying the <strong>lowest rate you will accept</strong>, and
        your currency is traded at <strong>our rate</strong> &mdash; the one on the board today. An offer
        below our rate still clears, just later.
    </p>

    <form method="post" action="{{ route('trade.offer.store') }}">
        @csrf

        <label for="base_asset">I have</label>
        <select id="base_asset" name="base_asset" required>
            @foreach (['NGN' => 'Naira', 'GHS' => 'Cedi'] as $code => $name)
                <option value="{{ $code }}" @selected(old('base_asset') === $code)>{{ $name }} ({{ $code }})</option>
            @endforeach
        </select>

        <label for="quote_asset">I want</label>
        <select id="quote_asset" name="quote_asset" required>
            @foreach (['GHS' => 'Cedi', 'NGN' => 'Naira'] as $code => $name)
                <option value="{{ $code }}" @selected(old('quote_asset') === $code)>{{ $name }} ({{ $code }})</option>
            @endforeach
        </select>

        <label for="amount_minor">Amount, in whole units</label>
        {{-- Minor units, so 50000000 is ₦500,000.00. Typed in whole units by a person and multiplied here,
             because a form asking for "₦500,000.00" is a form where somebody types 500,000.5 and means
             half a naira that does not exist. --}}
        <input id="amount_minor" type="text" inputmode="numeric" name="amount_display" value="{{ old('amount_display') }}"
               placeholder="500000" required
               oninput="document.getElementById('amount_minor').value = Math.round(parseFloat(this.value.replace(/[^0-9.]/g,'')) * 100);">
        <input id="amount_minor" type="hidden" name="amount_minor" value="{{ old('amount_minor') }}">
        @error('amount_minor')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="min_rate">Lowest rate you will accept</label>
        <input id="min_rate" type="text" inputmode="decimal" name="min_rate" value="{{ old('min_rate') }}"
               placeholder="0.00600" required>
        <p class="note">Whole quote per one whole base. 0.006 means 1 naira for 0.006 cedi.</p>
        @error('min_rate')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="lifetime">How long it stands</label>
        <select id="lifetime" name="lifetime">
            @foreach (['5 minutes' => '5 minutes', '15 minutes' => '15 minutes', '1 hour' => '1 hour'] as $value => $label)
                <option value="{{ $value }}" @selected(old('lifetime', '15 minutes') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <p class="note">
            Nothing is held while an offer stands. It is a statement of what you will accept, and it goes
            on your permanent record the moment you publish it &mdash; so it cannot be edited afterwards,
            only withdrawn.
        </p>

        <button type="submit">Publish this offer</button>
    </form>
@endsection

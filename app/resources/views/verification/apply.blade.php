@extends('layouts.trader')

@section('title', 'Ask to be checked')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.verification') }}">&larr; Getting checked</a></p>

    <h1>Ask to be checked</h1>

    {{-- The five things, because a trader told only "too short" will send two words and then stop.
         This list is brand/product.html's own admission criteria, not a form invented for convenience. --}}
    <div class="card">
        <div class="label">What we check</div>
        <ul class="rows">
            <li>Your name, as on your Ghana Card</li>
            <li>Where you live</li>
            <li>The account is in your own name — a sole proprietorship is not a separate person, so the
                account has to be yours</li>
            <li>Where the money comes from</li>
            <li>Your Ghana Card number</li>
        </ul>
        <p class="note">Write it however you like. A sentence is plenty. What we cannot do is guess.</p>
    </div>

    <form method="post" action="{{ route('trade.verification.store') }}">
        @csrf

        <label for="details">In your own words</label>
        <textarea id="details" name="details" rows="8" required
                  placeholder="Adwoa Serwaa. Ghana Card GHA-000000000-1-1. I live at 12 Boundary Road, Accra. My MTN number 0240000001 is in my own name at KudiMarket. The money is from trading.">{{ old('details') }}</textarea>
        @error('details')<p class="error" role="alert">{{ $message }}</p>@enderror

        <p class="note">
            What you write is kept on your permanent record, and whoever decides reads it word for word.
            Leave out anything you would not want read out — a PIN, a document number you have not been
            asked for.
        </p>

        <button type="submit">Send this</button>
    </form>
@endsection

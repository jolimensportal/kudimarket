@extends('layouts.trader')

@section('title', 'Report a problem')

@section('content')
    <p><a href="{{ route('trade.show', $trade->id) }}">&larr; Trade #{{ $trade->id }}</a></p>

    <h1>What happened?</h1>

    {{-- Filing this does not move money. Not your cedi, and not the naira. It puts the trade in front of
         operations, who will look and then decide. --}}
    <p class="note" style="margin:-4px 0 12px">
        Sending this <strong>does not move your money</strong>. It puts the trade in front of operations,
        who will look and then decide.
    </p>

    <form method="post" action="{{ route('trade.dispute', $trade->id) }}">
        @csrf

        <label for="reason">In your own words</label>
        <textarea id="reason" name="reason" rows="6" required
                  placeholder="When you sent it, from which number, and what you have seen since.">{{ old('reason') }}</textarea>

        @error('reason')
            <p class="error" role="alert">{{ $message }}</p>
        @enderror

        <p class="note">
            This is permanent. <strong>Both sides</strong> and a regulator can read it, word for word, and
            it cannot be edited afterwards &mdash; not by you, not by us. So do not put a MoMo
            <strong>PIN</strong>, a document number, or anything else you would not want on the record.
        </p>

        <button type="submit">Send this to operations</button>
    </form>

    <p class="note"><a href="{{ route('trade.show', $trade->id) }}">Keep waiting instead</a></p>

    @if (session('notice'))
        <p class="notice" role="status">{{ session('notice') }}</p>
    @endif
@endsection
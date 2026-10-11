@extends('layouts.trader')

@section('title', 'Your identity details')

@section('content')
    <p><a class="standalone-link" href="{{ route('trade.board') }}">&larr; The board</a></p>

    <h1>Your identity details</h1>

    {{-- The distinction, said plainly and on this screen rather than buried in a policy page. These two
         are different, and a trader who assumes "checked" covers identity has been told something
         untrue: /check is us trusting you to list an offer, /id is us establishing who you are because
         the law requires it of us. Only the second one is an AML control. --}}
    <div class="card">
        <div class="label">This is not the same as being checked</div>
        <p class="note">
            Asking to trade with others is <a href="{{ route('trade.verification') }}">separate</a> and
            optional. These details are not: we are a licensed currency business, and the law requires us
            to know who our customers are and where their money comes from before we trade with them.
        </p>
    </div>

    @if ($status === null)
        <div class="card">
            <div class="label">What we need</div>
            <ul class="rows">
                <li>The identity document you trade with — usually your Ghana Card</li>
                <li>Your residential address</li>
                <li>What you are using KudiMarket for</li>
                <li>Where the money comes from</li>
            </ul>
            <p class="note">Four things, and none of them is a password we then forget.</p>
        </div>
        <p><a class="standalone-link" href="{{ route('trade.cdd.submit') }}">Give us these details &rarr;</a></p>
    @else
        <div class="card">
            <div class="label">Where you stand</div>
            @if ($status->is_cdd_verified)
                <p>On file. Document <b>{{ $status->doc_number }}</b>, confirmed {{ $status->created_at->format('j M Y') }}.</p>
                @if ($status->biometric_on_record)
                    <p class="note">You have also been verified in person for a high-value transaction.</p>
                @endif
            @elseif ($status->is_cdd_refused)
                {{-- A refusal with no reason is a dead end, so the reason is here in full. --}}
                <p>We could not use these details.</p>
                <p class="note"><b>Why:</b> see the message from us, or contact support. Nothing is kept against you for asking again.</p>
            @else
                <p>Sent. We are looking at it now.</p>
            @endif
            @unless ($status->is_cdd_verified)
                <p><a class="standalone-link" href="{{ route('trade.cdd.submit') }}">Send new details</a></p>
            @endunless
        </div>
    @endif
@endsection
@extends('layouts.trader')

@section('title', 'Getting checked')

@section('content')
    <h1>Getting checked</h1>

    @if ($status === null)
        {{-- Never applied. Say plainly what checking buys, and what it does not. --}}
        <div class="card">
            <p>To trade with other people — rather than only with KudiMarket — somebody here has to check
               you first.</p>
            <p class="note">We check your identity, your address, the account in your own name, where the
               money comes from, and your Ghana Card. It usually takes a day.</p>
            <p><a href="{{ route('trade.verification.apply') }}"><button type="button">Ask to be checked</button></a></p>
        </div>
        <p class="note">You can buy from us today without this. Selling to other traders is what it unlocks.</p>
    @else
        <div class="card">
            @if ($status->is_verified)
                <div class="label">Checked</div>
                <p>You are checked, so you can sell to other traders. Decided by
                   <strong>{{ $status->actor }}</strong> on {{ \Illuminate\Support\Facades\Date::parse($status->created_at)->format('j M Y') }}.</p>
            @elseif ($status->is_pending)
                <div class="label">Waiting</div>
                <p>We have your application. Nothing is needed from you.</p>
                <p class="note">Sent {{ \Illuminate\Support\Facades\Date::parse($status->created_at)->diffForHumans() }}.</p>
            @else
                {{-- The refusal. This is the screen that turns "no" into something a trader can act on. --}}
                <div class="label">Not checked — yet</div>
                <p>{{ $status->narrative }}</p>
                <p class="note">
                    {{ $status->actor }} &middot;
                    {{ \Illuminate\Support\Facades\Date::parse($status->created_at)->format('j M Y') }}
                </p>
                <p><a href="{{ route('trade.verification.apply') }}"><button type="button">Fix it and ask again</button></a></p>
            @endif
        </div>
    @endif
@endsection

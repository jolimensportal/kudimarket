@extends('layouts.marketing')

@section('title', 'KudiMarket — Your market. Your rate.')
@section('description', 'Move between cedi and naira at a rate you set. KudiMarket holds your money in escrow and releases it only when you say so.')

@section('content')
    {{--
        The public face of KudiMarket, and the first thing anybody sees at kudimarket.com.

        Three rules from brand/BRAND.md decide what is on this page:

        1. Say we are not open yet, at the top, before anything else. Rule 3 of the voice section is
           "admit limits out loud before the user finds them". The licence position in REGULATORY.md
           5b is not settled, so a page that invited trading would be inviting something we may not
           legally do. This page invites interest and nothing else.
        2. Three promises that are all mechanically true, not adjectives. "Escrow you can see" is a
           fact about the ledger; "bank-grade security" would not be.
        3. Market speech, not fintech speech. Short sentences. Concrete nouns. No "seamless", no
           "revolutionary", no "cutting-edge".
    --}}

    <div class="hero">
        <p class="eyebrow">Cedi &harr; Naira &middot; Ghana</p>

        <h1>Your market.<br>Your rate.</h1>

        <p class="lead">
            List your naira, name your cedi. KudiMarket holds the money in escrow and
            releases it only when you say so.
        </p>
    </div>

    <ul class="terms">
        <li>
            <span class="n">01</span>
            <span class="t">Escrow you can see.
                <span class="d">Your cedi sits in an account in your own name until the trade settles. Not ours. Yours.</span>
            </span>
        </li>
        <li>
            <span class="n">02</span>
            <span class="t">Nobody touches your money until you say so.
                <span class="d">You confirm the payment arrived. Operations cannot confirm it for you, and neither can we.</span>
            </span>
        </li>
        <li>
            <span class="n">03</span>
            <span class="t">The rate you agreed is the rate you get.
                <span class="d">We quote our rate. You list a price you will accept. The trade is priced at the card, not at your walk-away.</span>
            </span>
        </li>
    </ul>

    @if (session('subscribed'))
        <div class="done">
            <b>You are on the list.</b> We will write when we open, and not before.
        </div>
    @else
        <form method="post" action="{{ route('landing.subscribe') }}">
            @csrf
            <label for="email">Get told when we open</label>
            <div class="row">
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" autocomplete="email" required
                       @error('email') aria-describedby="email-error" @enderror>
                <button type="submit">Notify me</button>
            </div>
            @error('email')
                <p class="note" id="email-error" style="color: var(--red);">{{ $message }}</p>
            @enderror
            <p class="note">One email, when we open. No follow-up sequence.</p>
        </form>
    @endif
@endsection

@extends('layouts.marketing')

@section('title', 'KudiMarket — Your market. Your rate.')
@section('description', 'Trade cedi and naira at a rate you set. KudiMarket holds your money in escrow and releases it only when you say so.')

@section('content')
    {{--
        The public face of KudiMarket, and the first thing anybody sees at kudimarket.com.

        The structure follows what the pre-launch pages that actually converted have in common, rather
        than what is merely handsome:

          * a REAL screen of the product, not a mockup. Framer let you hover the thing you were
            signing up for; Robinhood put a phone on one side of a split. This page ships photographs
            of the running application, because it is running.
          * the email field in the first screenful. Every teardown agrees the waitlist CTA can come
            earlier and be repeated more often than a normal page would dare, because the ask is
            interest rather than a purchase.
          * prices, shown. A bureau de change that hides its number is the thing this product exists to
            be different from. Both directions are read live out of the ledger, and the fee is quoted
            in basis points, because a fee quoted as a percentage is a fee being hidden.
          * a share link that moves the sharer up the queue. Dropbox reached four million on this one
            mechanic; it is the difference between a list and a launch.

        And the rule from brand/BRAND.md still governs the words: admit limits before the user finds
        them, so it says "Not open yet" at the top. Saying so does not make the licence question in
        REGULATORY.md 5b go away, but a page that quietly implied otherwise would be worse.
    --}}

    @if (session('subscribed'))
        @include('public.partials.confirmation')
    @else
        {{-- ─── Hero ────────────────────────────────────────────────────────────────
             Split on desktop, stacked on mobile with the proof screen BELOW the field, because
             on a 390px phone the ask has to come before the evidence or people never scroll. --}}
        <section class="hero">
            <div class="hero-copy">
                <p class="status"><span class="dot"></span>Not open yet</p>

                <h1>Your market.<br>Your rate.</h1>

                <p class="lead">
                    List your naira, name your cedi. KudiMarket holds the money in escrow
                    and releases it only when you say so.
                </p>

                <form method="post" action="{{ route('landing.subscribe') }}" class="signup" novalidate>
                    @csrf
                    @if ($referrer)
                        <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
                    @endif
                    <label for="email">Get told when we open</label>
                    <div class="row">
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               placeholder="you@example.com" autocomplete="email" required
                               aria-describedby="email-help email-error">
                        <button type="submit">Notify me</button>
                    </div>
                    @error('email')
                        <p class="err" id="email-error" role="alert">{{ $message }}</p>
                    @enderror
                    <p class="help" id="email-help">
                        One email, when we open. No follow-up sequence.
                        @if ($waiting > 0)
                            <strong>{{ $waiting }} {{ Str::plural('person', $waiting) }} {{ Str::plural('is', $waiting) }} already on the list.</strong>
                        @endif
                    </p>
                </form>
            </div>

            {{-- The real application, photographed. Nothing on this page is drawn. --}}
            <div class="hero-shot">
                <img src="{{ asset('img/landing/board.png') }}" width="780" height="1688"
                     alt="The KudiMarket rate board on a phone: cedi and naira, our published rate, and the buttons to buy from us or sell to us."
                     fetchpriority="high" decoding="async">
                <p class="shot-cap">The board, on a phone, running now.</p>
            </div>
        </section>

        {{-- ─── What we publish ──────────────────────────────────────────────────
             Every teardown of a converted fintech page says the same thing: lead with proof, not
             innovation. These are read live from the rate card and the trade policy, so they cannot
             drift out of date without the page being wrong, which is the point. --}}
        @if (count($rates) > 0)
            <section class="numbers">
                <h2>What we charge, before you ask</h2>
                <dl>
                    @if (isset($rates['GHS/NGN']))
                        <div><dt>We publish</dt><dd class="num">{{ rtrim(rtrim($rates['GHS/NGN'], '0'), '.') }} <span>GHS to NGN</span></dd></div>
                    @endif
                    @if (isset($rates['NGN/GHS']))
                        <div><dt>And back</dt><dd class="num">{{ rtrim(rtrim($rates['NGN/GHS'], '0'), '.') }} <span>NGN to GHS</span></dd></div>
                    @endif
                    @if ($feeBps !== null)
                        <div><dt>Our fee</dt><dd class="num">{{ rtrim(rtrim(sprintf('%.2f', $feeBps / 100), '0'), '.') }}% <span>{{ $feeBps }} bps</span></dd></div>
                    @endif
                </dl>
                <p class="foot">Read live from the ledger. When the number changes, this page changes with it.</p>
            </section>
        @endif

        {{-- ─── Three promises ──────────────────────────────────────────────────
             A ruled list, not three floating cards: a card grid says "features", a ruled list says
             "terms". Each one is mechanically true of the ledger, which is what makes it worth
             printing. --}}
        <section class="terms">
            <h2>What we promise, and how you could check it</h2>
            <ol>
                <li>
                    <span class="t">Escrow you can see.</span>
                    <span class="d">Your cedi sits in an account in your own name until the trade settles. Not ours. Yours.</span>
                </li>
                <li>
                    <span class="t">Nobody touches your money until you say so.</span>
                    <span class="d">You confirm the payment arrived. Operations cannot confirm it for you, and neither can we.</span>
                </li>
                <li>
                    <span class="t">The rate you agreed is the rate you get.</span>
                    <span class="d">We quote our rate. You list a price you will accept. The trade is priced at the card, not at your walk-away.</span>
                </li>
            </ol>
        </section>

        {{-- ─── Two more real screens ───────────────────────────────────────────
             Proof, not decoration. The trust objection on a pre-launch money product is "show me it
             works", and a paragraph cannot answer that. --}}
        <section class="proof">
            <figure>
                <img src="{{ asset('img/landing/buy.png') }}" width="780" height="1688" loading="lazy" decoding="async"
                     alt="The buy screen: enter an amount in naira and the cedi is derived from the published card rate.">
                <figcaption>Enter naira. The cedi comes off the card, not off a quote that moves.</figcaption>
            </figure>
            <figure>
                <img src="{{ asset('img/landing/history.png') }}" width="780" height="1688" loading="lazy" decoding="async"
                     alt="Trade history: every trade you are a party to, newest first, with its state and both amounts.">
                <figcaption>Every trade you are part of, with both amounts and where it stands.</figcaption>
            </figure>
        </section>

        {{-- ─── What happens to the address ─────────────────────────────────────
             A fintech waitlist that will not say what it does with the address is a page that has
             already lost the trust it was asking for. --}}
        <section class="plain">
            <h2>What we do with your address</h2>
            <ul>
                <li>Store it, and write once when we open.</li>
                <li>Nothing else. No partners, no selling it, no automated sequence.</li>
                <li>Reply to any email from us with <strong>delete</strong> and it is gone.</li>
            </ul>
            <p class="foot">
                We are completing licensing before we open. Nothing on this page is an offer to trade.
            </p>
        </section>

        {{-- The second ask. Lower on the page, same single action --}}
        <section class="closer">
            <h2>When it opens, you will hear it from us first.</h2>
            <form method="post" action="{{ route('landing.subscribe') }}" novalidate>
                @csrf
                <div class="row">
                    <input type="email" name="email" placeholder="you@example.com" autocomplete="email"
                           aria-label="Email address" required>
                    <button type="submit">Notify me</button>
                </div>
            </form>
        </section>
    @endif
@endsection

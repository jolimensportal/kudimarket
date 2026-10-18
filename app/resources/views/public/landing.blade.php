@extends('layouts.marketing')

@section('title', 'KudiMarket — Your market. Your rate.')
@section('description', 'Send cedi, receive naira, at a rate you see before you commit. KudiMarket is not open yet — join the list and we will write once.')

@section('content')
    {{--
        The public face of KudiMarket, and the first thing anybody sees at kudimarket.com.

        What changed, and why, is worth keeping in the file rather than in a commit message:

          * The phone screenshots are gone. Every one said "Sell to us", "Buy from us" or "Your
            trades", which is a P2P exchange photographed inside a remittance page. The contradiction
            was visible in the image, so no caption could fix it. The hero proof is now the live rate
            card, which is the claim the business actually makes, is read from rate_card, and cannot
            disagree with the copy beside it.

          * Escrow is gone from this page. BRAND.md section 0 retires it: the business moves money,
            it does not hold it on the customer's behalf. The banned-word test now forbids escrow,
            trade and offer so the exchange cannot creep back one noun at a time.

          * The rate table became a worked example. A sender wants to see their own transaction, not
            three abstract rates. The figures are multiplied out of the live rate in the controller,
            so they move when the rate moves. A worked example frozen at one number is the exact lie
            this page exists not to tell.

          * The doors point at anchors that exist. The previous set pointed at #send, #earn and
            #trust, none of which was on the page, so all three were dead links.

        What this page deliberately does NOT do: show a screen of the sending flow, because that
        flow does not exist yet. "Show me it works" is the strongest objection on a money product
        and the rate answers it with a number rather than an interface. That answer weakens over time
        and should be revisited the moment there is a send screen worth showing.
    --}}

    @if (session('subscribed'))
        @include('public.partials.confirmation')
    @else
        {{-- ─── Hero ───────────────────────────────────────────────────────────────────
             The device is the proof and it is the first thing you can touch: five
             screens, the live rate, figures nobody typed in, and it ends in the one
             ask this page has. The doors and the rate card it replaces were three
             arguments at once, and none of them was "show me it works". --}}
        <section class="hero hero--centred">
            <div class="hero-copy hero-copy--centred">
                <p class="status"><span class="dot"></span>Not open yet · Kumasi &amp; Lagos</p>

                <h1>Send money home<br>in a minute.</h1>

                <p class="lead">
                    Pick an amount. Watch the rate. Confirm in words. Nothing moves until the naira lands.
                </p>
            </div>

            @include('public.partials.send-demo')
        </section>

        {{-- ─── The ask ─────────────────────────────────────────────────────────────────
             One form, directly under the demo that ends by pointing at it. The header
             link and the device's sent screen both land on this field. --}}
        <section class="join" id="waitlist">
            <h2>Be first when we open.</h2>
            <p>One email, when we open. Nothing else.</p>

            <form method="post" action="{{ route('landing.subscribe') }}" class="signup" novalidate>
                @csrf
                @if ($referrer)
                    <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
                @endif
                <label for="email">Email address</label>
                <div class="row">
                    <input type="email" id="email" name="email" value="{{ old('email') }}"
                           autocomplete="email" required aria-describedby="email-help email-error">
                    <button type="submit">Join the waitlist</button>
                </div>
                @error('email')
                    <p class="err" id="email-error" role="alert">{{ $message }}</p>
                @enderror
                <p class="help" id="email-help">
                    No spam, no resold lists.
                    @if ($waiting > 0)
                        <strong>{{ $waiting }} {{ Str::plural('person', $waiting) }} {{ Str::plural('is', $waiting) }} already on the list.</strong>
                    @endif
                </p>
                <p class="alt">
                    No email?
                    <a href="{{ $waLink ?? '#' }}" rel="noopener">WhatsApp us</a>
                    <span aria-hidden="true">·</span>
                    <a href="{{ $tgLink ?? '#' }}" rel="noopener">Telegram</a>
                </p>
            </form>
        </section>

        {{-- ─── Three promises ────────────────────────────────────────────────────────
             A ruled list, not three floating cards: a card grid says "features", a
             ruled list says "terms". Each one is mechanically true, which is what makes
             it worth printing. Three columns at lg -- two left the third orphaned
             with a gap beside it. --}}
        <section class="promise" id="promise">
            <h2>What we promise, and how you could check it</h2>
            <ol>
                <li>
                    <span class="t">Cash in hand, the same day.</span>
                    <span class="d">The recipient is holding it, not watching a progress bar. You confirm when they have it.</span>
                </li>
                <li>
                    <span class="t">Nobody touches your money until you say so.</span>
                    <span class="d">Operations cannot confirm a delivery on your behalf, and neither can we.</span>
                </li>
                <li>
                    <span class="t">The rate you agreed is the rate you get.</span>
                    <span class="d">We quote our rate and you see it before you commit. The transfer is priced at that number.</span>
                </li>
            </ol>
        </section>

        {{-- ─── Address promise, and the second ask ─────────────────────────────────────
             Side by side at lg. A waitlist that will not say what it does with the address
             has already lost the trust it was asking for. --}}
        <section class="foot2">
            <div class="plain">
                <h2>What we do with your address</h2>
                <ul>
                    <li>Store it, and write once when we open.</li>
                    <li>Nothing else. No partners, no selling it, no sequence.</li>
                    <li>Reply with <strong>delete</strong> and it is gone.</li>
                </ul>
                <p class="foot">
                    We are completing licensing before we open. No money moves through this page.
                </p>
            </div>

            <div class="closer">
                <h2>When it opens, you will hear it from us first.</h2>
                <form method="post" action="{{ route('landing.subscribe') }}" novalidate>
                    @csrf
                    <div class="row">
                        <input type="email" name="email" placeholder="you@example.com" autocomplete="email"
                               aria-label="Email address" required>
                        <button type="submit">Notify me</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
@endsection
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
        {{-- ─── Hero ─────────────────────────────────────────────────────────────────
             The split is what uses the width. Below lg it is one column and the rate
             card follows the form, which is correct on a phone: the ask comes before
             the evidence, or on a 390px screen nobody reaches the evidence. --}}
        <section class="hero">
            <div class="hero-copy">
                <p class="status"><span class="dot"></span>Not open yet</p>

                <h1>Your market.<br>Your rate.</h1>

                <p class="lead">
                    You send your side, we send ours. Nothing waits on a stranger to turn up,
                    because we are the other side.
                </p>

                <nav class="doors" aria-label="What brings you here">
                    <a href="#cost"><span class="n">01</span> I am sending money</a>
                    <a href="#cost"><span class="n">02</span> I want to know what it costs first</a>
                    <a href="#promise"><span class="n">03</span> I do not trust apps that took my money</a>
                </nav>

                <form method="post" action="{{ route('landing.subscribe') }}" class="signup" novalidate>
                    @csrf
                    @if ($referrer)
                        <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
                    @endif
                    <label for="email">Tell us when we open</label>
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
                        One email, when we open. No sequence.
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
            </div>

            {{-- The proof, stated rather than photographed. --}}
            @if (isset($rates['GHS/NGN']))
                <aside class="rate-card" aria-label="Today's published rate">
                    <div class="rate-card__head">
                        <span class="rate-card__title">Today's rate</span>
                        <span class="live"><span class="dot"></span>Live from the ledger</span>
                    </div>

                    <div class="rate-card__body">
                        <p class="rate-card__pair">GHS &rarr; NGN</p>
                        <p class="rate-card__value num">{{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }}</p>
                        <p class="rate-card__eq">1 cedi is {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }} naira</p>

                        <div class="rate-card__split">
                            <div>
                                <span class="k">And back</span>
                                <span class="n num">{{ \App\Support\RateFormatter::format($rates['NGN/GHS'] ?? 0) }}</span>
                            </div>
                            <div>
                                <span class="k">Our fee</span>
                                <span class="n num">{{ $feeBps !== null ? rtrim(rtrim(sprintf('%.2f', $feeBps / 100), '0'), '.') : '&mdash;' }}%</span>
                            </div>
                        </div>
                    </div>

                    <p class="rate-card__foot">
                        Every rate we have ever shown stays on file. Nothing here is edited in place.
                    </p>
                </aside>
            @endif
        </section>

        {{-- ─── What it costs ──────────────────────────────────────────────────────────
             A worked example, not a rate table. Both figures are multiplied out of the
             live rate in the controller, so when the rate moves this moves with it. --}}
        @if ($example !== null)
            <section class="cost" id="cost">
                <h2>What it costs, before you ask</h2>
                <dl>
                    <div>
                        <dt>You send</dt>
                        <dd class="num">{{ $example['send'] }} <span>GHS</span></dd>
                    </div>
                    <div>
                        <dt>They receive</dt>
                        <dd class="num">{{ $example['receive'] }} <span>NGN</span></dd>
                    </div>
                    <div>
                        <dt>Our fee</dt>
                        <dd class="num">{{ $feeBps !== null ? rtrim(rtrim(sprintf('%.2f', $feeBps / 100), '0'), '.') : '&mdash;' }}% <span>{{ $feeBps }} bps, charged on the send</span></dd>
                    </div>
                </dl>
                <p class="foot">
                    Worked example at today's rate. Yours is priced at the rate you are shown,
                    not a rate that moves while you look.
                </p>
            </section>
        @endif

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
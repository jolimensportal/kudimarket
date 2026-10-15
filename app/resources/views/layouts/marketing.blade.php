<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KudiMarket')</title>
    <meta name="description" content="@yield('description', 'Trade cedi and naira at a rate you set. KudiMarket holds your money in escrow and releases it only when you say so.')">
    <meta property="og:title" content="KudiMarket — Your market. Your rate.">
    <meta property="og:description" content="Trade cedi and naira at a rate you set. Escrow you can see.">
    <meta property="og:image" content="{{ asset('img/landing/board.png') }}">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#FCF6EE">

    {{-- Same reasoning as the trader layout: inline CSS, no build step, no webfont fetch. The market
         is on budget Android handsets over poor mobile data, and every one of those requests has to
         arrive. --}}
    <style>
        @include('partials.tokens')

        *, *::before, *::after { box-sizing: border-box; }
        h1, h2, h3, p, ol, ul, dl, dd, figure, blockquote { margin: 0; padding: 0; }
        ol, ul { list-style: none; }
        img { display: block; max-width: 100%; height: auto; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            background: var(--bone);
            color: var(--ink);
            font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
            font-size: 16px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 22px; }

        /* One type scale, three sizes. The trust object is restraint, and restraint on a page
           full of numbers is mostly a decision about how many sizes are in play. */
        h1 { font-size: clamp(40px, 8.2vw, 74px); line-height: 1.02; letter-spacing: -0.035em; font-weight: 800; text-wrap: balance; }
        h2 { font-size: clamp(25px, 4.4vw, 38px); line-height: 1.12; letter-spacing: -0.025em; font-weight: 800; text-wrap: balance; }
        .lead { font-size: clamp(17px, 2.2vw, 20px); color: var(--ink-mid); text-wrap: pretty; }

        .mark { font-size: 21px; letter-spacing: -0.015em; text-decoration: none; color: var(--ink); }
        .mark b { font-weight: 800; color: var(--plum-600); }

        .top {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 20px 0; border-bottom: 1px solid var(--line);
        }

        .num { font-variant-numeric: tabular-nums; }

        /* ── Hero ──────────────────────────────────────────────────────────────
           Split on desktop. On a phone the field sits ABOVE the screenshot on purpose: the ask
           comes before the evidence, or on a 390px screen nobody reaches the evidence. */
        .hero {
            display: grid; gap: 40px; align-items: center;
            padding: 54px 0 8px;
        }
        @media (min-width: 900px) {
            .hero { grid-template-columns: 1fr 400px; gap: 72px; padding: 76px 0 24px; }
        }

        .status {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 600; letter-spacing: 0.01em;
            color: var(--plum-600);
            border: 1px solid var(--line); background: var(--paper);
            padding: 6px 13px 6px 11px; border-radius: 999px; margin-bottom: 22px;
        }
        .status.ok { color: var(--green); }
        .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        .hero .lead { margin-top: 20px; max-width: 27em; }

        /* ── Three doors ──────────────────────────────────────────────────────
           Stacked rules, not a card grid. A card grid reads as a feature row; these
           are the three reasons somebody arrives, and they are the page's only real
           navigation. Touch targets stay at 44px+ because most of this traffic is a
           thumb on a budget Android. */
        .doors { margin-top: 30px; border-top: 1px solid var(--line); max-width: 30em; }
        .doors a {
            display: flex; align-items: baseline; gap: 14px;
            padding: 15px 2px; min-height: 48px;
            border-bottom: 1px solid var(--line);
            font-size: clamp(16px, 2.2vw, 18px); font-weight: 600; letter-spacing: -0.01em;
            color: var(--ink); text-decoration: none;
        }
        .doors a:hover { color: var(--plum-600); }
        .doors a:focus-visible { outline: 2px solid var(--plum-400); outline-offset: 2px; }
        .doors .n { font-variant-numeric: tabular-nums; font-size: 13px; font-weight: 700; color: var(--plum-300); }

        .alt { font-size: 14px; color: var(--ink-soft); margin-top: 14px; }
        .alt a { color: var(--plum-600); }
        .alt span { margin: 0 6px; color: var(--line); }

        /* ── Signup ─────────────────────────────────────────────────────────── */
        .signup, .closer form { margin-top: 30px; }
        label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 10px; letter-spacing: 0.005em; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; }

        input[type=email], input[type=text] {
            flex: 1 1 210px; min-width: 0;
            font: inherit; font-size: 16px;         /* 16px or iOS zooms the viewport on focus */
            padding: 14px 15px;
            border: 1px solid var(--line); border-radius: 10px;
            background: var(--paper); color: var(--ink);
        }
        input::placeholder { color: var(--ink-soft); opacity: 1; }
        input:focus-visible { outline: 2px solid var(--plum-400); outline-offset: 1px; border-color: var(--plum-400); }

        button {
            flex: 0 0 auto; font: inherit; font-size: 16px; font-weight: 700; white-space: nowrap;
            padding: 14px 24px; min-height: 50px;
            border: 1px solid var(--plum-600); border-radius: 10px;
            background: var(--plum-600); color: #fff; cursor: pointer;
            transition: transform .12s ease, background .12s ease;
        }
        button:hover { background: var(--plum-800); border-color: var(--plum-800); }
        button:active { transform: translateY(1px); }
        button:focus-visible { outline: 2px solid var(--plum-400); outline-offset: 2px; }

        .help, .foot { font-size: 14px; color: var(--ink-soft); margin-top: 12px; text-wrap: pretty; }
        .help strong { color: var(--ink-mid); }
        .err { font-size: 14px; color: var(--red); margin-top: 10px; }

        /* ── The screenshot ─────────────────────────────────────────────────── */
        .hero-shot { text-align: center; }
        .hero-shot img {
            width: 100%; max-width: 300px; margin: 0 auto;
            border-radius: 26px; border: 1px solid var(--line);
            box-shadow: 0 24px 60px -28px color-mix(in oklch, var(--plum-900) 42%, transparent);
        }
        .shot-cap { font-size: 13px; color: var(--ink-soft); margin-top: 14px; }

        /* ── Numbers ────────────────────────────────────────────────────────── */
        .numbers { padding: 76px 0 0; }
        .numbers dl {
            display: grid; gap: 0; margin-top: 26px;
            border-top: 1px solid var(--line);
        }
        @media (min-width: 720px) { .numbers dl { grid-template-columns: repeat(3, 1fr); } }
        .numbers dl > div { padding: 22px 0; border-bottom: 1px solid var(--line); }
        @media (min-width: 720px) {
            .numbers dl > div { padding: 26px 26px 26px 0; border-bottom: 0; }
            .numbers dl > div + div { border-left: 1px solid var(--line); padding-left: 26px; }
        }
        .numbers dt { font-size: 13px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-soft); }
        .numbers dd { font-size: clamp(28px, 5vw, 40px); font-weight: 800; letter-spacing: -0.03em; margin-top: 8px; }
        .numbers dd span { display: block; font-size: 14px; font-weight: 500; letter-spacing: 0; color: var(--ink-soft); margin-top: 4px; }
        .numbers .foot { margin-top: 18px; }

        /* ── Promises ───────────────────────────────────────────────────────── */
        .terms { padding: 80px 0 0; }
        .terms ol { margin-top: 26px; border-top: 1px solid var(--line); }
        .terms li { display: grid; gap: 3px 28px; padding: 24px 0; border-bottom: 1px solid var(--line); }
        @media (min-width: 720px) { .terms li { grid-template-columns: 300px 1fr; align-items: baseline; } }
        .terms .t { font-size: clamp(19px, 2.6vw, 23px); font-weight: 700; letter-spacing: -0.02em; }
        .terms .d { color: var(--ink-mid); max-width: 46ch; }

        /* ── Screens ────────────────────────────────────────────────────────── */
        .proof { padding: 80px 0 0; display: grid; gap: 44px; }
        @media (min-width: 760px) { .proof { grid-template-columns: 1fr 1fr; gap: 56px; } }
        .proof figure { text-align: center; }
        .proof img {
            width: 100%; max-width: 280px; margin: 0 auto;
            border-radius: 22px; border: 1px solid var(--line);
            box-shadow: 0 20px 50px -30px color-mix(in oklch, var(--plum-900) 40%, transparent);
        }
        .proof figcaption { font-size: 14px; color: var(--ink-soft); margin-top: 14px; text-wrap: pretty; }

        /* ── Plain ──────────────────────────────────────────────────────────── */
        .plain { padding: 80px 0 0; }
        .plain ul { margin-top: 22px; border-top: 1px solid var(--line); }
        .plain li { padding: 16px 0 16px 22px; border-bottom: 1px solid var(--line); position: relative; color: var(--ink-mid); }
        .plain li::before { content: ""; position: absolute; left: 2px; top: 26px; width: 6px; height: 6px; border-radius: 50%; background: var(--plum-300); }

        /* ── Closer ──────────────────────────────────────────────────────────── */
        .closer { padding: 84px 0 8px; text-align: center; }
        .closer form { max-width: 440px; margin-inline: auto; }
        .closer .row { justify-content: center; }

        /* ── Confirmation ───────────────────────────────────────────────────── */
        .confirm { padding: 54px 0 20px; }
        @media (min-width: 900px) { .confirm { padding: 88px 0 40px; max-width: 640px; } }
        .confirm .lead { margin-top: 20px; max-width: 34em; }
        .share { margin-top: 34px; }
        .share input { width: 100%; flex: none; cursor: pointer; }

        footer {
            margin: 76px 0 0; padding: 26px 0 44px; border-top: 1px solid var(--line);
            font-size: 14px; color: var(--ink-soft);
            display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: space-between;
        }
        footer a { color: var(--plum-600); }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="top">
            <a class="mark" href="{{ route('landing') }}"><b>kudi</b>market</a>
        </header>

        @yield('content')

        <footer>
            <span>&copy; {{ date('Y') }} KudiMarket</span>
            <span><a href="mailto:hello@kudimarket.com">hello@kudimarket.com</a></span>
        </footer>
    </div>
</body>
</html>

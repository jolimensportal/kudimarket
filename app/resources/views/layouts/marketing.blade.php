<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KudiMarket')</title>
    <meta name="description" content="@yield('description', 'Move between cedi and naira at a rate you set. KudiMarket holds your money in escrow and releases it only when you say so.')">

    {{-- Same reasoning as the trader layout: inline CSS, no build step, no webfont fetch. The market
         is on budget Android handsets over poor mobile data, and every one of those requests has to
         arrive. --}}
    <style>
        @include('partials.tokens')

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            background: var(--bone);
            color: var(--ink);
            font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
            font-size: 16px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }

        .wrap { max-width: 720px; margin: 0 auto; padding: 0 20px; }

        /* The wordmark is the same lockup the app uses: *kudi* light, *market* 800. */
        .mark { font-size: 20px; letter-spacing: -0.01em; text-decoration: none; color: var(--ink); }
        .mark b { font-weight: 800; color: var(--plum-600); }

        .top {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 18px 0; border-bottom: 1px solid var(--line);
        }

        /* An open status, stated before anyone asks. brand/BRAND.md rule 3: admit limits out loud
           before the user finds them. */
        .pill {
            font-size: 13px; font-weight: 600; color: var(--plum-600);
            border: 1px solid var(--line); background: var(--paper);
            padding: 5px 11px; border-radius: 999px; white-space: nowrap;
        }

        .hero { padding: 56px 0 8px; }
        .eyebrow {
            font-size: 13px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--ink-soft); margin-bottom: 18px;
        }

        h1 {
            font-size: clamp(38px, 11vw, 64px);
            line-height: 1.04;
            letter-spacing: -0.03em;
            font-weight: 800;
            text-wrap: pretty;
        }

        .lead {
            font-size: clamp(17px, 4.4vw, 20px);
            color: var(--ink-mid);
            margin-top: 20px;
            max-width: 30em;
            text-wrap: pretty;
        }

        /* Three promises as a ruled list, not three floating cards. A card grid says "features";
           a ruled list says "these are the terms". brand/BRAND.md section 4: market speech, not
           fintech speech, and no adjective doing work a fact could do. */
        .terms { margin: 44px 0 0; border-top: 1px solid var(--line); }
        .terms li {
            list-style: none;
            display: flex; gap: 16px; align-items: baseline;
            padding: 18px 0; border-bottom: 1px solid var(--line);
        }
        .terms .n {
            font-variant-numeric: tabular-nums; font-weight: 700;
            color: var(--plum-300); font-size: 14px; min-width: 1.6em;
        }
        .terms .t { font-size: 17px; font-weight: 600; }
        .terms .d { display: block; font-size: 15px; font-weight: 400; color: var(--ink-mid); margin-top: 3px; }

        form { margin: 44px 0 0; }
        label { display: block; font-size: 15px; font-weight: 600; margin-bottom: 10px; }

        .row { display: flex; flex-wrap: wrap; gap: 10px; }

        input[type=email], input[type=text] {
            flex: 1 1 220px; min-width: 0;
            font: inherit; font-size: 16px;      /* 16px so iOS does not zoom on focus */
            padding: 13px 14px;
            border: 1px solid var(--line); border-radius: 10px;
            background: var(--paper); color: var(--ink);
        }
        input:focus-visible { outline: 2px solid var(--plum-400); outline-offset: 1px; border-color: var(--plum-400); }

        button {
            flex: 0 0 auto; font: inherit; font-size: 16px; font-weight: 700;
            padding: 13px 22px; min-height: 48px;
            border: 1px solid var(--plum-600); border-radius: 10px;
            background: var(--plum-600); color: #fff; cursor: pointer;
        }
        button:hover { background: var(--plum-800); border-color: var(--plum-800); }
        button:focus-visible { outline: 2px solid var(--plum-400); outline-offset: 2px; }

        .note { font-size: 14px; color: var(--ink-soft); margin-top: 14px; }

        .done {
            margin: 44px 0 0; padding: 20px;
            background: var(--paper); border: 1px solid var(--line); border-left: 4px solid var(--green);
            border-radius: 10px;
        }
        .done b { color: var(--green); }

        footer {
            margin: 64px 0 0; padding: 24px 0 40px;
            border-top: 1px solid var(--line);
            font-size: 14px; color: var(--ink-soft);
            display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: space-between;
        }
        footer a { color: var(--plum-600); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <a class="mark" href="{{ route('landing') }}"><b>kudi</b>market</a>
            <span class="pill">Not open yet</span>
        </div>

        @yield('content')

        <footer>
            <span>&copy; {{ date('Y') }} KudiMarket</span>
            <span><a href="mailto:hello@kudimarket.com">hello@kudimarket.com</a></span>
        </footer>
    </div>
</body>
</html>

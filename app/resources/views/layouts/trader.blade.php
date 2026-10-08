<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KudiMarket')</title>
    {{-- Inline, not a build step. The market is on budget Android handsets over poor mobile data, and
         a stylesheet that 404s on a bad connection is a page that arrives unstyled with the numbers
         still on it. Tailwind comes later, with the rest of the app, once there is a Vite build. --}}
    <style>
        :root {
            --plum-900: #331931;
            --plum-800: #462444;
            --plum-600: #5E2D5A;
            --plum-400: #743F6F;
            --plum-300: #8B6187;
            --bone: #FCF6EE;
            --paper: #FFFFFF;
            --green: #185B37;
            --red: #933331;
            --ink: var(--plum-900);
            --ink-mid: color-mix(in oklch, var(--plum-900) 74%, var(--bone));
            --ink-soft: color-mix(in oklch, var(--plum-900) 52%, var(--bone));
            --line: color-mix(in oklch, var(--plum-900) 12%, var(--bone));
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            background: var(--bone);
            color: var(--ink);
            font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
            font-size: 16px;      /* 16px on mobile so iOS does not zoom the viewport on focus */
            line-height: 1.5;
        }

        /* Currency, rates and counts line up in a column. Proportional digits make a rate board
           look like a typo. */
        .num, output { font-variant-numeric: tabular-nums; }

        a { color: var(--plum-600); }

        .bar {
            background: var(--plum-900);
            color: var(--bone);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .bar .mark { font-weight: 300; letter-spacing: -0.02em; font-size: 18px; }
        .bar .mark b { font-weight: 800; }

        main { max-width: 720px; margin: 0 auto; padding: 20px 16px 48px; }

        h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 16px; }
        h2 { font-size: 17px; font-weight: 600; letter-spacing: -0.01em; margin: 26px 0 10px; }

        .card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 15px 16px;
        }
        .card + .card { margin-top: 10px; }
        .card.ours { border-color: var(--plum-600); background: var(--plum-600); color: var(--bone); }
        .card.ours .label { color: color-mix(in oklch, var(--bone) 72%, var(--plum-600)); }
        .card.ours p { color: color-mix(in oklch, var(--bone) 82%, var(--plum-600)); }
        .card.ours .note { color: color-mix(in oklch, var(--bone) 70%, var(--plum-600)); }

        .label {
            font-size: 12px; font-weight: 600; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--ink-soft);
        }

        .rate { font-size: 32px; font-weight: 600; letter-spacing: -0.02em; margin: 4px 0 2px; }
        .pair-line { font-size: 15px; }
        .note { font-size: 14px; color: var(--ink-soft); margin-top: 8px; }
        /* A confirmation after a write. Plum, because the claim is pending rather than settled -- the
           money has not moved and green would say it had. Mixed from tokens that exist: the first
           version named --plum-100 and --plum-700, which were never defined, so the notice rendered with
           no background and inherited text. The design linter did not catch it -- it checks hex values
           and cannot see that a var() points at nothing. */
        .notice {
            margin-top: 12px; padding: 10px 12px; border-radius: 10px;
            background: color-mix(in oklch, var(--plum-300) 14%, var(--bone));
            color: var(--plum-900); font-size: 15px;
        }
        .card p { font-size: 15px; color: var(--ink-mid); margin-top: 8px; }

        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            padding: 3px 9px; border-radius: 999px;
            border: 1px solid currentColor; color: var(--plum-600);
        }
        .badge.checked { color: var(--ink-soft); }

        form.pair {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            margin-bottom: 16px;
        }
        form.pair label { font-size: 14px; color: var(--ink-soft); }
        textarea {
            width: 100%; font: inherit; color: var(--ink); padding: 10px 12px;
            border-radius: 10px; border: 1px solid color-mix(in oklch, var(--plum-900) 20%, var(--bone));
            background: var(--paper); resize: vertical; min-height: 120px;
        }
        textarea:focus-visible { outline: 2px solid var(--plum-600); outline-offset: 1px; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
        .error { color: var(--red); font-size: 14px; margin-top: 6px; }
        select, button, input[type=text] {
            font: inherit; font-size: 16px;
            min-height: 44px;            /* a tap target you can hit on a moving bus */
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid color-mix(in oklch, var(--plum-900) 20%, var(--bone));
            background: var(--paper);
            color: var(--ink);
        }
        button {
            background: var(--plum-600); color: var(--bone);
            border-color: var(--plum-600); font-weight: 600;
            cursor: pointer;
        }
        button.ghost { background: var(--paper); color: var(--plum-600); border-color: var(--plum-400); }
        button:disabled { background: var(--paper); color: var(--ink-soft); border-color: var(--line); cursor: not-allowed; }

        ul.offers { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        ul.offers li {
            background: var(--paper); border: 1px solid var(--line);
            border-radius: 12px; padding: 14px 16px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: baseline;
        }
        ul.offers .who { font-weight: 600; }
        ul.offers .r { font-weight: 600; font-variant-numeric: tabular-nums; }
        ul.offers .note { flex-basis: 100%; margin-top: 0; }

        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; margin: 0; }
        dt { font-size: 13px; color: var(--ink-soft); }
        dd { font-size: 16px; font-weight: 500; margin: 0; }

        .empty { color: var(--ink-soft); font-size: 15px; padding: 20px 0; }
        .error { color: var(--red); font-size: 14px; margin-top: 6px; }

        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 22px; }
        .actions button { width: 100%; }

        @media (prefers-color-scheme: dark) {
            /* Not a dark theme. A dimmed inversion of a light page would put the plum at a contrast
               we have not measured. If we want dark, it gets measured tokens like every other
               colour. */
        }
    </style>
</head>
<body>
    <header class="bar">
        <span class="mark">kudi<b>market</b></span>
        @auth('trader')
            <form method="post" action="{{ route('sign-out') }}" style="margin:0">
                @csrf
                <button type="submit" class="ghost"
                        style="background:transparent;color:var(--bone);border:1px solid rgba(252,246,238,.35);min-height:36px;font-size:14px;font-weight:500;padding:4px 12px">
                    {{ auth('trader')->user()->name }} &middot; sign out
                </button>
            </form>
        @endauth
    </header>

    <main>
        @if (session('status'))
            <p class="note" role="status">{{ session('status') }}</p>
        @endif

        @yield('content')
    </main>
</body>
</html>
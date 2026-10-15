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
        {{-- The tokens live in one file so the public landing page shares them instead of keeping a
             second copy that drifts. Do not paste them back in here. --}}
        @include('partials.tokens')

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            background: var(--bone);
            /* Safe areas. A notched phone cuts into the top of the header and the bottom of the page:
               without these the wordmark sits under the notch and the last control under the home
               indicator. env() is 0 on a device with no inset, so this costs nothing on a desktop. */
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
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
            /* The header is full-bleed plum, so it needs the notch inset itself. */
            padding-top: calc(14px + env(safe-area-inset-top));
            padding-left: calc(16px + env(safe-area-inset-left));
            padding-right: calc(16px + env(safe-area-inset-right));
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .bar .mark { font-weight: 300; letter-spacing: -0.02em; font-size: 18px; }
        .bar .mark b { font-weight: 800; }

        main { max-width: 720px; margin: 0 auto; padding: 20px 16px 48px; }
        /* Bottom inset so the last button clears the home indicator on a notched phone. */
        main { padding-bottom: calc(48px + env(safe-area-inset-bottom)); }

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
        /* The board's card is filled plum, so the buttons on it are inverted: a paper button reads
           against the field the way the plum button reads against the page. Two actions fit one row at
           390px and wrap to two full-width rows below it -- both 44px, both real tap targets. */
        .card-actions {
            display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px;
        }
        .card-actions a {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 44px; padding: 8px 16px; border-radius: 10px;
            font: inherit; font-size: 16px; font-weight: 600; text-decoration: none;
            border: 1px solid transparent;
        }
        .card-actions a.primary { background: var(--paper); color: var(--plum-900); }
        .card-actions a.ghost { border-color: color-mix(in oklch, var(--bone) 55%, var(--plum-600)); color: var(--bone); }
        @media (max-width: 360px) { .card-actions a { flex: 1 1 100%; } }

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
        /* The green confirmation the locked product reference already uses (.badge.ok = --green,
           product-preview/index.html). It is a CONFIRMATION state -- money a person has seen arrive --
           and never anything else: green on a pending payment would say the money had moved when it had
           not, which is the same mistake the .notice rule below was written to avoid. --green is a
           declared token; an earlier badge here named colours that were not, and rendered with none. */
        .badge.ok { color: var(--green); }
        /* The red counterpart, and the same token the locked product reference uses for it
           (.badge.lost = --red, product-preview/index.html). A trade that was cancelled or refunded is
           the only thing that earns it: green is money a person has seen arrive, red is money that went
           back, and a state in flight is neither, so it keeps the plain plum badge. Named .lost to match
           the reference rather than inventing a second word for the same signal. */
        .badge.lost { color: var(--red); }

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
        /* input:not([type]) is here because an input with no type attribute IS a text input, but the
           attribute selector input[type=text] does not match it. The amount field on the buy and sell
           screens is written without a type, so it rendered as a bare browser-default box -- 19px tall,
           no radius -- beside a properly styled select. Fixed at the selector rather than by adding
           type="text" to each field, because the next type-less input would inherit the same bug. */
        select, button, input[type=text], input:not([type]) {
            font: inherit; font-size: 16px;
            min-height: 44px;            /* a tap target you can hit on a moving bus */
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid color-mix(in oklch, var(--plum-900) 20%, var(--bone));
            background: var(--paper);
            color: var(--ink);
        }
        /* Date inputs sit alongside text inputs in the CDD form. Styled to match so the row of two
           dates reads as one field pair rather than two differently-drawn boxes, and kept at 16px /
           44px for the same reason as the others: a tap target and no iOS zoom on focus. */
        input[type=date] {
            font: inherit; font-size: 16px;
            min-height: 44px;
            padding: 8px 12px; width: 100%;
            border-radius: 10px;
            border: 1px solid color-mix(in oklch, var(--plum-900) 20%, var(--bone));
            background: var(--paper);
            color: var(--ink);
        }
        input[type=date]:focus-visible,
        select:focus-visible,
        input[type=text]:focus-visible,
        input:not([type]):focus-visible {
            outline: 2px solid var(--plum-600); outline-offset: 1px;
        }
        /* Two fields side by side, wrapping to one column when there is not the room. Used by the CDD
           form for issue/expiry dates; the wrap is the point, since a trader is often on a phone. */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        @media (max-width: 520px) { .grid2 { grid-template-columns: 1fr; } }
        .hint { font-weight: 400; color: var(--ink-soft); }
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
        /* A list of balances is a LIST, not a grid of stats. Forcing it into two columns put
           "held for a trade in progress" beside "GHS you can use" at 390px, which wrapped both labels
           mid-phrase and left two numbers competing for one row. dl.stack is one per row, always. */
        dl.stack { grid-template-columns: 1fr; gap: 0; }
        dt { font-size: 13px; color: var(--ink-soft); }
        dd { font-size: 16px; font-weight: 500; margin: 0; }

        .empty { color: var(--ink-soft); font-size: 15px; padding: 20px 0; }
        .error { color: var(--red); font-size: 14px; margin-top: 6px; }

        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 22px; }
        .actions button { width: 100%; }

        /* ---------------------------------------------------------- phone is the design width
         * These screens were drawn at 305px and are used on budget Android handsets, so 44px is the
         * floor for anything you tap, not an aspiration. Measured at 390px in a real browser: nothing
         * overflows horizontally (the layout is flex and wraps), but three things were under the floor
         * -- the sign-out button at 36px, and the standalone back-links at 17-19px.
         *
         * SCOPED BY CLASS, and the obvious way to scope it was wrong twice.
         *
         * First attempt: min-height on every a[href]. The links inside running prose inherited it and
         * every paragraph containing a link got a 44px line box.
         *
         * Second attempt, and this is the one worth remembering: `p > a[href]:only-child`. It MATCHED
         * the in-prose link anyway, because :only-child counts ELEMENT children and ignores text nodes
         * -- the paragraph is a mix of text and one link, so the link really is the only element
         * child. A selector that looks like it means "a link alone in its paragraph" in fact means "a
         * paragraph containing exactly one link", which is the opposite case.
         *
         * So it is a class, stated by the author, on the links that stand alone: the back-links and the
         * "Give us these details" call to action. A link inside running prose is text, and text keeps
         * its line height.
         */
        .standalone-link {
            display: inline-block;
            min-height: 44px;
            padding: 4px 0;
        }

        @media (max-width: 360px) {
            /* Not 400px, deliberately. At 390px (iPhone 12/13/14) the stats dl holds two columns fine --
               its labels are short, "You send", "You pay". Stacking there would make every screen
               longer for nothing. Below 360px the labels start wrapping, so it drops to one per row. */
            dl { grid-template-columns: 1fr; gap: 10px 0; }
        }

        /* ---------------------------------------------------------- the assurance boundary
         * The two tiers, side by side, in the same colours the locked product reference already uses
         * (.badge.handled = plum, .badge.checked = ink-soft, per product-preview/index.html). No new
         * colour is invented here, which is the point: plum already means "we are on the hook" and
         * ink-soft already means "somebody else is".
         *
         * The left border names --plum-300, which is a real token. It was briefly written against
           --line-mid, which is NOT declared anywhere; an undeclared var() resolves to transparent and
           would have taken the colour signal with it. Same class of bug as the --plum-100 notice
           background that shipped once already.
         *
         * The card is tinted differently on each side on purpose. A trader scanning a screen should be
         * able to tell "is this mine or theirs" before reading a word -- but the words carry the same
         * meaning, so the colour is never the only thing saying it.
         */
        .assurance {
            border: 1px solid var(--line);
            border-left: 3px solid var(--plum-300);
            border-radius: 10px;
            padding: 12px 14px;
            margin: 10px 0;
            background: var(--paper);
        }
        .assurance.is-handled {
            border-left-color: var(--plum-600);
            background: color-mix(in oklch, var(--plum-600) 5%, var(--paper));
        }
        .assurance.is-checked {
            border-left-color: var(--plum-300);
            background: var(--paper);
        }

        .assurance-tier {
            display: flex; align-items: center; gap: 9px; flex-wrap: wrap;
            margin: 0 0 6px;
        }
        .assurance-what {
            font-size: 14px; font-weight: 600; color: var(--ink);
        }

        /* The promise line is the one that must be read. Same size as body copy, not smaller -- a
           caveat set in fine print is a caveat nobody reads, and this is the whole product promise. */
        .assurance-promise {
            font-size: 15px; color: var(--ink-mid); margin: 0; text-wrap: pretty;
        }
        .assurance-promise strong { color: var(--ink); font-weight: 600; }

        .assurance-detail {
            font-size: 14px; color: var(--ink-soft); margin: 6px 0 0; text-wrap: pretty;
        }

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
                        style="background:transparent;color:var(--bone);border:1px solid rgba(252,246,238,.35);min-height:44px;font-size:14px;font-weight:500;padding:4px 12px">
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
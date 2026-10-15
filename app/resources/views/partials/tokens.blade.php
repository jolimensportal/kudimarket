{{--
    The tokens, in one place.

    Extracted from layouts/trader.blade.php when the public landing page needed the same colours, and
    the alternative was a second copy of this block that would drift. One source, two layouts.

    Every value here is locked in brand/BRAND.md "Colour system (LOCKED — 6 Oct 2026)", and kudilint
    enforces the palette against it. --plum-300 is the only one that reaches AA rather than AAA and
    is for large text only, never body copy.
--}}
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

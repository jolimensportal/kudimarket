<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KudiMarket')</title>
    {{-- The styles are no longer inline. They used to be, for a reason recorded here: "a stylesheet
         that 404s on a bad connection is a page that arrives unstyled with the numbers still on it."
         That was true when there was no Vite build. There is one now, the assets are content-hashed,
         and the market is still on budget Android over poor mobile data -- so the answer is a build
         step with hashed filenames and immutable caching, not a 290-line <style> block in a layout.
         The surface rules moved verbatim to resources/css/trader.css. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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

    <main class="read-col">
        @if (session('status'))
            <p class="note" role="status">{{ session('status') }}</p>
        @endif

        @yield('content')
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KudiMarket')</title>
    {{-- The defaults here are not decorative. They are what a WhatsApp or Facebook preview shows
         when the page is shared, and they sat advertising escrow and pointing at a screenshot of the
         exchange while the page itself had already been rewritten for remittance. The banned-word
         test only reads the response body, so nothing caught it. A share preview is the one place
         the old pitch would have escaped to, which is exactly where it must not. --}}
    <meta name="description" content="@yield('description', 'Send cedi, receive naira, at a rate you see before you commit. KudiMarket is not open yet — join the list and we will write once.')">
    <meta property="og:title" content="KudiMarket — Your market. Your rate.">
    <meta property="og:description" content="@yield('description', 'Send cedi, receive naira, at a rate you see before you commit.')">
    <meta property="og:image" content="{{ asset('img/landing/share.png') }}">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#FCF6EE">

    {{-- Same reasoning as the trader layout, resolved the same way: there is a Vite build now, the
         assets are content-hashed, and Instrument Sans is self-hosted through the plugin rather than
         fetched from a font CDN. The surface rules moved verbatim to resources/css/marketing.css. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>
<body>
    <div class="container">
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

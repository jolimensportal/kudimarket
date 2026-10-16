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

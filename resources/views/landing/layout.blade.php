@php
    $branding = \App\Support\PlatformBranding::all();
    $brandLogo = $branding['logo_url'] ?: asset('app/branding/mktransparenticon.png');
    $brandIcon = $branding['icon_url'] ?: asset('app/branding/mkicon.png');
    $brandFavicon = $branding['favicon_url'] ?: asset('app/branding/mkicon.png');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $branding['primary_color'] }}">
    <meta name="description" content="MKPOS is a point-of-sale app for local shops. Record sales, manage stock, track credit and see reports in one place.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="MKPOS — Sales, stock and credit in one place">
    <meta property="og:description" content="A simple point-of-sale app for local shops. Sell, manage stock and track what you are owed.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ $brandIcon }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" href="{{ $brandFavicon }}">
    <link rel="apple-touch-icon" href="{{ $brandIcon }}">
    <link rel="stylesheet" href="{{ asset('landing/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('landing/guide.css') }}">
    <link rel="stylesheet" href="{{ asset('landing/tutorials.css') }}">
    <style>
        :root {
            --green: {{ $branding['primary_color'] }};
            --green-2: {{ \App\Support\PlatformBranding::shade($branding['primary_color'], .15, 0) }};
            --green-3: {{ $branding['primary_color'] }};
            --ink-deep: {{ \App\Support\PlatformBranding::shade($branding['primary_color'], .45, 0) }};
            --lime: {{ \App\Support\PlatformBranding::shade($branding['primary_color'], .82, 255) }};
            --lime-soft: {{ \App\Support\PlatformBranding::shade($branding['primary_color'], .94, 255) }};
        }
    </style>
    <title>@yield('title', 'MKPOS')</title>
</head>
<body>
    <svg class="svg-library" aria-hidden="true">
        <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
        <symbol id="icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
        <symbol id="icon-receipt" viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/></symbol>
        <symbol id="icon-box" viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></symbol>
        <symbol id="icon-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="icon-truck" viewBox="0 0 24 24"><path d="M3 5h11v12H3zM14 9h4l3 3v5h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></symbol>
        <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></symbol>
        <symbol id="icon-wallet" viewBox="0 0 24 24"><path d="M4 6h15a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V6a3 3 0 0 1 3-3h13"/><path d="M16 11h5v4h-5a2 2 0 0 1 0-4Z"/></symbol>
        <symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 3 20 6v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-3Z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="icon-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></symbol>
        <symbol id="icon-monitor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
        <symbol id="icon-phone" viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></symbol>
    </svg>

    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="{{ url('/') }}" aria-label="MKPOS home">
                <img src="{{ $brandLogo }}" alt="" width="44" height="44">
                <span>MKPOS</span>
            </a>
            <nav class="desktop-nav" aria-label="Primary navigation">
                <a href="{{ route('landing') }}#how-it-works">How it works</a>
                <a href="{{ route('landing') }}#features">Features</a>
                <a href="{{ route('landing') }}#office">Office</a>
                <a href="{{ route('landing') }}#platforms">Downloads</a>
                <a href="{{ route('plans.index') }}">Pricing</a>
                <a href="{{ route('landing') }}#contact">Contact</a>
            </nav>
            <div class="nav-actions">
                <a class="text-link" href="{{ \App\Support\ClientApps::webUrl('login') }}">Sign in</a>
                <a class="button button-small" href="{{ \App\Support\ClientApps::webUrl('signup') }}">Start free trial</a>
            </div>
            <details class="mobile-nav">
                <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
                <div>
                    <a href="{{ route('landing') }}#how-it-works">How it works</a>
                    <a href="{{ route('landing') }}#features">Features</a>
                <a href="{{ route('landing') }}#office">Office</a>
                    <a href="{{ route('landing') }}#platforms">Downloads</a>
                    <a href="{{ route('plans.index') }}">Pricing</a>
                    <a href="{{ route('landing') }}#contact">Contact</a>
                    <span class="mobile-nav-label">Learn more</span>
                    <a href="{{ route('documentation.index') }}">Documentation</a>
                    <a href="{{ route('tutorials.index') }}">Tutorials</a>
                    <a href="{{ route('landing') }}#security">Security</a>
                    <a href="{{ \App\Support\ClientApps::webUrl('login') }}">Sign in</a>
                    <a class="button" href="{{ \App\Support\ClientApps::webUrl('signup') }}">Start free trial</a>
                </div>
            </details>
        </div>
    </header>

@yield('content')
    @php($contacts = \App\Support\PlatformContacts::all())
    <footer id="contact">
        <div class="container footer-main">
            <div><a class="brand footer-brand" href="{{ url('/') }}"><img src="{{ $brandLogo }}" alt="" width="42" height="42"><span>MKPOS</span></a><p>Sales, stock and credit in one app for local shops.</p></div>
            <nav aria-label="Footer navigation">
                <div><strong>Product</strong><a href="{{ route('landing') }}#how-it-works">How it works</a><a href="{{ route('landing') }}#features">Features</a>
                <a href="{{ route('landing') }}#office">Office</a><a href="{{ route('landing') }}#platforms">Downloads</a><a href="{{ route('plans.index') }}">Pricing</a></div>
                <div><strong>Resources</strong><a href="{{ route('documentation.index') }}">Documentation</a><a href="{{ route('tutorials.index') }}">Tutorials</a><a href="{{ route('landing') }}#security">Security</a></div>
                <div><strong>Access</strong><a href="{{ \App\Support\ClientApps::webUrl('signup') }}">Start free trial</a><a href="{{ \App\Support\ClientApps::webUrl('login') }}">Business sign in</a></div>
                @if($contacts['phone_numbers'] || $contacts['viber_numbers'] || $contacts['telegram_url'] || $contacts['email'] || $contacts['community_url'])
                    <div><strong>Contact & community</strong>
                        @foreach($contacts['phone_numbers'] as $phone)
                            <a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">{{ $phone }}</a>
                        @endforeach
                        @foreach($contacts['viber_numbers'] as $phone)
                            <a href="{{ \App\Support\PlatformContacts::viberUrl($phone) }}">Viber {{ $phone }}</a>
                        @endforeach
                        @if($contacts['telegram_url'])<a href="{{ $contacts['telegram_url'] }}" target="_blank" rel="noopener noreferrer">Telegram</a>@endif
                        @if($contacts['email'])<a href="mailto:{{ $contacts['email'] }}">{{ $contacts['email'] }}</a>@endif
                        @if($contacts['community_url'])<a href="{{ $contacts['community_url'] }}" target="_blank" rel="noopener noreferrer">{{ $contacts['community_label'] ?: 'Community' }}</a>@endif
                    </div>
                @endif
            </nav>
        </div>
        <div class="container footer-bottom"><span>© {{ date('Y') }} MKPOS. All rights reserved.</span><span>Made for businesses that keep moving.</span></div>
    </footer>
    <a class="ai-assistant-fab" href="https://t.me/KaungKaungKK_Bot" target="_blank" rel="noopener noreferrer" aria-label="AI assistant on Telegram (opens in a new tab)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v3M10 3h4"/><rect x="4" y="6" width="16" height="14" rx="4"/><path d="M4 11H2v4h2M20 11h2v4h-2M9 16h6"/><circle cx="9" cy="11" r=".8"/><circle cx="15" cy="11" r=".8"/></svg>
        <span><strong>AI assistant</strong><small>Chat on Telegram <span aria-hidden="true">↗</span></small></span>
    </a>
    <script>
        if (window.location.hash.startsWith('#/')) {
            window.location.replace(@json(\App\Support\ClientApps::webUrl()) + window.location.hash.slice(2));
        }
    </script>
    <script src="{{ asset('landing/navigation.js') }}" defer></script>
</body>
</html>

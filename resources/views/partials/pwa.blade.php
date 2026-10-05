{{-- PWA: manifest, ikon, warna bar (dipakai layouts/app & layouts/publik). Service worker didaftarkan di resources/js/app.js. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#4556A9">
<link rel="icon" type="image/svg+xml" href="{{ asset('icons/icon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="SIPERJAL">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="application-name" content="SIPERJAL">

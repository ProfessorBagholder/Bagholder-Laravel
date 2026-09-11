<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-theme="nocturne">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? config('app.name') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
        <style>[x-cloak]{display:none!important}</style>
        <style>
        html[data-bh-theme="midnight"] {
            --bh-mat:#06070b; --bh-bg:#0b0d12; --bh-surface:#10131a; --bh-n900:#171b24;
            --bh-ink:#e6e8ee; --bh-ink60:rgba(230,232,238,.66); --bh-ink55:rgba(230,232,238,.6);
            --bh-hair:rgba(230,232,238,.16); --bh-accent:#3fd68a; --bh-accent-300:#8fe8b8; --bh-accent-900:#123324;
            --bh-pos:#3fd68a; --bh-neg:#ff6b7a;
        }
        html[data-bh-theme="light"] {
            color-scheme:light;
            --bh-mat:#e0e2ea; --bh-bg:#eef0f5; --bh-surface:#fafafc; --bh-n900:#f1f2f6;
            --bh-ink:#1b1d27; --bh-ink60:rgba(27,29,39,.72); --bh-ink55:rgba(27,29,39,.66);
            --bh-hair:rgba(27,29,39,.12); --bh-accent:#6b5cd6; --bh-accent-300:#4a3bb8; --bh-accent-900:#e9e6fa;
            --bh-pos:#2e8a62; --bh-neg:#c0505f;
        }
        </style>

        <script>
            try {
                var t = localStorage.getItem('bh2.theme');
                if (t === 'midnight' || t === 'light' || t === 'nocturne') {
                    document.documentElement.setAttribute('data-bh-theme', t);
                }
            } catch (e) {}
        </script>
</head>
    <body class="min-h-dvh antialiased" style="background: var(--bh-mat); color: var(--bh-ink); margin: 0;">
        {{ $slot }}
        @livewireScripts
        @fluxScripts
    </body>
</html>

{{-- Panduan (Markdown): bar atas desktop + bar atas HP + cari. Isi lewat @section('isi'); menu & indeks dari data view. --}}
@php
    $aktif = $artikel ?? null;
    $kembali = $aktif ? route('panduan') : route('beranda');
    $judulHp = $aktif['peran'] ?? 'Semua panduan';
    $aplikasi = auth()->check() ? route('usulan') : route('beranda');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panduan · SIPERJAL' }}</title>
    @if (! empty($deskripsi)) <meta name="description" content="{{ $deskripsi }}"> @endif
    @if (! empty($og))
        <meta property="og:type" content="article">
        <meta property="og:site_name" content="SIPERJAL · Panduan">
        <meta property="og:title" content="{{ $og['judul'] }}">
        <meta property="og:description" content="{{ $og['deskripsi'] }}">
        <meta property="og:url" content="{{ $og['url'] }}">
        @if (! empty($og['gambar']))
            <meta property="og:image" content="{{ $og['gambar'] }}">
            <meta name="twitter:card" content="summary_large_image">
        @endif
    @endif
    @include('partials.pwa')
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh bg-bg" x-data="cariPanduan(@js($indeks))" @keydown.window="tekan($event)">
    {{-- Desktop --}}
    <header class="sticky top-0 z-[1000] hidden h-16 border-b border-line bg-bg lg:block">
        <div class="mx-auto flex h-full max-w-[1440px] items-center justify-between gap-6 px-8">
            <div class="flex items-center gap-4">
                <x-logo />
                <span class="h-5 w-px bg-line"></span>
                <a href="{{ route('panduan') }}" class="text-[15px] font-semibold text-fg-muted hover:text-fg">Panduan</a>
            </div>

            <div class="relative w-[440px]" @click.outside="q = ''">
                <label class="flex h-10 items-center gap-2.5 rounded-md bg-surface-muted px-3 outline outline-1 -outline-offset-1 outline-line focus-within:bg-bg focus-within:outline-2 focus-within:outline-accent">
                    @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                    <input x-ref="cari" x-model="q" @input="sorot = 0" @keydown.down.prevent="geser(1)" @keydown.up.prevent="geser(-1)" @keydown.enter.prevent="buka()" @keydown.escape="q = ''; $refs.cari.blur()" type="search" autocomplete="off" placeholder="Cari panduan, mis. revisi, lacak usulan" class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-fg-muted [&::-webkit-search-cancel-button]:hidden">
                    <kbd class="rounded-sm bg-bg px-1.5 py-0.5 font-caption text-xs text-fg-muted outline outline-1 -outline-offset-1 outline-line" x-show="! q">/</kbd>
                </label>
                <div x-show="q.trim().length >= 2" x-cloak class="absolute inset-x-0 top-12 z-10 max-h-[420px] overflow-auto rounded-md bg-bg p-1.5 shadow-lg outline outline-1 -outline-offset-1 outline-line">
                    @include('panduan._hasil')
                </div>
            </div>

            <div class="flex items-center gap-5">
                <x-logo-instansi class="hidden xl:flex" />
                <a href="{{ $aplikasi }}" class="flex items-center gap-2 rounded-md px-3.5 py-[9px] text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">Buka aplikasi @svg('lucide-arrow-up-right', 'size-4')</a>
            </div>
        </div>
    </header>

    {{-- HP --}}
    <header class="sticky top-0 z-[1000] flex h-14 items-center gap-3 border-b border-line bg-bg px-4 lg:hidden">
        <a href="{{ $kembali }}" aria-label="Kembali" class="grid size-10 place-items-center rounded-md outline outline-1 -outline-offset-1 outline-line">@svg('lucide-arrow-left', 'size-5')</a>
        <div class="flex min-w-0 flex-1 flex-col">
            <span class="font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Panduan</span>
            <span class="truncate text-[15px] font-semibold">{{ $judulHp }}</span>
        </div>
        <button type="button" @click="sheet = true; $nextTick(() => $refs.cariHp.focus())" aria-label="Cari panduan" class="grid size-10 place-items-center rounded-md outline outline-1 -outline-offset-1 outline-line">@svg('lucide-search', 'size-5')</button>
    </header>

    <div x-show="sheet" x-cloak x-transition.opacity class="fixed inset-0 z-[2000] flex flex-col bg-bg lg:hidden" @keydown.escape.window="sheet = false">
        <div class="flex h-14 shrink-0 items-center gap-3 border-b border-line px-4">
            <label class="flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-md bg-surface-muted px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                <input x-ref="cariHp" x-model="q" @input="sorot = 0" @keydown.enter.prevent="buka()" type="search" autocomplete="off" placeholder="Cari panduan" class="min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-fg-muted [&::-webkit-search-cancel-button]:hidden">
            </label>
            <button type="button" @click="sheet = false; q = ''" class="px-1 text-[15px] font-semibold text-accent">Batal</button>
        </div>
        <div class="flex-1 overflow-auto p-3">
            <p x-show="q.trim().length < 2" class="px-2 py-6 text-center text-sm text-fg-muted">Ketik kata kunci, mis. revisi atau lacak usulan.</p>
            <div x-show="q.trim().length >= 2">@include('panduan._hasil')</div>
        </div>
    </div>

    @yield('isi')

    @livewireScripts
</body>
</html>

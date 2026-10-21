{{-- nav: beranda | lacak | masuk · footer: lengkap | ringkas | tidak --}}
@php($nav ??= 'beranda')
@php($footer ??= 'lengkap')
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SIPERJAL' }}</title>
    @include('partials.pwa')
    @fonts
    @include('partials.peta-config')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh">
    {{-- Di HP, halaman lacak & masuk memakai header sendiri (tombol kembali) --}}
    <header @class(['sticky top-0 z-[1000] border-b border-line bg-bg', 'hidden lg:block' => in_array($nav, ['lacak', 'masuk'])])>
        <div class="mx-auto flex h-14 max-w-[1440px] items-center gap-3 px-4 sm:h-[72px] sm:px-8 lg:gap-10 lg:px-20">
            <x-logo />

            @if ($nav === 'lacak')
                <nav class="hidden flex-1 items-center gap-1 font-caption text-sm md:flex">
                    <a href="{{ url('/') }}" wire:navigate class="rounded-sm px-3.5 py-2 font-medium text-fg-muted hover:bg-surface-muted">Beranda</a>
                    <span class="rounded-sm bg-accent-soft px-3.5 py-2 font-semibold text-accent">Lacak Usulan</span>
                    <a href="{{ url('/#cara') }}" class="rounded-sm px-3.5 py-2 font-medium text-fg-muted hover:bg-surface-muted">Cara Kerja</a>
                </nav>
                <div class="ml-auto flex items-center gap-3">
                    <x-logo-instansi class="mr-2 hidden md:flex" />
                    @guest
                        <a href="{{ route('masuk') }}" wire:navigate class="hidden rounded-sm px-4 py-2.5 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted sm:block">Masuk</a>
                    @endguest
                    <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-2 whitespace-nowrap rounded-sm bg-accent px-3 py-2.5 sm:px-4 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Ajukan<span class="hidden sm:inline"> Usulan</span> @svg('lucide-plus', 'size-4')</a>
                </div>
            @elseif ($nav === 'masuk')
                <div class="ml-auto flex items-center gap-2 font-caption text-sm">
                    <x-logo-instansi class="mr-2 hidden md:flex" />
                    <a href="{{ url('/') }}" wire:navigate class="hidden items-center gap-2 px-3.5 py-2 font-medium text-fg-muted hover:text-fg sm:flex">@svg('lucide-arrow-left', 'size-4') Kembali ke Beranda</a>
                    <a href="{{ url('/#lacak') }}" class="rounded-sm px-3.5 py-2 font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">Lacak Usulan</a>
                </div>
            @else
                <div class="ml-auto flex items-center gap-4 sm:gap-6">
                    <x-logo-instansi class="hidden md:flex" />
                    <a href="{{ url('/#lacak') }}" class="hidden items-center gap-1.5 font-caption text-[15px] font-medium sm:flex">@svg('lucide-search', 'size-4') Lacak</a>
                    @auth
                        <a href="{{ route('usulan') }}" wire:navigate class="flex h-11 items-center gap-1.5 whitespace-nowrap rounded-sm px-3 text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-fg hover:bg-surface-muted sm:h-auto sm:px-4 sm:py-2.5">@svg('lucide-layout-grid', 'size-4') <span class="hidden sm:inline">Buka </span>Aplikasi</a>
                    @else
                        <a href="{{ route('masuk') }}" wire:navigate class="flex h-11 items-center gap-1.5 whitespace-nowrap rounded-sm px-3.5 text-[15px] font-semibold text-white -outline-offset-1 outline-fg bg-accent hover:brightness-110 sm:h-auto sm:px-4 sm:py-2.5">@svg('lucide-log-in', 'hidden size-4 sm:block') Masuk</a>
                    @endauth
                </div>
            @endif
        </div>
    </header>

    {{ $slot }}

    @if ($footer === 'lengkap')
        <footer class="bg-fg">
            <div class="mx-auto max-w-[1440px] px-4 pb-8 pt-16 sm:px-8 lg:px-[100px]">
                <div class="flex flex-col gap-10 lg:flex-row lg:gap-10 xl:gap-16">
                    <div class="flex flex-col gap-4 lg:w-[280px] lg:shrink-0 xl:w-[340px]">
                        <x-logo terang />
                        <p class="max-w-[360px] text-[15px] leading-[23px] text-[#C9CBD1]">Layanan usulan perlengkapan jalan untuk masyarakat dan instansi. Dikelola Dinas Perhubungan bersama Bappeda.</p>
                        <x-logo-instansi terang besar />
                    </div>
                    @foreach ([
                        'Layanan' => [['Ajukan Usulan', route('usulan.baru')], ['Lacak Usulan', url('/#lacak')], ['Peta Terpasang', url('/#peta')]],
                        'Petugas' => [['Masuk Dishub', route('masuk')], ['Masuk Bappeda', route('masuk')]],
                        'Kontak' => [['Dinas Perhubungan', null], [config('usuljalan.kontak_telepon'), null], ['WA '.config('usuljalan.kontak_wa'), 'https://wa.me/62'.ltrim(preg_replace('/\D/', '', config('usuljalan.kontak_wa')), '0')]],
                    ] as $judul => $tautan)
                        <div class="flex w-[200px] flex-col gap-3">
                            <div class="font-caption text-xs font-bold uppercase tracking-[1px] text-[#9CA3AF]">{{ $judul }}</div>
                            @foreach ($tautan as [$label, $href])
                                @if ($href) <a href="{{ $href }}" class="text-[15px] text-fg-inverse hover:underline">{{ $label }}</a>
                                @else <span class="text-[15px] text-fg-inverse">{{ $label }}</span> @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="mt-12 border-t border-fg-inverse/[.12] pt-6 font-caption text-[13px] text-[#9CA3AF]">© {{ date('Y') }} Dinas Perhubungan. Hak cipta dilindungi.</div>
            </div>
        </footer>
    @elseif ($footer === 'ringkas')
        <footer class="bg-fg">
            <div class="mx-auto flex max-w-[1440px] flex-col gap-3 px-4 py-7 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-20">
                <div class="flex flex-wrap items-center gap-4">
                    <x-logo terang kecil />
                    <x-logo-instansi terang />
                </div>
                <span class="font-caption text-[13px] text-fg-inverse/70">Dikelola Dinas Perhubungan · © {{ date('Y') }}</span>
            </div>
        </footer>
    @endif

    <x-toast />
    <x-lihat-foto />
    @livewireScripts
</body>
</html>

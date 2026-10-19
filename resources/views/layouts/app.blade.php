@php
    $user = auth()->user();
    $menu = collect([
        ['Usulan', 'usulan', 'inbox', true],
        ['Laporan', 'laporan', 'chart-column', ! $user->isPengusul()],
        ['Pengaturan', 'pengaturan', 'settings', $user->hasRole(\App\Enums\Role::Admin)],
    ])->filter(fn ($m) => $m[3]);
    // Usulan & Pemeliharaan satu menu; pindahnya lewat Category Switch di kepala daftar (<x-kategori>)
    $aktifMenu = fn ($route) => request()->routeIs($route.'*') || ($route === 'usulan' && request()->routeIs('pemeliharaan'));
@endphp
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
    @stack('head')
</head>
@php($layarForm = request()->routeIs('usulan.baru', 'usulan.ubah', 'lapor'))
@php($tanpaBarAtasHp = $layarForm || request()->routeIs('usulan', 'pemeliharaan', 'notifikasi', 'profil'))
<body @class(['min-h-dvh pb-20 lg:pb-0', 'bg-bg' => $layarForm, 'bg-surface-muted' => ! $layarForm])>
    <header @class(['sticky top-0 z-[1000] border-b border-line bg-bg', 'hidden lg:block' => $tanpaBarAtasHp])>
        <div class="flex h-16 items-center gap-3 px-4 lg:gap-6 lg:px-6">
            <x-logo />
            <nav class="hidden items-center gap-1 lg:flex">
                @foreach ($menu as [$label, $route, $ikon])
                    <a href="{{ route($route) }}" wire:navigate @class([
                        'rounded-sm px-3 py-2 text-[15px] font-medium',
                        'bg-accent-soft text-accent' => $aktifMenu($route),
                        'text-fg-muted hover:bg-surface-muted' => ! $aktifMenu($route),
                    ])>{{ $label }}</a>
                @endforeach

            </nav>

            <div class="ml-auto flex items-center gap-1.5 sm:gap-3">
                <x-logo-instansi class="mr-3 hidden xl:flex" />
                @if ($user->isPengusul())
                    <div class="hidden lg:block"><x-tombol :href="route('lapor')" ikon="plus">Ajukan</x-tombol></div>
                @endif
                <livewire:lonceng />
                <x-bantuan />
                <div x-data="{ buka: false, nama: @js($user->name), inisial: @js($user->inisial()), foto: @js($user->fotoUrl()) }"
                     @profil-berubah.window="nama = $event.detail.nama; inisial = $event.detail.inisial; foto = $event.detail.foto"
                     @tutup-profil.window="buka = false" @keydown.escape.window="buka = false">
                    <button @click="window.innerWidth < 1024 ? Livewire.navigate('{{ route('profil') }}') : buka = !buka"
                            :class="buka ? 'bg-accent-soft outline outline-1 -outline-offset-1 outline-accent/40' : 'hover:bg-surface-muted'"
                            class="flex items-center gap-2.5 rounded-sm p-1 sm:pr-2.5" aria-label="Profil saya">
                        <span class="grid size-9 place-items-center overflow-hidden rounded-full bg-secondary font-caption text-sm font-semibold text-on-secondary">
                            <template x-if="foto"><img :src="foto" class="size-full object-cover" alt=""></template>
                            <span x-show="! foto" x-text="inisial"></span>
                        </span>
                        <span class="hidden text-left leading-tight sm:block">
                            <span class="block text-sm font-semibold" x-text="nama"></span>
                            <span class="block text-xs text-fg-muted">{{ $user->keteranganPeran() }}</span>
                        </span>
                    </button>
                    <div x-show="buka" x-cloak x-transition.opacity.duration.150ms @click="buka = false" class="fixed inset-x-0 bottom-0 top-16 z-[1090] bg-fg/40"></div>
                    <div x-show="buka" x-cloak x-transition.opacity.duration.150ms
                         class="fixed right-6 top-[76px] z-[1100] max-h-[calc(100dvh-92px)] w-[456px] overflow-y-auto rounded-md bg-bg shadow-xl outline outline-1 -outline-offset-1 outline-line">
                        <livewire:profil />
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- Koneksi putus: isian diantre di HP (resources/js/app.js) lalu terkirim otomatis saat online --}}
    <div x-data="{ offline: ! navigator.onLine, antre: 0 }"
         @offline.window="offline = true" @online.window="offline = false"
         @antre-offline.window="antre = $event.detail.jumlah"
         @antre-terkirim.window="if ($event.detail.jumlah) $dispatch('toast', { pesan: 'Tersambung lagi · ' + $event.detail.jumlah + ' perubahan terkirim' }); antre = 0"
         x-init="window.addEventListener('beforeunload', (e) => { if (antre) e.preventDefault() })"
         x-show="offline" x-cloak x-transition.opacity
         class="sticky top-0 z-[1050] flex items-start gap-2 bg-warning-bg px-4 py-2.5 text-[13px] leading-[18px] text-warning lg:top-16 lg:justify-center">
        @svg('lucide-wifi-off', 'mt-px size-4 shrink-0')
        <span>Tidak ada koneksi. Isian tetap tersimpan di HP dan dikirim otomatis saat online.</span>
    </div>

    <main>{{ $slot }}</main>

    {{-- Bar bawah HP --}}
    @php($belum = $user->unreadNotifications()->count())
    <nav @class(['fixed inset-x-0 bottom-0 z-[1000] h-[72px] items-center border-t border-line bg-bg lg:hidden', 'hidden' => $layarForm, 'flex' => ! $layarForm, 'justify-between px-6 pb-3 pt-2' => $user->isPengusul(), 'px-2 pb-3 pt-1.5' => ! $user->isPengusul()])>
        @if ($user->isPengusul())
            <a href="{{ route('usulan') }}" wire:navigate @class(['flex w-[72px] flex-col items-center gap-1 py-1 font-caption text-xs', 'font-semibold text-accent' => request()->routeIs('usulan*', 'pemeliharaan'), 'font-medium text-fg-muted' => ! request()->routeIs('usulan*', 'pemeliharaan')])>@svg('lucide-file-text', 'size-[22px]') Usulan Saya</a>
            <a href="{{ route('lapor') }}" wire:navigate class="grid size-[52px] place-items-center rounded-full bg-accent text-fg-inverse shadow-lg" aria-label="Ajukan usulan atau lapor kerusakan">@svg('lucide-plus', 'size-6')</a>
            <a href="{{ route('notifikasi') }}" wire:navigate @class(['relative flex w-[72px] flex-col items-center gap-1 py-1 font-caption text-xs', 'font-semibold text-accent' => request()->routeIs('notifikasi'), 'font-medium text-fg-muted' => ! request()->routeIs('notifikasi')])>
                @svg('lucide-bell', 'size-[22px]') Notifikasi
                @if ($belum) <span class="absolute left-[40px] top-0 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold text-fg-inverse outline outline-[1.5px] outline-bg">{{ $belum }}</span> @endif
            </a>
        @else
            @foreach ($menu->push(['Notifikasi', 'notifikasi', 'bell', true]) as [$label, $route, $ikon])
                @php($aktif = $aktifMenu($route))
                <a href="{{ route($route) }}" wire:navigate class="flex h-[52px] flex-1 flex-col items-center justify-center gap-1">
                    <span @class(['relative grid h-7 w-[52px] place-items-center rounded-full', 'bg-accent-soft text-accent' => $aktif, 'text-fg-muted' => ! $aktif])>
                        @svg('lucide-'.($ikon === 'inbox' ? 'file-text' : $ikon), 'size-[22px]')
                        @if ($route === 'notifikasi' && $belum) <span class="absolute left-[30px] top-0 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold text-fg-inverse outline outline-[1.5px] outline-bg">{{ $belum }}</span> @endif
                    </span>
                    <span @class(['font-caption text-xs', 'font-semibold text-accent' => $aktif, 'font-medium text-fg-muted' => ! $aktif])>{{ $label }}</span>
                </a>
            @endforeach
        @endif
    </nav>

    <x-toast />
    @if (session('pesan'))
        <div x-data x-init="$nextTick(() => $dispatch('toast', { pesan: @js(session('pesan')), gagal: true }))"></div>
    @endif
    <x-lihat-foto />
    @livewireScripts
    @stack('scripts')
</body>
</html>

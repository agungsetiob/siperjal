{{-- Category Switch (pen.dev): pindah Usulan ↔ Pemeliharaan di kepala daftar. Pengganti menu navbar.
     aktif: usulan | pemeliharaan. Bappeda tidak punya pemeliharaan → tidak tampil. --}}
@props(['aktif' => 'usulan'])
@use('App\Enums\Role')
@use('App\Enums\StatusLaporan')
@use('App\Enums\StatusUsulan')
@php
    $user = auth()->user();
    $pengusul = $user->isPengusul();
    $tampil = $user->hasRole(Role::Pengusul, Role::Dishub, Role::Admin);
    // angka: pengusul = jumlah miliknya; Dishub/admin = yang menunggu ditangani
    $pilihan = $tampil ? [
        'usulan' => ['Usulan', 'inbox', route('usulan'), $pengusul
            ? \App\Models\Usulan::terlihatOleh($user)->where('status', '!=', StatusUsulan::Draft)->count()
            : \App\Models\Usulan::where('status', StatusUsulan::Diperiksa)->count()],
        'pemeliharaan' => [$pengusul ? 'Laporan kerusakan' : 'Pemeliharaan', 'wrench', route('pemeliharaan'), $pengusul
            ? \App\Models\LaporanKerusakan::where('user_id', $user->id)->count()
            : \App\Models\LaporanKerusakan::where('status', StatusLaporan::Baru)->count()],
    ] : [];
@endphp
@if ($tampil)
    <nav {{ $attributes->class('flex gap-0.5 rounded-sm bg-surface-muted p-[3px]') }} aria-label="Kategori">
        @foreach ($pilihan as $k => [$label, $ikon, $url, $n])
            @php $on = $aktif === $k; @endphp
            <a href="{{ $url }}" wire:navigate @if ($on) aria-current="page" @endif @class([
                'flex h-9 min-w-0 flex-1 items-center justify-center gap-2 rounded-sm px-2 text-sm',
                'bg-bg font-semibold text-fg shadow-sm' => $on,
                'font-medium text-fg-muted hover:text-fg' => ! $on,
            ])>
                <span @class(['shrink-0', 'text-accent' => $on])>@svg('lucide-'.$ikon, 'size-4')</span>
                <span class="truncate">{{ $label }}</span>
                @if ($n) <span @class(['shrink-0 rounded-full px-[7px] py-px font-caption text-[11px] font-semibold', 'bg-accent text-fg-inverse' => $on, 'bg-line text-fg-muted' => ! $on])>{{ $n }}</span> @endif
            </a>
        @endforeach
    </nav>
@endif

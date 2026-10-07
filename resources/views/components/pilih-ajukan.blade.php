{{-- Pilihan "Mau ajukan apa?": Usulan baru / Lapor kerusakan. Tampil di halaman Lapor dan langkah 1 form Usulan, agar pengguna tetap bisa berpindah.
     aktif: usulan|lapor|null. di-lapor: kartu Lapor memakai $set('pilihan') tanpa pindah halaman. --}}
@props(['aktif' => null, 'diLapor' => false])

@php
    $kartu = [
        'usulan' => ['Usulan baru', 'Perlengkapan belum ada di lokasi', 'map-pin-plus', 'bg-accent-soft text-accent', route('usulan.baru')],
        'lapor' => ['Lapor kerusakan', 'Perlengkapan yang ada rusak, hilang, mati, pudar, atau roboh', 'wrench', 'bg-warning-bg text-warning', route('lapor', ['jenis' => 'lapor'])],
    ];
@endphp

<div class="grid grid-cols-2 gap-2.5">
    @foreach ($kartu as $kunci => [$judul, $teks, $ikon, $warna, $href])
        @php
            $on = $aktif === $kunci;
            $tag = $on ? 'div' : ($kunci === 'lapor' && $diLapor ? 'button' : 'a');
        @endphp
        <{{ $tag }}
            @if ($tag === 'a') href="{{ $href }}" wire:navigate @endif
            @if ($tag === 'button') type="button" wire:click="$set('pilihan', 'lapor')" @endif
            @if ($on) aria-current="true" @endif
            @class([
                'flex flex-col gap-2 rounded-md p-3.5 text-left',
                'bg-accent-soft outline outline-2 -outline-offset-2 outline-accent' => $on,
                'bg-bg outline outline-1 -outline-offset-1 outline-line hover:outline-accent/40' => ! $on,
            ])>
            <span class="flex items-start justify-between">
                <span class="grid size-10 place-items-center rounded-sm {{ $on && $kunci === 'usulan' ? 'bg-bg text-accent' : $warna }}">@svg('lucide-'.$ikon, 'size-5')</span>
                @if ($on) <span class="grid size-6 place-items-center rounded-full bg-accent text-fg-inverse">@svg('lucide-check', 'size-4')</span> @endif
            </span>
            <span class="text-base font-semibold">{{ $judul }}</span>
            <span class="text-sm leading-5 text-fg-muted">{{ $teks }}</span>
        </{{ $tag }}>
    @endforeach
</div>

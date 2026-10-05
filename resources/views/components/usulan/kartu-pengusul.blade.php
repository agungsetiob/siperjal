{{-- Kartu usulan untuk pengusul (desain A1 · Usulan Saya HP). --}}
@props(['u', 'aktif' => false])
@php
    $s = $u->status;
    $hari = $u->diajukan_at ? (int) $u->diajukan_at->diffInDays() : 0;
    $disetujui = $u->items->whereIn('status', [\App\Enums\StatusItem::Disetujui, \App\Enums\StatusItem::Terpasang])->count();
    [$ikon, $teks] = match ($s) {
        \App\Enums\StatusUsulan::Draft => ['pencil', 'Belum dikirim · lanjutkan mengisi'],
        \App\Enums\StatusUsulan::Diperiksa => ['search', 'Sedang diperiksa Dishub · '.($hari ? "{$hari} hari" : 'hari ini')],
        \App\Enums\StatusUsulan::Revisi => ['triangle-alert', 'Perlu diperbaiki'],
        \App\Enums\StatusUsulan::MenungguBappeda => ['hourglass', 'Menunggu keputusan Bappeda'],
        \App\Enums\StatusUsulan::Disetujui => ['circle-check', "{$disetujui} item disetujui · menunggu pemasangan"],
        \App\Enums\StatusUsulan::Terpasang => ['map-pin-check', 'Sudah terpasang · terima kasih!'],
        \App\Enums\StatusUsulan::Ditunda => ['hourglass', 'Ditunda ke tahun berikutnya'],
        \App\Enums\StatusUsulan::Ditolak => ['circle-x', 'Tidak disetujui'],
        \App\Enums\StatusUsulan::Dibatalkan => ['x', 'Dibatalkan'],
    };
    $tahap = $s->tahap();
    $foto = $u->foto->first();
@endphp
<button type="button" wire:click="pilihUsulan({{ $u->id }})"
        {{ $attributes->class([
            'flex w-full shrink-0 flex-col gap-3 rounded-md p-3 text-left shadow-sm transition',
            'bg-[#F7F8FC] outline outline-[1.5px] -outline-offset-1 outline-accent' => $aktif,
            'bg-bg outline outline-1 -outline-offset-1 outline-line hover:outline-accent/40' => ! $aktif,
        ]) }}>
    <div class="flex w-full gap-3">
        @if ($foto)
            <img src="{{ $foto->url() }}" class="size-[76px] shrink-0 rounded-md object-cover" alt="">
        @else
            <span class="grid size-[76px] shrink-0 place-items-center rounded-md bg-surface-muted text-fg-muted">@svg('lucide-image', 'size-6')</span>
        @endif
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex items-center justify-between gap-2 font-caption text-[13px] text-fg-muted">
                <span class="flex items-center gap-1.5 font-semibold">
                    {{ $u->nomor ?? 'Draft' }}
                    @if ($u->diajukan_at && $u->diajukan_at->gt(now()->subDays(2)) && $s === \App\Enums\StatusUsulan::Diperiksa)
                        <span class="rounded-sm bg-accent px-1.5 py-0.5 text-[11px] text-fg-inverse">Baru</span>
                    @endif
                </span>
                <span>{{ ($u->diajukan_at ?? $u->created_at)->translatedFormat('j M') }}</span>
            </div>
            <span class="line-clamp-2 text-base font-semibold leading-[21px]">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
            <div class="flex flex-wrap gap-1.5 pt-0.5">
                @foreach ($u->items as $i)
                    <span class="flex items-center gap-1 rounded-sm bg-surface-muted px-1.5 py-[3px] font-caption text-[13px] font-semibold" title="{{ $i->jenis->nama }}">
                        <span class="text-accent">@svg('lucide-'.$i->jenis->ikon, 'size-4')</span>×{{ $i->jumlah }}
                    </span>
                @endforeach
            </div>
        </div>
    </div>
    <div class="flex w-full gap-1">
        @for ($n = 1; $n <= 4; $n++)
            <span @class([
                'h-1 flex-1 rounded-[2px]',
                'bg-success' => $s === \App\Enums\StatusUsulan::Terpasang,
                'bg-danger' => $s === \App\Enums\StatusUsulan::Ditolak && $n === $tahap,
                'bg-accent' => $s !== \App\Enums\StatusUsulan::Terpasang && ! ($s === \App\Enums\StatusUsulan::Ditolak && $n === $tahap) && $n <= max(1, $tahap - ($s === \App\Enums\StatusUsulan::Draft ? 1 : 0)) && $s !== \App\Enums\StatusUsulan::Draft,
                'bg-line' => $s !== \App\Enums\StatusUsulan::Terpasang && ($n > $tahap || $s === \App\Enums\StatusUsulan::Draft),
            ])></span>
        @endfor
    </div>
    <div class="flex w-full items-center gap-2">
        <span @class(['shrink-0', 'text-success' => in_array($s, [\App\Enums\StatusUsulan::Disetujui, \App\Enums\StatusUsulan::Terpasang]), 'text-danger' => $s === \App\Enums\StatusUsulan::Ditolak, 'text-warning' => in_array($s, [\App\Enums\StatusUsulan::Revisi, \App\Enums\StatusUsulan::Ditunda]), 'text-accent' => ! in_array($s, [\App\Enums\StatusUsulan::Disetujui, \App\Enums\StatusUsulan::Terpasang, \App\Enums\StatusUsulan::Ditolak, \App\Enums\StatusUsulan::Revisi, \App\Enums\StatusUsulan::Ditunda])])>@svg('lucide-'.$ikon, 'size-[18px]')</span>
        <span class="flex-1 text-base font-medium">{{ $teks }}</span>
        <span class="text-fg-muted">@svg('lucide-chevron-right', 'size-5')</span>
    </div>
</button>

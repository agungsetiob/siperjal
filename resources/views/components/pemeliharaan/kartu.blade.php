{{-- Kartu laporan kerusakan untuk pelapor (desain PM4 ① · Usulan Saya · Laporan kerusakan). --}}
@props(['l', 'aktif' => false])
@php
    $s = $l->status;
    $foto = $l->fotoJenis('pelapor')->first();
    [$ikon, $warna] = match ($s) {
        \App\Enums\StatusLaporan::Baru => ['inbox', 'text-accent'],
        \App\Enums\StatusLaporan::Ditugaskan => ['user-round-search', 'text-accent'],
        \App\Enums\StatusLaporan::MenungguPerbaikan => ['wrench', 'text-warning'],
        \App\Enums\StatusLaporan::Selesai => ['badge-check', 'text-success'],
        \App\Enums\StatusLaporan::Ditolak => ['circle-x', 'text-danger'],
        \App\Enums\StatusLaporan::Dibatalkan => ['x', 'text-fg-muted'],
    };
@endphp
<button type="button" wire:click="pilihLaporan({{ $l->id }})"
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
                <span class="font-semibold">{{ $l->nomor }}</span>
                <span>{{ $l->created_at->translatedFormat('j M') }}</span>
            </div>
            <span class="line-clamp-2 text-base font-semibold leading-[21px]">{{ $l->lokasi() }}</span>
            <div class="flex flex-wrap gap-1.5 pt-0.5">
                <span class="flex items-center gap-1 rounded-sm bg-surface-muted px-1.5 py-[3px] font-caption text-[13px] font-semibold">
                    <span class="text-accent">@svg('lucide-'.$l->ikon(), 'size-4')</span>{{ $l->jenis?->namaPendek() ?? 'Perlengkapan' }}
                </span>
                <span class="flex items-center gap-1 rounded-sm bg-warning-bg px-1.5 py-[3px] font-caption text-[13px] font-semibold text-warning">{{ ucfirst(\App\Models\LaporanKerusakan::KERUSAKAN[$l->kerusakan][1] ?? $l->kerusakan) }}</span>
            </div>
        </div>
    </div>
    <x-pemeliharaan.stepper :laporan="$l" gaya="mini" />
    <div class="flex w-full items-center gap-2">
        <span class="shrink-0 {{ $warna }}">@svg('lucide-'.$ikon, 'size-[18px]')</span>
        <span class="flex-1 text-base font-medium">{{ $l->kabarSingkat() }}</span>
        <span class="text-fg-muted">@svg('lucide-chevron-right', 'size-5')</span>
    </div>
</button>

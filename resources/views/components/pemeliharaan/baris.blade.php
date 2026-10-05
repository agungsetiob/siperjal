{{-- Satu baris di daftar laporan kerusakan (desain PM1, koordinator Dishub). --}}
@props(['l', 'aktif' => false])
@php
    $s = $l->status;
    $menunggu = $s === \App\Enums\StatusLaporan::MenungguPerbaikan;
    $hari = (int) ($menunggu ? $l->dicek_at : $l->created_at)->diffInDays();
    $umur = $menunggu ? ($hari ? "menunggu {$hari} hari" : 'menunggu sejak hari ini') : $l->created_at->diffForHumans();
@endphp
<button type="button" wire:click="pilihLaporan({{ $l->id }})"
        x-on:mouseenter="$dispatch('sorot-titik', { id: {{ $l->id }} })"
        {{ $attributes->class([
            'flex w-full flex-col gap-1.5 border-b border-line py-3.5 pr-6 text-left transition',
            'border-l-[3px] border-l-accent bg-accent-soft pl-[21px]' => $aktif,
            'pl-6 hover:bg-surface-muted' => ! $aktif,
        ]) }}>
    <div class="flex w-full items-center justify-between gap-2 font-caption text-xs">
        <span @class(['font-semibold tracking-[0.2px]', 'text-accent' => $aktif, 'text-fg-muted' => ! $aktif])>{{ $l->nomor }}</span>
        <span @class(['flex items-center gap-1', 'font-semibold text-warning' => $menunggu && $hari > 7, 'text-fg-muted' => ! ($menunggu && $hari > 7)])>@svg('lucide-hourglass', 'size-3') {{ $umur }}</span>
    </div>
    <span class="flex w-full items-center gap-2">
        <span class="text-accent">@svg('lucide-'.$l->item->jenis->ikon, 'size-4')</span>
        <span class="truncate text-[15px] font-semibold">{{ $l->judul() }}</span>
    </span>
    <div class="flex w-full items-center gap-2 text-[13px] text-fg-muted">
        <span class="truncate">{{ $l->lokasi() }}</span>
        <span class="size-[3px] shrink-0 rounded-full bg-fg-muted"></span>
        <span class="truncate">{{ $l->namaPelapor() }}</span>
        <span class="flex-1"></span>
        @if ($l->petugas && $s !== \App\Enums\StatusLaporan::Baru)
            <span @class(['flex shrink-0 items-center gap-1 rounded-sm px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg', 'bg-bg' => $aktif, 'bg-surface-muted' => ! $aktif])>@svg('lucide-hard-hat', 'size-3') {{ str($l->petugas->name)->words(1, '') }}</span>
        @else
            <span @class(['flex shrink-0 items-center gap-1 rounded-sm px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg', 'bg-bg' => $aktif, 'bg-surface-muted' => ! $aktif])>@svg('lucide-image', 'size-3') {{ $l->fotoJenis('pelapor')->count() }} foto</span>
        @endif
        @if (! $s->aktif()) <x-status :status="$s" class="shrink-0" /> @endif
    </div>
</button>

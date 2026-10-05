{{-- Satu baris di daftar usulan (desain A1 Dishub). --}}
@props(['u', 'aktif' => false, 'pengusul' => false])
@php
    $hari = $u->diajukan_at ? (int) $u->diajukan_at->diffInDays() : null;
    $telat = $u->status === \App\Enums\StatusUsulan::Diperiksa && $hari > config('usuljalan.batas_hari_periksa');
@endphp
<button type="button" wire:click="pilihUsulan({{ $u->id }})" data-id="{{ $u->id }}"
        x-on:mouseenter="$dispatch('sorot-titik', { id: {{ $u->id }} })"
        {{ $attributes->class([
            'flex w-full flex-col gap-1.5 border-b border-line py-3.5 pr-6 text-left transition',
            'border-l-[3px] border-l-accent bg-accent-soft pl-[21px]' => $aktif,
            'pl-6 hover:bg-surface-muted' => ! $aktif,
        ]) }}>
    <div class="flex w-full items-center justify-between gap-2 font-caption text-xs">
        <span @class(['font-semibold tracking-[0.2px]', 'text-accent' => $aktif, 'text-fg-muted' => ! $aktif])>{{ $u->nomor ?? 'Draft' }}</span>
        @if ($hari !== null)
            <span @class(['flex items-center gap-1', 'font-semibold text-warning' => $telat, 'text-fg-muted' => ! $telat])>@svg('lucide-hourglass', 'size-3') {{ $hari ? "masuk {$hari} hari lalu" : 'masuk hari ini' }}</span>
        @endif
    </div>
    <span class="w-full truncate text-[15px] font-semibold">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
    <div class="flex w-full items-center gap-2 text-[13px] text-fg-muted">
        <span class="truncate">{{ collect([$u->desa ? 'Ds. '.$u->desa->nama : null, $u->kecamatan ? 'Kec. '.$u->kecamatan->nama : null])->filter()->implode(' · ') ?: '—' }}</span>
        @unless ($pengusul)
            <span class="size-[3px] shrink-0 rounded-full bg-fg-muted"></span>
            <span class="truncate">{{ $u->namaPengusul() }}</span>
        @endunless
        <span class="flex-1"></span>
        @if ($pengusul || $u->status !== \App\Enums\StatusUsulan::Diperiksa)
            <x-status :status="$u->status" class="shrink-0" />
        @endif
        <span @class(['flex shrink-0 items-center gap-1 rounded-sm px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg', 'bg-bg' => $aktif, 'bg-surface-muted' => ! $aktif])>@svg('lucide-package', 'size-3') {{ $u->items->count() }} item</span>
    </div>
    @if ($u->revisi_ke || $u->status === \App\Enums\StatusUsulan::Revisi)
        <x-badge tone="perhatian" :dot="false">{{ $u->status === \App\Enums\StatusUsulan::Revisi ? 'Perlu revisi' : 'Revisi ke-'.($u->revisi_ke + 1) }}</x-badge>
    @endif
</button>

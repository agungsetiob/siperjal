{{-- Popover pin di peta penuh Pemeliharaan (sepadan partials/popup-usulan). --}}
@php
    $foto = $l->fotoJenis('pelapor')->first();
    $telat = $l->status->aktif() && $umur > config('usuljalan.batas_hari_periksa');
@endphp
<div class="flex w-[300px] flex-col font-sans">
    @if ($foto) <img src="{{ $foto->url() }}" class="h-[120px] w-full object-cover" alt=""> @endif
    <div class="flex flex-col gap-2 p-4">
        <div class="flex items-center justify-between font-caption text-xs">
            <span class="font-semibold text-accent">{{ $l->nomor }}</span>
            <span @class(['flex items-center gap-1', 'font-semibold text-warning' => $telat, 'text-fg-muted' => ! $telat])>{{ $l->status->label() }} · {{ $umur ? "{$umur} hari" : 'hari ini' }}</span>
        </div>
        <span class="text-base font-semibold text-fg">{{ $l->judul() }}</span>
        <span class="text-[13px] text-fg-muted">{{ $l->lokasi() }} · {{ $l->namaPelapor() }}</span>
        <div class="flex flex-wrap gap-1.5">
            <span class="rounded-sm bg-warning-bg px-[7px] py-[3px] font-caption text-xs font-semibold text-warning">{{ $l->kerusakanLabel() }}</span>
            @if ($l->petugas) <span class="rounded-sm bg-surface-muted px-[7px] py-[3px] font-caption text-xs text-fg">Petugas: {{ $l->petugas->name }}</span> @endif
        </div>
        <button type="button" onclick="Livewire.dispatch('pilih-titik', { id: {{ $l->id }} })" class="mt-1 flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">
            Buka Laporan {!! svg('lucide-arrow-right', 'size-[18px]')->toHtml() !!}
        </button>
    </div>
</div>

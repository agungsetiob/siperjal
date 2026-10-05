{{-- Popover marker di peta penuh (desain A1 Peta Penuh). --}}
@php
    $hari = $u->diajukan_at ? (int) $u->diajukan_at->diffInDays() : 0;
    $telat = $hari > config('usuljalan.batas_hari_periksa');
    $foto = $u->foto->first();
@endphp
<div class="flex w-[300px] flex-col font-sans">
    @if ($foto) <img src="{{ $foto->url() }}" class="h-[120px] w-full object-cover" alt=""> @endif
    <div class="flex flex-col gap-2 p-4">
        <div class="flex items-center justify-between font-caption text-xs">
            <span class="font-semibold text-accent">{{ $u->nomor }}</span>
            <span @class(['flex items-center gap-1', 'font-semibold text-warning' => $telat, 'text-fg-muted' => ! $telat])>{{ $hari ? "masuk {$hari} hari lalu" : 'masuk hari ini' }}</span>
        </div>
        <span class="text-base font-semibold text-fg">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
        <span class="text-[13px] text-fg-muted">{{ collect([$u->desa ? 'Ds. '.$u->desa->nama : null, $u->kecamatan ? 'Kec. '.$u->kecamatan->nama : null])->filter()->implode(' · ') }}</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach ($u->items as $i)
                <span class="rounded-sm bg-surface-muted px-[7px] py-[3px] font-caption text-xs text-fg">{{ $i->jenis->namaPendek() }} ×{{ $i->jumlah }}</span>
            @endforeach
        </div>
        @if ($dekat)
            <span class="flex items-center gap-1.5 rounded-sm bg-warning-bg px-2 py-1.5 font-caption text-xs font-semibold text-warning">Ada usulan berdekatan ({{ $dekat }} m)</span>
        @endif
        <button type="button" onclick="Livewire.dispatch('pilih-titik', { id: {{ $u->id }} })" class="mt-1 flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">
            Buka & Periksa {!! svg('lucide-arrow-right', 'size-[18px]')->toHtml() !!}
        </button>
    </div>
</div>

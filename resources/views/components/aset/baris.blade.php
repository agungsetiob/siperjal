{{-- Baris daftar aset (AS1/AS4): kode register, status, jenis, lokasi, terakhir dirawat. Mode Pilih menambah kotak centang. --}}
@props(['aset', 'aktif' => false, 'tandai' => false, 'dipilih' => false])
@php
    $rusak = (bool) $aset->laporanTerbuka;
    $rawat = $aset->dirawat_terakhir ? \Illuminate\Support\Carbon::parse($aset->dirawat_terakhir) : null;
    $tanpaKode = ! $aset->kode;
@endphp
<button type="button" wire:click="pilihAset({{ $aset->id }})" @disabled($tandai && $tanpaKode) {{ $attributes->class([
    'flex w-full items-start gap-3 border-b border-line px-6 py-3.5 text-left',
    'bg-accent-soft' => $aktif || $dipilih,
    'hover:bg-surface-muted' => ! $aktif && ! $dipilih,
    'opacity-50' => $tandai && $tanpaKode,
]) }} @if ($aktif) aria-current="true" @endif>
    @if ($tandai)
        <span @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded-[5px] outline outline-1 -outline-offset-1', 'bg-accent text-fg-inverse outline-accent' => $dipilih, 'bg-bg outline-line' => ! $dipilih])>
            @if ($dipilih) @svg('lucide-check', 'size-3.5') @endif
        </span>
    @endif
    <span class="flex min-w-0 flex-1 flex-col gap-1.5">
        <span class="flex items-center justify-between gap-2">
            @if ($aset->kode)
                <span class="font-caption text-[13px] font-semibold tracking-wide">{{ $aset->kode }}</span>
            @else
                <span class="flex items-center gap-1 font-caption text-[13px] font-semibold text-warning">@svg('lucide-circle-dashed', 'size-3.5') Belum berkode</span>
            @endif
            <span @class(['flex items-center gap-1.5 font-caption text-xs font-semibold', 'text-danger' => $rusak, 'text-success' => ! $rusak])><span class="size-1.5 rounded-full bg-current"></span>{{ $rusak ? 'Dilaporkan rusak' : 'Baik' }}</span>
        </span>
        <span class="truncate text-[15px] font-semibold">{{ $aset->jenis->namaPendek() }}@unless ($aset->jenis->per_unit)<span class="font-medium text-fg-muted"> · {{ number_format($aset->jumlah, 0, ',', '.') }} {{ $aset->jenis->satuan }}</span>@endunless</span>
        <span class="flex items-center gap-1.5 font-caption text-[13px] text-fg-muted">
            <span class="min-w-0 truncate">{{ $aset->alamat ?: ($aset->desa?->nama ?: '-') }}</span>
            @if ($aset->kecamatan) <span class="size-[3px] shrink-0 rounded-full bg-current"></span><span class="shrink-0">Kec. {{ $aset->kecamatan->nama }}</span> @endif
            @isset($aset->jarak) <span class="ml-auto shrink-0 text-xs font-semibold text-accent">{{ $aset->jarak < 1000 ? round($aset->jarak).' m' : number_format($aset->jarak / 1000, 1, ',', '.').' km' }}</span> @endisset
            <span class="{{ isset($aset->jarak) ? '' : 'ml-auto ' }}flex shrink-0 items-center gap-1 text-xs">@svg('lucide-wrench', 'size-3') {{ $rawat ? 'Dirawat '.$rawat->translatedFormat($rawat->isCurrentYear() ? 'j M' : 'j M Y') : 'Belum dirawat' }}</span>
        </span>
    </span>
</button>

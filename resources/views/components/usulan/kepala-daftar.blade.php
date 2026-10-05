{{-- Kepala daftar (Usulan & Pemeliharaan): Category Switch, judul, toggle Daftar/Peta, tab, cari & urut/saring. --}}
@props(['judul', 'tabs', 'jumlah', 'tab', 'mode', 'urut', 'semuaKecamatan', 'semuaJenis', 'kecamatan' => null, 'jenis' => null, 'bisaSaring' => true,
    'kategori' => 'usulan', 'cari' => 'Cari nomor, desa, atau pengusul'])
<div class="flex flex-col gap-4 px-6 pt-5">
    <x-kategori :aktif="$kategori" />
    <div class="flex items-center justify-between gap-2">
        <h1 class="judul min-w-0 whitespace-nowrap text-[26px] leading-[28px] sm:text-[28px]">{{ $judul }}</h1>
        <div class="flex shrink-0 gap-0.5 rounded-sm bg-surface-muted p-[3px] font-caption text-[13px]">
            @foreach (['daftar' => ['list', 'Daftar'], 'peta' => ['map', 'Peta']] as $m => [$ikon, $label])
                <button type="button" wire:click="$set('mode', '{{ $m }}')" aria-label="{{ $label }}" @class(['flex items-center gap-1.5 rounded-sm px-2 py-1.5 sm:px-2.5', 'bg-bg font-semibold shadow-sm' => $mode === $m, 'font-medium text-fg-muted hover:text-fg' => $mode !== $m])>@svg('lucide-'.$ikon, 'size-3.5') <span class="max-[379px]:hidden">{{ $label }}</span></button>
            @endforeach
        </div>
    </div>

    <div class="flex gap-5 overflow-x-auto border-b border-line font-caption text-sm">
        @foreach ($tabs as $k => $label)
            <button type="button" wire:click="gantiTab('{{ $k }}')" @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 pb-2.5', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted hover:text-fg' => $tab !== $k])>
                {{ $label }}
                @if ($jumlah[$k] ?? 0)
                    <span @class(['rounded-full px-[7px] py-px text-[11px] font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ $jumlah[$k] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="relative flex gap-2 pb-3" x-data="{ buka: false }">
        <label class="flex h-10 flex-1 items-center gap-2 rounded-sm bg-bg px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
            @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
            <input wire:model.live.debounce.300ms="cari" class="w-full bg-transparent text-sm outline-none placeholder:text-fg-muted" placeholder="{{ $cari }}">
        </label>
        <button type="button" @click="buka = !buka" @class(['flex h-10 items-center gap-1.5 rounded-sm px-3 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1', 'outline-accent text-accent' => $kecamatan || $jenis, 'outline-line' => ! $kecamatan && ! $jenis])>
            @svg('lucide-arrow-down-wide-narrow', 'size-4') {{ $urut === 'lama' ? 'Terlama' : 'Terbaru' }}
        </button>
        <div x-show="buka" x-cloak @click.outside="buka = false" x-transition.opacity.duration.150ms class="absolute right-0 top-11 z-[1100] flex w-72 flex-col gap-2 rounded-md bg-bg p-3 shadow-lg outline outline-1 -outline-offset-1 outline-line">
            <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Urutkan</span>
            <div class="flex gap-1 rounded-sm bg-surface-muted p-[3px] font-caption text-[13px]">
                @foreach (['lama' => 'Terlama', 'baru' => 'Terbaru'] as $v => $l)
                    <button type="button" wire:click="$set('urut', '{{ $v }}')" @class(['flex-1 rounded-sm py-1.5', 'bg-bg font-semibold shadow-sm' => $urut === $v, 'text-fg-muted' => $urut !== $v])>{{ $l }}</button>
                @endforeach
            </div>
            @if ($bisaSaring)
                <span class="mt-1 font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Saring</span>
                <select wire:model.live="kecamatan" class="input py-2 text-sm"><option value="">Semua kecamatan</option>@foreach ($semuaKecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select>
                <select wire:model.live="jenis" class="input py-2 text-sm"><option value="">Semua perlengkapan</option>@foreach ($semuaJenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select>
                @if ($kecamatan || $jenis) <button type="button" wire:click="$set('kecamatan', null); $set('jenis', null)" class="self-start text-sm text-accent">Hapus saringan</button> @endif
            @endif
        </div>
    </div>
</div>

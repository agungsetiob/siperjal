{{-- Detail laporan untuk pelapor (desain PM4 ②). Dipakai di pages/pemeliharaan/index. --}}
@props(['l'])
@use('App\Enums\StatusLaporan', 'SL')
@php
    $s = $l->status;
    $item = $l->item;
    $penjelasan = match ($s) {
        SL::Baru => 'Laporanmu sudah diterima. Dishub akan menugaskan petugas untuk cek ke lokasi.',
        SL::Ditugaskan => ($l->petugas?->name ?? 'Petugas').' akan cek kondisi di lokasi.',
        SL::MenungguPerbaikan => 'Petugas sudah memastikan '.str($item->jenis->namaPendek())->lower().' rusak. Tim perbaikan Dishub akan datang ke lokasi.',
        SL::Selesai => 'Sudah diperbaiki. Terima kasih sudah melapor!',
        SL::Ditolak => 'Laporan tidak dilanjutkan: '.str($l->alasan_tolak)->lower().'. Kamu bisa lapor lagi bila rusak kembali.',
        SL::Dibatalkan => 'Laporan ini kamu batalkan.',
    };
    $fotoKamu = $l->fotoJenis('pelapor');
    $fotoPetugas = $l->fotoJenis('cek');
    $fotoHasil = $l->fotoJenis('hasil');
    $galeri = \App\Models\LaporanFoto::galeri($l->foto);
    $indeks = fn ($f) => (int) $l->foto->values()->search(fn ($x) => $x->id === $f->id);
    $judulSeksi = 'font-caption text-xs font-semibold uppercase tracking-[1px] text-fg-muted';
@endphp
<div class="flex max-w-3xl flex-col gap-6 bg-bg px-4 pb-28 pt-3 lg:px-8 lg:pb-10 lg:pt-6">
    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-2.5">
            <span class="font-caption text-sm font-semibold text-fg-muted">{{ $l->nomor }}</span>
            <x-badge :tone="$s->tone()">{{ $s->label() }}</x-badge>
        </div>
        <h2 class="judul text-[34px] leading-9">{{ match ($s) { SL::Baru => 'Laporan diterima', SL::Ditugaskan => 'Petugas ditugaskan', SL::Ditolak => 'Laporan ditolak', default => $s->label() } }}</h2>
        <p class="text-base leading-[23px] text-fg-muted">{{ $penjelasan }}</p>
    </div>

    <x-pemeliharaan.stepper :laporan="$l" gaya="tegak" />

    @if ($l->dicek_at && $l->petugas)
        <div class="flex flex-col gap-3">
            <span class="{{ $judulSeksi }}">Hasil cek petugas</span>
            <div class="flex flex-col gap-2.5 rounded-md bg-surface-soft p-3.5">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-full bg-accent-deep font-caption text-xs font-bold text-fg-inverse">{{ $l->petugas->inisial() }}</span>
                    <span class="flex flex-col"><span class="text-[15px] font-semibold">{{ $l->petugas->name }}</span><span class="font-caption text-xs text-fg-muted">petugas lapangan · {{ $l->dicek_at->translatedFormat('j M, H.i') }}</span></span>
                </div>
                @if ($l->catatan_cek) <p class="text-[15px] font-medium leading-[22px]">“{{ $l->catatan_cek }}”</p> @endif
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <span class="{{ $judulSeksi }}">Foto kerusakan</span>
        <div class="grid max-w-xl grid-cols-2 gap-2.5">
            @foreach ([['Kamu', $fotoKamu], ['Petugas', $fotoPetugas], ['Hasil perbaikan', $fotoHasil]] as [$siapa, $daftar])
                @if ($daftar->isNotEmpty())
                    <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js($galeri), mulai: {{ $indeks($daftar->first()) }} })" class="relative aspect-[4/3] overflow-hidden rounded-md">
                        <img src="{{ $daftar->first()->url() }}" class="size-full object-cover" alt="">
                        <span class="absolute bottom-2 left-2 rounded-sm bg-fg/75 px-2 py-0.5 font-caption text-xs font-semibold text-fg-inverse">{{ $siapa }}{{ $daftar->count() > 1 ? ' · '.$daftar->count() : '' }}</span>
                    </button>
                @endif
            @endforeach
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <span class="{{ $judulSeksi }}">Lokasi</span>
        @if ($l->lat())
            <div class="h-36 overflow-hidden rounded-md" wire:ignore x-data="petaMini({{ $l->lat() }}, {{ $l->lng() }}, @js(svg('lucide-'.$item->jenis->ikon, 'size-4')->toHtml()))"></div>
        @endif
        <div class="flex flex-col gap-0.5">
            <span class="text-base font-semibold">{{ $l->lokasi() }}</span>
            <span class="text-sm text-fg-muted">{{ collect([$item->usulan->desa ? 'Ds. '.$item->usulan->desa->nama : null, $item->usulan->kecamatan ? 'Kec. '.$item->usulan->kecamatan->nama : null])->filter()->implode(' · ') }}</span>
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <span class="{{ $judulSeksi }}">Perlengkapan</span>
        <div class="flex items-center gap-3">
            <x-ikon-jenis :jenis="$item->jenis" ukuran="size-[22px]" class="size-11 shrink-0" />
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-base font-semibold">{{ $item->jenis->namaPendek() }}</span>
                <span class="text-sm text-fg-muted">terpasang {{ $item->terpasang_at?->translatedFormat('j M Y') }} · dari usulan {{ $item->usulan->nomor }}</span>
            </span>
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <span class="{{ $judulSeksi }}">Jenis kerusakan</span>
        <div class="flex flex-col gap-2">
            <span class="w-fit rounded-full bg-warning-bg px-3 py-1.5 text-sm font-semibold text-warning">{{ $l->kerusakanLabel() }}</span>
            @if ($l->catatan) <p class="text-[15px] leading-[22px]">“{{ $l->catatan }}”</p> @endif
        </div>
    </div>

    <div class="flex flex-col gap-2 border-t border-line pt-5">
        @if (in_array($s, [SL::Baru, SL::Ditugaskan]))
            <button type="button" wire:click="batalkan({{ $l->id }})" wire:confirm="Batalkan laporan {{ $l->nomor }}?" class="flex h-12 items-center justify-center gap-2 rounded-sm text-base font-semibold text-danger outline outline-1 -outline-offset-1 outline-line hover:bg-danger-bg">@svg('lucide-x', 'size-[18px]') Batalkan laporan</button>
        @elseif ($s === SL::MenungguPerbaikan)
            <p class="text-center font-caption text-[13px] text-fg-muted">Laporan tidak bisa dibatalkan setelah dikonfirmasi petugas.</p>
        @elseif (in_array($s, [SL::Selesai, SL::Ditolak]))
            <a href="{{ route('lapor', ['item' => $item->id]) }}" wire:navigate class="flex h-12 items-center justify-center gap-2 rounded-sm text-base font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-wrench', 'size-[18px]') Lapor lagi bila rusak kembali</a>
        @endif
    </div>
</div>

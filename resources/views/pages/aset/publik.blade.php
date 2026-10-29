<?php

use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use App\Support\KodeRegister;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Halaman publik hasil pindai QR (/aset/{kode}): info unit, kondisi, dan riwayat pemeliharaan.
 * Dishub yang sudah masuk melihat nama petugas & nomor laporan, plus tombol Catat pemeliharaan.
 */
new #[Layout('layouts.publik', ['nav' => 'lacak', 'footer' => 'ringkas'])] class extends Component
{
    public string $kode = '';
    public string $ketik = '';

    public function mount(string $kode): void
    {
        $this->kode = strtoupper(trim($kode));
        $this->ketik = $this->kode;
    }

    #[Computed]
    public function aset(): ?PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan', 'laporanTerbuka')->where('kode', $this->kode)->first();
    }

    #[Computed]
    public function riwayat()
    {
        return $this->aset
            ? $this->aset->pemeliharaan()->with('petugas', 'laporan')->get()
            : collect();
    }

    public function staf(): bool
    {
        return Pemeliharaan::bolehMencatat(auth()->user());
    }

    #[On('pemeliharaan-dicatat')]
    public function segarkan(): void
    {
        unset($this->aset, $this->riwayat);
    }

    public function cari()
    {
        $this->resetErrorBag();
        $kode = strtoupper(trim($this->ketik));
        $this->ketik = $kode;
        if (! KodeRegister::cocok($kode)) {
            $this->addError('ketik', 'Format belum benar. Contoh: RMB-001-BTL-26.');

            return null;
        }
        if (! PerlengkapanTerpasang::where('kode', $kode)->exists()) {
            $this->addError('ketik', "{$kode} belum terdaftar.");

            return null;
        }

        return $this->redirectRoute('aset', $kode, navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->aset ? $this->aset->kode.' · Info Perlengkapan Jalan' : 'Kode tidak ditemukan · SIPERJAL');
    }
};
?>

@php
    $a = $this->aset;
    $staf = $this->staf();
    $pengelola = 'Dinas Perhubungan '.str_replace('Kabupaten', 'Kab.', config('usuljalan.pemda'));
@endphp

<div class="min-h-[calc(100dvh-56px)] bg-surface-muted pb-28 lg:min-h-0 lg:py-12 lg:pb-12">
    <div class="sticky top-0 z-[1000] flex h-14 items-center gap-1 border-b border-line bg-bg px-2 lg:hidden">
        <a href="{{ url('/') }}" wire:navigate class="grid size-11 place-items-center" aria-label="Kembali">@svg('lucide-arrow-left', 'size-[22px]')</a>
        <span class="flex-1 text-base font-semibold">Info Perlengkapan Jalan</span>
        @if ($a)
            <button x-data type="button" @click="navigator.share ? navigator.share({ title: '{{ $a->kode }}', url: '{{ route('aset', $a->kode) }}' }) : (salin('{{ route('aset', $a->kode) }}'), $dispatch('toast', { pesan: 'Link disalin' }))" class="grid size-11 place-items-center" aria-label="Bagikan">@svg('lucide-share-2', 'size-5')</button>
        @endif
    </div>

    <div class="mx-auto flex max-w-xl flex-col gap-3 px-4 pt-3 lg:pt-0">
    @if (! $a)

        <div class="flex flex-col gap-4 rounded-md bg-bg p-6" wire:key="aset-tidak-ada">
            <span class="grid size-[72px] place-items-center rounded-full bg-secondary-soft text-warning">@svg('lucide-search-x', 'size-8')</span>
            <h1 class="judul text-[36px] leading-[38px]">KODE TIDAK DITEMUKAN</h1>
            <p class="text-base leading-6 text-fg-muted">{{ $kode }} tidak terdaftar. Cocokkan dengan kode yang tercetak di bawah QR pada label.</p>
            <form wire:submit="cari" class="flex flex-col gap-2">
                <label for="kode-aset" class="text-base font-semibold">Kode register</label>
                <input id="kode-aset" type="text" wire:model="ketik" autocomplete="off" autocapitalize="characters" spellcheck="false" class="h-[52px] rounded-sm px-3.5 font-caption text-lg font-semibold uppercase outline outline-1 -outline-offset-1 outline-line focus:outline-2 focus:outline-accent">
                @error('ketik') <span class="text-sm text-danger">{{ $message }}</span> @else <span class="font-caption text-[13px] text-fg-muted">Format: JENIS-NNN-KEC-YY, mis. RMB-001-BTL-26</span> @enderror
                <ul class="mt-2 flex flex-col gap-2 rounded-sm bg-surface-muted p-3.5 text-[15px]">
                    @foreach (['Kode tercetak di samping QR pada label', 'Huruf O dan angka 0 sering tertukar', 'Label rusak atau tak terbaca? Tetap bisa melapor'] as $tips)
                        <li class="flex items-start gap-2.5">@svg('lucide-check', 'mt-0.5 size-4 shrink-0 text-success') {{ $tips }}</li>
                    @endforeach
                </ul>
                <button type="submit" class="mt-2 flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Cari kode @svg('lucide-search', 'size-[18px]')</button>
            </form>
            <a href="{{ auth()->user()?->isPengusul() ? route('lapor') : url('/') }}" wire:navigate class="flex items-center gap-1.5 font-caption text-[15px] font-semibold text-accent">Laporkan kerusakan tanpa kode @svg('lucide-arrow-right', 'size-4')</a>
        </div>

    @else
        @php $rusak = $a->laporanTerbuka; @endphp

        @if ($staf)
            <div class="flex items-center gap-3 rounded-md bg-accent-deep p-3 text-fg-inverse" wire:key="bilah-sesi">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-secondary font-caption text-sm font-bold text-on-secondary">{{ auth()->user()->inisial() }}</span>
                <span class="flex min-w-0 flex-1 flex-col"><span class="truncate text-[15px] font-semibold">{{ auth()->user()->name }}</span><span class="font-caption text-xs text-fg-inverse/70">{{ auth()->user()->isPetugasLapangan() ? 'Petugas lapangan' : 'Koordinator' }} · Dishub</span></span>
                <a href="{{ route('terpasang') }}" wire:navigate class="flex items-center gap-1 font-caption text-[13px] font-semibold text-secondary">Detail aset @svg('lucide-arrow-right', 'size-3.5')</a>
            </div>
        @endif

        <div class="flex flex-col gap-4 rounded-md bg-bg p-4" wire:key="aset-info">
            <div class="flex items-start gap-3">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent">KODE REGISTER</span>
                    <h1 class="judul break-words text-[34px] leading-[36px]">{{ $a->kode }}</h1>
                    <span class="text-base text-fg-muted">{{ $a->jenis->nama }}</span>
                </div>
                <span class="grid size-14 shrink-0 place-items-center rounded-md text-fg-inverse" style="background: {{ $a->jenis->warna }}">@svg('lucide-'.$a->jenis->ikon, 'size-7')</span>
            </div>

            @if ($rusak)
                <div class="flex items-start gap-3 rounded-sm bg-warning-bg p-3.5 text-warning">
                    @svg('lucide-triangle-alert', 'mt-0.5 size-5 shrink-0')
                    <span class="flex flex-col gap-0.5"><span class="text-[15px] font-semibold">Dilaporkan rusak</span><span class="text-sm">Laporan kerusakan sedang ditangani Dishub.</span></span>
                </div>
            @else
                <div class="flex items-start gap-3 rounded-sm bg-success-bg p-3.5 text-success">
                    @svg('lucide-circle-check', 'mt-0.5 size-5 shrink-0')
                    <span class="flex flex-col gap-0.5"><span class="text-[15px] font-semibold">Kondisi baik</span><span class="text-sm">Tidak ada laporan kerusakan yang sedang ditangani.</span></span>
                </div>
            @endif

            <div class="flex flex-col gap-3.5">
                <div class="flex items-start gap-3">
                    @svg('lucide-map-pin', 'mt-0.5 size-5 shrink-0 text-fg-muted')
                    <span class="flex flex-col gap-1"><span class="font-caption text-[13px] text-fg-muted">Lokasi</span><span class="text-[15px]">{{ $a->lokasi() }}</span>
                        <a href="{{ $a->mapsUrl() }}" target="_blank" rel="noopener" class="font-caption text-sm font-semibold text-accent underline">Buka di Google Maps</a></span>
                </div>
                <div class="flex items-start gap-3">
                    @svg('lucide-calendar-check', 'mt-0.5 size-5 shrink-0 text-fg-muted')
                    <span class="flex flex-col gap-0.5"><span class="font-caption text-[13px] text-fg-muted">Dipasang</span><span class="text-[15px]">{{ $a->terpasang_at?->translatedFormat('j F Y') ?? ($a->tahun ? 'Tahun '.$a->tahun.' · sebelum SIPERJAL' : 'Sebelum SIPERJAL') }}</span></span>
                </div>
                <div class="flex items-start gap-3">
                    @svg('lucide-building-2', 'mt-0.5 size-5 shrink-0 text-fg-muted')
                    <span class="flex flex-col gap-0.5"><span class="font-caption text-[13px] text-fg-muted">Pengelola</span><span class="text-[15px]">{{ $pengelola }}</span></span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 rounded-md bg-bg p-4" wire:key="aset-riwayat">
            <h2 class="judul text-[22px] leading-6">RIWAYAT PEMELIHARAAN</h2>
            <ol class="flex flex-col">
                @foreach ($this->riwayat as $p)
                    <li class="relative flex gap-3 pb-4" wire:key="r-{{ $p->id }}">
                        <span class="absolute bottom-0 left-[5px] top-3 w-px bg-line"></span>
                        <span class="relative z-[1] mt-1.5 size-[11px] shrink-0 rounded-full bg-accent"></span>
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="font-caption text-[13px] text-fg-muted">{{ $p->tanggal->translatedFormat('j M Y') }}@if ($staf) @if ($p->petugas) · {{ $p->petugas->name }} @endif @if ($p->laporan) · {{ $p->laporan->nomor }} @endif @endif</span>
                            <span class="text-base font-semibold">{{ $p->label() }}@if ($p->dariLaporan()) · dari laporan warga @endif</span>
                            @if ($p->catatan) <span class="text-[15px] text-fg-muted">{{ $p->catatan }}</span> @endif
                        </div>
                    </li>
                @endforeach
                <li class="relative flex gap-3">
                    <span class="relative z-[1] mt-1.5 size-[11px] shrink-0 rounded-full bg-fg-muted"></span>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-caption text-[13px] text-fg-muted">{{ $a->terpasang_at?->translatedFormat('j M Y') ?? ($a->tahun ? $a->tahun.' · sebelum SIPERJAL' : 'Sebelum SIPERJAL') }}</span>
                        <span class="text-base font-semibold">Terpasang</span>
                    </div>
                </li>
            </ol>
        </div>

        <div class="fixed inset-x-0 bottom-0 z-[900] flex gap-2 border-t border-line bg-bg p-3 lg:static lg:border-0 lg:bg-transparent lg:p-0">
            @if ($rusak)
                <a href="{{ route('lapor.status', $rusak->nomor) }}" wire:navigate class="flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm text-base font-semibold outline outline-1 -outline-offset-1 outline-line">Lihat status laporan</a>
            @else
                <a href="{{ route('lapor.aset', $a->id) }}" wire:navigate @class(['flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm text-base font-semibold', 'bg-accent text-fg-inverse shadow-sm' => ! $staf, 'bg-bg outline outline-1 -outline-offset-1 outline-line' => $staf])>Lapor rusak @svg('lucide-triangle-alert', 'size-[18px]')</a>
            @endif
            @if ($staf)
                <button type="button" x-data @click="$dispatch('catat-pemeliharaan', { aset: {{ $a->id }} })" class="flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Catat pemeliharaan @svg('lucide-wrench', 'size-[18px]')</button>
            @endif
        </div>

        @if ($staf)
            <livewire:aset.catat :aset-id="$a->id" :key="'catat-'.$a->id" />
        @endif
    @endif
    </div>
</div>

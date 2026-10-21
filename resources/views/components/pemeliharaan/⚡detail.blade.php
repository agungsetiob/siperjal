<?php

use App\Enums\Role;
use App\Enums\StatusLaporan as SL;
use App\Models\LaporanKerusakan;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Panel detail laporan kerusakan untuk koordinator Dishub / admin (desain PM1).
 * Tindak lanjut per status: Tugaskan Petugas · Tolak laporan · Tandai Selesai Diperbaiki — hanya Dishub; admin hanya melihat.
 */
new class extends Component
{
    public int $laporanId;
    public int $posisi = 0;
    public int $total = 0;

    public ?string $dialog = null; // tugaskan, tolak
    public ?int $petugasId = null;
    public string $catatanTugas = '';
    public string $alasan = '';

    #[Computed]
    public function laporan(): LaporanKerusakan
    {
        return LaporanKerusakan::with('jenis', 'kecamatan', 'desa', 'aset.jenis', 'aset.usulanItem.usulan.instansi', 'aset.usulanItem.usulan.user', 'pelapor', 'petugas', 'penugas', 'penutup', 'foto')
            ->findOrFail($this->laporanId);
    }

    #[Computed]
    public function petugas()
    {
        return LaporanKerusakan::petugasLapangan();
    }

    /** Laporan aktif lain untuk perlengkapan yang sama atau dalam radius berdekatan. */
    #[Computed]
    public function berdekatan()
    {
        $l = $this->laporan;
        $radius = config('usuljalan.radius_berdekatan');

        return LaporanKerusakan::aktif()->whereKeyNot($l->id)->get()
            ->filter(fn ($b) => ($l->perlengkapan_terpasang_id && $b->perlengkapan_terpasang_id === $l->perlengkapan_terpasang_id)
                || ($l->lat() && $b->lat() && \App\Models\Usulan::jarak($l->lat(), $l->lng(), $b->lat(), $b->lng()) <= $radius))
            ->values();
    }

    #[Computed]
    public function kelola(): bool
    {
        return auth()->user()->hasRole(Role::Dishub);
    }

    private function bolehKelola(): void
    {
        abort_unless($this->kelola, 403);
    }

    public function buka(string $dialog): void
    {
        $this->bolehKelola();
        $this->resetValidation();
        $this->reset('catatanTugas', 'alasan');
        $this->dialog = $dialog;
        if ($dialog === 'tugaskan') {
            $this->petugasId = $this->laporan->petugas_id ?? $this->petugas->first()?->id;
        }
    }

    public function tugaskan(): void
    {
        $this->bolehKelola();
        $l = $this->laporan;
        abort_unless(in_array($l->status, [SL::Baru, SL::Ditugaskan], true), 403);
        $this->validate(['petugasId' => 'required|exists:users,id', 'catatanTugas' => 'nullable|string|max:300'], ['petugasId.required' => 'Pilih petugas lapangan.']);
        $petugas = User::findOrFail($this->petugasId);
        abort_unless($petugas->hasRole(Role::Dishub) && $petugas->aktif, 422);

        $l->tugaskan($petugas, trim($this->catatanTugas));
        $this->dialog = null;
        $this->dispatch('toast', pesan: "Ditugaskan ke {$petugas->name}");
        $this->dispatch('laporan-diproses', lanjut: true);
    }

    public function tolak(): void
    {
        $this->bolehKelola();
        $l = $this->laporan;
        abort_unless(in_array($l->status, [SL::Baru, SL::Ditugaskan], true), 403);
        $this->validate(['alasan' => 'required|string|max:200'], ['alasan.required' => 'Pilih atau tulis alasannya.']);

        $l->tolak(trim($this->alasan));
        $this->dialog = null;
        $this->dispatch('toast', pesan: 'Laporan ditolak · pelapor dikabari');
        $this->dispatch('laporan-diproses', lanjut: true);
    }
};
?>

@php
    $l = $this->laporan;
    $s = $l->status;
    $item = $l->aset;
    $asal = $item?->usulanItem?->usulan; // null: terpasang sebelum SIPERJAL
    $fotoPelapor = $l->fotoJenis('pelapor');
    $fotoCek = $l->fotoJenis('cek');
    $fotoHasil = $l->fotoJenis('hasil');
    $galeri = \App\Models\LaporanFoto::galeri($l->foto);
    $indeks = fn ($f) => (int) $l->foto->values()->search(fn ($x) => $x->id === $f->id);
    $judulSeksi = 'font-caption text-xs font-semibold uppercase tracking-[1px] text-fg-muted';
    $kartu = 'rounded-md bg-bg p-4 sm:p-5 outline outline-1 -outline-offset-1 outline-line';
    $saran = $this->petugas->first();
    $umur = $s === SL::MenungguPerbaikan && $l->dicek_at
        ? 'disetujui petugas '.$l->dicek_at->diffForHumans()
        : 'masuk '.$l->created_at->diffForHumans();
@endphp

<div class="flex flex-col gap-4 p-4 pb-24 lg:px-8 lg:py-6">
    {{-- Kepala --}}
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2.5 font-caption text-[13px] text-fg-muted">
            <span class="font-semibold">{{ $l->nomor }}</span>
            <x-status :status="$s" />
            <span>{{ $umur }}</span>
            <span class="size-[3px] rounded-full bg-fg-muted"></span>
            <span>{{ $l->created_at->translatedFormat('j M Y, H.i') }}</span>
        </div>
        <div class="flex items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <h2 class="judul text-[34px] leading-9">{{ $l->judul() }}</h2>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-fg-muted">
                    <span class="flex items-center gap-1.5">@svg('lucide-map-pin', 'size-4') {{ $l->lokasi() }}{{ $l->alamat && $l->kecamatan ? ', Kec. '.$l->kecamatan->nama : '' }}</span>
                    <span class="flex items-center gap-1.5">@svg('lucide-user-round', 'size-4') {{ $l->namaPelapor() }} · {{ $l->user_id ? 'Pelapor' : 'Pelapor tanpa akun' }}</span>
                    @if ($l->pelapor?->no_hp) <a href="tel:{{ $l->pelapor->no_hp }}" class="flex items-center gap-1.5 hover:text-fg">@svg('lucide-phone', 'size-4') {{ $l->pelapor->no_hp }}</a> @endif
                    @if ($l->kontak_email) <a href="mailto:{{ $l->kontak_email }}" class="flex items-center gap-1.5 hover:text-fg">@svg('lucide-mail', 'size-4') {{ $l->kontak_email }}</a> @endif
                </div>
            </div>
            @if ($total > 1)
                <div class="hidden shrink-0 items-center gap-1 lg:flex">
                    <span class="mr-1 font-caption text-[13px] text-fg-muted">{{ $posisi }} dari {{ $total }}</span>
                    <button type="button" wire:click="$parent.pindah(-1)" class="grid size-9 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line hover:bg-bg" aria-label="Sebelumnya">@svg('lucide-chevron-up', 'size-4')</button>
                    <button type="button" wire:click="$parent.pindah(1)" class="grid size-9 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line hover:bg-bg" aria-label="Berikutnya">@svg('lucide-chevron-down', 'size-4')</button>
                </div>
            @endif
        </div>
    </div>

    <div class="{{ $kartu }} py-4"><x-pemeliharaan.stepper :laporan="$l" /></div>

    {{-- Hasil cek lokasi --}}
    @if ($l->dicek_at)
        <div class="{{ $kartu }} flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                <span class="judul text-xl">Hasil cek lokasi</span>
                @if ($s === SL::Ditolak)
                    <x-badge tone="bahaya">Ditolak · {{ str($l->alasan_tolak)->lower() }}</x-badge>
                @else
                    <x-badge tone="sukses">Disetujui · perlu diperbaiki</x-badge>
                @endif
            </div>
            <div class="flex flex-col gap-4 sm:flex-row">
                @if ($fotoCek->isNotEmpty())
                    <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js($galeri), mulai: {{ $indeks($fotoCek->first()) }} })" class="relative aspect-[4/3] w-full shrink-0 overflow-hidden rounded-md sm:aspect-auto sm:h-[150px] sm:w-[220px]">
                        <img src="{{ $fotoCek->first()->url() }}" class="size-full object-cover" alt="">
                        <span class="absolute bottom-2 left-2 rounded-sm bg-fg/75 px-2 py-0.5 font-caption text-xs font-semibold text-fg-inverse">Foto petugas · {{ $l->dicek_at->translatedFormat('j M, H.i') }}</span>
                    </button>
                @endif
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    @if ($l->catatan_cek) <p class="text-[15px] font-medium leading-[22px]">“{{ $l->catatan_cek }}”</p> @endif
                    @if ($l->petugas)
                        <div class="mt-auto flex items-center gap-2.5 border-t border-line pt-3 sm:border-0 sm:pt-0">
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent-deep font-caption text-[11px] font-bold text-fg-inverse">{{ $l->petugas->inisial() }}</span>
                            <span class="flex min-w-0 flex-col font-caption text-xs"><span class="font-semibold">{{ $s === SL::Ditolak ? 'Ditolak' : 'Disetujui' }} oleh {{ $l->petugas->name }}</span><span class="text-fg-muted">Petugas lapangan · {{ $l->dicek_at->translatedFormat('j M Y, H.i') }}</span></span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Foto pelapor + peta --}}
    <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="relative h-[230px] overflow-hidden rounded-md bg-surface-soft" x-data="{ n: 0, total: {{ $fotoPelapor->count() }} }">
            @forelse ($fotoPelapor as $i => $f)
                <button type="button" x-show="n === {{ $i }}" @click="$dispatch('lihat-foto', { foto: @js($galeri), mulai: {{ $indeks($f) }} })" class="absolute inset-0"><img src="{{ $f->url() }}" class="size-full object-cover" alt=""></button>
            @empty
                <div class="grid h-full place-items-center text-fg-muted">@svg('lucide-image-off', 'size-8')</div>
            @endforelse
            <span class="absolute left-3 top-3 rounded-sm bg-fg/75 px-2 py-1 font-caption text-xs font-semibold text-fg-inverse">Foto pelapor · <span x-text="(n + 1) + '/' + total"></span></span>
            @if ($fotoPelapor->count() > 1)
                <button type="button" @click="n = (n + 1) % total" class="absolute bottom-3 right-3 grid size-9 place-items-center rounded-full bg-bg/90 shadow-sm" aria-label="Foto berikutnya">@svg('lucide-chevron-right', 'size-5')</button>
            @endif
        </div>
        <div class="flex flex-col overflow-hidden rounded-md bg-bg outline outline-1 -outline-offset-1 outline-line">
            @if ($l->lat())
                <div class="min-h-[170px] flex-1" wire:ignore x-data="petaTitik({ lat: {{ $l->lat() }}, lng: {{ $l->lng() }} }, @js($this->berdekatan->filter(fn ($b) => $b->lat())->map(fn ($b) => ['lat' => $b->lat(), 'lng' => $b->lng()])->values()), {{ config('usuljalan.radius_berdekatan') }}, @js(svg('lucide-'.$l->ikon(), 'size-[13px]')->toHtml()), @js(svg('lucide-wrench', 'size-[11px]')->toHtml()))"><div x-ref="peta" class="size-full min-h-[170px]"></div></div>
            @endif
            <div class="flex items-center justify-between gap-2 px-3.5 py-2.5 font-caption text-xs text-fg-muted">
                <span>{{ number_format($l->lat() ?? 0, 5) }}, {{ number_format($l->lng() ?? 0, 5) }}</span>
                <a href="https://www.google.com/maps/search/?api=1&query={{ $l->lat() }},{{ $l->lng() }}" target="_blank" class="flex items-center gap-1 font-semibold text-accent">Buka peta @svg('lucide-external-link', 'size-3')</a>
            </div>
        </div>
    </div>

    {{-- Kata pelapor --}}
    <div class="{{ $kartu }} flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-warning-bg px-2.5 py-1 font-caption text-xs font-semibold text-warning">{{ $l->kerusakanLabel() }}</span>
            </div>
            @if ($l->catatan) <p class="text-[15px] font-medium leading-[22px]">“{{ $l->catatan }}”</p> @endif
            <span class="flex flex-wrap items-center gap-2 font-caption text-xs text-fg-muted">
                <span>{{ $l->namaPelapor() }} · {{ $l->pelapor?->keteranganPeran() ?? 'lewat peta publik' }}</span>
                <span class="hidden size-[3px] rounded-full bg-fg-muted sm:block"></span>
                @if ($this->berdekatan->isEmpty())
                    <span class="flex items-center gap-1 text-success">@svg('lucide-circle-check', 'size-3.5') tidak ada laporan lain dalam radius {{ config('usuljalan.radius_berdekatan') }} m</span>
                @else
                    <span class="flex items-center gap-1 font-semibold text-warning">@svg('lucide-copy', 'size-3.5') {{ $this->berdekatan->count() }} laporan aktif lain di dekatnya: {{ $this->berdekatan->pluck('nomor')->implode(', ') }}</span>
                @endif
            </span>
        </div>
        @if ($l->pelapor?->no_hp)
            <a href="https://wa.me/{{ preg_replace('/^0/', '62', preg_replace('/\D/', '', $l->pelapor->no_hp)) }}" target="_blank" class="flex shrink-0 items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-message-circle', 'size-4') Hubungi</a>
        @endif
    </div>

    {{-- Perlengkapan terpasang --}}
    @if (! $item)
        <div class="flex flex-col gap-2.5">
            <span class="{{ $judulSeksi }}">Perlengkapan</span>
            <div class="flex items-center gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line">
                @if ($l->jenis) <x-ikon-jenis :jenis="$l->jenis" class="size-10 shrink-0" /> @endif
                <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">{{ $l->jenis?->namaPendek() ?? 'Perlengkapan' }}</span><span class="truncate font-caption text-xs text-fg-muted">Tidak tertaut data terpasang · titik dipilih pelapor</span></span>
            </div>
        </div>
    @else
    <div class="flex flex-col gap-2.5">
        <span class="{{ $judulSeksi }}">Perlengkapan terpasang</span>
        <div class="grid gap-3 lg:grid-cols-3">
            <div class="flex items-center gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line">
                <x-ikon-jenis :jenis="$item->jenis" class="size-10 shrink-0" />
                <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">{{ $item->jenis->namaPendek() }} · {{ $item->jumlah }} {{ $item->jenis->satuan }}</span><span class="truncate font-caption text-xs text-fg-muted">{{ $item->jenis->deskripsi }}</span></span>
            </div>
            @if ($asal)
                <a href="{{ route('usulan', $asal) }}" wire:navigate class="flex items-center gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line hover:outline-accent/40">
                    <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted">@svg('lucide-file-text', 'size-5')</span>
                    <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">Asal usulan · <span class="text-accent">{{ $asal->nomor }}</span></span><span class="truncate font-caption text-xs text-fg-muted">diajukan {{ $asal->namaPengusul() }}</span></span>
                </a>
            @else
                <div class="flex items-center gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line">
                    <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted">@svg('lucide-history', 'size-5')</span>
                    <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">Terpasang sebelum SIPERJAL</span><span class="truncate font-caption text-xs text-fg-muted">Data lama · tanpa usulan</span></span>
                </div>
            @endif
            <div class="flex items-center gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line">
                <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-success-bg text-success">@svg('lucide-map-pin-check', 'size-5')</span>
                <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">Terpasang · {{ $item->waktuPasang() ?? '—' }}</span><span class="truncate font-caption text-xs text-fg-muted">{{ $item->terpasang_at?->diffForHumans() ?? ($item->tahun ? 'tahun '.$item->tahun : '') }}</span></span>
            </div>
        </div>
    </div>
    @endif

    {{-- Tindak lanjut --}}
    <div class="{{ $kartu }} flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <span class="judul text-xl">Tindak lanjut</span>
            <span class="text-sm text-fg-muted">
                @switch ($s)
                    @case(SL::Baru) Petugas mengecek di lokasi, lalu memutuskan perlu diperbaiki atau tidak @break
                    @case(SL::Ditugaskan) Menunggu petugas mengecek kondisi di lokasi @break
                    @case(SL::MenungguPerbaikan) Tandai setelah tim perbaikan selesai bekerja di lokasi @break
                    @default Laporan sudah ditutup
                @endswitch
            </span>
        </div>

        @if ($s === SL::Baru && $saran)
            <div class="flex items-center gap-3 rounded-md bg-accent-soft p-3">
                <span class="grid size-9 place-items-center rounded-full bg-accent-deep font-caption text-xs font-bold text-fg-inverse">{{ $saran->inisial() }}</span>
                <span class="flex flex-col"><span class="text-sm font-semibold">Saran: {{ $saran->name }}</span><span class="font-caption text-xs text-fg-muted">{{ $saran->tugas_aktif }} tugas aktif{{ $saran->jabatan ? ' · '.$saran->jabatan : '' }}</span></span>
            </div>
        @elseif ($s === SL::Ditugaskan && $l->petugas)
            <div class="flex items-center gap-3 rounded-md bg-accent-soft p-3">
                <span class="grid size-9 place-items-center rounded-full bg-accent-deep font-caption text-xs font-bold text-fg-inverse">{{ $l->petugas->inisial() }}</span>
                <span class="flex min-w-0 flex-col"><span class="text-sm font-semibold">Ditugaskan ke {{ $l->petugas->name }} · {{ $l->ditugaskan_at->diffForHumans() }}</span>
                    <span class="truncate font-caption text-xs text-fg-muted">{{ $l->catatan_tugas ? '“'.$l->catatan_tugas.'”' : 'oleh '.($l->penugas?->name ?? 'koordinator') }}</span></span>
            </div>
        @elseif ($s === SL::Selesai)
            <div class="flex items-center gap-3">
                @if ($fotoHasil->isNotEmpty())
                    <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js($galeri), mulai: {{ $indeks($fotoHasil->first()) }} })" class="shrink-0"><img src="{{ $fotoHasil->first()->url() }}" class="size-16 rounded-md object-cover" alt=""></button>
                @endif
                <span class="flex flex-col gap-0.5"><span class="flex items-center gap-1.5 text-sm font-semibold text-success">@svg('lucide-badge-check', 'size-4') Selesai diperbaiki · {{ $l->selesai_at->translatedFormat('j M Y, H.i') }}</span><span class="font-caption text-xs text-fg-muted">oleh {{ $l->penutup?->name ?? '—' }}{{ $l->catatan_selesai ? ' · “'.$l->catatan_selesai.'”' : '' }}</span></span>
            </div>
        @elseif (in_array($s, [SL::Ditolak, SL::Dibatalkan]))
            <span class="flex items-center gap-1.5 text-sm font-semibold {{ $s === SL::Ditolak ? 'text-danger' : 'text-fg-muted' }}">@svg('lucide-circle-x', 'size-4') {{ $s === SL::Ditolak ? 'Ditolak: '.$l->alasan_tolak.' · oleh '.($l->penutup?->name ?? '—') : 'Dibatalkan oleh pelapor' }}</span>
        @endif

        @if ($s->aktif() && ! $this->kelola)
            <span class="flex items-center gap-1.5 border-t border-line pt-4 font-caption text-xs text-fg-muted">@svg('lucide-eye', 'size-3.5') Admin hanya melihat · tindak lanjut oleh Dishub</span>
        @elseif ($s->aktif())
            <div class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
                @if ($s === SL::MenungguPerbaikan)
                    <span class="flex items-center gap-1.5 font-caption text-xs text-fg-muted">@svg('lucide-bell', 'size-3.5') pelapor & petugas dikabari otomatis</span>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="$dispatch('buka-tugas', { id: {{ $l->id }} })" class="flex w-full items-center justify-center gap-2 sm:w-auto rounded-sm bg-success px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Tandai Selesai Diperbaiki @svg('lucide-circle-check', 'size-[18px]')</button>
                @else
                    <button type="button" wire:click="buka('tolak')" class="flex items-center gap-1.5 rounded-sm px-3 py-2.5 text-sm font-semibold text-danger hover:bg-danger-bg">@svg('lucide-circle-x', 'size-4') Tolak laporan</button>
                    <span class="font-caption text-xs text-fg-muted">untuk laporan iseng / duplikat</span>
                    <span class="flex-1"></span>
                    @if ($s === SL::Ditugaskan)
                        <button type="button" wire:click="buka('tugaskan')" class="flex items-center gap-1.5 rounded-sm px-3 py-2.5 text-sm font-semibold hover:bg-surface-muted">@svg('lucide-user-round-plus', 'size-4') Ganti Petugas</button>
                        <button type="button" wire:click="$dispatch('buka-tugas', { id: {{ $l->id }} })" class="flex w-full items-center justify-center gap-2 sm:w-auto rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Cek Kondisi @svg('lucide-clipboard-check', 'size-[18px]')</button>
                    @else
                        <button type="button" wire:click="buka('tugaskan')" class="flex w-full items-center justify-center gap-2 sm:w-auto rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Tugaskan Petugas @svg('lucide-user-round-plus', 'size-[18px]')</button>
                    @endif
                @endif
            </div>
        @endif
    </div>

    @if ($this->kelola)
        <livewire:pemeliharaan.tugas :tanpa-peta="true" :key="'cek-'.$l->id" />
    @endif

    {{-- ── Dialog ── --}}
    @if ($dialog === 'tugaskan')
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" @keydown.escape.window="$wire.set('dialog', null)">
            <div class="flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-xl bg-bg shadow-xl sm:max-w-[560px] sm:rounded-md">
                <div class="flex items-start gap-3 border-b border-line px-6 py-5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-user-round-plus', 'size-5')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-caption text-xs font-semibold text-fg-muted">{{ $l->nomor }} · {{ $l->judul() }}</span>
                        <span class="judul text-[28px] leading-7">Tugaskan petugas</span>
                        <span class="text-sm text-fg-muted">Petugas mengecek kondisi di lokasi, lalu memutuskan: perlu diperbaiki atau ditolak.</span>
                    </div>
                    <button wire:click="$set('dialog', null)" class="grid size-9 shrink-0 place-items-center rounded-full hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    <div class="flex flex-col gap-2.5">
                        <span class="flex items-center gap-2 text-sm font-semibold">1. Pilih petugas lapangan <span class="font-caption text-xs font-normal text-fg-muted">petugas lapangan dengan tugas paling sedikit di atas</span></span>
                        @foreach ($this->petugas as $i => $p)
                            @php $on = $petugasId === $p->id; @endphp
                            <button type="button" wire:click="$set('petugasId', {{ $p->id }})" wire:key="pt-{{ $p->id }}" @class([
                                'flex items-center gap-3 rounded-md p-3 text-left',
                                'bg-accent-soft outline outline-2 -outline-offset-2 outline-accent' => $on,
                                'outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted' => ! $on,
                            ])>
                                <span @class(['grid size-5 shrink-0 place-items-center rounded-full', 'bg-accent' => $on, 'outline outline-[1.5px] -outline-offset-1 outline-[#B8BDD0]' => ! $on])>@if ($on) <span class="size-2 rounded-full bg-bg"></span> @endif</span>
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent-deep font-caption text-xs font-bold text-fg-inverse">{{ $p->inisial() }}</span>
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="flex items-center gap-2 text-[15px] font-semibold">{{ $p->name }} @if ($i === 0) <x-badge tone="sukses" :dot="false">Disarankan</x-badge> @endif</span>
                                    <span class="truncate font-caption text-xs text-fg-muted">{{ $p->jabatan ?: 'Dishub' }}{{ $p->id === auth()->id() ? ' · saya' : '' }}</span>
                                </span>
                                <span class="flex flex-col items-end"><span class="judul text-xl leading-5">{{ $p->tugas_aktif }}</span><span class="font-caption text-[11px] text-fg-muted">tugas aktif</span></span>
                            </button>
                        @endforeach
                        @error('petugasId') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex flex-col gap-2" x-data="{ n: 0 }">
                        <span class="flex items-center gap-2 text-sm font-semibold">2. Catatan untuk petugas <span class="font-caption text-xs font-normal text-fg-muted">opsional</span></span>
                        <textarea wire:model="catatanTugas" x-on:input="n = $event.target.value.length" rows="3" maxlength="300" class="input text-sm" placeholder="Mis. pelapor bisa dihubungi kalau perlu ditunjukkan titiknya."></textarea>
                        <span class="flex justify-between font-caption text-xs text-fg-muted"><span>Terlihat oleh petugas di tugasnya</span><span x-text="n + ' / 300'"></span></span>
                    </div>
                    <div class="flex items-start gap-2.5 rounded-md bg-surface-tint p-3 text-[13px] leading-[19px] text-accent-deep">
                        @svg('lucide-bell-ring', 'mt-px size-4 shrink-0')
                        <span>Petugas langsung menerima notifikasi “Tugas baru” di HP dan lonceng. Status laporan jadi Ditugaskan.</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 border-t border-line px-6 py-4">
                    <span class="flex flex-1 items-center gap-2 font-caption text-xs text-fg-muted"><span class="grid size-6 place-items-center rounded-full bg-secondary text-[10px] font-semibold text-on-secondary">{{ auth()->user()->inisial() }}</span> Oleh {{ auth()->user()->name }}</span>
                    <x-tombol variant="kedua" wire:click="$set('dialog', null)">Batal</x-tombol>
                    <x-tombol wire:click="tugaskan" wire:loading.attr="disabled" wire:target="tugaskan" ikon-kanan="send">Tugaskan {{ str($this->petugas->firstWhere('id', $petugasId)?->name)->before(' ') }}</x-tombol>
                </div>
            </div>
        </div>
    @elseif ($dialog === 'tolak')
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" @keydown.escape.window="$wire.set('dialog', null)">
            <div class="w-full rounded-t-xl bg-bg p-6 sm:max-w-md sm:rounded-md">
                <span class="font-caption text-xs font-semibold text-fg-muted">{{ $l->nomor }}</span>
                <h3 class="judul mt-1 text-2xl">Tolak laporan</h3>
                <p class="mt-1 text-sm text-fg-muted">Pelapor dikabari beserta alasannya.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (\App\Models\LaporanKerusakan::ALASAN_KOORDINATOR as $a)
                        <button type="button" wire:click="$set('alasan', @js($a))" @class(['rounded-full px-3.5 py-2 text-sm', 'bg-fg font-semibold text-fg-inverse' => $alasan === $a, 'font-medium outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted' => $alasan !== $a])>{{ $a }}</button>
                    @endforeach
                </div>
                <input wire:model="alasan" class="input mt-3 text-sm" maxlength="200" placeholder="atau tulis alasan lain">
                @error('alasan') <div class="mt-1 text-sm text-danger">{{ $message }}</div> @enderror
                <div class="mt-5 flex justify-end gap-2">
                    <x-tombol variant="teks" wire:click="$set('dialog', null)">Batal</x-tombol>
                    <x-tombol variant="bahaya" wire:click="tolak" wire:loading.attr="disabled" wire:target="tolak">Tolak Laporan</x-tombol>
                </div>
            </div>
        </div>
    @endif
</div>

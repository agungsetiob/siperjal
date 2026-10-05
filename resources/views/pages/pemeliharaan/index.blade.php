<?php

use App\Enums\Role;
use App\Enums\StatusLaporan as SL;
use App\Models\LaporanKerusakan;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pemeliharaan: laporan kerusakan perlengkapan terpasang.
 * Pelapor: daftar + detail (PM4). Dishub/admin: tata letak sama dengan Usulan (daftar, peta penuh, panel detail — PM1);
 * peta lapangan HP (PM3) saat Peta di tab Ditugaskan/Perbaikan.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url(as: 'id')]
    public ?int $pilih = null;

    #[Url]
    public string $tab = '';

    #[Url(as: 'mode')]
    public string $mode = 'daftar';

    #[Url]
    public string $urut = 'lama';

    public string $cari = '';
    public ?int $kecamatan = null;
    public ?int $jenis = null;

    public function mount(?LaporanKerusakan $laporan = null): void
    {
        $this->tab = array_key_exists($this->tab, $this->daftarTab()) ? $this->tab : array_key_first($this->daftarTab());
        if ($laporan?->exists) {
            abort_unless(LaporanKerusakan::terlihatOleh($this->user)->whereKey($laporan->id)->exists(), 403);
            $this->pilih = $laporan->id;
        }
    }

    #[Computed]
    public function user(): User
    {
        return auth()->user();
    }

    /** Tab per role: kunci => [label, filter] */
    public function daftarTab(): array
    {
        return $this->user->isPengusul()
            ? [
                'aktif' => ['Aktif', fn ($q) => $q->aktif()],
                'selesai' => ['Selesai', fn ($q) => $q->whereNotIn('status', [SL::Baru, SL::Ditugaskan, SL::MenungguPerbaikan])],
            ]
            : [
                'baru' => ['Baru', fn ($q) => $q->where('status', SL::Baru)],
                'ditugaskan' => ['Ditugaskan', fn ($q) => $q->where('status', SL::Ditugaskan)],
                'perbaikan' => ['Perbaikan', fn ($q) => $q->where('status', SL::MenungguPerbaikan)],
                'semua' => ['Semua', fn ($q) => $q],
            ];
    }

    private function query(?string $tab = null)
    {
        $q = LaporanKerusakan::terlihatOleh($this->user);
        ($this->daftarTab()[$tab ?? $this->tab][1] ?? fn ($q) => $q)($q);

        return $q->when($this->cari, fn ($q) => $q->where(fn ($w) => $w
            ->where('nomor', 'like', "%{$this->cari}%")
            ->orWhereHas('pelapor', fn ($p) => $p->where('name', 'like', "%{$this->cari}%"))
            ->orWhereHas('item.usulan', fn ($u) => $u->where('alamat', 'like', "%{$this->cari}%"))
            ->orWhereHas('item.jenis', fn ($j) => $j->where('nama', 'like', "%{$this->cari}%"))))
            ->when($this->kecamatan, fn ($q) => $q->whereHas('item.usulan', fn ($u) => $u->where('kecamatan_id', $this->kecamatan)))
            ->when($this->jenis, fn ($q) => $q->whereHas('item', fn ($i) => $i->where('jenis_perlengkapan_id', $this->jenis)));
    }

    #[Computed]
    public function jumlahTab(): array
    {
        return collect($this->daftarTab())->map(fn ($_, $k) => $this->query($k)->count())->all();
    }

    #[Computed]
    public function daftar()
    {
        $kolom = $this->tab === 'perbaikan' ? 'dicek_at' : 'created_at';

        return $this->query()
            ->with('item.jenis', 'item.usulan.desa', 'item.usulan.kecamatan', 'pelapor', 'petugas', 'foto')
            ->orderBy($kolom, $this->urut === 'baru' || $this->user->isPengusul() ? 'desc' : 'asc')
            ->limit(200)->get();
    }

    /** Titik peta, format sama dengan peta Usulan (petaUsulan): pin kuning bila lewat batas hari. */
    #[Computed]
    public function titik(): array
    {
        $batas = config('usuljalan.batas_hari_periksa');

        return $this->daftar->filter(fn ($l) => $l->lat())->map(function ($l) use ($batas) {
            $menunggu = $l->status === SL::MenungguPerbaikan && $l->dicek_at;
            $hari = (int) ($menunggu ? $l->dicek_at : $l->created_at)->diffInDays();

            return [
                'id' => $l->id,
                'lat' => $l->lat(),
                'lng' => $l->lng(),
                'telat' => $l->status->aktif() && $hari > $batas,
                'dekat' => false,
                'ikon' => svg('lucide-'.$l->item->jenis->ikon, 'size-[15px]')->toHtml(),
                'nomor' => $l->nomor,
                'lokasi' => $l->judul(),
                'meta' => $l->lokasi(),
                'umur' => $menunggu ? ($hari ? "menunggu {$hari} hari" : 'menunggu sejak hari ini') : ($hari ? "masuk {$hari} hari lalu" : 'masuk hari ini'),
                'popup' => view('partials.popup-laporan', ['l' => $l, 'umur' => $hari])->render(),
            ];
        })->values()->all();
    }

    #[Computed]
    public function laporan(): ?LaporanKerusakan
    {
        return $this->pilih
            ? LaporanKerusakan::terlihatOleh($this->user)->with('item.jenis', 'item.usulan.desa', 'item.usulan.kecamatan', 'petugas', 'foto')->find($this->pilih)
            : null;
    }

    #[On('pilih-titik')]
    public function pilihLaporan(int $id): void
    {
        $this->pilih = $id;
        $this->dispatch('pilih-berubah', id: $id);
    }

    public function hapusFilter(): void
    {
        $this->reset('cari', 'kecamatan', 'jenis');
    }

    public function gantiTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, $this->daftarTab()), 404);
        $this->tab = $tab;
        $this->pilih = null;
    }

    public function pindah(int $arah): void
    {
        $ids = $this->daftar->pluck('id')->values();
        $i = $ids->search($this->pilih);
        $this->pilih = $ids->get($i === false ? 0 : max(0, min($ids->count() - 1, $i + $arah))) ?? $this->pilih;
    }

    /** Detail/HP memberi tahu laporan berubah; lanjut ke laporan berikutnya bila diminta. */
    #[On('laporan-diproses')]
    public function diproses(bool $lanjut = false): void
    {
        $ids = $this->daftar->pluck('id')->values();
        unset($this->daftar, $this->jumlahTab, $this->titik, $this->laporan);
        if ($lanjut && $this->pilih) {
            $i = $ids->search($this->pilih);
            $sisa = $this->daftar->pluck('id')->values();
            $this->pilih = $sisa->first(fn ($id) => $ids->search($id) > $i) ?? $sisa->first();
        }
    }

    /** Urungkan "Selesai diperbaiki" (toast dari detail desktop atau panel hasil di HP), maks 10 menit. */
    #[On('urungkan')]
    public function urungkan(string $token): void
    {
        if (! str_starts_with($token, 'lpr-selesai:') || $this->user->isPengusul()) {
            return;
        }
        // token: lpr-selesai:{id} (detail desktop) atau lpr-selesai:{id}:hp (panel hasil Tugas Saya)
        [, $id, $asal] = explode(':', $token) + [null, null, null];
        $l = LaporanKerusakan::find((int) $id);
        if ($l?->status === SL::Selesai && $l->selesai_at->gt(now()->subMinutes(10))) {
            $l->batalSelesai();
            if ($asal !== 'hp') { // desktop: buka lagi laporan yang diurungkan; HP petugas tetap di Tugas Saya
                $this->pilih = $l->id;
                $this->tab = 'perbaikan';
            }
            unset($this->daftar, $this->jumlahTab, $this->titik, $this->laporan);
            $this->dispatch('tugas-segarkan');
            $this->dispatch('toast', pesan: 'Penandaan selesai dibatalkan');
        }
    }

    /** Pelapor membatalkan laporan sebelum dikonfirmasi petugas. */
    public function batalkan(int $id): void
    {
        $l = LaporanKerusakan::findOrFail($id);
        abort_unless($l->user_id === $this->user->id && in_array($l->status, [SL::Baru, SL::Ditugaskan], true), 403);
        $l->batalkan();
        unset($this->daftar, $this->jumlahTab, $this->laporan);
        $this->dispatch('toast', pesan: 'Laporan dibatalkan');
    }

    public function render()
    {
        return $this->view([
            'semuaKecamatan' => \App\Models\Kecamatan::orderBy('nama')->get(),
            'semuaJenis' => \App\Models\JenisPerlengkapan::aktif()->get(),
        ])->title('Pemeliharaan · SIPERJAL');
    }
};
?>

@php
    $pengusul = $this->user->isPengusul();
    // Dishub/admin: sama dengan Usulan — Peta tanpa laporan terbuka = peta penuh; di HP tab Ditugaskan/Perbaikan = peta lapangan
    $petaPenuh = ! $pengusul && $mode === 'peta' && ! $pilih;
    $lapanganHp = $petaPenuh && in_array($tab, ['ditugaskan', 'perbaikan']);
    $batas = config('usuljalan.batas_hari_periksa');
    $adaFilter = $cari || $kecamatan || $jenis;
    $kepala = [
        'judul' => 'Laporan masuk', 'kategori' => 'pemeliharaan', 'cari' => 'Cari nomor, lokasi, atau pelapor',
        'tabs' => collect($this->daftarTab())->map(fn ($t) => $t[0])->all(), 'jumlah' => $this->jumlahTab,
        'tab' => $tab, 'mode' => $mode, 'urut' => $urut,
        'semuaKecamatan' => $semuaKecamatan, 'semuaJenis' => $semuaJenis, 'kecamatan' => $kecamatan, 'jenis' => $jenis,
    ];
    $ids = $this->daftar->pluck('id')->values();
    $posisi = $pilih ? $ids->search($pilih) + 1 : 0;
@endphp

<div wire:poll.30s
     x-data
     @keydown.window="if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) || {{ $pengusul ? 'true' : 'false' }}) return;
                      if ($event.key === 'ArrowDown') { $event.preventDefault(); $wire.pindah(1) }
                      if ($event.key === 'ArrowUp') { $event.preventDefault(); $wire.pindah(-1) }">
@if ($petaPenuh)
    @if ($lapanganHp)
        {{-- HP: peta lapangan (Cek Kondisi / Selesai Diperbaiki), seperti mode lapangan Siap Dipasang di Usulan --}}
        <div class="flex h-[calc(100dvh-72px)] flex-col bg-bg lg:hidden">
            <x-usulan.kepala-daftar :judul="$kepala['judul']" :kategori="$kepala['kategori']" :cari="$kepala['cari']" :tabs="$kepala['tabs']" :jumlah="$kepala['jumlah']" :tab="$tab" :mode="$mode" :urut="$urut" :semua-kecamatan="$semuaKecamatan" :semua-jenis="$semuaJenis" :kecamatan="$kecamatan" :jenis="$jenis" />
            <div class="flex min-h-0 flex-1 flex-col border-t border-line">
                <livewire:pemeliharaan.tugas :tab="$tab" :key="'tugas-'.$tab" />
            </div>
        </div>
    @endif
    {{-- ── Peta penuh: peta memenuhi layar + panel daftar mengambang (sama dengan Usulan) ── --}}
    <div @class(['relative h-[calc(100dvh-4rem-72px)] overflow-hidden bg-surface-muted lg:h-[calc(100dvh-4rem)]', 'hidden lg:block' => $lapanganHp])>
        <div class="absolute inset-0" wire:key="peta-penuh-{{ md5(json_encode(array_column($this->titik, 'id'))) }}"
             x-data="petaUsulan(@js($this->titik), @js(config('usuljalan.peta_awal')), { penuh: true, pilih: null })">
            <div x-ref="peta" class="peta-redup absolute inset-0" wire:ignore></div>
            <div x-show="{{ count($this->titik) ? 'false' : 'true' }} || $store.peta.ids?.length === 0" x-cloak class="pointer-events-none absolute inset-0 z-[600] grid place-items-center p-4">
                <div class="pointer-events-auto flex flex-col items-center gap-3 rounded-md bg-bg px-6 py-5 text-center shadow-lg">
                    @svg('lucide-map-pin-off', 'size-6 text-fg-muted')
                    <span class="text-[15px] font-semibold">Tidak ada laporan di area ini</span>
                    <div class="flex gap-2 font-caption text-[13px] font-semibold">
                        @if (count($this->titik)) <button type="button" @click="peta.zoomOut(2)" class="flex items-center gap-1.5 rounded-sm px-3 py-2 outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-zoom-out', 'size-3.5') Perkecil peta</button> @endif
                        @if ($adaFilter) <button type="button" wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-3.5') Hapus filter</button> @endif
                    </div>
                </div>
            </div>
            <div class="absolute right-4 top-4 z-[500] flex flex-col gap-2 lg:right-6 lg:top-6">
                <div class="flex flex-col overflow-hidden rounded-sm bg-bg shadow-sm">
                    <button type="button" @click="peta.zoomIn()" class="grid size-10 place-items-center border-b border-line hover:bg-surface-muted">@svg('lucide-plus', 'size-[18px]')</button>
                    <button type="button" @click="peta.zoomOut()" class="grid size-10 place-items-center hover:bg-surface-muted">@svg('lucide-minus', 'size-[18px]')</button>
                </div>
                <button type="button" @click="lokasiSaya()" class="grid size-10 place-items-center rounded-sm bg-bg shadow-sm hover:bg-surface-muted" title="Lokasi saya">@svg('lucide-locate-fixed', 'size-[18px]')</button>
                <button type="button" @click="gantiLapisan()" class="grid size-10 place-items-center rounded-sm bg-bg shadow-sm hover:bg-surface-muted" :class="satelit && 'text-accent'" title="Ganti peta / satelit">@svg('lucide-layers', 'size-[18px]')</button>
            </div>
            <div class="absolute bottom-6 right-4 z-[500] hidden items-center gap-3.5 rounded-sm bg-bg px-3.5 py-2.5 font-caption text-xs shadow-sm sm:flex lg:right-6">
                <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent"></span> ≤ {{ $batas }} hari</span>
                <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-warning"></span> Lewat batas</span>
                <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent-deep"></span> Kelompok</span>
            </div>
        </div>

        <section class="absolute inset-x-3 top-3 z-[600] flex max-h-[calc(100%-24px)] flex-col overflow-hidden rounded-md bg-bg shadow-lg sm:inset-x-auto sm:left-6 sm:top-6 sm:max-h-[calc(100%-48px)] sm:w-[420px]">
            <x-usulan.kepala-daftar :judul="$kepala['judul']" :kategori="$kepala['kategori']" :cari="$kepala['cari']" :tabs="$kepala['tabs']" :jumlah="$kepala['jumlah']" :tab="$tab" :mode="$mode" :urut="$urut" :semua-kecamatan="$semuaKecamatan" :semua-jenis="$semuaJenis" :kecamatan="$kecamatan" :jenis="$jenis" />
            <div class="flex items-center gap-2 border-y border-line bg-surface-muted px-6 py-3 font-caption text-[13px]">
                @svg('lucide-scan', 'size-3.5 shrink-0 text-fg-muted')
                <span class="flex-1 text-fg-muted" x-text="$store.peta.saring && $store.peta.ids ? $store.peta.ids.length + ' dari {{ count($this->titik) }} laporan terlihat di area peta' : '{{ count($this->titik) }} laporan di peta'"></span>
                <button type="button" x-show="$store.peta.saring" @click="$dispatch('tampilkan-semua')" class="font-semibold text-accent">Tampilkan semua</button>
                <button type="button" x-show="!$store.peta.saring" @click="$store.peta.saring = true" class="font-semibold text-accent">Saring area</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($this->daftar as $l)
                    <x-pemeliharaan.baris :l="$l" wire:key="pb-{{ $l->id }}" x-show="$store.peta.lihat({{ $l->id }})" />
                @empty
                    <x-kosong :ikon="$adaFilter ? 'search-x' : 'party-popper'" :nada="$adaFilter ? 'netral' : 'sukses'" :judul="$adaFilter ? 'Tidak ada yang cocok' : 'Tidak ada laporan di sini'" :teks="$adaFilter ? 'Coba kata kunci lain atau hapus filter.' : 'Laporan baru dari warga muncul di sini otomatis.'">
                        @if ($adaFilter) <button wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus filter</button> @endif
                    </x-kosong>
                @endforelse
            </div>
            <div class="hidden items-center gap-2 px-6 py-3 font-caption text-xs text-fg-muted sm:flex">
                <kbd class="rounded-[3px] px-1.5 py-0.5 text-[11px] font-semibold outline outline-1 -outline-offset-1 outline-line">↑</kbd>
                <kbd class="rounded-[3px] px-1.5 py-0.5 text-[11px] font-semibold outline outline-1 -outline-offset-1 outline-line">↓</kbd>
                pindah laporan · arahkan ke baris untuk menyalakan titiknya di peta
            </div>
        </section>
    </div>
@else
    <div class="lg:grid lg:h-[calc(100dvh-4rem)] lg:grid-cols-[440px_minmax(0,1fr)]">
        {{-- ── Panel kiri ── --}}
        <section @class(['flex min-h-0 flex-col border-r border-line bg-bg', 'hidden lg:flex' => $pilih])>
        @if ($pengusul)
            <div class="flex flex-col gap-3.5 border-b border-line px-4 pt-2 lg:px-6 lg:pt-5">
                <div class="flex items-end justify-between">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-base text-fg-muted">Halo, {{ str($this->user->name)->before(' ') }} 👋</span>
                        <h1 class="judul text-[40px] leading-[42px]">Usulan saya</h1>
                    </div>
                    <a href="{{ route('profil') }}" wire:navigate class="grid size-11 place-items-center overflow-hidden rounded-full bg-surface-soft text-base font-semibold lg:hidden" aria-label="Profil">
                        @if ($this->user->fotoUrl()) <img src="{{ $this->user->fotoUrl() }}" class="size-full object-cover" alt=""> @else {{ $this->user->inisial() }} @endif
                    </a>
                </div>
                <x-kategori aktif="pemeliharaan" />
                <div class="flex gap-6">
                    @foreach ($this->daftarTab() as $k => [$label])
                        <button wire:click="gantiTab('{{ $k }}')" @class(['-mb-px flex h-12 items-center gap-2 border-b-[3px] text-base', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted' => $tab !== $k])>
                            {{ $label }}
                            @if ($this->jumlahTab[$k]) <span @class(['grid h-[22px] min-w-[22px] place-items-center rounded-full px-[7px] font-caption text-xs font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ $this->jumlahTab[$k] }}</span> @endif
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto bg-surface-muted p-4">
                <div wire:loading.delay.flex wire:target="gantiTab" class="hidden flex-col gap-3" aria-label="Memuat daftar">
                    @foreach ([1, 2, 3] as $_)
                        <div class="flex gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line"><span class="kerangka size-14 shrink-0"></span><span class="flex flex-1 flex-col justify-center gap-2"><span class="kerangka h-2.5 w-1/3"></span><span class="kerangka h-3 w-4/5"></span><span class="kerangka h-2.5 w-1/2"></span></span></div>
                    @endforeach
                </div>
                <div wire:loading.delay.remove wire:target="gantiTab" class="contents">
                    @forelse ($this->daftar as $l)
                        <x-pemeliharaan.kartu :l="$l" :aktif="$pilih === $l->id" wire:key="lk-{{ $l->id }}" />
                    @empty
                        @if (array_sum($this->jumlahTab) === 0)
                            <x-kosong ikon="wrench" judul="Belum ada laporan kerusakan" teks="Lihat perlengkapan yang rusak, hilang, atau mati? Laporkan — petugas akan cek ke lokasi." class="py-16">
                                <a href="{{ route('lapor', ['jenis' => 'lapor']) }}" wire:navigate class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Lapor Kerusakan @svg('lucide-wrench', 'size-4')</a>
                            </x-kosong>
                        @else
                            <x-kosong :ikon="$tab === 'aktif' ? 'circle-check' : 'inbox'" :nada="$tab === 'aktif' ? 'sukses' : 'netral'" :judul="$tab === 'aktif' ? 'Tidak ada laporan aktif' : 'Belum ada yang selesai'" class="py-16" />
                        @endif
                    @endforelse
                    @if ($tab === 'aktif' && ($n = $this->jumlahTab['selesai']))
                        <button wire:click="gantiTab('selesai')" class="py-1 text-center font-caption text-[13px] text-fg-muted">{{ $n }} laporan sudah selesai · lihat di tab Selesai</button>
                    @endif
                </div>
            </div>
        @else
            {{-- Dishub/admin: kepala & daftar sama dengan Usulan; Peta + laporan terbuka = peta di panel kiri --}}
            <div class="flex min-h-0 flex-col lg:h-full">
                <x-usulan.kepala-daftar :judul="$kepala['judul']" :kategori="$kepala['kategori']" :cari="$kepala['cari']" :tabs="$kepala['tabs']" :jumlah="$kepala['jumlah']" :tab="$tab" :mode="$mode" :urut="$urut" :semua-kecamatan="$semuaKecamatan" :semua-jenis="$semuaJenis" :kecamatan="$kecamatan" :jenis="$jenis" />
                @if ($mode === 'peta')
                    <div class="relative min-h-[60dvh] flex-1 border-t border-line" wire:key="peta-panel-{{ md5(json_encode(array_column($this->titik, 'id'))) }}-{{ $pilih }}"
                         x-data="petaUsulan(@js($this->titik), @js(config('usuljalan.peta_awal')), { penuh: false, pilih: @js($pilih) })">
                        <div x-ref="peta" class="peta-redup absolute inset-0" wire:ignore></div>
                        <div class="absolute left-4 top-4 z-[500] flex items-center gap-3 rounded-sm bg-bg/90 px-2.5 py-1.5 font-caption text-xs">
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent"></span> ≤ {{ $batas }} hari</span>
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-warning"></span> Lewat batas</span>
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent-deep"></span> Kelompok</span>
                        </div>
                        <div class="absolute bottom-6 right-4 z-[500] flex flex-col gap-2">
                            <div class="flex flex-col overflow-hidden rounded-sm bg-bg shadow-sm">
                                <button type="button" @click="peta.zoomIn()" class="grid size-10 place-items-center border-b border-line hover:bg-surface-muted">@svg('lucide-plus', 'size-[18px]')</button>
                                <button type="button" @click="peta.zoomOut()" class="grid size-10 place-items-center hover:bg-surface-muted">@svg('lucide-minus', 'size-[18px]')</button>
                            </div>
                            <button type="button" @click="lokasiSaya()" class="grid size-10 place-items-center rounded-sm bg-bg shadow-sm hover:bg-surface-muted">@svg('lucide-locate-fixed', 'size-[18px]')</button>
                        </div>
                    </div>
                @else
                    <div class="min-h-0 flex-1 overflow-y-auto border-t border-line">
                        @forelse ($this->daftar as $l)
                            <x-pemeliharaan.baris :l="$l" :aktif="$pilih === $l->id" wire:key="lb-{{ $l->id }}" />
                        @empty
                            <x-kosong :ikon="$adaFilter ? 'search-x' : 'party-popper'" :nada="$adaFilter ? 'netral' : 'sukses'" :judul="$adaFilter ? 'Tidak ada yang cocok' : 'Tidak ada laporan di sini'" :teks="$adaFilter ? 'Coba kata kunci lain atau hapus filter.' : 'Laporan baru dari warga muncul di sini otomatis.'">
                                @if ($adaFilter) <button wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus filter</button> @endif
                            </x-kosong>
                        @endforelse
                    </div>
                @endif
            </div>
        @endif
        </section>

        {{-- ── Detail ── --}}
        <section @class(['relative min-h-0 overflow-y-auto', 'bg-bg' => $pengusul, 'bg-surface-muted' => ! $pengusul, 'hidden lg:block' => ! $pilih])>
            <div wire:loading.delay.flex wire:target="pilihLaporan,pindah,gantiTab" class="absolute inset-0 z-[900] hidden flex-col gap-4 bg-inherit p-6 lg:px-8" aria-label="Memuat detail">
                <div class="flex gap-2.5"><span class="kerangka h-3.5 w-28"></span><span class="kerangka h-5 w-[88px]"></span></div>
                <span class="kerangka h-6 w-2/5"></span>
                <div class="grid h-[172px] grid-cols-2 gap-3"><span class="kerangka h-full"></span><span class="kerangka h-full"></span></div>
            </div>
            @if ($pilih && $this->laporan)
                <button wire:click="$set('pilih', null)" class="sticky top-0 z-[800] flex w-full items-center gap-2 border-b border-line bg-bg px-4 py-3 text-sm font-medium lg:hidden">@svg('lucide-arrow-left', 'size-4') Kembali</button>
                @if ($pengusul)
                    <x-pemeliharaan.detail-pelapor :l="$this->laporan" />
                @else
                    <livewire:pemeliharaan.detail :laporan-id="$pilih" :posisi="$posisi" :total="$ids->count()" :key="'ld-'.$pilih" />
                @endif
            @else
                <div class="grid h-full place-items-center p-10 text-center text-fg-muted">
                    <div>@svg('lucide-mouse-pointer-click', 'mx-auto size-10')<p class="mt-3">Pilih laporan di daftar untuk melihat detail.</p></div>
                </div>
            @endif
        </section>
    </div>
@endif
</div>

<?php

use App\Enums\Role;
use App\Enums\StatusItem as SI;
use App\Enums\StatusUsulan as SU;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\User;
use App\Models\Usulan;
use App\Models\UsulanItem;
use App\Notifications\UsulanBerubah;
use App\Support\Urungkan;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

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

    // Bappeda: pilih item massal
    public array $itemDipilih = [];
    public int $tahun;
    public ?string $dialogMassal = null; // tunda, tolak
    public string $alasanMassal = '';

    public function mount(?Usulan $usulan = null): void
    {
        $this->tahun = now()->year + 1;
        $this->tab = $this->tab ?: array_key_first($this->daftarTab());
        if ($this->user->hasRole(Role::Bappeda) && ! request()->has('urut')) {
            $this->urut = 'skor';
        }
        if ($usulan?->exists) {
            $this->pilih = $usulan->id;
        }
    }

    #[Computed]
    public function user(): User
    {
        return auth()->user();
    }

    /** Tab per role: kunci => [label, filter query] */
    public function daftarTab(): array
    {
        $aktif = [SU::Draft, SU::Diperiksa, SU::Revisi, SU::MenungguBappeda, SU::Disetujui];

        return match ($this->user->role) {
            Role::Pengusul => [
                'aktif' => ['Aktif', fn ($q) => $q->whereIn('status', $aktif)],
                'selesai' => ['Selesai', fn ($q) => $q->whereNotIn('status', $aktif)],
            ],
            Role::Dishub => [
                'periksa' => ['Perlu Diperiksa', fn ($q) => $q->where('status', SU::Diperiksa)],
                'pasang' => ['Siap Dipasang', fn ($q) => $q->whereHas('items', fn ($i) => $i->where('status', SI::Disetujui))],
                'semua' => ['Semua', fn ($q) => $q],
            ],
            Role::Bappeda => [
                'menunggu' => ['Menunggu Keputusan', fn ($q) => $q->whereHas('items', fn ($i) => $i->where('status', SI::Lolos))],
                'ditunda' => ['Ditunda', fn ($q) => $q->whereHas('items', fn ($i) => $i->where('status', SI::Ditunda))],
                'semua' => ['Semua', fn ($q) => $q],
            ],
            Role::Admin => ['semua' => ['Semua', fn ($q) => $q]],
        };
    }

    private function query(?string $tab = null)
    {
        $q = Usulan::terlihatOleh($this->user);
        ($this->daftarTab()[$tab ?? $this->tab][1] ?? fn ($q) => $q)($q);

        return $q
            ->when($this->cari, fn ($q) => $q->where(fn ($w) => $w
                ->where('nomor', 'like', "%{$this->cari}%")
                ->orWhere('alamat', 'like', "%{$this->cari}%")
                ->orWhereHas('desa', fn ($d) => $d->where('nama', 'like', "%{$this->cari}%"))
                ->orWhereHas('user', fn ($d) => $d->where('name', 'like', "%{$this->cari}%"))))
            ->when($this->kecamatan, fn ($q) => $q->where('kecamatan_id', $this->kecamatan))
            ->when($this->jenis, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('jenis_perlengkapan_id', $this->jenis)));
    }

    #[Computed]
    public function jumlahTab(): array
    {
        return collect($this->daftarTab())->map(fn ($_, $k) => $this->query($k)->count())->all();
    }

    #[Computed]
    public function daftar()
    {
        $q = $this->query()->with(['items.jenis', 'foto', 'desa', 'kecamatan', 'user', 'instansi']);

        $arah = $this->urut === 'baru' ? 'desc' : 'asc';

        return (match (true) {
            $this->urut === 'skor' => $q->orderByDesc('skor')->orderBy('diajukan_at'),
            $this->urut === 'diputuskan' => $q->orderByDesc(UsulanItem::selectRaw('max(diputuskan_at)')->whereColumn('usulan_id', 'usulan.id'))->orderByDesc('diajukan_at'),
            default => $q->orderByRaw('diajukan_at is null')->orderBy('diajukan_at', $arah),
        })->limit(200)->get();
    }

    /** Titik peta: warna pin mengikuti umur usulan (lewat batas = kuning). */
    #[Computed]
    public function titik(): array
    {
        $batas = config('usuljalan.batas_hari_periksa');
        $radius = config('usuljalan.radius_berdekatan');
        $semua = $this->daftar->filter(fn ($u) => $u->lat);

        return $semua->map(function ($u) use ($batas, $radius, $semua) {
            $hari = $u->diajukan_at ? (int) $u->diajukan_at->diffInDays() : 0;
            $dekat = $semua->reject(fn ($b) => $b->id === $u->id)
                ->map(fn ($b) => Usulan::jarak($u->lat, $u->lng, $b->lat, $b->lng))
                ->filter(fn ($d) => $d <= $radius)->min();

            return [
                'id' => $u->id,
                'lat' => $u->lat,
                'lng' => $u->lng,
                'telat' => $u->status === SU::Diperiksa && $hari > $batas,
                'dekat' => $dekat !== null,
                'ikon' => svg('lucide-'.($u->items->first()?->jenis->ikon ?? 'map-pin'), 'size-[15px]')->toHtml(),
                'nomor' => $u->nomor ?? 'Draft',
                'lokasi' => $u->alamat ?: $u->lokasiSingkat(),
                'meta' => collect([$u->desa ? 'Ds. '.$u->desa->nama : null, $u->items->count().' item'])->filter()->implode(' · '),
                'umur' => $hari ? "menunggu {$hari} hari" : 'masuk hari ini',
                'popup' => view('partials.popup-usulan', ['u' => $u, 'dekat' => $dekat !== null ? (int) round($dekat) : null])->render(),
            ];
        })->values()->all();
    }

    #[On('toggle-item')]
    public function toggleItem(int $id): void
    {
        $this->itemDipilih = in_array($id, $this->itemDipilih)
            ? array_values(array_diff($this->itemDipilih, [$id]))
            : [...$this->itemDipilih, $id];
        $this->updatedItemDipilih();
    }

    /** Centang/lepas semua item yang bisa diputuskan pada satu usulan (atau semua usulan). */
    public function pilihSemua(?int $usulanId = null): void
    {
        $status = $this->tab === 'ditunda' ? SI::Ditunda : SI::Lolos;
        $ids = $this->daftar->when($usulanId, fn ($c) => $c->where('id', $usulanId))
            ->flatMap(fn ($u) => $u->items->where('status', $status)->pluck('id'))->all();
        $semua = $ids && ! array_diff($ids, $this->itemDipilih);
        $this->itemDipilih = $semua
            ? array_values(array_diff($this->itemDipilih, $ids))
            : array_values(array_unique([...$this->itemDipilih, ...$ids]));
        $this->updatedItemDipilih();
    }

    public function gantiTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, $this->daftarTab()), 404);
        $this->tab = $tab;
        $this->updated('tab');
    }

    public function hapusFilter(): void
    {
        $this->reset('cari', 'kecamatan', 'jenis');
    }

    /** Centang/lepas semua item ditunda dengan tahun target tertentu. */
    public function pilihTarget(int $tahun): void
    {
        $ids = $this->daftar->flatMap(fn ($u) => $u->items->where('status', SI::Ditunda)->where('tahun_target', $tahun)->pluck('id'))->all();
        $semua = $ids && ! array_diff($ids, $this->itemDipilih);
        $this->itemDipilih = $semua
            ? array_values(array_diff($this->itemDipilih, $ids))
            : array_values(array_unique([...$this->itemDipilih, ...$ids]));
        $this->updatedItemDipilih();
    }

    #[Computed]
    public function pagu(): ?int
    {
        $n = \App\Models\Pengaturan::ambil("pagu_{$this->tahun}");

        return $n === null ? null : (int) $n;
    }

    public function aturPagu(string $nilai): void
    {
        abort_unless($this->user->hasRole(Role::Bappeda), 403);
        $n = (int) preg_replace('/\D/', '', $nilai);
        if ($n <= 0) {
            return;
        }
        \App\Models\Pengaturan::simpan("pagu_{$this->tahun}", $n);
        unset($this->pagu);
        $this->dispatch('toast', pesan: "Pagu {$this->tahun} disimpan · ".\App\Support\Rekap::rupiahPendek($n));
    }

    /** Dari komponen lapangan: ganti tab/mode. */
    #[On('atur-daftar')]
    public function aturDaftar(?string $tab = null, ?string $mode = null): void
    {
        if ($tab) {
            $this->tab = $tab;
        }
        if ($mode) {
            $this->mode = $mode;
        }
    }

    #[On('tutup-detail')]
    public function tutupDetail(): void
    {
        $this->pilih = null;
    }

    #[On('pindah-usulan')]
    public function pindah(int $arah): void
    {
        $ids = $this->daftar->pluck('id')->values();
        $i = $ids->search($this->pilih);
        $baru = $ids->get($i === false ? 0 : max(0, min($ids->count() - 1, $i + $arah)));
        if ($baru) {
            $this->pilih = $baru;
        }
    }

    public function updatedPilih(): void
    {
        $this->dispatch('pilih-berubah', id: $this->pilih);
    }

    public function updatedItemDipilih(): void
    {
        $this->dispatch('pilihan-berubah', ids: $this->itemDipilih);
    }

    public function updated($prop): void
    {
        if (in_array($prop, ['tab', 'cari', 'kecamatan', 'jenis'])) {
            $this->itemDipilih = [];
        }
        if ($prop === 'tab' && $this->user->hasRole(Role::Bappeda)) {
            $this->urut = ['menunggu' => 'skor', 'ditunda' => 'target', 'semua' => 'diputuskan'][$this->tab] ?? 'skor';
        }
    }

    #[On('pilih-titik')]
    public function pilihUsulan(int $id): void
    {
        $this->pilih = $id;
        $this->updatedPilih();
    }

    /** Setelah satu usulan diproses: buka usulan berikutnya di antrian. */
    #[On('usulan-diproses')]
    public function diproses(bool $lanjut = true): void
    {
        unset($this->daftar, $this->jumlahTab);
        if ($lanjut && in_array($this->tab, ['periksa', 'menunggu'])) {
            $this->pilih = $this->daftar->first()?->id;
        }
    }

    /** Dipanggil tombol "Urungkan" di toast. */
    #[On('urungkan')]
    public function urungkan(string $token): void
    {
        if (str_contains($token, ':')) {
            return; // token milik komponen lain (mis. lapangan)
        }
        $id = Urungkan::pulihkan($token);
        unset($this->daftar, $this->jumlahTab, $this->totalTahun);
        if ($id) {
            $this->pilih = $id;
            $this->dispatch('$refresh')->to('usulan.detail');
        }
        $this->dispatch('toast', pesan: $id ? 'Dibatalkan' : 'Sudah tidak bisa dibatalkan', gagal: ! $id);
    }

    // ── Bappeda massal
    #[Computed]
    public function ringkasanPilihan(): array
    {
        $items = UsulanItem::with('jenis')->whereKey($this->itemDipilih)->get();

        return [
            'total' => $items->sum->estimasi(),
            'per_jenis' => $items->groupBy('jenis.nama')->map(fn ($g) => $g->sum->jumlahEfektif().' '.$g->first()->jenis->satuan),
        ];
    }

    #[Computed]
    public function totalTahun(): int
    {
        return UsulanItem::with('jenis')->where('tahun_anggaran', $this->tahun)
            ->whereIn('status', [SI::Disetujui, SI::Terpasang])->get()->sum->estimasi();
    }

    public function putuskanMassal(string $aksi): void
    {
        abort_unless($this->user->hasRole(Role::Bappeda), 403);
        $this->resetErrorBag();
        if ($aksi !== 'setujui' && blank($this->alasanMassal)) {
            $this->addError('alasanMassal', 'Tulis alasan singkat.');

            return;
        }

        $items = UsulanItem::with('jenis', 'usulan')->whereKey($this->itemDipilih)
            ->whereIn('status', [SI::Lolos, SI::Ditunda])->get();
        $usulanIds = $items->pluck('usulan_id')->unique()->all();
        $token = Urungkan::simpan($usulanIds);

        foreach ($items as $i) {
            $i->update([
                'status' => ['setujui' => SI::Disetujui, 'tunda' => SI::Ditunda, 'tolak' => SI::DitolakBappeda][$aksi],
                'tahun_anggaran' => $aksi === 'setujui' ? $this->tahun : null,
                'tahun_target' => $aksi === 'tunda' ? $this->tahun + 1 : null,
                'harga_satuan' => $aksi === 'setujui' ? $i->jenis->harga_satuan : null,
                'catatan_bappeda' => $aksi === 'setujui' ? null : $this->alasanMassal,
                'diputuskan_at' => now(),
                'diputuskan_oleh' => $this->user->id,
            ]);
        }
        $label = ['setujui' => 'disetujui', 'tunda' => 'ditunda', 'tolak' => 'ditolak'][$aksi];
        foreach ($items->groupBy('usulan_id') as $grup) {
            $u = $grup->first()->usulan;
            $u->hitungStatus();
            $u->catat("Bappeda: {$grup->count()} item {$label}".($aksi === 'setujui' ? " (anggaran {$this->tahun})" : ''), $aksi === 'setujui' ? null : $this->alasanMassal);
            $penerima = User::where('aktif', true)->where('role', Role::Dishub)->get()
                ->merge($u->instansi_id ? User::where('instansi_id', $u->instansi_id)->get() : [$u->user]);
            Notification::send($penerima->unique('id'), new UsulanBerubah(
                $u,
                "{$grup->count()} item {$label} Bappeda",
                $grup->map(fn ($i) => $i->jenis->namaPendek().' ×'.$i->jumlahEfektif())->implode(' dan ').($aksi === 'setujui' ? " masuk anggaran {$this->tahun}. Tinggal menunggu pemasangan." : ". {$this->alasanMassal}"),
                ['setujui' => 'disetujui', 'tunda' => 'ditunda', 'tolak' => 'ditolak'][$aksi],
            ));
        }

        $this->reset('itemDipilih', 'dialogMassal', 'alasanMassal');
        $this->updatedItemDipilih();
        unset($this->daftar, $this->jumlahTab, $this->totalTahun);
        $this->dispatch('toast', pesan: "{$items->count()} item {$label}", urungkan: $token);
    }

    public function render()
    {
        return $this->view([
            'semuaKecamatan' => Kecamatan::orderBy('nama')->get(),
            'semuaJenis' => JenisPerlengkapan::aktif()->get(),
        ])->title('Usulan · SIPERJAL');
    }
};
?>

@php
    $bappeda = $this->user->hasRole(\App\Enums\Role::Bappeda);
    $pengusul = $this->user->isPengusul();
    $tabItemBappeda = $bappeda && in_array($tab, ['menunggu', 'ditunda']);
    $statusItemTab = $tab === 'ditunda' ? \App\Enums\StatusItem::Ditunda : \App\Enums\StatusItem::Lolos;
    $petaPenuh = $mode === 'peta' && ! $pilih && ! $bappeda && ! $pengusul;
    $ids = $this->daftar->pluck('id')->values();
    $posisi = $pilih ? $ids->search($pilih) + 1 : 0;
    $kepala = [
        'judul' => $pengusul ? 'Usulan saya' : ($bappeda ? 'Keputusan usulan' : 'Usulan masuk'),
        'tabs' => collect($this->daftarTab())->map(fn ($t) => $t[0])->all(),
        'jumlah' => $this->jumlahTab,
        'tab' => $tab, 'mode' => $mode, 'urut' => $urut,
        'semuaKecamatan' => $semuaKecamatan, 'semuaJenis' => $semuaJenis,
        'kecamatan' => $kecamatan, 'jenis' => $jenis, 'bisaSaring' => ! $pengusul,
    ];
    $batas = config('usuljalan.batas_hari_periksa');
    // [ikon, nada, judul, teks, aksi] untuk daftar kosong
    $adaFilter = $cari || $kecamatan || $jenis;
    $keadaanKosong = match (true) {
        $adaFilter => ['search-x', 'netral', 'Tidak ada yang cocok', 'Coba kata kunci lain atau hapus filter.', 'filter'],
        $pengusul && array_sum($this->jumlahTab) === 0 => ['file-plus', 'proses', 'Belum ada usulan', 'Yuk ajukan yang pertama! Cukup foto lokasinya — sekitar 2 menit.', 'ajukan'],
        $pengusul && $tab === 'aktif' => ['circle-check', 'sukses', 'Tidak ada usulan aktif', 'Semua usulan Anda sudah selesai diproses.', 'ajukan'],
        $pengusul => ['inbox', 'netral', 'Belum ada yang selesai', 'Usulan yang sudah terpasang atau ditutup muncul di sini.', null],
        $tab === 'periksa' => ['party-popper', 'sukses', 'Semua usulan sudah diperiksa', 'Usulan baru akan muncul di sini otomatis.', ($this->jumlahTab['pasang'] ?? 0) ? 'pasang' : null],
        $tab === 'pasang' => ['map-pin-check', 'sukses', 'Belum ada yang siap dipasang', 'Usulan yang disetujui Bappeda muncul di sini.', null],
        $tab === 'menunggu' => ['party-popper', 'sukses', 'Semua usulan sudah diputuskan', 'Usulan yang lolos pemeriksaan Dishub akan muncul di sini.', null],
        default => ['inbox', 'netral', 'Tidak ada usulan di sini', null, null],
    };
@endphp

@php $lapangan = $this->user->hasRole(\App\Enums\Role::Dishub) && $tab === 'pasang' && $mode === 'peta' && ! $pilih; @endphp
<div @if (! $lapangan) wire:poll.20s @endif
     x-data
     @keydown.window="if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
                      if ($event.key === 'ArrowDown') { $event.preventDefault(); $wire.pindah(1) }
                      if ($event.key === 'ArrowUp') { $event.preventDefault(); $wire.pindah(-1) }">

@if ($lapangan)
    {{-- ── Mode lapangan Dishub (HP) ── --}}
    <div class="flex h-[calc(100dvh-72px)] flex-col lg:h-[calc(100dvh-4rem)]">
        <div class="flex flex-col gap-1 border-b border-line bg-bg px-4 pt-1 lg:hidden">
            <div class="flex items-center justify-between">
                <div class="flex flex-col gap-0.5">
                    <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">Dishub · Lapangan</span>
                    <h1 class="judul text-[28px] leading-7">Usulan</h1>
                </div>
                <div class="flex gap-0.5 rounded-sm bg-surface-muted p-0.5 font-caption text-sm">
                    @foreach (['daftar' => ['list', 'Daftar'], 'peta' => ['map', 'Peta']] as $m => [$ikon, $label])
                        <button wire:click="$set('mode', '{{ $m }}')" @class(['flex h-11 items-center gap-1.5 rounded-sm px-3', 'bg-bg font-semibold shadow-sm' => $mode === $m, 'font-medium text-fg-muted' => $mode !== $m])>@svg('lucide-'.$ikon, 'size-4') {{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <x-kategori aktif="usulan" />
            <div class="flex gap-6">
                @foreach (['periksa' => 'Perlu Diperiksa', 'pasang' => 'Siap Dipasang'] as $k => $label)
                    <button wire:click="gantiTab('{{ $k }}')" @class(['-mb-px flex h-12 items-center gap-2 border-b-[3px] text-base', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted' => $tab !== $k])>
                        {{ $label }} <span @class(['rounded-full px-2 py-0.5 font-caption text-xs font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ $this->jumlahTab[$k] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        <livewire:usulan.lapangan />
    </div>
@endif

<div @class(['hidden' => $lapangan])>
@if (! $lapangan && $petaPenuh)
    {{-- ── Peta penuh: peta memenuhi layar + panel daftar mengambang ── --}}
    <div class="relative h-[calc(100dvh-4rem-72px)] overflow-hidden bg-surface-muted lg:h-[calc(100dvh-4rem)]">
        <div class="absolute inset-0" wire:key="peta-penuh-{{ md5(json_encode(array_column($this->titik, 'id'))) }}"
             x-data="petaUsulan(@js($this->titik), @js(config('usuljalan.peta_awal')), { penuh: true, pilih: null })">
            <div x-ref="peta" class="peta-redup absolute inset-0" wire:ignore></div>
            <div x-show="{{ count($this->titik) ? 'false' : 'true' }} || $store.peta.ids?.length === 0" x-cloak class="pointer-events-none absolute inset-0 z-[600] grid place-items-center p-4">
                <div class="pointer-events-auto flex flex-col items-center gap-3 rounded-md bg-bg px-6 py-5 text-center shadow-lg">
                    @svg('lucide-map-pin-off', 'size-6 text-fg-muted')
                    <span class="text-[15px] font-semibold">Tidak ada usulan di area ini</span>
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
            <x-usulan.kepala-daftar :judul="$kepala['judul']" :tabs="$kepala['tabs']" :jumlah="$kepala['jumlah']" :tab="$tab" :mode="$mode" :urut="$urut" :semua-kecamatan="$semuaKecamatan" :semua-jenis="$semuaJenis" :kecamatan="$kecamatan" :jenis="$jenis" :bisa-saring="! $pengusul" />
            <div class="flex items-center gap-2 border-y border-line bg-surface-muted px-6 py-3 font-caption text-[13px]">
                @svg('lucide-scan', 'size-3.5 shrink-0 text-fg-muted')
                <span class="flex-1 text-fg-muted" x-text="$store.peta.saring && $store.peta.ids ? $store.peta.ids.length + ' dari {{ count($this->titik) }} usulan terlihat di area peta' : '{{ count($this->titik) }} usulan di peta'"></span>
                <button type="button" x-show="$store.peta.saring" @click="$dispatch('tampilkan-semua')" class="font-semibold text-accent">Tampilkan semua</button>
                <button type="button" x-show="!$store.peta.saring" @click="$store.peta.saring = true" class="font-semibold text-accent">Saring area</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($this->daftar as $u)
                    <x-usulan.baris :u="$u" :pengusul="$pengusul" wire:key="pb-{{ $u->id }}" x-show="$store.peta.lihat({{ $u->id }})" />
                @empty
                    <x-kosong :ikon="$keadaanKosong[0]" :nada="$keadaanKosong[1]" :judul="$keadaanKosong[2]" :teks="$keadaanKosong[3]">
                            @switch ($keadaanKosong[4])
                                @case('ajukan') <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Ajukan Usulan @svg('lucide-camera', 'size-4')</a> @break
                                @case('pasang') <button wire:click="gantiTab('pasang')" class="flex items-center gap-1.5 text-sm font-semibold text-accent">Lihat Siap Dipasang ({{ $this->jumlahTab['pasang'] }}) @svg('lucide-arrow-right', 'size-4')</button> @break
                                @case('filter') <button wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus filter</button> @break
                            @endswitch
                        </x-kosong>
                @endforelse
            </div>
            <div class="hidden items-center gap-2 px-6 py-3 font-caption text-xs text-fg-muted sm:flex">
                <kbd class="rounded-[3px] px-1.5 py-0.5 text-[11px] font-semibold outline outline-1 -outline-offset-1 outline-line">↑</kbd>
                <kbd class="rounded-[3px] px-1.5 py-0.5 text-[11px] font-semibold outline outline-1 -outline-offset-1 outline-line">↓</kbd>
                pindah usulan · arahkan ke baris untuk menyalakan titiknya di peta
            </div>
        </section>
    </div>
@else
    <div class="lg:grid lg:h-[calc(100dvh-4rem)] {{ $bappeda ? 'lg:grid-cols-[minmax(0,1fr)_480px]' : 'lg:grid-cols-[440px_minmax(0,1fr)]' }}">
        {{-- ── Daftar / peta panel kiri ── --}}
        <section @class(['flex min-h-0 flex-col bg-bg', 'border-r border-line' => ! $bappeda, 'hidden lg:flex' => $pilih])>
        @if ($pengusul)
            @php
                $revisiList = $this->daftar->where('status', \App\Enums\StatusUsulan::Revisi);
                $lainnya = $this->daftar->where('status', '!=', \App\Enums\StatusUsulan::Revisi);
            @endphp
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
                <x-kategori aktif="usulan" />
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
                {{-- Memuat: kerangka kartu saat ganti tab / mencari --}}
                <div wire:loading.delay.flex wire:target="gantiTab,cari,kecamatan,jenis,urut,hapusFilter" class="hidden flex-col gap-3" aria-label="Memuat daftar">
                    @foreach ([1, 2, 3, 4] as $_)
                        <div class="flex gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line"><span class="kerangka size-14 shrink-0"></span><span class="flex flex-1 flex-col justify-center gap-2"><span class="kerangka h-2.5 w-1/3"></span><span class="kerangka h-3 w-4/5"></span><span class="kerangka h-2.5 w-1/2"></span></span></div>
                    @endforeach
                </div>
                <div wire:loading.delay.remove wire:target="gantiTab,cari,kecamatan,jenis,urut,hapusFilter" class="contents">
                @foreach ($revisiList as $u)
                    <x-usulan.kartu-revisi :u="$u" wire:key="rv-{{ $u->id }}" />
                @endforeach
                @if ($revisiList->isNotEmpty() && $lainnya->isNotEmpty())
                    <div class="flex items-center justify-between pt-2 font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted"><span>Usulan lainnya</span><span>{{ $lainnya->count() }}</span></div>
                @endif
                @forelse ($lainnya as $u)
                    <x-usulan.kartu-pengusul :u="$u" :aktif="$pilih === $u->id" wire:key="kp-{{ $u->id }}" />
                @empty
                    @if ($revisiList->isEmpty())
                        <x-kosong :ikon="$keadaanKosong[0]" :nada="$keadaanKosong[1]" :judul="$keadaanKosong[2]" :teks="$keadaanKosong[3]" class="py-16">
                            @if ($keadaanKosong[4] === 'ajukan') <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Ajukan Usulan @svg('lucide-camera', 'size-4')</a> @endif
                        </x-kosong>
                    @endif
                @endforelse
                @if ($tab === 'aktif' && ($n = $this->jumlahTab['selesai'] ?? 0))
                    <button wire:click="gantiTab('selesai')" class="py-1 text-center font-caption text-[13px] text-fg-muted">{{ $n }} usulan sudah selesai · lihat di tab Selesai</button>
                @endif
                </div>
            </div>
        @elseif ($bappeda && $mode === 'daftar')
            @php
                $statusPilih = $tab === 'ditunda' ? \App\Enums\StatusItem::Ditunda : \App\Enums\StatusItem::Lolos;
                $semuaPilihan = $this->daftar->flatMap(fn ($u) => $u->items->where('status', $statusPilih)->pluck('id'));
                $kotak = function (int $pilih, int $total) {
                    if ($total && $pilih === $total) return ['bg-accent text-fg-inverse', 'check'];
                    if ($pilih) return ['bg-accent text-fg-inverse', 'minus'];
                    return ['bg-bg outline outline-[1.5px] -outline-offset-1 outline-[#B8BDD0]', null];
                };
            @endphp
            <div class="flex flex-col gap-[18px] px-8 pt-6">
                <div class="flex flex-wrap items-end gap-6">
                    <div class="flex min-w-60 flex-1 flex-col gap-1.5">
                        <span class="font-caption text-xs font-semibold uppercase tracking-[1.2px] text-accent">Tahap keputusan · Bappeda</span>
                        <h1 class="judul text-[36px] leading-[38px]">Keputusan usulan</h1>
                        <span class="max-w-[460px] text-sm text-fg-muted">{{ [
                            'ditunda' => 'Item yang ditunda menunggu pagu tahun target. Pilih untuk disetujui, ditunda lagi, atau ditolak.',
                            'semua' => 'Semua usulan dan keputusannya. Keputusan bisa diubah selama item belum terpasang.',
                        ][$tab] ?? 'Pilih item per usulan untuk disetujui atau ditunda. Diurutkan menurut skor pemeriksaan Dishub.' }}</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <label class="flex h-[38px] w-[230px] items-center gap-2 rounded-sm px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                            @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                            <input wire:model.live.debounce.300ms="cari" class="w-full bg-transparent font-caption text-[13px] outline-none placeholder:text-fg-muted" placeholder="Cari ruas jalan / no. usulan">
                        </label>
                        <div class="flex gap-0.5 rounded-sm bg-surface-muted p-[3px] font-caption text-[13px]">
                            @foreach (['daftar' => ['list', 'Daftar'], 'peta' => ['map', 'Peta']] as $m => [$ikon, $label])
                                <button wire:click="$set('mode', '{{ $m }}')" @class(['flex items-center gap-1.5 rounded-sm px-3 py-[7px]', 'bg-bg font-semibold shadow-sm' => $mode === $m, 'font-medium text-fg-muted' => $mode !== $m])>@svg('lucide-'.$ikon, 'size-[15px]') {{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                @if ($tab === 'ditunda')
                    @php
                        $terpakai = $this->totalTahun;
                        $pagu = $this->pagu;
                        $tambah = $itemDipilih ? $this->ringkasanPilihan['total'] : 0;
                        $persen = $pagu ? min(100, round($terpakai / $pagu * 100)) : 0;
                        $persenTambah = $pagu ? min(100 - $persen, round($tambah / $pagu * 100, 1)) : 0;
                        $sisa = $pagu ? $pagu - $terpakai - $tambah : null;
                    @endphp
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-md bg-surface-muted px-4 py-3.5" x-data="{ ubah: false }">
                        <div class="flex flex-col gap-1">
                            <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">Disetujui {{ $tahun }}</span>
                            <span class="flex items-end gap-2">
                                <span class="judul text-2xl normal-case leading-6">{{ \App\Support\Rekap::rupiahPendek($terpakai) }}</span>
                                <span x-show="! ubah" class="pb-0.5 font-caption text-[13px] text-fg-muted">dari
                                    <button @click="ubah = true; $nextTick(() => $refs.pagu.select())" class="underline decoration-dotted underline-offset-2 hover:text-accent" title="Ubah pagu {{ $tahun }}">{{ $pagu ? 'pagu '.\App\Support\Rekap::rupiahPendek($pagu) : 'atur pagu '.$tahun }}</button>
                                </span>
                                <span x-show="ubah" x-cloak class="flex items-center gap-1 pb-0.5 font-caption text-[13px]">
                                    pagu Rp <input x-ref="pagu" value="{{ $pagu ? number_format($pagu, 0, ',', '.') : '' }}" inputmode="numeric" class="w-36 rounded-sm bg-bg px-2 py-0.5 outline outline-1 outline-accent"
                                        @keydown.enter="$wire.aturPagu($el.value); ubah = false" @keydown.escape="ubah = false" @blur="ubah = false">
                                </span>
                            </span>
                        </div>
                        <div class="flex min-w-[240px] flex-1 flex-col gap-2">
                            <div class="flex h-1.5 overflow-hidden rounded-full bg-line">
                                <span class="bg-accent" style="width: {{ $persen }}%"></span>
                                <span class="bg-accent/35" style="width: {{ $persenTambah }}%"></span>
                            </div>
                            <div class="flex flex-wrap items-center gap-4 font-caption text-xs text-fg-muted">
                                <span class="flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-accent"></span>{{ $pagu ? $persen.'% terpakai' : 'Pagu '.$tahun.' belum diatur' }}</span>
                                @if ($tambah) <span class="flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-accent/35"></span>+ {{ \App\Support\Rekap::rupiahPendek($tambah) }} jika pilihan disetujui</span> @endif
                            </div>
                        </div>
                        @if ($pagu)
                            <div class="flex flex-col items-end gap-1">
                                <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">Sisa pagu</span>
                                <span @class(['judul text-2xl normal-case leading-6', 'text-success' => $sisa >= 0, 'text-danger' => $sisa < 0])>{{ \App\Support\Rekap::rupiahPendek($sisa) }}</span>
                            </div>
                        @endif
                    </div>
                @endif
                <div class="flex items-end gap-1 border-b border-line font-caption text-sm">
                    @foreach ($this->daftarTab() as $k => [$label])
                        <button wire:click="gantiTab('{{ $k }}')" @class(['-mb-px flex items-center gap-2 border-b-2 px-3.5 pb-3 pt-2.5', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted hover:text-fg' => $tab !== $k])>
                            {{ $label }}
                            @if ($k !== 'semua') <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ $this->jumlahTab[$k] }}</span> @endif
                        </button>
                    @endforeach
                    <span class="flex-1"></span>
                    <label class="flex items-center gap-1.5 px-1 pb-3 font-caption text-[13px]">
                        <span class="text-fg-muted">Urutkan:</span>
                        <select wire:model.live="urut" class="bg-transparent font-semibold outline-none">
                            @foreach (array_merge($tab === 'ditunda' ? ['target' => 'Target tahun terdekat'] : [], $tab === 'semua' ? ['diputuskan' => 'Terbaru diputuskan'] : [], ['skor' => 'Skor tertinggi', 'lama' => 'Terlama', 'baru' => 'Terbaru']) as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>
            @if ($tab === 'ditunda')
                @php
                    $itemTunda = $this->daftar->flatMap(fn ($u) => $u->items->where('status', \App\Enums\StatusItem::Ditunda)->each(fn ($i) => $i->setRelation('usulan', $u)));
                    $itemTunda = match ($urut) {
                        'skor' => $itemTunda->sortByDesc(fn ($i) => $i->usulan->skor),
                        'lama' => $itemTunda->sortBy('diputuskan_at'),
                        default => $itemTunda->sortByDesc('diputuskan_at'),
                    };
                    $kelompok = $itemTunda->groupBy('tahun_target')->sortKeys();
                    [$k, $ik] = $kotak($semuaPilihan->intersect($itemDipilih)->count(), $semuaPilihan->count());
                @endphp
                <div class="flex items-center gap-4 px-[49px] pb-2 pt-3.5 font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">
                    <button wire:click="pilihSemua" @disabled($semuaPilihan->isEmpty()) class="grid size-[18px] shrink-0 place-items-center rounded-sm {{ $k }} disabled:opacity-30" aria-label="Pilih semua">@if ($ik) @svg('lucide-'.$ik, 'size-[13px]') @endif</button>
                    <span class="ml-7 flex-1">Item · usulan</span>
                    <span class="w-[190px]">Alasan ditunda</span>
                    <span class="w-[80px]">Jumlah</span>
                    <span class="w-[130px] text-right">Estimasi biaya</span>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-8 pb-4">
                    @forelse ($kelompok as $target => $daftarItem)
                        @php
                            $idK = $daftarItem->pluck('id');
                            [$kk, $ikk] = $kotak($idK->intersect($itemDipilih)->count(), $idK->count());
                        @endphp
                        <div class="flex shrink-0 flex-col gap-2" wire:key="target-{{ $target }}">
                            <div class="flex items-center gap-3 px-4">
                                <button wire:click="pilihTarget({{ (int) $target }})" class="grid size-[18px] shrink-0 place-items-center rounded-sm {{ $kk }}" aria-label="Pilih semua target {{ $target }}">@if ($ikk) @svg('lucide-'.$ikk, 'size-[13px]') @endif</button>
                                <span class="text-warning">@svg('lucide-calendar-clock', 'size-[18px]')</span>
                                <span class="judul text-[22px] leading-6">Target {{ $target ?: '—' }}</span>
                                <span class="flex-1 font-caption text-[13px] text-fg-muted">· {{ $daftarItem->count() }} item</span>
                                <span class="font-caption text-xs text-fg-muted">Subtotal</span>
                                <x-rupiah class="w-[130px] text-right text-[15px] font-bold" :nilai="$daftarItem->sum(fn ($i) => $i->estimasi())" />
                            </div>
                            <div class="flex flex-col overflow-hidden rounded-md bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line">
                                @foreach ($daftarItem as $i)
                                    @php $on = in_array($i->id, $itemDipilih); $aktif = $pilih === $i->usulan_id; @endphp
                                    <div wire:key="td-{{ $i->id }}" @class(['flex items-center gap-4 px-4 py-3', 'border-t border-line' => ! $loop->first, 'bg-accent-soft/70' => $on, 'bg-[#F7F8FC]' => $aktif && ! $on])>
                                        <label class="grid cursor-pointer place-items-center">
                                            <input type="checkbox" wire:model.live="itemDipilih" value="{{ $i->id }}" class="peer sr-only">
                                            <span @class(['grid size-[18px] place-items-center rounded-sm', 'bg-accent text-fg-inverse' => $on, 'bg-bg outline outline-[1.5px] -outline-offset-1 outline-[#B8BDD0]' => ! $on])>@if ($on) @svg('lucide-check', 'size-[13px]') @endif</span>
                                        </label>
                                        <button wire:click="pilihUsulan({{ $i->usulan_id }})" class="flex min-w-0 flex-1 items-center gap-4 text-left">
                                            <span class="flex min-w-0 flex-1 flex-col gap-0.5 pl-7">
                                                <span class="truncate text-[15px] font-medium">{{ $i->jenis->nama }}</span>
                                                <span class="truncate font-caption text-xs text-fg-muted">{{ $i->usulan->nomor }} · {{ $i->usulan->alamat ?: $i->usulan->lokasiSingkat() }}</span>
                                            </span>
                                            <span class="flex w-[190px] flex-col gap-0.5">
                                                <span class="flex items-center gap-1.5 font-caption text-[13px] font-semibold text-warning"><span class="size-1.5 shrink-0 rounded-full bg-warning"></span><span class="truncate">{{ $i->catatan_bappeda ?: 'Tanpa alasan' }}</span></span>
                                                <span class="truncate font-caption text-[11px] text-fg-muted">Ditunda {{ $i->diputuskan_at?->translatedFormat('j M Y') }}{{ $i->pemutus ? ' · '.str($i->pemutus->name)->explode(' ')->first().' '.str($i->pemutus->name)->explode(' ')->skip(1)->map(fn ($k) => mb_substr($k, 0, 1).'.')->implode(' ') : '' }}</span>
                                            </span>
                                            <span class="w-[80px] font-caption text-[13px]">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }}</span>
                                            <x-rupiah @class(['w-[130px] text-right text-[15px]', 'font-bold' => $on, 'font-medium' => ! $on]) :nilai="$i->estimasi()" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-3 p-10 text-center text-fg-muted">@svg('lucide-calendar-check', 'size-10') Tidak ada item yang ditunda.</div>
                    @endforelse
                </div>
            @else
            <div class="flex items-center gap-3.5 px-[49px] pb-2 pt-3.5 font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">
                @php [$k, $ik] = $kotak($semuaPilihan->intersect($itemDipilih)->count(), $semuaPilihan->count()); @endphp
                <button wire:click="pilihSemua" @disabled($semuaPilihan->isEmpty()) class="grid size-[18px] shrink-0 place-items-center rounded-sm {{ $k }} disabled:opacity-30" aria-label="Pilih semua">@if ($ik) @svg('lucide-'.$ik, 'size-[13px]') @endif</button>
                <span class="w-4"></span>
                <span class="flex-1">Usulan · lokasi</span>
                <span class="w-[110px]">Skor</span>
                <span class="w-[90px]">{{ $tab === 'semua' ? 'Status' : 'Item' }}</span>
                <span class="w-[150px] text-right">Estimasi biaya</span>
            </div>
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-8 pb-4">
                @forelse ($this->daftar as $u)
                    @php
                        $bisa = $u->items->where('status', $statusPilih);
                        $dipilih = $bisa->whereIn('id', $itemDipilih);
                        [$k, $ik] = $kotak($dipilih->count(), $bisa->count());
                        $aktif = $pilih === $u->id;
                        $biaya = $dipilih->isNotEmpty() ? $dipilih->sum->estimasi() : ($bisa->isNotEmpty() ? $bisa->sum->estimasi() : $u->totalEstimasi());
                    @endphp
                    <div wire:key="bp-{{ $u->id }}" x-data="{ buka: @js($aktif || $dipilih->isNotEmpty()) }"
                         @class(['flex shrink-0 flex-col overflow-hidden rounded-md bg-bg shadow-sm outline -outline-offset-1', 'outline-[1.5px] outline-accent' => $aktif, 'outline-1 outline-line' => ! $aktif])>
                        <div @class(['flex items-center gap-3.5 px-4 py-3.5', 'bg-[#F7F8FC]' => $aktif])>
                            <button wire:click="pilihSemua({{ $u->id }})" @disabled($bisa->isEmpty()) class="grid size-[18px] shrink-0 place-items-center rounded-sm {{ $k }} disabled:opacity-30" aria-label="Pilih semua item usulan ini">@if ($ik) @svg('lucide-'.$ik, 'size-[13px]') @endif</button>
                            <button @click="buka = !buka" class="text-fg-muted" aria-label="Buka item"><span :class="buka ? '' : '-rotate-90'" class="block transition">@svg('lucide-chevron-down', 'size-4')</span></button>
                            <button wire:click="pilihUsulan({{ $u->id }})" @click="buka = true" class="flex min-w-0 flex-1 items-center gap-3.5 text-left">
                                <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                                    <span @class(['font-caption text-[11px] font-semibold tracking-[0.4px]', 'text-accent' => $aktif, 'text-fg-muted' => ! $aktif])>{{ $u->nomor }}</span>
                                    <span class="truncate text-[15px] font-semibold">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
                                    <span class="truncate font-caption text-xs text-fg-muted">{{ $u->kecamatan ? 'Kec. '.$u->kecamatan->nama : '—' }} · {{ $u->jenisPengusul() }}{{ in_array('sekolah', $u->kriteria_dishub ?? []) ? ' · Zona sekolah' : '' }}</span>
                                </span>
                                <span class="flex w-[110px] items-center gap-2">
                                    <span class="flex gap-1">@for ($n = 1; $n <= 5; $n++)<span @class(['size-2 rounded-full', 'bg-accent' => $n <= ($u->skor ?? 0), 'bg-line' => $n > ($u->skor ?? 0)])></span>@endfor</span>
                                    <span class="font-caption text-[13px] font-semibold">{{ number_format($u->skor ?? 0, 1, ',', '') }}</span>
                                </span>
                                @if ($tab === 'semua')
                                    <span class="w-[90px]"><x-status :status="$u->status" /></span>
                                @else
                                    <span @class(['w-[90px] font-caption text-[13px]', 'font-semibold text-accent' => $dipilih->isNotEmpty(), 'text-fg-muted' => $dipilih->isEmpty()])>{{ $dipilih->isNotEmpty() ? $dipilih->count().'/'.$u->items->count().' dipilih' : $u->items->count().' item' }}</span>
                                @endif
                                <x-rupiah class="w-[150px] text-right text-sm font-semibold" :nilai="$biaya" />
                            </button>
                        </div>
                        <div x-show="buka" x-collapse class="flex flex-col border-t border-line bg-surface-muted px-4 py-1">
                            @foreach ($u->items as $i)
                                @php $bisaI = $i->status === $statusPilih; $on = in_array($i->id, $itemDipilih); $tolak = in_array($i->status, [\App\Enums\StatusItem::DitolakDishub, \App\Enums\StatusItem::DitolakBappeda]); @endphp
                                <label @class(['flex items-center gap-3.5 py-2.5 pl-[30px]', 'border-t border-line' => ! $loop->first, 'cursor-pointer' => $bisaI])>
                                    @if ($bisaI)
                                        <input type="checkbox" wire:model.live="itemDipilih" value="{{ $i->id }}" class="peer sr-only">
                                        <span @class(['grid size-[18px] shrink-0 place-items-center rounded-sm', 'bg-accent text-fg-inverse' => $on, 'bg-bg outline outline-[1.5px] -outline-offset-1 outline-[#B8BDD0]' => ! $on])>@if ($on) @svg('lucide-check', 'size-[13px]') @endif</span>
                                    @elseif ($tolak)
                                        <span class="grid size-[18px] shrink-0 place-items-center rounded-sm bg-danger-bg text-danger">@svg('lucide-x', 'size-3')</span>
                                    @else
                                        <span class="grid size-[18px] shrink-0 place-items-center rounded-sm bg-success-bg text-success">@svg('lucide-check', 'size-3')</span>
                                    @endif
                                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span @class(['text-sm font-medium', 'text-fg-muted' => ! $bisaI])>{{ $i->jenis->namaPendek() }}</span>
                                        @if ($tolak)
                                            <span class="font-caption text-xs text-danger">Ditolak · {{ $i->catatan_bappeda ?? $i->catatan_dishub ?? 'tidak direkomendasikan' }}</span>
                                        @elseif (! $bisaI)
                                            <span class="font-caption text-xs text-fg-muted">{{ $i->status->label() }}{{ $i->tahun_anggaran ? ' · anggaran '.$i->tahun_anggaran : '' }}</span>
                                        @else
                                            <span class="truncate font-caption text-xs text-fg-muted">{{ $i->jenis->deskripsi }}{{ $i->status === \App\Enums\StatusItem::Ditunda && $i->tahun_target ? ' · ditunda ke '.$i->tahun_target : '' }}</span>
                                        @endif
                                    </span>
                                    <span class="w-[110px]"></span>
                                    <span @class(['w-[90px] font-caption text-[13px]', 'text-fg-muted' => ! $bisaI])>{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }}</span>
                                    <x-rupiah @class(['w-[150px] text-right text-sm', 'text-fg-muted' => ! $bisaI]) :nilai="$i->estimasi()" />
                                </label>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-kosong :ikon="$keadaanKosong[0]" :nada="$keadaanKosong[1]" :judul="$keadaanKosong[2]" :teks="$keadaanKosong[3]">
                            @switch ($keadaanKosong[4])
                                @case('ajukan') <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Ajukan Usulan @svg('lucide-camera', 'size-4')</a> @break
                                @case('pasang') <button wire:click="gantiTab('pasang')" class="flex items-center gap-1.5 text-sm font-semibold text-accent">Lihat Siap Dipasang ({{ $this->jumlahTab['pasang'] }}) @svg('lucide-arrow-right', 'size-4')</button> @break
                                @case('filter') <button wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus filter</button> @break
                            @endswitch
                        </x-kosong>
                @endforelse
            </div>
            @endif
            @if ($itemDipilih)
                <div class="px-8 pb-5">
                    <div class="flex flex-wrap items-center gap-5 rounded-md bg-fg py-3 pl-5 pr-3 text-fg-inverse shadow-lg">
                        <span class="flex items-center gap-2.5"><span class="grid size-7 place-items-center rounded-full bg-accent font-caption text-[13px] font-bold">{{ count($itemDipilih) }}</span><span class="text-sm font-medium">item dipilih</span></span>
                        <span class="h-7 w-px bg-fg-inverse/20"></span>
                        <span class="flex flex-col gap-px"><span class="font-caption text-[11px] text-fg-inverse/65">Total estimasi</span><x-rupiah class="judul text-xl normal-case tracking-[0.3px]" :nilai="$this->ringkasanPilihan['total']" /></span>
                        <span class="h-7 w-px bg-fg-inverse/20"></span>
                        <label class="flex flex-col gap-[3px]"><span class="font-caption text-[11px] text-fg-inverse/65">Tahun anggaran</span>
                            <select wire:model.live="tahun" class="bg-transparent text-sm font-semibold outline-none">@foreach (range(now()->year, now()->year + 3) as $t)<option class="text-fg">{{ $t }}</option>@endforeach</select>
                        </label>
                        <span class="flex-1"></span>
                        @if ($tab === 'ditunda')
                            <button wire:click="$set('dialogMassal', 'tolak')" class="flex items-center gap-2 rounded-sm px-[18px] py-[11px] text-[15px] font-semibold text-[#FF8A8A] outline outline-1 -outline-offset-1 outline-[#FF8A8A]/40 hover:bg-[#FF8A8A]/10">@svg('lucide-x', 'size-4') Tolak</button>
                            <button wire:click="$set('dialogMassal', 'tunda')" class="flex items-center gap-2 rounded-sm px-[18px] py-[11px] text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-fg-inverse/35 hover:bg-fg-inverse/10">@svg('lucide-hourglass', 'size-4') Tunda lagi</button>
                        @else
                            <button wire:click="$set('itemDipilih', [])" class="font-caption text-[13px] text-fg-inverse/65 hover:text-fg-inverse">Batal pilih</button>
                            <button wire:click="$set('dialogMassal', 'tolak')" class="font-caption text-[13px] text-fg-inverse/65 hover:text-fg-inverse">Tolak</button>
                            <button wire:click="$set('dialogMassal', 'tunda')" class="flex items-center gap-2 rounded-sm px-[18px] py-[11px] text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-fg-inverse/35 hover:bg-fg-inverse/10">@svg('lucide-hourglass', 'size-4') Tunda</button>
                        @endif
                        <button wire:click="putuskanMassal('setujui')" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold shadow-sm hover:brightness-110">Setujui {{ count($itemDipilih) }} item @svg('lucide-check', 'size-[18px]')</button>
                    </div>
                </div>
            @endif
        @else
            <x-usulan.kepala-daftar :judul="$kepala['judul']" :tabs="$kepala['tabs']" :jumlah="$kepala['jumlah']" :tab="$tab" :mode="$mode" :urut="$urut" :semua-kecamatan="$semuaKecamatan" :semua-jenis="$semuaJenis" :kecamatan="$kecamatan" :jenis="$jenis" :bisa-saring="! $pengusul" />

            @if ($tabItemBappeda)
                <div class="mx-6 mb-3 flex items-center justify-between rounded-sm bg-surface-muted px-3 py-2 text-sm">
                    <span>Disetujui untuk {{ $tahun }}</span>
                    <x-rupiah class="font-semibold" :nilai="$this->totalTahun" />
                </div>
            @endif

            @if ($mode === 'peta')
                <div class="relative min-h-[60dvh] flex-1 border-t border-line" wire:key="peta-panel-{{ md5(json_encode(array_column($this->titik, 'id'))) }}"
                     x-data="petaUsulan(@js($this->titik), @js(config('usuljalan.peta_awal')), { penuh: false, pilih: @js($pilih) })">
                    <div x-ref="peta" class="peta-redup absolute inset-0" wire:ignore></div>
                    <div x-show="{{ count($this->titik) ? 'false' : 'true' }} || $store.peta.ids?.length === 0" x-cloak class="pointer-events-none absolute inset-0 z-[600] grid place-items-center p-4">
                        <div class="pointer-events-auto flex flex-col items-center gap-3 rounded-md bg-bg px-6 py-5 text-center shadow-lg">
                            @svg('lucide-map-pin-off', 'size-6 text-fg-muted')
                            <span class="text-[15px] font-semibold">Tidak ada usulan di area ini</span>
                            <div class="flex gap-2 font-caption text-[13px] font-semibold">
                                @if (count($this->titik)) <button type="button" @click="peta.zoomOut(2)" class="flex items-center gap-1.5 rounded-sm px-3 py-2 outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-zoom-out', 'size-3.5') Perkecil peta</button> @endif
                                @if ($adaFilter) <button type="button" wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-3.5') Hapus filter</button> @endif
                            </div>
                        </div>
                    </div>
                    <div class="absolute inset-x-4 top-4 z-[500] flex items-center gap-2 font-caption text-[13px]">
                        <span class="flex items-center gap-2 rounded-sm bg-bg px-3 py-2 font-semibold shadow-sm">@svg('lucide-map-pin', 'size-3.5')
                            <span x-text="'{{ count($this->titik) }} usulan' + ($store.peta.ids ? ' · ' + $store.peta.ids.length + ' di area ini' : '')"></span></span>
                        <span class="flex-1"></span>
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-sm bg-bg px-3 py-2 shadow-sm">
                            <input type="checkbox" x-model="$store.peta.saring" class="size-3.5 accent-accent"> Filter saat digeser
                        </label>
                    </div>
                    <div class="absolute left-4 top-[62px] z-[500] flex items-center gap-3 rounded-sm bg-bg/90 px-2.5 py-1.5 font-caption text-xs">
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
                    {{-- Memuat: kerangka kartu saat ganti tab / mencari --}}
                <div wire:loading.delay.flex wire:target="gantiTab,cari,kecamatan,jenis,urut,hapusFilter" class="hidden flex-col gap-3 p-4" aria-label="Memuat daftar">
                    @foreach ([1, 2, 3, 4] as $_)
                        <div class="flex gap-3 rounded-md bg-bg p-3.5 outline outline-1 -outline-offset-1 outline-line"><span class="kerangka size-14 shrink-0"></span><span class="flex flex-1 flex-col justify-center gap-2"><span class="kerangka h-2.5 w-1/3"></span><span class="kerangka h-3 w-4/5"></span><span class="kerangka h-2.5 w-1/2"></span></span></div>
                    @endforeach
                </div>
                <div wire:loading.delay.remove wire:target="gantiTab,cari,kecamatan,jenis,urut,hapusFilter" class="contents">
                    @forelse ($this->daftar as $u)
                        @if ($tabItemBappeda)
                            <div @class(['border-b border-line p-4', 'bg-accent-soft/60' => $pilih === $u->id]) wire:key="u-{{ $u->id }}">
                                <button wire:click="pilihUsulan({{ $u->id }})" class="flex w-full items-start gap-3 text-left">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-caption text-xs font-semibold text-accent">{{ $u->nomor }}</div>
                                        <div class="truncate font-semibold">{{ $u->alamat ?: $u->lokasiSingkat() }}</div>
                                        <div class="text-sm text-fg-muted">{{ $u->lokasiSingkat() }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="judul text-2xl">{{ $u->skor }}<span class="text-sm text-fg-muted">/5</span></div>
                                        <x-rupiah class="text-sm" :nilai="$u->totalEstimasi(fn ($i) => $i->status === $statusItemTab)" />
                                    </div>
                                </button>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($u->items->where('status', $statusItemTab) as $i)
                                        <li>
                                            <label class="flex cursor-pointer items-center gap-3 rounded-sm px-2 py-1.5 hover:bg-bg">
                                                <input type="checkbox" wire:model.live="itemDipilih" value="{{ $i->id }}" class="size-4 accent-accent">
                                                <span class="flex-1 text-sm">{{ $i->jenis->nama }} <span class="text-fg-muted">· {{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }}</span></span>
                                                <x-rupiah class="text-sm" :nilai="$i->estimasi()" />
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <x-usulan.baris :u="$u" :aktif="$pilih === $u->id" :pengusul="$pengusul" wire:key="u-{{ $u->id }}" />
                        @endif
                    @empty
                        <x-kosong :ikon="$keadaanKosong[0]" :nada="$keadaanKosong[1]" :judul="$keadaanKosong[2]" :teks="$keadaanKosong[3]">
                            @switch ($keadaanKosong[4])
                                @case('ajukan') <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Ajukan Usulan @svg('lucide-camera', 'size-4')</a> @break
                                @case('pasang') <button wire:click="gantiTab('pasang')" class="flex items-center gap-1.5 text-sm font-semibold text-accent">Lihat Siap Dipasang ({{ $this->jumlahTab['pasang'] }}) @svg('lucide-arrow-right', 'size-4')</button> @break
                                @case('filter') <button wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus filter</button> @break
                            @endswitch
                        </x-kosong>
                    @endforelse
                    </div>
                </div>
            @endif
        @endif

        {{-- Bar keputusan massal Bappeda --}}
        @if ($bappeda && $itemDipilih && $mode === 'peta')
            <div class="sticky bottom-0 flex flex-wrap items-center gap-3 bg-fg p-3 text-fg-inverse">
                <div class="flex-1">
                    <div class="text-sm"><b>{{ count($itemDipilih) }}</b> item dipilih · <x-rupiah class="font-semibold" :nilai="$this->ringkasanPilihan['total']" /></div>
                    <div class="truncate text-xs text-fg-inverse/60">{{ $this->ringkasanPilihan['per_jenis']->map(fn ($v, $k) => "$k $v")->implode(' · ') }}</div>
                </div>
                <select wire:model.live="tahun" class="rounded-sm bg-fg-inverse/10 px-2 py-1.5 text-sm">
                    @foreach (range(now()->year, now()->year + 3) as $t) <option class="text-fg">{{ $t }}</option> @endforeach
                </select>
                <button wire:click="$set('itemDipilih', [])" class="text-sm text-fg-inverse/70">Batal pilih</button>
                <button wire:click="$set('dialogMassal', 'tunda')" class="rounded-sm border border-fg-inverse/30 px-3 py-1.5 text-sm">Tunda</button>
                <button wire:click="$set('dialogMassal', 'tolak')" class="rounded-sm border border-fg-inverse/30 px-3 py-1.5 text-sm">Tolak</button>
                <button wire:click="putuskanMassal('setujui')" class="rounded-sm bg-accent px-4 py-1.5 text-sm font-semibold">Setujui ({{ count($itemDipilih) }})</button>
            </div>
        @endif
        </section>

        {{-- ── Detail ── --}}
        <section @class(['relative min-h-0 overflow-y-auto', 'bg-bg border-l border-line' => $bappeda, 'bg-surface-muted' => ! $bappeda, 'hidden lg:block' => ! $pilih])
                 x-data="{ gagal: false, ulang: null }" @lw-gagal.window="if ($event.detail.pilih) { ulang = $event.detail.ulang; gagal = true }" @lw-berhasil.window="gagal = false">
            {{-- Memuat: kerangka panel detail saat berpindah usulan --}}
            <div wire:loading.delay.flex wire:target="pilihUsulan,pindah" class="absolute inset-0 z-[900] hidden flex-col gap-4 bg-inherit p-6 lg:px-8" aria-label="Memuat detail">
                <div class="flex gap-2.5"><span class="kerangka h-3.5 w-28"></span><span class="kerangka h-5 w-[88px]"></span></div>
                <span class="kerangka h-6 w-2/5"></span>
                <div class="grid h-[172px] grid-cols-2 gap-3"><span class="kerangka h-full"></span><span class="kerangka h-full"></span></div>
                @foreach ([1, 2, 3] as $_)
                    <div class="flex items-center gap-3"><span class="kerangka size-7"></span><span class="kerangka h-3 flex-1"></span><span class="kerangka h-3 w-20"></span></div>
                @endforeach
            </div>
            {{-- Galat: detail gagal dimuat (koneksi) --}}
            <div x-show="gagal" x-cloak class="absolute inset-0 z-[901] grid place-items-center bg-inherit p-6">
                <x-kosong ikon="cloud-off" nada="bahaya" judul="Detail usulan gagal dimuat" teks="Periksa koneksi, lalu coba lagi. Daftar di kiri tetap bisa dipakai." class="w-full max-w-[560px] rounded-md bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line">
                    <button type="button" @click="gagal = false; ulang()" class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Coba lagi @svg('lucide-rotate-cw', 'size-4')</button>
                </x-kosong>
            </div>
            @if ($pilih)
                <button wire:click="$set('pilih', null)" @class(['flex w-full items-center gap-2 border-b border-line bg-bg px-4 py-3 text-sm font-medium lg:hidden', 'hidden' => $pengusul])>@svg('lucide-arrow-left', 'size-4') Kembali ke daftar</button>
                <livewire:usulan.detail :usulan-id="$pilih" :posisi="$posisi" :total="$ids->count()" :pilihan="$itemDipilih" :key="'detail-'.$pilih" />
            @else
                <div class="grid h-full place-items-center p-10 text-center text-fg-muted">
                    <div>@svg('lucide-mouse-pointer-click', 'mx-auto size-10')<p class="mt-3">Pilih usulan di daftar untuk melihat detail.</p></div>
                </div>
            @endif
        </section>
    </div>
@endif

</div>

    @if ($dialogMassal)
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialogMassal', null)">
            <div class="w-full rounded-t-xl bg-bg p-5 sm:max-w-md sm:rounded-md">
                <h3 class="judul text-2xl">{{ $dialogMassal === 'tunda' ? 'Tunda ke '.($tahun + 1) : 'Tolak' }} {{ count($itemDipilih) }} item</h3>
                <textarea wire:model="alasanMassal" rows="3" class="input mt-4" placeholder="Alasan (wajib)"></textarea>
                @error('alasanMassal') <div class="mt-1 text-sm text-danger">{{ $message }}</div> @enderror
                <div class="mt-4 flex justify-end gap-2">
                    <x-tombol variant="teks" wire:click="$set('dialogMassal', null)">Batal</x-tombol>
                    <x-tombol :variant="$dialogMassal === 'tolak' ? 'bahaya' : 'utama'" wire:click="putuskanMassal('{{ $dialogMassal }}')">{{ $dialogMassal === 'tunda' ? 'Tunda' : 'Tolak' }}</x-tombol>
                </div>
            </div>
        </div>
    @endif
</div>

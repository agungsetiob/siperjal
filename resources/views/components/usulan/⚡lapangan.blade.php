<?php

use App\Enums\Role;
use App\Enums\StatusItem as SI;
use App\Models\UsulanItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Mode lapangan Dishub (HP): peta rute titik "Siap Dipasang", kartu per titik, sheet Tandai Terpasang.
 */
new class extends Component
{
    use WithFileUploads;

    public ?int $itemSheet = null;
    public ?int $itemHasil = null;
    public $foto = [];
    public array $fotoKoordinat = [];
    public int $jumlah = 1;
    public ?int $noTitik = null; // urutan di rute (dihitung di klien)
    public string $keterangan = '';

    #[Computed]
    public function titik()
    {
        return UsulanItem::with('jenis', 'usulan.desa', 'usulan.kecamatan', 'usulan.foto')
            ->where('status', SI::Disetujui)->get()
            ->filter(fn ($i) => $i->usulan->lat)
            ->map(fn ($i) => [
                'id' => $i->id,
                'lat' => $i->usulan->lat,
                'lng' => $i->usulan->lng,
                'nomor' => $i->usulan->nomor,
                'lokasi' => $i->usulan->alamat ?: $i->usulan->lokasiSingkat(),
                'wilayah' => collect([$i->usulan->desa ? 'Ds. '.$i->usulan->desa->nama : null, $i->usulan->kecamatan ? 'Kec. '.$i->usulan->kecamatan->nama : null])->filter()->implode(' · '),
                'kec' => $i->usulan->kecamatan ? 'Kec. '.$i->usulan->kecamatan->nama : '',
                'spec' => $i->jenis->deskripsi,
                'foto' => $i->usulan->foto->first()?->url(),
                'jenis' => $i->jenis->namaPendek(),
                'satuan' => $i->jenis->satuan,
                'jumlah' => $i->jumlahEfektif(),
                'tahun' => $i->tahun_anggaran,
                'ikon' => svg('lucide-'.$i->jenis->ikon, 'size-full')->toHtml(),
            ])->values()->all();
    }

    #[Computed]
    public function selesaiHariIni(): int
    {
        return UsulanItem::where('status', SI::Terpasang)->whereDate('terpasang_at', today())->count();
    }

    #[Computed]
    public function item(): ?UsulanItem
    {
        return $this->itemSheet ? UsulanItem::with('jenis', 'usulan')->find($this->itemSheet) : null;
    }

    public function buka(int $id, ?int $no = null): void
    {
        $item = UsulanItem::findOrFail($id);
        abort_unless(auth()->user()->hasRole(Role::Dishub) && $item->status === SI::Disetujui, 403);
        $this->reset('foto', 'fotoKoordinat', 'keterangan', 'itemHasil');
        $this->resetValidation();
        $this->itemSheet = $id;
        $this->noTitik = $no;
        $this->jumlah = $item->jumlahEfektif();
        unset($this->item);
    }

    #[On('buka-titik')]
    public function bukaTitik(int $id, ?int $no = null): void
    {
        $this->buka($id, $no);
    }

    public function tutup(): void
    {
        $this->reset('itemSheet', 'noTitik', 'foto', 'fotoKoordinat', 'keterangan');
    }

    public function hapusFoto(int $n): void
    {
        array_splice($this->foto, $n, 1);
        array_splice($this->fotoKoordinat, $n, 1);
    }

    public function simpan(?float $lat = null, ?float $lng = null): void
    {
        $item = $this->item;
        abort_unless($item && auth()->user()->hasRole(Role::Dishub) && $item->status === SI::Disetujui, 403);
        $this->validate([
            'foto' => 'required|array|min:1',
            'foto.*' => 'image|max:12288',
            'jumlah' => 'required|integer|min:1|max:9999',
        ], ['foto.required' => 'Ambil minimal 1 foto hasil pemasangan.'], ['jumlah' => 'jumlah']);

        $item->tandaiTerpasang(
            collect($this->foto)->map(fn ($f, $n) => ['file' => $f] + ($this->fotoKoordinat[$n] ?? []))->all(),
            $this->jumlah, $lat, $lng, $this->keterangan,
        );

        $this->itemHasil = $item->id;
        $this->reset('itemSheet', 'noTitik', 'foto', 'fotoKoordinat', 'keterangan');
        unset($this->titik, $this->selesaiHariIni, $this->item);
        $this->dispatch('toast', pesan: 'Ditandai terpasang', urungkan: 'pasang:'.$item->id);
        $this->dispatch('titik-berubah', titik: $this->titik);
        $this->dispatch('usulan-diproses', lanjut: false);
    }

    #[On('urungkan')]
    public function urungkan(string $token): void
    {
        if (! str_starts_with($token, 'pasang:')) {
            return;
        }
        $item = UsulanItem::find((int) substr($token, 7));
        if ($item?->status === SI::Terpasang && $item->updated_at->gt(now()->subMinutes(10))) {
            $item->batalTerpasang();
            $this->reset('itemHasil');
            unset($this->titik, $this->selesaiHariIni);
            $this->dispatch('titik-berubah', titik: $this->titik);
            $this->dispatch('toast', pesan: 'Penandaan dibatalkan');
            $this->dispatch('usulan-diproses', lanjut: false);
        }
    }
};
?>

@php
    $hasil = $itemHasil ? UsulanItem::with('jenis', 'usulan')->find($itemHasil) : null;
    $total = count($this->titik) + $this->selesaiHariIni;
@endphp

<div class="relative flex h-full min-h-0 flex-1 flex-col"
     x-data="petaLapangan(@js(config('usuljalan.peta_awal')), @js(config('usuljalan.gudang')))"
     @titik-berubah.window="perbarui($event.detail.titik)">
    <script type="application/json" x-ref="awal" wire:ignore>@json($this->titik)</script>

    {{-- Peta --}}
    <div class="relative min-h-0 flex-1 overflow-hidden" wire:ignore>
        <div x-ref="peta" class="peta-redup absolute inset-0"></div>
        <div class="absolute inset-x-4 top-3 z-[500] flex items-center justify-between gap-2 lg:hidden">
            <span class="flex h-11 items-center gap-2 rounded-md bg-bg px-3 font-caption text-sm font-semibold shadow-sm">
                @svg('lucide-route', 'size-[18px] text-accent')
                <span x-text="`Rute hari ini · ${urut.length} titik` + (km ? ` · ±${km} km` : '')"></span>
            </span>
            <button type="button" @click="kePosisi()" class="grid size-11 place-items-center rounded-md bg-bg shadow-sm" aria-label="Lokasi saya">@svg('lucide-locate-fixed', 'size-5')</button>
        </div>
    </div>


    {{-- ── Desktop: panel rute + kontrol + legenda ── --}}
    <section class="absolute bottom-4 left-4 top-4 z-[600] hidden w-[440px] flex-col overflow-hidden rounded-md bg-bg shadow-lg outline outline-1 -outline-offset-1 outline-line lg:flex">
        <div class="flex flex-col gap-4 px-6 pt-5">
            <x-kategori aktif="usulan" />
            <div class="flex items-center justify-between">
                <h1 class="judul text-[28px] leading-7">Usulan masuk</h1>
                <div class="flex gap-0.5 rounded-sm bg-surface-muted p-[3px] font-caption text-[13px]">
                    <button type="button" wire:click="$dispatch('atur-daftar', { mode: 'daftar' })" class="flex items-center gap-1.5 rounded-sm px-2.5 py-1.5 font-medium text-fg-muted hover:text-fg">@svg('lucide-list', 'size-3.5') Daftar</button>
                    <span class="flex items-center gap-1.5 rounded-sm bg-bg px-2.5 py-1.5 font-semibold shadow-sm">@svg('lucide-map', 'size-3.5') Peta</span>
                </div>
            </div>
            <div class="flex gap-5 border-b border-line font-caption text-sm">
                <button type="button" wire:click="$dispatch('atur-daftar', { tab: 'periksa' })" class="-mb-px border-b-2 border-transparent pb-2.5 font-medium text-fg-muted hover:text-fg">Perlu Diperiksa</button>
                <span class="-mb-px flex items-center gap-1.5 border-b-2 border-accent pb-2.5 font-semibold">Siap Dipasang <span class="rounded-full bg-accent px-[7px] py-px text-[11px] text-fg-inverse">{{ collect($this->titik)->pluck('nomor')->unique()->count() }}</span></span>
                <button type="button" wire:click="$dispatch('atur-daftar', { tab: 'semua' })" class="-mb-px border-b-2 border-transparent pb-2.5 font-medium text-fg-muted hover:text-fg">Semua</button>
            </div>
            <div class="flex gap-2 pb-3">
                <label class="flex h-10 flex-1 items-center gap-2 rounded-sm px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                    @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                    <input x-model="cari" class="w-full bg-transparent text-sm outline-none placeholder:text-fg-muted" placeholder="Cari lokasi atau jenis perlengkapan">
                </label>
                <button type="button" @click="susunUlang()" class="flex h-10 items-center gap-1.5 rounded-sm px-3 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1 outline-line" title="Susun ulang rute">@svg('lucide-route', 'size-4') Rute</button>
            </div>
        </div>
        <div class="flex flex-col gap-2.5 border-y border-line px-6 pb-3.5 pt-4">
            <div class="flex items-center justify-between">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-fg-muted">Rute pemasangan hari ini · {{ now()->translatedFormat('D, j M') }}</span>
                <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-[3px] font-caption text-[11px] font-semibold text-success">@svg('lucide-truck', 'size-3') {{ str(auth()->user()->name)->before(' ') }}</span>
            </div>
            <div class="flex items-end gap-5">
                <span class="flex items-end gap-[5px]"><span class="judul text-[26px] leading-[26px]" x-text="urut.length"></span><span class="font-caption text-xs font-medium leading-[19px] text-fg-muted">titik</span></span>
                <span class="flex items-end gap-[5px]"><span class="judul text-[26px] leading-[26px]" x-text="'±' + (km ?? 0)"></span><span class="font-caption text-xs font-medium leading-[19px] text-fg-muted">km</span></span>
                <span class="flex items-end gap-[5px]"><span class="judul text-[26px] normal-case leading-[26px]" x-text="'±' + estimasi"></span><span class="font-caption text-xs font-medium leading-[19px] text-fg-muted">estimasi</span></span>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto py-1.5">
            <template x-for="(t, n) in urut" :key="t.id">
                <button type="button" x-show="cocok(t)" @click="pilih(n, true)" @mouseenter="sorot(n)"
                        :class="n === aktif ? 'border-l-[3px] border-accent bg-accent-soft pl-[17px]' : 'pl-5 hover:bg-surface-muted'"
                        class="flex min-h-[82px] w-full gap-3 pr-6 text-left">
                    <span class="flex w-6 shrink-0 flex-col items-center pt-2.5">
                        <span class="grid size-6 place-items-center rounded-full font-caption text-xs font-bold text-fg-inverse" :class="n === aktif ? 'bg-accent' : 'bg-fg'" x-text="n + 1"></span>
                        <span x-show="n < urut.length - 1" class="w-0.5 flex-1 bg-accent/25"></span>
                    </span>
                    <span class="pt-2.5"><span class="grid size-9 place-items-center rounded-sm p-[9px] text-accent" :class="n === aktif ? 'bg-bg' : 'bg-accent-soft'" x-html="t.ikon"></span></span>
                    <span class="flex min-w-0 flex-1 flex-col gap-1 py-2.5">
                        <span class="truncate text-[15px] font-semibold" x-text="t.lokasi"></span>
                        <span class="flex items-center gap-1.5"><span class="text-[13px]" x-text="t.jenis"></span><span class="font-caption text-xs font-semibold text-accent" x-text="`× ${t.jumlah} ${t.satuan}`"></span></span>
                        <span class="flex items-center gap-2 text-xs text-fg-muted">
                            <span x-text="t.kec"></span>
                            <span x-show="t.langkah !== null" class="size-[3px] rounded-full bg-fg-muted"></span>
                            <span x-show="t.langkah !== null" x-text="'+' + teksJarak(t.langkah)"></span>
                            <span class="flex-1"></span>
                            <span x-show="t.tahun" class="flex items-center gap-1 rounded-sm px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg" :class="n === aktif ? 'bg-bg' : 'bg-surface-muted'">@svg('lucide-wallet', 'size-[11px]') <span x-text="'TA ' + t.tahun"></span></span>
                        </span>
                    </span>
                </button>
            </template>
            <div x-show="!urut.length" class="flex flex-col items-center gap-2 p-10 text-center text-sm text-fg-muted">@svg('lucide-circle-check', 'size-8') Tidak ada titik yang menunggu dipasang.</div>
        </div>
        <div class="flex items-center gap-2 border-t border-line px-6 pb-4 pt-3.5">
            <a :href="urlEkspor" class="flex items-center gap-2 rounded-sm bg-bg px-3.5 py-[11px] text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-file-down', 'size-4') Ekspor Rute</a>
            <a :href="urlNavigasi" target="_blank" class="flex flex-1 items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Mulai Navigasi @svg('lucide-navigation', 'size-[18px]')</a>
        </div>
    </section>

    <div class="absolute bottom-6 left-[472px] z-[600] hidden items-center gap-2.5 rounded-md bg-fg px-3.5 py-2.5 text-sm font-semibold text-fg-inverse shadow-lg lg:flex">
        @svg('lucide-route', 'size-4')
        <span x-text="`Rute pemasangan hari ini · ${urut.length} titik · ± ${km ?? 0} km`"></span>
        <span class="h-4 w-px bg-fg-inverse/20"></span>
        <button type="button" @click="susunUlang()" class="flex items-center gap-1 font-caption text-xs text-secondary">@svg('lucide-refresh-cw', 'size-[13px]') Susun ulang</button>
    </div>

    <div class="absolute right-4 top-4 z-[600] hidden flex-col gap-2 lg:flex">
        <button type="button" @click="gantiLapisan()" :class="satelit && 'text-accent'" class="grid size-10 place-items-center rounded-sm bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line" title="Peta / satelit">@svg('lucide-layers', 'size-[18px]')</button>
        <div class="flex flex-col overflow-hidden rounded-sm bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line">
            <button type="button" @click="peta.zoomIn()" class="grid size-10 place-items-center border-b border-line">@svg('lucide-plus', 'size-[18px]')</button>
            <button type="button" @click="peta.zoomOut()" class="grid size-10 place-items-center">@svg('lucide-minus', 'size-[18px]')</button>
        </div>
        <button type="button" @click="kePosisi(true)" class="grid size-10 place-items-center rounded-sm bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line" title="Lokasi saya">@svg('lucide-locate-fixed', 'size-[18px]')</button>
    </div>

    <div class="absolute bottom-6 right-4 z-[600] hidden w-[220px] flex-col gap-2 rounded-sm bg-bg px-3.5 py-3 shadow-sm outline outline-1 -outline-offset-1 outline-line lg:flex">
        <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-fg-muted">Legenda</span>
        <template x-for="j in legenda" :key="j.jenis">
            <span class="flex items-center gap-2 text-xs"><span class="size-3.5 text-accent" x-html="j.ikon"></span><span x-text="j.jenis"></span></span>
        </template>
        <span class="h-px bg-line"></span>
        <span class="flex items-center gap-2 text-xs"><span class="h-1 w-3.5 rounded-[2px] bg-accent"></span> Rute disarankan</span>
        <span class="flex items-center gap-2 text-xs"><span class="size-3.5 rounded-[3px] bg-accent-deep"></span> {{ config('usuljalan.gudang.nama') }} (titik awal)</span>
    </div>

    {{-- Kartu titik (geser horizontal) --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-3 z-[600] flex flex-col gap-2.5 lg:hidden" x-show="urut.length && !$wire.itemHasil" x-cloak>
        <div class="flex justify-center">
            <span class="pointer-events-auto flex items-center gap-2.5 rounded-full bg-fg/85 px-3 py-1.5 font-caption text-[13px] font-medium text-fg-inverse">
                <span class="flex items-center gap-1">
                    <template x-for="(t, n) in urut" :key="t.id"><span class="h-[5px] rounded-[3px] transition-all" :class="n === aktif ? 'w-3.5 bg-bg' : 'w-[5px] bg-bg/40'"></span></template>
                </span>
                <span x-text="aktif < urut.length - 1 ? `Geser ke titik ${aktif + 2}` : 'Titik terakhir'"></span>
                @svg('lucide-chevrons-right', 'size-4')
            </span>
        </div>
        <div x-ref="kartu" @scroll.debounce.80ms="geserKartu()" class="pointer-events-auto flex snap-x snap-mandatory gap-2 overflow-x-auto scroll-smooth px-4 pb-1 [scrollbar-width:none]">
            <template x-for="(t, n) in urut" :key="t.id">
                <div class="flex w-[calc(100%-40px)] max-w-[360px] shrink-0 snap-center flex-col gap-3 rounded-md bg-bg p-4 shadow-lg">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent" x-text="`TITIK ${n + 1} · ${t.nomor}`"></span>
                            <span x-show="t.jarak !== null" class="flex items-center gap-1 rounded-sm bg-[#1A73E8]/10 px-2 py-[3px] font-caption text-[13px] font-semibold text-[#1557B0]">@svg('lucide-navigation', 'size-[13px]') <span x-text="teksJarak(t.jarak) + ' dari Anda'"></span></span>
                        </div>
                        <span class="judul text-[26px] leading-[29px]" x-text="t.lokasi"></span>
                        <span class="text-base text-fg-muted" x-text="t.wilayah"></span>
                    </div>
                    <div class="flex items-center gap-3 border-y border-line py-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-accent-soft p-[11px] text-accent" x-html="t.ikon"></span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="text-[17px] font-semibold" x-text="t.jenis"></span>
                            <span class="text-sm text-fg-muted" x-text="t.tahun ? `Tahun anggaran ${t.tahun}` : 'Disetujui'"></span>
                        </span>
                        <span class="flex items-end gap-1"><span class="judul text-[28px] leading-7" x-text="`×${t.jumlah}`"></span><span class="font-caption text-[13px] text-fg-muted" x-text="t.satuan"></span></span>
                    </div>
                    <div class="flex gap-2">
                        <a :href="`https://www.google.com/maps/dir/?api=1&destination=${t.lat},${t.lng}`" target="_blank" class="grid size-[52px] shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line" aria-label="Arah">@svg('lucide-navigation', 'size-[22px]')</a>
                        <button type="button" @click="$wire.buka(t.id, n + 1)" class="flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Tandai Terpasang @svg('lucide-circle-check', 'size-[18px]')</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div x-show="!urut.length && !$wire.itemHasil" x-cloak class="absolute inset-x-4 bottom-4 z-[600] rounded-md bg-bg p-5 text-center shadow-lg lg:hidden">
        <div class="judul text-2xl">Semua beres ✓</div>
        <p class="mt-1 text-sm text-fg-muted">Tidak ada titik yang menunggu dipasang.</p>
    </div>

    {{-- Panel hasil setelah terpasang --}}
    @if ($hasil)
        @php $berikut = collect($this->titik)->first(); @endphp
        <div class="absolute inset-x-0 bottom-0 z-[700] flex flex-col gap-4 rounded-t-xl bg-bg p-4 shadow-[0_-6px_20px_#0001001f] lg:inset-x-auto lg:bottom-20 lg:right-6 lg:w-[420px] lg:rounded-md lg:shadow-xl">
            <div class="flex items-center gap-3">
                <span class="grid size-[52px] shrink-0 place-items-center rounded-full bg-success text-fg-inverse">@svg('lucide-check', 'size-7')</span>
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="judul text-[26px] leading-[27px]">{{ $hasil->jumlah_terpasang }} {{ $hasil->jenis->satuan }} {{ $hasil->jenis->namaPendek() }} terpasang</span>
                    <span class="text-[15px] text-fg-muted">{{ $hasil->usulan->alamat ?: $hasil->usulan->lokasiSingkat() }} · {{ $hasil->usulan->nomor }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2.5 rounded-sm bg-success-bg p-3 text-[15px] font-medium text-success">@svg('lucide-globe', 'size-5') Sudah tampil di peta publik · pengusul dikabari</div>
            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-base font-semibold">{{ $this->selesaiHariIni }} dari {{ $total }} selesai hari ini</span>
                    <span class="font-caption text-[13px] text-fg-muted" x-text="`${urut.length} tersisa` + (km ? ` · ±${km} km` : '')"></span>
                </div>
                <div class="flex gap-1">
                    @for ($n = 1; $n <= min($total, 12); $n++)
                        <span @class(['h-1.5 flex-1 rounded-[3px]', 'bg-success' => $n <= $this->selesaiHariIni, 'bg-accent/35' => $n === $this->selesaiHariIni + 1, 'bg-line' => $n > $this->selesaiHariIni + 1])></span>
                    @endfor
                </div>
            </div>
            <template x-if="urut.length">
                <div class="flex items-center gap-3 rounded-md p-3 outline outline-1 -outline-offset-1 outline-line">
                    <span class="judul grid size-9 shrink-0 place-items-center rounded-full bg-accent text-lg text-fg-inverse">1</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="font-caption text-xs font-semibold uppercase tracking-[0.4px] text-accent" x-text="'Berikutnya' + (urut[0].jarak !== null ? ' · ' + teksJarak(urut[0].jarak) : '')"></span>
                        <span class="text-base font-semibold" x-text="`${urut[0].jenis} ×${urut[0].jumlah}`"></span>
                        <span class="truncate text-sm text-fg-muted" x-text="urut[0].lokasi"></span>
                    </span>
                    <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-accent-soft p-[11px] text-accent" x-html="urut[0].ikon"></span>
                </div>
            </template>
            <button type="button" wire:click="$set('itemHasil', null)" @click="$nextTick(() => pilih(0))" class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">
                <span x-text="urut.length ? 'Lanjut ke titik berikutnya' : 'Selesai'"></span> @svg('lucide-arrow-right', 'size-[18px]')
            </button>
        </div>
    @endif

    {{-- Sheet Tandai Terpasang --}}
    @if ($itemSheet && $this->item)
        @php $it = $this->item; @endphp
        <div class="fixed inset-0 z-[3000] flex flex-col justify-end bg-fg/55 lg:items-center lg:justify-center" wire:click.self="tutup"
             x-data="{
                mengunggah: false,
                gps: null,
                titik: { lat: {{ $it->usulan->lat ?? 'null' }}, lng: {{ $it->usulan->lng ?? 'null' }} },
                get jauh() {
                    if (!this.gps || this.titik.lat === null) return false;
                    const r = Math.PI / 180, dLat = (this.gps.lat - this.titik.lat) * r, dLng = (this.gps.lng - this.titik.lng) * r;
                    const h = Math.sin(dLat / 2) ** 2 + Math.cos(this.titik.lat * r) * Math.cos(this.gps.lat * r) * Math.sin(dLng / 2) ** 2;
                    return 12742000 * Math.asin(Math.sqrt(h)) > {{ config('usuljalan.jarak_pasang_maks') }};
                },
                init() {
                    navigator.geolocation?.getCurrentPosition(p => this.gps = { lat: p.coords.latitude, lng: p.coords.longitude, akurasi: Math.round(p.coords.accuracy) }, () => {}, { enableHighAccuracy: true, timeout: 10000 })
                },
                async pilih(e) {
                    const files = [...e.target.files];
                    if (!files.length) return;
                    this.mengunggah = true;
                    const koordinat = await Promise.all(files.map(f => window.koordinatFoto(f)));
                    $wire.set('fotoKoordinat', [...$wire.fotoKoordinat, ...koordinat], false);
                    $wire.uploadMultiple('foto', files, () => this.mengunggah = false, () => this.mengunggah = false);
                    e.target.value = '';
                },
             }">
            <div class="flex max-h-[92dvh] flex-col overflow-y-auto rounded-t-xl bg-bg shadow-xl lg:w-[480px] lg:rounded-md">
                <div class="flex justify-center pb-1 pt-2 lg:hidden"><span class="h-[5px] w-9 rounded-[3px] bg-line"></span></div>
                <div class="flex gap-3 px-4 pb-4 pt-2">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent">{{ $noTitik ? "TITIK {$noTitik} · " : '' }}{{ $it->usulan->nomor }}</span>
                        <span class="judul text-[28px] leading-7">Tandai terpasang</span>
                        <span class="text-base text-fg-muted">{{ $it->jenis->namaPendek() }} · {{ $it->usulan->alamat ?: $it->usulan->lokasiSingkat() }}</span>
                    </div>
                    <button wire:click="tutup" class="grid size-11 shrink-0 place-items-center rounded-full bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
                </div>
                <div class="flex flex-col gap-5 px-4 pb-4">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center justify-between">
                            <span class="text-base font-semibold">Foto hasil pemasangan<span class="text-danger">*</span></span>
                            <span class="font-caption text-sm text-fg-muted">{{ count($foto) }} foto</span>
                        </div>
                        <div class="flex gap-2 overflow-x-auto">
                            <label class="flex h-28 min-w-28 flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-md bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-accent">
                                @svg('lucide-camera', 'size-7') <span class="text-base font-semibold" x-text="mengunggah ? 'Mengunggah…' : 'Ambil Foto'">Ambil Foto</span>
                                <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                            </label>
                            @foreach ($foto as $n => $f)
                                <div class="relative size-28 shrink-0 overflow-hidden rounded-md" wire:key="fh-{{ $n }}">
                                    <img src="{{ $f->temporaryUrl() }}" class="size-full object-cover" alt="">
                                    <button wire:click="hapusFoto({{ $n }})" class="absolute right-0 top-0 grid size-11 place-items-center" aria-label="Hapus foto"><span class="grid size-7 place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-4')</span></button>
                                </div>
                            @endforeach
                        </div>
                        @error('foto') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex flex-1 flex-col gap-0.5"><span class="text-base font-semibold">Jumlah terpasang</span><span class="text-sm text-fg-muted">Disetujui {{ $it->jumlahEfektif() }} {{ $it->jenis->satuan }}</span></div>
                        <div class="flex items-center overflow-hidden rounded-sm outline outline-1 -outline-offset-1 outline-line">
                            <button wire:click="$set('jumlah', {{ max(1, $jumlah - 1) }})" class="grid size-12 place-items-center" aria-label="Kurangi">@svg('lucide-minus', 'size-5')</button>
                            <span class="judul grid h-12 w-[51px] place-items-center border-x border-line text-[26px]">{{ $jumlah }}</span>
                            <button wire:click="$set('jumlah', {{ $jumlah + 1 }})" class="grid size-12 place-items-center bg-accent-soft text-accent" aria-label="Tambah">@svg('lucide-plus', 'size-5')</button>
                        </div>
                    </div>

                    <div class="flex flex-col border-t border-line">
                        <div class="flex items-center gap-3 border-b border-line py-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted">@svg('lucide-calendar-check', 'size-5')</span>
                            <span class="flex flex-1 flex-col gap-0.5"><span class="font-caption text-[13px] text-fg-muted">Tanggal pasang</span><span class="text-base font-semibold">Hari ini, {{ now()->translatedFormat('j M Y') }}</span></span>
                            <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-1 font-caption text-xs font-semibold text-success">@svg('lucide-check', 'size-3.5') Otomatis</span>
                        </div>
                        <div class="flex items-center gap-3 border-b border-line py-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted">@svg('lucide-crosshair', 'size-5')</span>
                            <span class="flex flex-1 flex-col gap-0.5"><span class="font-caption text-[13px] text-fg-muted">Lokasi · GPS otomatis</span><span class="text-base font-semibold" x-text="gps && !jauh ? `Akurasi ${gps.akurasi} m` : 'Memakai titik usulan'"></span><span x-show="jauh" x-cloak class="font-caption text-xs text-warning">Posisimu jauh dari lokasi usulan</span></span>
                            <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-1 font-caption text-xs font-semibold text-success">@svg('lucide-map-pin', 'size-3.5') <span x-text="gps && !jauh ? 'Terkunci' : 'Titik usulan'"></span></span>
                        </div>
                    </div>

                    <div x-data="{ buka: false }">
                        <button type="button" @click="buka = !buka" class="flex h-12 w-full items-center gap-2.5">
                            <span class="text-accent">@svg('lucide-message-square-plus', 'size-5')</span>
                            <span class="text-base font-semibold text-accent">Tambah keterangan</span>
                            <span class="flex-1 text-left text-sm text-fg-muted">opsional</span>
                            <span class="transition" :class="buka && 'rotate-180'">@svg('lucide-chevron-down', 'size-5')</span>
                        </button>
                        <textarea x-show="buka" x-cloak wire:model="keterangan" rows="2" class="input text-sm" placeholder="Mis. dipasang di sisi kiri arah pasar"></textarea>
                    </div>
                </div>
                <div class="sticky bottom-0 border-t border-line bg-bg px-4 pb-4 pt-3">
                    <button type="button" @click="$wire.simpan(gps?.lat ?? null, gps?.lng ?? null)" :disabled="mengunggah" wire:loading.attr="disabled" wire:target="simpan"
                            class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-60">
                        <span wire:loading.remove wire:target="simpan">Selesai</span><span wire:loading wire:target="simpan">Menyimpan…</span> @svg('lucide-check', 'size-[18px]')
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

<?php

use App\Enums\Role;
use App\Enums\StatusItem as SI;
use App\Enums\StatusLaporan as SL;
use App\Models\LaporanKerusakan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tugas Saya — petugas lapangan Dishub di HP (desain PM3): peta titik laporan, sheet Cek Kondisi & Selesai Diperbaiki.
 */
new class extends Component
{
    use WithFileUploads;

    public string $tab = 'ditugaskan'; // tab halaman: ditugaskan (perlu dicek) | perbaikan
    public ?string $sheet = null; // cek, selesai
    public ?int $laporanSheet = null;
    public ?int $noTitik = null;
    public $foto = [];
    public array $fotoKoordinat = [];
    public string $catatan = '';
    public string $alasan = '';
    public ?int $hasil = null;

    private function statusTab(string $tab): SL
    {
        return $tab === 'ditugaskan' ? SL::Ditugaskan : SL::MenungguPerbaikan;
    }

    #[Computed]
    public function daftar()
    {
        // data sama dengan tab daftar; tampilannya saja yang peta lapangan
        return LaporanKerusakan::where('status', $this->statusTab($this->tab))
            ->with('jenis', 'kecamatan', 'desa', 'pelapor', 'penugas', 'petugas', 'foto')
            ->orderBy($this->tab === 'ditugaskan' ? 'ditugaskan_at' : 'dicek_at')->get();
    }


    #[Computed]
    public function titik(): array
    {
        return $this->daftar->filter(fn ($l) => $l->lat())->values()->map(fn ($l, $n) => [
            'id' => $l->id,
            'no' => $n + 1,
            'lat' => $l->lat(),
            'lng' => $l->lng(),
            'ikon' => svg('lucide-'.$l->ikon(), 'size-4')->toHtml(),
        ])->all();
    }

    #[Computed]
    public function laporan(): ?LaporanKerusakan
    {
        return $this->laporanSheet ? LaporanKerusakan::with('jenis', 'kecamatan', 'desa', 'foto', 'pelapor')->find($this->laporanSheet) : null;
    }


    public function buka(int $id, ?int $no = null): void
    {
        $l = LaporanKerusakan::findOrFail($id);
        abort_unless(auth()->user()->hasRole(Role::Dishub) && in_array($l->status, [SL::Ditugaskan, SL::MenungguPerbaikan], true), 403);
        $this->reset('foto', 'fotoKoordinat', 'catatan', 'alasan', 'hasil');
        $this->resetValidation();
        $this->laporanSheet = $id;
        $this->noTitik = $no;
        $this->sheet = $l->status === SL::Ditugaskan ? 'cek' : 'selesai';
        unset($this->laporan);
    }

    public function tutup(): void
    {
        $this->reset('sheet', 'laporanSheet', 'noTitik', 'foto', 'fotoKoordinat', 'catatan', 'alasan');
    }

    public function hapusFoto(int $n): void
    {
        array_splice($this->foto, $n, 1);
        array_splice($this->fotoKoordinat, $n, 1);
    }

    private function fotoTerunggah(): array
    {
        return collect($this->foto)->map(fn ($f, $n) => ['file' => $f] + ($this->fotoKoordinat[$n] ?? []))->all();
    }

    /** Hasil cek kondisi: rusak = setujui (perlu perbaikan), tidak rusak = tolak dengan alasan. */
    public function cek(bool $rusak, ?float $lat = null, ?float $lng = null): void
    {
        $l = $this->laporan;
        abort_unless($l && auth()->user()->hasRole(Role::Dishub) && $l->status === SL::Ditugaskan, 403);
        $this->validate([
            'foto' => 'required|array|min:1',
            'foto.*' => 'image|max:12288',
            'catatan' => 'nullable|string|max:300',
            'alasan' => $rusak ? 'nullable' : 'required|in:'.implode(',', LaporanKerusakan::ALASAN_PETUGAS),
        ], ['foto.required' => 'Ambil minimal 1 foto kondisi sekarang.', 'alasan.required' => 'Pilih alasannya dulu.']);

        $l->cek($rusak, $this->fotoTerunggah(), trim($this->catatan), $rusak ? null : $this->alasan, $lat, $lng);
        $this->tutup();
        unset($this->daftar, $this->titik);
        $this->dispatch('tugas-berubah', titik: $this->titik);
        $this->dispatch('toast', pesan: $rusak ? 'Disetujui · masuk antrean perbaikan' : 'Laporan ditolak · pelapor dikabari');
        $this->dispatch('laporan-diproses');
    }

    public function selesai(): void
    {
        $l = $this->laporan;
        abort_unless($l && auth()->user()->hasRole(Role::Dishub) && $l->status === SL::MenungguPerbaikan, 403);
        $this->validate(['foto' => 'required|array|min:1', 'foto.*' => 'image|max:12288', 'catatan' => 'nullable|string|max:300'],
            ['foto.required' => 'Ambil minimal 1 foto hasil perbaikan.']);

        $l->selesai($this->fotoTerunggah(), trim($this->catatan));
        $this->tutup();
        $this->hasil = $l->id;
        unset($this->daftar, $this->titik);
        $this->dispatch('tugas-berubah', titik: $this->titik);
        $this->dispatch('laporan-diproses');
    }

    #[On('tugas-segarkan')]
    public function segarkan(): void
    {
        $this->hasil = null;
        unset($this->daftar, $this->titik);
        $this->dispatch('tugas-berubah', titik: $this->titik);
    }
};
?>

@php
    $hasilL = $hasil ? \App\Models\LaporanKerusakan::with('jenis', 'kecamatan', 'desa', 'foto')->find($hasil) : null;
    $no = collect($this->titik)->pluck('no', 'id');
@endphp

<div class="relative flex h-full min-h-0 flex-1 flex-col bg-bg"
     x-data="petaTugas(@js(config('usuljalan.peta_awal')))"
     @tugas-berubah.window="perbarui($event.detail.titik)">
    {{-- data awal di luar x-data: atribut x-data yang berubah membuat Alpine memasang ulang peta --}}
    <script type="application/json" x-ref="awal" wire:ignore>@json($this->titik)</script>
    {{-- Peta --}}
    <div class="relative min-h-0 flex-1 overflow-hidden">
        <div x-ref="peta" class="peta-redup absolute inset-0" wire:ignore></div>
        <div class="absolute inset-x-4 top-3 z-[500] flex items-center justify-between gap-2">
            <span class="flex h-11 items-center gap-2 rounded-md bg-bg px-3 font-caption text-sm font-semibold shadow-sm">
                @svg('lucide-route', 'size-[18px] text-accent')
                <span x-text="`${titik.length} titik` + (km ? ` · ±${km} km` : '')"></span>
            </span>
            <button type="button" @click="kePosisi()" class="grid size-11 place-items-center rounded-md bg-bg shadow-sm" aria-label="Lokasi saya">@svg('lucide-locate-fixed', 'size-5')</button>
        </div>
    </div>

    {{-- Kartu titik: geser di atas peta, atau daftar tegak --}}
    @php
        $kelasKartu = 'flex shrink-0 flex-col gap-3 rounded-md bg-bg p-4';
    @endphp
    @if (! $hasilL)
        <div class="pointer-events-none absolute inset-x-0 bottom-3 z-[600] flex flex-col gap-2.5">
            @if ($this->daftar->count() > 1)
                <div class="flex justify-center">
                    <span class="pointer-events-auto rounded-full bg-fg/85 px-3 py-1.5 font-caption text-[13px] font-medium text-fg-inverse" x-text="aktif < titik.length - 1 ? `Geser ke titik ${aktif + 2}` : 'Titik terakhir'"></span>
                </div>
            @endif
            <div x-ref="kartu" @scroll.debounce.80ms="geserKartu()" class="pointer-events-auto flex snap-x snap-mandatory gap-2 overflow-x-auto scroll-smooth px-4 pb-1 [scrollbar-width:none]">
                @forelse ($this->daftar as $l)
                    @php $n = $no[$l->id] ?? $loop->iteration; $fp = $l->fotoJenis('pelapor')->first(); @endphp
                    <div wire:key="tg-{{ $l->id }}" data-id="{{ $l->id }}" class="{{ $kelasKartu }} w-[calc(100%-40px)] max-w-[360px] snap-center shadow-lg">
                        <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1.5">
                            <span class="whitespace-nowrap font-caption text-xs font-semibold tracking-[0.4px] text-accent">TITIK {{ $n }} · {{ $l->nomor }}</span>
                            <span class="flex items-center gap-1.5 whitespace-nowrap">
                                <span x-show="jarak[{{ $l->id }}] != null" x-cloak class="flex items-center gap-1 rounded-sm bg-[#1A73E8]/10 px-2 py-[3px] font-caption text-[13px] font-semibold text-[#1557B0]">@svg('lucide-navigation', 'size-[13px]') <span x-text="teksJarak(jarak[{{ $l->id }}])"></span></span>
                                <x-status :status="$l->status" />
                            </span>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="judul text-[24px] leading-[26px]">{{ $l->judul() }}</span>
                                <span class="truncate text-[15px] text-fg-muted">{{ $l->lokasi() }}</span>
                            </div>
                            @if ($fp) <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\LaporanFoto::galeri($l->fotoJenis('pelapor'))), mulai: 0 })" class="shrink-0"><img src="{{ $fp->url() }}" class="size-14 rounded-md object-cover" alt=""></button> @endif
                        </div>
                        <div class="flex flex-col gap-1.5 border-y border-line py-2.5 text-[13px]">
                            <span class="flex items-center gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-secondary font-caption text-[10px] font-semibold text-on-secondary">{{ $l->inisialPelapor() }}</span> <span class="min-w-0 flex-1 truncate">Dilaporkan <b class="font-semibold">{{ $l->namaPelapor() }}</b> <span class="text-fg-muted">· {{ $l->created_at->diffForHumans() }}</span></span></span>
                            @if ($tab === 'ditugaskan')
                                <span class="flex items-center gap-2 text-fg-muted">@svg('lucide-user-round-check', 'size-4 mx-1') <span class="min-w-0 flex-1 truncate">Ditugaskan ke <b class="font-semibold text-fg">{{ $l->petugas_id === auth()->id() ? 'saya' : $l->petugas?->name }}</b> · {{ $l->ditugaskan_at?->diffForHumans() }}</span></span>
                                @if ($l->catatan_tugas) <span class="rounded-sm bg-surface-tint px-2.5 py-1.5 text-accent-deep">“{{ $l->catatan_tugas }}”</span> @endif
                            @else
                                <span class="flex items-center gap-2 text-fg-muted">@svg('lucide-clipboard-check', 'size-4 mx-1') <span class="min-w-0 flex-1">Dicek {{ $l->petugas ? 'oleh '.($l->petugas_id === auth()->id() ? 'saya' : $l->petugas->name).' ' : '' }}{{ $l->dicek_at?->diffForHumans() }}{{ $l->catatan_cek ? ' · “'.$l->catatan_cek.'”' : '' }}</span></span>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <a href="https://www.google.com/maps/dir/?api=1&destination={{ $l->lat() }},{{ $l->lng() }}" target="_blank" class="grid size-[52px] shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line" aria-label="Arah">@svg('lucide-navigation', 'size-[22px]')</a>
                            <button type="button" wire:click="buka({{ $l->id }}, {{ $n }})" @class(['flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm text-base font-semibold text-fg-inverse shadow-sm', 'bg-accent' => $tab === 'ditugaskan', 'bg-success' => $tab !== 'ditugaskan'])>
                                {{ $tab === 'ditugaskan' ? 'Cek Kondisi' : 'Selesai Diperbaiki' }} @svg('lucide-'.($tab === 'ditugaskan' ? 'clipboard-check' : 'circle-check'), 'size-[18px]')
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="mx-4 w-full rounded-md bg-bg p-5 text-center shadow-lg">
                        <div class="judul text-2xl">Semua beres ✓</div>
                        <p class="mt-1 text-sm text-fg-muted">{{ $tab === 'ditugaskan' ? 'Tidak ada laporan yang perlu dicek.' : 'Tidak ada yang menunggu perbaikan.' }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    @else
        {{-- Panel hasil: selesai diperbaiki --}}
        @php $sebelum = $hasilL->fotoJenis('pelapor')->first(); $sesudah = $hasilL->fotoJenis('hasil')->last(); $berikut = $this->daftar->first(); @endphp
        <div class="absolute inset-x-0 bottom-0 z-[700] flex flex-col gap-4 rounded-t-xl bg-bg p-4 shadow-[0_-6px_20px_#0001001f]">
            <div class="flex items-center justify-between gap-2 rounded-sm bg-success-bg px-3 py-2 text-sm font-semibold text-success">
                <span class="flex items-center gap-2">@svg('lucide-circle-check', 'size-[18px]') Selesai diperbaiki</span>
                <span class="flex items-center gap-2 font-caption text-xs font-medium">Bisa diurungkan 10 menit
                    <button type="button" @click="Livewire.dispatch('urungkan', { token: 'lpr-selesai:{{ $hasilL->id }}:hp' })" class="rounded-sm bg-bg px-2 py-1 font-semibold text-fg">Urungkan</button>
                </span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="judul text-[26px] leading-[27px]">{{ $hasilL->jenis?->namaPendek() ?? 'Perlengkapan' }} diperbaiki</span>
                <span class="text-[15px] text-fg-muted">{{ $hasilL->lokasi() }} · {{ $hasilL->nomor }}</span>
                <span class="flex items-center gap-1.5 font-caption text-[13px] text-success">@svg('lucide-bell', 'size-3.5') {{ $hasilL->usulan_item_id ? 'Pelapor & pengusul asli dikabari' : 'Pelapor dikabari' }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                @foreach ([['Sebelum · pelapor', $sebelum], ['Sesudah · '.$hasilL->selesai_at->format('H.i'), $sesudah]] as [$ket, $f])
                    <div class="relative aspect-[4/3] overflow-hidden rounded-md bg-surface-muted">
                        @if ($f) <img src="{{ $f->url() }}" class="size-full object-cover" alt=""> @endif
                        <span class="absolute bottom-1.5 left-1.5 rounded-sm bg-fg/75 px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg-inverse">{{ $ket }}</span>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="$set('hasil', null)" class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">
                {{ $berikut ? 'Lanjut ke titik berikutnya' : 'Selesai' }} @svg('lucide-arrow-right', 'size-[18px]')
            </button>
        </div>
    @endif

    {{-- Sheet Cek Kondisi / Selesai Diperbaiki --}}
    @if ($sheet && $this->laporan)
        @php $sl = $this->laporan; $fp = $sl->fotoJenis('pelapor')->first(); @endphp
        <div class="fixed inset-0 z-[3000] flex flex-col justify-end bg-fg/55" wire:click.self="tutup"
             x-data="{
                mengunggah: false,
                gps: null,
                gpsGagal: false,
                titik: { lat: {{ $sl->lat() ?? 'null' }}, lng: {{ $sl->lng() ?? 'null' }} },
                get jarakM() {
                    if (!this.gps || this.titik.lat === null) return null;
                    const r = Math.PI / 180, dLat = (this.gps.lat - this.titik.lat) * r, dLng = (this.gps.lng - this.titik.lng) * r;
                    const h = Math.sin(dLat / 2) ** 2 + Math.cos(this.titik.lat * r) * Math.cos(this.gps.lat * r) * Math.sin(dLng / 2) ** 2;
                    return Math.round(12742000 * Math.asin(Math.sqrt(h)));
                },
                init() {
                    navigator.geolocation?.getCurrentPosition(p => this.gps = { lat: p.coords.latitude, lng: p.coords.longitude }, () => this.gpsGagal = true, { enableHighAccuracy: true, timeout: 10000 })
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
            <div class="flex max-h-[92dvh] flex-col overflow-y-auto rounded-t-xl bg-bg shadow-xl">
                <div class="flex justify-center pb-1 pt-2"><span class="h-[5px] w-9 rounded-[3px] bg-line"></span></div>
                <div class="flex gap-3 px-4 pb-4 pt-2">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent">{{ $noTitik ? "TITIK {$noTitik} · " : '' }}{{ $sl->nomor }}</span>
                        <span class="judul text-[28px] leading-7">{{ $sheet === 'cek' ? 'Cek kondisi' : 'Selesai diperbaiki' }}</span>
                        <span class="text-base text-fg-muted">{{ $sl->judul() }} · {{ $sl->lokasi() }}</span>
                    </div>
                    <button wire:click="tutup" class="grid size-11 shrink-0 place-items-center rounded-full bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
                </div>
                <div class="flex flex-col gap-5 px-4 pb-4">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center justify-between">
                            <span class="text-base font-semibold">{{ $sheet === 'cek' ? 'Foto kondisi' : 'Foto hasil perbaikan' }}<span class="text-danger">*</span></span>
                            <span class="font-caption text-sm text-fg-muted">{{ count($foto) }} foto</span>
                        </div>
                        <div class="flex gap-2 overflow-x-auto">
                            <label class="flex h-28 min-w-28 flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-md bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-accent">
                                @svg('lucide-camera', 'size-7') <span class="text-base font-semibold" x-text="mengunggah ? ($store.sinyal.ada ? 'Mengunggah…' : 'Menunggu sinyal…') : 'Ambil foto'">Ambil foto</span>
                                <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                            </label>
                            @foreach ($foto as $n => $f)
                                <div class="relative size-28 shrink-0 overflow-hidden rounded-md" wire:key="tf-{{ $n }}">
                                    <img src="{{ $f->temporaryUrl() }}" class="size-full object-cover" alt="">
                                    <button wire:click="hapusFoto({{ $n }})" class="absolute right-0 top-0 grid size-11 place-items-center" aria-label="Hapus foto"><span class="grid size-7 place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-4')</span></button>
                                </div>
                            @endforeach
                        </div>
                        @error('foto') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                        @if ($fp)
                            <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\LaporanFoto::galeri($sl->fotoJenis('pelapor'))), mulai: 0 })" class="flex items-center gap-3 rounded-md bg-surface-muted p-2 text-left">
                                <img src="{{ $fp->url() }}" class="size-12 rounded-sm object-cover" alt="">
                                <span class="flex flex-col"><span class="text-sm font-semibold">{{ $sheet === 'cek' ? 'Bandingkan dengan foto pelapor' : 'Sebelum · foto pelapor' }}</span><span class="font-caption text-xs text-fg-muted">{{ $sl->namaPelapor() }} · {{ $fp->created_at->translatedFormat('j M') }}</span></span>
                            </button>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2">
                        <span class="flex items-center gap-2 text-base font-semibold">Catatan <span class="font-caption text-[13px] font-normal text-fg-muted">opsional</span></span>
                        <textarea wire:model="catatan" rows="2" maxlength="300" class="input text-base" placeholder="{{ $sheet === 'cek' ? 'Mis. bohlam putus, kabel terkelupas' : 'Mis. tiang diganti, pondasi dicor ulang' }}"></textarea>
                    </div>

                    @if ($sheet === 'cek')
                        <div class="flex items-center gap-3 border-y border-line py-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted">@svg('lucide-crosshair', 'size-5')</span>
                            <span class="flex flex-1 flex-col gap-0.5"><span class="font-caption text-[13px] text-fg-muted">Lokasi · GPS otomatis</span><span class="text-base font-semibold" x-text="jarakM !== null ? `${jarakM} m dari titik laporan` : (gpsGagal ? 'GPS tidak terbaca · tetap bisa disimpan' : 'Membaca GPS…')">Membaca GPS…</span></span>
                            <span x-show="jarakM !== null" x-cloak class="rounded-sm px-2 py-1 font-caption text-xs font-semibold" :class="jarakM <= 100 ? 'bg-success-bg text-success' : 'bg-warning-bg text-warning'" x-text="jarakM <= 100 ? 'Cocok' : 'Jauh'"></span>
                        </div>
                    @endif
                </div>
                <div class="sticky bottom-0 flex flex-col gap-3 border-t border-line bg-bg px-4 pb-4 pt-3">
                    @if ($sheet === 'cek')
                        <button type="button" @click="$wire.cek(true, gps?.lat ?? null, gps?.lng ?? null)" :disabled="mengunggah" wire:loading.attr="disabled" wire:target="cek"
                                class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-60">@svg('lucide-check', 'size-[18px]') Setujui — perlu perbaikan</button>
                        <div class="flex flex-col gap-2">
                            <span class="text-center font-caption text-[13px] text-fg-muted">Tidak rusak? Pilih alasan, lalu tolak</span>
                            <div class="flex justify-center gap-2">
                                @foreach (\App\Models\LaporanKerusakan::ALASAN_PETUGAS as $a)
                                    <button type="button" wire:click="$set('alasan', @js($a))" @class(['rounded-full px-3 py-2 text-sm', 'bg-fg font-semibold text-fg-inverse' => $alasan === $a, 'font-medium outline outline-1 -outline-offset-1 outline-line' => $alasan !== $a])>{{ $a }}</button>
                                @endforeach
                            </div>
                            @error('alasan') <span class="text-center text-sm text-danger">{{ $message }}</span> @enderror
                            <button type="button" @click="$wire.cek(false, gps?.lat ?? null, gps?.lng ?? null)" :disabled="mengunggah || {{ $alasan ? 'false' : 'true' }}" wire:loading.attr="disabled" wire:target="cek"
                                    class="flex h-12 w-full items-center justify-center gap-2 rounded-sm text-base font-semibold text-danger outline outline-1 -outline-offset-1 outline-line disabled:opacity-40">@svg('lucide-x', 'size-[18px]') Tolak — tidak rusak</button>
                        </div>
                    @else
                        <button type="button" wire:click="selesai" :disabled="mengunggah" wire:loading.attr="disabled" wire:target="selesai"
                                class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-success text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-60">
                            <span wire:loading.remove wire:target="selesai">Selesai</span><span wire:loading wire:target="selesai">Menyimpan…</span> @svg('lucide-check', 'size-[18px]')
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

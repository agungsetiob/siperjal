<?php

use App\Enums\StatusItem;
use App\Models\Desa;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LaporanKerusakan;
use App\Models\UsulanItem;
use App\Support\Wilayah;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ajukan (HP & desktop): pilih jenis pengajuan → lapor kerusakan (desain PM2).
 * Titik bebas seperti usulan (tap peta / GPS / cari alamat, kecamatan & desa terisi otomatis) + pilih jenis perlengkapan.
 * Bila perlengkapannya tercatat terpasang (pin di peta / di sekitar titik), laporan bisa ditautkan ke item itu.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Url(as: 'jenis')]
    public ?string $pilihan = null; // lapor

    public int $langkah = 1;

    // ① Lokasi
    public ?int $itemId = null; // perlengkapan terpasang yang ditautkan (opsional)
    public ?float $lat = null;
    public ?float $lng = null;
    public string $alamat = '';
    public ?int $kecamatan_id = null;
    public ?int $desa_id = null;
    public bool $kecamatanManual = false;
    public bool $desaManual = false;

    // ② Kerusakan
    public ?int $jenisId = null;
    public ?string $kerusakan = null;
    public $foto = [];
    public array $fotoKoordinat = [];
    public string $catatan = '';
    public ?int $terkirim = null;

    public function mount(): void
    {
        if (request()->integer('item')) {
            $this->pilihan = 'lapor';
            $this->pilih(request()->integer('item'));
        }
    }

    #[Computed]
    public function titik(): array
    {
        return LaporanKerusakan::perlengkapanTerpasang()->map(fn (UsulanItem $i) => [
            'id' => $i->id,
            'lat' => (float) ($i->lat_terpasang ?? $i->usulan->lat),
            'lng' => (float) ($i->lng_terpasang ?? $i->usulan->lng),
            'warna' => $i->jenis->warna,
            'ikon' => svg('lucide-'.$i->jenis->ikon, 'size-4')->toHtml(),
        ])->all();
    }

    #[Computed]
    public function item(): ?UsulanItem
    {
        return $this->itemId ? UsulanItem::with('jenis', 'usulan.desa', 'usulan.kecamatan', 'laporanTerbuka')->find($this->itemId) : null;
    }

    /** Perlengkapan terpasang di sekitar titik yang ditaruh, ditawarkan agar laporan tertaut. */
    #[Computed]
    public function sekitar()
    {
        return $this->lat && ! $this->itemId ? LaporanKerusakan::terpasangSekitar($this->lat, $this->lng)->take(3) : collect();
    }

    /** Tautkan ke perlengkapan terpasang: titik, wilayah & jenis ikut item. */
    public function pilih(int $id): void
    {
        $item = UsulanItem::with('usulan')->findOrFail($id);
        abort_unless($item->status === StatusItem::Terpasang, 422);
        $t = LaporanKerusakan::tempatDari($item);
        $this->itemId = $id;
        [$this->lat, $this->lng] = [$t['lat'] ?: null, $t['lng'] ?: null];
        $this->alamat = (string) $t['alamat'];
        [$this->kecamatan_id, $this->desa_id] = [$t['kecamatan_id'], $t['desa_id']];
        $this->kecamatanManual = ! $this->kecamatan_id;
        $this->desaManual = ! $this->desa_id;
        $this->jenisId = $t['jenis_id'];
        unset($this->item);
        $this->resetErrorBag();
        $this->dispatch('lokasi-diatur', lat: $this->lat, lng: $this->lng);
        $this->dispatch('sorot-titik', id: $id);
    }

    /** "Bukan yang ini": laporan di titik bebas, jenis dipilih sendiri. */
    public function lepas(): void
    {
        $this->itemId = null;
        $this->jenisId = null;
        unset($this->item);
        $this->dispatch('sorot-titik', id: null);
    }

    public function aturLokasi(float $lat, float $lng, array $alamat = []): void
    {
        if ($this->itemId) {
            $this->lepas();
        }
        $this->lat = round($lat, 7);
        $this->lng = round($lng, 7);
        $this->alamat = $alamat['jalan'] ?? $this->alamat;

        [$this->kecamatan_id, $this->desa_id] = Wilayah::cocokkan($alamat['kecamatan'] ?? null, $alamat['desa'] ?? null);
        $this->kecamatanManual = ! $this->kecamatan_id;
        $this->desaManual = ! $this->desa_id;
        $this->resetErrorBag();
        $this->dispatch('lokasi-diatur', lat: $this->lat, lng: $this->lng);
    }

    public function updatedKecamatanId(): void
    {
        $this->desa_id = null;
    }

    public function lanjut(): void
    {
        $this->validate([
            'lat' => 'required|numeric',
            'kecamatan_id' => $this->itemId ? 'nullable' : 'required|exists:kecamatan,id',
        ], [
            'lat.required' => 'Tandai lokasinya di peta dulu.',
            'kecamatan_id.required' => 'Pilih kecamatannya.',
        ]);
        abort_if($this->item?->laporanTerbuka, 422);
        $this->langkah = 2;
        $this->js('window.scrollTo({ top: 0 })');
    }

    public function kembali()
    {
        if ($this->langkah === 2) {
            $this->langkah = 1;

            return null;
        }

        return $this->redirectRoute('usulan', navigate: true);
    }

    public function hapusFoto(int $n): void
    {
        array_splice($this->foto, $n, 1);
        array_splice($this->fotoKoordinat, $n, 1);
    }

    public function kirim(): void
    {
        abort_unless(auth()->user()->isPengusul(), 403);
        $item = $this->item;
        abort_if($this->itemId && $item?->status !== StatusItem::Terpasang, 403);
        if ($ada = $item?->laporanTerbuka) {
            $this->addError('kirim', "Perlengkapan ini sudah dilaporkan ({$ada->nomor}) dan sedang ditangani Dishub.");

            return;
        }
        $this->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'kecamatan_id' => $item ? 'nullable' : 'required|exists:kecamatan,id',
            'desa_id' => 'nullable|exists:desa,id',
            'alamat' => 'nullable|string|max:255',
            'jenisId' => $item ? 'nullable' : 'required|exists:jenis_perlengkapan,id,aktif,1',
            'kerusakan' => 'required|in:'.implode(',', array_keys(LaporanKerusakan::KERUSAKAN)),
            'foto' => 'required|array|min:1|max:3',
            'foto.*' => 'image|max:12288',
            'catatan' => 'nullable|string|max:500',
        ], [
            'jenisId.required' => 'Pilih perlengkapan yang rusak.',
            'kerusakan.required' => 'Pilih jenis kerusakannya.',
            'foto.required' => 'Ambil minimal 1 foto kondisi sekarang.',
            'foto.max' => 'Maksimal 3 foto.',
        ]);

        $tempat = $item ? ['item' => $item] : [
            'jenis_id' => $this->jenisId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'alamat' => trim($this->alamat),
            'kecamatan_id' => $this->kecamatan_id,
            'desa_id' => $this->desa_id,
        ];
        $laporan = LaporanKerusakan::buat(
            $tempat, auth()->user(), $this->kerusakan, trim($this->catatan),
            collect($this->foto)->map(fn ($f, $n) => ['file' => $f] + ($this->fotoKoordinat[$n] ?? []))->all(),
        );
        $this->terkirim = $laporan->id;
    }

    public function with(): array
    {
        return [
            'jenis' => JenisPerlengkapan::aktif()->get(),
            'kecamatan' => Kecamatan::orderBy('nama')->get(),
            'desa' => $this->kecamatan_id ? Desa::where('kecamatan_id', $this->kecamatan_id)->orderBy('nama')->get() : collect(),
        ];
    }

    public function render()
    {
        return $this->view()->title('Ajukan · SIPERJAL');
    }
};
?>

@php $it = $this->item; @endphp

<div class="mx-auto max-w-2xl px-4 pb-40 pt-3 lg:pt-8">
@if ($terkirim)
    @php $l = \App\Models\LaporanKerusakan::with('jenis', 'kecamatan', 'desa', 'foto', 'pelapor')->find($terkirim); @endphp
    <div class="-mx-4 flex min-h-[calc(100dvh-40px)] flex-col pb-40 lg:mx-0 lg:min-h-0">
        <div class="flex items-center justify-between px-4 py-1">
            <span class="flex items-center gap-1.5 rounded-sm bg-success-bg px-2.5 py-1.5 font-caption text-[13px] font-medium text-success">@svg('lucide-circle-check', 'size-3.5') Dikirim {{ $l->created_at->translatedFormat('j M Y · H.i') }}</span>
            <a href="{{ route('pemeliharaan') }}" wire:navigate class="grid size-11 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line" aria-label="Tutup">@svg('lucide-x', 'size-5')</a>
        </div>

        <div class="flex flex-col gap-4 px-4 pb-6 pt-3" x-data x-init="$nextTick(() => $refs.centang?.animate([{ transform: 'scale(.4)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }], { duration: 400, easing: 'cubic-bezier(.2,1.4,.4,1)' }))">
            <span x-ref="centang" class="grid size-20 place-items-center rounded-full bg-accent text-fg-inverse shadow-lg">@svg('lucide-check', 'size-11')</span>
            <div class="flex flex-col gap-2">
                <h1 class="judul text-5xl leading-[48px]">Laporan terkirim!</h1>
                <p class="text-base leading-[23px] text-fg-muted">Terima kasih, {{ str(auth()->user()->name)->before(' ') }}. Laporanmu sudah kami terima dan diteruskan ke Dishub.</p>
            </div>
        </div>

        <div class="flex flex-col gap-3.5 bg-surface-soft px-4 py-[18px]" x-data>
            <div class="flex items-end gap-3">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="font-caption text-xs font-semibold uppercase tracking-[1px] text-fg-muted">Nomor laporan</span>
                    <span class="judul text-[34px] leading-9">{{ $l->nomor }}</span>
                </div>
                <button x-on:click="navigator.clipboard.writeText('{{ $l->nomor }}'); $dispatch('toast', { pesan: 'Nomor disalin' })" class="flex h-11 items-center gap-1.5 rounded-sm bg-bg px-3.5 text-base font-semibold text-accent shadow-sm outline outline-1 -outline-offset-1 outline-line">@svg('lucide-copy', 'size-[18px]') Salin</button>
            </div>
            <span class="h-px bg-fg/10"></span>
            <div class="flex items-center gap-3">
                @if ($f = $l->foto->first()) <img src="{{ $f->url() }}" class="size-14 shrink-0 rounded-md object-cover" alt=""> @endif
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-base font-semibold">{{ $l->jenis?->namaPendek() ?? 'Perlengkapan' }} · {{ $l->lokasi() }}</span>
                    <span class="text-sm leading-5 text-fg-muted">{{ $l->kerusakanLabel() }} · {{ $l->foto->count() }} foto</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4 px-4 pb-3 pt-5">
            <span class="judul text-xl tracking-[0.5px]">Perjalanan laporan</span>
            <x-pemeliharaan.stepper :laporan="$l" />
            <div class="flex gap-2.5 pt-1">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent-soft text-accent">@svg('lucide-bell-ring', 'size-4')</span>
                <p class="text-sm leading-5 text-fg-muted">Dishub akan menugaskan petugas lapangan untuk cek lokasi, biasanya 1–3 hari kerja. Kabar terbaru kami kirim lewat email.</p>
            </div>
        </div>

        <div class="fixed inset-x-0 bottom-0 z-[900] flex flex-col gap-2 border-t border-line bg-bg px-4 pb-7 pt-3 lg:static lg:mt-4 lg:border-0 lg:px-4">
            <a href="https://wa.me/?text={{ urlencode('Saya melaporkan '.str($l->judul())->lower().' di '.$l->lokasi().' ('.$l->nomor.') lewat SIPERJAL.') }}" target="_blank" class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Bagikan ke WhatsApp @svg('lucide-message-circle', 'size-[18px]')</a>
            <a href="{{ route('pemeliharaan', $l) }}" wire:navigate class="flex h-12 items-center justify-center gap-2 rounded-sm text-base font-semibold outline outline-1 -outline-offset-1 outline-line">@svg('lucide-file-text', 'size-[18px]') Lihat Laporan Saya</a>
        </div>
    </div>
@else
    {{-- ── Kepala ── --}}
    <div class="sticky top-0 z-[800] -mx-4 flex flex-col gap-3 bg-bg px-4 pb-3 pt-1 lg:static lg:mx-0 lg:px-0">
        <div class="flex items-center gap-3">
            <button wire:click="kembali" class="grid size-10 shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted" aria-label="Kembali">@svg('lucide-arrow-left', 'size-5')</button>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">{{ $langkah === 1 ? 'Ajukan' : 'Lapor kerusakan' }}</span>
                <span class="text-base font-semibold">Langkah {{ $langkah }} dari 2</span>
            </div>
        </div>
        <div class="flex gap-1.5">
            @foreach ([1, 2] as $n)
                <span @class(['h-1 flex-1 rounded-[2px] transition', 'bg-accent' => $n <= $langkah, 'bg-line' => $n > $langkah])></span>
            @endforeach
        </div>
    </div>

    @if ($langkah === 1)
        <div wire:key="langkah-1">
        <div class="flex flex-col gap-1.5 pb-4 pt-3">
            <h1 class="judul text-[34px] leading-9">Mau ajukan apa?</h1>
            <p class="text-base text-fg-muted">Pilih jenisnya, lalu tandai lokasinya di peta.</p>
        </div>

        <x-pilih-ajukan :aktif="$pilihan === 'lapor' ? 'lapor' : null" di-lapor />

        @if ($pilihan === 'lapor')
            <div class="mt-5 flex items-center justify-between">
                <span class="text-base font-semibold">Di mana lokasinya?</span>
                <span class="font-caption text-[13px] text-fg-muted">{{ count($this->titik) }} terpasang</span>
            </div>
            <div class="relative -mx-4 mt-2.5 h-[340px] overflow-hidden lg:mx-0 lg:rounded-md lg:outline lg:outline-1 lg:outline-line" wire:ignore
                 x-data="petaPilih({ lat: @js($lat), lng: @js($lng), pusat: @js(config('usuljalan.peta_awal')), titik: @js($this->titik), aktif: @js($itemId) })"
                 @pilih-titik="$wire.pilih($event.detail.id)"
                 @sorot-titik.window="sorot($event.detail.id)"
                 @lokasi-diatur.window="taruh($event.detail.lat, $event.detail.lng, false, true)">
                <div x-ref="peta" class="absolute inset-0"></div>
                <span class="pointer-events-none absolute bottom-3 left-3 z-[500] flex items-center gap-1.5 rounded-sm bg-bg px-2.5 py-1.5 font-caption text-[13px] font-semibold shadow-sm">@svg('lucide-pointer', 'size-3.5 text-accent') Ketuk lokasi atau pin perlengkapan</span>
                <button type="button" @click="lokasiSaya()" class="absolute right-3 top-3 z-[500] grid size-11 place-items-center rounded-sm bg-bg shadow-sm" aria-label="Lokasi saya">@svg('lucide-locate-fixed', 'size-5')</button>
                <button type="button" @click="cariBuka = !cariBuka; $nextTick(() => $refs.cari?.focus())" class="absolute right-3 top-[60px] z-[500] grid size-11 place-items-center rounded-sm bg-bg shadow-sm" aria-label="Cari alamat">@svg('lucide-search', 'size-5')</button>
                <div x-show="cariBuka" x-cloak class="absolute inset-x-3 top-3 z-[600] mr-14">
                    <input x-ref="cari" x-model="cari" @input.debounce.500ms="cariAlamat" class="w-full rounded-sm bg-bg px-3 py-2.5 text-sm shadow outline-none" placeholder="Cari alamat / nama tempat">
                    <ul x-show="hasil.length" class="mt-1 max-h-40 overflow-auto rounded-sm bg-bg shadow-lg">
                        <template x-for="h in hasil"><li><button type="button" @click="pilihHasil(h); cariBuka = false" class="w-full px-3 py-2 text-left text-xs hover:bg-surface-muted" x-text="h.display_name"></button></li></template>
                    </ul>
                </div>
            </div>

            @if ($it)
                {{-- Tertaut ke perlengkapan terpasang --}}
                <div class="mt-3 flex flex-col gap-3 rounded-md bg-bg p-3.5 outline outline-[1.5px] -outline-offset-1 outline-accent" wire:key="dipilih-{{ $it->id }}">
                    <div class="flex items-center gap-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$it->jenis->ikon, 'size-[22px]')</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-accent">Perlengkapan terpasang</span>
                            <span class="text-base font-semibold">{{ $it->jenis->namaPendek() }}</span>
                            <span class="truncate text-sm text-fg-muted">{{ $it->usulan->alamat ?: $it->usulan->lokasiSingkat() }}</span>
                        </span>
                        <button type="button" wire:click="lepas" class="h-11 shrink-0 px-2 text-sm font-semibold text-accent">Bukan yang ini</button>
                    </div>
                    <div class="flex items-center gap-2 rounded-sm bg-success-bg px-2.5 py-2 text-[13px] text-success">
                        @svg('lucide-map-pin-check', 'size-4 shrink-0')
                        <span>Terpasang {{ $it->terpasang_at?->translatedFormat('j M Y') }} · dari usulan {{ $it->usulan->nomor }}</span>
                    </div>
                    @if ($ada = $it->laporanTerbuka)
                        <div class="flex items-start gap-2 rounded-sm bg-warning-bg px-2.5 py-2 text-[13px] text-warning">
                            @svg('lucide-wrench', 'mt-px size-4 shrink-0')
                            <span>Sudah dilaporkan ({{ $ada->nomor }} · {{ $ada->status->label() }}). Tidak perlu melapor lagi — <a href="{{ $ada->user_id === auth()->id() ? route('pemeliharaan', $ada) : route('lapor.status', $ada->nomor) }}" wire:navigate class="font-semibold underline">lihat perkembangannya</a>.</span>
                        </div>
                    @endif
                    <dl class="grid grid-cols-3 gap-2 font-caption text-[13px]">
                        <div><dt class="text-fg-muted">Kecamatan</dt><dd class="font-semibold">{{ $it->usulan->kecamatan?->nama ?? '—' }}</dd></div>
                        <div><dt class="text-fg-muted">Desa</dt><dd class="font-semibold">{{ $it->usulan->desa?->nama ?? '—' }}</dd></div>
                        <div><dt class="text-fg-muted">Koordinat</dt><dd class="font-semibold">{{ number_format($lat, 3) }}, {{ number_format($lng, 3) }}</dd></div>
                    </dl>
                </div>
            @elseif ($lat)
                {{-- Titik bebas: alamat + Kecamatan → Desa → Koordinat (dropdown hanya bila tidak terbaca) --}}
                <div class="mt-3 flex flex-col gap-2.5 rounded-md bg-bg p-3 outline outline-1 -outline-offset-1 outline-line" wire:key="titik-bebas">
                    <div class="flex flex-col gap-1">
                        <span class="flex items-center gap-1 font-caption text-[10px] font-semibold uppercase tracking-[0.6px] text-accent">@svg('lucide-sparkles', 'size-3') Terisi otomatis</span>
                        <input wire:model.blur="alamat" class="w-full bg-transparent text-[15px] font-semibold outline-none placeholder:font-normal placeholder:text-fg-muted" placeholder="Tulis nama jalan / patokan">
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        @if ($kecamatanManual)
                            <label class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5 outline outline-1 -outline-offset-1 outline-warning">
                                <span class="font-caption text-[10px] text-fg-muted">Kecamatan</span>
                                <select wire:model.live="kecamatan_id" class="-ml-1 bg-transparent text-xs font-semibold outline-none">
                                    <option value="">Pilih…</option>@foreach ($kecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach
                                </select>
                            </label>
                        @else
                            <div class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                                <span class="font-caption text-[10px] text-fg-muted">Kecamatan</span>
                                <span class="truncate text-xs font-semibold">{{ $kecamatan->firstWhere('id', $kecamatan_id)?->nama }}</span>
                            </div>
                        @endif
                        @if ($desaManual)
                            <label class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5 outline outline-1 -outline-offset-1 outline-warning">
                                <span class="font-caption text-[10px] text-fg-muted">Desa</span>
                                <select wire:model.live="desa_id" @disabled(! $kecamatan_id) class="-ml-1 bg-transparent text-xs font-semibold outline-none disabled:text-fg-muted">
                                    <option value="">{{ $kecamatan_id ? 'Pilih…' : 'Pilih kecamatan dulu' }}</option>@foreach ($desa as $d)<option value="{{ $d->id }}">{{ $d->nama }}</option>@endforeach
                                </select>
                            </label>
                        @else
                            <div class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                                <span class="font-caption text-[10px] text-fg-muted">Desa</span>
                                <span class="truncate text-xs font-semibold">{{ $desa->firstWhere('id', $desa_id)?->nama }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                            <span class="font-caption text-[10px] text-fg-muted">Koordinat</span>
                            <span class="truncate text-xs font-semibold">{{ number_format($lat, 3, '.', '') }}, {{ number_format($lng, 3, '.', '') }}</span>
                        </div>
                    </div>
                    @if ($kecamatanManual || $desaManual)
                        <p class="flex items-center gap-1.5 text-xs text-warning">@svg('lucide-info', 'size-3.5 shrink-0') {{ $kecamatanManual ? 'Kecamatan' : 'Desa' }} tidak terbaca dari peta, pilih sendiri.</p>
                    @endif
                    @error('kecamatan_id') <div class="text-xs text-danger">{{ $message }}</div> @enderror
                </div>

                @if ($this->sekitar->isNotEmpty())
                    <div class="mt-3 flex flex-col gap-2">
                        <span class="text-sm font-semibold">Perlengkapan terpasang di dekat titik ini <span class="font-caption text-xs font-normal text-fg-muted">ketuk bila ini yang rusak</span></span>
                        @foreach ($this->sekitar as $s)
                            <button type="button" wire:click="pilih({{ $s->id }})" wire:key="sekitar-{{ $s->id }}" class="flex items-center gap-3 rounded-md bg-bg p-3 text-left outline outline-1 -outline-offset-1 outline-line hover:outline-accent/50">
                                <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$s->jenis->ikon, 'size-5')</span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="text-[15px] font-semibold">{{ $s->jenis->namaPendek() }}</span>
                                    <span class="truncate font-caption text-xs text-fg-muted">±{{ round($s->jarak) }} m · terpasang {{ $s->terpasang_at?->translatedFormat('j M Y') }}{{ $s->laporanTerbuka ? ' · sudah dilaporkan' : '' }}</span>
                                </span>
                                @svg('lucide-chevron-right', 'size-5 text-fg-muted')
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <p class="mt-3 text-[15px] text-fg-muted"><b class="text-fg">Ketuk peta</b> di lokasi perlengkapan yang rusak, pakai tombol lokasi saya, atau ketuk pin perlengkapan yang sudah terpasang.</p>
            @endif
            @error('lat') <div class="mt-2 text-sm text-danger">{{ $message }}</div> @enderror

            <div class="fixed inset-x-0 bottom-0 z-[900] border-t border-line bg-bg px-4 pb-7 pt-3 lg:static lg:mt-5 lg:border-0 lg:p-0">
                <button wire:click="lanjut" @disabled(! $lat || $it?->laporanTerbuka) class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-50">
                    {{ $lat ? 'Lanjut: Isi laporan' : 'Tandai lokasi di peta' }} @svg('lucide-arrow-right', 'size-[18px]')
                </button>
            </div>
        @endif
        </div>
    @else
        <div wire:key="langkah-2">
        <div class="flex flex-col gap-1.5 pb-4 pt-3">
            <h1 class="judul text-[34px] leading-9">Apa yang rusak?</h1>
            <p class="text-base text-fg-muted">Isi singkat saja — petugas akan cek ke lokasi.</p>
        </div>

        <div class="flex items-center gap-3 rounded-md bg-surface-soft p-3">
            <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-bg text-accent">@svg('lucide-'.($it ? $it->jenis->ikon : 'map-pin'), 'size-[22px]')</span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-base font-semibold">{{ $it ? $it->jenis->namaPendek() : 'Lokasi laporan' }}</span>
                <span class="truncate text-sm text-fg-muted">{{ collect([$alamat ?: null, $desa->firstWhere('id', $desa_id)?->nama, $kecamatan->firstWhere('id', $kecamatan_id)?->nama ? 'Kec. '.$kecamatan->firstWhere('id', $kecamatan_id)->nama : null])->filter()->implode(', ') }}</span>
                @if ($it) <span class="font-caption text-xs text-fg-muted">Terpasang dari {{ $it->usulan->nomor }}</span> @endif
            </span>
            <button wire:click="kembali" class="h-11 px-2 text-base font-semibold text-accent">Ganti</button>
        </div>

        @unless ($it)
            <div class="mt-6 flex flex-col gap-2.5">
                <span class="text-base font-semibold">Perlengkapan apa?<span class="text-danger">*</span></span>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($jenis as $j)
                        @php $on = $jenisId === $j->id; @endphp
                        <button type="button" wire:click="$set('jenisId', {{ $j->id }})" wire:key="j-{{ $j->id }}" @class([
                            'relative flex h-[84px] flex-col items-center justify-center gap-1.5 rounded-md px-1.5 transition active:scale-95',
                            'bg-accent-soft text-accent outline outline-2 -outline-offset-1 outline-accent' => $on,
                            'bg-bg text-fg shadow-sm outline outline-1 -outline-offset-1 outline-line' => ! $on,
                        ])>
                            @if ($on) <span class="absolute right-1.5 top-1.5 grid size-[18px] place-items-center rounded-full bg-accent text-fg-inverse">@svg('lucide-check', 'size-3')</span> @endif
                            <span @class(['text-accent' => ! $on])>@svg('lucide-'.$j->ikon, 'size-7')</span>
                            <span @class(['w-full text-center font-caption text-xs leading-tight', 'font-semibold' => $on, 'font-medium' => ! $on])>{{ $j->namaPendek() }}</span>
                        </button>
                    @endforeach
                </div>
                @error('jenisId') <span class="text-sm text-danger">{{ $message }}</span> @enderror
            </div>
        @endunless

        <div class="mt-6 flex flex-col gap-2.5">
            <span class="text-base font-semibold">Jenis kerusakan<span class="text-danger">*</span></span>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Models\LaporanKerusakan::KERUSAKAN as $k => [$label, , $ikon])
                    <button type="button" wire:click="$set('kerusakan', '{{ $k }}')" @class([
                        'flex h-11 items-center gap-2 rounded-full px-4 text-[15px]',
                        'bg-accent font-semibold text-fg-inverse' => $kerusakan === $k,
                        'bg-bg font-medium outline outline-1 -outline-offset-1 outline-line' => $kerusakan !== $k,
                    ])>@svg('lucide-'.$ikon, 'size-4') {{ $label }}</button>
                @endforeach
            </div>
            @error('kerusakan') <span class="text-sm text-danger">{{ $message }}</span> @enderror
        </div>

        <div class="mt-6 flex flex-col gap-2.5"
             x-data="{
                mengunggah: false,
                async pilih(e) {
                    const files = [...e.target.files].slice(0, 3 - $wire.foto.length);
                    if (!files.length) return;
                    this.mengunggah = true;
                    const koordinat = await Promise.all(files.map(f => window.koordinatFoto(f)));
                    $wire.set('fotoKoordinat', [...$wire.fotoKoordinat, ...koordinat], false);
                    $wire.uploadMultiple('foto', files, () => this.mengunggah = false, () => this.mengunggah = false);
                    e.target.value = '';
                },
             }">
            <div class="flex items-center justify-between">
                <span class="text-base font-semibold">Foto kondisi sekarang<span class="text-danger">*</span></span>
                <span class="font-caption text-[13px] text-fg-muted">{{ count($foto) }} dari 3 · wajib min. 1</span>
            </div>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($foto as $n => $f)
                    <div class="relative aspect-square overflow-hidden rounded-md" wire:key="fl-{{ $n }}">
                        <img src="{{ $f->temporaryUrl() }}" class="size-full object-cover" alt="">
                        @if ($fotoKoordinat[$n] ?? null) <span class="absolute bottom-1.5 left-1.5 flex items-center gap-1 rounded-sm bg-fg/75 px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg-inverse">@svg('lucide-map-pin', 'size-3') GPS</span> @endif
                        <button wire:click="hapusFoto({{ $n }})" class="absolute right-0 top-0 grid size-11 place-items-center" aria-label="Hapus foto"><span class="grid size-7 place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-4')</span></button>
                    </div>
                @endforeach
                @if (count($foto) < 3)
                    <label class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1.5 rounded-md bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-dashed outline-accent">
                        @svg('lucide-camera', 'size-6') <span class="text-sm font-semibold" x-text="mengunggah ? ($store.sinyal.ada ? 'Mengunggah…' : 'Menunggu sinyal…') : 'Tambah foto'">Tambah foto</span>
                        <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                    </label>
                @endif
            </div>
            @error('foto') <span class="text-sm text-danger">{{ $message }}</span> @enderror
            @error('foto.*') <span class="text-sm text-danger">{{ $message }}</span> @enderror
        </div>

        <div class="mt-6 flex flex-col gap-2.5">
            <span class="flex items-center gap-2 text-base font-semibold">Catatan <span class="font-caption text-[13px] font-normal text-fg-muted">Opsional</span></span>
            <textarea wire:model="catatan" rows="3" maxlength="500" class="input text-base" placeholder="Contoh: lampu mati sejak 3 hari, jalan jadi gelap di malam hari."></textarea>
        </div>

        <div class="mt-6 flex items-center gap-3 rounded-md bg-surface-muted p-3">
            <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-full bg-secondary font-caption text-sm font-semibold text-on-secondary">
                @if (auth()->user()->fotoUrl()) <img src="{{ auth()->user()->fotoUrl() }}" class="size-full object-cover" alt=""> @else {{ auth()->user()->inisial() }} @endif
            </span>
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-fg-muted">Dikirim sebagai</span>
                <span class="text-base font-semibold">{{ auth()->user()->name }}</span>
                <span class="text-sm text-fg-muted">{{ auth()->user()->no_hp ?: auth()->user()->email }} · dari akun</span>
            </span>
        </div>

        @error('kirim') <div class="mt-4 flex items-start gap-2 rounded-sm bg-danger-bg p-3 text-sm text-danger">@svg('lucide-circle-alert', 'mt-px size-4 shrink-0') {{ $message }}</div> @enderror
        <div class="fixed inset-x-0 bottom-0 z-[900] border-t border-line bg-bg px-4 pb-7 pt-3 lg:static lg:mt-6 lg:border-0 lg:p-0">
            <button wire:click="kirim" wire:loading.attr="disabled" wire:target="kirim,foto" class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-60">
                <span wire:loading.remove wire:target="kirim">Kirim Laporan</span><span wire:loading wire:target="kirim">Mengirim…</span> @svg('lucide-send', 'size-[18px] rotate-45')
            </button>
        </div>
        </div>
    @endif
@endif
</div>

<?php

use App\Enums\Role;
use App\Models\Desa;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LogPengaturan;
use App\Models\PerlengkapanTerpasang;
use App\Support\ImporTerpasang;
use App\Support\Wilayah;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Perlengkapan terpasang: yang dari usulan (otomatis) dan yang terpasang sebelum SIPERJAL (data lama).
 * Dishub mengelola data lama (tambah, ubah, hapus, impor Excel); admin hanya melihat.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $cari = '';

    #[Url(as: 'jenis')]
    public ?int $jenisFilter = null;

    #[Url]
    public string $sumber = 'semua'; // semua | lama | usulan

    public ?string $dialog = null; // form | impor

    // Form
    public ?int $ubahId = null;
    public ?int $jenisId = null;
    public int $jumlah = 1;
    public ?float $lat = null;
    public ?float $lng = null;
    public string $alamat = '';
    public ?int $kecamatan_id = null;
    public ?int $desa_id = null;
    public ?int $tahun = null;
    public string $catatan = '';

    // Impor
    public $berkas = null;
    public array $sah = [];
    public array $ringkas = [];
    public array $masalah = [];
    public ?string $batchTerakhir = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasRole(Role::Dishub, Role::Admin), 403);
    }

    /** Hanya Dishub yang mengelola; admin melihat. */
    #[Computed]
    public function kelola(): bool
    {
        return auth()->user()->hasRole(Role::Dishub);
    }

    #[Computed]
    public function daftar()
    {
        return PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan', 'usulanItem.usulan', 'laporanTerbuka')
            ->when($this->jenisFilter, fn ($q) => $q->where('jenis_perlengkapan_id', $this->jenisFilter))
            ->when($this->sumber === 'lama', fn ($q) => $q->lama())
            ->when($this->sumber === 'usulan', fn ($q) => $q->whereNotNull('usulan_item_id'))
            ->when(trim($this->cari) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('alamat', 'like', "%{$this->cari}%")
                ->orWhereHas('desa', fn ($d) => $d->where('nama', 'like', "%{$this->cari}%"))
                ->orWhereHas('kecamatan', fn ($d) => $d->where('nama', 'like', "%{$this->cari}%"))
                ->orWhereHas('jenis', fn ($d) => $d->where('nama', 'like', "%{$this->cari}%"))))
            ->latest('id')->limit(300)->get();
    }

    #[Computed]
    public function jumlahSemua(): array
    {
        return ['semua' => PerlengkapanTerpasang::count(), 'lama' => PerlengkapanTerpasang::lama()->count()];
    }

    // ── Tambah / ubah satu
    public function tambah(): void
    {
        abort_unless($this->kelola, 403);
        $this->resetForm();
        $this->dialog = 'form';
    }

    public function ubah(int $id): void
    {
        abort_unless($this->kelola, 403);
        $a = PerlengkapanTerpasang::lama()->findOrFail($id);
        $this->ubahId = $a->id;
        $this->jenisId = $a->jenis_perlengkapan_id;
        $this->jumlah = $a->jumlah;
        [$this->lat, $this->lng] = [$a->lat, $a->lng];
        $this->alamat = (string) $a->alamat;
        [$this->kecamatan_id, $this->desa_id] = [$a->kecamatan_id, $a->desa_id];
        $this->tahun = $a->tahun;
        $this->catatan = (string) $a->catatan;
        $this->resetErrorBag();
        $this->dialog = 'form';
    }

    public function aturLokasi(float $lat, float $lng, array $alamat = []): void
    {
        abort_unless($this->kelola, 403);
        $this->lat = round($lat, 7);
        $this->lng = round($lng, 7);
        $this->alamat = $alamat['jalan'] ?? $this->alamat;
        [$this->kecamatan_id, $this->desa_id] = Wilayah::cocokkan($alamat['kecamatan'] ?? null, $alamat['desa'] ?? null);
        $this->resetErrorBag();
    }

    public function updatedKecamatanId(): void
    {
        $this->desa_id = null;
    }

    public function simpan(): void
    {
        abort_unless($this->kelola, 403);
        $this->validate([
            'jenisId' => 'required|exists:jenis_perlengkapan,id',
            'jumlah' => 'required|integer|min:1|max:999',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'alamat' => 'nullable|string|max:255',
            'kecamatan_id' => 'nullable|exists:kecamatan,id',
            'desa_id' => 'nullable|exists:desa,id',
            'tahun' => 'nullable|integer|min:1970|max:'.now()->year,
            'catatan' => 'nullable|string|max:500',
        ], [
            'jenisId.required' => 'Pilih jenis perlengkapannya.',
            'lat.required' => 'Tandai lokasinya di peta dulu.',
            'tahun.max' => 'Tahun tidak boleh lebih dari tahun ini.',
        ]);
        $data = [
            'jenis_perlengkapan_id' => $this->jenisId,
            'jumlah' => $this->jumlah,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'alamat' => trim($this->alamat) ?: null,
            'kecamatan_id' => $this->kecamatan_id,
            'desa_id' => $this->desa_id,
            'tahun' => $this->tahun,
            'catatan' => trim($this->catatan) ?: null,
        ];
        $nama = JenisPerlengkapan::find($this->jenisId)->namaPendek();
        if ($this->ubahId) {
            PerlengkapanTerpasang::lama()->findOrFail($this->ubahId)->update($data);
            LogPengaturan::catat("Perlengkapan terpasang {$nama} diubah");
            $pesan = 'Perubahan tersimpan';
        } else {
            PerlengkapanTerpasang::create($data);
            LogPengaturan::catat("Perlengkapan terpasang {$nama} ditambahkan (data lama)");
            $pesan = "{$nama} ditambahkan";
        }
        $this->tutup();
        unset($this->daftar, $this->jumlahSemua);
        $this->dispatch('toast', pesan: $pesan);
    }

    public function hapus(int $id): void
    {
        abort_unless($this->kelola, 403);
        $a = PerlengkapanTerpasang::lama()->with('jenis')->findOrFail($id);
        if ($a->laporanKerusakan()->exists()) {
            $this->dispatch('toast', pesan: 'Sudah punya laporan kerusakan, tidak bisa dihapus.', gagal: true);

            return;
        }
        $a->delete();
        LogPengaturan::catat("Perlengkapan terpasang {$a->jenis->namaPendek()} dihapus (data lama)");
        unset($this->daftar, $this->jumlahSemua);
        $this->dispatch('toast', pesan: 'Perlengkapan dihapus');
    }

    // ── Impor Excel/CSV
    public function bukaImpor(): void
    {
        abort_unless($this->kelola, 403);
        $this->reset('berkas', 'sah', 'ringkas', 'masalah');
        $this->resetErrorBag();
        $this->dialog = 'impor';
    }

    public function updatedBerkas(): void
    {
        abort_unless($this->kelola, 403);
        $this->reset('sah', 'ringkas', 'masalah');
        $this->validate(['berkas' => 'required|file|max:5120|mimes:csv,txt,xlsx'], [
            'berkas.mimes' => 'Berkas harus .xlsx atau .csv.',
            'berkas.max' => 'Ukuran berkas maksimal 5 MB.',
        ]);
        try {
            $r = ImporTerpasang::baca($this->berkas->getRealPath(), $this->berkas->getClientOriginalExtension());
        } catch (\Throwable) {
            $this->addError('berkas', 'Berkas tidak bisa dibaca. Pakai template yang disediakan.');

            return;
        }
        $baris = collect($r['baris']);
        $this->sah = $baris->where('status', 'sah')->pluck('data')->values()->all();
        $this->ringkas = [
            'sah' => count($this->sah),
            'lewati' => $baris->where('status', 'lewati')->count(),
            'galat' => $baris->where('status', 'galat')->count(),
            'terlalu_banyak' => $r['terlalu_banyak'],
        ];
        $this->masalah = $baris->where('status', '!=', 'sah')->take(100)
            ->map(fn ($b) => ['no' => $b['no'], 'status' => $b['status'], 'alasan' => $b['alasan'], 'jenis' => $b['data']['jenis'] ?? ''])->values()->all();
        if ($baris->isEmpty()) {
            $this->addError('berkas', 'Tidak ada baris data. Pakai template yang disediakan.');
        }
    }

    public function terapkanImpor(): void
    {
        abort_unless($this->kelola, 403);
        if (! $this->sah) {
            return;
        }
        $n = count($this->sah);
        $this->batchTerakhir = ImporTerpasang::simpan($this->sah);
        $this->tutup();
        unset($this->daftar, $this->jumlahSemua);
        $this->dispatch('toast', pesan: "{$n} perlengkapan diimpor", urungkan: 'impor-terpasang:'.$this->batchTerakhir);
    }

    #[\Livewire\Attributes\On('urungkan')]
    public function urungkan(string $token): void
    {
        abort_unless($this->kelola, 403);
        if (! str_starts_with($token, 'impor-terpasang:') || substr($token, 16) !== $this->batchTerakhir) {
            return;
        }
        $n = ImporTerpasang::batalkan($this->batchTerakhir);
        $this->batchTerakhir = null;
        unset($this->daftar, $this->jumlahSemua);
        $this->dispatch('toast', pesan: "Impor dibatalkan ({$n} dihapus)");
    }

    public function tutup(): void
    {
        $this->dialog = null;
        $this->resetForm();
        $this->reset('berkas', 'sah', 'ringkas', 'masalah');
    }

    private function resetForm(): void
    {
        $this->reset('ubahId', 'jenisId', 'lat', 'lng', 'alamat', 'kecamatan_id', 'desa_id', 'tahun', 'catatan');
        $this->jumlah = 1;
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'semuaJenis' => JenisPerlengkapan::orderBy('urutan')->get(),
            'semuaKecamatan' => Kecamatan::orderBy('nama')->get(),
            'desaPilihan' => $this->kecamatan_id ? Desa::where('kecamatan_id', $this->kecamatan_id)->orderBy('nama')->get() : collect(),
        ];
    }

    public function render()
    {
        return $this->view()->title('Terpasang · SIPERJAL');
    }
};
?>

<div class="mx-auto flex max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6 lg:py-10">
    <x-kategori aktif="terpasang" class="max-w-md" />

    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div class="flex flex-col gap-1">
            <h1 class="judul text-[28px] leading-[30px] lg:text-[40px] lg:leading-[40px]">Perlengkapan terpasang</h1>
            <span class="text-sm text-fg-muted">{{ number_format($this->jumlahSemua['semua'], 0, ',', '.') }} tercatat · {{ number_format($this->jumlahSemua['lama'], 0, ',', '.') }} terpasang sebelum SIPERJAL. Semuanya tampil di peta beranda dan bisa dilaporkan rusak.</span>
        </div>
        @if ($this->kelola)
            <div class="flex shrink-0 gap-2">
                <x-tombol variant="kedua" wire:click="bukaImpor" ikon="file-spreadsheet">Impor Excel</x-tombol>
                <x-tombol wire:click="tambah" ikon="plus">Tambah</x-tombol>
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-2 sm:flex-row">
        <label class="flex h-10 flex-1 items-center gap-2 rounded-sm bg-bg px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
            @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
            <input wire:model.live.debounce.300ms="cari" class="w-full bg-transparent text-sm outline-none placeholder:text-fg-muted" placeholder="Cari alamat, desa, kecamatan, atau perlengkapan">
        </label>
        <select wire:model.live="jenisFilter" class="input h-10 py-0 text-sm sm:w-56" aria-label="Saring perlengkapan">
            <option value="">Semua perlengkapan</option>
            @foreach ($semuaJenis as $j) <option value="{{ $j->id }}">{{ $j->nama }}</option> @endforeach
        </select>
        <div class="flex shrink-0 gap-0.5 rounded-sm bg-surface-muted p-[3px] font-caption text-[13px]">
            @foreach (['semua' => 'Semua', 'lama' => 'Sebelum SIPERJAL', 'usulan' => 'Dari usulan'] as $v => $l)
                <button type="button" wire:click="$set('sumber', '{{ $v }}')" @class(['rounded-sm px-2.5 py-1.5', 'bg-bg font-semibold shadow-sm' => $sumber === $v, 'font-medium text-fg-muted hover:text-fg' => $sumber !== $v])>{{ $l }}</button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col divide-y divide-line rounded-md bg-bg outline outline-1 -outline-offset-1 outline-line">
        @forelse ($this->daftar as $a)
            <div class="flex items-center gap-3 p-3.5" wire:key="aset-{{ $a->id }}">
                <x-ikon-jenis :jenis="$a->jenis" class="size-10 shrink-0" />
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-[15px] font-semibold">{{ $a->jenis->namaPendek() }} · {{ $a->jumlah }} {{ $a->jenis->satuan }}</span>
                    <span class="truncate text-sm text-fg-muted">{{ $a->lokasi() }}</span>
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-caption text-xs text-fg-muted">
                        @if ($a->usulanItem)
                            <span>Dari usulan <a href="{{ route('usulan', $a->usulanItem->usulan) }}" wire:navigate class="font-semibold text-accent">{{ $a->usulanItem->usulan->nomor }}</a></span>
                        @else
                            <span class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-semibold">Sebelum SIPERJAL</span>
                        @endif
                        @if ($a->waktuPasang()) <span>· {{ $a->waktuPasang() }}</span> @endif
                        @if ($a->laporanTerbuka) <x-badge tone="perhatian">Dilaporkan rusak</x-badge> @endif
                    </span>
                </div>
                @if ($this->kelola && ! $a->usulan_item_id)
                    <div class="flex shrink-0 gap-1">
                        <button type="button" wire:click="ubah({{ $a->id }})" class="grid size-10 place-items-center rounded-sm text-fg-muted hover:bg-surface-muted hover:text-fg" aria-label="Ubah">@svg('lucide-pencil', 'size-[18px]')</button>
                        <button type="button" wire:click="hapus({{ $a->id }})" wire:confirm="Hapus {{ $a->jenis->namaPendek() }} di {{ $a->lokasi() }}?" class="grid size-10 place-items-center rounded-sm text-fg-muted hover:bg-danger-bg hover:text-danger" aria-label="Hapus">@svg('lucide-trash-2', 'size-[18px]')</button>
                    </div>
                @endif
            </div>
        @empty
            <x-kosong ikon="map-pin-check" judul="Belum ada perlengkapan" :teks="$this->kelola ? 'Tambahkan satu per satu atau impor dari Excel.' : 'Belum ada yang tercatat.'" />
        @endforelse
    </div>
    @if ($this->daftar->count() >= 300)
        <span class="text-center font-caption text-xs text-fg-muted">Menampilkan 300 terbaru. Persempit dengan cari atau saringan.</span>
    @endif

    {{-- ── Dialog tambah/ubah ── --}}
    @if ($dialog === 'form')

        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="tutup" x-data @keydown.escape.window="$wire.tutup()" wire:key="dialog-form">
            <form wire:submit="simpan" class="flex max-h-[92dvh] w-full flex-col gap-4 overflow-y-auto rounded-t-xl bg-bg p-6 sm:max-w-lg sm:rounded-md">
                <h3 class="judul text-2xl">{{ $ubahId ? 'Ubah perlengkapan' : 'Tambah perlengkapan terpasang' }}</h3>
                <span class="-mt-2 text-sm text-fg-muted">Data lama: terpasang sebelum SIPERJAL, tanpa usulan.</span>

                <div class="relative h-[240px] overflow-hidden rounded-md outline outline-1 outline-line" wire:ignore
                     x-data="petaPilih({ lat: @js($lat), lng: @js($lng), pusat: @js(config('usuljalan.peta_awal')) })">
                    <div x-ref="peta" class="absolute inset-0"></div>
                    <span class="pointer-events-none absolute bottom-2 left-2 z-[500] flex items-center gap-1.5 rounded-sm bg-bg px-2 py-1 font-caption text-xs font-semibold shadow-sm">@svg('lucide-pointer', 'size-3.5 text-accent') Ketuk lokasi di peta</span>
                    <button type="button" @click="cariBuka = !cariBuka; $nextTick(() => $refs.cari?.focus())" class="absolute right-2 top-2 z-[500] grid size-10 place-items-center rounded-sm bg-bg shadow-sm" aria-label="Cari alamat">@svg('lucide-search', 'size-[18px]')</button>
                    <div x-show="cariBuka" x-cloak class="absolute inset-x-2 top-2 z-[600] mr-12">
                        <input x-ref="cari" x-model="cari" @input.debounce.500ms="cariAlamat" class="w-full rounded-sm bg-bg px-3 py-2 text-sm shadow outline-none" placeholder="Cari alamat / nama tempat">
                        <ul x-show="hasil.length" class="mt-1 max-h-40 overflow-auto rounded-sm bg-bg shadow-lg">
                            <template x-for="h in hasil"><li><button type="button" @click="pilihHasil(h); cariBuka = false" class="w-full px-3 py-2 text-left text-xs hover:bg-surface-muted" x-text="h.display_name"></button></li></template>
                        </ul>
                    </div>
                </div>
                @error('lat') <span class="-mt-2 text-xs text-danger">{{ $message }}</span> @enderror

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1.5 sm:col-span-2">
                        <span class="text-sm font-semibold">Perlengkapan</span>
                        <select wire:model="jenisId" class="input"><option value="">Pilih…</option>@foreach ($semuaJenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select>
                        @error('jenisId') <span class="text-xs text-danger">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">Jumlah</span>
                        <input wire:model="jumlah" type="number" min="1" class="input">
                        @error('jumlah') <span class="text-xs text-danger">{{ $message }}</span> @enderror
                    </label>
                </div>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">Alamat / patokan</span>
                    <input wire:model="alamat" class="input" placeholder="Jl. Raya Batulicin, depan SDN 1">
                </label>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">Kecamatan</span>
                        <select wire:model.live="kecamatan_id" class="input"><option value="">—</option>@foreach ($semuaKecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select>
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">Desa</span>
                        <select wire:model="desa_id" @disabled(! $kecamatan_id) class="input disabled:text-fg-muted"><option value="">—</option>@foreach ($desaPilihan as $d)<option value="{{ $d->id }}">{{ $d->nama }}</option>@endforeach</select>
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">Tahun pasang</span>
                        <input wire:model="tahun" type="number" min="1970" max="{{ now()->year }}" class="input" placeholder="2019">
                        @error('tahun') <span class="text-xs text-danger">{{ $message }}</span> @enderror
                    </label>
                </div>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">Catatan</span>
                    <input wire:model="catatan" class="input" placeholder="Opsional">
                </label>
                <div class="flex justify-end gap-2 pt-1">
                    <x-tombol variant="teks" wire:click="tutup">Batal</x-tombol>
                    <x-tombol type="submit">Simpan</x-tombol>
                </div>
            </form>
        </div>
    @endif

    {{-- ── Dialog impor ── --}}
    @if ($dialog === 'impor')

        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="tutup" x-data @keydown.escape.window="$wire.tutup()" wire:key="dialog-impor">
            <div class="flex max-h-[92dvh] w-full flex-col gap-4 overflow-y-auto rounded-t-xl bg-bg p-6 sm:max-w-lg sm:rounded-md">
                <h3 class="judul text-2xl">Impor dari Excel</h3>
                <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm text-fg-muted">
                    <li><a href="{{ route('terpasang.template') }}" class="font-semibold text-accent" download>Unduh template</a>, isi satu baris per perlengkapan (hapus baris contoh).</li>
                    <li>Kolom wajib: <b>Perlengkapan</b> (sama dengan nama di Pengaturan), <b>Latitude</b>, <b>Longitude</b>. Lainnya opsional.</li>
                    <li>Unggah berkas .xlsx atau .csv, periksa pratinjau, lalu simpan.</li>
                </ol>
                <label class="flex cursor-pointer flex-col items-center gap-1 rounded-md border-2 border-dashed border-line p-5 text-center hover:border-accent">
                    @svg('lucide-upload', 'size-6 text-accent')
                    <span class="text-sm font-semibold" wire:loading.remove wire:target="berkas">{{ $berkas ? $berkas->getClientOriginalName() : 'Pilih berkas .xlsx / .csv' }}</span>
                    <span class="text-sm font-semibold" wire:loading wire:target="berkas">Membaca berkas…</span>
                    <input type="file" wire:model="berkas" accept=".xlsx,.csv,.txt" class="sr-only">
                </label>
                @error('berkas') <span class="text-sm text-danger">{{ $message }}</span> @enderror

                @if ($ringkas)
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-sm bg-success-bg p-2.5"><div class="judul text-2xl text-success">{{ $ringkas['sah'] }}</div><div class="font-caption text-xs text-fg-muted">siap diimpor</div></div>
                        <div class="rounded-sm bg-surface-muted p-2.5"><div class="judul text-2xl">{{ $ringkas['lewati'] }}</div><div class="font-caption text-xs text-fg-muted">dilewati</div></div>
                        <div class="rounded-sm bg-danger-bg p-2.5"><div class="judul text-2xl text-danger">{{ $ringkas['galat'] }}</div><div class="font-caption text-xs text-fg-muted">bermasalah</div></div>
                    </div>
                    @if ($ringkas['terlalu_banyak'])
                        <span class="text-sm text-warning">Berkas lebih dari {{ ImporTerpasang::MAKS_BARIS }} baris; hanya {{ ImporTerpasang::MAKS_BARIS }} baris pertama dibaca. Impor sisanya di berkas terpisah.</span>
                    @endif
                    @if ($masalah)
                        <div class="flex max-h-44 flex-col divide-y divide-line overflow-y-auto rounded-sm outline outline-1 -outline-offset-1 outline-line">
                            @foreach ($masalah as $m)
                                <div class="flex items-start gap-2 px-3 py-2 text-sm" wire:key="masalah-{{ $m['no'] }}">
                                    <span class="w-14 shrink-0 font-caption text-xs text-fg-muted">Baris {{ $m['no'] }}</span>
                                    <span class="flex-1 {{ $m['status'] === 'galat' ? 'text-danger' : 'text-fg-muted' }}">{{ $m['alasan'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif

                <div class="flex justify-end gap-2 pt-1">
                    <x-tombol variant="teks" wire:click="tutup">Batal</x-tombol>
                    <x-tombol wire:click="terapkanImpor" :disabled="! $sah" wire:loading.attr="disabled" wire:target="terapkanImpor">Simpan {{ $sah ? count($sah).' perlengkapan' : '' }}</x-tombol>
                </div>
            </div>
        </div>
    @endif
</div>

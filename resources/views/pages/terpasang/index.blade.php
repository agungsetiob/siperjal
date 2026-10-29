<?php

use App\Enums\Role;
use App\Models\Desa;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LogPengaturan;
use App\Models\PerlengkapanTerpasang;
use App\Models\Pemeliharaan;
use App\Models\User;
use App\Models\Usulan;
use App\Notifications\PermintaanJenis;
use App\Support\ImporTerpasang;
use App\Support\Wilayah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Aset: perlengkapan terpasang (dari usulan, otomatis, dan yang terpasang sebelum SIPERJAL) + riwayat pemeliharaannya.
 * Daftar + panel detail (kode register, QR, linimasa), cetak label QR massal, unduh Excel/CSV.
 * Dishub mengelola data lama (tambah, ubah, hapus, impor Excel) dan mencatat pemeliharaan; admin hanya melihat.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $cari = '';

    #[Url(as: 'jenis')]
    public ?int $jenisFilter = null;

    #[Url]
    public string $sumber = 'semua'; // semua | lama | usulan

    #[Url]
    public string $tab = 'terpasang'; // terpasang | riwayat

    #[Url(as: 'id')]
    public ?int $pilih = null;

    #[Url]
    public string $kondisi = 'semua'; // semua | rusak | belum_berkode

    #[Url(as: 'kec')]
    public ?int $kecamatanFilter = null;

    public int $batas = 40;

    /** Titik pengguna ([lat, lng], dari GPS peramban, tidak disimpan): daftar diurut terdekat dan menampilkan jarak. */
    public ?array $dekat = null;

    // Mode Pilih: cetak label QR massal
    public bool $tandai = false;
    public array $terpilih = [];
    public string $ukuranLabel = 'sedang'; // sedang | kecil

    // Riwayat
    #[Url]
    public string $rentang = 'tujuh'; // hari | tujuh | bulan | pilih
    public ?string $dari = null;
    public ?string $sampai = null;
    #[Url(as: 'asal')]
    public string $sumberRiwayat = 'semua'; // semua | laporan | rutin
    #[Url(as: 'petugas')]
    public ?int $petugasFilter = null;

    public const MAKS_LABEL = 200;

    public ?string $dialog = null; // form | impor

    // Form
    public ?int $ubahId = null;
    /** Satu lokasi bisa berisi beberapa jenis (seperti item usulan): [{jenis, jumlah, harga}] */
    public array $items = [];
    public ?float $lat = null;
    public ?float $lng = null;
    public string $alamat = '';
    public ?int $kecamatan_id = null;
    public ?int $desa_id = null;
    public ?int $tahun = null;
    public string $catatan = '';

    // Minta admin menambah jenis yang belum ada
    public bool $minta = false;
    public string $mintaNama = '';
    public string $mintaSatuan = '';
    public string $mintaCatatan = '';

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

    public function mount(): void
    {
        // petugas lapangan membuka Riwayat: bawaannya catatan miliknya
        if (auth()->user()->isPetugasLapangan() && $this->tab === 'riwayat' && ! $this->petugasFilter) {
            $this->petugasFilter = auth()->id();
        }
    }

    /** Hanya Dishub yang mengelola; admin melihat. */
    #[Computed]
    public function kelola(): bool
    {
        return auth()->user()->hasRole(Role::Dishub);
    }

    /** Saringan daftar aset (dipakai daftar, hitungan, dan unduhan). */
    private function saringan(): array
    {
        return ['cari' => $this->cari, 'jenis' => $this->jenisFilter, 'kecamatan' => $this->kecamatanFilter, 'sumber' => $this->sumber, 'kondisi' => $this->kondisi];
    }

    private function queryDaftar()
    {
        return PerlengkapanTerpasang::saring($this->saringan());
    }

    #[Computed]
    public function daftar()
    {
        $q = $this->queryDaftar()
            ->with('jenis', 'desa', 'kecamatan', 'usulanItem.usulan', 'laporanTerbuka')
            ->withMax('pemeliharaan as dirawat_terakhir', 'tanggal');
        if ($this->dekat) {
            [$lat, $lng] = $this->dekat;
            $q->orderByRaw('((lat - ?) * (lat - ?)) + ((lng - ?) * (lng - ?)) * 0.98', [$lat, $lat, $lng, $lng]);
        } else {
            $q->latest('id');
        }

        return $q->limit($this->batas)->get()->each(fn ($a) => $this->dekat ? $a->jarak = Usulan::jarak($this->dekat[0], $this->dekat[1], $a->lat, $a->lng) : null);
    }

    public function urutDekat(float $lat, float $lng): void
    {
        $this->dekat = [round($lat, 6), round($lng, 6)];
        $this->batas = 40;
    }

    public function hapusDekat(): void
    {
        $this->dekat = null;
    }

    #[Computed]
    public function totalHasil(): int
    {
        return $this->queryDaftar()->count();
    }

    #[Computed]
    public function jumlahSemua(): array
    {
        return [
            'semua' => PerlengkapanTerpasang::count(),
            'lama' => PerlengkapanTerpasang::lama()->count(),
            'nilai' => (int) PerlengkapanTerpasang::sum(DB::raw('jumlah * harga_satuan')), // harga kosong tidak dihitung
            'rusak' => PerlengkapanTerpasang::whereHas('laporanTerbuka')->count(),
            'belum_berkode' => PerlengkapanTerpasang::belumBerkode()->count(),
        ];
    }

    /** Unit yang sedang dibuka di panel detail. */
    #[Computed]
    public function aset(): ?PerlengkapanTerpasang
    {
        return $this->pilih ? PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan', 'laporanTerbuka', 'usulanItem.fotoHasil', 'usulanItem.usulan.instansi',
            'pemeliharaan.petugas', 'pemeliharaan.foto', 'pemeliharaan.laporan')->find($this->pilih) : null;
    }

    /** Siapa yang menandai terpasang (dari riwayat usulan), untuk linimasa. */
    #[Computed]
    public function penandaTerpasang(): ?string
    {
        $u = $this->aset?->usulanItem?->usulan;

        return $u?->riwayat()->where('aksi', 'like', '% terpasang')->with('user')->latest('id')->first()?->user?->name;
    }

    /** Rentang tanggal Riwayat: [dari, sampai] (Y-m-d). */
    #[Computed]
    public function rentangTanggal(): array
    {
        return match ($this->rentang) {
            'hari' => [today()->toDateString(), today()->toDateString()],
            'bulan' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'pilih' => [$this->dari ?: null, $this->sampai ?: null],
            default => [today()->subDays(6)->toDateString(), today()->toDateString()],
        };
    }

    private function saringanRiwayat(): array
    {
        [$dari, $sampai] = $this->rentangTanggal;

        return ['dari' => $dari, 'sampai' => $sampai, 'jenis' => $this->jenisFilter, 'kecamatan' => $this->kecamatanFilter,
            'sumber' => $this->sumberRiwayat, 'petugas' => $this->petugasFilter, 'cari' => $this->cari];
    }

    #[Computed]
    public function riwayat()
    {
        return Pemeliharaan::with('aset.jenis', 'aset.kecamatan', 'aset.desa', 'petugas', 'laporan')
            ->saring($this->saringanRiwayat())->orderByDesc('tanggal')->orderByDesc('id')->limit($this->batas)->get();
    }

    /** Jumlah catatan menurut asal, untuk ringkasan Riwayat. @return array{semua: int, laporan: int, rutin: int} */
    #[Computed]
    public function hitunganRiwayat(): array
    {
        $q = fn () => Pemeliharaan::saring([...$this->saringanRiwayat(), 'sumber' => 'semua']);

        return ['semua' => $q()->count(), 'laporan' => $q()->whereNotNull('laporan_kerusakan_id')->count(), 'rutin' => $q()->whereNull('laporan_kerusakan_id')->count()];
    }

    /** Parameter unduhan sesuai tab dan saringan yang aktif. */
    public function parameterUnduh(): array
    {
        return $this->tab === 'riwayat'
            ? ['data' => 'riwayat'] + array_filter($this->saringanRiwayat(), fn ($v) => $v !== null && $v !== '' && $v !== 'semua')
            : array_filter($this->saringan(), fn ($v) => $v !== null && $v !== '' && $v !== 'semua');
    }

    /** Boleh mencatat pemeliharaan (Dishub; admin hanya melihat). */
    #[Computed]
    public function bolehCatat(): bool
    {
        return Pemeliharaan::bolehMencatat(auth()->user());
    }

    // ── Navigasi daftar / detail
    public function gantiTab(string $tab): void
    {
        abort_unless(in_array($tab, ['terpasang', 'riwayat'], true), 422);
        $this->tab = $tab;
        $this->batas = 40;
        $this->tandai = false;
        $this->pilih = null;
        $this->petugasFilter = $tab === 'riwayat' && auth()->user()->isPetugasLapangan() ? auth()->id() : null;
    }

    public function pilihAset(int $id): void
    {
        if ($this->tandai) {
            $this->terpilih = in_array($id, $this->terpilih, true) ? array_values(array_diff($this->terpilih, [$id])) : [...$this->terpilih, $id];

            return;
        }
        $this->pilih = $id;
    }

    public function tutupDetail(): void
    {
        $this->pilih = null;
    }

    public function muatLagi(): void
    {
        $this->batas += 40;
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['cari', 'jenisFilter', 'sumber', 'kondisi', 'kecamatanFilter', 'rentang', 'dari', 'sampai', 'sumberRiwayat', 'petugasFilter'], true)) {
            $this->batas = 40;
        }
    }

    public function hapusFilter(): void
    {
        $this->reset('cari', 'jenisFilter', 'sumber', 'kondisi', 'kecamatanFilter', 'sumberRiwayat', 'batas');
        $this->petugasFilter = $this->tab === 'riwayat' && auth()->user()->isPetugasLapangan() ? auth()->id() : null;
    }

    public function saringKondisi(string $kondisi): void
    {
        $this->kondisi = $this->kondisi === $kondisi ? 'semua' : $kondisi;
        $this->batas = 40;
    }

    // ── Mode Pilih (cetak label QR)
    public function mulaiTandai(): void
    {
        $this->tandai = true;
        $this->terpilih = [];
    }

    public function selesaiTandai(): void
    {
        $this->tandai = false;
        $this->terpilih = [];
    }

    /** Pilih semua hasil saringan yang sudah berkode. */
    public function pilihSemua(): void
    {
        $this->terpilih = $this->queryDaftar()->whereNotNull('kode')->orderBy('id')->limit(5000)->pluck('id')->all();
    }

    public function bersihkanPilihan(): void
    {
        $this->terpilih = [];
    }

    /** Hapus catatan pemeliharaan (koordinator; petugas hanya miliknya ≤10 menit; yang dari laporan tidak). */
    public function hapusCatatan(int $id): void
    {
        $p = Pemeliharaan::findOrFail($id);
        abort_unless($p->bolehDihapus(auth()->user()), 403);
        $p->hapusCatat();
        unset($this->aset, $this->riwayat, $this->hitunganRiwayat, $this->daftar);
        $this->dispatch('toast', pesan: 'Catatan dihapus');
    }

    #[\Livewire\Attributes\On('pemeliharaan-dicatat')]
    public function segarkan(): void
    {
        unset($this->aset, $this->riwayat, $this->hitunganRiwayat, $this->daftar);
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
        $this->items = [['jenis' => $a->jenis_perlengkapan_id, 'jumlah' => $a->jumlah, 'harga' => $a->harga_satuan]];
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

    public function tambahItem(): void
    {
        if (! $this->ubahId && count($this->items) < 20) {
            $this->items[] = self::itemBaru();
        }
    }

    public function hapusItem(int $i): void
    {
        if (count($this->items) > 1) {
            unset($this->items[$i]);
            $this->items = array_values($this->items);
            $this->resetErrorBag();
        }
    }

    private static function itemBaru(): array
    {
        return ['jenis' => null, 'jumlah' => 1, 'harga' => null];
    }

    public function updatedKecamatanId(): void
    {
        $this->desa_id = null;
    }

    public function simpan(): void
    {
        abort_unless($this->kelola, 403);
        $this->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.jenis' => 'required|distinct|exists:jenis_perlengkapan,id',
            'items.*.jumlah' => 'required|integer|min:1|max:999',
            'items.*.harga' => 'nullable|integer|min:0|max:100000000000',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'alamat' => 'nullable|string|max:255',
            'kecamatan_id' => 'nullable|exists:kecamatan,id',
            'desa_id' => 'nullable|exists:desa,id',
            'tahun' => 'nullable|integer|min:1970|max:'.now()->year,
            'catatan' => 'nullable|string|max:500',
        ], [
            'items.*.jenis.required' => 'Pilih jenis perlengkapannya.',
            'items.*.jenis.distinct' => 'Jenis ini sudah ada di baris lain; ubah jumlahnya saja.',
            'items.*.jumlah.*' => 'Jumlah 1–999.',
            'items.*.harga.*' => 'Isi angka rupiah tanpa titik, mis. 1500000.',
            'lat.required' => 'Tandai lokasinya di peta dulu.',
            'tahun.max' => 'Tahun tidak boleh lebih dari tahun ini.',
        ]);
        $tempat = [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'alamat' => trim($this->alamat) ?: null,
            'kecamatan_id' => $this->kecamatan_id,
            'desa_id' => $this->desa_id,
            'tahun' => $this->tahun,
            'catatan' => trim($this->catatan) ?: null,
        ];
        $baris = collect($this->items)->map(fn ($it) => $tempat + [
            'jenis_perlengkapan_id' => (int) $it['jenis'],
            'jumlah' => (int) $it['jumlah'],
            'harga_satuan' => ($it['harga'] ?? '') === '' ? null : (int) $it['harga'],
        ]);
        $nama = JenisPerlengkapan::whereIn('id', $baris->pluck('jenis_perlengkapan_id'))->get()->map->namaPendek()->implode(', ');
        if ($this->ubahId) {
            $d = $baris->first();
            // satu unit tetap satu unit: jumlah hanya bisa diubah untuk jenis per ruas/paket
            if (JenisPerlengkapan::find($d['jenis_perlengkapan_id'])->per_unit) {
                $d['jumlah'] = 1;
            }
            PerlengkapanTerpasang::lama()->findOrFail($this->ubahId)->update($d);
            LogPengaturan::catat("Perlengkapan terpasang {$nama} diubah");
            $pesan = 'Perubahan tersimpan';
        } else {
            $unit = DB::transaction(fn () => $baris->flatMap(fn ($d) => PerlengkapanTerpasang::buatUnit($d)));
            LogPengaturan::catat("Perlengkapan terpasang {$nama} ditambahkan (data lama)");
            $pesan = $unit->count() > 1 ? "{$unit->count()} unit ditambahkan" : "{$nama} ditambahkan";
        }
        $this->tutup();
        unset($this->daftar, $this->jumlahSemua);
        $this->dispatch('toast', pesan: $pesan);
    }

    public function bukaMinta(): void
    {
        abort_unless($this->kelola, 403);
        $this->reset('mintaNama', 'mintaSatuan', 'mintaCatatan');
        $this->resetErrorBag(['mintaNama', 'mintaSatuan', 'mintaCatatan']);
        $this->minta = true;
    }

    /** Kirim permintaan jenis baru ke semua admin (lonceng + email). */
    public function kirimMinta(): void
    {
        abort_unless($this->kelola, 403);
        $this->validate([
            'mintaNama' => 'required|string|min:3|max:80',
            'mintaSatuan' => 'nullable|string|max:20',
            'mintaCatatan' => 'nullable|string|max:300',
        ], ['mintaNama.required' => 'Tulis nama jenis perlengkapannya.', 'mintaNama.min' => 'Nama terlalu pendek.']);

        $nama = trim($this->mintaNama);
        if (JenisPerlengkapan::whereRaw('lower(nama) = ?', [mb_strtolower($nama)])->exists()) {
            $this->addError('mintaNama', 'Jenis ini sudah ada di daftar. Pilih dari dropdown.');

            return;
        }
        if (! RateLimiter::attempt('minta-jenis:'.auth()->id(), 10, fn () => true, 3600)) {
            $this->dispatch('toast', pesan: 'Terlalu banyak permintaan. Coba lagi nanti.', gagal: true);

            return;
        }

        Notification::send(User::where('role', Role::Admin)->get(), new PermintaanJenis(auth()->user(), $nama, trim($this->mintaSatuan) ?: null, trim($this->mintaCatatan) ?: null));
        LogPengaturan::catat("Permintaan jenis perlengkapan baru: {$nama}");
        $this->minta = false;
        $this->dispatch('toast', pesan: 'Permintaan terkirim ke admin');
    }

    public function hapus(int $id): void
    {
        abort_unless($this->kelola, 403);
        $a = PerlengkapanTerpasang::lama()->with('jenis')->findOrFail($id);
        if ($a->punyaRiwayat()) {
            $this->dispatch('toast', pesan: 'Sudah punya riwayat (laporan atau pemeliharaan), tidak bisa dihapus.', gagal: true);

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
        if (! str_starts_with($token, 'impor-terpasang:') || substr($token, 16) !== $this->batchTerakhir) {
            return;
        }
        abort_unless($this->kelola, 403);
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
        $this->reset('ubahId', 'lat', 'lng', 'alamat', 'kecamatan_id', 'desa_id', 'tahun', 'catatan');
        $this->items = [self::itemBaru()];
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'semuaJenis' => JenisPerlengkapan::orderBy('urutan')->get(),
            'semuaSatuan' => JenisPerlengkapan::distinct()->orderBy('satuan')->pluck('satuan'),
            'semuaKecamatan' => Kecamatan::orderBy('nama')->get(),
            'petugasLapangan' => $this->tab === 'riwayat' ? User::where('role', Role::Dishub)->orderBy('name')->get(['id', 'name', 'petugas_lapangan']) : collect(),
            'desaPilihan' => $this->kecamatan_id ? Desa::where('kecamatan_id', $this->kecamatan_id)->orderBy('nama')->get() : collect(),
        ];
    }

    public function render()
    {
        return $this->view()->title('Terpasang · SIPERJAL');
    }
};
?>

@php
    $jml = $this->jumlahSemua;
    $punyaSaringan = $cari !== '' || $jenisFilter || $kecamatanFilter || $sumber !== 'semua' || $kondisi !== 'semua' || ($tab === 'riwayat' && ($sumberRiwayat !== 'semua' || $petugasFilter));
    $namaHari = fn (\Illuminate\Support\Carbon $t) => $t->isToday() ? 'Hari ini' : ($t->isYesterday() ? 'Kemarin' : $t->translatedFormat('l'));
    $perLembar = ['sedang' => 24, 'kecil' => 65][$ukuranLabel] ?? 24;
    $nTerpilih = count($terpilih);
    $unduh = $this->parameterUnduh();
    $sel = 'input py-2 text-sm';
@endphp
<div>
<div class="lg:grid lg:h-[calc(100dvh-4rem)] lg:grid-cols-[440px_minmax(0,1fr)]">
    {{-- ── Panel kiri: kepala + daftar ── --}}
    <section @class(['flex min-h-0 flex-col border-r border-line bg-bg', 'hidden lg:flex' => $pilih && ! $tandai])>
        <div class="flex flex-col gap-4 px-6 pt-5">
            <x-kategori aktif="terpasang" />
            <div class="flex items-center justify-between gap-2">
                <h1 class="judul min-w-0 whitespace-nowrap text-[26px] leading-[28px] sm:text-[28px]">{{ $tandai ? 'Pilih untuk dicetak' : 'Aset' }}</h1>
                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($tab === 'terpasang' && ! $tandai)
                        <button type="button" wire:click="mulaiTandai" class="flex h-[30px] items-center gap-1.5 rounded-sm px-2.5 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-square-check-big', 'size-4') Pilih</button>
                    @endif
                    @unless ($tandai)
                        <div class="relative" x-data="{ buka: false }">
                            <button type="button" @click="buka = !buka" class="flex h-[30px] items-center gap-1.5 rounded-sm px-2.5 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted" :aria-expanded="buka">@svg('lucide-download', 'size-4') Unduh @svg('lucide-chevron-down', 'size-3.5')</button>
                            <div x-show="buka" x-cloak @click.outside="buka = false" x-transition.opacity.duration.150ms class="absolute right-0 top-9 z-[1100] flex w-72 flex-col gap-1 rounded-md bg-bg p-2 shadow-lg outline outline-1 -outline-offset-1 outline-line">
                                @foreach ($tab === 'riwayat' ? [['xlsx', 'Excel (.xlsx)', 'Catatan pemeliharaan sesuai saringan', 'file-spreadsheet'], ['csv', 'CSV', 'Untuk diolah di aplikasi lain', 'file-text'], ['pdf', 'PDF', 'Siap cetak', 'file']] : [['xlsx', 'Excel (.xlsx)', 'Koordinat jadi tautan Google Maps yang bisa diklik', 'file-spreadsheet'], ['csv', 'CSV', 'Untuk diolah di aplikasi lain; koordinat sebagai teks', 'file-text']] as [$fmt, $judulFmt, $ket, $ikonFmt])
                                    <a href="{{ route('terpasang.unduh', $unduh + ['format' => $fmt]) }}" class="flex items-start gap-3 rounded-sm p-2.5 hover:bg-surface-muted">
                                        <span class="mt-0.5 text-accent">@svg('lucide-'.$ikonFmt, 'size-5')</span>
                                        <span class="flex flex-col"><span class="text-sm font-semibold">{{ $judulFmt }}</span><span class="text-xs leading-4 text-fg-muted">{{ $ket }}</span></span>
                                    </a>
                                @endforeach
                                <span class="px-2.5 pb-1 pt-0.5 font-caption text-[11px] leading-4 text-fg-muted">{{ $tab === 'riwayat' ? 'Kolom: tanggal, kode, jenis, lokasi, kegiatan, sumber, petugas, catatan.' : 'Kolom: kode, jenis, lokasi, koordinat, tahun, sumber, harga, status, pemeliharaan terakhir.' }} Mengikuti saringan yang aktif.</span>
                            </div>
                        </div>
                        @if ($this->kelola)
                            <div class="relative" x-data="{ buka: false }">
                                <button type="button" @click="buka = !buka" class="grid size-[30px] place-items-center rounded-sm bg-accent text-fg-inverse hover:brightness-110" aria-label="Tambah data lama" :aria-expanded="buka">@svg('lucide-plus', 'size-4')</button>
                                <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 top-9 z-[1100] flex w-64 flex-col rounded-md bg-bg p-1.5 shadow-lg outline outline-1 -outline-offset-1 outline-line">
                                    <button type="button" wire:click="tambah" @click="buka = false" class="flex items-center gap-2.5 rounded-sm p-2.5 text-left text-sm font-semibold hover:bg-surface-muted">@svg('lucide-plus', 'size-4 text-accent') Tambah perlengkapan lama</button>
                                    <button type="button" wire:click="bukaImpor" @click="buka = false" class="flex items-center gap-2.5 rounded-sm p-2.5 text-left text-sm font-semibold hover:bg-surface-muted">@svg('lucide-file-spreadsheet', 'size-4 text-accent') Impor Excel</button>
                                </div>
                            </div>
                        @endif
                    @endunless
                </div>
            </div>

            @unless ($tandai)
                <div class="flex gap-5 overflow-x-auto border-b border-line font-caption text-sm">
                    @foreach (['terpasang' => ['Terpasang', $jml['semua']], 'riwayat' => ['Riwayat', null]] as $k => [$label, $n])
                        <button type="button" wire:click="gantiTab('{{ $k }}')" @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 pb-2.5', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted hover:text-fg' => $tab !== $k])>
                            {{ $label }}
                            @if ($n) <span @class(['rounded-full px-[7px] py-px text-[11px] font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ number_format($n, 0, ',', '.') }}</span> @endif
                        </button>
                    @endforeach
                </div>
            @endunless

            <div class="relative flex gap-2" x-data="{ buka: false }">
                <label class="flex h-10 flex-1 items-center gap-2 rounded-sm bg-bg px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                    @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                    <input wire:model.live.debounce.300ms="cari" class="w-full bg-transparent text-sm outline-none placeholder:text-fg-muted" placeholder="{{ $tab === 'riwayat' ? 'Cari kode register' : 'Cari kode, jenis, atau lokasi' }}">
                </label>
                <button type="button" @click="buka = !buka" @class(['flex h-10 items-center gap-1.5 rounded-sm px-3 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1', 'outline-accent text-accent' => $jenisFilter || $kecamatanFilter || $sumber !== 'semua', 'outline-line' => ! ($jenisFilter || $kecamatanFilter || $sumber !== 'semua')])>@svg('lucide-sliders-horizontal', 'size-4') Filter</button>
                <div x-show="buka" x-cloak @click.outside="buka = false" x-transition.opacity.duration.150ms class="absolute right-0 top-11 z-[1100] flex w-72 flex-col gap-2 rounded-md bg-bg p-3 shadow-lg outline outline-1 -outline-offset-1 outline-line">
                    <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Saring</span>
                    <select wire:model.live="kecamatanFilter" class="{{ $sel }}" aria-label="Kecamatan"><option value="">Semua kecamatan</option>@foreach ($semuaKecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select>
                    <select wire:model.live="jenisFilter" class="{{ $sel }}" aria-label="Perlengkapan"><option value="">Semua perlengkapan</option>@foreach ($semuaJenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select>
                    @if ($tab === 'terpasang')
                        <div class="flex gap-0.5 rounded-sm bg-surface-muted p-[3px] font-caption text-[12px]">
                            @foreach (['semua' => 'Semua', 'lama' => 'Sebelum SIPERJAL', 'usulan' => 'Dari usulan'] as $v => $l)
                                <button type="button" wire:click="$set('sumber', '{{ $v }}')" @class(['flex-1 rounded-sm py-1.5', 'bg-bg font-semibold shadow-sm' => $sumber === $v, 'font-medium text-fg-muted' => $sumber !== $v])>{{ $l }}</button>
                            @endforeach
                        </div>
                    @else
                        <select wire:model.live="petugasFilter" class="{{ $sel }}" aria-label="Petugas"><option value="">Semua petugas</option>@foreach ($petugasLapangan as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                    @endif
                    @if ($punyaSaringan) <button type="button" wire:click="hapusFilter" @click="buka = false" class="self-start text-sm text-accent">Hapus saringan</button> @endif
                </div>
            </div>

            @if ($tandai)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pb-3 font-caption text-[13px]">
                    <span class="font-semibold">{{ $nTerpilih }} dipilih</span>
                    <button type="button" wire:click="pilihSemua" class="font-semibold text-accent">Pilih semua hasil ({{ number_format($this->totalHasil, 0, ',', '.') }})</button>
                    @if ($nTerpilih) <button type="button" wire:click="bersihkanPilihan" class="text-fg-muted hover:text-fg">Kosongkan</button> @endif
                    <button type="button" wire:click="selesaiTandai" class="ml-auto font-semibold text-fg-muted hover:text-fg">Batal</button>
                </div>
            @elseif ($tab === 'terpasang')
                <div class="flex flex-col gap-2.5 pb-3">
                    <div class="flex flex-wrap items-center gap-x-2 font-caption text-xs text-fg-muted">
                        <span class="font-semibold text-fg">{{ number_format($this->totalHasil, 0, ',', '.') }} unit</span>
                        @if ($jml['nilai']) <span class="size-[3px] rounded-full bg-current"></span><span>nilai tercatat {{ \App\Support\Rekap::rupiahPendek($jml['nilai']) }}</span> @endif
                    </div>
                    <div class="flex flex-wrap gap-1.5" x-data="{ cari: false, galat: false,
                            dekat() { if (! navigator.geolocation) { this.galat = true; return } this.cari = true; this.galat = false;
                                navigator.geolocation.getCurrentPosition((p) => { this.cari = false; $wire.urutDekat(p.coords.latitude, p.coords.longitude) }, () => { this.cari = false; this.galat = true }, { enableHighAccuracy: true, timeout: 10000 }) } }">
                        <button type="button" @if ($dekat) wire:click="hapusDekat" @else @click="dekat()" @endif @class(['flex items-center gap-1.5 rounded-full px-2.5 py-1 font-caption text-xs font-semibold outline outline-1 -outline-offset-1', 'bg-accent text-fg-inverse outline-accent' => $dekat, 'text-accent outline-line hover:bg-accent-soft' => ! $dekat])>@svg('lucide-locate-fixed', 'size-3.5') <span x-show="! cari">Dekat saya</span><span x-show="cari" x-cloak>Mencari lokasi…</span></button>
                        <span x-show="galat" x-cloak class="self-center font-caption text-xs text-danger">Lokasi tidak bisa dibaca. Izinkan akses lokasi di peramban.</span>
                    @if ($jml['rusak'] || $jml['belum_berkode'])
                            @foreach ([['rusak', $jml['rusak'], 'dilaporkan rusak', 'triangle-alert'], ['belum_berkode', $jml['belum_berkode'], 'belum berkode', 'circle-dashed']] as [$k, $n, $l, $ik])
                                @if ($n)
                                    <button type="button" wire:click="saringKondisi('{{ $k }}')" @class(['flex items-center gap-1.5 rounded-full px-2.5 py-1 font-caption text-xs font-semibold outline outline-1 -outline-offset-1', 'bg-warning-bg text-warning outline-warning' => $kondisi === $k, 'text-warning outline-line hover:bg-warning-bg' => $kondisi !== $k])>@svg('lucide-'.$ik, 'size-3.5') {{ $n }} {{ $l }}</button>
                                @endif
                            @endforeach
                    @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-2.5 pb-3">
                    <div class="flex flex-wrap gap-1.5 font-caption text-[13px]">
                        @foreach (['hari' => 'Hari ini', 'tujuh' => '7 hari', 'bulan' => 'Bulan ini', 'pilih' => 'Pilih tanggal'] as $v => $l)
                            <button type="button" wire:click="$set('rentang', '{{ $v }}')" @class(['rounded-full px-3 py-1.5 outline outline-1 -outline-offset-1', 'bg-accent font-semibold text-fg-inverse outline-accent' => $rentang === $v, 'font-medium outline-line hover:bg-surface-muted' => $rentang !== $v])>{{ $l }}</button>
                        @endforeach
                    </div>
                    @if ($rentang === 'pilih')
                        <div class="flex items-center gap-2">
                            <input type="date" wire:model.live="dari" class="input h-9 flex-1 py-0 text-sm" aria-label="Dari tanggal" max="{{ today()->toDateString() }}">
                            <span class="text-fg-muted">–</span>
                            <input type="date" wire:model.live="sampai" class="input h-9 flex-1 py-0 text-sm" aria-label="Sampai tanggal" max="{{ today()->toDateString() }}">
                        </div>
                    @endif
                    @php $h = $this->hitunganRiwayat; @endphp
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 font-caption text-xs text-fg-muted">
                        <span class="font-semibold text-fg">{{ $h['semua'] }} catatan</span>
                        @if ($kecamatanFilter) <span class="size-[3px] rounded-full bg-current"></span><span>Kec. {{ $semuaKecamatan->firstWhere('id', $kecamatanFilter)?->nama }}</span> @endif
                        @foreach (['laporan' => 'laporan', 'rutin' => 'rutin'] as $v => $l)
                            <button type="button" wire:click="$set('sumberRiwayat', '{{ $sumberRiwayat === $v ? 'semua' : $v }}')" @class(['rounded-full px-2 py-0.5 font-semibold', 'bg-accent text-fg-inverse' => $sumberRiwayat === $v, 'bg-surface-muted' => $sumberRiwayat !== $v])>{{ $h[$v] }} {{ $l }}</button>
                        @endforeach
                        @if (auth()->user()->isPetugasLapangan())
                            <button type="button" wire:click="$set('petugasFilter', {{ $petugasFilter ? 'null' : auth()->id() }})" @class(['rounded-full px-2 py-0.5 font-semibold', 'bg-accent text-fg-inverse' => $petugasFilter, 'bg-surface-muted' => ! $petugasFilter])>Catatan saya</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto border-t border-line pb-24 lg:pb-0">
            @if ($tab === 'terpasang')
                @forelse ($this->daftar as $a)
                    <x-aset.baris :aset="$a" :aktif="$pilih === $a->id && ! $tandai" :tandai="$tandai" :dipilih="in_array($a->id, $terpilih, true)" wire:key="aset-{{ $a->id }}" />
                @empty
                    @if ($punyaSaringan)
                        <x-kosong ikon="search-x" judul="Tidak ada yang cocok" teks="Coba kata kunci lain atau hapus saringan.">
                            <button type="button" wire:click="hapusFilter" class="flex items-center gap-1.5 rounded-sm px-3 py-2 text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-x', 'size-4') Hapus saringan</button>
                        </x-kosong>
                    @else
                        <x-kosong ikon="package" judul="Belum ada perlengkapan" :teks="$this->kelola ? 'Tambahkan satu per satu atau impor dari Excel.' : 'Belum ada yang tercatat.'" />
                    @endif
                @endforelse
                @if ($this->daftar->count() < $this->totalHasil)
                    <button type="button" wire:click="muatLagi" wire:loading.attr="disabled" wire:target="muatLagi" class="w-full py-4 text-center font-caption text-[13px] font-semibold text-accent hover:bg-surface-muted">Muat {{ min(40, $this->totalHasil - $this->daftar->count()) }} lagi · sisa {{ number_format($this->totalHasil - $this->daftar->count(), 0, ',', '.') }}</button>
                @endif
            @else
                @forelse ($this->riwayat->groupBy(fn ($p) => $p->tanggal->toDateString()) as $tgl => $catatan)
                    @php $t = \Illuminate\Support\Carbon::parse($tgl); @endphp
                    <div class="flex items-center justify-between border-b border-line bg-surface-muted px-6 py-2 font-caption text-xs" wire:key="rt-{{ $tgl }}">
                        <span class="font-semibold uppercase tracking-[0.6px]">{{ $namaHari($t) }}{{ $t->isToday() || $t->isYesterday() ? '' : '' }} · {{ $t->translatedFormat('j M Y') }}</span>
                        <span class="text-fg-muted">{{ $catatan->count() }} catatan</span>
                    </div>
                    @foreach ($catatan as $p)
                        <button type="button" wire:click="pilihAset({{ $p->perlengkapan_terpasang_id }})" wire:key="rc-{{ $p->id }}" @class(['flex w-full flex-col gap-1 border-b border-line px-6 py-3 text-left', 'bg-accent-soft' => $pilih === $p->perlengkapan_terpasang_id, 'hover:bg-surface-muted' => $pilih !== $p->perlengkapan_terpasang_id])>
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-caption text-[13px] font-semibold tracking-wide">{{ $p->aset->kode ?? 'Belum berkode' }}</span>
                                <span class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg-muted">{{ $p->dariLaporan() ? $p->laporan?->nomor : 'Rutin' }}</span>
                            </span>
                            <span class="text-[15px] font-semibold">{{ $p->label() }}</span>
                            <span class="flex items-center gap-1.5 truncate font-caption text-[13px] text-fg-muted">{{ $p->aset->jenis->namaPendek() }} · {{ $p->aset->alamat ?: ($p->aset->desa?->nama ?: ($p->aset->kecamatan?->nama ?? '-')) }}@if ($p->petugas)<span class="size-[3px] shrink-0 rounded-full bg-current"></span><span class="shrink-0">{{ str($p->petugas->name)->before(' ') }}</span>@endif</span>
                        </button>
                    @endforeach
                @empty
                    <x-kosong ikon="history" :judul="$punyaSaringan || $rentang !== 'tujuh' ? 'Tidak ada catatan' : 'Belum ada catatan pemeliharaan'" teks="Catatan muncul di sini saat laporan kerusakan selesai diperbaiki atau petugas mencatat pemeliharaan rutin." />
                @endforelse
                @if ($this->riwayat->count() >= $batas)
                    <button type="button" wire:click="muatLagi" class="w-full py-4 text-center font-caption text-[13px] font-semibold text-accent hover:bg-surface-muted">Muat lebih banyak</button>
                @endif
            @endif
        </div>

        {{-- HP: bilah cetak saat memilih --}}
        @if ($tandai)
            <form method="POST" action="{{ route('terpasang.label') }}" target="_blank" class="fixed inset-x-0 bottom-0 z-[1001] flex items-center gap-2 border-t border-line bg-bg px-4 pb-6 pt-3 shadow-[0_-4px_12px_#0000000a] lg:hidden">
                @csrf
                @foreach (array_slice($terpilih, 0, self::MAKS_LABEL) as $id) <input type="hidden" name="ids[]" value="{{ $id }}"> @endforeach
                <select name="ukuran" wire:model.live="ukuranLabel" class="input h-11 w-36 py-0 text-sm"><option value="sedang">Sedang 60×30</option><option value="kecil">Kecil 40×20</option></select>
                <button @disabled(! $nTerpilih || $nTerpilih > self::MAKS_LABEL) class="flex h-11 flex-1 items-center justify-center gap-2 rounded-sm bg-accent text-sm font-semibold text-fg-inverse disabled:opacity-40">@svg('lucide-printer', 'size-4') Cetak {{ $nTerpilih }} label</button>
            </form>
        @endif
    </section>

    {{-- ── Panel kanan: detail / cetak label ── --}}
    <section @class(['relative min-h-0 overflow-y-auto bg-bg', 'hidden lg:block' => ! $pilih || $tandai])>
        <div wire:loading.delay.flex wire:target="pilihAset" class="absolute inset-0 z-[900] hidden flex-col gap-4 bg-inherit p-6 lg:px-8" aria-label="Memuat detail">
            <span class="kerangka h-4 w-1/3"></span><span class="kerangka h-9 w-2/3"></span><div class="grid h-[200px] grid-cols-2 gap-3"><span class="kerangka h-full"></span><span class="kerangka h-full"></span></div>
        </div>
        @if ($tandai)

            <div class="flex flex-col gap-5 p-6 lg:px-8">
                <div class="flex flex-col gap-1">
                    <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Cetak label QR</span>
                    <h2 class="judul text-[28px] leading-8">{{ $nTerpilih }} label</h2>
                    <span class="text-sm text-fg-muted">{{ $nTerpilih ? ceil(min($nTerpilih, self::MAKS_LABEL) / $perLembar).' lembar A4 · dari '.$nTerpilih.' unit yang dipilih di daftar' : 'Centang unit di daftar (hanya yang sudah berkode).' }}</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['sedang' => ['Sedang · 60 × 30 mm', '24 label per lembar · mudah dipindai dari 1 m'], 'kecil' => ['Kecil · 40 × 20 mm', '65 label per lembar · untuk tiang ramping']] as $v => [$judulU, $ketU])
                        <label @class(['flex cursor-pointer flex-col gap-1 rounded-md p-4 outline -outline-offset-1', 'bg-accent-soft outline-2 outline-accent' => $ukuranLabel === $v, 'outline-1 outline-line hover:bg-surface-muted' => $ukuranLabel !== $v])>
                            <input type="radio" wire:model.live="ukuranLabel" value="{{ $v }}" class="sr-only">
                            <span class="text-sm font-semibold">{{ $judulU }}</span><span class="text-xs text-fg-muted">{{ $ketU }}</span>
                        </label>
                    @endforeach
                </div>
                @php $contoh = $this->daftar->whereIn('id', $terpilih)->take($perLembar); @endphp
                <div class="rounded-md bg-surface-muted p-4">
                    <div class="mx-auto grid max-w-[420px] gap-1.5 rounded-sm bg-white p-3 shadow-sm" style="grid-template-columns: repeat({{ $ukuranLabel === 'sedang' ? 3 : 5 }}, minmax(0, 1fr))" aria-label="Pratinjau lembar A4">
                        @for ($i = 0; $i < min($perLembar, $ukuranLabel === 'sedang' ? 12 : 20); $i++)
                            <span @class(['aspect-[2/1] rounded-[3px] outline outline-1 -outline-offset-1', 'bg-accent-soft outline-accent' => $i < $nTerpilih, 'outline-line' => $i >= $nTerpilih])></span>
                        @endfor
                    </div>
                </div>
                @if ($nTerpilih > self::MAKS_LABEL)
                    <span class="flex items-center gap-2 rounded-sm bg-warning-bg px-3 py-2 text-sm text-warning">@svg('lucide-triangle-alert', 'size-4 shrink-0') Maksimal {{ self::MAKS_LABEL }} label sekali unduh. Untuk lebih banyak, saring per kecamatan lalu cetak bertahap.</span>
                @else
                    <span class="text-sm text-fg-muted">Maksimal {{ self::MAKS_LABEL }} label sekali unduh. Untuk lebih banyak, saring per kecamatan lalu cetak bertahap.</span>
                @endif
                <form method="POST" action="{{ route('terpasang.label') }}" target="_blank" class="flex flex-col gap-2">
                    @csrf
                    @foreach (array_slice($terpilih, 0, self::MAKS_LABEL) as $id) <input type="hidden" name="ids[]" value="{{ $id }}"> @endforeach
                    <input type="hidden" name="ukuran" value="{{ $ukuranLabel }}">
                    <button @disabled(! $nTerpilih || $nTerpilih > self::MAKS_LABEL) class="flex h-12 items-center justify-center gap-2 rounded-sm bg-accent text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-40">@svg('lucide-download', 'size-[18px]') Unduh PDF label</button>
                    <span class="text-center text-xs text-fg-muted">PDF siap cetak di kertas stiker A4, skala 100%</span>
                </form>
            </div>
        @elseif ($pilih && $this->aset)

            <x-aset.detail :aset="$this->aset" :bolehCatat="$this->bolehCatat" :kelola="$this->kelola" :penanda="$this->penandaTerpasang" wire:key="detail-{{ $pilih }}" />
        @else

            <div class="grid h-full place-items-center p-10 text-center text-fg-muted">
                <div>@svg('lucide-mouse-pointer-click', 'mx-auto size-10')<p class="mt-3">Pilih unit di daftar untuk melihat kode, riwayat, dan QR-nya.</p></div>
            </div>
        @endif
    </section>
</div>

<livewire:aset.catat />

    {{-- ── Dialog tambah/ubah ── --}}
    @if ($dialog === 'form')

        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="tutup" x-data @keydown.escape.window="$wire.minta ? $wire.set('minta', false) : $wire.tutup()" wire:key="dialog-form">
            <form wire:submit="simpan" class="flex max-h-[92dvh] w-full flex-col gap-4 overflow-y-auto rounded-t-xl bg-bg p-6 sm:max-w-xl sm:rounded-md">
                <h3 class="judul text-2xl">{{ $ubahId ? 'Ubah perlengkapan' : 'Tambah perlengkapan terpasang' }}</h3>
                <span class="-mt-2 text-sm text-fg-muted">Data lama: terpasang sebelum SIPERJAL, tanpa usulan.</span>

                <div class="relative h-[240px] shrink-0 overflow-hidden rounded-md outline outline-1 outline-line" wire:ignore
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

                {{-- Item di lokasi ini: satu baris per jenis, seperti item usulan --}}
                <div class="flex flex-col gap-2">
                    <div class="grid grid-cols-[1fr_72px_128px_40px] gap-2 text-sm font-semibold">
                        <span>Perlengkapan</span><span>Jumlah</span><span>Harga satuan</span><span></span>
                    </div>
                    @foreach ($items as $i => $it)
                        @php $jenisIni = $semuaJenis->firstWhere('id', (int) $it['jenis']); @endphp
                        <div class="flex flex-col gap-1" wire:key="item-{{ $i }}">
                            <div class="grid grid-cols-[1fr_72px_128px_40px] items-center gap-2">
                                <select wire:model.live="items.{{ $i }}.jenis" class="input min-w-0" aria-label="Perlengkapan"><option value="">Pilih…</option>@foreach ($semuaJenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select>
                                <input wire:model="items.{{ $i }}.jumlah" type="number" min="1" @disabled($ubahId && $jenisIni?->per_unit) class="input min-w-0 disabled:bg-surface-muted disabled:text-fg-muted" aria-label="Jumlah">
                                <input wire:model="items.{{ $i }}.harga" type="number" min="0" inputmode="numeric" class="input min-w-0" aria-label="Harga satuan (Rp)"
                                       placeholder="Opsional">
                                @if (count($items) > 1)
                                    <button type="button" wire:click="hapusItem({{ $i }})" class="grid size-10 place-items-center rounded-sm text-fg-muted hover:bg-danger-bg hover:text-danger" aria-label="Hapus baris">@svg('lucide-x', 'size-[18px]')</button>
                                @else
                                    <span></span>
                                @endif
                            </div>
                            @if ($jenisIni)
                                <span class="font-caption text-xs text-fg-muted">{{ $ubahId && $jenisIni->per_unit ? 'Satu unit, kodenya tetap. Untuk menambah unit, pakai Tambah.' : 'Jumlah dalam '.$jenisIni->satuan }}{{ $jenisIni->harga_satuan ? ' · harga SSH sekarang Rp '.number_format($jenisIni->harga_satuan, 0, ',', '.').' (saran, boleh dikosongkan)' : '' }}</span>
                            @endif
                            @foreach (['jenis', 'jumlah', 'harga'] as $k)
                                @error("items.$i.$k") <span class="text-xs text-danger">{{ $message }}</span> @enderror
                            @endforeach
                        </div>
                    @endforeach
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        @unless ($ubahId)
                            <button type="button" wire:click="tambahItem" class="flex w-fit items-center gap-1.5 rounded-sm px-1 py-1.5 text-sm font-semibold text-accent hover:underline">@svg('lucide-plus', 'size-4') Tambah jenis lain di lokasi ini</button>
                        @endunless
                        <span class="text-[13px] text-fg-muted">Jenisnya tidak ada? <button type="button" wire:click="bukaMinta" class="font-semibold text-accent hover:underline">Minta admin menambahkan</button></span>
                    </div>
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

        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="tutup" x-data @keydown.escape.window="$wire.minta ? $wire.set('minta', false) : $wire.tutup()" wire:key="dialog-impor">
            <div class="flex max-h-[92dvh] w-full flex-col gap-4 overflow-y-auto rounded-t-xl bg-bg p-6 sm:max-w-lg sm:rounded-md">
                <h3 class="judul text-2xl">Impor dari Excel</h3>
                <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm text-fg-muted">
                    <li><a href="{{ route('terpasang.template') }}" class="font-semibold text-accent" download>Unduh template</a>, isi satu baris per perlengkapan (hapus baris contoh).</li>
                    <li>Kolom wajib: <b>Perlengkapan</b>, <b>Latitude</b>, <b>Longitude</b>. Lainnya opsional. Nama perlengkapan tinggal disalin dari sheet <b>Daftar perlengkapan</b> di template.</li>
                    <li>Unggah berkas .xlsx atau .csv, periksa pratinjau, lalu simpan.</li>
                </ol>
                <span class="-mt-2 text-[13px] text-fg-muted">Jenisnya tidak ada di daftar? <button type="button" wire:click="bukaMinta" class="font-semibold text-accent hover:underline">Minta admin menambahkan</button></span>
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

    {{-- ── Minta admin menambah jenis (di atas dialog form/impor) ── --}}
    @if ($minta)

        <div class="fixed inset-0 z-[3100] flex items-end justify-center bg-fg/40 sm:items-center" wire:click.self="$set('minta', false)" wire:key="dialog-minta">
            <form wire:submit="kirimMinta" class="flex w-full flex-col gap-4 rounded-t-xl bg-bg p-6 shadow-xl sm:max-w-md sm:rounded-md">
                <div class="flex flex-col gap-1">
                    <h3 class="judul text-2xl">Minta jenis baru</h3>
                    <span class="text-sm text-fg-muted">Daftar jenis diatur admin di Pengaturan → Perlengkapan. Admin akan dikabari lewat lonceng & email.</span>
                </div>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">Nama jenis perlengkapan</span>
                    <input wire:model="mintaNama" class="input" placeholder="mis. Cermin tikungan" autofocus>
                    @error('mintaNama') <span class="text-xs text-danger">{{ $message }}</span> @enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">Satuan</span>
                    <input wire:model="mintaSatuan" class="input" list="daftar-satuan" placeholder="unit / titik / meter">
                    <datalist id="daftar-satuan">@foreach ($semuaSatuan as $st)<option value="{{ $st }}">@endforeach</datalist>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">Catatan untuk admin</span>
                    <input wire:model="mintaCatatan" class="input" placeholder="Opsional, mis. ada 12 titik di Jl. Transmigrasi">
                </label>
                <div class="flex justify-end gap-2 pt-1">
                    <x-tombol variant="teks" wire:click="$set('minta', false)">Batal</x-tombol>
                    <x-tombol type="submit" ikon="send" wire:loading.attr="disabled" wire:target="kirimMinta">Kirim ke admin</x-tombol>
                </div>
            </form>
        </div>
    @endif
</div>

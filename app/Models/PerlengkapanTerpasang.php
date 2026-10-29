<?php

namespace App\Models;

use App\Support\KodeRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Perlengkapan jalan yang terpasang: dari usulan (`usulan_item_id` terisi) atau terpasang sebelum SIPERJAL
 * (data lama, dikelola Dishub). Sumber tunggal untuk peta beranda dan laporan kerusakan.
 */
class PerlengkapanTerpasang extends Model
{
    protected $table = 'perlengkapan_terpasang';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['terpasang_at' => 'date', 'lat' => 'float', 'lng' => 'float', 'harga_satuan' => 'integer'];
    }

    protected static function booted(): void
    {
        // semua jalur pembuatan/ubah aset (usulan, form, impor) otomatis mendapat kode bila datanya lengkap
        static::saved(fn (self $a) => $a->kode ?: KodeRegister::beri($a));
    }

    /**
     * Buat aset dari satu isian: jenis per unit → `jumlah` baris (satu unit satu kode),
     * jenis per ruas/paket (meter, set, lokasi) → satu baris berjumlah.
     *
     * @return Collection<int, self>
     */
    public static function buatUnit(array $data): Collection
    {
        $jenis = JenisPerlengkapan::findOrFail($data['jenis_perlengkapan_id']);
        $jumlah = max(1, (int) ($data['jumlah'] ?? 1));
        if (! $jenis->per_unit) {
            return collect([static::create($data + ['jumlah' => $jumlah])]);
        }

        return collect(range(1, $jumlah))->map(fn () => static::create([...$data, 'jumlah' => 1]));
    }

    /** Nilai aset (jumlah × harga satuan); null bila harga belum diisi. */
    public function nilai(): ?int
    {
        return $this->harga_satuan === null ? null : $this->jumlah * $this->harga_satuan;
    }

    public function jenis()
    {
        return $this->belongsTo(JenisPerlengkapan::class, 'jenis_perlengkapan_id');
    }

    public function kecamatan()
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function desa()
    {
        return $this->belongsTo(Desa::class);
    }

    public function usulanItem()
    {
        return $this->belongsTo(UsulanItem::class);
    }

    public function pemeliharaan()
    {
        return $this->hasMany(Pemeliharaan::class)->orderByDesc('tanggal')->orderByDesc('id');
    }

    public function laporanKerusakan()
    {
        return $this->hasMany(LaporanKerusakan::class)->latest('id');
    }

    /** Laporan kerusakan yang masih berjalan (paling banyak satu per perlengkapan). */
    public function laporanTerbuka()
    {
        return $this->hasOne(LaporanKerusakan::class)->aktif()->latest('id');
    }

    public function scopeLama(Builder $q): void
    {
        $q->whereNull('usulan_item_id');
    }

    /**
     * Saring daftar aset (dipakai halaman Terpasang dan unduhannya).
     * Kunci: cari, jenis (id), kecamatan (id), sumber (semua|lama|usulan), kondisi (semua|rusak|belum_berkode).
     */
    public function scopeSaring(Builder $q, array $f): void
    {
        $cari = trim((string) ($f['cari'] ?? ''));
        $q->when($f['jenis'] ?? null, fn ($q, $v) => $q->where('jenis_perlengkapan_id', $v))
            ->when($f['kecamatan'] ?? null, fn ($q, $v) => $q->where('kecamatan_id', $v))
            ->when(($f['sumber'] ?? 'semua') === 'lama', fn ($q) => $q->lama())
            ->when(($f['sumber'] ?? 'semua') === 'usulan', fn ($q) => $q->whereNotNull('usulan_item_id'))
            ->when(($f['kondisi'] ?? 'semua') === 'rusak', fn ($q) => $q->whereHas('laporanTerbuka'))
            ->when(($f['kondisi'] ?? 'semua') === 'belum_berkode', fn ($q) => $q->whereNull('kode'))
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('kode', 'like', '%'.strtoupper($cari).'%')
                ->orWhere('alamat', 'like', "%{$cari}%")
                ->orWhereHas('desa', fn ($d) => $d->where('nama', 'like', "%{$cari}%"))
                ->orWhereHas('kecamatan', fn ($d) => $d->where('nama', 'like', "%{$cari}%"))
                ->orWhereHas('jenis', fn ($d) => $d->where('nama', 'like', "%{$cari}%"))));
    }

    /** Catatan pemeliharaan terakhir (untuk "Dirawat 22 Okt"); null bila belum pernah. */
    public function dirawatTerakhir(): ?\Illuminate\Support\Carbon
    {
        return $this->pemeliharaan->first()?->tanggal;
    }

    public function scopeBelumBerkode(Builder $q): void
    {
        $q->whereNull('kode');
    }

    /** Punya riwayat (laporan kerusakan atau pemeliharaan): tidak boleh dihapus. */
    public function punyaRiwayat(): bool
    {
        return $this->laporanKerusakan()->exists() || $this->pemeliharaan()->exists();
    }

    public function mapsUrl(): string
    {
        return 'https://www.google.com/maps?q='.$this->lat.','.$this->lng;
    }

    /** Alamat publik halaman pindai QR. */
    public function urlPindai(): ?string
    {
        return $this->kode ? route('aset', $this->kode) : null;
    }

    /** Terpasang sebelum SIPERJAL (tanpa usulan). */
    public function adalahLama(): bool
    {
        return $this->usulan_item_id === null;
    }

    public function lokasi(): string
    {
        return $this->alamat ?: $this->lokasiSingkat();
    }

    public function lokasiSingkat(): string
    {
        return collect([$this->desa?->nama, $this->kecamatan ? 'Kec. '.$this->kecamatan->nama : null])
            ->filter()->implode(', ') ?: '-';
    }

    /** "5 Okt 2026", atau hanya tahunnya untuk data lama. */
    public function waktuPasang(): ?string
    {
        return $this->terpasang_at?->translatedFormat('j M Y') ?? ($this->tahun ? (string) $this->tahun : null);
    }

    /** Perlengkapan dalam radius (meter) dari sebuah titik, terdekat dulu; `jarak` terisi. */
    public static function sekitar(float $lat, float $lng, int $radius, ?int $jenisId = null): \Illuminate\Support\Collection
    {
        $d = $radius / 111000;

        return static::with('jenis', 'desa', 'kecamatan', 'laporanTerbuka')
            ->whereBetween('lat', [$lat - $d, $lat + $d])
            ->whereBetween('lng', [$lng - $d * 1.5, $lng + $d * 1.5])
            ->when($jenisId, fn ($q) => $q->where('jenis_perlengkapan_id', $jenisId))
            ->get()
            ->map(fn ($a) => tap($a, fn ($a) => $a->jarak = Usulan::jarak($lat, $lng, $a->lat, $a->lng)))
            ->filter(fn ($a) => $a->jarak <= $radius)
            ->sortBy('jarak')->values();
    }
}

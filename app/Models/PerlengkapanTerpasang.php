<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        return ['terpasang_at' => 'date', 'lat' => 'float', 'lng' => 'float'];
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

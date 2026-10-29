<?php

namespace App\Models;

use App\Enums\StatusItem;
use App\Support\Kabar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UsulanItem extends Model
{
    protected $table = 'usulan_item';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => StatusItem::class,
            'diubah' => 'boolean',
            'diputuskan_at' => 'datetime',
            'terpasang_at' => 'date',
        ];
    }

    public function usulan()
    {
        return $this->belongsTo(Usulan::class);
    }

    public function jenis()
    {
        return $this->belongsTo(JenisPerlengkapan::class, 'jenis_perlengkapan_id');
    }

    public function pemutus()
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    public function fotoHasil()
    {
        return $this->hasMany(UsulanFoto::class)->where('jenis', 'hasil');
    }

    /** Unit perlengkapan terpasang (ada setelah item ditandai terpasang); jenis per unit menghasilkan satu baris per unit. */
    public function asetUnit()
    {
        return $this->hasMany(PerlengkapanTerpasang::class)->orderBy('id');
    }

    /** Unit pertama (tautan lama berbasis item usulan menuju sini). */
    public function aset()
    {
        return $this->hasOne(PerlengkapanTerpasang::class)->oldestOfMany();
    }

    public function laporanKerusakan()
    {
        return $this->hasMany(LaporanKerusakan::class)->latest('id');
    }

    /** Laporan kerusakan yang masih berjalan (paling banyak satu per item). */
    public function laporanTerbuka()
    {
        return $this->hasOne(LaporanKerusakan::class)->aktif()->latest('id');
    }

    public function jumlahEfektif(): int
    {
        return $this->jumlah_disetujui ?? $this->jumlah;
    }

    /** Harga dikunci saat disetujui Bappeda; sebelum itu memakai harga master terbaru. */
    public function estimasi(): int
    {
        return $this->jumlahEfektif() * ($this->harga_satuan ?? $this->jenis->harga_satuan);
    }

    /**
     * Catat item sebagai terpasang (foto hasil wajib), perbarui status usulan, dan kabari pengusul.
     *
     * @param  array<int, array{file: \Illuminate\Http\UploadedFile, lat: ?float, lng: ?float}>  $foto
     */
    public function tandaiTerpasang(array $foto, int $jumlah, ?float $lat, ?float $lng, ?string $catatan = null, ?string $tanggal = null): void
    {
        $terakhir = null;
        foreach ($foto as $f) {
            $terakhir = UsulanFoto::create([
                'usulan_id' => $this->usulan_id,
                'usulan_item_id' => $this->id,
                'jenis' => 'hasil',
                'path' => $f['file']->store("usulan/{$this->usulan_id}", 'public'),
                'lat' => $f['lat'] ?? null,
                'lng' => $f['lng'] ?? null,
            ]);
        }
        $u = $this->usulan;
        // GPS foto galeri/HP yang jauh dari titik usulan tidak dipercaya
        if ($lat === null || $lng === null || ($u->lat && Usulan::jarak($u->lat, $u->lng, $lat, $lng) > config('usuljalan.jarak_pasang_maks'))) {
            [$lat, $lng] = [$u->lat, $u->lng];
        }
        $this->update([
            'status' => StatusItem::Terpasang,
            'jumlah_terpasang' => $jumlah,
            'terpasang_at' => $tanggal ?? now()->toDateString(),
            'lat_terpasang' => $lat,
            'lng_terpasang' => $lng,
            'catatan_terpasang' => $catatan ?: null,
        ]);
        $this->sinkronAset();
        $u->hitungStatus();
        $u->catat("{$this->jenis->namaPendek()} terpasang");
        Kabar::kirim($u, "{$this->jenis->namaPendek()} sudah terpasang",
            "{$jumlah} {$this->jenis->satuan} di ".($u->alamat ?: $u->lokasiSingkat()).'. Terima kasih sudah mengusulkan!',
            'terpasang', ['foto' => $terakhir?->url()]);
    }

    /**
     * Samakan unit perlengkapan terpasang dengan data terpasang item ini (titik terpasang, atau titik usulan).
     * Idempoten: data bersama diperbarui di semua unit (kode tidak berubah), unit kurang ditambah, unit lebih
     * dihapus mulai dari yang terbaru. Unit yang sudah punya riwayat tidak dihapus.
     *
     * @return PerlengkapanTerpasang unit pertama
     *
     * @throws \DomainException bila jumlah tidak bisa dikurangi karena unit sudah punya riwayat
     */
    public function sinkronAset(): PerlengkapanTerpasang
    {
        $u = $this->usulan;
        $tanggal = $this->terpasang_at;
        $banyak = max(1, $this->jumlah_terpasang ?? $this->jumlah);
        $perUnit = (bool) $this->jenis->per_unit;
        $bersama = [
            'jenis_perlengkapan_id' => $this->jenis_perlengkapan_id,
            'jumlah' => $perUnit ? 1 : $banyak,
            'harga_satuan' => $this->harga_satuan,
            'lat' => $this->lat_terpasang ?? $u->lat,
            'lng' => $this->lng_terpasang ?? $u->lng,
            'alamat' => $u->alamat,
            'kecamatan_id' => $u->kecamatan_id,
            'desa_id' => $u->desa_id,
            'terpasang_at' => $tanggal,
            'tahun' => $tanggal?->year,
            'catatan' => $this->catatan_terpasang,
        ];
        $target = $perUnit ? $banyak : 1;

        return DB::transaction(function () use ($bersama, $target) {
            $unit = $this->asetUnit()->get();
            if ($unit->count() > $target) {
                $lebih = $unit->slice($target)->reverse();
                $terkunci = $lebih->filter->punyaRiwayat();
                if ($terkunci->isNotEmpty()) {
                    throw new \DomainException("{$terkunci->count()} unit sudah punya riwayat; jumlah tidak bisa dikurangi di bawah ".($target + $terkunci->count()).'.');
                }
                $lebih->each->delete();
                $unit = $unit->take($target);
            }
            $unit->each->update($bersama);
            while ($unit->count() < $target) {
                $unit->push($this->asetUnit()->create($bersama));
            }
            $pertama = $unit->first();
            LaporanKerusakan::where('usulan_item_id', $this->id)->whereNull('perlengkapan_terpasang_id')->update(['perlengkapan_terpasang_id' => $pertama->id]);

            return $pertama;
        });
    }

    /** Batalkan penandaan terpasang (tombol Urungkan). */
    public function batalTerpasang(): void
    {
        $unit = $this->asetUnit()->get();
        // pengaman: unit yang sudah punya laporan/pemeliharaan tidak boleh hilang bersama penandaannya
        throw_if($unit->contains->punyaRiwayat(), \DomainException::class, 'Perlengkapan ini sudah punya riwayat, penandaan terpasang tidak bisa dibatalkan.');
        UsulanFoto::where('usulan_item_id', $this->id)->where('jenis', 'hasil')->get()->each(function ($f) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($f->path);
            $f->delete();
        });
        $this->update(['status' => StatusItem::Disetujui, 'jumlah_terpasang' => null, 'terpasang_at' => null, 'lat_terpasang' => null, 'lng_terpasang' => null, 'catatan_terpasang' => null]);
        $unit->each->delete();
        $this->usulan->hitungStatus();
        $this->usulan->catat("Penandaan {$this->jenis->namaPendek()} terpasang dibatalkan", null, false);
    }
}

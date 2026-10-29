<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu catatan pemeliharaan sebuah unit perlengkapan terpasang: dari laporan kerusakan yang selesai
 * (`laporan_kerusakan_id` terisi) atau dicatat petugas langsung (pemeliharaan rutin).
 */
class Pemeliharaan extends Model
{
    protected $table = 'pemeliharaan';

    protected $guarded = [];

    /** kunci => [label, label pendek]; urutan = urutan pilihan di form. */
    public const KEGIATAN = [
        'rutin' => 'Pemeliharaan rutin',
        'pembersihan' => 'Pembersihan',
        'perbaikan' => 'Perbaikan',
        'penggantian' => 'Penggantian',
        'lainnya' => 'Lainnya',
    ];

    /** Pilihan chip di form Catat pemeliharaan. */
    public const PILIHAN = ['rutin' => 'Rutin', 'pembersihan' => 'Pembersihan', 'perbaikan' => 'Perbaikan', 'penggantian' => 'Penggantian', 'lainnya' => 'Lainnya'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

    /**
     * Saring catatan (halaman Riwayat dan unduhannya).
     * Kunci: dari, sampai (Y-m-d), jenis (id), kecamatan (id), sumber (semua|laporan|rutin), petugas (id), cari (kode).
     */
    public function scopeSaring(\Illuminate\Database\Eloquent\Builder $q, array $f): void
    {
        $q->when($f['dari'] ?? null, fn ($q, $v) => $q->whereDate('tanggal', '>=', $v))
            ->when($f['sampai'] ?? null, fn ($q, $v) => $q->whereDate('tanggal', '<=', $v))
            ->when($f['petugas'] ?? null, fn ($q, $v) => $q->where('petugas_id', $v))
            ->when(($f['sumber'] ?? 'semua') === 'laporan', fn ($q) => $q->whereNotNull('laporan_kerusakan_id'))
            ->when(($f['sumber'] ?? 'semua') === 'rutin', fn ($q) => $q->whereNull('laporan_kerusakan_id'))
            ->when(($f['jenis'] ?? null) || ($f['kecamatan'] ?? null) || trim((string) ($f['cari'] ?? '')) !== '', fn ($q) => $q->whereHas('aset', fn ($a) => $a
                ->when($f['jenis'] ?? null, fn ($a, $v) => $a->where('jenis_perlengkapan_id', $v))
                ->when($f['kecamatan'] ?? null, fn ($a, $v) => $a->where('kecamatan_id', $v))
                ->when(trim((string) ($f['cari'] ?? '')) !== '', fn ($a) => $a->where('kode', 'like', '%'.strtoupper(trim($f['cari'])).'%'))));
    }

    public function aset()
    {
        return $this->belongsTo(PerlengkapanTerpasang::class, 'perlengkapan_terpasang_id');
    }

    public function petugas()
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function laporan()
    {
        return $this->belongsTo(LaporanKerusakan::class, 'laporan_kerusakan_id');
    }

    public function foto()
    {
        return $this->hasMany(PemeliharaanFoto::class);
    }

    /** Hanya Dishub (koordinator & petugas lapangan) yang mencatat; admin hanya melihat. */
    public static function bolehMencatat(?User $u): bool
    {
        return $u && ($u->isKoordinator() || $u->isPetugasLapangan());
    }

    /**
     * Catat pemeliharaan rutin/non-laporan pada satu unit.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $foto
     */
    public static function catat(PerlengkapanTerpasang $aset, string $kegiatan, ?string $catatan, ?string $tanggal, array $foto, User $oleh): self
    {
        abort_unless(self::bolehMencatat($oleh), 403);
        abort_unless(array_key_exists($kegiatan, self::KEGIATAN), 422);
        $p = self::create([
            'perlengkapan_terpasang_id' => $aset->id,
            'tanggal' => $tanggal ?: now()->toDateString(),
            'kegiatan' => $kegiatan,
            'catatan' => trim((string) $catatan) ?: null,
            'petugas_id' => $oleh->id,
        ]);
        foreach ($foto as $f) {
            $p->foto()->create(['path' => $f->store("pemeliharaan/{$p->id}", 'public')]);
        }

        return $p;
    }

    /** Urungkan / hapus catatan (foto yang diunggah ikut terhapus; foto hasil laporan dimiliki laporannya). */
    public function hapusCatat(): void
    {
        if (! $this->dariLaporan()) {
            $this->foto->each(fn ($f) => \Illuminate\Support\Facades\Storage::disk('public')->delete($f->path));
        }
        $this->delete();
    }

    public function label(): string
    {
        return self::KEGIATAN[$this->kegiatan] ?? ucfirst($this->kegiatan);
    }

    public function dariLaporan(): bool
    {
        return $this->laporan_kerusakan_id !== null;
    }

    /** Petugas boleh menghapus catatannya sendiri selama 10 menit; koordinator kapan saja (kecuali dari laporan). */
    public function bolehDihapus(User $u): bool
    {
        if ($this->dariLaporan()) {
            return false;
        }

        return $u->isKoordinator() || ($u->isPetugasLapangan() && $this->petugas_id === $u->id && $this->created_at->gt(now()->subMinutes(10)));
    }
}

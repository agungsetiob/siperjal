<?php

namespace App\Support;

use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\PerlengkapanTerpasang;
use Illuminate\Support\Facades\DB;

/**
 * Kode register unit perlengkapan terpasang: `JENIS-NNN-KEC-YY` (mis. RMB-014-BTL-26).
 * Nomor urut dari tabel penghitung per (jenis, kecamatan, tahun) yang hanya naik, jadi nomor aset yang
 * dihapus tidak dipakai ulang. Aset tanpa kode jenis/kecamatan/tahun tetap tercatat dan dikodekan belakangan.
 */
class KodeRegister
{
    public static function rakit(string $jenis, int $nomor, string $kecamatan, int $tahun): string
    {
        return sprintf('%s-%03d-%s-%02d', $jenis, $nomor, $kecamatan, $tahun % 100);
    }

    /** Pola yang sah untuk kode register (dipakai kolom ketik ulang di halaman pindai). */
    public static function cocok(string $kode): bool
    {
        return (bool) preg_match('/^[A-Z]{2,4}-\d{3,}-[A-Z]{2,4}-\d{2}$/', $kode);
    }

    /** Tahun pasang aset (tanggal, atau hanya tahun untuk data lama). */
    public static function tahunDari(PerlengkapanTerpasang $a): ?int
    {
        return $a->terpasang_at?->year ?? $a->tahun;
    }

    /** Beri kode bila aset belum berkode dan datanya lengkap. @return ?string kode aset (null = belum bisa) */
    public static function beri(PerlengkapanTerpasang $a): ?string
    {
        if ($a->kode) {
            return $a->kode;
        }
        $tahun = self::tahunDari($a);
        $jenis = JenisPerlengkapan::find($a->jenis_perlengkapan_id)?->kode;
        $kec = $a->kecamatan_id ? Kecamatan::find($a->kecamatan_id)?->kode : null;
        if (! $jenis || ! $kec || ! $tahun) {
            return null;
        }

        return DB::transaction(function () use ($a, $jenis, $kec, $tahun) {
            $kunci = ['jenis_perlengkapan_id' => $a->jenis_perlengkapan_id, 'kecamatan_id' => $a->kecamatan_id, 'tahun' => $tahun];
            DB::table('kode_register_urut')->insertOrIgnore($kunci + ['terakhir' => 0]);
            $baris = DB::table('kode_register_urut')->where($kunci)->lockForUpdate()->first();
            $nomor = $baris->terakhir + 1;
            DB::table('kode_register_urut')->where('id', $baris->id)->update(['terakhir' => $nomor]);
            $kode = self::rakit($jenis, $nomor, $kec, $tahun);
            DB::table('perlengkapan_terpasang')->where('id', $a->id)->update(['kode' => $kode]);
            $a->setAttribute('kode', $kode);
            $a->syncOriginalAttribute('kode');

            return $kode;
        });
    }

    /**
     * Beri kode ke semua aset yang belum berkode dan sudah lengkap datanya, yang tertua dulu.
     *
     * @return int jumlah yang baru berkode
     */
    public static function lengkapi(?int $jenisId = null, ?int $kecamatanId = null): int
    {
        $n = 0;
        PerlengkapanTerpasang::whereNull('kode')->whereNotNull('kecamatan_id')
            ->when($jenisId, fn ($q) => $q->where('jenis_perlengkapan_id', $jenisId))
            ->when($kecamatanId, fn ($q) => $q->where('kecamatan_id', $kecamatanId))
            ->get()
            ->sortBy(fn ($a) => ($a->terpasang_at?->toDateString() ?? ($a->tahun ? "{$a->tahun}-01-01" : '9999-12-31')).sprintf('%012d', $a->id))
            ->each(function ($a) use (&$n) {
                if (self::beri($a)) {
                    $n++;
                }
            });

        return $n;
    }

    /** Kode singkat bawaan untuk jenis & kecamatan yang sudah dikenal (dipakai migrasi data dan seeder). */
    public const KODE_JENIS = [
        'Rambu lalu lintas' => 'RMB', 'Marka jalan' => 'MRK', 'Lampu penerangan jalan' => 'PJU', 'Lampu peringatan' => 'WL',
        'Traffic light' => 'TL', 'Pagar pengaman (guardrail)' => 'GRL', 'Patok tikungan (delineator)' => 'DLN',
        'ZoSS (Zona Selamat Sekolah)' => 'ZSS', 'Pita getar' => 'PTG', 'Kerucut lalu lintas' => 'KRC',
        'Traffic barrier' => 'TBR', 'RPPJ (rambu penunjuk jurusan)' => 'RPJ',
    ];

    public const KODE_KECAMATAN = [
        'Batulicin' => 'BTL', 'Simpang Empat' => 'SPE', 'Kusan Hilir' => 'KSH', 'Karang Bintang' => 'KRB',
        'Mantewe' => 'MTW', 'Satui' => 'STI', 'Kusan Hulu' => 'KSU', 'Sungai Loban' => 'SLB', 'Angsana' => 'ANG',
        'Kuranji' => 'KRJ', 'Kusan Tengah' => 'KST', 'Teluk Kepayang' => 'TKP',
    ];
}

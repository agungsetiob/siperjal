<?php

namespace App\Support;

use App\Models\JenisPerlengkapan;
use App\Models\LogPengaturan;
use App\Models\PerlengkapanTerpasang;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Impor perlengkapan yang terpasang sebelum SIPERJAL dari Excel/CSV: baca + validasi (pratinjau), lalu simpan satu batch.
 * Kolom: Perlengkapan, Jumlah, Latitude, Longitude, Alamat, Kecamatan, Desa, Tahun, Catatan.
 */
class ImporTerpasang
{
    public const KOLOM = ['Perlengkapan', 'Jumlah', 'Latitude', 'Longitude', 'Alamat', 'Kecamatan', 'Desa', 'Tahun', 'Catatan'];

    public const MAKS_BARIS = 2000;

    /** Baris dengan jenis yang sama dalam radius ini (meter) dianggap sudah ada. */
    public const RADIUS_SAMA = 10;

    private const ALIAS = [
        'perlengkapan' => 'jenis', 'jenis' => 'jenis', 'jenisperlengkapan' => 'jenis',
        'jumlah' => 'jumlah', 'latitude' => 'lat', 'lat' => 'lat', 'longitude' => 'lng', 'lng' => 'lng', 'lon' => 'lng',
        'alamat' => 'alamat', 'lokasi' => 'alamat', 'kecamatan' => 'kecamatan', 'desa' => 'desa', 'tahun' => 'tahun',
        'tahunpasang' => 'tahun', 'catatan' => 'catatan', 'keterangan' => 'catatan',
    ];

    /**
     * Baca & validasi berkas. Tiap baris: no (nomor baris di berkas), status (sah | lewati | galat), alasan, data.
     *
     * @return array{baris: array<int, array<string, mixed>>, terlalu_banyak: bool}
     */
    public static function baca(string $path, string $ekstensi): array
    {
        $ekstensi = strtolower($ekstensi);
        $pembaca = SimpleExcelReader::create($path, $ekstensi === 'xlsx' ? 'xlsx' : 'csv');
        if ($ekstensi !== 'xlsx') {
            $baris1 = (string) strtok((string) file_get_contents($path), "\n");
            $pembaca->useDelimiter(substr_count($baris1, ';') > substr_count($baris1, ',') ? ';' : ',');
        }

        $jenis = JenisPerlengkapan::all()->flatMap(fn ($j) => [
            self::kunci($j->nama) => $j, self::kunci($j->namaPendek()) => $j,
        ]);
        [$pusatLat, $pusatLng] = config('usuljalan.peta_awal');
        $batas = (float) config('usuljalan.batas_impor_derajat', 0.6);
        $wilayah = [];
        $terlihat = [];
        $hasil = [];
        $no = 1;

        foreach ($pembaca->getRows() as $mentah) {
            $no++;
            if ($no - 1 > self::MAKS_BARIS) {
                return ['baris' => $hasil, 'terlalu_banyak' => true];
            }
            $r = [];
            foreach ($mentah as $k => $v) {
                if (isset(self::ALIAS[self::kunci((string) $k)])) {
                    $r[self::ALIAS[self::kunci((string) $k)]] = is_string($v) ? trim($v) : $v;
                }
            }
            if (collect($r)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue; // baris kosong
            }

            $j = $jenis[self::kunci((string) ($r['jenis'] ?? ''))] ?? null;
            $lat = self::angka($r['lat'] ?? null);
            $lng = self::angka($r['lng'] ?? null);
            $jumlah = ($r['jumlah'] ?? '') === '' ? 1 : self::angka($r['jumlah'] ?? null);
            $tahun = ($r['tahun'] ?? '') === '' ? null : (int) $r['tahun'];

            $galat = match (true) {
                ! $j => 'Perlengkapan "'.($r['jenis'] ?? '').'" tidak dikenal',
                $lat === null || $lng === null => 'Latitude/Longitude kosong atau bukan angka',
                abs($lat - $pusatLat) > $batas || abs($lng - $pusatLng) > $batas => 'Koordinat di luar wilayah',
                $jumlah === null || $jumlah < 1 || $jumlah != (int) $jumlah => 'Jumlah harus bilangan bulat ≥ 1',
                $tahun !== null && ($tahun < 1970 || $tahun > now()->year) => 'Tahun tidak masuk akal',
                default => null,
            };
            if ($galat) {
                $hasil[] = ['no' => $no, 'status' => 'galat', 'alasan' => $galat, 'data' => $r];

                continue;
            }

            $ada = PerlengkapanTerpasang::sekitar($lat, $lng, self::RADIUS_SAMA, $j->id)->isNotEmpty();
            $kunciTitik = $j->id.':'.round($lat, 4).':'.round($lng, 4);
            if ($ada || isset($terlihat[$kunciTitik])) {
                $hasil[] = ['no' => $no, 'status' => 'lewati', 'alasan' => $ada ? 'Sudah tercatat di titik ini' : 'Dobel di berkas', 'data' => $r];

                continue;
            }
            $terlihat[$kunciTitik] = true;

            $w = $wilayah[($r['kecamatan'] ?? '').'|'.($r['desa'] ?? '')] ??= Wilayah::cocokkan($r['kecamatan'] ?? null, $r['desa'] ?? null);
            $hasil[] = ['no' => $no, 'status' => 'sah', 'alasan' => null, 'data' => [
                'jenis_perlengkapan_id' => $j->id,
                'jenis' => $j->namaPendek(),
                'jumlah' => (int) $jumlah,
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'alamat' => ($r['alamat'] ?? '') ?: null,
                'kecamatan_id' => $w[0],
                'desa_id' => $w[1],
                'tahun' => $tahun,
                'catatan' => ($r['catatan'] ?? '') ?: null,
            ]];
        }

        return ['baris' => $hasil, 'terlalu_banyak' => false];
    }

    /**
     * Simpan baris yang sah sebagai satu batch (bisa dibatalkan).
     *
     * @param  array<int, array<string, mixed>>  $sah  `data` dari baris berstatus sah
     * @return string id batch
     */
    public static function simpan(array $sah): string
    {
        $batch = Str::lower(Str::random(12));
        DB::transaction(function () use ($sah, $batch) {
            foreach ($sah as $d) {
                PerlengkapanTerpasang::create([
                    'jenis_perlengkapan_id' => $d['jenis_perlengkapan_id'],
                    'jumlah' => $d['jumlah'],
                    'lat' => $d['lat'],
                    'lng' => $d['lng'],
                    'alamat' => $d['alamat'],
                    'kecamatan_id' => $d['kecamatan_id'],
                    'desa_id' => $d['desa_id'],
                    'tahun' => $d['tahun'],
                    'catatan' => $d['catatan'],
                    'batch' => $batch,
                ]);
            }
        });
        LogPengaturan::catat('Impor perlengkapan terpasang: '.count($sah).' baris');

        return $batch;
    }

    /** Batalkan impor: hapus yang belum pernah dilaporkan. @return int jumlah terhapus */
    public static function batalkan(string $batch): int
    {
        $n = PerlengkapanTerpasang::where('batch', $batch)->whereDoesntHave('laporanKerusakan')->delete();
        LogPengaturan::catat("Impor perlengkapan terpasang dibatalkan ({$n} baris)");

        return $n;
    }

    private static function kunci(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)) ?? '';
    }

    private static function angka(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = str_replace(',', '.', trim((string) $v));

        return is_numeric($v) ? (float) $v : null;
    }
}

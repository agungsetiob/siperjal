<?php

namespace App\Support;

use App\Models\Desa;
use App\Models\Kecamatan;
use Illuminate\Support\Collection;

/**
 * Mencocokkan nama wilayah dari peta (Nominatim) ke data Kecamatan & Desa.
 * Toleran terhadap awalan ("Kecamatan …", "Desa …"), huruf besar/kecil, tanda baca, dan salah ketik kecil.
 */
class Wilayah
{
    /** @return array{0: ?int, 1: ?int} [kecamatan_id, desa_id] */
    public static function cocokkan(array|string|null $kecamatan, array|string|null $desa): array
    {
        $kandidatKec = self::kandidat($kecamatan);
        $kandidatDesa = self::kandidat($desa);

        $kec = self::terbaik(Kecamatan::all(), $kandidatKec);
        $semuaDesa = Desa::all();

        if ($kec) {
            return [$kec->id, self::terbaik($semuaDesa->where('kecamatan_id', $kec->id), $kandidatDesa)?->id];
        }

        // Kecamatan tidak terbaca: cari desanya di semua kecamatan, pakai bila hanya ada satu yang cocok.
        $cocok = $semuaDesa->filter(fn ($d) => self::skor($d->nama, $kandidatDesa) > 0);
        $terbaik = self::terbaik($cocok, $kandidatDesa);
        if ($terbaik && $cocok->where('nama', $terbaik->nama)->count() === 1) {
            return [$terbaik->kecamatan_id, $terbaik->id];
        }

        return [null, null];
    }

    public static function normal(string $nama): string
    {
        $nama = mb_strtolower($nama);
        $nama = preg_replace('/^\s*(kecamatan|kec\.?|desa|ds\.?|kelurahan|kel\.?)\s+/u', '', $nama);
        $nama = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $nama);

        return trim(preg_replace('/\s+/', ' ', $nama));
    }

    /** @return string[] nama ternormalisasi yang tidak kosong */
    private static function kandidat(array|string|null $nama): array
    {
        return collect((array) $nama)->filter(fn ($n) => is_string($n) && trim($n) !== '')
            ->map(fn ($n) => self::normal($n))->filter()->unique()->values()->all();
    }

    private static function terbaik(Collection $data, array $kandidat)
    {
        if (! $kandidat) {
            return null;
        }

        return $data->map(fn ($m) => [$m, self::skor($m->nama, $kandidat)])
            ->filter(fn ($p) => $p[1] > 0)->sortByDesc(fn ($p) => $p[1])->first()[0] ?? null;
    }

    /** 100 = sama persis; 1–99 = mirip (salah ketik kecil); 0 = tidak cocok. */
    private static function skor(string $nama, array $kandidat): float
    {
        $nama = self::normal($nama);
        $skor = 0;
        foreach ($kandidat as $k) {
            if ($k === $nama) {
                return 100;
            }
            similar_text($k, $nama, $persen);
            // Salah ketik ≤ 2 huruf hanya untuk nama yang cukup panjang, agar nama pendek tidak tertukar.
            if ((min(mb_strlen($k), mb_strlen($nama)) >= 6 && levenshtein($k, $nama) <= 2) || $persen >= 85) {
                $skor = max($skor, min(max($persen, 1), 99));
            }
        }

        return $skor;
    }
}

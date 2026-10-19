<?php

namespace App\Support;

use App\Models\Pengaturan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Penyedia peta yang dipakai aplikasi: OpenStreetMap (Leaflet, gratis) atau Google Maps (butuh kunci API + billing).
 * Diatur admin di Pengaturan → Peta; kunci disimpan terenkripsi. Tanpa kunci → selalu OpenStreetMap.
 */
class PetaConfig
{
    public const PENYEDIA = ['osm' => 'OpenStreetMap', 'google' => 'Google Maps'];

    private const CACHE = 'peta_config';

    /** @return array{penyedia: string, kunci: ?string} nilai tersimpan (kunci masih terenkripsi) */
    private static function simpanan(): array
    {
        return Cache::rememberForever(self::CACHE, fn () => [
            'penyedia' => (string) Pengaturan::ambil('peta_penyedia', 'osm'),
            'kunci' => Pengaturan::ambil('peta_google_kunci'),
        ]);
    }

    /** Pilihan admin (tanpa mempertimbangkan kunci). */
    public static function pilihan(): string
    {
        $p = self::simpanan()['penyedia'];

        return array_key_exists($p, self::PENYEDIA) ? $p : 'osm';
    }

    public static function kunci(): ?string
    {
        $k = self::simpanan()['kunci'];
        if (! $k) {
            return null;
        }
        try {
            return Crypt::decryptString($k);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Penyedia yang benar-benar dipakai: Google hanya bila dipilih dan kunci terisi. */
    public static function penyedia(): string
    {
        return self::pilihan() === 'google' && self::kunci() ? 'google' : 'osm';
    }

    /** Isi `window.PETA` untuk JS. */
    public static function untukJs(): array
    {
        $google = self::penyedia() === 'google';

        return ['penyedia' => $google ? 'google' : 'osm', 'googleKey' => $google ? self::kunci() : null];
    }

    /** Kunci disamarkan untuk tampilan admin, mis. "AIza…k3Yz". */
    public static function kunciSamar(): ?string
    {
        $k = self::kunci();

        return $k ? substr($k, 0, 4).'…'.substr($k, -4) : null;
    }

    public static function simpan(string $penyedia, ?string $kunci = null): void
    {
        Pengaturan::simpan('peta_penyedia', $penyedia);
        if ($kunci !== null) {
            Pengaturan::simpan('peta_google_kunci', $kunci === '' ? null : Crypt::encryptString($kunci));
        }
        Cache::forget(self::CACHE);
    }
}

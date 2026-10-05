<?php

namespace App\Support;

use App\Models\Riwayat;
use App\Models\Usulan;
use App\Models\UsulanItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyimpan keadaan sebelum sebuah keputusan, agar bisa dibatalkan lewat toast "Urungkan".
 */
class Urungkan
{
    /** @param  array<int>  $usulanIds */
    public static function simpan(array $usulanIds): string
    {
        $token = Str::random(16);
        Cache::put(self::kunci($token), [
            'usulan' => Usulan::whereKey($usulanIds)->get()->map->getAttributes()->all(),
            'items' => UsulanItem::whereIn('usulan_id', $usulanIds)->get()->map->getAttributes()->all(),
            'riwayat' => (int) Riwayat::max('id'),
            'ids' => $usulanIds,
        ], now()->addMinutes(10));

        return $token;
    }

    /** Mengembalikan id usulan pertama yang dipulihkan, atau null. */
    public static function pulihkan(string $token): ?int
    {
        $data = Cache::pull(self::kunci($token));
        if (! $data) {
            return null;
        }

        DB::transaction(function () use ($data) {
            foreach ($data['usulan'] as $attr) {
                DB::table('usulan')->where('id', $attr['id'])->update($attr);
            }
            foreach ($data['items'] as $attr) {
                DB::table('usulan_item')->where('id', $attr['id'])->update($attr);
            }
            Riwayat::whereIn('usulan_id', $data['ids'])->where('id', '>', $data['riwayat'])->delete();
        });

        return $data['ids'][0] ?? null;
    }

    private static function kunci(string $token): string
    {
        return 'urungkan:'.auth()->id().':'.$token;
    }
}

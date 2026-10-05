<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan kunci–nilai sederhana (mis. tahun SSH). */
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    protected $primaryKey = 'kunci';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function ambil(string $kunci, mixed $bawaan = null): mixed
    {
        return static::find($kunci)?->nilai ?? $bawaan;
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        static::updateOrCreate(['kunci' => $kunci], ['nilai' => $nilai]);
    }
}

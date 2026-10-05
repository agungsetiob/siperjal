<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Instansi extends Model
{
    protected $table = 'instansi';

    protected $guarded = [];

    public const JENIS = ['desa' => 'Desa/Kelurahan', 'kecamatan' => 'Kecamatan', 'sekolah' => 'Sekolah', 'opd' => 'OPD', 'lainnya' => 'Lainnya'];

    public function kecamatan()
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

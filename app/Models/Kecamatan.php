<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kecamatan extends Model
{
    protected $table = 'kecamatan';

    protected $guarded = [];

    public function aset()
    {
        return $this->hasMany(PerlengkapanTerpasang::class);
    }

    public function desa()
    {
        return $this->hasMany(Desa::class)->orderBy('nama');
    }
}

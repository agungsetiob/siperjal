<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kecamatan extends Model
{
    protected $table = 'kecamatan';

    protected $guarded = [];

    public function desa()
    {
        return $this->hasMany(Desa::class)->orderBy('nama');
    }
}

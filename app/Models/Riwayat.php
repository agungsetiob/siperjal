<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Riwayat extends Model
{
    protected $table = 'riwayat';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['publik' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogPengaturan extends Model
{
    protected $table = 'log_pengaturan';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function catat(string $keterangan): void
    {
        static::create(['user_id' => auth()->id(), 'keterangan' => $keterangan]);
    }
}

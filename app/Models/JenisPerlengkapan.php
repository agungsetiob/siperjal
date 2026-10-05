<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JenisPerlengkapan extends Model
{
    protected $table = 'jenis_perlengkapan';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'harga_satuan' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(UsulanItem::class);
    }

    /** Nama pendek untuk label (tanpa keterangan dalam kurung). */
    public function namaPendek(): string
    {
        return str($this->nama)->before(' (')->toString();
    }

    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true)->orderBy('urutan');
    }
}

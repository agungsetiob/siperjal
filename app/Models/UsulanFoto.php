<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class UsulanFoto extends Model
{
    protected $table = 'usulan_foto';

    protected $guarded = [];

    /** Data untuk penampil foto (<x-lihat-foto>): [{ url, ket }]. */
    public static function galeri(iterable $foto): array
    {
        return collect($foto)->values()->map(fn (self $f) => [
            'url' => $f->url(),
            'ket' => ($f->jenis === 'hasil' ? 'Hasil pemasangan' : 'Foto pengusul').' · '.$f->created_at->translatedFormat('j M Y'),
        ])->all();
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}

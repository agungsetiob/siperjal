<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PemeliharaanFoto extends Model
{
    protected $table = 'pemeliharaan_foto';

    protected $guarded = [];

    /** Data untuk penampil foto (<x-lihat-foto>): [{ url, ket }]. */
    public static function galeri(iterable $foto): array
    {
        return collect($foto)->values()->map(fn (self $f) => [
            'url' => $f->url(),
            'ket' => 'Pemeliharaan · '.$f->created_at->translatedFormat('j M Y, H.i'),
        ])->all();
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}

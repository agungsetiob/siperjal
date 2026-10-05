<?php

namespace App\Http\Controllers;

use App\Support\Panduan;
use Illuminate\Http\Request;

class PanduanController extends Controller
{
    public function index()
    {
        return view('panduan.index', ['menu' => Panduan::menu(), 'indeks' => Panduan::indeks()]);
    }

    public function artikel(string $grup, string $slug)
    {
        $artikel = Panduan::cari($grup, $slug);
        abort_unless($artikel, 404);

        return view('panduan.artikel', [
            'artikel' => $artikel,
            'menu' => Panduan::menu(),
            'indeks' => Panduan::indeks(),
        ]);
    }

    /** Data menu bantuan (tombol ?) untuk layar yang sedang dibuka: rute + query (mis. tab) dikirim browser. */
    public function menu(Request $request)
    {
        $layar = Panduan::layarDari($request->user(), (string) $request->query('rute'), $request->query());

        return response()->json(Panduan::menuBantuan($layar));
    }

    public function gambar(string $nama)
    {
        abort_unless(preg_match('/^[\w.-]+\.(png|jpe?g|webp|gif)$/i', $nama), 404);
        $berkas = Panduan::folder().'/gambar/'.$nama;
        abort_unless(is_file($berkas), 404);

        return response()->file($berkas, ['Cache-Control' => 'public, max-age=86400']);
    }
}

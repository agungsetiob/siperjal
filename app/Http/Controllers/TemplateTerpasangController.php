<?php

namespace App\Http\Controllers;

use App\Models\JenisPerlengkapan;
use App\Support\ImporTerpasang;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** Template Excel impor perlengkapan yang terpasang sebelum SIPERJAL (berisi contoh baris & daftar nama perlengkapan). */
class TemplateTerpasangController extends Controller
{
    public function __invoke()
    {
        [$lat, $lng] = config('usuljalan.peta_awal');
        $contoh = JenisPerlengkapan::aktif()->first();

        $path = storage_path('app/private/template-perlengkapan-terpasang-'.now()->format('YmdHis').'.xlsx');
        $writer = SimpleExcelWriter::create($path);
        $writer->addRow(array_combine(ImporTerpasang::KOLOM, [
            $contoh?->nama ?? 'Rambu peringatan', 1, $lat, $lng, 'Jl. Raya Batulicin (hapus baris contoh ini)', '', '', 2019, '',
        ]));
        $writer->close();

        return response()->download($path, 'template-perlengkapan-terpasang.xlsx')->deleteFileAfterSend();
    }
}

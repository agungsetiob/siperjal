<?php

namespace App\Http\Controllers;

use App\Models\JenisPerlengkapan;
use App\Support\ImporTerpasang;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** Template Excel impor perlengkapan yang terpasang sebelum SIPERJAL (sheet Data berisi contoh baris; sheet Daftar perlengkapan berisi nama jenis yang dikenali). */
class TemplateTerpasangController extends Controller
{
    public function __invoke()
    {
        [$lat, $lng] = config('usuljalan.peta_awal');
        $contoh = JenisPerlengkapan::aktif()->first();

        $path = storage_path('app/private/template-perlengkapan-terpasang-'.now()->format('YmdHis').'.xlsx');
        $writer = SimpleExcelWriter::create($path)->nameCurrentSheet('Data');
        $writer->addRow(array_combine(ImporTerpasang::KOLOM, [
            $contoh?->nama ?? 'Rambu peringatan', 1, $contoh?->harga_satuan ?: '', $lat, $lng, 'Jl. Raya Batulicin (hapus baris contoh ini)', '', '', 2019, '',
        ]));

        // Sheet kedua: nama jenis yang dikenali sistem, tinggal disalin ke kolom Perlengkapan (impor hanya membaca sheet pertama)
        $writer->addNewSheetAndMakeItCurrent('Daftar perlengkapan');
        JenisPerlengkapan::orderBy('urutan')->get()->each(fn ($j) => $writer->addRow([
            'Perlengkapan (salin ke sheet Data)' => $j->nama,
            'Satuan jumlah' => $j->satuan,
            'Harga SSH sekarang (Rp)' => $j->harga_satuan ?: '',
        ]));
        $writer->close();

        return response()->download($path, 'template-perlengkapan-terpasang.xlsx')->deleteFileAfterSend();
    }
}

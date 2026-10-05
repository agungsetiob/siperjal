<?php

namespace App\Http\Controllers;

use App\Support\Rekap;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;

class LaporanExportController extends Controller
{
    public function __invoke(Request $request, string $format)
    {
        $rekap = new Rekap((int) $request->input('tahun', now()->year), $request->integer('kecamatan') ?: null, $request->integer('jenis') ?: null);
        $items = $rekap->items();

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.laporan', [
                'rekap' => $rekap,
                'tahap' => Rekap::tahap($items),
                'perJenis' => Rekap::per($items, fn ($i) => $i->jenis->nama),
                'perKecamatan' => Rekap::per($items, fn ($i) => $i->usulan->kecamatan?->nama ?? '-'),
            ])->setPaper('a4', 'landscape')->download("rekap-usulan-{$rekap->tahun}.pdf");
        }

        abort_unless($format === 'xlsx', 404);
        $path = storage_path("app/private/rekap-usulan-{$rekap->tahun}.xlsx");
        $writer = SimpleExcelWriter::create($path);
        foreach ($items as $i) {
            $writer->addRow([
                'Nomor' => $i->usulan->nomor,
                'Diajukan' => $i->usulan->diajukan_at?->format('Y-m-d'),
                'Pengusul' => $i->usulan->namaPengusul(),
                'Jenis pengusul' => $i->usulan->jenisPengusul(),
                'Kecamatan' => $i->usulan->kecamatan?->nama,
                'Desa' => $i->usulan->desa?->nama,
                'Alamat' => $i->usulan->alamat,
                'Lat' => $i->usulan->lat,
                'Lng' => $i->usulan->lng,
                'Perlengkapan' => $i->jenis->nama,
                'Jumlah' => $i->jumlahEfektif(),
                'Satuan' => $i->jenis->satuan,
                'Estimasi biaya' => $i->estimasi(),
                'Skor' => $i->usulan->skor,
                'Status item' => $i->status->label(),
                'Tahun anggaran' => $i->tahun_anggaran,
                'Terpasang' => $i->terpasang_at?->format('Y-m-d'),
            ]);
        }
        $writer->close();

        return response()->download($path)->deleteFileAfterSend();
    }
}

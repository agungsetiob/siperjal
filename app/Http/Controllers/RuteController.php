<?php

namespace App\Http\Controllers;

use App\Enums\StatusItem;
use App\Models\UsulanItem;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** Ekspor rute pemasangan (urutan dari peta) ke Excel untuk tim lapangan. */
class RuteController extends Controller
{
    public function __invoke(Request $request)
    {
        $urut = collect(explode(',', (string) $request->query('ids')))->map(fn ($id) => (int) $id)->filter()->values();
        $items = UsulanItem::with('jenis', 'usulan.desa', 'usulan.kecamatan')
            ->whereKey($urut)->where('status', StatusItem::Disetujui)->get()
            ->sortBy(fn ($i) => $urut->search($i->id));

        $path = storage_path('app/private/rute-pemasangan-'.now()->format('Ymd').'.xlsx');
        $writer = SimpleExcelWriter::create($path);
        foreach ($items->values() as $n => $i) {
            $writer->addRow([
                'Titik' => $n + 1,
                'Nomor usulan' => $i->usulan->nomor,
                'Lokasi' => $i->usulan->alamat,
                'Desa' => $i->usulan->desa?->nama,
                'Kecamatan' => $i->usulan->kecamatan?->nama,
                'Perlengkapan' => $i->jenis->nama,
                'Jumlah' => $i->jumlahEfektif(),
                'Satuan' => $i->jenis->satuan,
                'Tahun anggaran' => $i->tahun_anggaran,
                'Lat' => $i->usulan->lat,
                'Lng' => $i->usulan->lng,
                'Google Maps' => "https://www.google.com/maps?q={$i->usulan->lat},{$i->usulan->lng}",
            ]);
        }
        $writer->close();

        return response()->download($path)->deleteFileAfterSend();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\PerlengkapanTerpasang;
use App\Support\Qr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cetak label QR untuk unit terpasang yang sudah berkode register.
 * Tata letak `a4`: banyak label per lembar A4 (kertas stiker); `satuan`: satu label per halaman seukuran label (printer label).
 */
class LabelQrController extends Controller
{
    public const MAKS = 200;

    public const TATA = ['a4', 'satuan'];

    /** ukuran => [lebar mm, tinggi mm, kolom, baris per lembar] */
    public const UKURAN = [
        'sedang' => [60, 30, 3, 8],
        'kecil' => [40, 20, 5, 13],
    ];

    public function __invoke(Request $request)
    {
        $ids = array_values(array_unique((array) $request->input('ids', [])));
        if (count($ids) > self::MAKS) {
            throw ValidationException::withMessages(['ids' => 'Maksimal '.self::MAKS.' label sekali unduh.']);
        }
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'ukuran' => ['required', 'in:'.implode(',', array_keys(self::UKURAN))],
            'tata' => ['nullable', 'in:'.implode(',', self::TATA)],
        ], ['ids.required' => 'Pilih minimal satu unit.', 'ids.min' => 'Pilih minimal satu unit.', 'ukuran.in' => 'Ukuran label tidak dikenal.', 'tata.in' => 'Tata letak label tidak dikenal.']);
        $satuan = ($data['tata'] ?? 'a4') === 'satuan';

        $unit = PerlengkapanTerpasang::with('jenis', 'kecamatan')->whereIn('id', $ids)->aktif()->whereNotNull('kode')->orderBy('kode')->get();
        if ($unit->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Unit yang dipilih belum berkode register, jadi belum bisa dicetak labelnya.']);
        }

        // 200 label bisa memakan ±30 detik di dompdf: beri ruang waktu & memori agar tidak terpotong
        set_time_limit(180);
        ini_set('memory_limit', '256M');

        [$lebar, $tinggi, $kolom, $baris] = self::UKURAN[$data['ukuran']];
        $label = $unit->map(fn ($a) => [
            'kode' => $a->kode,
            'jenis' => $a->jenis->namaPendek(),
            'kecamatan' => $a->kecamatan?->nama,
            'qr' => Qr::pngDataUri(route('aset', $a->kode)),
        ]);

        $pt = fn (float $mm) => $mm * 72 / 25.4;

        return Pdf::loadView('pdf.label-qr', [
            'halaman' => $label->chunk($satuan ? 1 : $kolom * $baris),
            'lebar' => $lebar, 'tinggi' => $tinggi, 'kolom' => $kolom, 'ukuran' => $data['ukuran'], 'satuan' => $satuan,
        ])->setPaper($satuan ? [0, 0, $pt($lebar), $pt($tinggi)] : 'a4')
            ->download('label-qr-'.($satuan ? 'satuan-' : '').now()->format('Y-m-d').'.pdf');
    }
}

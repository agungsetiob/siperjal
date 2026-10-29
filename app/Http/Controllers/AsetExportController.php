<?php

namespace App\Http\Controllers;

use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Unduh daftar perlengkapan terpasang (`data=aset`, bawaan) atau catatan pemeliharaan (`data=riwayat`).
 * Query: format=xlsx|csv (riwayat juga pdf) + filter yang sama dengan halamannya.
 */
class AsetExportController extends Controller
{
    private const BATAS = 20000;

    public function __invoke(Request $request)
    {
        $riwayat = $request->query('data') === 'riwayat';
        $format = $request->query('format', 'xlsx');
        abort_unless(in_array($format, $riwayat ? ['xlsx', 'csv', 'pdf'] : ['xlsx', 'csv'], true), 404);

        return $riwayat ? $this->riwayat($request, $format) : $this->aset($request, $format);
    }

    private function aset(Request $request, string $format)
    {
        $baris = PerlengkapanTerpasang::saring($request->query())
            ->with('jenis', 'kecamatan', 'desa', 'usulanItem.usulan', 'laporanTerbuka')
            ->withMax('pemeliharaan as rawat_terakhir', 'tanggal')
            ->orderBy('kode')->orderBy('id')->limit(self::BATAS)->get()
            ->map(fn (PerlengkapanTerpasang $a) => [
                'Kode register' => self::aman($a->kode ?? ''),
                'Jenis' => self::aman($a->jenis->nama),
                'Jumlah' => $a->jenis->per_unit ? '1 unit' : $a->jumlah.' '.$a->jenis->satuan,
                'Kecamatan' => self::aman($a->kecamatan?->nama ?? ''),
                'Desa' => self::aman($a->desa?->nama ?? ''),
                'Alamat' => self::aman($a->alamat ?? ''),
                'Koordinat' => $format === 'xlsx'
                    ? sprintf('=HYPERLINK("%s","%s, %s")', $a->mapsUrl(), $a->lat, $a->lng)
                    : "{$a->lat}, {$a->lng}",
                'Tahun pasang' => $a->terpasang_at?->year ?? $a->tahun,
                'Sumber' => $a->usulanItem?->usulan?->nomor ?? 'Sebelum SIPERJAL',
                'Harga satuan' => $a->harga_satuan,
                'Status' => $a->laporanTerbuka ? 'Dilaporkan rusak' : 'Baik',
                'Pemeliharaan terakhir' => $a->rawat_terakhir ? substr((string) $a->rawat_terakhir, 0, 10) : '',
            ]);

        return $this->tulis($baris, $format, 'perlengkapan-terpasang');
    }

    private function riwayat(Request $request, string $format)
    {
        $catatan = Pemeliharaan::saring($request->query())
            ->with('aset.jenis', 'aset.desa', 'aset.kecamatan', 'petugas', 'laporan')
            ->orderByDesc('tanggal')->orderByDesc('id')->limit(self::BATAS)->get();

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.riwayat', ['catatan' => $catatan])->setPaper('a4', 'landscape')
                ->download('riwayat-pemeliharaan-'.now()->format('Y-m-d').'.pdf');
        }

        $baris = $catatan->map(fn (Pemeliharaan $p) => [
            'Tanggal' => $p->tanggal->format('Y-m-d'),
            'Kode register' => self::aman($p->aset?->kode ?? ''),
            'Jenis' => self::aman($p->aset?->jenis?->nama ?? ''),
            'Lokasi' => self::aman($p->aset?->lokasi() ?? ''),
            'Kegiatan' => $p->label(),
            'Sumber' => $p->laporan?->nomor ? 'Laporan '.$p->laporan->nomor : 'Rutin',
            'Petugas' => self::aman($p->petugas?->name ?? ''),
            'Catatan' => self::aman($p->catatan ?? ''),
        ]);

        return $this->tulis($baris, $format, 'riwayat-pemeliharaan');
    }

    private function tulis($baris, string $format, string $nama)
    {
        $berkas = "{$nama}-".now()->format('Y-m-d').".{$format}";
        $path = storage_path('app/private/'.uniqid('unduh-').".{$format}");
        @mkdir(dirname($path), 0775, true);
        $writer = SimpleExcelWriter::create($path);
        foreach ($baris as $b) {
            $writer->addRow($b);
        }
        $writer->close();

        return response()->download($path, $berkas)->deleteFileAfterSend();
    }

    /** Cegah injeksi rumus: teks dari pengguna yang diawali = + - @ diberi apostrof. */
    private static function aman(?string $teks): string
    {
        $teks = (string) $teks;

        return $teks !== '' && str_contains('=+-@', $teks[0]) ? "'".$teks : $teks;
    }
}

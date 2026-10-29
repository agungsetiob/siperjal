<?php

namespace App\Support;

use App\Models\PerlengkapanTerpasang;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR kode register: isinya alamat publik `/aset/{kode}` (halaman info & riwayat tanpa login). */
class Qr
{
    /** SVG QR untuk teks apa pun (tanpa deklarasi XML, siap disisipkan di HTML). */
    public static function svg(string $isi, int $ukuran = 240, int $margin = 1): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($ukuran, $margin), new SvgImageBackEnd)))->writeString($isi);

        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }

    /** SVG QR sebuah unit; null bila unit belum berkode. */
    public static function untuk(PerlengkapanTerpasang $aset, int $ukuran = 240, int $margin = 1): ?string
    {
        return $aset->kode ? self::svg(route('aset', $aset->kode), $ukuran, $margin) : null;
    }

    /** `data:` URI untuk dipakai di <img> (mis. di PDF dompdf, yang tidak bisa menampilkan SVG inline). */
    public static function dataUri(string $isi, int $ukuran = 240, int $margin = 1): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($isi, $ukuran, $margin));
    }

    /**
     * Matriks QR sebagai potongan horizontal modul gelap per baris, untuk digambar dengan <div> di PDF
     * (dompdf tidak menampilkan SVG). Hasil: ['n' => jumlah modul per sisi, 'baris' => [[ [x, panjang], ... ], ...]].
     */
    public static function potongan(string $isi): array
    {
        $m = \BaconQrCode\Encoder\Encoder::encode($isi, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $n = $m->getWidth();
        $baris = [];
        for ($y = 0; $y < $n; $y++) {
            $r = [];
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) !== 1) {
                    continue;
                }
                $mulai = $x;
                while ($x + 1 < $n && $m->get($x + 1, $y) === 1) {
                    $x++;
                }
                $r[] = [$mulai, $x - $mulai + 1];
            }
            $baris[] = $r;
        }

        return ['n' => $n, 'baris' => $baris];
    }

    /**
     * QR sebagai gambar PNG (data-URI) tanpa margin, digambar dengan GD. Dipakai label PDF: satu gambar per label
     * jauh lebih ringan bagi dompdf daripada ratusan elemen (batas 200 label per unduhan).
     */
    public static function pngDataUri(string $isi, int $skala = 12): string
    {
        $m = \BaconQrCode\Encoder\Encoder::encode($isi, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $n = $m->getWidth();
        $gambar = imagecreate($n * $skala, $n * $skala);
        $putih = imagecolorallocate($gambar, 255, 255, 255);
        $hitam = imagecolorallocate($gambar, 0, 1, 0);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) === 1) {
                    imagefilledrectangle($gambar, $x * $skala, $y * $skala, ($x + 1) * $skala - 1, ($y + 1) * $skala - 1, $hitam);
                }
            }
        }
        ob_start();
        imagepng($gambar);
        $png = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }
}

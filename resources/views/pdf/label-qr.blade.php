<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000100; }
        @php
            $qrMm = $tinggi - 6;               // sisi QR (mm)
            $lembarL = $kolom * $lebar;        // lebar blok label
            $kiri = (210 - $lembarL) / 2;
            $atas = $ukuran === 'sedang' ? 28.5 : 13.5;
        @endphp
        .lembar { position: relative; width: {{ $satuan ? $lebar : 210 }}mm; height: {{ $satuan ? $tinggi : 297 }}mm; page-break-after: always; overflow: hidden; }
        .lembar:last-child { page-break-after: auto; }
        .label { position: absolute; width: {{ $lebar }}mm; height: {{ $tinggi }}mm; box-sizing: border-box; overflow: hidden;{{ $satuan ? '' : ' border: 0.1mm solid #b8bccb;' }} }
        .qr { position: absolute; left: 3mm; top: 3mm; width: {{ $qrMm }}mm; height: {{ $qrMm }}mm; }
        .teks { position: absolute; left: {{ $qrMm + 4.5 }}mm; top: 3mm; width: {{ $lebar - $qrMm - 6 }}mm; }
        .kode { font-weight: bold; font-size: {{ $ukuran === 'sedang' ? 8.5 : 6 }}pt; line-height: 1.15; }
        .ket { font-size: {{ $ukuran === 'sedang' ? 6.5 : 4.5 }}pt; color: #333; margin-top: 1mm; line-height: 1.2; }
    </style>
</head>
<body>
    @foreach ($halaman as $unit)
        <div class="lembar">
            @foreach ($unit->values() as $i => $l) {{-- chunk() mempertahankan key: tiap lembar mulai dari 0 --}}
                @php
                    // satuan: halaman = label, jadi label menempel di pojok kiri atas
                    $x = $satuan ? 0 : $kiri + ($i % $kolom) * $lebar;
                    $y = $satuan ? 0 : $atas + intdiv($i, $kolom) * $tinggi;
                @endphp
                <div class="label" style="left: {{ $x }}mm; top: {{ $y }}mm;">
                    <img class="qr" src="{{ $l['qr'] }}" alt="">
                    <div class="teks">
                        <div class="kode">{{ $l['kode'] }}</div>
                        <div class="ket">{{ $l['jenis'] }}@if ($l['kecamatan'])<br>{{ $l['kecamatan'] }}@endif</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>

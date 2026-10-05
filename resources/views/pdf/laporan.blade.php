<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #000100; }
        h1 { font-size: 16pt; margin: 0; } h2 { font-size: 11pt; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e1e4ee; padding: 5px 7px; } th { background: #f4f5fa; text-align: left; }
        .r { text-align: right; } .muted { color: #565b73; }
    </style>
</head>
<body>
    <h1>Rekap Usulan Perlengkapan Jalan {{ $rekap->tahun }}</h1>
    <div class="muted">Dicetak {{ now()->translatedFormat('j F Y H.i') }}</div>

    @php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
    <h2>Ringkasan</h2>
    <table>
        <tr><th>Tahap</th><th class="r">Item</th><th class="r">Estimasi biaya</th></tr>
        @foreach (['masuk' => 'Diajukan', 'lolos' => 'Lolos Dishub', 'disetujui' => 'Disetujui Bappeda', 'terpasang' => 'Terpasang'] as $k => $l)
            <tr><td>{{ $l }}</td><td class="r">{{ $tahap[$k]['jumlah'] }}</td><td class="r">{{ $rp($tahap[$k]['biaya']) }}</td></tr>
        @endforeach
    </table>

    @foreach (['Per jenis perlengkapan' => $perJenis, 'Per kecamatan' => $perKecamatan] as $judul => $data)
        <h2>{{ $judul }}</h2>
        <table>
            <tr><th></th><th class="r">Diajukan</th><th class="r">Lolos</th><th class="r">Disetujui</th><th class="r">Biaya disetujui</th><th class="r">Terpasang</th></tr>
            @foreach ($data as $nama => $t)
                <tr><td>{{ $nama }}</td><td class="r">{{ $t['masuk']['jumlah'] }}</td><td class="r">{{ $t['lolos']['jumlah'] }}</td><td class="r">{{ $t['disetujui']['jumlah'] }}</td><td class="r">{{ $rp($t['disetujui']['biaya']) }}</td><td class="r">{{ $t['terpasang']['jumlah'] }}</td></tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>

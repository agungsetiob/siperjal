<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #000100; }
        h1 { font-size: 16pt; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #e1e4ee; padding: 4px 6px; vertical-align: top; } th { background: #f4f5fa; text-align: left; }
        .muted { color: #565b73; }
    </style>
</head>
<body>
    <h1>Riwayat Pemeliharaan Perlengkapan Jalan</h1>
    <div class="muted">Dicetak {{ now()->translatedFormat('j F Y H.i') }} · {{ $catatan->count() }} catatan</div>

    <table>
        <tr><th>Tanggal</th><th>Kode register</th><th>Jenis</th><th>Lokasi</th><th>Kegiatan</th><th>Sumber</th><th>Petugas</th><th>Catatan</th></tr>
        @forelse ($catatan as $p)
            <tr>
                <td>{{ $p->tanggal->format('d/m/Y') }}</td>
                <td>{{ $p->aset?->kode ?? '-' }}</td>
                <td>{{ $p->aset?->jenis?->namaPendek() }}</td>
                <td>{{ $p->aset?->lokasi() }}</td>
                <td>{{ $p->label() }}</td>
                <td>{{ $p->laporan?->nomor ? 'Laporan '.$p->laporan->nomor : 'Rutin' }}</td>
                <td>{{ $p->petugas?->name }}</td>
                <td>{{ $p->catatan }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Tidak ada catatan pada penyaring ini.</td></tr>
        @endforelse
    </table>
</body>
</html>

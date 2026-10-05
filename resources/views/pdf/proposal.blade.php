<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #000100; line-height: 1.5; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        .muted { color: #565b73; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e1e4ee; padding: 6px 8px; text-align: left; }
        th { background: #f4f5fa; }
        .foto img { width: 48%; margin: 1%; }
    </style>
</head>
<body>
    <p class="muted">{{ now()->translatedFormat('j F Y') }}</p>
    <p>Kepada Yth.<br>Kepala Dinas Perhubungan<br>di tempat</p>

    <h1>Usulan Perlengkapan Jalan</h1>
    <p>Dengan hormat, saya yang bertanda tangan di bawah ini:</p>
    <table>
        <tr><th width="30%">Nama</th><td>{{ $usulan->instansi?->nama ?? $usulan->user->name }}</td></tr>
        @if ($usulan->instansi) <tr><th>Penanggung jawab</th><td>{{ $usulan->penanggung_jawab }}</td></tr> @endif
        <tr><th>No. HP</th><td>{{ $usulan->user->no_hp }}</td></tr>
        <tr><th>Email</th><td>{{ $usulan->user->email }}</td></tr>
    </table>

    <p>mengusulkan pengadaan perlengkapan jalan pada lokasi berikut:</p>
    <table>
        <tr><th width="30%">Alamat / patokan</th><td>{{ $usulan->alamat ?: '-' }}</td></tr>
        <tr><th>Desa / Kecamatan</th><td>{{ $usulan->lokasiSingkat() }}</td></tr>
        <tr><th>Koordinat</th><td>{{ $usulan->lat }}, {{ $usulan->lng }}</td></tr>
    </table>

    <table>
        <tr><th>Perlengkapan</th><th width="25%">Jumlah</th></tr>
        @foreach ($usulan->items as $i)
            <tr><td>{{ $i->jenis->nama }}</td><td>{{ $i->jumlah }} {{ $i->jenis->satuan }}</td></tr>
        @endforeach
    </table>

    <p><b>Alasan:</b>
        {{ collect($usulan->kriteria_klaim)->map(fn ($k) => \App\Enums\Kriteria::from($k)->labelAwam())->implode('; ') ?: '-' }}.
        {{ $usulan->keterangan }}
    </p>

    <p>Demikian usulan ini kami sampaikan. Atas perhatian Bapak/Ibu, kami ucapkan terima kasih.</p>
    <p style="margin-top:40px">Hormat kami,<br><br><br><b>{{ $usulan->penanggung_jawab ?? $usulan->user->name }}</b></p>

    @if ($usulan->foto->isNotEmpty())
        <div style="page-break-before: always"></div>
        <h1>Lampiran foto lokasi</h1>
        <div class="foto">
            @foreach ($usulan->foto->take(6) as $f)
                <img src="{{ storage_path('app/public/'.$f->path) }}">
            @endforeach
        </div>
    @endif
</body>
</html>

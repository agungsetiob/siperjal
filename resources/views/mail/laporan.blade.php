{{-- Email kabar laporan kerusakan (akun & pelapor tanpa akun). Gaya inline agar tampil di klien email (selaras mail/usulan). --}}
@php
    $l = $laporan;
    $publik = $penerima instanceof \Illuminate\Notifications\AnonymousNotifiable;
    $petugas = ! $publik && in_array($jenis, ['kerusakan_baru', 'kerusakan_tugas']) && ! $penerima->isPengusul();
    $nama = $publik ? $l->nama_pelapor : $penerima->name;
    $link = $publik ? route('lapor.status', $l->nomor) : route('pemeliharaan', $l);
@endphp
<!DOCTYPE html>
<html lang="id">
<body style="margin:0;padding:16px;background:#F4F5FA;font-family:Helvetica,Arial,sans-serif;color:#000100">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#FFFFFF;border-radius:6px;overflow:hidden">
    <tr><td style="background:#4556A9;padding:18px 20px">
        <div style="font-family:Impact,'Anton',sans-serif;font-size:16px;letter-spacing:.5px;color:#FFFFFF;text-transform:uppercase">SIPERJAL · Pemeliharaan</div>
        <div style="font-family:Impact,'Anton',sans-serif;font-size:30px;line-height:32px;color:#FFFFFF;text-transform:uppercase;margin-top:10px">{{ $judul }}</div>
    </td></tr>
    <tr><td style="padding:20px">
        <p style="font-size:15px;line-height:23px;color:#565B73;margin:0 0 14px">
            Halo{{ $nama ? ' '.str($nama)->before(' ') : '' }}, ada kabar untuk laporan <b style="color:#000100">{{ $l->nomor }}</b> ({{ $l->judul() }} · {{ $l->lokasi() }}).
        </p>
        @if ($isi)
            <div style="background:#F4F5FA;border-radius:6px;padding:14px;margin:0 0 14px;font-size:15px;line-height:22px;color:#000100">{{ $isi }}</div>
        @endif
        @if (! empty($ekstra['foto']))
            <img src="{{ $ekstra['foto'] }}" alt="" style="width:100%;height:auto;border-radius:6px;margin:0 0 14px">
        @endif
        <a href="{{ $link }}" style="display:block;text-align:center;background:#4556A9;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:15px;padding:12px 20px;border-radius:4px">{{ $petugas ? 'Buka Laporan' : ($publik ? 'Lihat Status' : 'Lihat Laporan') }} →</a>
    </td></tr>
    <tr><td style="background:#F4F5FA;padding:14px 20px 18px">
        @if ($publik)
            <div style="font-size:12px;color:#4556A9">Lacak kapan saja: <a href="{{ $link }}" style="color:#4556A9">{{ str($link)->after('://') }}</a></div>
            <div style="font-size:12px;color:#565B73;margin-top:4px">Email ini dikirim karena Anda melaporkan kerusakan perlengkapan jalan dan meminta dikabari.</div>
        @else
            <div style="font-size:12px;color:#565B73">Email ini dikirim karena Anda terkait dengan laporan kerusakan perlengkapan jalan ini.</div>
        @endif
    </td></tr>
</table>
</body>
</html>

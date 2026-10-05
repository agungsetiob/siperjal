{{-- Email kabar usulan (desain "Email · Minta Revisi"). Gaya inline agar tampil di klien email. --}}
@php
    $u = $usulan;
    $bagian = collect($ekstra['bagian'] ?? [])->map(fn ($b) => ucfirst($b))->implode(', ');
    $cta = match ($jenis) {
        'revisi' => ['Perbaiki Usulan', route('usulan.ubah', $u)],
        'baru', 'diperiksa', 'disetujui', 'koreksi' => ['Buka Usulan', route('usulan', $u)],
        default => ['Lihat Status', route('lacak', $u->nomor)],
    };
    $judulBesar = match ($jenis) {
        'revisi' => 'Usulan Anda perlu diperbaiki',
        'terpasang' => 'Perlengkapan sudah terpasang',
        'disetujui' => 'Usulan disetujui Bappeda',
        'duplikat' => 'Usulan digabung',
        default => $judul,
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<body style="margin:0;padding:16px;background:#F4F5FA;font-family:Helvetica,Arial,sans-serif;color:#000100">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#FFFFFF;border-radius:6px;overflow:hidden">
    <tr><td style="background:#4556A9;padding:18px 20px">
        <div style="font-family:Impact,'Anton',sans-serif;font-size:16px;letter-spacing:.5px;color:#FFFFFF;text-transform:uppercase">SIPERJAL</div>
        <div style="font-family:Impact,'Anton',sans-serif;font-size:30px;line-height:32px;color:#FFFFFF;text-transform:uppercase;margin-top:10px">{{ $judulBesar }}</div>
    </td></tr>
    <tr><td style="padding:20px">
        <p style="font-size:15px;line-height:23px;color:#565B73;margin:0 0 14px">
            Halo {{ str($penerima->name)->before(' ') }},
            @if ($jenis === 'revisi')
                Dishub sudah melihat usulan Anda di {{ $u->alamat ?: $u->lokasiSingkat() }}. {{ $bagian ? 'Bagian berikut perlu diperbaiki dulu:' : 'Ada yang perlu diperbaiki dulu:' }}
            @else
                ada kabar untuk usulan <b style="color:#000100">{{ $u->nomor }}</b> ({{ $u->alamat ?: $u->lokasiSingkat() }}): {{ $judul }}.
            @endif
        </p>
        @if ($isi)
            <div style="background:{{ $jenis === 'revisi' ? '#FFF1C2' : '#F4F5FA' }};border-radius:6px;padding:14px;margin:0 0 14px">
                @if ($bagian)<div style="display:inline-block;background:#FFFFFF;border-radius:4px;padding:3px 8px;font-size:12px;font-weight:600;color:#8A5A00;margin-bottom:8px">{{ $bagian }}</div>@endif
                <div style="font-size:15px;line-height:22px;color:#000100">“{{ $isi }}”</div>
                @if (! empty($ekstra['oleh']))<div style="font-size:12px;color:#565B73;margin-top:8px">{{ $ekstra['oleh'] }}</div>@endif
            </div>
        @endif
        @if (! empty($ekstra['foto']))
            <img src="{{ $ekstra['foto'] }}" alt="" style="width:100%;height:auto;border-radius:6px;margin:0 0 14px">
        @endif
        <a href="{{ $cta[1] }}" style="display:block;text-align:center;background:#4556A9;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:15px;padding:12px 20px;border-radius:4px">{{ $cta[0] }} →</a>
        @if ($jenis === 'revisi')
            <p style="font-size:13px;line-height:19px;color:#565B73;margin:14px 0 0">Cukup perbaiki bagian yang diminta. Data lainnya tetap tersimpan.</p>
        @endif
        @php($panduan = \App\Support\Panduan::tautanKabar($jenis))
        @if ($panduan)
            <p style="font-size:13px;line-height:19px;color:#565B73;margin:10px 0 0">{{ $panduan['label'] }}{{ $panduan['durasi'] ? ' ('.$panduan['durasi'].')' : '' }}: <a href="{{ $panduan['url'] }}" style="color:#4556A9">{{ str($panduan['url'])->after('://') }}</a></p>
        @endif
    </td></tr>
    <tr><td style="background:#F4F5FA;padding:14px 20px 18px">
        <div style="font-size:12px;color:#4556A9">Lacak kapan saja: <a href="{{ route('lacak', $u->nomor) }}" style="color:#4556A9">{{ str(route('lacak', $u->nomor))->after('://') }}</a></div>
        <div style="font-size:12px;color:#565B73;margin-top:4px">Email ini dikirim karena Anda terkait dengan usulan perlengkapan jalan ini.</div>
    </td></tr>
</table>
</body>
</html>

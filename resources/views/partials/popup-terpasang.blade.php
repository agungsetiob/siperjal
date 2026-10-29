{{-- Popup peta beranda (AS5): satu titik = satu jenis di satu koordinat; unit di titik itu tampil sebagai daftar kode (ketuk → info & riwayat). --}}
@php
    $unit = $unit ?? collect([$item]);
    $banyak = $unit->count();
    $pertama = $unit->first();
    $rusak = $unit->first(fn ($i) => $i->laporanTerbuka);
    $laporanBisa = $unit->first(fn ($i) => ! $i->laporanTerbuka); // laporan dari titik ini masuk ke unit yang belum dilaporkan
@endphp
<div class="flex w-[236px] flex-col gap-2 font-sans">
    <div class="flex items-center gap-2">
        <span class="grid size-6 shrink-0 place-items-center rounded-full text-fg-inverse" style="background: {{ $pertama->jenis->warna }}">@svg('lucide-'.$pertama->jenis->ikon, 'size-[13px]')</span>
        <span class="flex min-w-0 flex-col">
            <span class="text-[15px] font-semibold leading-5 text-fg">{{ $pertama->jenis->namaPendek() }}</span>
            @if ($banyak > 1) <span class="font-caption text-xs text-fg-muted">{{ $banyak }} unit di titik ini</span>
            @elseif (! $pertama->jenis->per_unit) <span class="font-caption text-xs text-fg-muted">{{ number_format($pertama->jumlah, 0, ',', '.') }} {{ $pertama->jenis->satuan }}</span> @endif
        </span>
    </div>
    <div class="text-[13px] text-fg-muted">{{ $pertama->lokasi() }}@if ($pertama->waktuPasang()) · {{ $pertama->waktuPasang() }} @endif</div>
    @if ($banyak === 1 && ! $pertama->kode)
        <div class="flex items-center justify-between">
            @if ($rusak) <x-badge tone="perhatian">Dilaporkan rusak</x-badge> @else <x-badge tone="sukses">Terpasang</x-badge> @endif
        </div>
    @else
        <ul class="flex flex-col gap-1">
            @foreach ($unit->take(8) as $u)
                <li>
                    @if ($u->kode)
                        <a href="{{ route('aset', $u->kode) }}" class="flex items-center justify-between gap-2 rounded-sm px-2 py-1.5 font-caption text-[13px] font-semibold !text-fg outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted {{ $u->laporanTerbuka ? 'bg-warning-bg' : '' }}">
                            <span class="tracking-wide">{{ $u->kode }}</span>
                            <span class="flex items-center gap-1 text-xs {{ $u->laporanTerbuka ? 'text-warning' : 'text-success' }}"><span class="size-1.5 rounded-full bg-current"></span>{{ $u->laporanTerbuka ? 'Dilaporkan rusak' : 'Baik' }}</span>
                        </a>
                    @else
                        <span class="flex items-center justify-between gap-2 rounded-sm px-2 py-1.5 font-caption text-[13px] text-fg-muted outline outline-1 -outline-offset-1 outline-line"><span>Belum berkode</span><span class="text-xs">{{ $u->laporanTerbuka ? 'Dilaporkan rusak' : 'Baik' }}</span></span>
                    @endif
                </li>
            @endforeach
            @if ($banyak > 8) <li class="px-2 font-caption text-xs text-fg-muted">+{{ $banyak - 8 }} unit lagi</li> @endif
        </ul>
    @endif
    @if ($laporanBisa)
        <a href="{{ route('lapor.aset', $laporanBisa->id) }}" class="flex items-center gap-1.5 border-t border-line pt-2 font-caption text-[13px] font-semibold !text-accent">@svg('lucide-wrench', 'size-3.5') Laporkan kerusakan{{ $banyak > 1 ? ' di titik ini' : '' }}</a>
        @if ($banyak > 1) <span class="font-caption text-[11px] leading-4 text-fg-muted">Ketuk kode untuk info &amp; riwayat unit. Laporan dari titik ini masuk ke unit yang belum dilaporkan.</span> @endif
    @elseif ($rusak)
        <a href="{{ route('lapor.status', $rusak->laporanTerbuka->nomor) }}" class="flex items-center gap-1.5 border-t border-line pt-2 font-caption text-[13px] font-semibold !text-accent">{{ $rusak->laporanTerbuka->status->label() }} · lihat {{ $rusak->laporanTerbuka->nomor }} →</a>
    @endif
</div>

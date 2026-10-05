@php $rusak = $item->laporanTerbuka; @endphp
<div class="flex w-[222px] flex-col gap-2 font-sans">
    <div class="flex items-center gap-2">
        <span class="grid size-6 place-items-center rounded-full text-fg-inverse" style="background: {{ $item->jenis->warna }}">@svg('lucide-'.$item->jenis->ikon, 'size-[13px]')</span>
        <span class="text-[15px] font-semibold text-fg">{{ $item->jenis->namaPendek() }}</span>
    </div>
    <div class="text-[13px] text-fg-muted">{{ $item->usulan->alamat ?: $item->usulan->lokasiSingkat() }}</div>
    <div class="flex items-center justify-between">
        @if ($rusak) <x-badge tone="perhatian">Dilaporkan rusak</x-badge> @else <x-badge tone="sukses">Terpasang</x-badge> @endif
        <span class="font-caption text-xs text-fg-muted">{{ $item->terpasang_at->translatedFormat('j M Y') }}</span>
    </div>
    @if ($rusak)
        <a href="{{ route('lapor.status', $rusak->nomor) }}" class="flex items-center gap-1.5 border-t border-line pt-2 font-caption text-[13px] font-semibold !text-accent">{{ $rusak->status->label() }} · lihat {{ $rusak->nomor }} →</a>
    @else
        <a href="{{ route('lapor.item', $item->id) }}" class="flex items-center gap-1.5 border-t border-line pt-2 font-caption text-[13px] font-semibold !text-accent">@svg('lucide-wrench', 'size-3.5') Laporkan rusak</a>
    @endif
</div>

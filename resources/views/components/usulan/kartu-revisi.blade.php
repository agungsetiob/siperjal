{{-- Kartu "Perlu Revisi" yang disematkan di atas daftar pengusul. --}}
@props(['u'])
@php
    $r = $u->riwayat->firstWhere('aksi', 'Dishub meminta revisi');
    $oleh = $r?->user;
    $bagian = collect($u->revisi_bagian ?? []);
    $ikonBagian = ['foto' => 'camera', 'lokasi' => 'map-pin', 'kebutuhan' => 'package', 'proposal' => 'file-text'];
@endphp
<div class="flex shrink-0 flex-col gap-3 rounded-md bg-warning-bg p-4 shadow-sm outline outline-1 -outline-offset-1 outline-[#E3B23C]">
    <div class="flex items-center justify-between">
        <span class="flex items-center gap-1.5 text-warning">@svg('lucide-pin', 'size-3.5')
            <span class="flex items-center gap-1.5 rounded-sm bg-warning px-2.5 py-1 font-caption text-xs font-semibold text-fg-inverse"><span class="size-1.5 rounded-full bg-bg"></span> Perlu Revisi</span>
        </span>
        <span class="font-caption text-xs text-warning">{{ ($r?->created_at ?? $u->updated_at)->diffForHumans() }}</span>
    </div>
    <div class="flex flex-col gap-0.5">
        <span class="font-caption text-xs font-semibold text-warning">{{ $u->nomor }}</span>
        <span class="text-lg font-bold">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
        <span class="text-[13px] text-fg-muted">{{ $u->lokasiSingkat() }} · {{ $u->items->count() }} item</span>
    </div>
    <div class="flex flex-col gap-2 rounded-sm bg-bg p-3">
        <div class="flex items-center gap-2">
            <span class="grid size-6 place-items-center rounded-full bg-accent-deep font-caption text-[10px] font-bold text-fg-inverse">{{ $oleh ? str($oleh->name)->explode(' ')->take(2)->map(fn ($k) => $k[0])->implode('') : 'DS' }}</span>
            <span class="flex-1 font-caption text-xs font-semibold">{{ $oleh?->name ?? 'Petugas' }} · Dishub</span>
            @foreach ($bagian as $b)
                <span class="flex items-center gap-1 rounded-sm bg-warning-bg px-1.5 py-0.5 font-caption text-[11px] font-bold text-warning">@svg('lucide-'.($ikonBagian[$b] ?? 'pencil'), 'size-3') {{ ucfirst($b) }}</span>
            @endforeach
        </div>
        <p class="text-sm leading-5">“{{ $u->revisi_catatan }}”</p>
    </div>
    <div class="flex flex-col gap-2">
        <a href="{{ route('usulan.ubah', $u) }}" wire:navigate class="flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3.5 text-[15px] font-semibold text-fg-inverse shadow-sm">Perbaiki @svg('lucide-arrow-right', 'size-[18px]')</a>
        <span class="text-center font-caption text-xs text-warning">
            {{ $bagian->count() === 1 && $bagian->first() === 'foto' ? 'Cukup ganti foto · ± 2 menit' : 'Cukup perbaiki bagian yang ditandai' }}
        </span>
    </div>
</div>

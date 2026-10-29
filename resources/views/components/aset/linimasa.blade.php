{{-- Linimasa riwayat satu unit: catatan pemeliharaan (baru di atas) lalu peristiwa "Terpasang". --}}
@props(['aset', 'penanda' => null, 'rinci' => true])
@php
    $catatan = $aset->pemeliharaan;
    $asal = $aset->usulanItem?->usulan;
    $pasang = $aset->terpasang_at;
@endphp
<div class="flex flex-col">
    @foreach ($catatan as $p)
        @php $foto = $p->foto; @endphp
        <div class="flex gap-4" wire:key="cat-{{ $p->id }}">
            <div class="flex w-14 shrink-0 flex-col items-end pt-0.5 font-caption leading-[15px]">
                <span class="text-[13px] font-semibold">{{ $p->tanggal->translatedFormat('j M') }}</span>
                <span class="text-xs text-fg-muted">{{ $p->tanggal->format('Y') }}</span>
            </div>
            <div class="flex flex-col items-center">
                <span @class(['mt-1.5 size-2.5 shrink-0 rounded-full', 'bg-accent' => $loop->first, 'bg-[#C4C9D8]' => ! $loop->first])></span>
                <span class="w-px flex-1 bg-line"></span>
            </div>
            <div class="mb-5 flex min-w-0 flex-1 flex-col gap-1.5 pb-1">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span class="text-[15px] font-semibold">{{ $p->label() }}</span>
                    <span class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-caption text-[11px] font-semibold text-fg-muted">
                        {{ $p->dariLaporan() ? ($rinci && $p->laporan ? 'dari laporan '.$p->laporan->nomor : 'dari laporan warga') : 'catatan petugas' }}
                    </span>
                    @if ($rinci && $p->bolehDihapus(auth()->user()))
                        <button type="button" wire:click="hapusCatatan({{ $p->id }})" wire:confirm="Hapus catatan {{ $p->label() }} {{ $p->tanggal->translatedFormat('j M Y') }}?" class="ml-auto grid size-7 place-items-center rounded-sm text-fg-muted hover:bg-danger-bg hover:text-danger" aria-label="Hapus catatan">@svg('lucide-trash-2', 'size-3.5')</button>
                    @endif
                </div>
                @if ($p->catatan) <p class="text-sm leading-[21px] text-fg-muted">{{ $p->catatan }}</p> @endif
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-caption text-xs text-fg-muted">
                    @if ($rinci && $p->petugas) <span>{{ $p->petugas->name }} · {{ $p->petugas->isPetugasLapangan() ? 'Petugas lapangan' : 'Koordinator' }}</span> @endif
                    @if ($rinci && $foto->isNotEmpty())
                        <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\PemeliharaanFoto::galeri($foto)), mulai: 0 })" class="flex items-center gap-1 font-semibold text-accent">@svg('lucide-image', 'size-3.5') {{ $foto->count() }} foto{{ $p->dariLaporan() ? ' hasil' : '' }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
    <div class="flex gap-4">
        <div class="flex w-14 shrink-0 flex-col items-end pt-0.5 font-caption leading-[15px]">
            @if ($pasang)
                <span class="text-[13px] font-semibold">{{ $pasang->translatedFormat('j M') }}</span>
                <span class="text-xs text-fg-muted">{{ $pasang->format('Y') }}</span>
            @else
                <span class="text-[13px] font-semibold">{{ $aset->tahun ?? '—' }}</span>
            @endif
        </div>
        <div class="flex flex-col items-center"><span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $catatan->isEmpty() ? 'bg-accent' : 'bg-[#C4C9D8]' }}"></span></div>
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-[15px] font-semibold">Terpasang</span>
            <p class="text-sm leading-[21px] text-fg-muted">
                @if ($asal)
                    Dipasang dari usulan {{ $asal->nomor }}{{ $asal->instansi ? ' · '.$asal->instansi->nama : '' }}.
                @else
                    Terpasang sebelum SIPERJAL (data lama){{ $aset->tahun ? ', tahun '.$aset->tahun : '' }}.
                @endif
            </p>
            @if ($rinci && $penanda) <span class="font-caption text-xs text-fg-muted">Tandai terpasang oleh {{ $penanda }}</span> @endif
        </div>
    </div>
</div>

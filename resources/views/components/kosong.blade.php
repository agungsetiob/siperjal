{{-- Keadaan kosong/galat: ikon bulat + judul + teks + (opsional) satu aksi di slot. nada: proses | sukses | bahaya | netral --}}
@props(['ikon' => 'inbox', 'judul', 'teks' => null, 'nada' => 'proses'])
@php
    $warna = ['proses' => 'bg-accent-soft text-accent', 'sukses' => 'bg-success-bg text-success', 'bahaya' => 'bg-danger-bg text-danger', 'netral' => 'bg-surface-muted text-fg-muted'][$nada];
@endphp
<div {{ $attributes->class('flex flex-col items-center gap-3 px-6 py-12 text-center') }}>
    <span class="grid size-14 place-items-center rounded-full {{ $warna }}">@svg('lucide-'.$ikon, 'size-6')</span>
    <span class="text-base font-semibold">{{ $judul }}</span>
    @if ($teks) <span class="max-w-[300px] text-sm leading-5 text-fg-muted">{{ $teks }}</span> @endif
    @if ($slot->isNotEmpty()) <div class="mt-1">{{ $slot }}</div> @endif
</div>

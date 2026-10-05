@props(['jenis', 'ukuran' => 'size-5'])
<span {{ $attributes->class('inline-grid place-items-center rounded-sm bg-accent-soft text-accent p-1.5') }} title="{{ $jenis->nama }}">
    @svg('lucide-'.$jenis->ikon, $ukuran)
</span>

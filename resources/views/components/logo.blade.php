@props(['terang' => false, 'kecil' => false])
<a href="{{ url('/') }}" wire:navigate {{ $attributes->class('flex items-center gap-2.5') }}>
    <span @class(['grid place-items-center rounded-sm bg-accent text-fg-inverse', 'size-7' => $kecil, 'size-8' => ! $kecil])>@svg('lucide-traffic-cone', $kecil ? 'size-4' : 'size-[18px]')</span>
    <span @class(['judul whitespace-nowrap tracking-[0.5px]', 'text-fg-inverse' => $terang, 'text-lg' => $kecil, 'text-[22px]' => ! $kecil])>SIPERJAL</span>
</a>

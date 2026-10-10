{{-- Logo produk SIPERJAL (lambang Kabupaten Tanah Bumbu); sub-logo Kemenhub & BerAKSI ada di `x-logo-instansi`.
     `terang` = di latar gelap. --}}
@props(['terang' => false, 'kecil' => false])
<div {{ $attributes->class('flex shrink-0 items-center gap-4') }}>
    <a href="{{ url('/') }}" wire:navigate class="flex items-center gap-2.5">
        <img src="{{ asset('img/logo/tanah-bumbu.webp') }}" width="156" height="192" alt="Kabupaten Tanah Bumbu" @class(['w-auto', 'h-7' => $kecil, 'h-9' => ! $kecil])>
        <span @class(['judul whitespace-nowrap tracking-[0.5px]', 'text-fg-inverse' => $terang, 'text-lg' => $kecil, 'text-[22px]' => ! $kecil])>SIPERJAL</span>
    </a>
</div>

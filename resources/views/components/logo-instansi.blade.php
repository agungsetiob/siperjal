{{-- Sub-logo instansi pengelola: Kementerian Perhubungan & Tanah Bumbu BerAKSI (tanpa lambang daerah, sudah jadi logo utama). `terang` = di latar gelap (diberi alas putih). --}}
@props(['terang' => false, 'besar' => false])
<span {{ $attributes->class(['flex w-fit shrink-0 items-center', 'gap-2.5 rounded-sm bg-bg px-2.5 py-1.5' => $terang, 'gap-2' => ! $terang]) }}>
    <img src="{{ asset('img/logo/kemenhub.webp') }}" width="165" height="192" alt="Kementerian Perhubungan" title="Kementerian Perhubungan" @class(['w-auto', 'h-9' => $besar, 'h-7' => ! $besar])>
    <img src="{{ asset('img/logo/beraksi-saja.webp') }}" width="619" height="192" alt="Tanah Bumbu BerAKSI" title="Tanah Bumbu BerAKSI" @class(['w-auto', 'h-8' => $besar, 'h-6' => ! $besar])>
</span>

{{-- MD · Keterangan Penanda: nomor kuning + teks; sorot penanda di gambar saat disentuh --}}
@props(['daftar'])
<ol class="flex flex-col gap-3">
    @foreach ($daftar as $i => $p)
        <li class="flex gap-3" @mouseenter="aktif = {{ $i + 1 }}" @mouseleave="aktif = 0">
            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-secondary text-xs font-bold text-on-secondary">{{ $i + 1 }}</span>
            <span class="text-[15px] leading-normal">{{ $p['teks'] }}</span>
        </li>
    @endforeach
</ol>

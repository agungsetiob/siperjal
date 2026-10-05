{{-- Screenshot berbingkai + penanda bernomor (posisi dalam persen). HP = bingkai ponsel, layar lebar = selebar kolom. --}}
@props(['g'])
@php $lebar = $g['lebarLayar']; @endphp
<figure @class(['flex flex-col gap-2.5', 'w-full' => $lebar, 'mx-auto w-[250px] max-w-full lg:mx-0 lg:w-[280px]' => ! $lebar])>
    <div @class(['relative overflow-hidden border border-line', 'rounded-md' => $lebar, 'rounded-[21px] lg:rounded-[23px]' => ! $lebar])>
        <img src="{{ $g['url'] }}" alt="{{ $g['ket'] }}" @if ($g['lebar']) width="{{ $g['lebar'] }}" height="{{ $g['tinggi'] }}" @endif loading="lazy" class="block h-auto w-full">
        @foreach ($g['penanda'] as $i => $p)
            <span aria-hidden="true" style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%"
                  :class="aktif === {{ $i + 1 }} ? 'z-10 scale-125' : ''"
                  class="absolute grid size-7 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border-2 border-bg bg-secondary text-[13px] font-bold text-on-secondary shadow-[0_2px_6px_#00010040] transition-transform">{{ $i + 1 }}</span>
        @endforeach
    </div>
    @if ($g['ket'])
        <figcaption class="text-center font-caption text-xs text-fg-muted">{{ $g['ket'] }}</figcaption>
    @endif
</figure>

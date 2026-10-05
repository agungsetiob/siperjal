{{-- Satu blok isi artikel. $x = blok, $utama = gambar HP yang tampil di kolom kiri (desktop). --}}
@switch($x['t'])
    @case('p')
        <p class="md-isi text-base leading-[1.6] lg:text-[17px] lg:leading-[1.65]">{!! $x['html'] !!}</p>
        @break

    @case('daftar')
        <ul class="flex flex-col gap-3">
            @foreach ($x['butir'] as $b)
                <x-panduan.butir>{!! $b['html'] !!}</x-panduan.butir>
            @endforeach
        </ul>
        @break

    @case('callout')
        <x-panduan.callout :tone="$x['tone']" :label="$x['label']">
            @foreach ($x['paragraf'] as $p)<p>{!! $p !!}</p>@endforeach
        </x-panduan.callout>
        @break

    @case('gambar')
        @if ($utama ?? false)
            {{-- Desktop: gambar di kolom kiri, hanya keterangannya di sini. HP: gambar + keterangan di tempat. --}}
            <div class="flex flex-col gap-5 lg:hidden">
                <x-panduan.gambar :g="$x" />
                @if ($x['penanda'])<x-panduan.penanda :daftar="$x['penanda']" />@endif
            </div>
            @if ($x['penanda'])<div class="hidden lg:block"><x-panduan.penanda :daftar="$x['penanda']" /></div>@endif
        @else
            <div class="flex flex-col gap-5">
                <x-panduan.gambar :g="$x" />
                @if ($x['penanda'])<x-panduan.penanda :daftar="$x['penanda']" />@endif
            </div>
        @endif
        @break

    @case('kode')
        <pre class="overflow-x-auto rounded-md bg-surface-muted px-4 py-3 font-mono text-sm leading-relaxed">{{ $x['teks'] }}</pre>
        @break

    @case('h3')
        <h3 class="text-lg font-semibold">{!! $x['html'] !!}</h3>
        @break
@endswitch

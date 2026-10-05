{{-- Stepper laporan kerusakan, 4 langkah: Dilaporkan → Dicek petugas → Menunggu perbaikan → Selesai diperbaiki.
     gaya: datar (desktop) | tegak (detail HP, dengan keterangan) | mini (kartu) --}}
@props(['laporan', 'gaya' => 'datar'])
@php
    $langkah = $laporan->langkah();
    $selesaiSemua = $laporan->status === \App\Enums\StatusLaporan::Selesai;
@endphp
@if ($gaya === 'mini')
    <div {{ $attributes->class('flex w-full gap-1') }} title="{{ $laporan->status->label() }}">
        @foreach ($langkah as $l)
            <span @class([
                'h-1 flex-1 rounded-[2px]',
                'bg-success' => $selesaiSemua,
                'bg-accent' => ! $selesaiSemua && $l['keadaan'] === 'selesai',
                'bg-accent/40' => $l['keadaan'] === 'aktif',
                'bg-danger' => $l['keadaan'] === 'gagal',
                'bg-line' => $l['keadaan'] === 'nanti',
            ])></span>
        @endforeach
    </div>
@elseif ($gaya === 'tegak')
    <ol {{ $attributes->class('flex flex-col') }}>
        @foreach ($langkah as $n => $l)
            @php $k = $l['keadaan']; @endphp
            <li class="flex gap-3">
                <span class="flex w-7 shrink-0 flex-col items-center">
                    <span @class([
                        'grid size-7 shrink-0 place-items-center rounded-full font-caption text-[13px] font-bold',
                        'bg-accent text-fg-inverse' => $k === 'selesai' && ! $selesaiSemua,
                        'bg-success text-fg-inverse' => $k === 'selesai' && $selesaiSemua,
                        'bg-bg text-accent outline outline-2 -outline-offset-2 outline-accent' => $k === 'aktif',
                        'bg-danger text-fg-inverse' => $k === 'gagal',
                        'bg-surface-muted text-fg-muted' => $k === 'nanti',
                    ])>
                        @if ($k === 'selesai') @svg('lucide-check', 'size-4') @elseif ($k === 'gagal') @svg('lucide-x', 'size-4') @else {{ $n + 1 }} @endif
                    </span>
                    @if (! $loop->last) <span @class(['my-1 w-0.5 flex-1 rounded-full', 'bg-accent' => $k === 'selesai', 'bg-line' => $k !== 'selesai'])></span> @endif
                </span>
                <span @class(['flex min-w-0 flex-1 flex-col gap-0.5 pt-1', 'pb-5' => ! $loop->last])>
                    <span @class(['text-base font-semibold', 'text-fg-muted' => $k === 'nanti', 'text-danger' => $k === 'gagal'])>{{ $l['label'] }}</span>
                    @if ($l['ket']) <span class="text-sm text-fg-muted">{{ $l['ket'] }}</span> @endif
                </span>
            </li>
        @endforeach
    </ol>
@else
    <ol {{ $attributes->class('grid grid-cols-4') }}>
        @foreach ($langkah as $n => $l)
            @php $k = $l['keadaan']; @endphp
            <li class="flex min-w-0 flex-col gap-1.5" title="{{ $l['ket'] }}">
                <span class="flex items-center gap-1.5">
                    <span @class([
                        'grid size-6 shrink-0 place-items-center rounded-full font-caption text-xs font-bold',
                        'bg-accent text-fg-inverse' => $k === 'selesai' && ! $selesaiSemua,
                        'bg-success text-fg-inverse' => $k === 'selesai' && $selesaiSemua,
                        'bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-accent' => $k === 'aktif',
                        'bg-danger text-fg-inverse' => $k === 'gagal',
                        'bg-surface-muted text-fg-muted' => $k === 'nanti',
                    ])>
                        @if ($k === 'selesai') @svg('lucide-check', 'size-3.5') @elseif ($k === 'gagal') @svg('lucide-x', 'size-3.5') @else {{ $n + 1 }} @endif
                    </span>
                    @if (! $loop->last) <span @class(['mr-1.5 h-0.5 flex-1 rounded-full', 'bg-accent' => $k === 'selesai', 'bg-line' => $k !== 'selesai'])></span> @endif
                </span>
                <span @class(['pr-2 font-caption text-[13px] leading-[17px]', 'font-semibold' => $k !== 'nanti', 'text-fg-muted' => $k === 'nanti', 'text-danger' => $k === 'gagal'])>{{ $l['label'] }}</span>
            </li>
        @endforeach
    </ol>
@endif

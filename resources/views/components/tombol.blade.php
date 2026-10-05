@props(['variant' => 'utama', 'href' => null, 'ikon' => null, 'ikonKanan' => null, 'besar' => false, 'navigate' => true])
@php
    $gaya = [
        'utama' => 'bg-accent text-fg-inverse shadow-sm hover:brightness-110',
        'kedua' => 'border border-line bg-bg text-fg hover:bg-surface-muted',
        'teks' => 'text-fg-muted hover:text-fg hover:bg-surface-muted',
        'bahaya' => 'bg-danger text-fg-inverse hover:brightness-110',
    ][$variant];
    $ukuran = $besar ? 'px-5 py-3.5 text-base' : 'px-4 py-2.5 text-[15px]';
    $kelas = "inline-flex items-center justify-center gap-2 rounded-sm font-semibold transition disabled:opacity-50 disabled:pointer-events-none $ukuran $gaya";
@endphp
@if ($href)
    <a href="{{ $href }}" @if ($navigate) wire:navigate @endif {{ $attributes->class($kelas) }}>
        @if ($ikon) @svg('lucide-'.$ikon, 'size-[18px]') @endif
        {{ $slot }}
        @if ($ikonKanan) @svg('lucide-'.$ikonKanan, 'size-[18px]') @endif
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($kelas) }}>
        @if ($ikon) @svg('lucide-'.$ikon, 'size-[18px]') @endif
        {{ $slot }}
        @if ($ikonKanan) @svg('lucide-'.$ikonKanan, 'size-[18px]') @endif
    </button>
@endif

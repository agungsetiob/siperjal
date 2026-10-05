{{-- MD · Callout: > [!TIP] hijau, > [!NOTE] biru, > [!WARNING] kuning --}}
@props(['tone' => 'info', 'label' => 'INFO'])
@php
    $gaya = [
        'tip' => ['bg-success-bg border-success', 'text-success', 'lightbulb'],
        'info' => ['bg-accent-soft border-accent', 'text-accent', 'info'],
        'warning' => ['bg-warning-bg border-warning', 'text-warning', 'triangle-alert'],
    ][$tone] ?? ['bg-accent-soft border-accent', 'text-accent', 'info'];
@endphp
<div role="note" class="flex gap-3 rounded-md border-l-[3px] px-4 py-3.5 {{ $gaya[0] }}">
    @svg('lucide-'.$gaya[2], 'mt-px size-5 shrink-0 '.$gaya[1])
    <div class="flex min-w-0 flex-1 flex-col gap-1">
        <span class="font-caption text-xs font-bold tracking-[1.2px] {{ $gaya[1] }}">{{ $label }}</span>
        <div class="md-isi flex flex-col gap-2 text-[15px] leading-[1.55]">{{ $slot }}</div>
    </div>
</div>

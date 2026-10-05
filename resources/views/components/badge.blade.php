@props(['tone' => 'netral', 'dot' => true])
@php
    $warna = [
        'netral' => 'bg-surface-muted text-fg-muted',
        'proses' => 'bg-accent-soft text-accent',
        'perhatian' => 'bg-warning-bg text-warning',
        'bahaya' => 'bg-danger-bg text-danger',
        'sukses' => 'bg-success-bg text-success',
    ][$tone];
@endphp
<span {{ $attributes->class("inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 font-caption text-xs font-semibold whitespace-nowrap $warna") }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current"></span>@endif
    {{ $slot }}
</span>

@props(['status'])
<x-badge :tone="$status->tone()" {{ $attributes }}>{{ $status->label() }}</x-badge>

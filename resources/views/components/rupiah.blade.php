@props(['nilai'])
<span {{ $attributes->class('tabular-nums') }}>Rp {{ number_format($nilai, 0, ',', '.') }}</span>

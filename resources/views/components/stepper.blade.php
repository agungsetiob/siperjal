{{-- Diajukan → Dishub → Bappeda → Terpasang --}}
@props(['usulan', 'mini' => false])
@php
    $status = $usulan->status;
    $tahap = $status->tahap();
    $gagal = $status === \App\Enums\StatusUsulan::Ditolak;
    $langkah = ['Diajukan', 'Dishub', 'Bappeda', 'Terpasang'];
@endphp
@if ($mini)
    <div class="flex items-center gap-1" title="{{ $status->label() }}">
        @foreach ($langkah as $i => $nama)
            @php $n = $i + 1; @endphp
            <span @class([
                'h-1.5 w-6 rounded-full',
                'bg-danger' => $gagal && $n === $tahap,
                'bg-accent' => ! ($gagal && $n === $tahap) && $n < $tahap,
                'bg-accent/40' => ! $gagal && $n === $tahap,
                'bg-line' => $n > $tahap,
            ])></span>
        @endforeach
    </div>
@else
    <ol class="grid grid-cols-4 gap-2">
        @foreach ($langkah as $i => $nama)
            @php $n = $i + 1; $selesai = $n < $tahap; $aktif = $n === $tahap; @endphp
            <li class="flex flex-col gap-2">
                <span @class([
                    'h-1.5 rounded-full',
                    'bg-accent' => $selesai,
                    'bg-danger' => $aktif && $gagal,
                    'bg-accent/40 animate-pulse' => $aktif && ! $gagal,
                    'bg-line' => $n > $tahap,
                ])></span>
                <span @class(['font-caption text-xs font-semibold', 'text-fg' => $selesai || $aktif, 'text-fg-muted' => ! $selesai && ! $aktif])>
                    @if ($selesai) ✓ @endif {{ $nama }}
                </span>
            </li>
        @endforeach
    </ol>
@endif

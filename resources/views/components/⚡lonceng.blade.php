<?php

use App\Enums\Role;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $belumSaja = false;

    #[Computed]
    public function notifikasi()
    {
        return auth()->user()->notifications()
            ->when($this->belumSaja, fn ($q) => $q->whereNull('read_at'))
            ->latest()->limit(20)->get();
    }

    #[Computed]
    public function jumlahBelum(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /** Tujuan & label aksi per jenis notifikasi. */
    public function tujuan(array $data): array
    {
        $petugas = ! auth()->user()->isPengusul();

        if (isset($data['tautan'])) {
            return [$data['aksi'] ?? 'Lihat', $data['tautan']];
        }

        if (isset($data['laporan_id'])) {
            return [$data['jenis'] === 'kerusakan_tugas' && $petugas ? 'Cek lokasi' : 'Lihat laporan', route('pemeliharaan', $data['laporan_id'])];
        }

        return match ($data['jenis'] ?? '') {
            'baru' => ['Periksa', route('usulan', $data['usulan_id'])],
            'revisi_ulang' => ['Buka', route('usulan', $data['usulan_id'])],
            'disetujui' => $petugas && auth()->user()->hasRole(Role::Dishub)
                ? ['Lihat di Siap Dipasang', route('usulan', ['tab' => 'pasang'])]
                : ['Lihat', route('usulan', $data['usulan_id'])],
            'batas' => ['Lihat', route('usulan', ['tab' => 'periksa'])],
            'revisi' => ['Perbaiki', route('usulan.ubah', $data['usulan_id'])],
            'koreksi' => [null, route('usulan', $data['usulan_id'])],
            default => ['Lihat', route('usulan', $data['usulan_id'])],
        };
    }

    public function buka(string $id)
    {
        $n = auth()->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return $this->redirect($this->tujuan($n->data)[1], navigate: true);
    }

    public function tandaiSemua(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->notifikasi, $this->jumlahBelum);
    }
};
?>

@php
    $gaya = [
        'baru' => ['inbox', 'bg-accent-soft text-accent'],
        'minta_jenis' => ['package-plus', 'bg-accent-soft text-accent'],
        'revisi_ulang' => ['rotate-ccw', 'bg-warning-bg text-warning'],
        'revisi' => ['triangle-alert', 'bg-warning-bg text-warning'],
        'disetujui' => ['badge-check', 'bg-success-bg text-success'],
        'terpasang' => ['map-pin-check', 'bg-success-bg text-success'],
        'batas' => ['hourglass', 'bg-danger-bg text-danger'],
        'ditolak' => ['circle-x', 'bg-danger-bg text-danger'],
        'ditunda' => ['hourglass', 'bg-warning-bg text-warning'],
        'diperiksa' => ['clipboard-check', 'bg-accent-soft text-accent'],
        'duplikat' => ['copy', 'bg-surface-muted text-fg-muted'],
        'koreksi' => ['pencil-line', 'bg-surface-muted text-fg-muted'],
    ] + \App\Support\Kabar::GAYA_KERUSAKAN;
    $kelompok = $this->notifikasi->groupBy(fn ($n) => $n->created_at->isToday() ? 'Hari ini' : ($n->created_at->isYesterday() ? 'Kemarin' : 'Sebelumnya'));
@endphp

<div x-data="{ buka: false }" class="relative" wire:poll.30s @keydown.escape.window="buka = false">
    <button @click="buka = !buka" :class="buka ? 'bg-accent-soft text-accent' : 'hover:bg-surface-muted'" class="relative grid size-10 place-items-center rounded-sm" aria-label="Notifikasi">
        @svg('lucide-bell', 'size-5')
        @if ($this->jumlahBelum) <span class="absolute left-6 top-[9px] size-2 rounded-full bg-danger"></span> @endif
    </button>

    <div x-show="buka" x-cloak x-transition.opacity.duration.150ms @click="buka = false" class="fixed inset-x-0 bottom-0 top-16 z-[1090] bg-fg/[.08]"></div>

    <div x-show="buka" x-cloak x-transition.opacity.duration.150ms
         class="fixed inset-x-2 top-16 z-[1100] flex max-h-[calc(100dvh-80px)] flex-col rounded-md bg-bg shadow-xl outline outline-1 -outline-offset-1 outline-line sm:absolute sm:inset-x-auto sm:right-0 sm:top-[calc(100%-4px)] sm:w-[420px]">
        <div class="flex flex-col gap-3 px-5 pb-3 pt-[18px]">
            <div class="flex items-center justify-between">
                <span class="judul text-2xl">Notifikasi</span>
                @if ($this->jumlahBelum) <button wire:click="tandaiSemua" class="font-caption text-[13px] font-semibold text-accent">Tandai semua dibaca</button> @endif
            </div>
            <div class="flex gap-2 font-caption text-[13px] font-semibold">
                <button wire:click="$set('belumSaja', false)" @class(['rounded-full px-3 py-1.5', 'bg-fg text-fg-inverse' => ! $belumSaja, 'bg-surface-muted text-fg-muted' => $belumSaja])>Semua</button>
                <button wire:click="$set('belumSaja', true)" @class(['rounded-full px-3 py-1.5', 'bg-fg text-fg-inverse' => $belumSaja, 'bg-surface-muted text-fg-muted' => ! $belumSaja])>Belum dibaca · {{ $this->jumlahBelum }}</button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse ($kelompok as $judul => $daftar)
                <div class="px-5 pb-1.5 pt-2.5 font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-fg-muted">{{ $judul }}</div>
                @foreach ($daftar as $n)
                    @php
                        [$ikon, $warna] = $gaya[$n->data['jenis'] ?? ''] ?? ['bell', 'bg-surface-muted text-fg-muted'];
                        [$aksi] = $this->tujuan($n->data);
                        $baru = ! $n->read_at;
                    @endphp
                    <button wire:click="buka('{{ $n->id }}')" wire:key="lc-{{ $n->id }}" @class(['flex w-full gap-3 px-5 py-3 text-left hover:bg-surface-muted', 'bg-accent/[.04]' => $baru])>
                        <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $warna }}">@svg('lucide-'.$ikon, 'size-[17px]')</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                            <span class="text-sm font-semibold">{{ $n->data['judul'] }}</span>
                            <span class="text-[13px] leading-[18px] text-fg-muted">{{ str($n->data['isi'] ?? '')->contains($n->data['nomor']) ? str($n->data['isi'])->limit(110) : collect([$n->data['nomor'], $n->data['isi'] ?? null])->filter()->implode(' · ') }}</span>
                            <span class="flex items-center gap-2.5 pt-[3px] font-caption text-xs">
                                <span class="text-fg-muted">{{ $n->created_at->isToday() ? $n->created_at->diffForHumans() : ($n->created_at->isYesterday() ? 'Kemarin, '.$n->created_at->format('H.i') : $n->created_at->translatedFormat('j M, H.i')) }}</span>
                                @if ($aksi) <span class="font-semibold text-accent">{{ $aksi }}</span> @endif
                            </span>
                        </span>
                        <span class="w-2 pt-1.5">@if ($baru) <span class="block size-2 rounded-full bg-accent"></span> @endif</span>
                    </button>
                @endforeach
            @empty
                <div class="p-8 text-center text-sm text-fg-muted">{{ $belumSaja ? 'Semua sudah dibaca ✓' : 'Belum ada notifikasi.' }}</div>
            @endforelse
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-line px-5 py-3.5">
            <span class="flex items-center gap-1.5 font-caption text-xs text-fg-muted">@svg('lucide-mail', 'size-3.5') Juga dikirim ke email</span>
            <a href="{{ route('notifikasi') }}" wire:navigate class="font-caption text-[13px] font-semibold text-accent">Lihat semua</a>
        </div>
    </div>
</div>

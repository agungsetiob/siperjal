<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Computed]
    public function kelompok()
    {
        return auth()->user()->notifications()->latest()->limit(60)->get()
            ->groupBy(fn ($n) => match (true) {
                $n->created_at->isToday() => 'Hari ini',
                $n->created_at->gte(now()->subDays(7)) => 'Minggu ini',
                default => 'Sebelumnya',
            });
    }

    public function buka(string $id, bool $perbaiki = false)
    {
        $n = auth()->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        if (isset($n->data['laporan_id'])) {
            return $this->redirectRoute('pemeliharaan', $n->data['laporan_id'], navigate: true);
        }

        return $perbaiki
            ? $this->redirectRoute('usulan.ubah', $n->data['usulan_id'], navigate: true)
            : $this->redirectRoute('usulan', $n->data['usulan_id'], navigate: true);
    }

    public function tandaiSemua(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->kelompok);
    }

    public function render()
    {
        return $this->view()->title('Notifikasi · SIPERJAL');
    }
};
?>

@php
    $gaya = [
        'revisi' => ['triangle-alert', 'bg-warning-bg text-warning'],
        'disetujui' => ['badge-check', 'bg-success-bg text-success'],
        'terpasang' => ['map-pin-check', 'bg-success-bg text-success'],
        'duplikat' => ['copy', 'bg-surface-muted text-fg-muted'],
        'ditolak' => ['circle-x', 'bg-danger-bg text-danger'],
        'ditunda' => ['hourglass', 'bg-warning-bg text-warning'],
        'diperiksa' => ['clipboard-check', 'bg-accent-soft text-accent'],
        'baru' => ['inbox', 'bg-accent-soft text-accent'],
        'koreksi' => ['pencil-line', 'bg-surface-muted text-fg-muted'],
        'revisi_ulang' => ['rotate-ccw', 'bg-warning-bg text-warning'],
        'batas' => ['hourglass', 'bg-danger-bg text-danger'],
    ] + \App\Support\Kabar::GAYA_KERUSAKAN;
@endphp

<div class="mx-auto min-h-[calc(100dvh-4rem)] max-w-2xl bg-bg lg:my-6 lg:min-h-0 lg:rounded-md lg:outline lg:outline-1 lg:outline-line">
    <div class="flex items-end justify-between px-4 pb-3 pt-2 lg:px-6 lg:pt-6">
        <h1 class="judul text-[36px] leading-9">Notifikasi</h1>
        @if (auth()->user()->unreadNotifications()->exists())
            <button wire:click="tandaiSemua" class="pb-1 pt-3 font-caption text-sm font-semibold text-accent">Tandai dibaca</button>
        @endif
    </div>
    <div class="px-4 pb-2 lg:px-6">
        <div class="flex items-center gap-2.5 rounded-md bg-surface-tint px-3 py-2.5 text-sm text-accent-deep">
            @svg('lucide-mail', 'size-[18px] shrink-0')
            <span class="flex-1">Kabar juga dikirim ke email {{ str(auth()->user()->email)->mask('•', 3, max(0, strpos(auth()->user()->email, '@') - 3)) }}</span>
        </div>
    </div>

    @forelse ($this->kelompok as $judul => $daftar)
        <div class="px-4 pb-1 pt-3 font-caption text-xs font-semibold uppercase tracking-[0.6px] text-fg-muted lg:px-6">{{ $judul }}</div>
        @foreach ($daftar as $n)
            @php
                [$ikon, $warna] = $gaya[$n->data['jenis'] ?? ''] ?? ['bell', 'bg-surface-muted text-fg-muted'];
                $baru = ! $n->read_at;
                $revisi = ($n->data['jenis'] ?? '') === 'revisi' && auth()->user()->isPengusul()
                    && \App\Models\Usulan::whereKey($n->data['usulan_id'])->where('status', \App\Enums\StatusUsulan::Revisi)->exists();
            @endphp
            <div wire:key="n-{{ $n->id }}" @class(['flex gap-3 border-b border-line px-4 py-3.5 lg:px-6', 'bg-accent/[.04]' => $baru])>
                <span class="grid size-10 shrink-0 place-items-center rounded-full {{ $warna }}">@svg('lucide-'.$ikon, 'size-[19px]')</span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <button wire:click="buka('{{ $n->id }}')" class="flex flex-col gap-1 text-left">
                        <span class="flex w-full items-center gap-2">
                            <span class="flex-1 text-base font-semibold">{{ $n->data['judul'] }}</span>
                            @if ($baru) <span class="size-2 shrink-0 rounded-full bg-accent"></span> @endif
                        </span>
                        <span class="text-sm leading-5 text-fg-muted">{{ str($n->data['isi'] ?? '')->contains($n->data['nomor']) ? str($n->data['isi']) : collect([$n->data['nomor'], $n->data['isi'] ?? null])->filter()->implode(' · ') }}</span>
                        @if (! empty($n->data['foto']))
                            <img src="{{ $n->data['foto'] }}" class="mt-1 h-[110px] w-full rounded-md object-cover" alt="">
                        @endif
                    </button>
                    @if ($revisi)
                        <button wire:click="buka('{{ $n->id }}', true)" class="mt-1.5 flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Perbaiki @svg('lucide-arrow-right', 'size-[18px]')</button>
                    @endif
                    <span class="font-caption text-xs text-fg-muted">{{ $n->created_at->isToday() ? $n->created_at->diffForHumans() : $n->created_at->translatedFormat('l, H.i') }}</span>
                </div>
            </div>
        @endforeach
    @empty
        <div class="flex flex-col items-center gap-3 px-6 py-16 text-center text-fg-muted">@svg('lucide-bell-off', 'size-10') Belum ada notifikasi.</div>
    @endforelse

    <a href="{{ route('profil') }}" wire:navigate class="flex items-center gap-3 border-t border-line px-4 py-5 hover:bg-surface-muted lg:hidden">
        <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-full bg-accent-deep font-caption text-sm font-semibold text-fg-inverse">
            @if (auth()->user()->fotoUrl()) <img src="{{ auth()->user()->fotoUrl() }}" class="size-full object-cover" alt=""> @else {{ auth()->user()->inisial() }} @endif
        </span>
        <span class="min-w-0 flex-1 text-sm"><span class="block font-semibold">{{ auth()->user()->name }}</span><span class="block truncate text-fg-muted">Profil & pengaturan kabar</span></span>
        @svg('lucide-chevron-right', 'size-5 text-fg-muted')
    </a>
</div>

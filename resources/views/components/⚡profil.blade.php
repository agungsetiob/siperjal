<?php

use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Profil pengguna: dipakai sebagai modal (desktop, dari bar atas) dan halaman /profil (HP).
 * Setiap perubahan langsung disimpan.
 */
new class extends Component
{
    use WithFileUploads;

    public bool $halaman = false;

    public string $nama = '';
    public string $hp = '';          // tanpa awalan 0 / 62, mis. "812-2045-7781"
    public bool $notifEmail = true;
    public bool $notifWa = true;
    public $foto;
    public bool $tersimpan = false;

    public function mount(): void
    {
        $u = $this->user;
        $this->nama = $u->name;
        $this->hp = $this->formatHp($u->no_hp);
        $this->notifEmail = $u->notif_email;
        $this->notifWa = $u->notif_wa;
    }

    #[Computed]
    public function user()
    {
        return auth()->user();
    }

    private function formatHp(?string $no): string
    {
        $d = preg_replace('/^(62|0)/', '', preg_replace('/\D/', '', (string) $no));

        return trim(implode('-', array_filter([substr($d, 0, 3), substr($d, 3, 4), substr($d, 7)])), '-');
    }

    public function updatedNama(): void
    {
        $this->validateOnly('nama', ['nama' => 'required|string|min:2|max:100'], [], ['nama' => 'nama']);
        $this->simpan(['name' => trim($this->nama)]);
    }

    public function updatedHp(): void
    {
        $d = preg_replace('/^(62|0)/', '', preg_replace('/\D/', '', $this->hp));
        if ($d === '') {
            $this->simpan(['no_hp' => null]);

            return;
        }
        if (strlen($d) < 8 || strlen($d) > 13 || $d[0] !== '8') {
            $this->addError('hp', 'Nomor HP tidak valid, mis. 812-3456-7890.');

            return;
        }
        $this->hp = $this->formatHp($d);
        $this->simpan(['no_hp' => '0'.$d]);
    }

    public function updatedNotifEmail(): void
    {
        $this->simpan(['notif_email' => $this->notifEmail]);
    }

    public function updatedNotifWa(): void
    {
        abort_unless(config('usuljalan.whatsapp_aktif'), 403);
        $this->simpan(['notif_wa' => $this->notifWa]);
    }

    public function updatedFoto(): void
    {
        $this->validateOnly('foto', ['foto' => 'image|max:4096'], ['foto.image' => 'Pilih file gambar.', 'foto.max' => 'Ukuran foto maksimal 4 MB.']);
        $lama = $this->user->foto_path;
        $this->simpan(['foto_path' => $this->foto->store('profil', 'public')]);
        if ($lama) {
            Storage::disk('public')->delete($lama);
        }
        $this->reset('foto');
    }

    private function simpan(array $data): void
    {
        $this->resetErrorBag();
        $this->user->update($data);
        unset($this->user);
        $this->tersimpan = true;
        $this->dispatch('profil-berubah', nama: $this->user->name, inisial: $this->user->inisial(), foto: $this->user->fotoUrl());
    }
};
?>

@php
    $u = $this->user;
    $petugas = ! $u->isPengusul();
    $waAktif = config('usuljalan.whatsapp_aktif');
    $keteranganEmail = match ($u->role) {
        \App\Enums\Role::Bappeda => 'Usulan siap diputuskan & ringkasan mingguan',
        \App\Enums\Role::Dishub => 'Usulan baru masuk & pengingat batas periksa',
        \App\Enums\Role::Admin => 'Ringkasan aktivitas mingguan',
        default => 'Kabar status usulan',
    };
    $chips = $petugas
        ? [['shield-check', collect([$u->role->label(), $u->jabatan])->filter()->implode(' · ')], ['building-2', $u->instansi?->nama ?? $u->role->label().' '.config('usuljalan.pemda')]]
        : array_filter([['user-round', 'Pengusul · '.$u->jenisPengusul()], $u->instansi ? ['building-2', $u->instansi->nama] : null]);
    $label = 'text-sm font-semibold';
@endphp

<div class="flex flex-col">
    @if ($halaman)
        <div class="flex items-center gap-3 px-4 pb-3 pt-3">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('usulan') }}" wire:navigate class="grid size-9 place-items-center rounded-sm hover:bg-surface-muted" aria-label="Kembali">@svg('lucide-chevron-left', 'size-6')</a>
            <h1 class="judul flex-1 text-[32px] leading-8">Profil</h1>
            @if ($tersimpan) <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-1 font-caption text-xs font-semibold text-success">Tersimpan @svg('lucide-check', 'size-3.5')</span> @endif
        </div>
    @else
        <div class="flex items-center gap-3 border-b border-line px-6 pb-4 pt-5">
            <h2 class="judul flex-1 text-[28px] leading-7">Profil saya</h2>
            @if ($tersimpan) <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-1 font-caption text-xs font-semibold text-success">Tersimpan @svg('lucide-check', 'size-3.5')</span> @endif
            <button type="button" @click="$dispatch('tutup-profil')" class="grid size-8 place-items-center rounded-sm text-fg-muted hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
        </div>
    @endif

    <div @class(['flex flex-col gap-4', 'px-4 pb-4' => $halaman, 'px-6 py-5' => ! $halaman])>
        <div class="flex items-center gap-4">
            <span @class(['grid shrink-0 place-items-center overflow-hidden rounded-full', 'size-14 bg-surface-soft text-fg' => $halaman, 'size-16 bg-accent text-fg-inverse' => ! $halaman])>
                @if ($u->fotoUrl()) <img src="{{ $u->fotoUrl() }}" class="size-full object-cover" alt=""> @else <span class="judul text-2xl">{{ $u->inisial() }}</span> @endif
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <span class="truncate text-lg font-semibold">{{ $u->name }}</span>
                <label class="flex w-fit cursor-pointer items-center gap-1.5 font-caption text-[13px] font-semibold text-accent hover:underline">
                    @svg('lucide-camera', 'size-3.5') <span wire:loading.remove wire:target="foto">Ganti foto</span><span wire:loading wire:target="foto">Mengunggah…</span>
                    <input type="file" wire:model="foto" accept="image/*" class="sr-only">
                </label>
                @error('foto') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <label class="flex flex-col gap-2">
            <span class="{{ $label }}">Nama</span>
            <span class="flex h-11 items-center gap-2 rounded-sm bg-bg px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-[1.5px] focus-within:outline-accent">
                <input wire:model.live.blur="nama" class="flex-1 bg-transparent text-[15px] outline-none" autocomplete="name">
                @svg('lucide-pencil', 'size-4 text-fg-muted')
            </span>
            @error('nama') <span class="text-xs text-danger">{{ $message }}</span> @enderror
        </label>

        <label class="flex flex-col gap-2">
            <span class="{{ $label }}">No. HP / WhatsApp</span>
            <span class="flex h-11 items-center gap-3 rounded-sm bg-bg px-3 outline outline-1 -outline-offset-1 outline-line focus-within:outline-[1.5px] focus-within:outline-accent">
                <span class="border-r border-line pr-3 text-[15px] text-fg-muted">+62</span>
                <input wire:model.live.blur="hp" inputmode="tel" class="flex-1 bg-transparent text-[15px] outline-none" placeholder="812-3456-7890" autocomplete="tel-national">
                @svg('lucide-pencil', 'size-4 text-fg-muted')
            </span>
            @error('hp') <span class="text-xs text-danger">{{ $message }}</span> @else <span class="font-caption text-xs text-fg-muted">Dipakai untuk kabar lewat WhatsApp{{ $petugas ? '' : ' dan dihubungi petugas' }}.</span> @enderror
        </label>

        <div class="flex flex-col gap-2">
            <span class="flex items-center gap-2"><span class="{{ $label }}">Email</span><span class="flex items-center gap-1 font-caption text-[11px] text-fg-muted">@svg('lucide-lock', 'size-3') hanya dibaca</span></span>
            <span class="flex h-11 items-center gap-2 rounded-sm bg-surface-muted px-3 text-[15px] text-fg-muted">
                <span class="flex-1 truncate">{{ $u->email }}</span> @svg('lucide-lock', 'size-4')
            </span>
            @unless ($halaman) <span class="font-caption text-xs text-fg-muted">Dipakai untuk masuk. Hubungi admin untuk mengubah.</span> @endunless
        </div>

        <div class="flex flex-col gap-2">
            <span class="flex items-center gap-2"><span class="{{ $label }}">{{ $petugas ? 'Peran & instansi' : 'Peran' }}</span><span class="flex items-center gap-1 font-caption text-[11px] text-fg-muted">@svg('lucide-lock', 'size-3') hanya dibaca</span></span>
            <span class="flex flex-wrap gap-2">
                @foreach ($chips as [$ikon, $teks])
                    <span class="flex items-center gap-1.5 rounded-sm bg-surface-muted px-2.5 py-1.5 font-caption text-[13px] font-semibold">@svg('lucide-'.$ikon, 'size-3.5') {{ $teks }}</span>
                @endforeach
            </span>
        </div>

        <div class="flex flex-col gap-3 border-t border-line pt-4">
            <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">{{ $halaman ? 'Kabari saya lewat' : 'Notifikasi' }}</span>
            @foreach ([
                ['email', 'mail', 'Email', $keteranganEmail, 'notifEmail', true],
                ['wa', 'message-circle', 'WhatsApp', $waAktif ? ($u->no_hp ? 'Dikirim ke +62 '.$this->formatHp($u->no_hp) : 'Isi No. HP dulu') : 'Segera tersedia', 'notifWa', $waAktif && $u->no_hp],
            ] as [$kunci, $ikon, $judul, $ket, $model, $bisa])
                @php $on = $bisa && $this->{$model}; @endphp
                <label @class(['flex items-center gap-3', 'cursor-pointer' => $bisa, 'opacity-60' => ! $bisa])>
                    <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$ikon, 'size-[18px]')</span>
                    <span class="flex min-w-0 flex-1 flex-col"><span class="text-[15px] font-semibold">{{ $judul }}</span><span class="truncate font-caption text-xs text-fg-muted">{{ $ket }}</span></span>
                    <input type="checkbox" wire:model.live="{{ $model }}" @disabled(! $bisa) class="peer sr-only">
                    <span @class(['relative h-6 w-10 shrink-0 rounded-full transition', 'bg-accent' => $on, 'bg-line' => ! $on])><span @class(['absolute top-0.5 size-5 rounded-full bg-bg shadow-sm transition-all', 'left-[18px]' => $on, 'left-0.5' => ! $on])></span></span>
                </label>
            @endforeach
        </div>

        {{-- PWA: pasang SIPERJAL di layar utama (store 'pwa' di resources/js/app.js) --}}
        <div x-data x-show="$store.pwa.bisa || $store.pwa.ios || $store.pwa.terpasang" x-cloak class="flex flex-col gap-3 border-t border-line pt-4">
            <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">Aplikasi</span>
            <div class="flex items-center gap-3">
                <img src="{{ asset('icons/icon-192.png') }}" class="size-9 shrink-0 rounded-sm" alt="">
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-[15px] font-semibold" x-text="$store.pwa.terpasang ? 'SIPERJAL sudah terpasang' : 'Pasang SIPERJAL'"></span>
                    <span class="font-caption text-xs text-fg-muted" x-text="$store.pwa.terpasang ? 'Buka langsung dari layar utama' : ($store.pwa.ios && ! $store.pwa.bisa ? 'Ketuk Bagikan, lalu “Tambah ke Layar Utama”' : 'Buka lebih cepat dari layar utama, tanpa browser')"></span>
                </span>
                <button type="button" x-show="$store.pwa.bisa" @click="$store.pwa.pasang()" class="flex h-10 shrink-0 items-center gap-1.5 rounded-sm bg-accent px-3.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">@svg('lucide-download', 'size-4') Pasang</button>
                <span x-show="$store.pwa.terpasang" class="text-success">@svg('lucide-circle-check', 'size-5')</span>
            </div>
        </div>
    </div>

    @if ($halaman)
        <div class="flex flex-col gap-2 px-4 pt-2">
            <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">Bantuan</span>
            <a href="{{ route('panduan') }}" target="_blank" rel="noopener" class="flex h-11 items-center gap-3 rounded-sm px-3 outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">
                @svg('lucide-book-open', 'size-[18px] text-accent') <span class="flex-1 text-sm font-medium">Panduan & bantuan</span> @svg('lucide-chevron-right', 'size-4 text-fg-muted')
            </a>
        </div>

        <form method="POST" action="{{ route('keluar') }}" class="px-4 pb-6 pt-8">
            @csrf
            <button class="flex h-12 w-full items-center justify-center gap-2 rounded-sm text-[15px] font-semibold text-danger outline outline-1 -outline-offset-1 outline-danger/30 hover:bg-danger-bg">@svg('lucide-log-out', 'size-[18px]') Keluar</button>
        </form>
    @else
        <div class="flex items-center justify-between rounded-b-md border-t border-line bg-surface-muted px-6 py-3.5">
            <form method="POST" action="{{ route('keluar') }}">
                @csrf
                <button class="flex items-center gap-2 text-sm font-semibold text-danger hover:underline">@svg('lucide-log-out', 'size-4') Keluar</button>
            </form>
            <span class="font-caption text-xs text-fg-muted">Perubahan disimpan otomatis</span>
        </div>
    @endif
</div>

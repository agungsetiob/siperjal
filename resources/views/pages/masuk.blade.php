<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.publik', ['nav' => 'masuk', 'footer' => 'tidak'])] #[Title('Masuk · SIPERJAL')] class extends Component
{
    #[Locked]
    public string $langkah = 'email'; // email, kode, profil
    public string $email = '';
    public string $kode = '';
    public string $nama = '';
    public string $no_hp = '';
    public ?string $kodeDev = null;

    public function kirimKode(): void
    {
        $this->validate(['email' => 'required|email'], [], ['email' => 'email']);
        $this->email = strtolower(trim($this->email));

        $kunci = 'otp:'.$this->email;
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $this->addError('email', 'Terlalu sering. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.');

            return;
        }
        RateLimiter::hit($kunci, 600);

        $kode = (string) random_int(100000, 999999);
        DB::table('otp_codes')->where('email', $this->email)->delete();
        DB::table('otp_codes')->insert([
            'email' => $this->email,
            'code' => Hash::make($kode),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::raw("Kode masuk SIPERJAL Anda: {$kode}\n\nBerlaku 10 menit. Abaikan email ini jika Anda tidak meminta kode.", function ($m) {
            $m->to($this->email)->subject('Kode masuk SIPERJAL');
        });

        $this->kodeDev = app()->isLocal() ? $kode : null;
        $this->kode = '';
        $this->langkah = 'kode';
        $this->dispatch('kode-terkirim');
    }

    public function verifikasi(): void
    {
        $otp = DB::table('otp_codes')->where('email', $this->email)->first();

        if (! $otp || now()->gt($otp->expires_at) || $otp->attempts >= 5) {
            $this->addError('kode', 'Kode kedaluwarsa. Minta kode baru.');

            return;
        }
        if (! Hash::check($this->kode, $otp->code)) {
            DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');
            $this->addError('kode', 'Kode salah. Periksa lagi email Anda.');
            $this->kode = '';

            return;
        }
        DB::table('otp_codes')->where('id', $otp->id)->delete();

        $user = User::where('email', $this->email)->first();
        if ($user && ! $user->aktif) {
            $this->addError('kode', 'Akun Anda dinonaktifkan. Hubungi Dishub.');

            return;
        }
        if (! $user) {
            session(['otp_email' => $this->email]); // email terverifikasi, dipakai saat buat akun
            $this->langkah = 'profil';

            return;
        }

        $this->masukSebagai($user);
    }

    public function simpanProfil(): void
    {
        $this->no_hp = preg_replace('/\D/', '', $this->no_hp);
        $this->validate([
            'nama' => 'required|string|max:100',
            'no_hp' => 'required|digits_between:8,14',
        ], [], ['nama' => 'nama', 'no_hp' => 'nomor HP']);

        $email = session()->pull('otp_email');
        abort_if(! $email || User::where('email', $email)->exists(), 403);

        $user = User::create([
            'name' => $this->nama,
            'email' => $email,
            'no_hp' => '0'.ltrim(preg_replace('/^62/', '', $this->no_hp), '0'),
            'role' => Role::Pengusul,
            'email_verified_at' => now(),
        ]);

        $this->masukSebagai($user, baru: true);
    }

    public function emailSamar(): string
    {
        [$nama, $domain] = explode('@', $this->email) + [1 => ''];

        return substr($nama, 0, 3).'•••@'.$domain;
    }

    public function gantiEmail(): void
    {
        $this->langkah = 'email';
    }

    private function masukSebagai(User $user, bool $baru = false): void
    {
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        Auth::login($user, remember: true);
        session()->regenerate();
        // pengguna baru langsung diarahkan ke Ajukan Usulan
        $this->redirectIntended($baru ? route('usulan.baru') : '/usulan', navigate: true);
    }
};
?>

@php
    $tombol = 'flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent px-5 text-base font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60 lg:h-12 lg:w-auto lg:text-[15px]';
    $bawah = 'sticky bottom-0 -mx-4 mt-auto bg-bg px-4 pb-7 pt-3 lg:static lg:mx-0 lg:mt-0 lg:bg-transparent lg:p-0';
@endphp

<div class="flex min-h-dvh lg:min-h-[calc(100dvh-72px)]">
    <div class="flex flex-1 flex-col">
        {{-- Kepala HP --}}
        @if ($langkah === 'email')
            <div class="relative h-[220px] shrink-0 bg-cover bg-center lg:hidden" style="background-image: url('{{ asset('img/masuk.jpg') }}')">
                <a href="{{ url('/') }}" wire:navigate class="absolute left-2 top-2 grid size-11 place-items-center rounded-full bg-bg/90" aria-label="Kembali">@svg('lucide-arrow-left', 'size-5')</a>
            </div>
        @else
            <div class="flex h-14 shrink-0 items-center gap-1 px-2 lg:hidden">
                <button type="button" wire:click="gantiEmail" class="grid size-11 place-items-center" aria-label="Kembali">@svg('lucide-arrow-left', 'size-[22px]')</button>
                <span class="flex-1 text-base font-semibold">{{ $langkah === 'kode' ? 'Masuk' : 'Lengkapi Data' }}</span>
                @if ($langkah === 'kode') <span class="px-2 font-caption text-[13px] text-fg-muted">2 / 2</span> @endif
            </div>
        @endif

        <div class="flex flex-1 flex-col gap-5 px-4 pt-4 lg:gap-9 lg:px-20 lg:pb-16 lg:pt-14">
            <div @class(['flex-col gap-2 lg:flex lg:gap-3.5', 'flex pt-2' => $langkah === 'email', 'hidden' => $langkah !== 'email'])>
                <span class="font-caption text-xs font-semibold uppercase tracking-[0.6px] text-accent lg:text-[13px] lg:tracking-[1.2px] lg:text-fg-muted">Masuk</span>
                <h1 class="judul text-[40px] leading-10 lg:text-[80px] lg:leading-[80px]">Masuk dengan kode</h1>
                <p class="max-w-[560px] text-base leading-[23px] text-fg-muted lg:text-[17px] lg:leading-[26px]">
                    <span class="lg:hidden">Tanpa kata sandi. Kami kirim kode 6 digit ke email Anda.</span>
                    <span class="hidden lg:inline">Tidak perlu kata sandi. Masukkan email, lalu kami kirim kode 6 angka untuk masuk. Berlaku untuk masyarakat maupun petugas instansi.</span>
                </p>
            </div>

            @if (session('pesan'))
                <div class="max-w-[560px] rounded-sm bg-warning-bg px-4 py-3 text-sm text-warning">{{ session('pesan') }}</div>
            @endif

            @if ($langkah === 'email')
                <form wire:submit="kirimKode" class="flex max-w-[680px] flex-1 flex-col gap-5 lg:flex-none lg:gap-3">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <label class="flex flex-1 flex-col gap-2">
                            <span class="text-sm font-semibold">Email</span>
                            <span class="flex h-[52px] items-center gap-2.5 rounded-sm bg-bg px-3.5 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent lg:h-12">
                                @svg('lucide-mail', 'size-[18px] shrink-0 text-fg-muted')
                                <input wire:model="email" type="email" autofocus autocomplete="email" class="w-full bg-transparent text-base outline-none lg:text-[15px]" placeholder="nama@email.com">
                            </span>
                        </label>
                        <button class="{{ $tombol }} hidden lg:flex" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="kirimKode">Kirim Kode</span><span wire:loading wire:target="kirimKode">Mengirim…</span> @svg('lucide-send', 'size-[18px] rotate-45')
                        </button>
                    </div>
                    @error('email') <span class="-mt-2 text-sm text-danger lg:mt-0">{{ $message }}</span> @enderror
                    <p class="hidden font-caption text-[13px] text-fg-muted lg:block">Petugas instansi: gunakan email dinas yang sudah didaftarkan admin.</p>
                    <div class="flex gap-2.5 rounded-md bg-surface-tint p-3 text-sm leading-5 text-accent-deep lg:hidden">@svg('lucide-building-2', 'size-[18px] shrink-0') Akun instansi dibuat oleh Dishub. Hubungi admin jika belum punya.</div>
                    <div class="{{ $bawah }} lg:hidden">
                        <button class="{{ $tombol }}" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="kirimKode">Kirim Kode</span><span wire:loading wire:target="kirimKode">Mengirim…</span> @svg('lucide-send', 'size-[18px] rotate-45')
                        </button>
                    </div>
                </form>

            @elseif ($langkah === 'kode')
                <form wire:submit="verifikasi" class="flex max-w-[520px] flex-1 flex-col gap-5 lg:flex-none lg:gap-[18px] lg:rounded-md lg:bg-bg lg:p-6 lg:shadow-sm lg:outline lg:outline-1 lg:-outline-offset-1 lg:outline-line"
                      x-data="{
                          kode: @entangle('kode'),
                          sisa: 60,
                          mulai() { this.sisa = 60; clearInterval(this.t); this.t = setInterval(() => this.sisa > 0 && this.sisa--, 1000) },
                          get waktu() { return Math.floor(this.sisa / 60) + ':' + String(this.sisa % 60).padStart(2, '0') },
                      }"
                      x-init="mulai(); $nextTick(() => $refs.kode.focus()); $watch('kode', v => { v = (v || '').replace(/\D/g, '').slice(0, 6); if (v !== kode) kode = v; if (v.length === 6) $wire.verifikasi() })"
                      @kode-terkirim.window="mulai()">
                    <div class="flex flex-col gap-2 lg:gap-1.5">
                        <h1 class="judul text-[40px] leading-10 lg:hidden">Cek email Anda</h1>
                        <span class="hidden text-[19px] font-bold lg:block">Masukkan kode 6 angka</span>
                        <span class="text-[15px] leading-5 text-fg-muted lg:text-sm">Kode dikirim ke <span class="lg:hidden">{{ $email }}</span><span class="hidden lg:inline">{{ $this->emailSamar() }}. Cek juga folder spam.</span>
                            <button type="button" wire:click="gantiEmail" class="font-semibold text-accent lg:hidden">Ubah</button></span>
                        @if ($kodeDev) <span class="mt-1 w-fit rounded-sm bg-surface-tint px-2.5 py-1 font-caption text-xs">Mode pengembangan · kode: <b>{{ $kodeDev }}</b></span> @endif
                    </div>
                    <label class="relative grid cursor-text grid-cols-6 gap-2" @click="$refs.kode.focus()">
                        <input x-ref="kode" x-model="kode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                               class="absolute inset-0 opacity-0" aria-label="Kode 6 angka">
                        <template x-for="i in 6" :key="i">
                            <span class="grid h-[60px] place-items-center rounded-sm outline -outline-offset-1 lg:h-14"
                                  :class="i - 1 === kode.length ? 'bg-bg outline-2 outline-accent' : (i <= kode.length ? 'bg-accent-soft outline-1 outline-transparent' : 'bg-bg outline-1 outline-line')">
                                <span class="judul text-[30px] lg:font-sans lg:text-2xl lg:font-bold lg:tracking-normal" x-text="kode[i - 1] ?? ''"></span>
                                <span x-show="i - 1 === kode.length" class="h-[26px] w-0.5 animate-pulse bg-accent"></span>
                            </span>
                        </template>
                    </label>
                    @error('kode') <span class="-mt-2 text-sm text-danger">{{ $message }}</span> @enderror
                    <div class="flex flex-col gap-2 text-sm lg:flex-row lg:items-center lg:justify-between">
                        <span class="flex items-center gap-2 lg:hidden">@svg('lucide-zap', 'size-4 text-accent') Masuk otomatis saat 6 digit lengkap</span>
                        <span class="flex items-center gap-2 text-fg-muted lg:gap-1.5 lg:font-caption lg:text-[13px] lg:font-medium">
                            @svg('lucide-timer', 'size-4')
                            <span x-show="sisa > 0">Kirim ulang kode dalam <span x-text="waktu"></span></span>
                            <button type="button" x-show="sisa === 0" x-cloak wire:click="kirimKode" class="font-semibold text-accent">Kirim ulang kode</button>
                        </span>
                        <span class="text-fg-muted lg:hidden">Tidak ada di kotak masuk? Cek folder Spam.</span>
                        <button type="button" wire:click="gantiEmail" class="hidden text-[13px] font-semibold text-accent lg:block">Ganti email</button>
                    </div>
                    <button class="{{ $tombol }} hidden lg:flex" wire:loading.attr="disabled">Masuk @svg('lucide-log-in', 'size-[18px]')</button>
                </form>

            @else
                <form wire:submit="simpanProfil" class="flex max-w-[520px] flex-1 flex-col gap-5 lg:flex-none lg:gap-[18px] lg:rounded-md lg:bg-bg lg:p-6 lg:shadow-sm lg:outline lg:outline-1 lg:-outline-offset-1 lg:outline-line">
                    <span class="flex w-fit items-center gap-1.5 rounded-sm bg-success-bg px-2.5 py-1.5 font-caption text-[13px] font-semibold text-success lg:hidden">@svg('lucide-circle-check', 'size-[15px]') Email terverifikasi</span>
                    <div class="flex flex-col gap-2 lg:gap-1.5">
                        <h1 class="judul text-[40px] leading-10 lg:hidden">Satu langkah lagi</h1>
                        <span class="hidden text-[19px] font-bold lg:block">Lengkapi data diri</span>
                        <span class="text-base leading-[23px] text-fg-muted lg:text-sm lg:leading-5">
                            <span class="lg:hidden">Isi sekali saja. Kami pakai untuk mengabari perkembangan usulan Anda.</span>
                            <span class="hidden lg:inline">Cukup sekali ini saja. Hanya dua hal yang kami perlukan.</span>
                        </span>
                    </div>
                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-semibold">Nama lengkap</span>
                        <span class="flex h-[52px] items-center gap-2.5 rounded-sm bg-bg px-3.5 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent lg:h-12">
                            @svg('lucide-user-round', 'size-[18px] shrink-0 text-fg-muted')
                            <input wire:model="nama" autofocus autocomplete="name" class="w-full bg-transparent text-base outline-none lg:text-[15px]">
                        </span>
                        @error('nama') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-semibold">No. HP (WhatsApp)</span>
                        <span class="flex h-[52px] items-center gap-2.5 rounded-sm bg-bg px-3.5 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent lg:h-12">
                            <span class="border-r border-line pr-2.5 text-base font-semibold text-fg-muted lg:text-[15px]">+62</span>
                            <input wire:model="no_hp" inputmode="tel" autocomplete="tel-national" class="w-full bg-transparent text-base outline-none lg:text-[15px]" placeholder="812 3456 7890">
                        </span>
                        @error('no_hp') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </label>
                    <div class="flex gap-2 font-caption text-[13px] leading-[18px] text-fg-muted">@svg('lucide-shield-check', 'size-4 shrink-0') Nama dan nomor Anda tidak pernah tampil di halaman publik.</div>
                    <div class="{{ $bawah }}">
                        <button class="{{ $tombol }} lg:w-full">
                            <span class="lg:hidden">Lanjut ke Ajukan Usulan</span><span class="hidden lg:inline">Simpan & Lanjut</span> @svg('lucide-arrow-right', 'size-[18px]')
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="hidden w-[540px] shrink-0 flex-col justify-end bg-cover bg-center p-8 lg:flex" style="background-image: url('{{ asset('img/masuk.jpg') }}')">
        <div class="flex items-center gap-3.5 rounded-md bg-bg p-5 shadow-[0_4px_16px_#0000001a]">
            <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-smartphone', 'size-5')</span>
            <span class="text-[15px] font-semibold leading-[21px]">Setelah masuk, ajukan dan pantau usulan langsung dari HP Anda.</span>
        </div>
    </div>
</div>

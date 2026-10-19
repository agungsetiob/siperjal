<?php

use App\Enums\StatusItem;
use App\Models\JenisPerlengkapan;
use App\Models\PerlengkapanTerpasang;
use App\Models\Usulan;
use App\Models\UsulanItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.publik', ['nav' => 'beranda', 'footer' => 'lengkap'])] #[Title('SIPERJAL · Usulkan perlengkapan jalan di sekitar Anda')] class extends Component
{
    public string $nomor = '';

    public function lacak()
    {
        $nomor = strtoupper(trim($this->nomor));
        if (str_starts_with($nomor, 'LPR-') && \App\Models\LaporanKerusakan::where('nomor', $nomor)->exists()) {
            return $this->redirectRoute('lapor.status', $nomor, navigate: true);
        }
        if (! Usulan::where('nomor', $nomor)->exists()) {
            $this->addError('nomor', 'Nomor usulan tidak ditemukan. Periksa lagi, ya.');

            return;
        }

        return $this->redirectRoute('lacak', $nomor, navigate: true);
    }

    #[Computed]
    public function jenis()
    {
        return JenisPerlengkapan::aktif()->get();
    }

    #[Computed]
    public function angka(): array
    {
        $tahun = now()->year;

        return [
            'masuk' => Usulan::whereYear('diajukan_at', $tahun)->count(),
            'disetujui' => UsulanItem::whereYear('diputuskan_at', $tahun)->whereIn('status', [StatusItem::Disetujui, StatusItem::Terpasang])->count(),
            'terpasang' => PerlengkapanTerpasang::count(),
        ];
    }

    #[Computed]
    public function terpasang()
    {
        return PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan', 'laporanTerbuka')
            ->orderByRaw('terpasang_at is null')->latest('terpasang_at')->latest('id')->limit(500)->get();
    }

    /** Data peta: jenis (warna + ikon) dan titik terpasang. */
    public function dataPeta(): array
    {
        return [
            'jenis' => $this->jenis->mapWithKeys(fn ($j) => [$j->id => [
                'warna' => $j->warna,
                'ikon' => svg('lucide-'.$j->ikon, 'size-4')->toHtml(),
            ]])->all(),
            'titik' => $this->terpasang->map(fn ($i) => [
                'lat' => $i->lat,
                'lng' => $i->lng,
                'jenis' => $i->jenis_perlengkapan_id,
                'rusak' => (bool) $i->laporanTerbuka,
                'popup' => view('partials.popup-terpasang', ['item' => $i])->render(),
            ])->all(),
        ];
    }
};
?>

@php
    $legenda = $this->terpasang->pluck('jenis')->unique('id')->values();
    $legenda = $legenda->isEmpty() ? $this->jenis->take(6) : $legenda->take(8);
@endphp

<div>
    {{-- ── Hero · Peta Terpasang ── --}}
    <section class="relative isolate overflow-hidden bg-surface-muted" id="peta">
        {{-- Satu peta: di HP pita 300 px di atas kartu, di desktop mengisi hero --}}
        <div class="absolute inset-x-0 top-0 z-0 h-[300px] lg:inset-0 lg:h-auto" wire:ignore x-data="petaTerpasang(@js($this->dataPeta()), @js(config('usuljalan.peta_awal')))">
            <div x-ref="peta" class="h-full w-full"></div>
        </div>
        <div class="pointer-events-none absolute inset-x-0 top-0 z-[1] h-[300px] bg-surface-muted/50 lg:hidden"></div>
        {{-- Desktop: foto jalan menggantikan pudar putih di sisi teks, memudar ke peta di kanan --}}
        <div class="pointer-events-none absolute inset-0 z-[1] hidden lg:block [mask-image:linear-gradient(90deg,#000_0%,#000_38%,transparent_66%)]">
            <img src="{{ asset('img/hero-beranda.webp') }}" width="1600" height="900" alt="" fetchpriority="high" decoding="async" class="size-full object-cover object-left">
            <div class="absolute inset-0 bg-gradient-to-r from-bg/50 via-bg/35 to-bg/20"></div>
        </div>

        {{-- ── HP ── --}}
        @php $akhir = $this->terpasang->first(); @endphp
        @if ($akhir)
            <div class="pointer-events-none absolute right-4 top-[22px] z-[3] flex max-w-[220px] items-center gap-2 rounded-md bg-bg px-2.5 py-2 shadow-md lg:hidden">
                <span class="grid size-[22px] shrink-0 place-items-center rounded-full p-1 text-fg-inverse" style="background: {{ $akhir->jenis->warna }}">@svg('lucide-'.$akhir->jenis->ikon, 'size-3')</span>
                <span class="flex min-w-0 flex-col gap-px"><span class="truncate text-[13px] font-semibold">{{ $akhir->jenis->namaPendek() }} · terpasang</span><span class="truncate font-caption text-xs text-fg-muted">{{ $akhir->waktuPasang() }} · {{ $akhir->lokasi() }}</span></span>
            </div>
        @endif
        <span class="pointer-events-none absolute left-4 top-[252px] z-[3] flex items-center gap-1.5 rounded-sm bg-bg px-2.5 py-1.5 font-caption text-[13px] font-semibold shadow-md lg:hidden">@svg('lucide-map-pin-check', 'size-3.5 text-success') {{ number_format($this->terpasang->count(), 0, ',', '.') }} perlengkapan terpasang</span>
        <div class="relative z-[2] mt-[276px] flex flex-col items-center gap-4 rounded-t-2xl bg-bg px-4 pb-5 pt-2.5 shadow-[0_-6px_18px_#00010014] lg:hidden">
            <span class="h-1 w-9 rounded-full bg-line"></span>
            <h1 class="judul w-full text-[34px] leading-9">Usulkan perlengkapan jalan di sekitar Anda</h1>
            <p class="w-full text-base leading-[23px] text-fg-muted">Rambu, lampu jalan, marka — cukup foto lokasinya. Sekitar 2 menit.</p>
            <a href="{{ route('usulan.baru') }}" wire:navigate class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Ajukan Usulan @svg('lucide-camera', 'size-[18px]')</a>
            <form wire:submit="lacak" id="lacak-hp" class="flex w-full scroll-mt-20 flex-col gap-2 rounded-md bg-surface-muted p-3.5">
                <span class="text-sm font-semibold">Sudah punya nomor usulan?</span>
                <div class="flex gap-2">
                    <input wire:model="nomor" class="h-12 min-w-0 flex-1 rounded-sm bg-bg px-3 text-base uppercase outline outline-1 -outline-offset-1 outline-line placeholder:normal-case placeholder:text-[#9CA1B5] focus:outline-2 focus:outline-accent" placeholder="USL-{{ date('Y') }}-00123">
                    <button class="h-12 rounded-sm bg-fg px-4 text-base font-semibold text-fg-inverse">Lacak</button>
                </div>
                @error('nomor') <div class="text-sm text-danger">{{ $message }}</div> @enderror
            </form>
        </div>
        <div class="flex flex-col gap-3 bg-accent px-4 pb-6 pt-1 lg:hidden">
            <h2 class="judul text-2xl text-fg-inverse">3 langkah mengajukan</h2>
            <div class="grid grid-cols-3 gap-2">
                @foreach ([['camera', '1. Foto lokasi'], ['grid-2x2', '2. Pilih kebutuhan'], ['send', '3. Kirim']] as [$ikon, $l])
                    <div class="flex flex-col gap-2 rounded-md bg-bg p-3">@svg('lucide-'.$ikon, 'size-5 text-accent'.($ikon === 'send' ? ' rotate-45' : ''))<span class="text-sm font-semibold leading-[18px]">{{ $l }}</span></div>
                @endforeach
            </div>
        </div>

        {{-- ── Desktop ── --}}
        <div class="pointer-events-none relative z-[2] mx-auto hidden min-h-[640px] max-w-[1440px] items-center px-4 py-14 sm:px-8 lg:flex lg:h-[840px] lg:px-[100px] lg:py-[72px]">
            <div class="pointer-events-auto flex w-full max-w-[560px] flex-col gap-6">
                <div class="flex items-center gap-2 font-caption text-sm font-semibold text-accent">
                    <span class="size-2 rounded-full bg-accent"></span> Layanan publik Dishub
                </div>
                <h1 class="judul text-[50px] leading-[1] sm:text-[60px] sm:leading-[80px]">kelola perlengkapan jalan. <span class="text-accent">wujudkan keselamatan.</span></h1>
                <p class="text-lg leading-[27px] font-semibold">Ada perlengkapan jalan yang perlu diperbaiki? Ajukan usulan dan bantu meningkatkan keselamatan berkendara.</p>
                <a href="{{ route('usulan.baru') }}" wire:navigate class="flex w-fit items-center gap-2 rounded-sm bg-accent px-8 py-5 text-[19px] font-semibold text-fg-inverse shadow-sm transition hover:brightness-110">
                    Ajukan Usulan @svg('lucide-arrow-right', 'size-5')
                </a>

                <form wire:submit="lacak" id="lacak" class="flex scroll-mt-28 flex-col gap-3 rounded-md bg-bg p-5 shadow-sm outline outline-1 -outline-offset-1 outline-line">
                    <div class="font-semibold">Sudah mengajukan? Lacak usulan Anda</div>
                    <div class="flex gap-2">
                        <label class="flex h-12 flex-1 items-center gap-2.5 rounded-sm bg-bg px-3.5 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                            @svg('lucide-hash', 'size-[18px] shrink-0 text-fg-muted')
                            <input wire:model="nomor" class="w-full bg-transparent font-caption text-[15px] uppercase outline-none placeholder:normal-case placeholder:text-[#6B7280]" placeholder="Nomor usulan, mis. USL-2026-00123">
                        </label>
                        <button class="h-12 rounded-sm bg-accent-soft px-5 text-[15px] font-semibold text-accent hover:bg-accent hover:text-fg-inverse">Lacak</button>
                    </div>
                    @error('nomor') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                    <p class="font-caption text-[13px] text-fg-muted">Nomor usulan ada di layar & email yang Anda terima setelah mengirim.</p>
                </form>
            </div>
        </div>

        <div class="absolute bottom-10 right-4 z-[2] hidden w-[280px] flex-col gap-2.5 rounded-md bg-bg/95 p-4 shadow-sm sm:right-8 lg:right-[100px] lg:flex">
            <div class="font-caption text-[11px] font-bold uppercase tracking-[0.8px]">{{ number_format($this->terpasang->count(), 0, ',', '.') }} titik sudah terpasang</div>
            <div class="grid grid-cols-2 gap-x-2 gap-y-2.5">
                @foreach ($legenda as $j)
                    <span class="flex items-center gap-1.5 font-caption text-xs text-fg-muted"><span class="size-2 shrink-0 rounded-full" style="background: {{ $j->warna }}"></span><span class="truncate">{{ $j->namaPendek() }}</span></span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Cara Mengajukan ── --}}
    <section class="hidden scroll-mt-16 bg-accent lg:block" id="cara">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-12 px-4 py-16 sm:px-8 lg:px-[100px] lg:py-[88px]">
            <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-end lg:gap-20">
                <h2 class="judul text-5xl text-fg-inverse lg:text-[64px] lg:leading-[64px]">Ajukan dalam 3 langkah</h2>
                <p class="max-w-[420px] text-lg leading-[27px] text-fg-inverse">Tidak perlu datang ke kantor. Dari HP, cukup sekitar 5 menit — tanpa isi formulir pendaftaran.</p>
            </div>
            <div class="grid items-start gap-4 md:grid-cols-3">
                @foreach ([
                    ['camera', '01', 'Foto & tandai lokasi', 'Foto titik jalan yang bermasalah. Lokasi terisi otomatis dari HP, dan bisa digeser di peta.'],
                    ['list-checks', '02', 'Pilih kebutuhan', 'Centang yang dibutuhkan: rambu, lampu PJU, marka, atau perlengkapan lainnya.'],
                    ['send', '03', 'Kirim', 'Anda dapat nomor usulan. Dishub memeriksa, Bappeda menyetujui, lalu dipasang.'],
                ] as [$ikon, $no, $judul, $isi])
                    <div class="flex flex-col gap-5 rounded-md bg-bg p-7 shadow-sm">
                        <div class="flex items-center justify-between">
                            <span class="grid size-12 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$ikon, 'size-6'.($ikon === 'send' ? ' rotate-45' : ''))</span>
                            <span class="judul text-[44px] leading-[44px] text-accent">{{ $no }}</span>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="text-[22px] font-semibold">{{ $judul }}</div>
                            <p class="text-base leading-6 text-fg-muted">{{ $isi }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Ticker ── --}}
    <div class="flex h-16 items-center overflow-hidden bg-secondary">
        <div class="flex w-max animate-geser items-center gap-7 pl-10">
            @foreach ([1, 2] as $_)
                @foreach ($this->jenis as $j)
                    <span class="judul whitespace-nowrap text-2xl tracking-[1px] text-accent">{{ $j->namaPendek() }}</span>
                    <span class="text-accent/70">@svg('lucide-asterisk', 'size-4')</span>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- ── Angka ── --}}
    <section class="mx-auto flex max-w-[1440px] flex-col gap-10 px-4 py-16 sm:px-8 lg:flex-row lg:items-center lg:gap-12 lg:px-[100px] lg:py-[72px]">
        <div class="flex w-[300px] shrink-0 flex-col gap-2">
            <h2 class="judul text-[40px] leading-10">Angka tahun ini</h2>
            <span class="font-caption text-[13px] text-fg-muted">Diperbarui {{ now()->translatedFormat('j F Y') }}</span>
        </div>
        <div class="grid flex-1 gap-8 sm:grid-cols-3 sm:gap-0">
            @foreach ([['masuk', 'usulan masuk', 'inbox', false], ['disetujui', 'item disetujui Bappeda', 'badge-check', false], ['terpasang', 'sudah terpasang', 'map-pin', true]] as [$k, $label, $ikon, $sorot])
                <div class="flex flex-col gap-1.5 border-l border-line py-1 pl-8">
                    <div @class(['judul text-[64px] leading-[64px]', 'text-accent' => $sorot])>{{ number_format($this->angka[$k], 0, ',', '.') }}</div>
                    <div class="flex items-center gap-1.5 text-base text-fg-muted">@svg('lucide-'.$ikon, 'size-4') {{ $label }}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Pantau tanpa bolak-balik ── --}}
    <section class="bg-surface-soft">
        <div class="mx-auto flex max-w-[1440px] flex-col items-center gap-10 lg:flex-row lg:gap-20 lg:pr-[100px]">
            <div class="flex h-[420px] w-full shrink-0 flex-col justify-end bg-cover bg-center p-8 lg:h-[640px] lg:w-[680px]" style="background-image: url('{{ asset('img/wa-hp.jpg') }}')">
                <div class="flex w-full max-w-[340px] flex-col gap-1.5 rounded-md rounded-bl-none bg-bg px-3.5 py-3 shadow-[0_6px_18px_#00010026]">
                    <div class="flex items-center gap-1.5 font-caption text-[13px] font-bold text-[#128C4A]">@svg('lucide-message-circle', 'size-3.5') Grup Warga RT 03</div>
                    <p class="text-sm leading-5">Usulan lampu jalan kita sudah disetujui Bappeda. Cek tahap berikutnya:</p>
                    <span class="text-sm text-[#0B6BCB]">{{ str(route('lacak', 'USL-'.date('Y').'-00123'))->after('://') }}</span>
                    <span class="text-right font-caption text-[11px] text-fg-muted">09.41</span>
                </div>
            </div>
            <div class="flex flex-1 flex-col gap-6 px-4 pb-16 sm:px-8 lg:px-0 lg:py-[88px]">
                <span class="font-caption text-sm font-semibold text-accent">Tanpa perlu bolak-balik</span>
                <h2 class="judul text-5xl lg:text-[64px] lg:leading-[65px]">Pantau usulan cukup dari satu tautan</h2>
                <p class="text-lg leading-[27px] text-fg-muted">Setiap kali usulan pindah tahap, kami kirim email berisi tautan lacak. Bagikan tautannya ke grup WhatsApp warga — siapa pun bisa melihat status terbaru tanpa perlu masuk akun.</p>
                <div class="flex flex-wrap items-center gap-2 text-fg-muted">
                    @foreach ([['Diterima', 'netral'], ['Diperiksa Dishub', 'proses'], ['Disetujui Bappeda', 'perhatian'], ['Terpasang', 'sukses']] as $i => [$l, $t])
                        @if ($i) @svg('lucide-chevron-right', 'size-3.5') @endif
                        <x-badge :tone="$t">{{ $l }}</x-badge>
                    @endforeach
                </div>
                <a href="https://wa.me/62{{ ltrim(preg_replace('/\D/', '', config('usuljalan.kontak_wa')), '0') }}" target="_blank" class="flex w-fit items-center gap-2 py-1 text-base font-semibold hover:text-accent">
                    @svg('lucide-message-circle', 'size-[18px]') Tanya lewat WhatsApp: {{ config('usuljalan.kontak_wa') }}
                </a>
            </div>
        </div>
    </section>
</div>

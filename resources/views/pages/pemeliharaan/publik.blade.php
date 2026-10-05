<?php

use App\Enums\StatusItem;
use App\Enums\StatusLaporan as SL;
use App\Models\LaporanKerusakan;
use App\Models\UsulanItem;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Publik (tanpa login): laporkan kerusakan perlengkapan terpasang (/lapor/{item}, dari popup peta beranda & halaman lacak)
 * dan lihat status laporan (/lapor/status/{nomor}). Pengusul yang sudah masuk diarahkan ke form /lapor.
 */
new #[Layout('layouts.publik', ['nav' => 'lacak', 'footer' => 'ringkas'])] class extends Component
{
    use WithFileUploads;

    public ?UsulanItem $item = null;
    public ?LaporanKerusakan $laporan = null;
    public string $nomor = '';

    public string $kerusakan = '';
    public $foto = [];
    public array $fotoKoordinat = [];
    public string $catatan = '';
    public string $nama = '';
    public string $email = '';
    public string $situs = ''; // jebakan bot, harus kosong

    public function mount(?UsulanItem $item = null, ?string $nomor = null)
    {
        if ($nomor !== null) {
            $this->nomor = strtoupper(trim($nomor));
            $this->laporan = LaporanKerusakan::with('item.jenis', 'item.usulan.desa', 'item.usulan.kecamatan', 'foto', 'petugas')->where('nomor', $this->nomor)->first();

            return null;
        }
        abort_unless($item?->status === StatusItem::Terpasang, 404);
        if (auth()->user()?->isPengusul()) {
            return $this->redirectRoute('lapor', ['item' => $item->id], navigate: true);
        }
        $this->item = $item->load('jenis', 'usulan.desa', 'usulan.kecamatan', 'laporanTerbuka');

        return null;
    }

    public function hapusFoto(int $n): void
    {
        array_splice($this->foto, $n, 1);
        array_splice($this->fotoKoordinat, $n, 1);
    }

    public function kirim()
    {
        $item = $this->item;
        abort_unless($item?->status === StatusItem::Terpasang, 404);
        if ($this->situs !== '') {
            return $this->redirect('/', navigate: true);
        }
        $kunci = 'lapor:'.request()->ip();
        if (RateLimiter::tooManyAttempts($kunci, config('usuljalan.laporan_per_jam'))) {
            $this->addError('kirim', 'Terlalu banyak laporan dari perangkat ini. Coba lagi dalam '.ceil(RateLimiter::availableIn($kunci) / 60).' menit.');

            return null;
        }
        $maks = config('usuljalan.laporan_maks_foto');
        $this->validate([
            'kerusakan' => 'required|in:'.implode(',', array_keys(LaporanKerusakan::KERUSAKAN)),
            'foto' => "required|array|min:1|max:{$maks}",
            'foto.*' => 'image|max:12288',
            'catatan' => ($this->kerusakan === 'lainnya' ? 'required' : 'nullable').'|string|max:500',
            'nama' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:120',
        ], [
            'kerusakan.required' => 'Pilih jenis kerusakannya.',
            'foto.required' => 'Ambil minimal 1 foto kerusakan.',
            'foto.max' => "Maksimal {$maks} foto.",
            'catatan.required' => 'Ceritakan singkat kerusakannya.',
            'email.email' => 'Format email belum benar.',
        ]);

        $laporan = LaporanKerusakan::buat($item, null, $this->kerusakan, trim($this->catatan),
            collect($this->foto)->map(fn ($f, $n) => ['file' => $f] + ($this->fotoKoordinat[$n] ?? []))->all(),
            ['nama_pelapor' => trim($this->nama), 'kontak_email' => trim($this->email)],
        );
        RateLimiter::hit($kunci, 3600);
        if ($laporan->wasRecentlyCreated) {
            session()->flash('laporan_baru', true);
        }

        return $this->redirectRoute('lapor.status', $laporan->nomor, navigate: true);
    }

    public function render()
    {
        return $this->view()->title(match (true) {
            (bool) $this->laporan => $this->laporan->nomor.' · Laporan Kerusakan',
            (bool) $this->item => 'Laporkan Kerusakan · SIPERJAL',
            default => 'Nomor tidak ditemukan · Laporan Kerusakan',
        });
    }
};
?>

@php
    $l = $laporan;
    $it = $l?->item ?? $item;
    $u = $it?->usulan;
    $terbuka = $item?->laporanTerbuka;
    $linkStatus = $l ? route('lapor.status', $l->nomor) : null;
    $penjelasan = $l ? match ($l->status) {
        SL::Baru => 'Laporan sudah diterima. Dishub akan menugaskan petugas untuk cek ke lokasi.',
        SL::Ditugaskan => 'Petugas Dishub akan mengecek kondisi di lokasi.',
        SL::MenungguPerbaikan => 'Petugas sudah memastikan kerusakannya. Masuk antrean perbaikan Dishub.',
        SL::Selesai => 'Perlengkapan sudah diperbaiki. Terima kasih sudah melapor!',
        SL::Ditolak => 'Laporan tidak ditindaklanjuti: '.str($l->alasan_tolak)->lower().'.',
        SL::Dibatalkan => 'Laporan dibatalkan pelapor.',
    } : null;
@endphp

<div class="min-h-[calc(100dvh-56px)] bg-surface-muted pb-10 lg:min-h-0 lg:py-12">
    {{-- Kepala HP --}}
    <div class="sticky top-0 z-[1000] flex h-14 items-center gap-1 border-b border-line bg-bg px-2 lg:hidden">
        <a href="{{ url('/') }}" wire:navigate class="grid size-11 place-items-center" aria-label="Kembali">@svg('lucide-arrow-left', 'size-[22px]')</a>
        <span class="flex-1 text-base font-semibold">{{ $l || ! $it ? 'Laporan Kerusakan' : 'Laporkan Kerusakan' }}</span>
        @if ($l)
            <button x-data @click="navigator.share ? navigator.share({ title: '{{ $l->nomor }}', url: '{{ $linkStatus }}' }) : (navigator.clipboard.writeText('{{ $linkStatus }}'), $dispatch('toast', { pesan: 'Link disalin' }))" class="grid size-11 place-items-center" aria-label="Bagikan">@svg('lucide-share-2', 'size-5')</button>
        @endif
    </div>

    <div class="mx-auto flex max-w-xl flex-col gap-4 px-4 pt-4 lg:pt-0">
    @if (! $it)
        {{-- Nomor laporan tidak ditemukan --}}
        <div class="flex flex-col gap-4 rounded-md bg-bg p-6">
            <span class="grid size-[72px] place-items-center rounded-full bg-warning-bg text-warning">@svg('lucide-search-x', 'size-8')</span>
            <h1 class="judul text-[36px] leading-[38px]">Nomor tidak ditemukan</h1>
            <p class="text-base leading-6 text-fg-muted">{{ $nomor }} belum ada di sistem. Nomor laporan kerusakan berformat LPR-tahun-5 angka, mis. LPR-{{ date('Y') }}-00012.</p>
            <a href="{{ url('/') }}" wire:navigate class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Kembali ke Beranda</a>
        </div>
    @else
        @if ($l)
            {{-- ── Status laporan ── --}}
            @if (session('laporan_baru'))
                <div class="flex items-start gap-3 rounded-md bg-success-bg p-4 text-success">
                    @svg('lucide-circle-check', 'mt-0.5 size-5 shrink-0')
                    <span class="flex flex-col gap-0.5"><span class="text-base font-semibold">Terima kasih, laporan terkirim!</span><span class="text-sm text-fg">Simpan nomor di bawah untuk melihat perkembangannya.{{ $l->kontak_email ? ' Kabar juga kami kirim ke '.$l->kontak_email.'.' : '' }}</span></span>
                </div>
            @endif
            <div class="flex flex-col gap-5 rounded-md bg-bg p-5">
                <div class="flex items-end gap-3" x-data>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-caption text-xs font-semibold uppercase tracking-[0.6px] text-accent">Nomor laporan</span>
                        <h1 class="judul whitespace-nowrap text-[32px] leading-9 sm:text-[40px] sm:leading-10">{{ $l->nomor }}</h1>
                    </div>
                    <button @click="navigator.clipboard.writeText('{{ $l->nomor }}'); $dispatch('toast', { pesan: 'Nomor disalin' })" class="flex h-11 items-center gap-1.5 rounded-sm px-3.5 text-[15px] font-semibold text-accent outline outline-1 -outline-offset-1 outline-line">@svg('lucide-copy', 'size-4') Salin</button>
                </div>
                <div class="flex flex-col gap-2 rounded-md bg-surface-muted p-4">
                    <x-status :status="$l->status" class="w-fit" />
                    <p class="text-[15px] leading-[22px]">{{ $penjelasan }}</p>
                    @if ($l->catatan_selesai) <p class="text-[15px] leading-[22px] text-fg-muted">“{{ $l->catatan_selesai }}” <span class="font-caption text-xs">— Dishub</span></p> @endif
                </div>
                <x-pemeliharaan.stepper :laporan="$l" gaya="tegak" />
                @if (($hasil = $l->fotoJenis('hasil'))->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-semibold">Foto hasil perbaikan</span>
                        <div class="flex gap-2 overflow-x-auto">
                            @foreach ($hasil as $n => $f)
                                <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\LaporanFoto::galeri($hasil)), mulai: {{ $n }} })" class="shrink-0" aria-label="Lihat foto perbaikan"><img src="{{ $f->url() }}" class="h-24 rounded-sm object-cover" alt="Foto hasil perbaikan"></button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="flex gap-2" x-data>
                    <button @click="navigator.clipboard.writeText('{{ $linkStatus }}'); $dispatch('toast', { pesan: 'Link disalin' })" class="flex h-12 flex-1 items-center justify-center gap-2 rounded-sm text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-link', 'size-4') Salin link</button>
                    <a href="https://wa.me/?text={{ urlencode("Laporan kerusakan {$l->nomor}: {$linkStatus}") }}" target="_blank" class="flex h-12 flex-1 items-center justify-center gap-2 rounded-sm bg-success text-[15px] font-semibold text-fg-inverse shadow-sm">@svg('lucide-message-circle', 'size-4') WhatsApp</a>
                </div>
            </div>
        @endif

        {{-- ── Perlengkapan yang dilaporkan ── --}}
        <div class="flex flex-col overflow-hidden rounded-md bg-bg">
            <div class="h-40" wire:ignore x-data="petaMini({{ (float) ($it->lat_terpasang ?? $u->lat) }}, {{ (float) ($it->lng_terpasang ?? $u->lng) }}, @js(svg('lucide-'.$it->jenis->ikon, 'size-4')->toHtml()))"></div>
            <div class="flex items-center gap-3 p-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-md text-fg-inverse" style="background: {{ $it->jenis->warna }}">@svg('lucide-'.$it->jenis->ikon, 'size-5')</span>
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-base font-semibold">{{ $it->jenis->namaPendek() }}</span>
                    <span class="text-sm text-fg-muted">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
                    <span class="font-caption text-xs text-fg-muted">Dipasang {{ $it->terpasang_at?->translatedFormat('j M Y') }} · usulan <a href="{{ route('lacak', $u->nomor) }}" wire:navigate class="font-semibold text-accent">{{ $u->nomor }}</a></span>
                </span>
            </div>
            @if ($l && ($fp = $l->fotoJenis('pelapor'))->isNotEmpty())
                <div class="flex flex-col gap-2 border-t border-line p-4">
                    <span class="flex items-center gap-2 text-sm font-semibold">@svg('lucide-'.(\App\Models\LaporanKerusakan::KERUSAKAN[$l->kerusakan][2] ?? 'wrench'), 'size-4 text-warning') {{ $l->kerusakanLabel() }}</span>
                    @if ($l->catatan) <p class="text-sm text-fg-muted">{{ $l->catatan }}</p> @endif
                    <div class="flex gap-2 overflow-x-auto">
                        @foreach ($fp as $n => $f)
                            <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\LaporanFoto::galeri($fp)), mulai: {{ $n }} })" class="shrink-0" aria-label="Lihat foto laporan"><img src="{{ $f->url() }}" class="h-20 rounded-sm object-cover" alt="Foto laporan"></button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if (! $l && $terbuka)
            {{-- Sudah ada laporan yang sedang berjalan --}}
            <div class="flex flex-col gap-3 rounded-md bg-bg p-5">
                <span class="grid size-12 place-items-center rounded-full bg-warning-bg text-warning">@svg('lucide-wrench', 'size-6')</span>
                <h1 class="judul text-[28px] leading-[30px]">Sudah dilaporkan</h1>
                <p class="text-[15px] leading-[22px] text-fg-muted">Kerusakan di titik ini sudah dilaporkan ({{ $terbuka->created_at->translatedFormat('j M Y') }}) dan sedang ditangani Dishub. Tidak perlu melapor lagi.</p>
                <x-status :status="$terbuka->status" class="w-fit" />
                <a href="{{ route('lapor.status', $terbuka->nomor) }}" wire:navigate class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Lihat perkembangan {{ $terbuka->nomor }} @svg('lucide-arrow-right', 'size-[18px]')</a>
            </div>
        @elseif (! $l)
            {{-- ── Form lapor ── --}}
            <div class="flex flex-col gap-5 rounded-md bg-bg p-5"
                 x-data="{
                    mengunggah: false,
                    async pilih(e) {
                        const files = [...e.target.files];
                        if (!files.length) return;
                        this.mengunggah = true;
                        const koordinat = await Promise.all(files.map(f => window.koordinatFoto(f)));
                        $wire.set('fotoKoordinat', [...$wire.fotoKoordinat, ...koordinat], false);
                        $wire.uploadMultiple('foto', files, () => this.mengunggah = false, () => this.mengunggah = false);
                        e.target.value = '';
                    },
                 }">
                <div class="flex flex-col gap-1">
                    <h1 class="judul text-[32px] leading-[34px]">Laporkan kerusakan</h1>
                    <p class="text-[15px] leading-[22px] text-fg-muted">Tanpa perlu masuk akun. Foto kerusakannya, pilih jenisnya, lalu kirim ke Dishub.</p>
                </div>

                <div class="flex flex-col gap-2.5">
                    <span class="text-base font-semibold">Apa yang terjadi?<span class="text-danger">*</span></span>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Models\LaporanKerusakan::KERUSAKAN as $k => [$label, , $ikon])
                            <button type="button" wire:click="$set('kerusakan', '{{ $k }}')" @class([
                                'flex min-h-12 items-center gap-2 rounded-sm px-3 py-2 text-left text-sm font-medium outline -outline-offset-1',
                                'bg-accent-soft font-semibold text-accent outline-[1.5px] outline-accent' => $kerusakan === $k,
                                'outline-1 outline-line hover:bg-surface-muted' => $kerusakan !== $k,
                            ])>@svg('lucide-'.$ikon, 'size-[18px] shrink-0') {{ $label }}</button>
                        @endforeach
                    </div>
                    @error('kerusakan') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                </div>

                <div class="flex flex-col gap-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-base font-semibold">Foto kerusakan<span class="text-danger">*</span></span>
                        <span class="font-caption text-sm text-fg-muted">{{ count($foto) }}/{{ config('usuljalan.laporan_maks_foto') }}</span>
                    </div>
                    <div class="flex gap-2 overflow-x-auto">
                        @if (count($foto) < config('usuljalan.laporan_maks_foto'))
                            <label class="flex h-28 min-w-28 flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-md bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-accent">
                                @svg('lucide-camera', 'size-7') <span class="text-base font-semibold" x-text="mengunggah ? ($store.sinyal.ada ? 'Mengunggah…' : 'Menunggu sinyal…') : 'Ambil Foto'">Ambil Foto</span>
                                <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                            </label>
                        @endif
                        @foreach ($foto as $n => $f)
                            <div class="relative size-28 shrink-0 overflow-hidden rounded-md" wire:key="fp-{{ $n }}">
                                <img src="{{ $f->temporaryUrl() }}" class="size-full object-cover" alt="">
                                <button wire:click="hapusFoto({{ $n }})" class="absolute right-0 top-0 grid size-11 place-items-center" aria-label="Hapus foto"><span class="grid size-7 place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-4')</span></button>
                            </div>
                        @endforeach
                    </div>
                    @error('foto') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    @error('foto.*') <span class="text-sm text-danger">Berkas harus foto, maksimal 12 MB.</span> @enderror
                </div>

                <label class="flex flex-col gap-2">
                    <span class="text-base font-semibold">Keterangan @if ($kerusakan === 'lainnya')<span class="text-danger">*</span>@else<span class="text-sm font-normal text-fg-muted">opsional</span>@endif</span>
                    <textarea wire:model="catatan" rows="2" maxlength="500" class="input text-sm" placeholder="Mis. tiang bengkok tertabrak truk, lampu mati sejak 3 hari"></textarea>
                    @error('catatan') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-2">
                        <span class="text-base font-semibold">Nama <span class="text-sm font-normal text-fg-muted">opsional</span></span>
                        <input wire:model="nama" maxlength="80" autocomplete="name" class="input" placeholder="Nama Anda">
                    </label>
                    <label class="flex flex-col gap-2">
                        <span class="text-base font-semibold">Email <span class="text-sm font-normal text-fg-muted">opsional</span></span>
                        <input wire:model="email" type="email" inputmode="email" autocomplete="email" class="input" placeholder="nama@email.com">
                        @error('email') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </label>
                </div>
                <input wire:model="situs" type="text" name="situs" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">

                @error('kirim') <div class="flex items-start gap-2 rounded-sm bg-danger-bg p-3 text-sm text-danger">@svg('lucide-circle-alert', 'mt-px size-4 shrink-0') {{ $message }}</div> @enderror
                <button type="button" wire:click="kirim" :disabled="mengunggah" wire:loading.attr="disabled" wire:target="kirim"
                        class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60">
                    <span wire:loading.remove wire:target="kirim">Kirim Laporan</span><span wire:loading wire:target="kirim">Mengirim…</span> @svg('lucide-send', 'size-[18px]')
                </button>
                <p class="flex items-center gap-2 font-caption text-xs text-fg-muted">@svg('lucide-shield-check', 'size-4 shrink-0') Isi email untuk dikabari perkembangannya. Nama & email tidak ditampilkan di halaman publik.</p>
                <p class="font-caption text-xs text-fg-muted">Punya akun? <a href="{{ route('masuk') }}" wire:navigate class="font-semibold text-accent">Masuk</a> untuk melihat semua laporan Anda.</p>
            </div>
        @endif
    @endif
    </div>
</div>

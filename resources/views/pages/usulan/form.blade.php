<?php

use App\Enums\Kriteria;
use App\Enums\StatusUsulan;
use App\Models\Desa;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\Usulan;
use App\Models\UsulanFoto;
use App\Notifications\UsulanBerubah;
use App\Support\Panduan;
use App\Models\User;
use App\Enums\Role;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $usulanId = null;

    public int $langkah = 1;
    public bool $terkirim = false;

    /** Durasi panduan "Mengajukan usulan" untuk tautan "Pertama kali?"; null bila pengguna sudah pernah mengirim usulan. */
    #[Locked]
    public ?string $panduanDurasi = null;

    /** Id foto sebelum revisi; diganti bila pengusul mengambil foto baru. */
    #[Locked]
    public array $fotoLama = [];

    /** Bagian yang diperbaiki pada kiriman ulang terakhir (untuk layar sukses). */
    #[Locked]
    public array $bagianTerkirim = [];

    // ① Foto & lokasi
    public $unggah; // satu foto sementara (diunggah via $wire.upload)
    public ?float $lat = null;
    public ?float $lng = null;
    public string $alamat = '';
    public ?int $kecamatan_id = null;
    public ?int $desa_id = null;

    // ② Kebutuhan
    public array $pilihan = []; // jenis_id => jumlah
    public array $kriteria = [];
    public string $keterangan = '';

    // ③ Proposal
    public bool $proposal_otomatis = true;
    public $proposal;
    public string $penanggung_jawab = '';

    public function mount(?Usulan $usulan = null): void
    {
        $user = auth()->user();
        if (! $user->isPengusul()) {
            session()->flash('pesan', "Akun {$user->role->label()} tidak dipakai untuk mengajukan usulan. Gunakan email warga atau instansi.");
            $this->redirectRoute('usulan', navigate: true);

            return;
        }

        if ($usulan?->exists) {
            abort_unless($this->milikSaya($usulan) && in_array($usulan->status, [StatusUsulan::Draft, StatusUsulan::Revisi]), 403);
            $this->usulanId = $usulan->id;
            $this->fill($usulan->only(['lat', 'lng', 'kecamatan_id', 'desa_id', 'proposal_otomatis']));
            $this->alamat = $usulan->alamat ?? '';
            $this->keterangan = $usulan->keterangan ?? '';
            $this->penanggung_jawab = $usulan->penanggung_jawab ?? $user->name;
            $this->kriteria = $usulan->kriteria_klaim ?? [];
            $this->pilihan = $usulan->items->pluck('jumlah', 'jenis_perlengkapan_id')->all();
            $this->langkah = $this->langkahTersedia()[0];
            if ($usulan->status === StatusUsulan::Revisi && in_array('foto', $usulan->revisi_bagian ?? [])) {
                $diminta = $usulan->riwayat()->where('aksi', 'Dishub meminta revisi')->latest('id')->value('created_at');
                $this->fotoLama = $usulan->foto->filter(fn ($f) => ! $diminta || $f->created_at <= $diminta)->pluck('id')->all();
            }
        } else {
            $this->penanggung_jawab = $user->name;
            $this->proposal_otomatis = ! $user->instansi_id;
            if (! Usulan::where('user_id', $user->id)->where('status', '!=', StatusUsulan::Draft)->exists()) {
                $this->panduanDurasi = Panduan::ringkas('pengusul', 'ajukan-usulan')['durasi'] ?? null;
            }
        }
    }

    private function milikSaya(Usulan $u): bool
    {
        $user = auth()->user();

        return $user->instansi_id ? $u->instansi_id === $user->instansi_id : $u->user_id === $user->id;
    }

    #[Computed]
    public function usulan(): ?Usulan
    {
        return $this->usulanId ? Usulan::with('foto', 'items.jenis')->find($this->usulanId) : null;
    }

    /** Buat draft saat pertama kali ada isian, lalu simpan otomatis setiap perubahan. */
    private function simpan(): Usulan
    {
        $data = [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'alamat' => $this->alamat ?: null,
            'kecamatan_id' => $this->kecamatan_id,
            'desa_id' => $this->desa_id,
            'kriteria_klaim' => array_values($this->kriteria),
            'keterangan' => $this->keterangan ?: null,
            'proposal_otomatis' => $this->proposal_otomatis,
            'penanggung_jawab' => $this->penanggung_jawab ?: null,
        ];

        $usulan = $this->usulan ?? Usulan::create($data + [
            'user_id' => auth()->id(),
            'instansi_id' => auth()->user()->instansi_id,
        ]);
        $usulan->update($data);
        $this->usulanId = $usulan->id;

        $usulan->items()->whereNotIn('jenis_perlengkapan_id', array_keys($this->pilihan))->delete();
        foreach ($this->pilihan as $jenisId => $jumlah) {
            $usulan->items()->updateOrCreate(['jenis_perlengkapan_id' => $jenisId], ['jumlah' => max(1, (int) $jumlah)]);
        }
        unset($this->usulan);

        return $usulan;
    }

    public function updated($prop): void
    {
        if (in_array($prop, ['unggah', 'proposal', 'langkah'])) {
            return;
        }
        if ($prop === 'kecamatan_id') {
            $this->desa_id = null;
        }
        $this->simpan();
    }

    // ── Langkah ①
    public function simpanFoto(?float $lat, ?float $lng): void
    {
        $this->validate(['unggah' => 'image|max:12288'], [], ['unggah' => 'foto']);
        $usulan = $this->simpan();
        abort_if($usulan->foto()->count() >= config('usuljalan.maks_foto'), 422);

        $usulan->foto()->create([
            'path' => $this->unggah->store("usulan/{$usulan->id}", 'public'),
            'lat' => $lat,
            'lng' => $lng,
        ]);
        $this->unggah = null;
        unset($this->usulan);
    }

    public function hapusFoto(int $id): void
    {
        $foto = $this->usulan?->foto()->findOrFail($id);
        Storage::disk('public')->delete($foto->path);
        $foto->delete();
        unset($this->usulan);
    }

    public function aturLokasi(float $lat, float $lng, array $alamat = []): void
    {
        $this->lat = round($lat, 7);
        $this->lng = round($lng, 7);
        $this->alamat = $alamat['jalan'] ?? $this->alamat;

        if ($kec = Kecamatan::where('nama', 'like', trim($alamat['kecamatan'] ?? '-'))->first()) {
            $this->kecamatan_id = $kec->id;
            $this->desa_id = Desa::where('kecamatan_id', $kec->id)->where('nama', 'like', trim($alamat['desa'] ?? '-'))->value('id');
        }
        $this->simpan();
        $this->dispatch('lokasi-diatur', lat: $this->lat, lng: $this->lng);
    }

    #[Computed]
    public function berdekatan()
    {
        return $this->lat ? Usulan::sekitar($this->lat, $this->lng, config('usuljalan.radius_berdekatan'))
            ->reject(fn ($u) => $u->id === $this->usulanId) : collect();
    }

    // ── Langkah ②
    public function tambah(int $jenisId, int $delta): void
    {
        $jumlah = ($this->pilihan[$jenisId] ?? 0) + $delta;
        if ($jumlah <= 0) {
            unset($this->pilihan[$jenisId]);
        } else {
            $this->pilihan[$jenisId] = min($jumlah, 9999);
        }
        $this->simpan();
    }

    public function toggleKriteria(string $k): void
    {
        $this->kriteria = in_array($k, $this->kriteria)
            ? array_values(array_diff($this->kriteria, [$k]))
            : [...$this->kriteria, $k];
        $this->simpan();
    }

    // ── Navigasi
    /** Saat revisi, hanya langkah yang ditandai Dishub yang tampil. */
    public function langkahTersedia(): array
    {
        $bagian = $this->usulan?->status === StatusUsulan::Revisi ? ($this->usulan->revisi_bagian ?? []) : null;
        if (! $bagian) {
            return [1, 2, 3];
        }
        $peta = ['foto' => 1, 'lokasi' => 1, 'kebutuhan' => 2, 'proposal' => 3];

        return collect($bagian)->map(fn ($b) => $peta[$b] ?? 3)->push(3)->unique()->sort()->values()->all();
    }

    public function lanjut(): void
    {
        if (! $this->validasiLangkah($this->langkah)) {
            return;
        }
        $daftar = $this->langkahTersedia();
        $this->langkah = $daftar[array_search($this->langkah, $daftar) + 1] ?? 3;
    }

    public function kembali(): void
    {
        $daftar = $this->langkahTersedia();
        $i = array_search($this->langkah, $daftar);
        $i > 0 ? $this->langkah = $daftar[$i - 1] : $this->redirectRoute('usulan', navigate: true);
    }

    private function validasiLangkah(int $n): bool
    {
        $this->resetErrorBag();
        if ($n === 1) {
            if (! $this->usulan?->foto->count()) {
                $this->addError('foto', 'Tambahkan minimal 1 foto supaya Dishub bisa melihat lokasinya.');
            } elseif ($this->fotoLama && $this->usulan->foto->whereNotIn('id', $this->fotoLama)->isEmpty()) {
                $this->addError('foto', 'Ambil minimal 1 foto baru.');
            }
            if (! $this->lat) {
                $this->addError('lokasi', 'Tandai lokasi di peta dulu.');
            }
            if (! $this->kecamatan_id) {
                $this->addError('kecamatan_id', 'Pilih kecamatan.');
            }
        }
        if ($n === 2 && ! $this->pilihan) {
            $this->addError('pilihan', 'Pilih minimal satu perlengkapan.');
        }
        if ($n === 3) {
            if (auth()->user()->instansi_id) {
                $this->proposal_otomatis = false;
            }
            if (! $this->proposal_otomatis && ! $this->proposal && ! $this->usulan?->proposal_path) {
                $this->addError('proposal', 'Unggah surat/proposal (PDF).');
            }
            if ($this->proposal) {
                $this->validate(['proposal' => 'file|mimes:pdf|max:5120'], [], ['proposal' => 'proposal']);
            }
        }
        return $this->getErrorBag()->isEmpty();
    }

    public function kirim(): void
    {
        // Revisi tanpa bagian proposal: pakai proposal otomatis bila belum ada berkas.
        if ($this->usulan?->status === StatusUsulan::Revisi && ! $this->usulan->proposal_path && ! auth()->user()->instansi_id) {
            $this->proposal_otomatis = true;
        }
        foreach ([1, 2, 3] as $n) {
            if (! $this->validasiLangkah($n)) {
                $this->langkah = $n;
                $this->dispatch('toast', pesan: collect($this->getErrorBag()->all())->first(), gagal: true);

                return;
            }
        }

        $usulan = $this->simpan();
        $revisi = $usulan->status === StatusUsulan::Revisi;
        $this->bagianTerkirim = $revisi ? ($usulan->revisi_bagian ?? []) : [];
        $bagianRevisi = collect($this->bagianTerkirim)->map(fn ($b) => ucfirst($b))->implode(', ');
        if ($revisi && $this->fotoLama) {
            $usulan->foto()->whereKey($this->fotoLama)->get()->each(function ($f) {
                Storage::disk('public')->delete($f->path);
                $f->delete();
            });
        }

        if ($this->proposal) {
            $usulan->proposal_path = $this->proposal->store("usulan/{$usulan->id}", 'local');
        } elseif ($this->proposal_otomatis) {
            $usulan->load('items.jenis', 'desa', 'kecamatan', 'user', 'instansi', 'foto');
            $path = "usulan/{$usulan->id}/proposal-otomatis.pdf";
            Storage::disk('local')->put($path, Pdf::loadView('pdf.proposal', ['usulan' => $usulan])->output());
            $usulan->proposal_path = $path;
        }
        $usulan->save();

        $usulan->kirim();
        if ($revisi) {
            $usulan->increment('revisi_ke');
        }
        $usulan->catat($revisi ? 'Revisi dikirim ulang' : 'Usulan diajukan');

        Notification::send(
            User::where('role', Role::Dishub)->where('aktif', true)->get(),
            $revisi
                ? new UsulanBerubah($usulan, 'Revisi dikirim ulang', auth()->user()->name." memperbaiki {$bagianRevisi} pada {$usulan->nomor} · revisi ke-{$usulan->revisi_ke}", 'revisi_ulang')
                : new UsulanBerubah($usulan, 'Usulan baru masuk', collect([$usulan->alamat, $usulan->desa ? 'Ds. '.$usulan->desa->nama : null])->filter()->implode(', ').' · '.$usulan->items()->count().' item', 'baru'),
        );

        unset($this->usulan);
        $this->terkirim = true;
        $this->js('window.scrollTo({ top: 0 })');
    }

    public function with(): array
    {
        return [
            'jenis' => JenisPerlengkapan::aktif()->get(),
            'kecamatan' => Kecamatan::orderBy('nama')->get(),
            'desa' => $this->kecamatan_id ? Desa::where('kecamatan_id', $this->kecamatan_id)->orderBy('nama')->get() : collect(),
        ];
    }

    public function render()
    {
        return $this->view()->title('Ajukan Usulan');
    }
};
?>

@php
    $u = $this->usulan;
    $daftarLangkah = $this->langkahTersedia();
    $posisi = array_search($langkah, $daftarLangkah) + 1;
    $revisi = $u?->status === \App\Enums\StatusUsulan::Revisi;
    $bagian = $revisi ? ($u->revisi_bagian ?? []) : [];
    $lihat = fn ($b, $n) => $revisi ? in_array($b, $bagian) : $langkah === $n;
    $namaBagian = ['foto' => 'Foto', 'lokasi' => 'Lokasi', 'kebutuhan' => 'Kebutuhan', 'proposal' => 'Proposal'];
    $tagPerbaiki = fn ($ikon) => '<span class="flex items-center gap-1 rounded-sm bg-warning px-2 py-[3px] font-caption text-[11px] font-bold text-fg-inverse">'.svg('lucide-'.$ikon, 'size-3')->toHtml().'Perlu diperbaiki</span>';
@endphp

<div class="mx-auto max-w-2xl px-4 pb-40 pt-3 lg:pt-8">
@if ($terkirim)
    @php
        $kirimUlang = $u->revisi_ke > 0;
        $fotoBaru = $u->foto->sortByDesc('id')->first();
        $diperbaiki = collect($bagianTerkirim)->map(fn ($b) => $namaBagian[$b] ?? $b);
        $diminta = $kirimUlang ? $u->riwayat()->where('aksi', 'Dishub meminta revisi')->latest('id')->value('created_at') : null;
        $jmlFotoBaru = $diminta ? $u->foto->where('created_at', '>', $diminta)->count() : 0;
        $ringkasRevisi = collect($bagianTerkirim)->map(fn ($b) => $b === 'foto' && $jmlFotoBaru
            ? "Foto diperbarui · {$jmlFotoBaru} foto baru" : ($namaBagian[$b] ?? $b).' diperbarui')->implode(' · ');
        $tahapSekarang = 2;
    @endphp
    <div class="-mx-4 flex min-h-[calc(100dvh-40px)] flex-col pb-40 lg:mx-0 lg:min-h-0">
        <div class="flex items-center justify-between px-4 py-1">
            <span class="flex items-center gap-1.5 rounded-sm bg-success-bg px-2.5 py-1.5 font-caption text-[13px] font-medium text-success">@svg('lucide-circle-check', 'size-3.5') {{ $kirimUlang ? 'Revisi dikirim' : 'Dikirim' }} {{ $u->updated_at->translatedFormat('j M Y · H.i') }}</span>
            <a href="{{ route('usulan') }}" wire:navigate class="grid size-11 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line" aria-label="Tutup">@svg('lucide-x', 'size-5')</a>
        </div>

        <div class="flex flex-col gap-4 px-4 pb-6 pt-3" x-data x-init="$refs.centang?.animate([{ transform: 'scale(.4)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }], { duration: 400, easing: 'cubic-bezier(.2,1.4,.4,1)' })">
            @unless ($kirimUlang)
                <span x-ref="centang" class="grid size-20 place-items-center rounded-full bg-accent text-fg-inverse shadow-lg">@svg('lucide-check', 'size-11')</span>
            @endunless
            <div class="flex flex-col gap-2">
                <h1 class="judul text-5xl leading-[48px]">{{ $kirimUlang ? '✓ Revisi terkirim' : 'Usulan terkirim!' }}</h1>
                <p class="text-base leading-[23px] text-fg-muted">Terima kasih, {{ str(auth()->user()->name)->before(' ') }}. {{ $kirimUlang ? ($diperbaiki->all() === ['Foto'] ? 'Foto barumu' : 'Perbaikanmu').' sudah diteruskan ke Dishub untuk diperiksa ulang.' : 'Usulanmu sudah kami terima dan diteruskan ke Dishub.' }}</p>
            </div>
        </div>

        <div class="flex flex-col gap-3.5 bg-surface-soft px-4 py-[18px]" x-data>
            <div class="flex items-end gap-3">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="font-caption text-xs font-semibold uppercase tracking-[1px] text-fg-muted">Nomor usulan</span>
                    <span class="judul text-[34px] leading-9">{{ $u->nomor }}</span>
                </div>
                <button x-on:click="navigator.clipboard.writeText('{{ $u->nomor }}'); $dispatch('toast', { pesan: 'Nomor disalin' })" class="flex h-11 items-center gap-1.5 rounded-sm bg-bg px-3.5 text-base font-semibold text-accent shadow-sm outline outline-1 -outline-offset-1 outline-line">@svg('lucide-copy', 'size-[18px]') Salin</button>
            </div>
            <span class="h-px bg-fg/10"></span>
            <div class="flex items-center gap-3">
                @if ($fotoBaru) <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($u->foto)), mulai: {{ (int) $u->foto->values()->search(fn ($f) => $f->id === $fotoBaru->id) }} })" class="shrink-0" aria-label="Lihat foto"><img src="{{ $fotoBaru->url() }}" class="size-14 rounded-md object-cover" alt=""></button> @endif
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-base font-semibold">{{ $u->alamat ?: $u->lokasiSingkat() }}</span>
                    <span class="text-sm leading-5 text-fg-muted">{{ $kirimUlang ? ($ringkasRevisi ?: 'Bagian yang diperbaiki sudah dikirim') : $u->items->map(fn ($i) => $i->jenis->namaPendek().' ×'.$i->jumlah)->implode(' · ') }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 px-4 pb-3 pt-5">
            <div class="flex items-center justify-between">
                <span class="judul text-xl tracking-[0.5px]">Perjalanan usulan</span>
                <span class="font-caption text-[13px] font-medium text-fg-muted">Langkah {{ $kirimUlang ? 2 : 1 }} dari 4</span>
            </div>
            <div class="flex gap-1">
                @foreach (['Diajukan', 'Diperiksa', 'Keputusan', 'Terpasang'] as $n => $l)
                    @php $selesai = $kirimUlang && $n === 0; $kini = $kirimUlang ? $n === 1 : $n === 0; @endphp
                    <div class="flex flex-1 flex-col gap-1.5">
                        <span @class(['h-1 rounded-[2px]', 'bg-success' => $selesai, 'bg-accent' => $kini, 'bg-line' => ! $selesai && ! $kini])></span>
                        <span @class(['font-caption text-[13px]', 'font-medium text-success' => $selesai, 'font-semibold' => $kini, 'font-medium text-fg-muted' => ! $selesai && ! $kini])>{{ $l }}{{ $selesai ? ' ✓' : '' }}</span>
                    </div>
                @endforeach
            </div>
            @if ($kirimUlang)
                <div class="flex items-center gap-2.5 rounded-sm bg-accent-soft px-3 py-2.5 text-sm font-semibold text-accent">@svg('lucide-refresh-ccw', 'size-4') Sekarang: Diperiksa Dishub · revisi ke-{{ $u->revisi_ke }}</div>
            @endif
            <div class="flex gap-2.5 pt-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent-soft text-accent">@svg('lucide-bell-ring', 'size-4')</span>
                <p class="text-sm leading-5 text-fg-muted">{{ $kirimUlang ? 'Dishub cukup memeriksa ulang bagian '.($diperbaiki->isNotEmpty() ? $diperbaiki->implode(', ') : 'yang diperbaiki').' · biasanya ± 3 hari. Kami kabari lewat email.' : 'Kami kabari lewat email saat ada perkembangan · biasanya diperiksa ± 7 hari.' }}</p>
            </div>
        </div>

        <div class="fixed inset-x-0 bottom-0 z-[900] flex flex-col gap-2 border-t border-line bg-bg px-4 pb-7 pt-3 lg:static lg:mt-4 lg:border-0 lg:px-4">
            @if ($kirimUlang)
                <a href="{{ route('usulan') }}" wire:navigate class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Kembali ke Usulan Saya @svg('lucide-arrow-right', 'size-[18px]')</a>
                <a href="{{ route('usulan', $u) }}" wire:navigate class="flex h-12 items-center justify-center gap-2 rounded-sm text-base font-semibold outline outline-1 -outline-offset-1 outline-line">@svg('lucide-eye', 'size-[18px]') Lihat Detail Usulan</a>
            @else
                <a href="https://wa.me/?text={{ urlencode('Usulan jalan saya '.$u->nomor.': '.route('lacak', $u->nomor)) }}" target="_blank" class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Bagikan ke WhatsApp @svg('lucide-message-circle', 'size-[18px]')</a>
                <a href="{{ route('usulan', $u) }}" wire:navigate class="flex h-12 items-center justify-center gap-2 rounded-sm text-base font-semibold outline outline-1 -outline-offset-1 outline-line">@svg('lucide-file-text', 'size-[18px]') Lihat Usulan Saya</a>
            @endif
        </div>
    </div>
@else
    {{-- ── Kepala: kembali, langkah, tersimpan otomatis, progres ── --}}
    <div class="sticky top-0 z-[800] -mx-4 flex flex-col gap-3 bg-bg px-4 pb-3 pt-1 lg:static lg:mx-0 lg:px-0">
        <div class="flex items-center gap-3">
            <button wire:click="kembali" class="grid size-10 shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted" aria-label="Kembali">@svg('lucide-arrow-left', 'size-5')</button>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted">{{ $revisi ? $u->nomor.' · revisi ke-'.($u->revisi_ke + 1) : 'Ajukan usulan' }}</span>
                <span class="text-base font-semibold">{{ $revisi ? 'Perbaiki Usulan' : 'Langkah '.$posisi.' dari '.count($daftarLangkah) }}</span>
            </div>
            @if ($u)
                <span x-data="{ diHp: null }" @antre-offline.window="diHp = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })" @antre-terkirim.window="diHp = null" class="contents">
                    <span x-show="diHp" x-cloak class="flex items-center gap-1 rounded-sm bg-warning-bg px-2 py-[5px] font-caption text-[11px] font-medium text-warning">@svg('lucide-hard-drive-download', 'size-3.5') Tersimpan di HP · <span x-text="diHp"></span></span>
                    <span x-show="! diHp">
                        <span wire:loading.flex class="hidden items-center gap-1 rounded-sm bg-bg px-2 py-[5px] font-caption text-[11px] font-medium text-fg-muted outline outline-1 -outline-offset-1 outline-line">@svg('lucide-loader-circle', 'size-3.5 animate-spin') Menyimpan…</span>
                        <span wire:loading.remove class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-[5px] font-caption text-[11px] font-medium text-success">@svg('lucide-cloud-check', 'size-3.5') {{ $revisi ? 'Tersimpan ✓' : 'Tersimpan otomatis' }}</span>
                    </span>
                </span>
            @endif
        </div>
        <div @class(['flex gap-1.5', 'hidden' => $revisi])>
            @foreach ($daftarLangkah as $i => $_)
                <span @class(['h-1 flex-1 rounded-[2px] transition', 'bg-accent' => $i < $posisi, 'bg-line' => $i >= $posisi])></span>
            @endforeach
        </div>
    </div>

    @if ($revisi)
        @php $minta = $u->riwayat()->where('aksi', 'Dishub meminta revisi')->latest('id')->with('user')->first(); @endphp
        <div class="mt-3 flex flex-col gap-2 rounded-md bg-warning-bg p-3.5 outline outline-1 -outline-offset-1 outline-[#E3B23C]">
            <div class="flex items-center gap-2">
                <span class="grid size-7 place-items-center rounded-full bg-accent-deep font-caption text-[10px] font-bold text-fg-inverse">{{ $minta?->user ? str($minta->user->name)->explode(' ')->take(2)->map(fn ($k) => $k[0])->implode('') : 'DS' }}</span>
                <div class="flex flex-col font-caption text-xs">
                    <span class="font-semibold">Catatan dari {{ $minta?->user?->name ?? 'petugas' }} · Dishub</span>
                    <span class="text-fg-muted">{{ $minta?->created_at?->isToday() ? 'Hari ini, '.$minta->created_at->format('H.i') : $minta?->created_at?->translatedFormat('j M, H.i') }}</span>
                </div>
            </div>
            <p class="text-[15px] font-medium leading-[22px]">“{{ $u->revisi_catatan }}”</p>
        </div>

        @if (in_array('foto', $bagian))
            @php
                $lama = $u->foto->whereIn('id', $fotoLama);
                $baru = $u->foto->whereNotIn('id', $fotoLama);
            @endphp
            <div class="mt-4 flex flex-col gap-3" x-data="{
                    mengunggah: false,
                    async pilih(e) {
                        for (const file of e.target.files) {
                            this.mengunggah = true;
                            const k = await window.koordinatFoto(file);
                            await new Promise((ok, gagal) => $wire.upload('unggah', file, ok, gagal));
                            await $wire.simpanFoto(k?.lat ?? null, k?.lng ?? null);
                        }
                        this.mengunggah = false;
                        e.target.value = '';
                    }
                }">
                <div class="flex items-end justify-between"><h1 class="judul text-[30px] leading-[30px]">Ganti foto</h1>{!! $tagPerbaiki('camera') !!}</div>
                @if ($lama->isNotEmpty())
                    <div class="flex items-center gap-2.5">
                        @foreach ($lama->take(3)->values() as $n => $f)
                            <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($lama)), mulai: {{ $n }} })" class="relative h-[52px] w-16 shrink-0 overflow-hidden rounded-sm opacity-55 hover:opacity-80" aria-label="Lihat foto lama">
                                <img src="{{ $f->url() }}" class="size-full object-cover" alt="">
                                <span class="absolute left-1 top-1 grid size-[18px] place-items-center rounded-full bg-danger text-fg-inverse">@svg('lucide-x', 'size-3')</span>
                            </button>
                        @endforeach
                        <div class="flex flex-col gap-0.5">
                            <span class="text-[13px] font-semibold">{{ $lama->count() }} foto lama akan diganti</span>
                            <span class="font-caption text-xs text-danger">Ditandai Dishub kurang jelas</span>
                        </div>
                    </div>
                @endif
                <label class="flex cursor-pointer items-center gap-3.5 rounded-md bg-accent p-4 text-fg-inverse shadow-sm">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-fg-inverse/15">@svg('lucide-camera', 'size-6')</span>
                    <span class="flex flex-col gap-0.5">
                        <span class="text-[17px] font-bold" x-text="mengunggah ? 'Mengunggah…' : 'Ambil Foto Lagi'">Ambil Foto Lagi</span>
                        <span class="font-caption text-xs leading-4 text-fg-inverse/85">Berdiri di jalan, arahkan ke lokasi yang diminta</span>
                    </span>
                    <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                </label>
                <div class="flex items-center justify-between"><span class="text-sm font-semibold">Foto baru</span><span class="font-caption text-xs text-fg-muted">{{ $baru->count() }} dari {{ config('usuljalan.maks_foto') }} foto</span></div>
                <div class="flex gap-2">
                    @foreach ($baru->values() as $n => $f)
                        <div class="relative h-24 flex-1 overflow-hidden rounded-md outline outline-2 -outline-offset-1 outline-success" wire:key="fb-{{ $f->id }}">
                            <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($baru)), mulai: {{ $n }} })" class="block size-full" aria-label="Lihat foto"><img src="{{ $f->url() }}" class="size-full object-cover" alt=""></button>
                            <span class="absolute left-1.5 top-1.5 flex items-center gap-[3px] rounded-sm bg-success px-1.5 py-0.5 font-caption text-[10px] font-bold text-fg-inverse">@svg('lucide-check', 'size-[11px]') Baru</span>
                            <button wire:click="hapusFoto({{ $f->id }})" class="absolute right-1.5 top-1.5 grid size-[22px] place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-3')</button>
                        </div>
                    @endforeach
                    <label class="flex h-24 w-[72px] shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-md bg-accent-soft text-accent outline outline-1 -outline-offset-1 outline-accent">
                        @svg('lucide-image-plus', 'size-5') <span class="font-caption text-[11px] font-semibold">Galeri</span>
                        <input type="file" accept="image/*" multiple class="hidden" @change="pilih">
                    </label>
                </div>
                @error('foto') <div class="text-sm text-danger">{{ $message }}</div> @enderror
            </div>
        @endif
    @endif

    {{-- ── ① Foto & Lokasi ── --}}
    @if ($lihat('lokasi', 1))
        <div class="mt-4 flex flex-col gap-4">
            @if ($revisi)
                <div class="flex items-end justify-between"><h1 class="judul text-[30px] leading-[30px]">Perbaiki lokasi</h1>{!! $tagPerbaiki('map-pin') !!}</div>
            @else
            <div class="flex flex-col gap-1">
                <h1 class="judul text-[34px] leading-9">Foto & lokasi</h1>
                <p class="text-sm text-fg-muted">Foto jalannya, lokasi terisi sendiri.</p>
                @if ($panduanDurasi)
                    <a href="{{ route('panduan.artikel', ['pengusul', 'ajukan-usulan']) }}" target="_blank" rel="noopener" class="flex w-fit items-center gap-1.5 pt-1.5 text-sm font-semibold text-accent hover:underline">@svg('lucide-book-open', 'size-4') Pertama kali? Lihat panduan {{ $panduanDurasi }} @svg('lucide-arrow-right', 'size-3.5')</a>
                @endif
            </div>

            <div class="flex flex-col gap-2" x-data="{
                    mengunggah: false,
                    async pilih(e) {
                        for (const file of e.target.files) {
                            this.mengunggah = true;
                            const k = await window.koordinatFoto(file);
                            await new Promise((ok, gagal) => $wire.upload('unggah', file, ok, gagal));
                            await $wire.simpanFoto(k?.lat ?? null, k?.lng ?? null);
                            if (k && !$wire.lat) await $wire.aturLokasi(k.lat, k.lng, await window.alamatDari(k.lat, k.lng));
                        }
                        this.mengunggah = false;
                        e.target.value = '';
                        if (!$wire.lat) $dispatch('butuh-lokasi');
                    }
                }">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold">Foto lokasi</span>
                    <span class="font-caption text-xs text-fg-muted">{{ $u?->foto->count() ?? 0 }} dari {{ config('usuljalan.maks_foto') }} foto</span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($u?->foto->values() ?? [] as $n => $f)
                        @php $jauh = $f->lat && $lat && \App\Models\Usulan::jarak($f->lat, $f->lng, $lat, $lng) > config('usuljalan.jarak_foto_maks'); @endphp
                        <div class="relative h-[92px] overflow-hidden rounded-md bg-surface-muted" wire:key="foto-{{ $f->id }}">
                            <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($u->foto)), mulai: {{ $n }} })" class="block size-full" aria-label="Lihat foto"><img src="{{ $f->url() }}" class="size-full object-cover" alt=""></button>
                            <button wire:click="hapusFoto({{ $f->id }})" class="absolute right-1.5 top-1.5 grid size-[22px] place-items-center rounded-full bg-fg/80 text-fg-inverse" aria-label="Hapus foto">@svg('lucide-x', 'size-3')</button>
                            <span @class(['absolute bottom-1.5 left-1.5 flex items-center gap-[3px] rounded-sm px-1.5 py-[3px] font-caption text-[10px] font-semibold', 'bg-fg/80 text-fg-inverse' => $f->lat && ! $jauh, 'bg-warning-bg text-warning' => $jauh, 'bg-bg/90 text-fg-muted' => ! $f->lat])>
                                @svg('lucide-map-pin', 'size-2.5') {{ ! $f->lat ? 'Tanpa GPS' : ($jauh ? 'Jauh' : 'GPS') }}
                            </span>
                        </div>
                    @endforeach
                    @if (($u?->foto->count() ?? 0) < config('usuljalan.maks_foto'))
                        <label @class(['flex h-[92px] cursor-pointer flex-col items-center justify-center gap-1 rounded-md outline -outline-offset-1', 'bg-danger-bg text-danger outline-[1.5px] outline-danger' => $errors->has('foto'), 'bg-accent-soft text-accent outline-1 outline-accent' => ! $errors->has('foto')])>
                            <span x-show="!mengunggah" class="flex flex-col items-center gap-1">@svg('lucide-camera', 'size-[22px]') <span class="font-caption text-xs font-semibold">{{ $u?->foto->count() ? 'Tambah foto' : 'Ambil foto' }}</span></span>
                            <span x-show="mengunggah" x-cloak class="font-caption text-xs font-semibold">Mengunggah…</span>
                            <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                        </label>
                    @endif
                </div>
                <label class="w-fit cursor-pointer font-caption text-xs font-semibold text-accent">atau pilih dari galeri <input type="file" accept="image/*" multiple class="hidden" @change="pilih"></label>
                @error('foto') <div class="flex items-center gap-1.5 text-[13px] text-danger">@svg('lucide-circle-alert', 'size-4 shrink-0') {{ $message }}</div> @enderror
                @error('unggah') <div class="text-sm text-danger">{{ $message }}</div> @enderror
            </div>
            @endif

            <div class="overflow-hidden rounded-md bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line">
                <div class="relative h-[166px]" wire:ignore
                     x-data="petaPilih({ lat: @js($lat), lng: @js($lng), pusat: @js(config('usuljalan.peta_awal')) })"
                     @butuh-lokasi.window="cariBuka = true; $nextTick(() => $refs.cari?.focus())"
                     @lokasi-diatur.window="taruh($event.detail.lat, $event.detail.lng, false, true)">
                    <div x-ref="peta" class="absolute inset-0"></div>
                    <span class="absolute bottom-2 left-2 z-[500] flex items-center gap-1.5 rounded-sm bg-bg px-2.5 py-1.5 font-caption text-xs font-medium shadow-sm">@svg('lucide-move', 'size-3.5') Geser pin jika kurang tepat</span>
                    <button type="button" @click="lokasiSaya" class="absolute right-2 top-2 z-[500] grid size-9 place-items-center rounded-sm bg-bg shadow-sm" aria-label="Lokasi saya">@svg('lucide-locate-fixed', 'size-[18px]')</button>
                    <button type="button" @click="cariBuka = !cariBuka" class="absolute right-2 top-[52px] z-[500] grid size-9 place-items-center rounded-sm bg-bg shadow-sm" aria-label="Cari alamat">@svg('lucide-search', 'size-[18px]')</button>
                    <div x-show="cariBuka" x-cloak class="absolute inset-x-2 top-2 z-[600] mr-11">
                        <input x-ref="cari" x-model="cari" @input.debounce.500ms="cariAlamat" class="w-full rounded-sm bg-bg px-3 py-2 text-sm shadow outline-none" placeholder="Cari alamat / nama tempat">
                        <ul x-show="hasil.length" class="mt-1 max-h-40 overflow-auto rounded-sm bg-bg shadow-lg">
                            <template x-for="h in hasil"><li><button type="button" @click="pilihHasil(h); cariBuka = false" class="w-full px-3 py-2 text-left text-xs hover:bg-surface-muted" x-text="h.display_name"></button></li></template>
                        </ul>
                    </div>
                </div>
                <div class="flex flex-col gap-2.5 p-3">
                    @if ($lat)
                        <div class="flex flex-col gap-1">
                            <span class="flex items-center gap-1 font-caption text-[10px] font-semibold uppercase tracking-[0.6px] text-accent">@svg('lucide-sparkles', 'size-3') Terisi otomatis{{ $u?->foto->whereNotNull('lat')->isNotEmpty() ? ' dari foto' : '' }}</span>
                            <input wire:model.live.blur="alamat" class="w-full bg-transparent text-[15px] font-semibold outline-none placeholder:font-normal placeholder:text-fg-muted" placeholder="Tulis nama jalan / patokan">
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                                <span class="font-caption text-[10px] text-fg-muted">Desa</span>
                                <select wire:model.live="desa_id" class="-ml-1 bg-transparent text-xs font-semibold outline-none">
                                    <option value="">Pilih…</option>@foreach ($desa as $d)<option value="{{ $d->id }}">{{ $d->nama }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                                <span class="font-caption text-[10px] text-fg-muted">Kecamatan</span>
                                <select wire:model.live="kecamatan_id" class="-ml-1 bg-transparent text-xs font-semibold outline-none">
                                    <option value="">Pilih…</option>@foreach ($kecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach
                                </select>
                            </label>
                            <div class="flex flex-col gap-0.5 rounded-sm bg-surface-muted px-2 py-1.5">
                                <span class="font-caption text-[10px] text-fg-muted">Koordinat</span>
                                <span class="truncate text-xs font-semibold">{{ number_format($lat, 3, '.', '') }}, {{ number_format($lng, 3, '.', '') }}</span>
                            </div>
                        </div>
                        @error('kecamatan_id') <div class="text-xs text-danger">{{ $message }}</div> @enderror
                    @else
                        <p class="text-[13px] text-fg-muted">Lokasi foto tidak terbaca? Tidak apa-apa — <b class="text-fg">tap peta</b> untuk menaruh pin, atau <button type="button" x-data @click="$dispatch('butuh-lokasi')" class="font-semibold text-accent">cari alamat</button>.</p>
                        @error('lokasi') <div class="text-xs text-danger">{{ $message }}</div> @enderror
                    @endif
                </div>
            </div>

            @if ($this->berdekatan->isNotEmpty())
                @php $b = $this->berdekatan->first(); @endphp
                <div class="flex gap-2.5 rounded-md bg-warning-bg p-3">
                    <span class="text-warning">@svg('lucide-info', 'size-[18px]')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="text-[13px] font-semibold">Ada {{ $this->berdekatan->count() }} usulan lain dalam ±{{ config('usuljalan.radius_berdekatan') }} m</span>
                        <span class="text-xs leading-[17px] text-fg-muted">{{ $b->items->map(fn ($i) => $i->jenis->namaPendek())->implode(', ') }} · diajukan {{ $b->diajukan_at?->translatedFormat('j M') }}. Hanya info — usulanmu tetap bisa dikirim.</span>
                    </div>
                    <a href="{{ route('lacak', $b->nomor) }}" target="_blank" class="font-caption text-xs font-semibold text-warning">Lihat</a>
                </div>
            @endif
        </div>
    @endif

    {{-- ── ② Apa yang dibutuhkan ── --}}
    @if ($lihat('kebutuhan', 2))
        <div class="mt-4 flex flex-col gap-[18px]">
            @if ($revisi)
                <div class="flex items-end justify-between"><h1 class="judul text-[30px] leading-[30px]">Perbaiki kebutuhan</h1>{!! $tagPerbaiki('package') !!}</div>
            @else
            <div class="flex flex-col gap-1">
                <h1 class="judul text-[34px] leading-9">Apa yang dibutuhkan?</h1>
                <p class="text-sm text-fg-muted">Ketuk semua yang perlu. Boleh lebih dari satu.</p>
            </div>
            @endif

            <div class="grid grid-cols-3 gap-2">
                @foreach ($jenis as $j)
                    @php $n = $pilihan[$j->id] ?? 0; @endphp
                    <button wire:click="tambah({{ $j->id }}, {{ $n ? -$n : 1 }})" wire:key="j-{{ $j->id }}" @class([
                        'relative flex h-[84px] flex-col items-center justify-center gap-1.5 rounded-md px-1.5 transition active:scale-95',
                        'bg-accent-soft text-accent outline outline-2 -outline-offset-1 outline-accent' => $n,
                        'bg-bg text-fg shadow-sm outline outline-1 -outline-offset-1 outline-line' => ! $n,
                    ])>
                        @if ($n) <span class="absolute right-1.5 top-1.5 grid size-[18px] place-items-center rounded-full bg-accent text-fg-inverse">@svg('lucide-check', 'size-3')</span> @endif
                        <span @class(['text-accent' => ! $n])>@svg('lucide-'.$j->ikon, 'size-7')</span>
                        <span @class(['w-full text-center font-caption text-xs leading-tight', 'font-semibold' => $n, 'font-medium' => ! $n])>{{ $j->namaPendek() }}</span>
                    </button>
                @endforeach
            </div>
            @error('pilihan') <div class="-mt-2 text-sm text-danger">{{ $message }}</div> @enderror

            @if ($pilihan)
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-semibold">Berapa banyak?</span>
                    @foreach ($jenis->whereIn('id', array_keys($pilihan)) as $j)
                        <div class="flex items-center gap-2.5 rounded-md py-2 pl-3 pr-2 outline outline-1 -outline-offset-1 outline-line" wire:key="n-{{ $j->id }}">
                            <span class="text-accent">@svg('lucide-'.$j->ikon, 'size-5')</span>
                            <div class="flex min-w-0 flex-1 flex-col gap-px">
                                <span class="truncate text-sm font-semibold">{{ $j->namaPendek() }}</span>
                                <span class="font-caption text-[11px] text-fg-muted">Jumlah {{ $j->satuan }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="tambah({{ $j->id }}, -1)" class="grid size-10 place-items-center rounded-sm bg-surface-muted" aria-label="Kurangi">@svg('lucide-minus', 'size-[18px]')</button>
                                <span class="w-8 text-center text-[17px] font-bold tabular-nums">{{ $pilihan[$j->id] }}</span>
                                <button wire:click="tambah({{ $j->id }}, 1)" class="grid size-10 place-items-center rounded-sm bg-accent text-fg-inverse" aria-label="Tambah">@svg('lucide-plus', 'size-[18px]')</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-col gap-2" x-data="{ lainnya: @js((bool) $keterangan) }">
                <span class="text-sm font-semibold">Kenapa dibutuhkan?</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (Kriteria::cases() as $k)
                        @php $on = in_array($k->value, $kriteria); @endphp
                        <button wire:click="toggleKriteria('{{ $k->value }}')" @class([
                            'flex items-center gap-1.5 rounded-full px-3 py-2 font-caption text-[13px] outline outline-1 -outline-offset-1 transition',
                            'bg-accent font-semibold text-fg-inverse outline-accent' => $on,
                            'bg-bg font-medium outline-line' => ! $on,
                        ])>@if ($on) @svg('lucide-check', 'size-3.5') @endif {{ $k->labelAwam() }}</button>
                    @endforeach
                    <button @click="lainnya = !lainnya" class="flex items-center gap-1.5 rounded-full bg-bg px-3 py-2 font-caption text-[13px] font-medium outline outline-1 -outline-offset-1 outline-line" :class="lainnya && 'outline-accent text-accent'">@svg('lucide-plus', 'size-3.5') Lainnya</button>
                </div>
                <textarea x-show="lainnya" x-cloak wire:model.live.blur="keterangan" rows="3" class="input mt-1 text-sm" placeholder="Ceritakan singkat kondisinya"></textarea>
            </div>
        </div>
    @endif

    {{-- ── ③ Proposal & Kirim ── --}}
    @if ($lihat('proposal', 3))
        @php $instansi = auth()->user()->instansi_id; @endphp
        <div class="mt-4 flex flex-col gap-4">
            @if ($revisi)
                <div class="flex items-end justify-between"><h1 class="judul text-[30px] leading-[30px]">Perbaiki proposal</h1>{!! $tagPerbaiki('file-text') !!}</div>
            @else
            <div class="flex flex-col gap-1">
                <h1 class="judul text-[34px] leading-9">Proposal & kirim</h1>
                <p class="text-sm text-fg-muted">{{ $instansi ? 'Unggah surat usulan resmi, lalu periksa ringkasannya.' : 'Pilih satu cara, lalu periksa ringkasannya.' }}</p>
            </div>
            @endif
            <div class="flex flex-col gap-2">
                @unless ($instansi)
                    <button wire:click="$set('proposal_otomatis', true)" @class(['flex items-center gap-3 rounded-md p-3.5 text-left outline -outline-offset-1', 'bg-accent-soft outline-2 outline-accent' => $proposal_otomatis, 'bg-bg outline-1 outline-line' => ! $proposal_otomatis])>
                        <span @class(['grid size-10 shrink-0 place-items-center rounded-sm', 'bg-accent text-fg-inverse' => $proposal_otomatis, 'bg-surface-muted' => ! $proposal_otomatis])>@svg('lucide-sparkles', 'size-5')</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                            <span class="flex flex-wrap items-center gap-1.5"><span class="whitespace-nowrap text-[15px] font-semibold">Buat proposal otomatis</span><span class="whitespace-nowrap rounded-sm bg-accent px-1.5 py-0.5 font-caption text-[10px] font-semibold text-fg-inverse">Paling cepat</span></span>
                            <span class="text-xs leading-[17px] text-fg-muted">Disusun dari foto & pilihanmu. Tinggal kirim.</span>
                        </span>
                        <span @class(['grid size-[22px] shrink-0 place-items-center rounded-full outline outline-2 -outline-offset-1', 'outline-accent' => $proposal_otomatis, 'outline-line' => ! $proposal_otomatis])>@if ($proposal_otomatis)<span class="size-2.5 rounded-full bg-accent"></span>@endif</span>
                    </button>
                @endunless
                <div @class(['flex flex-col gap-3 rounded-md p-3.5 outline -outline-offset-1', 'bg-accent-soft outline-2 outline-accent' => ! $proposal_otomatis, 'bg-bg outline-1 outline-line' => $proposal_otomatis])>
                    <button wire:click="$set('proposal_otomatis', false)" class="flex items-center gap-3 text-left">
                        <span @class(['grid size-10 shrink-0 place-items-center rounded-sm', 'bg-accent text-fg-inverse' => ! $proposal_otomatis, 'bg-surface-muted' => $proposal_otomatis])>@svg('lucide-file-up', 'size-5')</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                            <span class="text-[15px] font-semibold">Unggah surat (PDF)</span>
                            <span class="text-xs leading-[17px] text-fg-muted">Sudah punya surat dari desa/instansi? Maks. 5 MB.</span>
                        </span>
                        <span @class(['grid size-[22px] shrink-0 place-items-center rounded-full outline outline-2 -outline-offset-1', 'outline-accent' => ! $proposal_otomatis, 'outline-line' => $proposal_otomatis])>@if (! $proposal_otomatis)<span class="size-2.5 rounded-full bg-accent"></span>@endif</span>
                    </button>
                    @if (! $proposal_otomatis)
                        <input type="file" wire:model="proposal" accept="application/pdf" class="block w-full text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-accent file:px-3 file:py-2 file:text-fg-inverse">
                        <span wire:loading wire:target="proposal" class="text-xs text-fg-muted">Mengunggah…</span>
                        @if ($u?->proposal_path && ! $proposal) <span class="text-xs text-fg-muted">Sudah ada file sebelumnya. Unggah lagi untuk mengganti.</span> @endif
                    @endif
                </div>
                @error('proposal') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                @if ($proposal_otomatis && $u)
                    <a href="{{ route('usulan.pratinjau', $u) }}" target="_blank" class="flex w-fit items-center gap-1.5 px-0.5 py-1 font-caption text-[13px] font-semibold text-accent">@svg('lucide-eye', 'size-4') Lihat pratinjau proposal</a>
                @endif
                @if ($instansi)
                    <label class="flex flex-col gap-1.5"><span class="text-sm font-semibold">Penanggung jawab</span><input wire:model.live.blur="penanggung_jawab" class="input"></label>
                @endif
            </div>
        </div>

        @unless ($revisi)
        <div class="-mx-4 mt-4 flex flex-col gap-3.5 bg-surface-soft px-4 py-5 lg:mx-0 lg:rounded-md">
            <div class="flex items-end justify-between">
                <span class="judul text-[22px] tracking-[0.5px]">Ringkasan</span>
                <span class="font-caption text-[11px] text-fg-muted">Bisa diubah sebelum dikirim</span>
            </div>
            <div class="flex items-center gap-3">
                @if ($f = $u?->foto->first()) <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($u->foto)), mulai: 0 })" class="shrink-0" aria-label="Lihat foto"><img src="{{ $f->url() }}" class="size-[72px] rounded-md object-cover" alt=""></button> @endif
                <div class="flex min-w-0 flex-1 flex-col gap-[3px]">
                    <span class="font-caption text-[10px] font-semibold uppercase tracking-[0.6px] text-fg-muted">Lokasi · {{ $u?->foto->count() }} foto</span>
                    <span class="text-sm font-semibold">{{ $alamat ?: 'Tanpa nama jalan' }}</span>
                    <span class="font-caption text-xs text-fg-muted">{{ collect([$u?->desa ? 'Desa '.$u->desa->nama : null, $u?->kecamatan ? 'Kec. '.$u->kecamatan->nama : null])->filter()->implode(' · ') }}</span>
                </div>
                <button wire:click="$set('langkah', 1)" class="font-caption text-[13px] font-semibold text-accent">Ubah</button>
            </div>
            <span class="h-px bg-fg/10"></span>
            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="font-caption text-[10px] font-semibold uppercase tracking-[0.6px] text-fg-muted">Kebutuhan</span>
                    <button wire:click="$set('langkah', 2)" class="font-caption text-[13px] font-semibold text-accent">Ubah</button>
                </div>
                @foreach ($u?->items ?? [] as $i)
                    <div class="flex items-center gap-2.5 text-sm"><span class="flex-1 font-medium">{{ $i->jenis->namaPendek() }}</span><b>{{ $i->jumlah }} {{ $i->jenis->satuan }}</b></div>
                @endforeach
                @if ($kriteria)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($kriteria as $k) <span class="rounded-full bg-bg/70 px-2 py-1 font-caption text-[11px] font-medium">{{ Kriteria::from($k)->labelAwam() }}</span> @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endunless
    @endif

    @if ($revisi)
        @php $tetap = collect($namaBagian)->except($bagian); @endphp
        @if ($tetap->isNotEmpty())
            <div class="mt-5 rounded-md bg-surface-muted" x-data="{ buka: false }">
                <button type="button" @click="buka = !buka" class="flex w-full items-center gap-2.5 px-3.5 py-3 text-left">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-success-bg text-success">@svg('lucide-check', 'size-4')</span>
                    <span class="flex flex-1 flex-col gap-px">
                        <span class="text-[13px] font-semibold">{{ $tetap->implode(', ') }} tidak perlu diubah</span>
                        <span class="font-caption text-xs text-fg-muted">Sudah sesuai · hanya bisa dilihat</span>
                    </span>
                    <span class="text-fg-muted transition" :class="buka && 'rotate-180'">@svg('lucide-chevron-down', 'size-[18px]')</span>
                </button>
                <dl x-show="buka" x-collapse x-cloak class="flex flex-col gap-2.5 border-t border-line px-3.5 py-3 text-[13px]">
                    @if ($tetap->has('lokasi'))
                        <div class="flex gap-2"><dt class="w-20 shrink-0 text-fg-muted">Lokasi</dt><dd class="font-medium">{{ $u->alamat ?: $u->lokasiSingkat() }}</dd></div>
                    @endif
                    @if ($tetap->has('kebutuhan'))
                        <div class="flex gap-2"><dt class="w-20 shrink-0 text-fg-muted">Kebutuhan</dt><dd class="font-medium">{{ $u->items->map(fn ($i) => $i->jenis->nama.' ×'.$i->jumlah)->implode(', ') }}</dd></div>
                    @endif
                    @if ($tetap->has('proposal'))
                        <div class="flex gap-2"><dt class="w-20 shrink-0 text-fg-muted">Proposal</dt><dd class="font-medium">{{ $u->proposal_path ? 'Terlampir' : 'Dibuat otomatis' }}</dd></div>
                    @endif
                    @if ($tetap->has('foto'))
                        <div class="flex gap-2"><dt class="w-20 shrink-0 text-fg-muted">Foto</dt><dd class="font-medium">{{ $u->foto->count() }} foto</dd></div>
                    @endif
                </dl>
            </div>
        @endif
    @endif

    {{-- ── Tombol utama menempel di bawah ── --}}
    <div class="fixed inset-x-0 bottom-0 z-[900] border-t border-line bg-bg px-4 pb-7 pt-3 lg:pb-4">
        <div class="mx-auto flex max-w-2xl flex-col gap-2.5">
            @if ($langkah === 3 || $revisi)
                <span class="flex items-center justify-center gap-1.5 text-center font-caption text-[11px] text-fg-muted">@svg('lucide-history', 'size-3') {{ $revisi ? 'Dishub akan memeriksa ulang · kami kabari lewat email' : 'Status bisa dipantau di menu Lacak Usulan' }}</span>
                <button wire:click="kirim" wire:loading.attr="disabled" wire:target="kirim" class="flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3.5 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60">
                    <span wire:loading.remove wire:target="kirim">{{ $revisi ? 'Kirim Ulang' : 'Kirim Usulan' }}</span><span wire:loading wire:target="kirim">Mengirim…</span> @svg('lucide-send', 'size-[18px]')
                </button>
            @else
                <button wire:click="lanjut" class="flex items-center justify-center gap-2 rounded-sm bg-accent px-5 py-3.5 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">
                    {{ $langkah === 1 ? 'Lanjut: Pilih kebutuhan' : 'Lanjut: Proposal & kirim' }} @svg('lucide-arrow-right', 'size-[18px]')
                </button>
            @endif
        </div>
    </div>
@endif
</div>

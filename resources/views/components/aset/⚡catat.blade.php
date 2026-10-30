<?php

use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sheet "Catat pemeliharaan" satu unit perlengkapan terpasang (hanya Dishub). Dibuka lewat
 * `$dispatch('catat-pemeliharaan', aset: id)` dari halaman mana pun yang memuat komponen ini.
 */
new class extends Component
{
    use WithFileUploads;

    public ?int $asetId = null;
    public bool $terbuka = false;
    public string $kegiatan = 'rutin';
    public string $tanggal = '';
    public bool $ubahTanggal = false;
    public string $catatan = '';
    public $foto = [];
    public $tambah = [];

    public function mount(?int $asetId = null): void
    {
        $this->asetId = $asetId;
    }

    #[Computed]
    public function aset(): ?PerlengkapanTerpasang
    {
        return $this->asetId ? PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan')->aktif()->find($this->asetId) : null;
    }

    #[On('catat-pemeliharaan')]
    public function buka(?int $aset = null): void
    {
        abort_unless(Pemeliharaan::bolehMencatat(auth()->user()), 403);
        if ($aset) {
            $this->asetId = $aset;
            unset($this->aset);
        }
        abort_unless($this->aset, 404);
        $this->reset('kegiatan', 'ubahTanggal', 'catatan', 'foto', 'tambah');
        $this->resetErrorBag();
        $this->tanggal = today()->toDateString();
        $this->terbuka = true;
    }

    public function tutup(): void
    {
        $this->terbuka = false;
    }

    public function pilihKegiatan(string $k): void
    {
        if (array_key_exists($k, Pemeliharaan::PILIHAN)) {
            $this->kegiatan = $k;
        }
    }

    public function updatedTambah(): void
    {
        $this->foto = array_slice([...$this->foto, ...(array) $this->tambah], 0, 4);
        $this->tambah = [];
    }

    public function hapusFoto(int $n): void
    {
        array_splice($this->foto, $n, 1);
    }

    public function simpan(): void
    {
        abort_unless(Pemeliharaan::bolehMencatat(auth()->user()), 403);
        abort_unless($this->aset, 404);
        $this->validate([
            'kegiatan' => 'required|in:'.implode(',', array_keys(Pemeliharaan::PILIHAN)),
            'tanggal' => 'required|date|before_or_equal:today|after_or_equal:'.today()->subYear()->toDateString(),
            'catatan' => 'nullable|string|max:500',
            'foto' => 'array|max:4',
            'foto.*' => 'image|max:10240',
        ], [
            'tanggal.before_or_equal' => 'Tanggal tidak boleh lewat hari ini.',
            'tanggal.after_or_equal' => 'Tanggal paling lama setahun ke belakang.',
            'foto.*.image' => 'Berkas harus berupa foto.',
            'foto.*.max' => 'Foto maksimal 10 MB.',
        ]);

        $p = Pemeliharaan::catat($this->aset, $this->kegiatan, $this->catatan, $this->tanggal, $this->foto, auth()->user());
        $this->terbuka = false;
        $this->reset('foto', 'tambah', 'catatan');
        $this->dispatch('toast', pesan: 'Pemeliharaan dicatat', urungkan: 'pmh-catat:'.$p->id);
        $this->dispatch('pemeliharaan-dicatat', aset: $this->asetId);
    }

    #[On('urungkan')]
    public function urungkan(string $token): void
    {
        if (! str_starts_with($token, 'pmh-catat:')) {
            return;
        }
        abort_unless(Pemeliharaan::bolehMencatat(auth()->user()), 403);
        $p = Pemeliharaan::with('foto')->find((int) substr($token, 10));
        if (! $p) {
            return;
        }
        abort_unless($p->bolehDihapus(auth()->user()), 403);
        $asetId = $p->perlengkapan_terpasang_id;
        $p->hapusCatat();
        $this->dispatch('toast', pesan: 'Catatan dibatalkan');
        $this->dispatch('pemeliharaan-dicatat', aset: $asetId);
    }
};
?>

<div>
    @if ($terbuka && $this->aset)
        @php $a = $this->aset; @endphp

        <div class="fixed inset-0 z-[3000] flex flex-col justify-end bg-fg/55 lg:items-center lg:justify-center" wire:click.self="tutup" wire:key="catat-sheet"
             x-data="{ mengunggah: false }" @keydown.escape.window="$wire.tutup()">
            <div class="flex max-h-[92dvh] flex-col overflow-y-auto rounded-t-xl bg-bg shadow-xl lg:w-[480px] lg:rounded-md">
                <div class="flex justify-center pb-1 pt-2 lg:hidden"><span class="h-[5px] w-9 rounded-[3px] bg-line"></span></div>
                <div class="flex gap-3 px-4 pb-4 pt-2">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent">{{ $a->kode }}</span>
                        <span class="judul text-[28px] leading-7">Catat pemeliharaan</span>
                        <span class="text-base text-fg-muted">{{ $a->jenis->namaPendek() }} · {{ $a->lokasi() }}</span>
                    </div>
                    <button wire:click="tutup" class="grid size-11 shrink-0 place-items-center rounded-full bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
                </div>

                <div class="flex flex-col gap-5 px-4 pb-4">
                    <div class="flex flex-col gap-2.5">
                        <span class="text-base font-semibold">Kegiatan</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\Pemeliharaan::PILIHAN as $k => $label)
                                <button type="button" wire:click="pilihKegiatan('{{ $k }}')" wire:key="keg-{{ $k }}" @class([
                                    'flex h-11 items-center gap-1.5 rounded-full px-4 text-[15px] font-semibold',
                                    'bg-accent text-fg-inverse' => $kegiatan === $k,
                                    'outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted' => $kegiatan !== $k,
                                ])>@if ($kegiatan === $k) @svg('lucide-check', 'size-4') @endif {{ $label }}</button>
                            @endforeach
                        </div>
                        @error('kegiatan') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <span class="text-base font-semibold">Tanggal</span>
                        <div class="flex h-12 items-center gap-2.5 rounded-sm px-3.5 outline outline-1 -outline-offset-1 outline-line">
                            @svg('lucide-calendar', 'size-5 text-fg-muted')
                            @if ($ubahTanggal)
                                <input type="date" wire:model.live="tanggal" min="{{ today()->subYear()->toDateString() }}" max="{{ today()->toDateString() }}" class="min-w-0 flex-1 bg-transparent text-base outline-none">
                                <button type="button" wire:click="$set('ubahTanggal', false)" class="font-caption text-sm font-semibold text-accent">Selesai</button>
                            @else
                                <span class="flex-1 text-base">{{ \Illuminate\Support\Carbon::parse($tanggal)->isToday() ? 'Hari ini · ' : '' }}{{ \Illuminate\Support\Carbon::parse($tanggal)->translatedFormat('l, j M Y') }}</span>
                                <button type="button" wire:click="$set('ubahTanggal', true)" class="font-caption text-sm font-semibold text-accent">Ubah</button>
                            @endif
                        </div>
                        @error('tanggal') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <span class="text-base font-semibold">Catatan <span class="font-caption text-sm font-normal text-fg-muted">opsional</span></span>
                        <textarea wire:model="catatan" rows="3" maxlength="500" placeholder="Mis. rambu dibersihkan, reflektif masih terang" class="w-full rounded-sm px-3.5 py-3 text-base outline outline-1 -outline-offset-1 outline-line placeholder:text-fg-muted focus:outline-2 focus:outline-accent"></textarea>
                        @error('catatan') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <span class="text-base font-semibold">Foto <span class="font-caption text-sm font-normal text-fg-muted">opsional</span></span>
                        <div class="flex gap-2 overflow-x-auto">
                            @foreach ($foto as $n => $f)
                                <div class="relative size-24 shrink-0 overflow-hidden rounded-md" wire:key="cf-{{ $n }}">
                                    <img src="{{ $f->temporaryUrl() }}" class="size-full object-cover" alt="">
                                    <button type="button" wire:click="hapusFoto({{ $n }})" class="absolute right-0 top-0 grid size-11 place-items-center" aria-label="Hapus foto"><span class="grid size-7 place-items-center rounded-full bg-fg/80 text-fg-inverse">@svg('lucide-x', 'size-4')</span></button>
                                </div>
                            @endforeach
                            @if (count($foto) < 4)
                                <label class="flex size-24 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-md bg-accent-soft text-accent outline outline-[1.5px] -outline-offset-1 outline-accent">
                                    @svg('lucide-camera', 'size-6')
                                    <span class="font-caption text-[13px] font-semibold"><span wire:loading.remove wire:target="tambah">Tambah</span><span wire:loading wire:target="tambah">Mengunggah…</span></span>
                                    <input type="file" wire:model="tambah" accept="image/*" capture="environment" multiple class="hidden">
                                </label>
                            @endif
                        </div>
                        @error('foto') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                        @error('foto.*') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                        @error('tambah.*') <span class="text-sm text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="sticky bottom-0 border-t border-line bg-bg p-4">
                    <button type="button" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan,tambah" class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm disabled:opacity-60">
                        <span wire:loading.remove wire:target="simpan">Simpan catatan</span><span wire:loading wire:target="simpan">Menyimpan…</span>
                        @svg('lucide-check', 'size-[18px]')
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

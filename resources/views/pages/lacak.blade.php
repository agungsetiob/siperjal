<?php

use App\Enums\StatusItem as SI;
use App\Enums\StatusUsulan as SU;
use App\Models\Usulan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.publik', ['nav' => 'lacak', 'footer' => 'ringkas'])] class extends Component
{
    public ?Usulan $usulan = null;

    public string $nomor = '';

    public string $nomorLain = '';

    public function mount(string $nomor): void
    {
        $this->nomor = strtoupper(trim($nomor));
        if (str_starts_with($this->nomor, 'LPR-')) {
            $this->redirectRoute('lapor.status', $this->nomor, navigate: true);

            return;
        }
        $this->usulan = Usulan::where('nomor', $this->nomor)
            ->where('status', '!=', SU::Draft)
            ->with(['items.jenis', 'items.fotoHasil', 'items.laporanTerbuka', 'foto', 'desa', 'kecamatan', 'riwayat' => fn ($q) => $q->where('publik', true)])
            ->first();
        $this->nomorLain = $this->usulan ? '' : $this->nomor;
    }

    public function lacakLain()
    {
        $nomor = strtoupper(trim($this->nomorLain));
        if (str_starts_with($nomor, 'LPR-') && \App\Models\LaporanKerusakan::where('nomor', $nomor)->exists()) {
            return $this->redirectRoute('lapor.status', $nomor, navigate: true);
        }
        if (! Usulan::where('nomor', $nomor)->where('status', '!=', SU::Draft)->exists()) {
            $this->addError('nomorLain', 'Nomor usulan tidak ditemukan.');

            return;
        }

        return $this->redirectRoute('lacak', $nomor, navigate: true);
    }

    /** Empat tahap untuk kartu status: [judul, tanggal, catatan, keadaan: selesai|sekarang|nanti|gagal] */
    #[Computed]
    public function tahap(): array
    {
        $u = $this->usulan;
        $items = $u->items;
        $sekarang = $u->status->tahap(); // 2 Dishub, 3 Bappeda, 4 Terpasang, 5 selesai
        $ditolakDishub = $u->status === SU::Ditolak && $items->every(fn ($i) => $i->status === SI::DitolakDishub);
        $tgl = fn ($d) => $d?->translatedFormat('j F Y');

        $keadaan = function (int $n) use ($sekarang, $u, $ditolakDishub) {
            if ($u->status === SU::Ditolak) {
                $gagalDi = $ditolakDishub ? 2 : 3;

                return $n < $gagalDi ? 'selesai' : ($n === $gagalDi ? 'gagal' : 'nanti');
            }

            return $n < $sekarang ? 'selesai' : ($n === $sekarang ? 'sekarang' : 'nanti');
        };

        $disetujui = $items->whereIn('status', [SI::Disetujui, SI::Terpasang]);
        $terpasang = $items->where('status', SI::Terpasang);
        $diputuskan = $items->whereIn('status', [SI::Disetujui, SI::Ditunda, SI::DitolakBappeda, SI::Terpasang]);

        return [
            ['Diajukan', $tgl($u->diajukan_at), 'Usulan diterima dan diberi nomor.', 'selesai'],
            ['Diperiksa Dishub', $u->diperiksa_at ? $tgl($u->diperiksa_at) : ($u->status === SU::Revisi ? 'Menunggu perbaikan' : 'Sedang berjalan'),
                match (true) {
                    $u->status === SU::Revisi => 'Dishub meminta pengusul melengkapi beberapa bagian.',
                    (bool) $u->diperiksa_at => $items->whereNotIn('status', [SI::DitolakDishub])->count().' dari '.$items->count().' jenis lolos pemeriksaan.',
                    default => 'Petugas Dishub sedang memeriksa usulan (biasanya ± 7 hari).',
                }, $keadaan(2)],
            ['Disetujui Bappeda', $diputuskan->isNotEmpty() ? $tgl($diputuskan->max('diputuskan_at')) : ($keadaan(3) === 'sekarang' ? 'Sedang berjalan' : '—'),
                $diputuskan->isNotEmpty()
                    ? collect([
                        $disetujui->count() ? $disetujui->count().' jenis disetujui' : null,
                        ($n = $items->where('status', SI::Ditunda)->count()) ? "$n ditunda" : null,
                        ($n = $items->where('status', SI::DitolakBappeda)->count()) ? "$n ditolak" : null,
                    ])->filter()->implode(', ').'.'
                    : 'Menunggu keputusan anggaran.', $keadaan(3)],
            ['Terpasang', $terpasang->isNotEmpty() && $keadaan(4) === 'selesai' ? $tgl($terpasang->max('terpasang_at')) : ($keadaan(4) === 'sekarang' ? 'Sedang berjalan' : '—'),
                $disetujui->isNotEmpty()
                    ? $terpasang->count().' dari '.$disetujui->count().' jenis yang disetujui sudah terpasang'.($terpasang->isNotEmpty() ? ' ('.$terpasang->max('terpasang_at')->translatedFormat('j M Y').')' : '').'.'
                    : 'Dipasang setelah disetujui.', $keadaan(4)],
        ];
    }

    /** Ringkasan unit per kelompok status. */
    #[Computed]
    public function ringkasan(): array
    {
        $items = $this->usulan->items;
        $unit = fn ($c) => $c->sum(fn ($i) => $i->jumlahEfektif());
        $tunda = $items->where('status', SI::Ditunda);

        return array_filter([
            ['sudah terpasang', $unit($items->where('status', SI::Terpasang)), 'bg-success'],
            ['menunggu dipasang', $unit($items->where('status', SI::Disetujui)), 'bg-accent'],
            ['menunggu keputusan', $unit($items->whereIn('status', [SI::Menunggu, SI::Lolos])), 'bg-fg-muted'],
            ['ditunda'.($tunda->first()?->tahun_target ? ' ke '.$tunda->first()->tahun_target : ''), $unit($tunda), 'bg-warning'],
            ['ditolak', $unit($items->whereIn('status', [SI::DitolakDishub, SI::DitolakBappeda])), 'bg-danger'],
        ], fn ($r) => $r[1] > 0);
    }

    public function catatanItem($i): string
    {
        return match ($i->status) {
            SI::Terpasang => 'Dipasang '.$i->terpasang_at->translatedFormat('j M Y').'.'.($i->catatan_terpasang ? ' '.$i->catatan_terpasang : ''),
            SI::Disetujui => "Masuk anggaran {$i->tahun_anggaran}, menunggu jadwal pemasangan.",
            SI::Ditunda => ($i->catatan_bappeda ? rtrim($i->catatan_bappeda, '.').'. ' : '')."Diusulkan lagi pada anggaran {$i->tahun_target}.",
            SI::DitolakBappeda => $i->catatan_bappeda ?? 'Tidak disetujui Bappeda.',
            SI::DitolakDishub => $i->catatan_dishub ?? 'Tidak lolos pemeriksaan Dishub.',
            SI::Lolos => 'Lolos pemeriksaan Dishub, menunggu keputusan Bappeda.',
            SI::Menunggu => 'Sedang diperiksa Dishub.',
        };
    }

    public function render()
    {
        return $this->view()->title($this->usulan ? $this->usulan->nomor.' · Lacak Usulan' : 'Nomor tidak ditemukan · Lacak Usulan');
    }
};
?>

<div wire:poll.60s>
@if (! $usulan)
    {{-- ── Nomor tidak ditemukan ── --}}
    <div class="sticky top-0 z-[1000] flex h-14 items-center gap-1 border-b border-line bg-bg px-2 lg:hidden">
        <a href="{{ url('/') }}" wire:navigate class="grid size-11 place-items-center" aria-label="Kembali">@svg('lucide-arrow-left', 'size-[22px]')</a>
        <span class="flex-1 text-base font-semibold">Lacak Usulan</span>
        
    </div>
    <form wire:submit="lacakLain" class="mx-auto flex min-h-[calc(100dvh-56px)] max-w-md flex-col px-4 pt-8 lg:min-h-0 lg:py-20">
        <div class="flex flex-1 flex-col gap-5">
            <span class="grid size-[72px] place-items-center rounded-full bg-warning-bg text-warning">@svg('lucide-search-x', 'size-8')</span>
            <h1 class="judul text-[36px] leading-[38px]">Nomor tidak ditemukan</h1>
            <p class="text-base leading-6 text-fg-muted">{{ $nomor }} belum ada di sistem. Coba periksa lagi — nomornya ada di email atau pesan dari kami.</p>
            <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold">Nomor usulan</span>
                <span x-data class="flex h-[52px] items-center gap-2 rounded-sm bg-bg px-3.5 outline outline-2 -outline-offset-2 outline-warning focus-within:outline-accent">
                    <input wire:model="nomorLain" x-ref="n" class="min-w-0 flex-1 bg-transparent text-base font-semibold uppercase outline-none placeholder:font-normal placeholder:normal-case" placeholder="USL-{{ date('Y') }}-00123" autocapitalize="characters">
                    <button type="button" @click="$wire.set('nomorLain', '', false); $refs.n.value = ''; $refs.n.focus()" class="text-fg-muted" aria-label="Kosongkan">@svg('lucide-circle-x', 'size-[18px]')</button>
                </span>
                @error('nomorLain') <span class="text-sm text-danger">{{ $message }}</span> @else <span class="font-caption text-[13px] text-fg-muted">Format: USL-tahun-5 angka, mis. USL-{{ date('Y') }}-00123</span> @enderror
            </label>
            <div class="flex flex-col gap-2.5 rounded-md bg-surface-muted p-4 text-sm">
                @foreach (['Cari email dari '.config('mail.from.address'), 'Buka lagi link lacak yang pernah dibagikan', 'Usulan yang baru dikirim langsung bisa dilacak'] as $tip)
                    <span class="flex gap-2.5">@svg('lucide-check', 'size-4 shrink-0 text-success') {{ $tip }}</span>
                @endforeach
            </div>
            <a href="{{ route('usulan.baru') }}" wire:navigate class="flex h-11 w-fit items-center gap-1.5 text-[15px] font-semibold text-accent">Belum punya nomor? Ajukan usulan @svg('lucide-arrow-right', 'size-4')</a>
        </div>
        <div class="sticky bottom-0 -mx-4 bg-bg px-4 pb-7 pt-3 lg:static lg:mx-0 lg:px-0">
            <button class="flex h-[52px] w-full items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm hover:brightness-110">Lacak Lagi @svg('lucide-search', 'size-[18px]')</button>
        </div>
    </form>
@else
    @php
        $u = $usulan;
        $tahapIni = collect($this->tahap)->search(fn ($t) => in_array($t[3], ['sekarang', 'gagal']));
        $link = route('lacak', $u->nomor);
        $jumlahUnit = $u->items->sum(fn ($i) => $i->jumlahEfektif());
        $diperbarui = collect([$u->updated_at, $u->riwayat->first()?->created_at, $u->items->max('updated_at')])->filter()->max();
        $foto = $u->foto->first();
        $tileTone = ['sukses' => 'bg-success-bg text-success', 'proses' => 'bg-accent-soft text-accent', 'perhatian' => 'bg-warning-bg text-warning', 'bahaya' => 'bg-surface-muted text-fg-muted', 'netral' => 'bg-surface-muted text-fg-muted'];
    @endphp

    {{-- ── HP ── --}}
    @php
        $toneKotak = ['sukses' => 'bg-success-bg', 'proses' => 'bg-accent-soft', 'perhatian' => 'bg-warning-bg', 'bahaya' => 'bg-danger-bg', 'netral' => 'bg-surface-muted'][$u->status->tone()] ?? 'bg-surface-muted';
        $toneTeks = ['sukses' => 'text-success', 'proses' => 'text-accent', 'perhatian' => 'text-warning', 'bahaya' => 'text-danger', 'netral' => 'text-fg-muted'][$u->status->tone()] ?? 'text-fg-muted';
        $kelompokItem = fn ($st) => $u->items->whereIn('status', $st)->map(fn ($i) => preg_match('/^\p{Lu}\p{Ll}/u', $n = $i->jenis->namaPendek()) ? lcfirst($n) : $n)->implode(', ');
        $ringkasHp = collect([
            ($t = $kelompokItem([\App\Enums\StatusItem::Terpasang])) ? ucfirst($t).' sudah terpasang' : null,
            ($t = $kelompokItem([\App\Enums\StatusItem::Disetujui])) ? $t.' menunggu pemasangan' : null,
            ($t = $kelompokItem([\App\Enums\StatusItem::Ditunda])) ? $t.' ditunda ke '.$u->items->firstWhere('status', \App\Enums\StatusItem::Ditunda)->tahun_target : null,
            ($t = $kelompokItem([\App\Enums\StatusItem::DitolakDishub, \App\Enums\StatusItem::DitolakBappeda])) ? $t.' tidak disetujui' : null,
        ])->filter()->values();
        $catatanStatus = $ringkasHp->isNotEmpty() && $u->items->contains(fn ($i) => ! in_array($i->status, [\App\Enums\StatusItem::Menunggu, \App\Enums\StatusItem::Lolos]))
            ? ucfirst($ringkasHp->implode('; ')).'.'
            : $u->status->penjelasan();
    @endphp
    <div class="lg:hidden">
        <div class="sticky top-0 z-[1000] flex h-14 items-center gap-1 border-b border-line bg-bg px-2 lg:hidden">
        <a href="{{ url('/') }}" wire:navigate class="grid size-11 place-items-center" aria-label="Kembali">@svg('lucide-arrow-left', 'size-[22px]')</a>
        <span class="flex-1 text-base font-semibold">Lacak Usulan</span>
        <button x-data @click="navigator.share ? navigator.share({ title: '{{ $u->nomor }}', url: '{{ $link }}' }) : (salin('{{ $link }}'), $dispatch('toast', { pesan: 'Link disalin' }))" class="grid size-11 place-items-center" aria-label="Bagikan">@svg('lucide-share-2', 'size-5')</button>
    </div>
        <div class="flex flex-col gap-5 px-4 pb-6 pt-2">
            <div class="flex flex-col gap-1.5">
                <span class="font-caption text-xs font-semibold uppercase tracking-[0.6px] text-accent">Nomor usulan</span>
                <h1 class="judul break-all text-[40px] leading-10">{{ $u->nomor }}</h1>
                <p class="text-[15px] leading-[21px] text-fg-muted">{{ collect([$u->alamat, collect([$u->desa ? 'Ds. '.$u->desa->nama : null, $u->kecamatan ? 'Kec. '.$u->kecamatan->nama : null])->filter()->implode(', ')])->filter()->implode(' · ') }}</p>
            </div>
            <div class="flex flex-col gap-2.5 rounded-md p-4 {{ $toneKotak }}">
                <span class="flex w-fit items-center gap-1.5 rounded-sm bg-bg px-2.5 py-1 font-caption text-xs font-semibold {{ $toneTeks }}"><span class="size-1.5 rounded-full bg-current"></span>{{ $u->status->label() }}</span>
                <p class="text-[15px] leading-[22px]">{{ $catatanStatus }}</p>
            </div>
            <ol class="flex flex-col">
                @foreach ($this->tahap as $n => [$judul, $tgl, $catatan, $keadaan])
                    <li class="flex gap-3">
                        <span class="flex w-6 flex-col items-center">
                            @if ($keadaan === 'selesai')
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-success text-fg-inverse">@svg('lucide-check', 'size-3.5')</span>
                            @elseif ($keadaan === 'gagal')
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-danger text-fg-inverse">@svg('lucide-x', 'size-3.5')</span>
                            @elseif ($keadaan === 'sekarang')
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-bg outline outline-2 -outline-offset-2 outline-accent"><span class="size-2.5 rounded-full bg-accent"></span></span>
                            @else
                                <span class="size-6 shrink-0 rounded-full bg-bg outline outline-2 -outline-offset-2 outline-line"></span>
                            @endif
                            @unless ($loop->last) <span @class(['w-0.5 flex-1', 'bg-success' => $keadaan === 'selesai', 'bg-line' => $keadaan !== 'selesai'])></span> @endunless
                        </span>
                        <span @class(['flex flex-1 flex-col gap-0.5 pt-px', 'pb-[18px]' => ! $loop->last])>
                            <span @class(['text-base font-semibold', 'text-accent' => $keadaan === 'sekarang', 'text-danger' => $keadaan === 'gagal', 'text-fg-muted' => $keadaan === 'nanti'])>{{ $judul }}</span>
                            @if ($tgl !== '—') <span class="text-sm text-fg-muted">{{ $tgl }}</span> @endif
                            @if (in_array($keadaan, ['sekarang', 'gagal'])) <span class="text-sm text-fg-muted">{{ $catatan }}</span> @endif
                        </span>
                    </li>
                @endforeach
            </ol>
            <div class="flex flex-col">
                <h2 class="judul text-xl">Yang diusulkan</h2>
                @foreach ($u->items as $i)
                    @php $tone = $i->status->tone(); @endphp
                    <div class="flex items-center gap-3 border-b border-line py-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-md {{ $tileTone[$tone] ?? $tileTone['netral'] }}">@svg('lucide-'.$i->jenis->ikon, 'size-[19px]')</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-px"><span class="text-[15px] font-semibold">{{ $i->jenis->namaPendek() }}</span><span class="text-[13px] text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }}</span>
                            @if ($i->status === \App\Enums\StatusItem::Terpasang)
                            @if ($i->laporanTerbuka) <a href="{{ route('lapor.status', $i->laporanTerbuka->nomor) }}" wire:navigate class="flex w-fit items-center gap-1.5 font-caption text-[13px] font-semibold text-warning">@svg('lucide-wrench', 'size-3.5') Dilaporkan rusak · {{ $i->laporanTerbuka->status->label() }}</a>
                            @else <a href="{{ route('lapor.item', $i->id) }}" wire:navigate class="flex w-fit items-center gap-1.5 font-caption text-[13px] font-semibold text-accent">@svg('lucide-wrench', 'size-3.5') Laporkan rusak</a> @endif
                        @endif
                        </span>
                        <x-badge :tone="$tone">{{ $i->status === \App\Enums\StatusItem::Ditunda ? 'Ditunda '.$i->tahun_target : $i->status->label() }}</x-badge>
                    </div>
                @endforeach
            </div>
            <p class="flex items-center gap-2 font-caption text-xs text-fg-muted">@svg('lucide-shield-check', 'size-4 shrink-0') Nama dan kontak pengusul tidak ditampilkan di halaman ini.</p>
        </div>
        <div class="sticky bottom-0 flex gap-2 border-t border-line bg-bg px-4 pb-7 pt-3">
            <button x-data @click="salin('{{ $link }}'); $dispatch('toast', { pesan: 'Link disalin' })" class="grid size-[52px] shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-fg" aria-label="Salin link">@svg('lucide-link', 'size-5')</button>
            <a href="https://wa.me/?text={{ urlencode("Pantau usulan jalan {$u->nomor}: {$link}") }}" target="_blank" class="flex h-[52px] flex-1 items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Bagikan ke WhatsApp @svg('lucide-message-circle', 'size-[18px]')</a>
        </div>
    </div>

    {{-- ── Desktop ── --}}
    <div class="hidden lg:block">


    {{-- ── Hero ── --}}
    <section class="mx-auto flex max-w-[1440px] flex-col gap-12 px-4 pb-16 pt-10 sm:px-8 lg:flex-row lg:gap-16 lg:px-20 lg:pb-[72px] lg:pt-14">
        <div class="flex min-w-0 flex-1 flex-col gap-7">
            <div class="flex flex-col gap-3">
                <span class="font-caption text-[13px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Nomor usulan</span>
                <h1 class="judul break-all text-[56px] leading-none tracking-[-0.5px] sm:text-[96px] sm:leading-[96px]">{{ $u->nomor }}</h1>
                <div class="flex items-center gap-2.5 text-[22px] font-semibold">
                    @svg('lucide-map-pin', 'size-[22px] shrink-0 text-accent')
                    {{ collect([$u->desa ? 'Desa '.$u->desa->nama : null, $u->kecamatan ? 'Kecamatan '.$u->kecamatan->nama : null])->filter()->implode(', ') ?: '—' }}
                </div>
                @if ($u->alamat) <div class="text-base text-fg-muted">{{ $u->alamat }}</div> @endif
            </div>

            <div class="flex flex-wrap gap-x-10 gap-y-4">
                @foreach (['Diajukan' => $u->diajukan_at->translatedFormat('j F Y'), 'Jenis pengusul' => $u->jenisPengusul(), 'Jumlah' => $u->items->count().' jenis · '.$jumlahUnit.' unit'] as $k => $v)
                    <div class="flex flex-col gap-1">
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted">{{ $k }}</span>
                        <span class="text-base font-semibold">{{ $v }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col gap-2.5 rounded-md bg-accent-soft p-5">
                <span @class(['flex w-fit items-center gap-1.5 rounded-sm px-2.5 py-1 font-caption text-xs font-semibold text-fg-inverse', 'bg-accent' => $u->status !== \App\Enums\StatusUsulan::Ditolak, 'bg-danger' => $u->status === \App\Enums\StatusUsulan::Ditolak])>
                    <span class="size-1.5 rounded-full bg-fg-inverse"></span>
                    @if ($u->status === \App\Enums\StatusUsulan::Terpasang) Selesai · Semua terpasang
                    @elseif (in_array($u->status, [\App\Enums\StatusUsulan::Ditolak, \App\Enums\StatusUsulan::Dibatalkan])) {{ $u->status->label() }}
                    @elseif ($tahapIni !== false) Tahap {{ $tahapIni + 1 }} · {{ [1 => $u->status === \App\Enums\StatusUsulan::Revisi ? 'Perlu perbaikan' : 'Diperiksa Dishub', 2 => $u->status === \App\Enums\StatusUsulan::Ditunda ? 'Ditunda' : 'Menunggu Bappeda', 3 => 'Sedang dipasang'][$tahapIni] ?? $u->status->label() }}
                    @else {{ $u->status->label() }} @endif
                </span>
                <p class="text-[15px] leading-[23px]">{{ $u->status->penjelasan() }}
                    @if ($u->status !== \App\Enums\StatusUsulan::Ditolak && $u->items->contains(fn ($i) => in_array($i->status, [\App\Enums\StatusItem::Ditunda, \App\Enums\StatusItem::DitolakDishub, \App\Enums\StatusItem::DitolakBappeda])))
                        Ada barang yang ditunda atau ditolak — alasannya ada di daftar di bawah.
                    @elseif ($u->status === \App\Enums\StatusUsulan::Ditolak)
                        Alasannya ada di daftar di bawah.
                    @endif
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center" x-data>
                <a href="https://wa.me/?text={{ urlencode("Pantau usulan jalan {$u->nomor}: {$link}") }}" target="_blank" class="flex items-center justify-center gap-2 rounded-sm bg-success px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">
                    Bagikan ke WhatsApp @svg('lucide-message-circle', 'size-[18px]')
                </a>
                <div class="flex h-[46px] min-w-0 flex-1 items-center overflow-hidden rounded-sm outline outline-1 -outline-offset-1 outline-line">
                    <span class="flex min-w-0 flex-1 items-center gap-2 px-3.5 font-caption text-[13px] text-fg-muted">@svg('lucide-link', 'size-4 shrink-0') <span class="truncate">{{ str($link)->after('://') }}</span></span>
                    <button x-on:click="salin('{{ $link }}'); $dispatch('toast', { pesan: 'Link disalin' })" class="flex h-full items-center gap-2 border-l border-line bg-surface-muted px-4 text-sm font-semibold hover:bg-line">@svg('lucide-copy', 'size-4') Salin link</button>
                </div>
            </div>

            <div class="flex items-center gap-2 font-caption text-[13px] text-fg-muted">@svg('lucide-shield-check', 'size-4') Nama dan kontak pengusul tidak ditampilkan di halaman ini.</div>
        </div>

        <div class="flex w-full flex-col gap-4 lg:w-[560px]">
            <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($u->foto)), mulai: 0 })" @disabled(! $foto)
                    class="flex h-[300px] flex-col items-start justify-between rounded-md bg-surface-muted bg-cover bg-center p-4 text-left disabled:cursor-default sm:h-[400px]" @if ($foto) style="background-image: url('{{ $foto->url() }}')" @endif>
                <span>@if ($u->foto->count() > 1)<span class="flex items-center gap-1.5 rounded-sm bg-fg/80 px-2.5 py-1.5 font-caption text-xs font-medium text-fg-inverse">@svg('lucide-images', 'size-3.5') {{ $u->foto->count() }} foto · ketuk untuk melihat</span>@endif</span>
                @if ($foto)
                    <span class="flex w-fit items-center gap-1.5 rounded-sm bg-fg/80 px-2.5 py-1.5 font-caption text-xs font-medium text-fg-inverse">@svg('lucide-camera', 'size-3.5') Foto lokasi dari pengusul · {{ $foto->created_at->translatedFormat('j M Y') }}</span>
                @else
                    <span class="m-auto flex flex-col items-center gap-2 text-fg-muted">@svg('lucide-image-off', 'size-8') Belum ada foto lokasi</span>
                @endif
            </button>
            @if ($u->lat)
                <div class="flex items-center gap-5">
                    <div class="h-[150px] w-[240px] shrink-0 overflow-hidden rounded-md outline outline-1 -outline-offset-1 outline-line" wire:ignore
                         x-data="petaMini({{ $u->lat }}, {{ $u->lng }}, @js(svg('lucide-traffic-cone', 'size-4')->toHtml()))"></div>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Titik lokasi</span>
                        <span class="text-lg font-semibold">{{ number_format($u->lat, 4, '.', '') }}, {{ number_format($u->lng, 4, '.', '') }}</span>
                        @if ($u->kecamatan) <span class="text-sm text-fg-muted">Kecamatan {{ $u->kecamatan->nama }}</span> @endif
                        <a href="https://www.google.com/maps?q={{ $u->lat }},{{ $u->lng }}" target="_blank" class="flex items-center gap-1.5 pt-1.5 text-sm font-semibold text-accent">Buka di Google Maps @svg('lucide-external-link', 'size-3.5')</a>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- ── Status ── --}}
    <section class="bg-accent">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-9 px-4 pb-14 pt-16 sm:px-8 lg:px-20">
            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                <div class="flex flex-col gap-2">
                    <span class="font-caption text-[13px] font-semibold uppercase tracking-[1.2px] text-accent-soft">Status usulan</span>
                    <h2 class="judul text-5xl text-fg-inverse lg:text-[64px] lg:leading-[64px]">Sudah sampai mana?</h2>
                </div>
                <span class="flex w-fit items-center gap-2 rounded-sm bg-fg-inverse/15 px-3 py-2 font-caption text-sm font-medium text-fg-inverse">@svg('lucide-clock', 'size-4') Terakhir diperbarui {{ $diperbarui->translatedFormat('j F Y, H.i') }} WITA</span>
            </div>
            <div class="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->tahap as $n => [$judul, $tanggal, $catatan, $keadaan])
                    @php $warna = ['selesai' => 'bg-secondary', 'sekarang' => 'bg-accent', 'gagal' => 'bg-danger', 'nanti' => 'bg-line'][$keadaan]; @endphp
                    <div class="flex flex-col gap-[18px] overflow-hidden rounded-md bg-bg pb-6 shadow-sm">
                        <div class="h-1.5 w-full bg-accent-soft">
                            <div @class(['h-full', $warna, 'w-full' => $keadaan !== 'sekarang', 'w-1/2 animate-pulse' => $keadaan === 'sekarang'])></div>
                        </div>
                        <div class="flex flex-col gap-3.5 px-6">
                            <div class="flex items-center justify-between">
                                <span @class(['grid size-9 place-items-center rounded-full', $warna, 'text-on-secondary' => $keadaan === 'selesai', 'text-fg-inverse' => in_array($keadaan, ['sekarang', 'gagal']), 'text-fg-muted' => $keadaan === 'nanti'])>
                                    @if ($keadaan === 'selesai') @svg('lucide-check', 'size-[18px]')
                                    @elseif ($keadaan === 'gagal') @svg('lucide-x', 'size-[18px]')
                                    @else <span class="judul text-lg">{{ $n + 1 }}</span> @endif
                                </span>
                                @if (in_array($keadaan, ['sekarang', 'gagal']))
                                    <span @class(['rounded-sm px-2 py-1 font-caption text-[11px] font-bold uppercase tracking-[1px]', 'bg-accent-soft text-accent' => $keadaan === 'sekarang', 'bg-danger-bg text-danger' => $keadaan === 'gagal'])>Tahap {{ $n + 1 }} · {{ $keadaan === 'gagal' ? 'Berhenti' : 'Sekarang' }}</span>
                                @else
                                    <span class="font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Tahap {{ $n + 1 }}</span>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <span @class(['text-xl font-bold', 'text-fg-muted' => $keadaan === 'nanti'])>{{ $judul }}</span>
                                <span @class(['font-caption text-sm font-semibold', 'text-accent-deep' => $keadaan === 'selesai', 'text-accent' => $keadaan === 'sekarang', 'text-danger' => $keadaan === 'gagal', 'text-fg-muted' => $keadaan === 'nanti'])>{{ $tanggal }}</span>
                                <span class="text-sm leading-[21px] text-fg-muted">{{ $catatan }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex h-14 items-center overflow-hidden bg-secondary">
            <div class="flex w-max animate-geser items-center gap-5 pl-20 text-accent">
                @foreach (range(1, 6) as $_)
                    @foreach (['Diajukan', 'Diperiksa Dishub', 'Disetujui Bappeda', 'Terpasang'] as $w)
                        <span class="judul whitespace-nowrap text-[22px] tracking-[1px]">{{ $w }}</span>@svg('lucide-arrow-right', 'size-[18px] text-accent/70')
                    @endforeach
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Yang diusulkan ── --}}
    <section class="mx-auto flex max-w-[1440px] flex-col gap-12 px-4 py-16 sm:px-8 lg:flex-row lg:gap-20 lg:px-20 lg:py-[88px]">
        <div class="flex w-full shrink-0 flex-col gap-6 lg:w-[400px]">
            <span class="font-caption text-[13px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Yang diusulkan</span>
            <h2 class="judul text-5xl lg:text-[56px] lg:leading-[59px]">{{ $u->items->count() }} jenis, {{ $jumlahUnit }} unit perlengkapan</h2>
            <p class="text-[17px] leading-[26px] text-fg-muted">Setiap barang diputuskan sendiri-sendiri. Jadi dalam satu usulan, statusnya bisa berbeda.</p>
            <div class="flex flex-col gap-3 pt-2">
                @foreach ($this->ringkasan as [$label, $jumlah, $warna])
                    <div class="flex items-center gap-3 text-base">
                        <span class="h-6 w-1 rounded-sm {{ $warna }}"></span>
                        <b>{{ $jumlah }} unit</b> <span class="text-fg-muted">{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <ul class="min-w-0 flex-1 border-t-2 border-line">
            @foreach ($u->items as $i)
                <li class="flex gap-5 border-b border-line py-6 last:border-b-0">
                    <span class="grid size-[52px] shrink-0 place-items-center rounded-md {{ $tileTone[$i->status->tone()] }}">@svg('lucide-'.$i->jenis->ikon, 'size-6')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="text-lg font-bold">{{ $i->jenis->nama }}</span>
                            <span class="rounded-sm px-2 py-0.5 font-caption text-[13px] font-bold outline outline-1 -outline-offset-1 outline-line">×{{ $i->jumlahEfektif() }}</span>
                        </div>
                        <p class="text-[15px] leading-[23px] text-fg-muted">{{ $this->catatanItem($i) }}</p>
                        @if ($i->status === \App\Enums\StatusItem::Terpasang)
                            @if ($i->laporanTerbuka) <a href="{{ route('lapor.status', $i->laporanTerbuka->nomor) }}" wire:navigate class="flex w-fit items-center gap-1.5 font-caption text-[13px] font-semibold text-warning">@svg('lucide-wrench', 'size-3.5') Dilaporkan rusak · {{ $i->laporanTerbuka->status->label() }}</a>
                            @else <a href="{{ route('lapor.item', $i->id) }}" wire:navigate class="flex w-fit items-center gap-1.5 font-caption text-[13px] font-semibold text-accent">@svg('lucide-wrench', 'size-3.5') Laporkan rusak</a> @endif
                        @endif
                        @if ($i->fotoHasil->isNotEmpty())
                            <div class="mt-2 flex gap-2 overflow-x-auto">
                                @foreach ($i->fotoHasil->values() as $n => $f)
                                    <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($i->fotoHasil)), mulai: {{ $n }} })" class="shrink-0" aria-label="Lihat foto hasil pemasangan"><img src="{{ $f->url() }}" class="h-24 rounded-sm object-cover" alt="Foto hasil pemasangan"></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="pt-1"><x-badge :tone="$i->status->tone()">{{ $i->status->label() }}</x-badge></div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ── CTA ── --}}
    <section class="bg-surface-soft">
        <div class="mx-auto flex max-w-[1440px] flex-col items-center gap-10 px-4 py-16 sm:px-8 lg:flex-row lg:gap-[72px] lg:p-20">
            <div class="h-[280px] w-full shrink-0 rounded-md bg-cover bg-center sm:h-[420px] lg:w-[600px]" style="background-image: url('{{ asset('img/lacak-cta.jpg') }}')"></div>
            <div class="flex w-full flex-1 flex-col gap-6">
                <h2 class="judul text-5xl lg:text-[56px] lg:leading-[59px]">Punya nomor usulan lain?</h2>
                <p class="text-[17px] leading-[26px] text-fg-muted">Masukkan nomor yang Anda terima lewat email atau layar setelah mengirim. Siapa pun bisa melihat perkembangannya — tanpa perlu masuk.</p>
                <form wire:submit="lacakLain" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="flex h-12 flex-1 items-center gap-2.5 rounded-sm bg-bg px-4 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                        @svg('lucide-search', 'size-[18px] shrink-0 text-fg-muted')
                        <input wire:model="nomorLain" class="w-full bg-transparent text-[15px] uppercase outline-none placeholder:normal-case placeholder:text-fg-muted" placeholder="Contoh: USL-{{ date('Y') }}-00123">
                    </label>
                    <button class="flex items-center justify-center gap-2 rounded-sm bg-accent px-[22px] py-3.5 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Lacak @svg('lucide-arrow-right', 'size-[18px]')</button>
                </form>
                @error('nomorLain') <div class="-mt-3 text-sm text-danger">{{ $message }}</div> @enderror
                <div class="flex flex-wrap items-center gap-2 pt-2 text-[15px]">
                    Ada jalan lain yang butuh rambu atau lampu?
                    <a href="{{ route('usulan.baru') }}" wire:navigate class="flex items-center gap-1.5 font-bold text-accent">Ajukan usulan baru @svg('lucide-arrow-right', 'size-4')</a>
                </div>
            </div>
        </div>
    </section>

    </div>
@endif
</div>

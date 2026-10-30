{{-- Panel detail satu unit (AS1① desktop, AS4② HP): kode, foto & peta, fakta, linimasa, QR. Aksi memakai komponen halaman Terpasang. --}}
@props(['aset', 'bolehCatat' => false, 'kelola' => false, 'penanda' => null])
@php
    $rusak = (bool) $aset->laporanTerbuka;
    $hapus = $aset->dihapuskan();
    $foto = $aset->usulanItem?->fotoHasil ?? collect();
    $asal = $aset->usulanItem?->usulan;
    $rawat = $aset->dirawatTerakhir();
    $url = $aset->urlPindai();
    $lama = $aset->adalahLama();
@endphp
<div class="flex flex-col pb-32 lg:pb-10" x-data="{ qr: false }" @keydown.escape.window="qr = false">
    <button type="button" wire:click="tutupDetail" class="sticky top-0 z-[800] flex w-full items-center gap-2 border-b border-line bg-bg px-4 py-3 text-sm font-medium lg:hidden">@svg('lucide-arrow-left', 'size-4') Kembali</button>

    <div class="flex flex-col gap-5 p-4 lg:gap-5 lg:px-8 lg:py-6">
        {{-- Kepala --}}
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div class="flex min-w-0 flex-col gap-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 font-caption text-[13px] text-fg-muted">
                    <span class="font-semibold text-fg">{{ $aset->jenis->namaPendek() }}@unless ($aset->jenis->per_unit) · {{ number_format($aset->jumlah, 0, ',', '.') }} {{ $aset->jenis->satuan }}@endunless</span>
                    @if ($hapus) <x-badge>Dihapuskan</x-badge> @else <x-badge :tone="$rusak ? 'bahaya' : 'sukses'">{{ $rusak ? 'Dilaporkan rusak' : 'Baik' }}</x-badge> @endif
                    @if ($aset->waktuPasang()) <span>terpasang {{ $aset->waktuPasang() }}</span> @endif
                </div>
                @if ($aset->kode)
                    <div class="flex flex-wrap items-center gap-3" x-data="{ ok: false }">
                        <h2 class="judul text-[30px] leading-[36px] tracking-wide lg:text-[36px] lg:leading-10">{{ $aset->kode }}</h2>
                        <button type="button" @click="navigator.clipboard?.writeText(@js($aset->kode)).then(() => { ok = true; setTimeout(() => ok = false, 1500) })" class="flex items-center gap-1.5 rounded-sm px-2 py-1 font-caption text-xs font-semibold text-accent outline outline-1 -outline-offset-1 outline-accent hover:bg-accent-soft">
                            <span x-show="! ok" class="flex items-center gap-1.5">@svg('lucide-copy', 'size-3.5') Salin</span><span x-show="ok" x-cloak class="flex items-center gap-1.5">@svg('lucide-check', 'size-3.5') Tersalin</span>
                        </button>
                    </div>
                @else
                    <div class="flex flex-col gap-1">
                        <h2 class="judul text-[28px] leading-8 text-warning">Belum berkode</h2>
                        <span class="text-sm text-fg-muted">Kode dibuat otomatis setelah {{ collect([! $aset->jenis->kode ? 'kode jenis diisi admin' : null, ! $aset->kecamatan?->kode ? ($aset->kecamatan_id ? 'kode kecamatan diisi admin' : 'kecamatan diisi') : null, ! \App\Support\KodeRegister::tahunDari($aset) ? 'tahun pasang diisi' : null])->filter()->implode(' dan ') ?: 'datanya lengkap' }}.</span>
                    </div>
                @endif
                <span class="flex items-start gap-1.5 text-sm text-fg-muted">@svg('lucide-map-pin', 'mt-0.5 size-3.5 shrink-0') <span>{{ $aset->lokasi() }}{{ $aset->alamat && $aset->desa ? ', Ds. '.$aset->desa->nama : '' }}{{ $aset->alamat && $aset->kecamatan ? ', Kec. '.$aset->kecamatan->nama : '' }}</span></span>
            </div>
            <div class="fixed inset-x-0 bottom-0 z-[1001] flex gap-2 border-t border-line bg-bg px-4 pb-6 pt-3 shadow-[0_-4px_12px_#0000000a] lg:static lg:z-auto lg:shrink-0 lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
                @if ($aset->kode)
                    <button type="button" @click="qr = true" class="flex h-[38px] flex-1 items-center justify-center gap-2 rounded-sm px-3.5 text-sm font-semibold outline outline-1 -outline-offset-1 outline-fg hover:bg-surface-muted lg:flex-none">@svg('lucide-qr-code', 'size-4') Lihat QR</button>
                @endif
                @if ($hapus)
                    @if ($kelola)
                        <button type="button" wire:click="aktifkanLagi({{ $aset->id }})" wire:loading.attr="disabled" wire:target="aktifkanLagi" class="flex h-[38px] flex-[2] items-center justify-center gap-2 rounded-sm px-4 text-sm font-semibold outline outline-1 -outline-offset-1 outline-fg hover:bg-surface-muted lg:flex-none">@svg('lucide-archive-restore', 'size-4') Aktifkan lagi</button>
                    @endif
                @elseif ($bolehCatat)
                    <button type="button" @click="$dispatch('catat-pemeliharaan', { aset: {{ $aset->id }} })" class="flex h-[38px] flex-[2] items-center justify-center gap-2 rounded-sm bg-accent px-4 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110 lg:flex-none">@svg('lucide-wrench', 'size-4') Catat pemeliharaan</button>
                @endif
                @if ($kelola && ! $hapus)
                    @if ($lama)
                        <button type="button" wire:click="ubah({{ $aset->id }})" class="grid size-[38px] shrink-0 place-items-center rounded-sm text-fg-muted outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted hover:text-fg" aria-label="Ubah" title="Ubah data lama">@svg('lucide-pencil', 'size-4')</button>
                    @endif
                    <button type="button" wire:click="bukaHapus({{ $aset->id }})" class="grid size-[38px] shrink-0 place-items-center rounded-sm text-fg-muted outline outline-1 -outline-offset-1 outline-line hover:bg-danger-bg hover:text-danger" aria-label="Hapus" title="Hapus / hapuskan aset">@svg('lucide-trash-2', 'size-4')</button>
                @endif
            </div>
        </div>

        @if ($hapus)
            <div class="flex gap-3 rounded-md bg-surface-muted p-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-bg text-fg-muted">@svg('lucide-archive-x', 'size-5')</span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="text-[15px] font-semibold">Dihapuskan {{ $aset->dihapuskan_at->translatedFormat('j M Y') }} · {{ $aset->alasanHapusLabel() }}</span>
                    @if ($aset->catatan_hapus) <p class="text-sm leading-[21px] text-fg-muted">{{ $aset->catatan_hapus }}</p> @endif
                    <span class="font-caption text-xs text-fg-muted">Tidak tampil di daftar Terpasang, peta, dan lapor kerusakan.@if ($aset->penghapus) Oleh {{ $aset->penghapus->name }}.@endif</span>
                </div>
                @if ($aset->foto_hapus)
                    <button type="button" @click="$dispatch('lihat-foto', { foto: [{ url: @js($aset->urlFotoHapus()), ket: @js('Dihapuskan · '.$aset->dihapuskan_at->translatedFormat('j M Y')) }], mulai: 0 })" class="size-16 shrink-0 overflow-hidden rounded-sm" aria-label="Lihat foto penghapusan"><img src="{{ $aset->urlFotoHapus() }}" alt="" class="size-full object-cover"></button>
                @endif
            </div>
        @endif

        {{-- Foto saat dipasang + peta --}}
        <div @class(['grid gap-3', 'lg:grid-cols-2' => $foto->isNotEmpty()])>
            @if ($foto->isNotEmpty())
                <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($foto)), mulai: 0 })" class="group relative h-[200px] overflow-hidden rounded-md bg-surface-muted text-left lg:h-[240px]" aria-label="Lihat foto saat dipasang">
                    <img src="{{ $foto->first()->url() }}" alt="Foto saat dipasang" class="size-full object-cover" loading="lazy">
                    <span class="absolute bottom-2 left-2 rounded-sm bg-[#000100]/60 px-2 py-1 font-caption text-xs font-semibold text-fg-inverse">Foto saat dipasang{{ $aset->terpasang_at ? ' · '.$aset->terpasang_at->translatedFormat('j M Y') : '' }}</span>
                </button>
            @endif
            <div class="relative h-[200px] overflow-hidden rounded-md outline outline-1 -outline-offset-1 outline-line lg:h-[240px]">
                <div class="size-full" wire:ignore wire:key="peta-aset-{{ $aset->id }}" x-data="petaMini({{ $aset->lat }}, {{ $aset->lng }}, @js(svg('lucide-'.$aset->jenis->ikon, 'size-[13px]')->toHtml()))"></div>
                <a href="{{ $aset->mapsUrl() }}" target="_blank" rel="noopener" class="absolute inset-x-2 bottom-2 z-[500] flex items-center justify-between gap-2 rounded-sm bg-bg px-3 py-2 font-caption text-xs shadow-sm hover:bg-surface-muted">
                    <span class="font-semibold tabular-nums">{{ number_format($aset->lat, 5, '.', '') }}, {{ number_format($aset->lng, 5, '.', '') }}</span>
                    <span class="flex items-center gap-1 font-semibold text-accent">Buka di Google Maps @svg('lucide-external-link', 'size-3')</span>
                </a>
            </div>
        </div>

        {{-- Fakta --}}
        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 rounded-md bg-surface-muted px-5 py-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                ['Kecamatan', $aset->kecamatan ? $aset->kecamatan->nama.($aset->kecamatan->kode ? ' · '.$aset->kecamatan->kode : '') : '—'],
                ['Tahun pasang', $aset->terpasang_at?->format('Y') ?? ($aset->tahun ?: '—')],
                ['Sumber', null],
                ['Harga satuan', $aset->harga_satuan !== null ? 'Rp '.number_format($aset->harga_satuan, 0, ',', '.') : '—'],
                ['Dirawat terakhir', $rawat ? $rawat->translatedFormat('j M Y') : 'Belum pernah'],
            ] as [$label, $nilai])
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-fg-muted">{{ $label }}</dt>
                    <dd class="truncate text-sm font-semibold">
                        @if ($label === 'Sumber')
                            @if ($asal) <a href="{{ route('usulan', $asal) }}" wire:navigate class="text-accent">{{ $asal->nomor }}</a> @else Sebelum SIPERJAL @endif
                        @else {{ $nilai }} @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        {{-- Riwayat --}}
        <section class="flex flex-col gap-3.5">
            <div class="flex items-center justify-between">
                <h3 class="judul text-[22px]">Riwayat pemeliharaan</h3>
                <span class="font-caption text-xs text-fg-muted">{{ $aset->pemeliharaan->count() ? $aset->pemeliharaan->count().' catatan · terbaru di atas' : 'Belum ada catatan' }}</span>
            </div>
            <x-aset.linimasa :aset="$aset" :penanda="$penanda" />
        </section>
    </div>

    {{-- Modal QR --}}
    @if ($aset->kode)
        <div x-show="qr" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" @click.self="qr = false" role="dialog" aria-modal="true" aria-label="QR aset"
             x-data="{ ok: false,
                 salin() { navigator.clipboard?.writeText(@js($url)).then(() => { this.ok = true; setTimeout(() => this.ok = false, 1500) }) },
                 png() {
                     const xml = new XMLSerializer().serializeToString(this.$refs.kode.querySelector('svg'));
                     const img = new Image();
                     img.onload = () => {
                         const c = document.createElement('canvas'); c.width = c.height = 1000;
                         const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, 1000, 1000); g.imageSmoothingEnabled = false; g.drawImage(img, 0, 0, 1000, 1000);
                         c.toBlob((b) => { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = @js($aset->kode).concat('.png'); a.click(); URL.revokeObjectURL(a.href) });
                     };
                     img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
                 } }">
            <div class="flex w-full flex-col gap-4 rounded-t-xl bg-bg p-6 sm:max-w-sm sm:rounded-md">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">QR aset</span>
                        <h3 class="judul text-2xl tracking-wide">{{ $aset->kode }}</h3>
                    </div>
                    <button type="button" @click="qr = false" class="grid size-9 place-items-center rounded-sm hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-5')</button>
                </div>
                <p class="text-sm leading-[21px] text-fg-muted">Tempel di tiang/badan perlengkapan. Siapa pun yang memindai melihat info &amp; riwayat, lalu bisa melapor kerusakan.</p>
                <div class="flex items-center gap-4 rounded-md bg-surface-muted p-4">
                    <div x-ref="kode" class="size-[120px] shrink-0 rounded-sm bg-white [&>svg]:size-full">{!! \App\Support\Qr::untuk($aset, 240, 4) !!}</div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="font-caption text-[11px] text-fg-muted">Pratinjau label ukuran sedang · 60 × 30 mm</span>
                        <span class="judul text-[17px] leading-5 tracking-wide">{{ $aset->kode }}</span>
                        <span class="truncate font-caption text-xs text-fg-muted">{{ $aset->jenis->namaPendek() }}{{ $aset->kecamatan ? ' · '.$aset->kecamatan->nama : '' }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 rounded-sm bg-bg px-3 py-2 font-caption text-xs outline outline-1 -outline-offset-1 outline-line">
                    <span class="min-w-0 flex-1 truncate">{{ preg_replace('#^https?://#', '', $url) }}</span>
                    <button type="button" @click="salin()" class="shrink-0 font-semibold text-accent"><span x-show="! ok">Salin</span><span x-show="ok" x-cloak>Tersalin</span></button>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" @click="png()" class="flex h-10 items-center justify-center gap-1.5 rounded-sm text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-download', 'size-4') PNG</button>
                    <a href="data:image/svg+xml;charset=utf-8,{{ rawurlencode(\App\Support\Qr::untuk($aset, 480, 4)) }}" download="{{ $aset->kode }}.svg" class="flex h-10 items-center justify-center gap-1.5 rounded-sm text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-download', 'size-4') SVG</a>
                    <form method="POST" action="{{ route('terpasang.label') }}" target="_blank" class="contents">
                        @csrf <input type="hidden" name="ids[]" value="{{ $aset->id }}"><input type="hidden" name="ukuran" value="sedang">
                        <button class="flex h-10 items-center justify-center gap-1.5 rounded-sm bg-accent text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">@svg('lucide-printer', 'size-4') Cetak label</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

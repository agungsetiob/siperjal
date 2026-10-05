<?php

use App\Enums\StatusItem as SI;
use App\Models\Kecamatan;
use App\Models\Riwayat;
use App\Models\Usulan;
use App\Models\UsulanItem;
use App\Support\Rekap;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url] public int $tahun;
    #[Url] public ?int $kecamatan = null;

    public function mount(): void
    {
        $this->tahun ??= now()->year;
    }

    #[Computed]
    public function items()
    {
        return (new Rekap($this->tahun, $this->kecamatan))->items();
    }

    /** Fakta singkat untuk ticker. */
    #[Computed]
    public function fakta(): array
    {
        $items = $this->items;
        $usulan = $items->pluck('usulan')->unique('id');
        $rataHari = fn ($pasangan) => $pasangan->filter(fn ($p) => $p[0] && $p[1])->avg(fn ($p) => $p[0]->diffInDays($p[1]));
        $periksa = $rataHari($usulan->map(fn ($u) => [$u->diajukan_at, $u->diperiksa_at]));
        $putus = $rataHari($items->map(fn ($i) => [$i->usulan->diperiksa_at, $i->diputuskan_at]));
        $teraktif = $usulan->groupBy(fn ($u) => $u->kecamatan?->nama)->filter(fn ($g, $k) => $k)->sortByDesc->count()->keys()->first();
        $ditunda = $items->where('status', SI::Ditunda);

        return array_values(array_filter([
            $usulan->where('diajukan_at', '>=', now()->subWeek())->count().' usulan baru minggu ini',
            $items->where('status', SI::Terpasang)->where('terpasang_at', '>=', now()->subWeek()->startOfDay())->count().' item terpasang minggu ini',
            $periksa !== null ? 'Rata-rata periksa Dishub: '.round($periksa).' hari' : null,
            $putus !== null ? 'Rata-rata keputusan Bappeda: '.round($putus).' hari' : null,
            $ditunda->isNotEmpty() ? $ditunda->count().' item ditunda ke '.$ditunda->max('tahun_target') : null,
            $teraktif ? 'Kecamatan teraktif: '.$teraktif : null,
        ]));
    }

    public function render()
    {
        $items = $this->items;
        $tahap = Rekap::tahap($items);
        $disetujui = $items->whereIn('status', [SI::Disetujui, SI::Terpasang]);
        $perKec = $items->groupBy(fn ($i) => $i->usulan->kecamatan?->nama ?? 'Tanpa kecamatan')
            ->map(fn ($g) => ['total' => $g->count(), 'terpasang' => $g->where('status', SI::Terpasang)->count()])
            ->sortByDesc('total');
        $perJenis = $items->groupBy('jenis_perlengkapan_id')->map(fn ($g) => [
            'jenis' => $g->first()->jenis,
            'masuk' => $g->count(),
            'lolos' => $g->whereIn('status', [SI::Lolos, SI::Disetujui, SI::Ditunda, SI::DitolakBappeda, SI::Terpasang])->count(),
            'disetujui' => $g->whereIn('status', [SI::Disetujui, SI::Terpasang])->count(),
            'terpasang' => $g->where('status', SI::Terpasang)->count(),
            'biaya' => $g->whereIn('status', [SI::Disetujui, SI::Terpasang])->sum->estimasi(),
        ])->sortByDesc('masuk');

        return $this->view([
            'tahap' => $tahap,
            'disetujui' => $disetujui,
            'jumlahUsulan' => $items->pluck('usulan_id')->unique()->count(),
            'menunggu' => $items->whereIn('status', [SI::Menunggu, SI::Lolos])->count(),
            'ditunda' => $items->where('status', SI::Ditunda),
            'perKec' => $perKec->count() > 8
                ? $perKec->take(7)->put('Lainnya ('.($perKec->count() - 7).' kec.)', [
                    'total' => $perKec->slice(7)->sum('total'), 'terpasang' => $perKec->slice(7)->sum('terpasang'),
                ])
                : $perKec,
            'perJenis' => $perJenis,
            'terakhir' => collect([Usulan::max('updated_at'), UsulanItem::max('updated_at'), Riwayat::max('created_at')])->filter()->max(),
            'semuaKecamatan' => Kecamatan::orderBy('nama')->get(),
        ])->title('Laporan · SIPERJAL');
    }
};
?>

@php
    $pct = fn ($a, $b) => $b ? round($a / $b * 100) : 0;
    $masuk = $tahap['masuk']['jumlah'];
    $query = http_build_query(array_filter(['tahun' => $tahun, 'kecamatan' => $kecamatan]));
    $maksKec = max(1, $perKec->max('total'));
    $funnel = [
        ['Diusulkan', $masuk, '100%', 'bg-accent-deep'],
        ['Lolos Dishub', $tahap['lolos']['jumlah'], $pct($tahap['lolos']['jumlah'], $masuk).'%', 'bg-[#1D2554]'],
        ['Disetujui Bappeda', $tahap['disetujui']['jumlah'], $pct($tahap['disetujui']['jumlah'], $tahap['lolos']['jumlah']).'% dari lolos', 'bg-accent'],
        ['Terpasang', $tahap['terpasang']['jumlah'], $pct($tahap['terpasang']['jumlah'], $tahap['disetujui']['jumlah']).'% dari disetujui', 'bg-secondary'],
    ];
@endphp

<div class="bg-bg">
    {{-- ── Kepala ── --}}
    <div class="mx-auto flex max-w-[1440px] flex-col gap-6 px-4 pb-10 pt-10 sm:px-8 lg:flex-row lg:items-end lg:justify-between lg:px-20 lg:pt-14">
        <div class="flex flex-col gap-2">
            <span class="font-caption text-[13px] font-semibold tracking-[0.5px] text-accent">Rekap Usulan Perlengkapan Jalan</span>
            <h1 class="judul text-5xl lg:text-[64px] lg:leading-[64px]">Laporan {{ $tahun }}</h1>
            <span class="text-[15px] text-fg-muted">Diperbarui langsung · data terakhir {{ $terakhir ? \Illuminate\Support\Carbon::parse($terakhir)->translatedFormat('j M Y, H.i') : '—' }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 rounded-sm bg-bg px-3.5 py-2.5 font-caption text-[13px] outline outline-1 -outline-offset-1 outline-line">
                <span class="text-fg-muted">Tahun</span>
                <select wire:model.live="tahun" class="bg-transparent font-semibold outline-none">@foreach (range(now()->year, now()->year - 4) as $t)<option>{{ $t }}</option>@endforeach</select>
            </label>
            <label class="flex items-center gap-2 rounded-sm bg-bg px-3.5 py-2.5 font-caption text-[13px] outline outline-1 -outline-offset-1 outline-line">
                <span class="text-fg-muted">Kecamatan</span>
                <select wire:model.live="kecamatan" class="max-w-40 bg-transparent font-semibold outline-none"><option value="">Semua</option>@foreach ($semuaKecamatan as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select>
            </label>
            <span class="hidden w-3 lg:block"></span>
            <a href="{{ route('laporan.export', 'xlsx') }}?{{ $query }}" class="flex items-center gap-2 rounded-sm px-4 py-[11px] text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-fg hover:bg-surface-muted">@svg('lucide-file-spreadsheet', 'size-[18px]') Export Excel</a>
            <a href="{{ route('laporan.export', 'pdf') }}?{{ $query }}" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Export PDF @svg('lucide-download', 'size-[18px]')</a>
        </div>
    </div>

    {{-- ── Pita ringkasan ── --}}
    <section class="bg-accent">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-8 px-4 pt-12 sm:px-8 lg:px-20">
            <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-end">
                <div class="flex flex-col gap-1.5">
                    <span class="font-caption text-sm text-fg-inverse/80">Total estimasi biaya item disetujui</span>
                    <span class="judul text-5xl text-fg-inverse lg:text-[72px] lg:leading-[72px]">{{ \App\Support\Rekap::rupiahSingkat($tahap['disetujui']['biaya']) }}</span>
                </div>
                <span class="text-[15px] text-fg-inverse/85">dari {{ number_format($disetujui->count(), 0, ',', '.') }} item disetujui Bappeda · tahun anggaran {{ $disetujui->max('tahun_anggaran') ?? $tahun + 1 }}</span>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['inbox', number_format($jumlahUsulan, 0, ',', '.'), 'Usulan masuk', number_format($masuk, 0, ',', '.').' item diminta'],
                    ['clipboard-check', $tahap['lolos']['jumlah'], 'Item lolos Dishub', $pct($tahap['lolos']['jumlah'], $masuk).'% dari item diusulkan'],
                    ['badge-check', $tahap['disetujui']['jumlah'], 'Item disetujui Bappeda', $pct($tahap['disetujui']['jumlah'], $tahap['lolos']['jumlah']).'% dari yang lolos Dishub'],
                    ['map-pin-check', $tahap['terpasang']['jumlah'], 'Item terpasang', 'tampil di peta publik'],
                ] as [$ikon, $nilai, $label, $sub])
                    <div class="flex flex-col gap-3.5 rounded-md bg-bg p-6 shadow-sm">
                        <span class="grid size-10 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$ikon, 'size-5')</span>
                        <div class="flex flex-col gap-1">
                            <span class="judul text-5xl leading-[48px]">{{ $nilai }}</span>
                            <span class="text-[15px] font-semibold">{{ $label }}</span>
                            <span class="font-caption text-[13px] text-fg-muted">{{ $sub }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="mx-auto mt-8 max-w-[1440px] overflow-hidden px-4 sm:px-8 lg:px-20">
            <div class="flex overflow-hidden border-t border-fg-inverse/25 py-4">
                <div class="flex w-max animate-geser items-center gap-7">
                    @foreach ([1, 2] as $_)
                        @foreach ($this->fakta as $f)
                            <span class="judul whitespace-nowrap text-lg tracking-[0.5px] text-fg-inverse">{{ $f }}</span>
                            <span class="text-fg-inverse/60">@svg('lucide-asterisk', 'size-3.5')</span>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── Analisis ── --}}
    <section class="mx-auto flex max-w-[1440px] flex-col gap-16 px-4 pt-16 sm:px-8 lg:flex-row lg:gap-20 lg:px-20 lg:pt-[72px]">
        <div class="flex w-full flex-col gap-7 lg:w-[560px] lg:shrink-0">
            <div class="flex flex-col gap-1.5">
                <h2 class="judul text-[36px] leading-[38px]">Alur item</h2>
                <p class="text-[15px] text-fg-muted">Berapa item yang bertahan di tiap tahap, dari diusulkan sampai terpasang.</p>
            </div>
            <div class="flex flex-col gap-[18px]">
                @foreach ($funnel as [$label, $n, $ket, $warna])
                    <div class="flex flex-col gap-2">
                        <div class="flex items-end gap-2.5">
                            <span class="text-[15px] font-semibold">{{ $label }}</span>
                            <span class="font-caption text-[13px] text-fg-muted">{{ $ket }}</span>
                            <span class="flex-1"></span>
                            <span class="judul text-[26px] leading-[26px]">{{ $n }}</span>
                        </div>
                        <div class="h-7 w-full">
                            <div class="h-full rounded-sm {{ $warna }} transition-all duration-500" style="width: {{ $masuk ? max(2, $n / $masuk * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($ditunda->isNotEmpty() || $menunggu)
                <div class="flex gap-2.5 rounded-md bg-surface-tint p-4">
                    <span class="text-accent-deep">@svg('lucide-info', 'size-[18px]')</span>
                    <p class="text-sm leading-5 text-accent-deep">
                        @if ($ditunda->isNotEmpty()) {{ $ditunda->count() }} item ditunda Bappeda ke tahun {{ $ditunda->max('tahun_target') }} dan @endif
                        {{ $menunggu }} item masih menunggu keputusan — tidak dihitung sebagai ditolak.
                    </p>
                </div>
            @endif
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-7">
            <div class="flex flex-col gap-1.5">
                <h2 class="judul text-[36px] leading-[38px]">Per kecamatan</h2>
                <p class="text-[15px] text-fg-muted">Jumlah item diusulkan dan yang sudah terpasang.</p>
            </div>
            <div class="flex items-center gap-5 font-caption text-[13px] text-fg-muted">
                <span class="flex items-center gap-2"><span class="size-3 rounded-[2px] bg-accent"></span> Terpasang</span>
                <span class="flex items-center gap-2"><span class="size-3 rounded-[2px] bg-accent-soft"></span> Belum terpasang</span>
            </div>
            <div class="flex flex-col gap-3.5">
                @forelse ($perKec as $nama => $k)
                    <div class="flex items-center gap-4">
                        <span class="w-[150px] shrink-0 truncate text-sm">{{ $nama }}</span>
                        <div class="h-5 min-w-0 flex-1 lg:max-w-[380px]">
                            <div class="flex h-full overflow-hidden rounded-sm bg-accent-soft" style="width: {{ $k['total'] / $maksKec * 100 }}%">
                                <div class="h-full bg-accent" style="width: {{ $k['total'] ? $k['terpasang'] / $k['total'] * 100 : 0 }}%"></div>
                            </div>
                        </div>
                        <span class="w-8 shrink-0 font-caption text-sm font-semibold">{{ $k['total'] }}</span>
                        <span class="shrink-0 font-caption text-[13px] text-fg-muted">{{ $k['terpasang'] }} terpasang</span>
                    </div>
                @empty
                    <p class="text-sm text-fg-muted">Belum ada data.</p>
                @endforelse
            </div>
        </div>
    </section>

    {{-- ── Per jenis ── --}}
    <section class="mx-auto flex max-w-[1440px] flex-col gap-7 px-4 pb-20 pt-16 sm:px-8 lg:px-20 lg:pt-[72px]">
        <div class="flex flex-col gap-1.5">
            <h2 class="judul text-[36px] leading-[38px]">Per jenis perlengkapan</h2>
            <p class="text-[15px] text-fg-muted">Estimasi = item disetujui × harga satuan (SSH). Harga satuan diatur Admin di Pengaturan.</p>
        </div>
        <div class="overflow-x-auto rounded-md outline outline-1 -outline-offset-1 outline-line">
            <table class="w-full min-w-[980px] text-sm">
                <thead class="border-b border-line bg-surface-muted font-caption text-xs font-semibold uppercase tracking-[0.3px] text-fg-muted">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold">Jenis</th>
                        <th class="w-[110px] px-2 text-right font-semibold">Diusulkan</th>
                        <th class="w-[120px] px-2 text-right font-semibold">Lolos Dishub</th>
                        <th class="w-[110px] px-2 text-right font-semibold">Disetujui</th>
                        <th class="w-[110px] px-2 text-right font-semibold">Terpasang</th>
                        <th class="w-[160px] px-2 text-right font-semibold">Harga satuan</th>
                        <th class="w-[190px] px-6 text-right font-semibold">Estimasi disetujui</th>
                    </tr>
                </thead>
                <tbody class="font-caption">
                    @forelse ($perJenis as $r)
                        <tr class="border-b border-line">
                            <td class="px-6 py-4">
                                <span class="flex items-center gap-3">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$r['jenis']->ikon, 'size-4')</span>
                                    <span class="font-sans text-[15px] font-semibold">{{ $r['jenis']->nama }}</span>
                                </span>
                            </td>
                            <td class="px-2 text-right">{{ $r['masuk'] }}</td>
                            <td class="px-2 text-right">{{ $r['lolos'] }}</td>
                            <td class="px-2 text-right">{{ $r['disetujui'] }}</td>
                            <td class="px-2 text-right">{{ $r['terpasang'] }}</td>
                            <td class="px-2 text-right"><x-rupiah :nilai="$r['jenis']->harga_satuan" /></td>
                            <td class="px-6 text-right font-sans font-semibold"><x-rupiah :nilai="$r['biaya']" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-10 text-center text-fg-muted">Belum ada data untuk tahun ini.</td></tr>
                    @endforelse
                </tbody>
                @if ($perJenis->isNotEmpty())
                    <tfoot class="bg-surface-soft font-caption font-semibold">
                        <tr>
                            <td class="px-6 py-4">Total</td>
                            <td class="px-2 text-right">{{ number_format($perJenis->sum('masuk'), 0, ',', '.') }}</td>
                            <td class="px-2 text-right">{{ number_format($perJenis->sum('lolos'), 0, ',', '.') }}</td>
                            <td class="px-2 text-right">{{ number_format($perJenis->sum('disetujui'), 0, ',', '.') }}</td>
                            <td class="px-2 text-right">{{ number_format($perJenis->sum('terpasang'), 0, ',', '.') }}</td>
                            <td class="px-2 text-right">—</td>
                            <td class="px-6 text-right font-sans"><x-rupiah :nilai="$perJenis->sum('biaya')" /></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>
</div>

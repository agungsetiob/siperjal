<?php

namespace App\Support;

use App\Enums\StatusItem as SI;
use App\Models\UsulanItem;
use Illuminate\Support\Collection;

/** Data rekap untuk halaman Laporan & export. */
class Rekap
{
    public function __construct(public int $tahun, public ?int $kecamatan = null, public ?int $jenis = null) {}

    /** Item usulan yang diajukan pada tahun terpilih. */
    public function items(): Collection
    {
        return UsulanItem::with('jenis', 'usulan.kecamatan', 'usulan.desa', 'usulan.user', 'usulan.instansi')
            ->whereHas('usulan', fn ($q) => $q->whereYear('diajukan_at', $this->tahun)
                ->when($this->kecamatan, fn ($q) => $q->where('kecamatan_id', $this->kecamatan)))
            ->when($this->jenis, fn ($q) => $q->where('jenis_perlengkapan_id', $this->jenis))
            ->get();
    }

    public static function tahap(Collection $items): array
    {
        $lolos = $items->whereIn('status', [SI::Lolos, SI::Disetujui, SI::Ditunda, SI::DitolakBappeda, SI::Terpasang]);
        $setuju = $items->whereIn('status', [SI::Disetujui, SI::Terpasang]);
        $pasang = $items->where('status', SI::Terpasang);

        return collect(['masuk' => $items, 'lolos' => $lolos, 'disetujui' => $setuju, 'terpasang' => $pasang])
            ->map(fn ($c) => ['jumlah' => $c->count(), 'biaya' => $c->sum->estimasi()])->all();
    }

    /** Rp 2,84 Miliar · Rp 184,5 Juta · Rp 750.000 */
    public static function rupiahSingkat(int $n): string
    {
        return match (true) {
            $n >= 1_000_000_000 => 'Rp '.rtrim(rtrim(number_format($n / 1_000_000_000, 2, ',', '.'), '0'), ',').' Miliar',
            $n >= 1_000_000 => 'Rp '.rtrim(rtrim(number_format($n / 1_000_000, 1, ',', '.'), '0'), ',').' Juta',
            default => 'Rp '.number_format($n, 0, ',', '.'),
        };
    }

    /** Sangat ringkas untuk bar & label sempit: "Rp 1,2 M", "Rp 67,5 jt". */
    public static function rupiahPendek(int $n): string
    {
        $f = fn ($v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

        return match (true) {
            abs($n) >= 1_000_000_000 => 'Rp '.$f($n / 1_000_000_000).' M',
            abs($n) >= 1_000_000 => 'Rp '.$f($n / 1_000_000).' jt',
            default => 'Rp '.number_format($n, 0, ',', '.'),
        };
    }

    public static function per(Collection $items, callable $kunci): Collection
    {
        return $items->groupBy($kunci)->map(fn ($g) => self::tahap($g))->sortKeys();
    }
}

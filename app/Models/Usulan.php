<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StatusItem;
use App\Enums\StatusUsulan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Usulan extends Model
{
    protected $table = 'usulan';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => StatusUsulan::class,
            'kriteria_klaim' => 'array',
            'kriteria_dishub' => 'array',
            'revisi_bagian' => 'array',
            'proposal_otomatis' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'diajukan_at' => 'datetime',
            'diperiksa_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instansi()
    {
        return $this->belongsTo(Instansi::class);
    }

    public function kecamatan()
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function desa()
    {
        return $this->belongsTo(Desa::class);
    }

    public function items()
    {
        return $this->hasMany(UsulanItem::class);
    }

    public function foto()
    {
        return $this->hasMany(UsulanFoto::class)->where('jenis', 'lokasi');
    }

    public function riwayat()
    {
        return $this->hasMany(Riwayat::class)->latest('id');
    }

    public function duplikatDari()
    {
        return $this->belongsTo(Usulan::class, 'duplikat_dari_id');
    }

    /** Usulan yang boleh dilihat user sesuai role. */
    public function scopeTerlihatOleh(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::Pengusul => $user->instansi_id
                ? $query->where('instansi_id', $user->instansi_id)
                : $query->where('user_id', $user->id),
            Role::Bappeda => $query->whereHas('items', fn ($q) => $q->whereNotIn('status', [
                StatusItem::Menunggu, StatusItem::DitolakDishub,
            ])),
            default => $query->where('status', '!=', StatusUsulan::Draft),
        };
    }

    public function namaPengusul(): string
    {
        return $this->instansi?->nama ?? $this->user->name;
    }

    public function jenisPengusul(): string
    {
        return $this->instansi_id ? 'Instansi' : 'Masyarakat';
    }

    public function lokasiSingkat(): string
    {
        return collect([$this->desa?->nama, $this->kecamatan ? 'Kec. '.$this->kecamatan->nama : null])
            ->filter()->implode(', ') ?: ($this->alamat ?? '-');
    }

    public function totalEstimasi(?callable $filter = null): int
    {
        return $this->items->filter($filter ?? fn () => true)->sum(fn (UsulanItem $i) => $i->estimasi());
    }

    public function catat(string $aksi, ?string $keterangan = null, bool $publik = true): void
    {
        $this->riwayat()->create([
            'user_id' => auth()->id(),
            'aksi' => $aksi,
            'keterangan' => $keterangan,
            'publik' => $publik,
        ]);
    }

    public function kirim(): void
    {
        DB::transaction(function () {
            if (! $this->nomor) {
                $tahun = now()->year;
                $urut = static::where('nomor', 'like', "USL-{$tahun}-%")->count() + 1;
                $this->nomor = sprintf('USL-%d-%05d', $tahun, $urut);
            }
            $this->items()->update(['status' => StatusItem::Menunggu]);
            $this->forceFill([
                'status' => StatusUsulan::Diperiksa,
                'diajukan_at' => $this->diajukan_at ?? now(),
                'revisi_bagian' => null,
            ])->save();
        });
    }

    /** Hitung ulang status usulan dari status item-itemnya. */
    public function hitungStatus(): void
    {
        $status = $this->items()->pluck('status');
        $ada = fn (StatusItem ...$s) => $status->contains(fn ($x) => in_array($x, $s, true));

        $baru = match (true) {
            $status->isEmpty(), $ada(StatusItem::Menunggu) => StatusUsulan::Diperiksa,
            $ada(StatusItem::Lolos) => StatusUsulan::MenungguBappeda,
            $ada(StatusItem::Disetujui) => StatusUsulan::Disetujui,
            $ada(StatusItem::Terpasang) => StatusUsulan::Terpasang,
            $ada(StatusItem::Ditunda) => StatusUsulan::Ditunda,
            default => StatusUsulan::Ditolak,
        };

        $this->update(['status' => $baru]);
    }

    /** Usulan aktif lain dalam radius (meter). */
    public function berdekatan(int $radius = 100): Collection
    {
        if (! $this->lat) {
            return new Collection;
        }

        return static::sekitar($this->lat, $this->lng, $radius)
            ->reject(fn ($u) => $u->id === $this->id);
    }

    public static function sekitar(float $lat, float $lng, int $radius = 100): Collection
    {
        $d = $radius / 111000; // derajat kira-kira

        return static::query()
            ->whereNotIn('status', [StatusUsulan::Draft, StatusUsulan::Ditolak, StatusUsulan::Dibatalkan])
            ->whereBetween('lat', [$lat - $d, $lat + $d])
            ->whereBetween('lng', [$lng - $d * 1.5, $lng + $d * 1.5])
            ->with('items.jenis', 'foto', 'user', 'instansi', 'desa', 'kecamatan')
            ->get()
            ->filter(fn ($u) => static::jarak($lat, $lng, $u->lat, $u->lng) <= $radius)
            ->values();
    }

    public static function jarak(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StatusLaporan as SL;
use App\Notifications\LaporanBerubah;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Laporan kerusakan perlengkapan jalan (pemeliharaan). Titik & jenis disimpan di laporan sendiri;
 * bila perlengkapannya tercatat terpasang (`aset`, dari usulan atau data lama), laporan tertaut ke sana;
 * `item` = item usulan asalnya, hanya ada bila perlengkapan itu terpasang lewat usulan.
 * Alur: Baru → Ditugaskan (koordinator Dishub) → Menunggu perbaikan (petugas cek lokasi) → Selesai diperbaiki.
 */
class LaporanKerusakan extends Model
{
    protected $table = 'laporan_kerusakan';

    protected $guarded = [];

    /** kunci => [label, kata pendek untuk judul, ikon] */
    public const KERUSAKAN = [
        'mati' => ['Mati / tidak menyala', 'mati', 'lightbulb-off'],
        'rusak' => ['Rusak', 'rusak', 'hammer'],
        'hilang' => ['Hilang', 'hilang', 'circle-dashed'],
        'pudar' => ['Pudar', 'pudar', 'eye-off'],
        'roboh' => ['Roboh / miring', 'roboh', 'triangle-right'],
        'lainnya' => ['Lainnya', 'rusak', 'ellipsis'],
    ];

    /** Alasan cepat menolak laporan: petugas di lokasi & koordinator di meja. */
    public const ALASAN_PETUGAS = ['Kondisi baik', 'Sudah diperbaiki', 'Lokasi salah'];

    public const ALASAN_KOORDINATOR = ['Laporan iseng', 'Duplikat laporan lain', 'Bukan perlengkapan Dishub'];

    protected function casts(): array
    {
        return [
            'status' => SL::class,
            'ditugaskan_at' => 'datetime',
            'dicek_at' => 'datetime',
            'selesai_at' => 'datetime',
            'lat_cek' => 'float',
            'lng_cek' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function item()
    {
        return $this->belongsTo(UsulanItem::class, 'usulan_item_id');
    }

    public function aset()
    {
        return $this->belongsTo(PerlengkapanTerpasang::class, 'perlengkapan_terpasang_id');
    }

    public function jenis()
    {
        return $this->belongsTo(JenisPerlengkapan::class, 'jenis_perlengkapan_id');
    }

    public function kecamatan()
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function desa()
    {
        return $this->belongsTo(Desa::class);
    }

    public function pelapor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function petugas()
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function penugas()
    {
        return $this->belongsTo(User::class, 'ditugaskan_oleh');
    }

    public function penutup()
    {
        return $this->belongsTo(User::class, 'ditutup_oleh');
    }

    public function foto()
    {
        return $this->hasMany(LaporanFoto::class)->orderBy('id');
    }

    public function scopeTerlihatOleh(Builder $query, User $user): void
    {
        match (true) {
            $user->role === Role::Pengusul => $query->where('user_id', $user->id),
            $user->isPetugasLapangan() => $query->where('petugas_id', $user->id),
            $user->hasRole(Role::Dishub, Role::Admin) => null,
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function scopeAktif(Builder $query): void
    {
        $query->whereIn('status', [SL::Baru, SL::Ditugaskan, SL::MenungguPerbaikan]);
    }

    /** Perlengkapan yang boleh dilaporkan rusak: semua yang tercatat terpasang. */
    public static function perlengkapanTerpasang(): \Illuminate\Database\Eloquent\Collection
    {
        return PerlengkapanTerpasang::with('jenis', 'desa', 'kecamatan')->get();
    }

    /** Perlengkapan terpasang di sekitar titik laporan (ditawarkan agar laporan tertaut), terdekat dulu. */
    public static function terpasangSekitar(float $lat, float $lng, ?int $radius = null): \Illuminate\Support\Collection
    {
        return PerlengkapanTerpasang::sekitar($lat, $lng, $radius ?? config('usuljalan.radius_lapor', 200));
    }

    public static function petugasLapangan(): Collection
    {
        return User::where('role', Role::Dishub)->where('aktif', true)
            ->withCount(['tugasLaporan as tugas_aktif' => fn ($q) => $q->aktif()])
            ->get()
            // petugas lapangan didahulukan, lalu yang paling sedikit tugas aktifnya
            ->sortBy(fn ($u) => [$u->petugas_lapangan ? 0 : 1, $u->tugas_aktif, $u->name])->values();
    }

    /** Boleh cek kondisi / tandai selesai: koordinator, atau petugas lapangan yang ditugaskan. */
    public function bolehDikerjakan(User $user): bool
    {
        return $user->isKoordinator() || ($user->isPetugasLapangan() && $this->petugas_id === $user->id);
    }

    // ── Tampilan ──

    /** Nama pelapor: akun, atau nama yang diisi pelapor publik. */
    public function namaPelapor(): string
    {
        return $this->pelapor?->name ?? $this->nama_pelapor ?? 'Warga (tanpa akun)';
    }

    public function inisialPelapor(): string
    {
        return $this->pelapor?->inisial() ?? str($this->namaPelapor())->explode(' ')->filter()->take(2)->map(fn ($k) => mb_strtoupper(mb_substr($k, 0, 1)))->implode('');
    }

    public function kerusakanLabel(): string
    {
        return self::KERUSAKAN[$this->kerusakan][0] ?? ucfirst($this->kerusakan);
    }

    public function judul(): string
    {
        return ($this->jenis?->namaPendek() ?? 'Perlengkapan').' '.(self::KERUSAKAN[$this->kerusakan][1] ?? $this->kerusakan);
    }

    public function ikon(): string
    {
        return $this->jenis?->ikon ?? 'wrench';
    }

    public function lokasi(): string
    {
        return $this->alamat ?: $this->lokasiSingkat();
    }

    public function lokasiSingkat(): string
    {
        return collect([$this->desa?->nama, $this->kecamatan ? 'Kec. '.$this->kecamatan->nama : null])
            ->filter()->implode(', ') ?: ($this->alamat ?? '-');
    }

    public function lat(): ?float
    {
        return $this->lat ?: null;
    }

    public function lng(): ?float
    {
        return $this->lng ?: null;
    }

    public function fotoJenis(string $jenis): Collection
    {
        return $this->foto->where('jenis', $jenis)->values();
    }

    /** Kalimat status untuk pelapor (kartu & detail). */
    public function kabarSingkat(): string
    {
        return match ($this->status) {
            SL::Baru => 'Laporan diterima · menunggu Dishub',
            SL::Ditugaskan => 'Petugas ditugaskan · cek lokasi',
            SL::MenungguPerbaikan => 'Rusak dikonfirmasi · antre perbaikan',
            SL::Selesai => 'Sudah diperbaiki · terima kasih!',
            SL::Ditolak => 'Ditolak · '.str($this->alasan_tolak)->lower(),
            SL::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Stepper 4 langkah, dipakai di semua layar.
     *
     * @return array<int, array{label: string, keadaan: string, ket: ?string}> keadaan: selesai | aktif | gagal | nanti
     */
    public function langkah(): array
    {
        $s = $this->status;
        $tgl = fn ($t) => $t?->translatedFormat('j M Y');
        $petugas = $this->petugas?->name;

        $cek = match ($s) {
            SL::Baru => ['aktif', 'Menunggu Dishub menugaskan petugas'],
            SL::Ditugaskan => ['aktif', "{$petugas} ditugaskan · ".$tgl($this->ditugaskan_at)],
            SL::Ditolak => ['gagal', 'Ditolak · '.$this->alasan_tolak],
            SL::Dibatalkan => ['gagal', 'Dibatalkan pelapor'],
            default => ['selesai', collect([$petugas, 'rusak dikonfirmasi', $tgl($this->dicek_at)])->filter()->implode(' · ')],
        };

        return [
            ['label' => 'Dilaporkan', 'keadaan' => 'selesai', 'ket' => $tgl($this->created_at)],
            ['label' => 'Dicek petugas', 'keadaan' => $cek[0], 'ket' => $cek[1]],
            ['label' => 'Menunggu perbaikan', 'keadaan' => match ($s) { SL::MenungguPerbaikan => 'aktif', SL::Selesai => 'selesai', default => 'nanti' },
                'ket' => $s === SL::MenungguPerbaikan ? 'Sejak '.$this->dicek_at?->translatedFormat('j M').' · antrean perbaikan Dishub' : null],
            ['label' => 'Selesai diperbaiki', 'keadaan' => $s === SL::Selesai ? 'selesai' : 'nanti', 'ket' => $s === SL::Selesai ? $tgl($this->selesai_at) : 'Pelapor dikabari beserta foto hasil'],
        ];
    }

    // ── Aksi ──

    /** Jenis & titik laporan yang tertaut ke perlengkapan terpasang. */
    public static function tempatDari(PerlengkapanTerpasang $aset): array
    {
        return [
            'aset' => $aset,
            'jenis_id' => $aset->jenis_perlengkapan_id,
            'lat' => $aset->lat,
            'lng' => $aset->lng,
            'alamat' => $aset->alamat,
            'kecamatan_id' => $aset->kecamatan_id,
            'desa_id' => $aset->desa_id,
        ];
    }

    /**
     * Buat laporan. Satu perlengkapan terpasang hanya punya satu laporan berjalan: bila sudah ada, laporan itu yang dikembalikan.
     *
     * `$tempat`: aset?, jenis_id, lat, lng, alamat, kecamatan_id, desa_id. Bila `aset` diisi, jenis & titik diambil dari aset.
     *
     * @param  array<int, array{file: \Illuminate\Http\UploadedFile, lat?: ?float, lng?: ?float}>  $foto
     * @param  array{nama_pelapor?: ?string, kontak_email?: ?string}  $publik  pelapor tanpa akun
     */
    public static function buat(array $tempat, ?User $pelapor, string $kerusakan, ?string $catatan, array $foto, array $publik = []): self
    {
        $aset = $tempat['aset'] ?? null;
        if ($aset) {
            $tempat = static::tempatDari($aset);
            if ($ada = static::aktif()->where('perlengkapan_terpasang_id', $aset->id)->first()) {
                return $ada;
            }
        }
        $laporan = DB::transaction(function () use ($aset, $tempat, $pelapor, $kerusakan, $catatan, $publik) {
            $tahun = now()->year;
            $urut = static::where('nomor', 'like', "LPR-{$tahun}-%")->count() + 1;

            return static::create([
                'nomor' => sprintf('LPR-%d-%05d', $tahun, $urut),
                'usulan_item_id' => $aset?->usulan_item_id,
                'perlengkapan_terpasang_id' => $aset?->id,
                'jenis_perlengkapan_id' => $tempat['jenis_id'] ?? null,
                'lat' => $tempat['lat'] ?? null,
                'lng' => $tempat['lng'] ?? null,
                'alamat' => ($tempat['alamat'] ?? null) ?: null,
                'kecamatan_id' => $tempat['kecamatan_id'] ?? null,
                'desa_id' => $tempat['desa_id'] ?? null,
                'user_id' => $pelapor?->id,
                'nama_pelapor' => $pelapor ? null : (($publik['nama_pelapor'] ?? null) ?: null),
                'kontak_email' => $pelapor ? null : (($publik['kontak_email'] ?? null) ?: null),
                'kerusakan' => $kerusakan,
                'catatan' => $catatan ?: null,
                'status' => SL::Baru,
            ]);
        });
        $laporan->simpanFoto($foto, 'pelapor');
        $asal = $aset?->usulanItem?->usulan;
        $asal?->catat("{$aset->jenis->namaPendek()} dilaporkan rusak ({$laporan->nomor})", $laporan->kerusakanLabel());
        $laporan->kabari("Laporan kerusakan baru: {$laporan->judul()}", $laporan->lokasi().' · dari '.$laporan->namaPelapor(), 'kerusakan_baru',
            User::where('role', Role::Dishub)->where('petugas_lapangan', false)->where('aktif', true)->get(), pelapor: false);

        return $laporan;
    }

    public function tugaskan(User $petugas, ?string $catatan = null): void
    {
        $this->update([
            'status' => SL::Ditugaskan,
            'petugas_id' => $petugas->id,
            'ditugaskan_oleh' => auth()->id(),
            'catatan_tugas' => $catatan ?: null,
            'ditugaskan_at' => now(),
        ]);
        $this->kabari("Tugas baru: cek {$this->judul()}", $this->lokasi().($catatan ? " · “{$catatan}”" : ''), 'kerusakan_tugas', collect([$petugas]), pelapor: false);
        $this->kabari('Petugas ditugaskan', "{$petugas->name} akan cek {$this->judul()} di {$this->lokasi()}.", 'kerusakan_tugas');
    }

    /** Hasil cek lokasi petugas: rusak → menunggu perbaikan, tidak rusak → ditolak. */
    public function cek(bool $rusak, array $foto, ?string $catatan, ?string $alasan = null, ?float $lat = null, ?float $lng = null): void
    {
        $this->simpanFoto($foto, 'cek');
        $this->update([
            'status' => $rusak ? SL::MenungguPerbaikan : SL::Ditolak,
            'petugas_id' => $this->petugas_id ?? auth()->id(),
            'catatan_cek' => $catatan ?: null,
            'lat_cek' => $lat,
            'lng_cek' => $lng,
            'dicek_at' => now(),
            'alasan_tolak' => $rusak ? null : $alasan,
            'ditutup_oleh' => $rusak ? null : auth()->id(),
        ]);
        $nama = auth()->user()->name;
        $rusak
            ? $this->kabari('Kerusakan dikonfirmasi petugas', "{$nama} sudah cek: ".($catatan ?: str($this->judul())->lower()).'. Masuk antrean perbaikan Dishub.', 'kerusakan_dikonfirmasi')
            : $this->kabari('Laporan ditolak petugas', "{$alasan} saat dicek. Kamu bisa lapor lagi bila rusak kembali.", 'kerusakan_ditolak');
    }

    /** Koordinator menolak tanpa cek lokasi (laporan iseng/duplikat). */
    public function tolak(string $alasan): void
    {
        $this->update(['status' => SL::Ditolak, 'alasan_tolak' => $alasan, 'ditutup_oleh' => auth()->id()]);
        $this->kabari('Laporan ditolak Dishub', "{$alasan}. Kamu bisa lapor lagi bila memang rusak.", 'kerusakan_ditolak');
    }

    public function selesai(array $foto = [], ?string $catatan = null): void
    {
        $this->simpanFoto($foto, 'hasil');
        $this->update(['status' => SL::Selesai, 'selesai_at' => now(), 'catatan_selesai' => $catatan ?: null, 'ditutup_oleh' => auth()->id()]);
        $this->item?->usulan->catat("{$this->item->jenis->namaPendek()} selesai diperbaiki ({$this->nomor})", $catatan ?: null);
        $hasil = $this->fotoJenis('hasil')->last()?->url();
        $isi = "{$this->judul()} di {$this->lokasi()} sudah diperbaiki. Terima kasih sudah melapor!";
        $this->kabari('Selesai diperbaiki', $isi, 'kerusakan_selesai', ekstra: ['foto' => $hasil]);
        // pengusul asli perlengkapan (bila tertaut) ikut dikabari
        if ($asal = $this->item?->usulan) {
            $pengusul = $asal->instansi_id ? User::where('instansi_id', $asal->instansi_id)->get() : collect([$asal->user]);
            $this->kabari("Perlengkapan usulan {$asal->nomor} sudah diperbaiki", $isi, 'kerusakan_selesai',
                $pengusul->reject(fn ($u) => $u->id === $this->user_id), pelapor: false, ekstra: ['foto' => $hasil]);
        }
        if ($this->petugas_id && $this->petugas_id !== auth()->id()) {
            $this->kabari("{$this->judul()} selesai diperbaiki", $this->lokasi(), 'kerusakan_selesai', collect([$this->petugas]), pelapor: false);
        }
    }

    /** Urungkan "Selesai diperbaiki" (10 menit). */
    public function batalSelesai(): void
    {
        // kabar "Selesai diperbaiki" ikut ditarik (fotonya akan dihapus)
        \Illuminate\Notifications\DatabaseNotification::where('type', LaporanBerubah::class)
            ->where('created_at', '>=', $this->selesai_at)->get()
            ->filter(fn ($n) => ($n->data['laporan_id'] ?? null) === $this->id && ($n->data['jenis'] ?? null) === 'kerusakan_selesai')
            ->each->delete();
        $this->fotoJenis('hasil')->each(function ($f) {
            Storage::disk('public')->delete($f->path);
            $f->delete();
        });
        $this->update(['status' => SL::MenungguPerbaikan, 'selesai_at' => null, 'catatan_selesai' => null, 'ditutup_oleh' => null]);
        $this->item?->usulan->riwayat()->where('aksi', 'like', "%selesai diperbaiki ({$this->nomor})")->where('created_at', '>=', now()->subMinutes(10))->delete();
    }

    public function batalkan(): void
    {
        $this->update(['status' => SL::Dibatalkan, 'ditutup_oleh' => auth()->id()]);
    }

    /** @param  array<int, array{file: \Illuminate\Http\UploadedFile, lat?: ?float, lng?: ?float}>  $foto */
    private function simpanFoto(array $foto, string $jenis): void
    {
        foreach ($foto as $f) {
            $this->foto()->create([
                'jenis' => $jenis,
                'path' => $f['file']->store("laporan/{$this->id}", 'public'),
                'lat' => $f['lat'] ?? null,
                'lng' => $f['lng'] ?? null,
            ]);
        }
        $this->unsetRelation('foto');
    }

    /** Kirim kabar (lonceng + email) ke pelapor dan/atau penerima lain, kecuali diri sendiri. */
    private function kabari(string $judul, ?string $isi, string $jenis, ?Collection $penerima = null, bool $pelapor = true, array $ekstra = []): void
    {
        $penerima = collect($penerima ?? [])->when($pelapor, fn ($c) => $c->push($this->pelapor));
        Notification::send(
            $penerima->filter()->unique('id')->reject(fn ($p) => $p->id === auth()->id()),
            new LaporanBerubah($this, $judul, $isi, $jenis, $ekstra),
        );
        // pelapor publik: hanya email, bila mengisinya
        if ($pelapor && ! $this->user_id && $this->kontak_email) {
            Notification::route('mail', $this->kontak_email)->notify(new LaporanBerubah($this, $judul, $isi, $jenis, $ekstra));
        }
    }
}

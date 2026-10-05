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
 * Laporan kerusakan perlengkapan yang sudah terpasang (pemeliharaan).
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
        ];
    }

    public function item()
    {
        return $this->belongsTo(UsulanItem::class, 'usulan_item_id');
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
        match ($user->role) {
            Role::Pengusul => $query->where('user_id', $user->id),
            Role::Dishub, Role::Admin => null,
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function scopeAktif(Builder $query): void
    {
        $query->whereIn('status', [SL::Baru, SL::Ditugaskan, SL::MenungguPerbaikan]);
    }

    /** Perlengkapan yang boleh dilaporkan rusak: item terpasang. */
    public static function perlengkapanTerpasang(): \Illuminate\Database\Eloquent\Collection
    {
        return UsulanItem::with('jenis', 'usulan.desa', 'usulan.kecamatan')
            ->where('status', \App\Enums\StatusItem::Terpasang)->get()
            ->filter(fn ($i) => $i->lat_terpasang ?? $i->usulan->lat)->values();
    }

    public static function petugasLapangan(): Collection
    {
        return User::where('role', Role::Dishub)->where('aktif', true)
            ->withCount(['tugasLaporan as tugas_aktif' => fn ($q) => $q->aktif()])
            ->get()
            // petugas lapangan didahulukan, lalu yang paling sedikit tugas aktifnya
            ->sortBy(fn ($u) => [str_contains(strtolower((string) $u->jabatan), 'lapangan') ? 0 : 1, $u->tugas_aktif, $u->name])->values();
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
        return $this->item->jenis->namaPendek().' '.(self::KERUSAKAN[$this->kerusakan][1] ?? $this->kerusakan);
    }

    public function lokasi(): string
    {
        $u = $this->item->usulan;

        return $u->alamat ?: $u->lokasiSingkat();
    }

    public function lat(): ?float
    {
        return (float) ($this->item->lat_terpasang ?? $this->item->usulan->lat) ?: null;
    }

    public function lng(): ?float
    {
        return (float) ($this->item->lng_terpasang ?? $this->item->usulan->lng) ?: null;
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

    /**
     * Buat laporan. Satu perlengkapan hanya punya satu laporan berjalan: bila sudah ada, laporan itu yang dikembalikan.
     *
     * @param  array<int, array{file: \Illuminate\Http\UploadedFile, lat?: ?float, lng?: ?float}>  $foto
     * @param  array{nama_pelapor?: ?string, kontak_email?: ?string}  $publik  pelapor tanpa akun
     */
    public static function buat(UsulanItem $item, ?User $pelapor, string $kerusakan, ?string $catatan, array $foto, array $publik = []): self
    {
        if ($ada = static::aktif()->where('usulan_item_id', $item->id)->first()) {
            return $ada;
        }
        $laporan = DB::transaction(function () use ($item, $pelapor, $kerusakan, $catatan, $publik) {
            $tahun = now()->year;
            $urut = static::where('nomor', 'like', "LPR-{$tahun}-%")->count() + 1;

            return static::create([
                'nomor' => sprintf('LPR-%d-%05d', $tahun, $urut),
                'usulan_item_id' => $item->id,
                'user_id' => $pelapor?->id,
                'nama_pelapor' => $pelapor ? null : (($publik['nama_pelapor'] ?? null) ?: null),
                'kontak_email' => $pelapor ? null : (($publik['kontak_email'] ?? null) ?: null),
                'kerusakan' => $kerusakan,
                'catatan' => $catatan ?: null,
                'status' => SL::Baru,
            ]);
        });
        $laporan->simpanFoto($foto, 'pelapor');
        $item->usulan->catat("{$item->jenis->namaPendek()} dilaporkan rusak ({$laporan->nomor})", $laporan->kerusakanLabel());
        $laporan->kabari("Laporan kerusakan baru: {$laporan->judul()}", $laporan->lokasi().' · dari '.$laporan->namaPelapor(), 'kerusakan_baru',
            User::where('role', Role::Dishub)->where('aktif', true)->get(), pelapor: false);

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
        $this->item->usulan->catat("{$this->item->jenis->namaPendek()} selesai diperbaiki ({$this->nomor})", $catatan ?: null);
        $hasil = $this->fotoJenis('hasil')->last()?->url();
        $isi = "{$this->judul()} di {$this->lokasi()} sudah diperbaiki. Terima kasih sudah melapor!";
        $this->kabari('Selesai diperbaiki', $isi, 'kerusakan_selesai', ekstra: ['foto' => $hasil]);
        // pengusul asli perlengkapan ikut dikabari
        $asal = $this->item->usulan;
        $pengusul = $asal->instansi_id ? User::where('instansi_id', $asal->instansi_id)->get() : collect([$asal->user]);
        $this->kabari("Perlengkapan usulan {$asal->nomor} sudah diperbaiki", $isi, 'kerusakan_selesai',
            $pengusul->reject(fn ($u) => $u->id === $this->user_id), pelapor: false, ekstra: ['foto' => $hasil]);
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
        $this->item->usulan->riwayat()->where('aksi', 'like', "%selesai diperbaiki ({$this->nomor})")->where('created_at', '>=', now()->subMinutes(10))->delete();
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

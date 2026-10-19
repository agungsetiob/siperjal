<?php

use App\Enums\Kriteria;
use App\Enums\Role;
use App\Enums\StatusItem as SI;
use App\Enums\StatusUsulan as SU;
use App\Models\User;
use App\Models\Usulan;
use App\Notifications\UsulanBerubah;
use App\Support\Urungkan;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Reactive]
    public int $usulanId;

    public int $posisi = 0;

    public int $total = 0;

    /** Item yang sedang dicentang di daftar Bappeda (disinkronkan lewat event). */
    public array $pilihan = [];

    #[\Livewire\Attributes\On('pilihan-berubah')]
    public function pilihanBerubah(array $ids): void
    {
        $this->pilihan = array_map('intval', $ids);
    }

    // Periksa (Dishub)
    public array $kriteriaDishub = [];
    public array $keputusan = [];   // item_id => lolos|tolak  (Dishub)  /  setujui|tunda|tolak (Bappeda)
    public array $jumlah = [];      // item_id => jumlah disetujui
    public array $catatan = [];     // item_id => catatan
    public ?string $status_jalan = null;
    public int $tahun;

    // Dialog
    public ?string $dialog = null;  // revisi, tolak, duplikat, terpasang
    public array $revisiBagian = [];
    public string $dialogCatatan = '';
    public ?int $duplikatDari = null;   // id usulan yang dipilih sebagai asli
    public ?int $bandingId = null;      // usulan berdekatan yang sedang dibandingkan
    public ?int $itemTolak = null;
    public ?string $alasanTolak = null;

    // Ubah keputusan (koreksi, Dishub & Bappeda)
    public bool $mengubah = false;
    public array $tahunItem = [];   // item_id => tahun (anggaran bila disetujui, target bila ditunda)

    // Tandai terpasang
    public ?int $itemTerpasang = null;
    public $fotoHasil;
    public array $fotoHasilKoordinat = [];
    public ?int $jumlahTerpasang = null;
    public string $tanggalTerpasang = '';

    /** Usulan tidak bisa dibuka (dihapus / tidak lagi terlihat oleh peran ini). */
    public bool $hilang = false;

    public function mount(): void
    {
        $this->tahun = now()->year + 1;
        $this->muat();
    }

    public function updatedUsulanId(): void
    {
        $this->reset('dialog', 'mengubah', 'dialogCatatan', 'kriteriaDishub', 'keputusan', 'jumlah', 'catatan', 'status_jalan', 'tahunItem');
        unset($this->usulan);
        $this->muat();
    }

    public function muat(): void
    {
        $this->hilang = ! Usulan::terlihatOleh(auth()->user())->whereKey($this->usulanId)->exists();
        if (! $this->hilang) {
            unset($this->usulan);
            $this->siapkan();
        }
    }

    #[Computed]
    public function usulan(): Usulan
    {
        return Usulan::terlihatOleh(auth()->user())
            ->with(['items.jenis', 'items.fotoHasil', 'items.pemutus', 'foto', 'user', 'instansi', 'desa', 'kecamatan', 'riwayat.user', 'duplikatDari'])
            ->findOrFail($this->usulanId);
    }

    #[Computed]
    public function berdekatan()
    {
        return $this->user->hasRole(Role::Dishub)
            ? $this->usulan->berdekatan(config('usuljalan.radius_berdekatan'))
            : collect();
    }

    #[Computed]
    public function user(): User
    {
        return auth()->user();
    }

    /** Isi awal panel sesuai keadaan usulan. */
    private function siapkan(): void
    {
        $u = $this->usulan;
        $this->kriteriaDishub = $u->kriteria_dishub ?? $u->kriteria_klaim ?? [];
        $this->status_jalan = $u->status_jalan;
        foreach ($u->items as $i) {
            $this->jumlah[$i->id] = $i->jumlahEfektif();
            $this->catatan[$i->id] = $this->user->hasRole(Role::Bappeda) ? ($i->catatan_bappeda ?? '') : ($i->catatan_dishub ?? '');
            $this->keputusan[$i->id] = $this->keputusanAwal($i);
            $this->tahunItem[$i->id] = $i->tahun_target ?? $i->tahun_anggaran ?? $this->tahun;
        }
    }

    /** Keputusan tersimpan untuk item, dari sudut pandang peran yang sedang masuk. */
    private function keputusanAwal(\App\Models\UsulanItem $i): string
    {
        return match (true) {
            $this->user->hasRole(Role::Dishub) => $i->status === SI::DitolakDishub ? 'tolak' : 'lolos',
            $i->status === SI::Ditunda => 'tunda',
            $i->status === SI::DitolakBappeda => 'tolak',
            default => 'setujui',
        };
    }

    // ── Hak aksi
    #[Computed]
    public function bolehPeriksa(): bool
    {
        return $this->user->hasRole(Role::Dishub) && $this->usulan->status === SU::Diperiksa;
    }

    /** Item yang sedang menunggu keputusan Bappeda (lolos Dishub atau ditunda). */
    #[Computed]
    public function itemBappeda()
    {
        return $this->user->hasRole(Role::Bappeda)
            ? $this->usulan->items->filter(fn ($i) => in_array($i->status, [SI::Lolos, SI::Ditunda]))
            : collect();
    }

    #[Computed]
    public function bolehUbahDishub(): bool
    {
        $u = $this->usulan;

        return $this->user->hasRole(Role::Dishub) && $u->diperiksa_at && $u->status !== SU::Diperiksa
            && $u->items->every(fn ($i) => in_array($i->status, [SI::Lolos, SI::DitolakDishub]));
    }

    #[Computed]
    public function bolehUbahBappeda(): bool
    {
        return $this->user->hasRole(Role::Bappeda) && $this->itemUbahBappeda->isNotEmpty();
    }

    /** Item yang sudah diputuskan Bappeda dan belum terpasang. */
    #[Computed]
    public function itemUbahBappeda()
    {
        return $this->usulan->items->filter(fn ($i) => in_array($i->status, [SI::Disetujui, SI::Ditunda, SI::DitolakBappeda]));
    }

    /** Sedang memeriksa (baru) atau membuka ulang hasil pemeriksaan. */
    #[Computed]
    public function modePeriksa(): bool
    {
        return $this->bolehPeriksa || ($this->mengubah && $this->bolehUbahDishub);
    }

    // ── Dishub: periksa
    public function toggleKriteria(string $k): void
    {
        $this->kriteriaDishub = in_array($k, $this->kriteriaDishub)
            ? array_values(array_diff($this->kriteriaDishub, [$k]))
            : [...$this->kriteriaDishub, $k];
    }

    private function periksaValid(): bool
    {
        $this->resetErrorBag();
        $skor = count($this->kriteriaDishub);
        foreach ($this->usulan->items as $i) {
            if (($this->keputusan[$i->id] ?? 'lolos') === 'lolos' && $skor === 0) {
                $this->addError('skor', 'Skor 0 — tandai minimal 1 kriteria untuk meloloskan item.');
            }
            if (($this->keputusan[$i->id] ?? '') === 'tolak' && blank($this->catatan[$i->id] ?? '')) {
                $this->addError("catatan.{$i->id}", 'Tulis alasan singkat.');
            }
        }

        return $this->getErrorBag()->isEmpty();
    }

    public function loloskan(int $id): void
    {
        abort_unless($this->modePeriksa, 403);
        $this->keputusan[$id] = 'lolos';
        $this->catatan[$id] = '';
    }

    /** Pilihan alasan tolak: label chip → kalimat untuk pengusul. */
    public function alasanTolakList(?\App\Models\UsulanItem $i = null): array
    {
        $j = $i?->jenis->namaPendek() ?? 'Perlengkapan ini';

        return [
            'kabupaten' => ['Lokasi bukan jalan kabupaten', "Lokasi ini jalan desa, bukan jalan kabupaten, jadi di luar kewenangan Dishub. {$j} bisa diajukan lewat Dana Desa."],
            'serupa' => ['Sudah ada perlengkapan serupa', "Di lokasi ini sudah ada perlengkapan serupa yang masih berfungsi, jadi {$j} belum diperlukan."],
            'standar' => ['Tidak sesuai standar teknis', "{$j} di titik ini tidak sesuai standar teknis pemasangan (jarak atau posisi)."],
            'foto' => ['Foto kurang jelas', "Foto belum menunjukkan kondisi lokasi dengan jelas, jadi kebutuhan {$j} belum bisa dipastikan."],
        ];
    }

    public function bukaTolak(int $id): void
    {
        abort_unless($this->modePeriksa && $this->usulan->items->contains('id', $id), 403);
        $this->resetErrorBag();
        $this->itemTolak = $id;
        $this->dialogCatatan = $this->catatan[$id] ?? '';
        $this->alasanTolak = collect($this->alasanTolakList($this->usulan->items->firstWhere('id', $id)))
            ->search(fn ($a) => $a[1] === $this->dialogCatatan) ?: null;
        $this->dialog = 'tolak';
    }

    public function pilihAlasanTolak(string $kunci): void
    {
        $daftar = $this->alasanTolakList($this->usulan->items->firstWhere('id', $this->itemTolak));
        if (! isset($daftar[$kunci])) {
            return;
        }
        $lepas = $this->alasanTolak === $kunci;
        $this->alasanTolak = $lepas ? null : $kunci;
        $this->dialogCatatan = $lepas ? '' : $daftar[$kunci][1];
    }

    public function tolakItem(): void
    {
        abort_unless($this->modePeriksa && $this->itemTolak, 403);
        $this->validate(['dialogCatatan' => 'required|string|max:300'], ['dialogCatatan.required' => 'Tulis alasan untuk pengusul.'], ['dialogCatatan' => 'catatan']);
        $this->keputusan[$this->itemTolak] = 'tolak';
        $this->catatan[$this->itemTolak] = trim($this->dialogCatatan);
        $this->reset('dialog', 'dialogCatatan', 'itemTolak', 'alasanTolak');
    }

    /** @return bool berhasil — tombol menampilkan "Tersimpan" lalu membuka usulan berikutnya (lihat Alpine `simpan()`). */
    public function simpanPeriksa(): bool
    {
        abort_unless($this->bolehPeriksa, 403);
        if (! $this->periksaValid()) {
            return false;
        }
        $u = $this->usulan;
        $skor = count($this->kriteriaDishub);

        $token = Urungkan::simpan([$u->id]);
        foreach ($u->items as $i) {
            $lolos = $this->keputusan[$i->id] === 'lolos';
            $i->update([
                'status' => $lolos ? SI::Lolos : SI::DitolakDishub,
                'jumlah_disetujui' => max(1, (int) ($this->jumlah[$i->id] ?? $i->jumlah)),
                'catatan_dishub' => $this->catatan[$i->id] ?: null,
            ]);
        }
        $u->update(['kriteria_dishub' => $this->kriteriaDishub, 'skor' => $skor, 'status_jalan' => $this->status_jalan, 'diperiksa_at' => now()]);
        $u->hitungStatus();

        $jmlLolos = collect($this->keputusan)->filter(fn ($k) => $k === 'lolos')->count();
        $u->catat("Diperiksa Dishub · {$jmlLolos} dari {$u->items->count()} item lolos · skor {$skor}");
        $this->kabari($u, $jmlLolos ? "{$jmlLolos} item lolos pemeriksaan Dishub" : 'Usulan tidak lolos pemeriksaan', $jmlLolos ? [Role::Bappeda] : [], $jmlLolos ? 'Selanjutnya menunggu keputusan Bappeda.' : collect($this->catatan)->filter()->unique()->implode('; '), $jmlLolos ? 'diperiksa' : 'ditolak');

        $this->dispatch('toast', pesan: "Pemeriksaan disimpan · {$jmlLolos} item lolos", urungkan: $token);
        $this->skipRender(); // panel tetap tampil sampai usulan berikutnya dibuka

        return true;
    }

    /** Chip alasan cepat: tambahkan/hapus kalimat di catatan & tandai bagiannya. */
    public function alasanRevisi(string $bagian): void
    {
        $teks = ['foto' => 'Foto kurang jelas', 'lokasi' => 'Lokasi kurang tepat', 'kebutuhan' => 'Jenis/jumlah perlu disesuaikan', 'proposal' => 'Proposal belum lengkap'][$bagian] ?? null;
        if (! $teks) {
            return;
        }
        if (str_contains($this->dialogCatatan, $teks)) {
            $this->dialogCatatan = trim(preg_replace('/\s*'.preg_quote($teks, '/').'\.?/', '', $this->dialogCatatan));
        } else {
            $this->dialogCatatan = trim($this->dialogCatatan.' '.$teks.'.');
            $this->revisiBagian = array_values(array_unique([...$this->revisiBagian, $bagian]));
        }
    }

    public function mintaRevisi(): void
    {
        abort_unless($this->bolehPeriksa, 403);
        $this->validate(['revisiBagian' => 'required|array|min:1', 'dialogCatatan' => 'required|string|max:300'], [], ['revisiBagian' => 'bagian', 'dialogCatatan' => 'catatan']);

        $u = $this->usulan;
        $token = Urungkan::simpan([$u->id]);
        $u->update(['status' => SU::Revisi, 'revisi_bagian' => $this->revisiBagian, 'revisi_catatan' => $this->dialogCatatan]);
        $u->catat('Dishub meminta revisi', $this->dialogCatatan);
        $this->kabari($u, 'Usulan perlu diperbaiki', [], $this->dialogCatatan, 'revisi', ['bagian' => $this->revisiBagian, 'oleh' => $this->user->name.' · Dishub']);

        $this->selesai('Permintaan revisi dikirim ke pengusul', $token);
    }

    public function bukaDuplikat(?int $banding = null): void
    {
        abort_unless($this->bolehPeriksa, 403);
        $b = $this->berdekatan->firstWhere('id', $banding) ?? $this->berdekatan->first();
        abort_unless($b, 404);
        $this->bandingId = $b->id;
        // bawaan: usulan yang lebih dulu diajukan dianggap asli
        $u = $this->usulan;
        $this->duplikatDari = $b->status !== SU::Diperiksa || $b->diajukan_at <= $u->diajukan_at ? $b->id : $u->id;
        $this->dialog = 'duplikat';
    }

    public function tandaiDuplikat(): void
    {
        abort_unless($this->bolehPeriksa, 403);
        $u = $this->usulan;
        $banding = $this->berdekatan->firstWhere('id', $this->bandingId);
        abort_unless($banding && in_array($this->duplikatDari, [$u->id, $banding->id]), 422);

        [$dupe, $asli] = $this->duplikatDari === $u->id ? [$banding, $u] : [$u, $banding];
        abort_unless($dupe->status === SU::Diperiksa, 422);

        $token = Urungkan::simpan([$dupe->id]);
        $dupe->items()->update(['status' => SI::DitolakDishub, 'catatan_dishub' => "Duplikat dari {$asli->nomor}"]);
        $dupe->update(['duplikat_dari_id' => $asli->id, 'diperiksa_at' => now()]);
        $dupe->hitungStatus();
        $dupe->catat("Ditandai duplikat dari {$asli->nomor}");
        $this->kabari($dupe, 'Usulan digabung (duplikat)', [], "{$dupe->nomor} sama dengan usulan {$asli->nomor} yang sudah diproses. Lacak usulan aslinya.", 'duplikat', ['asli' => $asli->nomor]);

        if ($dupe->is($u)) {
            $this->selesai("{$dupe->nomor} ditandai duplikat", $token);

            return;
        }
        // usulan lain yang digabung: pemeriksaan usulan ini berlanjut, isian yang belum disimpan tetap
        $this->reset('dialog', 'duplikatDari', 'bandingId');
        unset($this->berdekatan);
        $this->dispatch('toast', pesan: "{$dupe->nomor} ditandai duplikat dari usulan ini", urungkan: $token);
        $this->dispatch('usulan-diproses', lanjut: false);
    }

    // ── Bappeda
    public function simpanBappeda(): void
    {
        $this->resetErrorBag();
        $items = $this->itemBappeda;
        abort_if($items->isEmpty(), 403);
        foreach ($items as $i) {
            if (in_array($this->keputusan[$i->id] ?? '', ['tunda', 'tolak']) && blank($this->catatan[$i->id] ?? '')) {
                $this->addError("catatan.{$i->id}", 'Tulis alasan singkat.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $u = $this->usulan;
        $token = Urungkan::simpan([$u->id]);
        foreach ($items as $i) {
            $k = $this->keputusan[$i->id] ?? 'setujui';
            $i->update([
                'status' => ['setujui' => SI::Disetujui, 'tunda' => SI::Ditunda, 'tolak' => SI::DitolakBappeda][$k],
                'tahun_anggaran' => $k === 'setujui' ? $this->tahun : null,
                'tahun_target' => $k === 'tunda' ? $this->tahun + 1 : null,
                'harga_satuan' => $k === 'setujui' ? $i->jenis->harga_satuan : null,
                'catatan_bappeda' => $this->catatan[$i->id] ?: null,
                'diputuskan_at' => now(),
                'diputuskan_oleh' => $this->user->id,
            ]);
        }
        $u->hitungStatus();
        $setuju = collect($this->keputusan)->only($items->pluck('id'))->filter(fn ($k) => $k === 'setujui')->count();
        $u->catat("Diputuskan Bappeda · {$setuju} item disetujui (anggaran {$this->tahun})");
        $this->kabari($u, $setuju ? "{$setuju} item disetujui Bappeda" : 'Keputusan Bappeda', [Role::Dishub], $setuju ? $items->filter(fn ($i) => ($this->keputusan[$i->id] ?? '') === 'setujui')->map(fn ($i) => $i->jenis->namaPendek().' ×'.$i->jumlahEfektif())->implode(' dan ')." masuk anggaran {$this->tahun}. Tinggal menunggu pemasangan." : null, 'disetujui');

        $this->selesai("Keputusan disimpan · {$setuju} item disetujui", $token);
    }

    // ── Ubah keputusan (koreksi)
    public function mulaiUbah(): void
    {
        abort_unless($this->bolehUbahDishub || $this->bolehUbahBappeda, 403);
        $this->resetErrorBag();
        $this->siapkan();
        $this->mengubah = true;
        $this->dialogCatatan = '';
    }

    public function batalUbah(): void
    {
        $this->reset('mengubah', 'dialogCatatan');
        $this->resetErrorBag();
        $this->siapkan();
    }

    private function labelKeputusan(string $k, ?int $tahun = null): string
    {
        return ['lolos' => 'Lolos', 'tolak' => 'Tolak', 'setujui' => 'Setujui', 'tunda' => 'Tunda'][$k].($k === 'tunda' && $tahun ? " ({$tahun})" : '');
    }

    public function simpanUbahDishub(): void
    {
        abort_unless($this->mengubah && $this->bolehUbahDishub, 403);
        $valid = $this->periksaValid();
        $this->validate(['dialogCatatan' => 'required|string|max:150'], ['dialogCatatan.required' => 'Tulis alasan perubahan.'], ['dialogCatatan' => 'alasan']);
        if (! $valid) {
            return;
        }

        $u = $this->usulan;
        $skor = count($this->kriteriaDishub);
        $berubah = $u->items->filter(fn ($i) => $this->keputusan[$i->id] !== $this->keputusanAwal($i));
        $kriteriaBerubah = collect($this->kriteriaDishub)->sort()->values()->all() !== collect($u->kriteria_dishub ?? [])->sort()->values()->all();
        if ($berubah->isEmpty() && ! $kriteriaBerubah) {
            $this->addError('dialogCatatan', 'Belum ada keputusan yang diubah.');

            return;
        }

        $token = Urungkan::simpan([$u->id]);
        $ringkas = $berubah->map(fn ($i) => $i->jenis->namaPendek().': '.$this->labelKeputusan($this->keputusanAwal($i)).' → '.$this->labelKeputusan($this->keputusan[$i->id]));
        foreach ($u->items as $i) {
            $lolos = $this->keputusan[$i->id] === 'lolos';
            $i->update([
                'status' => $lolos ? SI::Lolos : SI::DitolakDishub,
                'catatan_dishub' => $lolos ? null : ($this->catatan[$i->id] ?: null),
                'diubah' => $i->diubah || $berubah->contains('id', $i->id),
            ]);
        }
        $u->update(['kriteria_dishub' => $this->kriteriaDishub, 'skor' => $skor]);
        $u->hitungStatus();
        $isi = $ringkas->push($kriteriaBerubah ? "skor {$skor}/5" : null)->filter()->implode(' · ').' · alasan: '.$this->dialogCatatan;
        $u->catat('Dishub mengubah hasil pemeriksaan', $isi);
        $this->kabari($u, 'Dishub mengubah hasil pemeriksaan', [Role::Bappeda], $isi, 'koreksi');

        $this->mengubah = false;
        $this->selesai('Perubahan disimpan', $token, lanjut: false);
    }

    public function aturKeputusanUbah(int $id, string $k): void
    {
        $i = $this->itemUbahBappeda->firstWhere('id', $id);
        abort_unless($this->mengubah && $i && in_array($k, ['setujui', 'tunda', 'tolak']), 403);
        $this->keputusan[$id] = $k;
        // tahun bawaan: anggaran semula bila disetujui, tahun berikutnya bila ditunda
        $this->tahunItem[$id] = match ($k) {
            'tunda' => $i->tahun_target ?? ($i->tahun_anggaran ?? $this->tahun) + 1,
            default => $i->tahun_anggaran ?? $this->tahun,
        };
    }

    public function simpanUbahBappeda(): void
    {
        abort_unless($this->mengubah && $this->bolehUbahBappeda, 403);
        $this->resetErrorBag();
        $this->validate(['dialogCatatan' => 'required|string|max:300'], ['dialogCatatan.required' => 'Tulis alasan perubahan.'], ['dialogCatatan' => 'alasan']);

        $u = $this->usulan;
        $awalTahun = fn ($i) => $i->tahun_target ?? $i->tahun_anggaran ?? $this->tahun;
        $berubah = $this->itemUbahBappeda->filter(fn ($i) => $this->keputusan[$i->id] !== $this->keputusanAwal($i)
            || ($this->keputusan[$i->id] !== 'tolak' && (int) $this->tahunItem[$i->id] !== (int) $awalTahun($i)));
        if ($berubah->isEmpty()) {
            $this->addError('dialogCatatan', 'Belum ada keputusan yang diubah.');

            return;
        }

        $token = Urungkan::simpan([$u->id]);
        $ringkas = [];
        foreach ($berubah as $i) {
            $k = $this->keputusan[$i->id];
            $tahun = (int) $this->tahunItem[$i->id];
            $ringkas[] = $i->jenis->namaPendek().': '.$this->labelKeputusan($this->keputusanAwal($i), $i->tahun_target).' → '.$this->labelKeputusan($k, $tahun).($k === 'setujui' ? " {$tahun}" : '');
            $i->update([
                'status' => ['setujui' => SI::Disetujui, 'tunda' => SI::Ditunda, 'tolak' => SI::DitolakBappeda][$k],
                'tahun_anggaran' => $k === 'setujui' ? $tahun : null,
                'tahun_target' => $k === 'tunda' ? $tahun : null,
                'harga_satuan' => $k === 'setujui' ? ($i->harga_satuan ?? $i->jenis->harga_satuan) : null,
                'catatan_bappeda' => $k === 'setujui' ? null : $this->dialogCatatan,
                'diubah' => true,
                'diputuskan_at' => now(),
                'diputuskan_oleh' => $this->user->id,
            ]);
        }
        $u->hitungStatus();
        $isi = implode(' · ', $ringkas).' · alasan: '.$this->dialogCatatan;
        $u->catat('Bappeda mengubah keputusan', $isi);
        $this->kabari($u, 'Bappeda mengubah keputusan', [Role::Dishub], $isi, 'koreksi');

        $this->mengubah = false;
        $this->selesai('Perubahan keputusan disimpan', $token, lanjut: false);
    }

    // ── Dishub: tandai terpasang
    public function bukaTerpasang(int $itemId): void
    {
        $item = $this->usulan->items->firstWhere('id', $itemId);
        abort_unless($this->user->hasRole(Role::Dishub) && $item?->status === SI::Disetujui, 403);
        $this->itemTerpasang = $itemId;
        $this->jumlahTerpasang = $item->jumlahEfektif();
        $this->tanggalTerpasang = now()->toDateString();
        $this->fotoHasil = null;
        $this->fotoHasilKoordinat = [];
        $this->dialog = 'terpasang';
    }

    public function simpanTerpasang(?float $lat = null, ?float $lng = null): void
    {
        $item = $this->usulan->items->firstWhere('id', $this->itemTerpasang);
        abort_unless($this->user->hasRole(Role::Dishub) && $item?->status === SI::Disetujui, 403);
        $this->validate([
            'fotoHasil' => 'required|array|min:1',
            'fotoHasil.*' => 'image|max:12288',
            'jumlahTerpasang' => 'required|integer|min:1',
            'tanggalTerpasang' => 'required|date|before_or_equal:today',
        ], [], ['fotoHasil' => 'foto hasil', 'jumlahTerpasang' => 'jumlah', 'tanggalTerpasang' => 'tanggal']);

        $item->tandaiTerpasang(
            collect($this->fotoHasil)->map(fn ($f, $n) => ['file' => $f] + ($this->fotoHasilKoordinat[$n] ?? []))->all(),
            $this->jumlahTerpasang, $lat, $lng, null, $this->tanggalTerpasang,
        );
        unset($this->usulan);

        $this->reset('dialog', 'itemTerpasang', 'fotoHasil');
        unset($this->usulan);
        $this->dispatch('toast', pesan: 'Tercatat terpasang · muncul di peta publik');
        $this->dispatch('usulan-diproses', lanjut: false);
    }

    // ── Pengusul
    public function batalkan(): void
    {
        $u = $this->usulan;
        abort_unless($this->user->isPengusul() && $u->status === SU::Diperiksa && ! $u->diperiksa_at, 403);
        $token = Urungkan::simpan([$u->id]);
        $u->update(['status' => SU::Dibatalkan]);
        $u->catat('Dibatalkan pengusul');
        $this->selesai('Usulan dibatalkan', $token, lanjut: false);
    }

    public function hapusDraft(): void
    {
        $u = $this->usulan;
        abort_unless($this->user->isPengusul() && $u->status === SU::Draft, 403);
        $u->delete();
        $this->redirectRoute('usulan', navigate: true);
    }

    // ── Bantuan
    private function selesai(string $pesan, string $token, bool $lanjut = true): void
    {
        $this->reset('dialog', 'dialogCatatan', 'revisiBagian', 'duplikatDari', 'bandingId', 'itemTolak', 'alasanTolak');
        unset($this->usulan);
        $this->siapkan();
        $this->dispatch('toast', pesan: $pesan, urungkan: $token);
        $this->dispatch('usulan-diproses', lanjut: $lanjut);
    }

    /** @param  Role[]  $roles */
    private function kabari(Usulan $u, string $judul, array $roles = [], ?string $isi = null, string $jenis = 'info', array $ekstra = [], bool $pengusul = true): void
    {
        \App\Support\Kabar::kirim($u, $judul, $isi, $jenis, $ekstra, $roles, $pengusul);
    }
};
?>

@php
    $u = $hilang ? null : $this->usulan;
    $user = $this->user;
    $dishub = $user->hasRole(\App\Enums\Role::Dishub);
    $lihatBiaya = ! $user->isPengusul();
    $berdekatan = $u ? $this->berdekatan : collect();
    $hari = $u?->diajukan_at ? (int) $u->diajukan_at->diffInDays() : 0;
    $telat = $u?->status === \App\Enums\StatusUsulan::Diperiksa && $hari > config('usuljalan.batas_hari_periksa');
    $foto = $u ? $u->foto->values() : collect();
@endphp

@if (! $u)

<div class="grid min-h-[420px] place-items-center p-6">
    <x-kosong ikon="cloud-off" nada="bahaya" judul="Detail usulan gagal dimuat" teks="Usulan ini tidak bisa dibuka — mungkin sudah dihapus atau dipindahkan. Daftar di kiri tetap bisa dipakai." class="w-full max-w-[560px] rounded-md bg-bg shadow-sm outline outline-1 -outline-offset-1 outline-line">
        <button wire:click="muat" class="flex items-center gap-2 rounded-sm bg-accent px-4 py-2.5 text-sm font-semibold text-fg-inverse shadow-sm hover:brightness-110">Coba lagi @svg('lucide-rotate-cw', 'size-4')</button>
    </x-kosong>
</div>
@elseif ($user->isPengusul())
    @php
        $S = \App\Enums\StatusUsulan::class;
        $st = $u->status;
        $judulStatus = [
            'draft' => 'Belum dikirim', 'diperiksa' => 'Sedang di Dishub', 'revisi' => 'Perlu diperbaiki',
            'menunggu_bappeda' => 'Menunggu Bappeda', 'disetujui' => 'Disetujui', 'terpasang' => 'Sudah terpasang',
            'ditunda' => 'Ditunda', 'ditolak' => 'Tidak disetujui', 'dibatalkan' => 'Dibatalkan',
        ][$st->value];
        $tahap = $st->tahap();
        $diputuskan = $u->items->whereNotNull('diputuskan_at')->max('diputuskan_at');
        $terpasangAt = $u->items->whereNotNull('terpasang_at')->max('terpasang_at');
        $hariDishub = $u->diajukan_at ? (int) $u->diajukan_at->diffInDays() + 1 : 0;
        $langkah = [
            ['Diajukan', $u->diajukan_at ? $u->diajukan_at->translatedFormat('j M Y').' · oleh kamu' : 'Belum dikirim', null],
            ['Diperiksa Dishub', $u->diperiksa_at ? 'Selesai '.$u->diperiksa_at->translatedFormat('j M') : ($u->diajukan_at ? 'Mulai '.$u->diajukan_at->translatedFormat('j M').' · biasanya ± 7 hari' : 'Setelah dikirim'), $tahap === 2 && $st !== $S::Ditolak ? ($st === $S::Revisi ? 'Perlu revisi' : "Hari ke-{$hariDishub}") : null],
            ['Keputusan Bappeda', $diputuskan ? 'Diputuskan '.\Illuminate\Support\Carbon::parse($diputuskan)->translatedFormat('j M') : 'Setelah lolos pemeriksaan', $tahap === 3 ? 'Sekarang' : null],
            ['Terpasang', $terpasangAt ? 'Dipasang '.\Illuminate\Support\Carbon::parse($terpasangAt)->translatedFormat('j M') : 'Petugas memasang di lokasi', $tahap === 4 ? 'Dijadwalkan' : null],
        ];
        $unit = $u->items->sum(fn ($i) => $i->jumlahEfektif());
        $bisaBatal = $st === $S::Diperiksa && ! $u->diperiksa_at;
    @endphp

    <div class="flex min-h-full flex-col bg-bg pb-44 lg:pb-0" wire:key="detail-p-{{ $u->id }}">
        <div class="sticky top-0 z-[700] flex items-center gap-3 border-b border-line bg-bg px-4 pb-3 pt-1 lg:static lg:px-6 lg:pt-4">
            <button wire:click="$dispatch('tutup-detail')" class="grid size-11 shrink-0 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line lg:hidden" aria-label="Kembali">@svg('lucide-arrow-left', 'size-5')</button>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="font-caption text-xs font-semibold uppercase tracking-[1px] text-fg-muted">Detail usulan</span>
                <span class="judul whitespace-nowrap text-[22px] leading-[26px] sm:text-2xl">{{ $u->nomor ?? 'Draft' }}</span>
            </div>
            <x-status :status="$st" />
        </div>

        <section @class(['flex flex-col gap-3 px-4 pb-3 pt-4 lg:px-6', 'bg-accent-soft' => ! in_array($st, [$S::Revisi, $S::Ditolak, $S::Terpasang]), 'bg-warning-bg' => $st === $S::Revisi, 'bg-danger-bg' => $st === $S::Ditolak, 'bg-success-bg' => $st === $S::Terpasang])>
            <div class="flex flex-col gap-1">
                <h2 class="judul text-[30px] leading-8">{{ $judulStatus }}</h2>
                <p class="text-base leading-[22px] text-fg-muted">{{ $st === $S::Revisi ? '“'.$u->revisi_catatan.'”' : $st->penjelasan() }}</p>
            </div>
            <ol class="flex flex-col">
                @foreach ($langkah as $n => [$nama, $ket, $tag])
                    @php $no = $n + 1; $selesai = $no < $tahap || $st === $S::Terpasang; $kini = $no === $tahap && $st !== $S::Terpasang; @endphp
                    <li class="flex gap-3">
                        <div class="flex w-7 flex-col items-center">
                            <span @class([
                                'grid size-7 shrink-0 place-items-center rounded-full font-caption text-[13px] font-semibold',
                                'bg-accent text-fg-inverse' => $selesai && $st !== $S::Terpasang,
                                'bg-success text-fg-inverse' => $st === $S::Terpasang,
                                'bg-bg shadow-sm outline outline-2 -outline-offset-1 outline-accent' => $kini && $st !== $S::Ditolak,
                                'bg-danger text-fg-inverse' => $kini && $st === $S::Ditolak,
                                'bg-bg text-fg-muted outline outline-2 -outline-offset-1 outline-line' => ! $selesai && ! $kini,
                            ])>
                                @if ($selesai) @svg('lucide-check', 'size-4')
                                @elseif ($kini && $st === $S::Ditolak) @svg('lucide-x', 'size-4')
                                @elseif ($kini) <span class="size-2.5 rounded-full bg-accent"></span>
                                @else {{ $no }} @endif
                            </span>
                            @unless ($loop->last) <span @class(['min-h-4 w-0.5 flex-1', 'bg-accent' => $selesai && $st !== $S::Terpasang, 'bg-success' => $st === $S::Terpasang, 'bg-accent/25' => ! $selesai])></span> @endunless
                        </div>
                        <div @class(['flex min-w-0 flex-1 flex-col gap-0.5 pt-[3px]', 'pb-3' => ! $loop->last])>
                            <div class="flex items-center justify-between gap-2">
                                <span @class(['text-base', 'font-semibold' => $selesai || $kini, 'font-medium text-fg-muted' => ! $selesai && ! $kini])>{{ $nama }}</span>
                                @if ($kini && $tag) <span class="rounded-sm bg-accent px-2 py-0.5 font-caption text-xs font-semibold text-fg-inverse">{{ $tag }}</span> @endif
                            </div>
                            <span class="text-sm text-fg-muted">{{ $ket }}</span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="flex flex-col gap-3 px-4 pb-2 pt-[18px] lg:px-6">
            <div class="flex items-end justify-between"><h3 class="judul text-xl tracking-[0.5px]">Foto & lokasi</h3><span class="font-caption text-[13px] text-fg-muted">{{ $u->foto->count() }} foto</span></div>
            <div class="flex h-[84px] gap-2">
                @foreach ($u->foto->take(2)->values() as $n => $f)
                    <button type="button" x-data @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($u->foto)), mulai: {{ $n }} })" class="relative flex-1 overflow-hidden rounded-md" aria-label="Lihat foto {{ $n + 1 }}">
                        <img src="{{ $f->url() }}" class="size-full object-cover" alt="">
                        @if ($n === 1 && $u->foto->count() > 2) <span class="absolute inset-0 grid place-items-center bg-fg/55 font-caption text-sm font-semibold text-fg-inverse">+{{ $u->foto->count() - 2 }}</span> @endif
                    </button>
                @endforeach
                @if ($u->lat)
                    <a href="https://www.google.com/maps?q={{ $u->lat }},{{ $u->lng }}" target="_blank" class="relative flex-1 overflow-hidden rounded-md outline outline-1 -outline-offset-1 outline-line" wire:ignore>
                        <div class="absolute inset-0" x-data="petaMini({{ $u->lat }}, {{ $u->lng }}, @js(svg('lucide-map-pin', 'size-4')->toHtml()))"></div>
                    </a>
                @endif
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="text-base font-semibold">{{ $u->alamat ?: 'Tanpa nama jalan' }}</span>
                <span class="text-sm text-fg-muted">{{ collect([$u->desa ? 'Ds. '.$u->desa->nama : null, $u->kecamatan ? 'Kec. '.$u->kecamatan->nama : null])->filter()->implode(' · ') }}</span>
            </div>
        </section>

        <section class="flex flex-col gap-1 px-4 pb-2 pt-5 lg:px-6">
            <div class="flex items-end justify-between"><h3 class="judul text-xl tracking-[0.5px]">Kebutuhan</h3><span class="font-caption text-[13px] text-fg-muted">{{ $u->items->count() }} jenis · {{ $unit }} unit</span></div>
            @foreach ($u->items as $i)
                <div class="flex items-center gap-3 py-2.5">
                    <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-surface-muted text-accent">@svg('lucide-'.$i->jenis->ikon, 'size-[22px]')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="text-base font-semibold">{{ $i->jenis->namaPendek() }}</span>
                        <span class="text-sm text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }}{{ $i->catatan_bappeda || $i->catatan_dishub ? ' · '.($i->catatan_bappeda ?? $i->catatan_dishub) : '' }}</span>
                    </div>
                    <x-badge :tone="$i->status->tone()">{{ $i->status === \App\Enums\StatusItem::Menunggu ? 'Belum diperiksa' : $i->status->label() }}</x-badge>
                </div>
            @endforeach
        </section>

        @if ($u->kriteria_klaim || $u->keterangan)
            <section class="flex flex-col gap-2.5 px-4 pb-2 pt-4 lg:px-6">
                <h3 class="judul text-xl tracking-[0.5px]">Alasan</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($u->kriteria_klaim ?? [] as $k) <span class="rounded-full bg-surface-soft px-2.5 py-1.5 font-caption text-[13px] font-medium">{{ \App\Enums\Kriteria::from($k)->labelAwam() }}</span> @endforeach
                </div>
                @if ($u->keterangan) <p class="text-sm text-fg-muted">“{{ $u->keterangan }}”</p> @endif
            </section>
        @endif

        @if ($u->proposal_path)
            <section class="flex flex-col gap-2.5 px-4 pb-2 pt-5 lg:px-6">
                <h3 class="judul text-xl tracking-[0.5px]">Proposal</h3>
                <a href="{{ route('usulan.proposal', $u) }}" target="_blank" class="flex items-center gap-3 rounded-md bg-bg p-3 shadow-sm outline outline-1 -outline-offset-1 outline-line">
                    <span class="grid h-12 w-10 place-items-center rounded-sm bg-danger-bg font-caption text-xs font-bold text-danger">PDF</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5"><span class="text-base font-semibold">Proposal {{ $u->nomor }}</span><span class="text-sm text-fg-muted">PDF · {{ $u->proposal_otomatis ? 'dibuat otomatis' : 'diunggah' }}</span></span>
                    <span class="grid size-11 place-items-center rounded-sm outline outline-1 -outline-offset-1 outline-line">@svg('lucide-download', 'size-5')</span>
                </a>
            </section>
        @endif

        <details class="group mx-4 mb-6 mt-3 lg:mx-6">
            <summary class="flex h-12 cursor-pointer list-none items-center gap-2 rounded-sm bg-surface-muted px-3">
                @svg('lucide-history', 'size-[18px]') <span class="flex-1 text-base font-medium">Lihat riwayat ({{ $u->riwayat->count() }})</span>
                <span class="transition group-open:rotate-180">@svg('lucide-chevron-down', 'size-5')</span>
            </summary>
            <ol class="mt-3 flex flex-col gap-3 px-1">
                @foreach ($u->riwayat as $r)
                    <li class="flex gap-3"><span class="mt-1.5 size-2 shrink-0 rounded-full {{ $loop->first ? 'bg-accent' : 'bg-[#C4C9D8]' }}"></span>
                        <div class="text-sm"><div>{{ $r->aksi }}</div><div class="font-caption text-xs text-fg-muted">{{ $r->created_at->translatedFormat('j M Y, H.i') }}</div></div></li>
                @endforeach
            </ol>
        </details>

        <div class="fixed inset-x-0 bottom-0 z-[1001] flex flex-col gap-1 border-t border-line bg-bg px-4 pb-6 pt-3 shadow-[0_-4px_12px_#0000000a] lg:sticky lg:mt-auto lg:px-6 lg:pb-4">
            @if ($st === $S::Draft)
                <a href="{{ route('usulan.ubah', $u) }}" wire:navigate class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Lanjutkan mengisi @svg('lucide-arrow-right', 'size-[18px]')</a>
                <button wire:click="hapusDraft" wire:confirm="Hapus draft ini?" class="h-11 text-base font-semibold text-danger">Hapus draft</button>
            @elseif ($st === $S::Revisi)
                <a href="{{ route('usulan.ubah', $u) }}" wire:navigate class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Perbaiki @svg('lucide-arrow-right', 'size-[18px]')</a>
            @else
                <button x-data x-on:click="navigator.share ? navigator.share({ title: 'Usulan {{ $u->nomor }}', url: '{{ route('lacak', $u->nomor) }}' }) : (salin('{{ route('lacak', $u->nomor) }}'), $dispatch('toast', { pesan: 'Link lacak disalin' }))"
                        class="flex h-[52px] items-center justify-center gap-2 rounded-sm bg-accent text-base font-semibold text-fg-inverse shadow-sm">Bagikan link lacak @svg('lucide-share-2', 'size-[18px]')</button>
                @if ($bisaBatal)
                    <button wire:click="batalkan" class="h-11 text-base font-semibold text-danger">Batalkan usulan</button>
                    <span class="text-center font-caption text-xs text-fg-muted">Bisa dibatalkan selama belum ada item yang diperiksa</span>
                @endif
            @endif
        </div>
    </div>
@elseif ($user->hasRole(\App\Enums\Role::Bappeda))

<div>
    @php
        $SI = \App\Enums\StatusItem::class;
        $bisaPilih = fn ($i) => in_array($i->status, [$SI::Lolos, $SI::Ditunda]);
        $menunggu = $u->items->contains(fn ($i) => $bisaPilih($i));
        $periksa = $u->riwayat->first(fn ($r) => str_starts_with($r->aksi, 'Diperiksa Dishub'));
        $catatanDishub = $u->items->pluck('catatan_dishub')->filter()->unique()->implode(' ');
        $foto = $u->foto->values();
    @endphp
    <div class="flex flex-col gap-[18px] px-6 py-5" wire:key="detail-b-{{ $u->id }}">
        <div class="flex items-center gap-2.5">
            <span class="font-caption text-xs font-semibold tracking-[0.4px] text-accent">{{ $u->nomor }}</span>
            @php $jmlTunda = $u->items->where('status', $SI::Ditunda)->count(); @endphp
            @if ($u->items->contains('status', $SI::Lolos)) <x-badge tone="perhatian">Menunggu Keputusan</x-badge>
            @elseif ($jmlTunda) <x-badge tone="perhatian">{{ $jmlTunda }} item ditunda</x-badge>
            @else <x-status :status="$u->status" /> @endif
            <span class="flex-1"></span>
            <a href="{{ route('lacak', $u->nomor) }}" target="_blank" class="grid size-[30px] place-items-center rounded-sm text-fg-muted hover:bg-surface-muted" title="Buka halaman lacak">@svg('lucide-external-link', 'size-4')</a>
            <button wire:click="$dispatch('tutup-detail')" class="grid size-[30px] place-items-center rounded-sm text-fg-muted hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-4')</button>
        </div>
        <div class="flex flex-col gap-1">
            <h2 class="judul text-[28px] leading-[29px]">{{ $u->alamat ?: $u->lokasiSingkat() }}</h2>
            <span class="text-sm text-fg-muted">{{ $u->lokasiSingkat() }}</span>
        </div>
        @if ($foto->isNotEmpty())
            <div x-data="{ aktif: 0, n: {{ $foto->count() }} }" class="group relative h-[150px] overflow-hidden rounded-md">
                <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($foto)), mulai: aktif })" class="block size-full" aria-label="Lihat foto">
                    @foreach ($foto as $k => $f)
                        <img src="{{ $f->url() }}" x-show="aktif === {{ $k }}" @if ($k) x-cloak @endif class="size-full object-cover" alt="">
                    @endforeach
                </button>
                @if ($foto->count() > 1)
                    <button type="button" @click="aktif = (aktif - 1 + n) % n" class="absolute left-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-full bg-bg/90 shadow-sm lg:opacity-0 lg:transition lg:group-hover:opacity-100" aria-label="Foto sebelumnya">@svg('lucide-chevron-left', 'size-4')</button>
                    <button type="button" @click="aktif = (aktif + 1) % n" class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-full bg-bg/90 shadow-sm lg:opacity-0 lg:transition lg:group-hover:opacity-100" aria-label="Foto berikutnya">@svg('lucide-chevron-right', 'size-4')</button>
                @endif
                <span class="pointer-events-none absolute bottom-2.5 right-2.5 flex items-center gap-[5px] rounded-sm bg-fg/70 px-2 py-1 font-caption text-[11px] font-medium text-fg-inverse">@svg('lucide-image', 'size-3') <span x-text="aktif + 1"></span> / {{ $foto->count() }} foto</span>
            </div>
        @endif
        <div class="flex flex-col gap-1.5 font-caption text-[13px]">
            @if ($u->lat)
                <span class="flex items-center gap-2">@svg('lucide-map-pin', 'size-[15px] text-fg-muted') {{ number_format($u->lat, 4, '.', '') }}, {{ number_format($u->lng, 4, '.', '') }} <span class="text-fg-muted">·</span> <a href="https://www.google.com/maps?q={{ $u->lat }},{{ $u->lng }}" target="_blank" class="font-semibold text-accent">Lihat di peta</a></span>
            @endif
            <span class="flex items-center gap-2 text-fg-muted">@svg('lucide-users', 'size-[15px]') Diajukan {{ $u->instansi_id ? $u->namaPengusul() : 'warga' }} {{ $u->diajukan_at?->translatedFormat('j M Y') }}</span>
        </div>

        <section class="flex flex-col gap-2.5 border-t border-line pt-4">
            <div class="flex items-center justify-between">
                <span class="font-caption text-[11px] font-bold uppercase tracking-[1px] text-fg-muted">Hasil pemeriksaan Dishub</span>
                <span class="flex items-center gap-1.5"><span class="font-caption text-xs text-fg-muted">Skor</span><span class="judul text-lg text-accent">{{ $u->skor ?? 0 }}/5</span></span>
            </div>
            <div class="flex flex-col gap-[7px]">
                @foreach (\App\Enums\Kriteria::cases() as $k)
                    @php $ok = in_array($k->value, $u->kriteria_dishub ?? []); @endphp
                    <span class="flex items-center gap-2 font-caption text-[13px]">
                        <span @class(['text-success' => $ok, 'text-line' => ! $ok])>@svg('lucide-'.($ok ? 'circle-check' : 'circle'), 'size-[15px]')</span>
                        <span @class(['flex-1', 'text-fg-muted' => ! $ok])>{{ $k->label() }}</span>
                        <span @class(['font-semibold', 'text-fg-muted' => ! $ok])>{{ $ok ? 'Terpenuhi' : '—' }}</span>
                    </span>
                @endforeach
            </div>
            @if ($catatanDishub || $periksa)
                <div class="flex flex-col gap-1 rounded-sm border-l-[3px] border-accent bg-surface-muted px-3 py-2.5">
                    @if ($catatanDishub) <p class="text-[13px] leading-[19px]">“{{ $catatanDishub }}”</p> @endif
                    @if ($periksa) <span class="font-caption text-[11px] text-fg-muted">{{ $periksa->user?->name ?? 'Petugas' }} · Dishub · {{ $periksa->created_at->translatedFormat('j M Y') }}</span> @endif
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-2.5 border-t border-line pt-4">
            <div class="flex items-center justify-between">
                <span class="font-caption text-[11px] font-bold uppercase tracking-[1px] text-fg-muted">Keputusan per item</span>
                @if ($this->bolehUbahBappeda)
                    <button wire:click="{{ $mengubah ? 'batalUbah' : 'mulaiUbah' }}" @class(['flex items-center gap-[5px] rounded-sm px-2 py-1 font-caption text-[13px] font-semibold text-accent', 'bg-accent-soft' => $mengubah, 'hover:bg-accent-soft' => ! $mengubah])>
                        @svg('lucide-pencil', 'size-[13px]') Ubah Keputusan @if ($mengubah) @svg('lucide-chevron-up', 'size-3.5') @endif
                    </button>
                @endif
            </div>

            @if ($mengubah && $this->bolehUbahBappeda)
                @php
                    $awalKep = $this->itemUbahBappeda->sortBy('diputuskan_at')->first();
                    $daftarUbah = $u->items->filter(fn ($i) => in_array($i->status, [$SI::Disetujui, $SI::Ditunda, $SI::DitolakBappeda, $SI::Terpasang]));
                    $awalK = fn ($i) => match ($i->status) { $SI::Ditunda => 'tunda', $SI::DitolakBappeda => 'tolak', default => 'setujui' };
                    $labelK = fn ($k, $t = null) => ['setujui' => 'Setujui', 'tunda' => 'Tunda', 'tolak' => 'Tolak'][$k].($k === 'tunda' && $t ? " ({$t})" : '');
                @endphp
                <p class="font-caption text-[13px] text-fg-muted">Keputusan awal {{ $awalKep?->diputuskan_at?->translatedFormat('j M Y') }}{{ $awalKep?->pemutus ? ' oleh '.$awalKep->pemutus->name : '' }}. Pilih ulang per item.</p>
                <div class="flex flex-col overflow-hidden rounded-md outline outline-1 -outline-offset-1 outline-line">
                    @foreach ($daftarUbah as $i)
                        @php
                            $terkunci = $i->status === $SI::Terpasang;
                            $k = $keputusan[$i->id] ?? $awalK($i);
                            $t = (int) ($tahunItem[$i->id] ?? $tahun);
                            $awalTahun = (int) ($i->tahun_target ?? $i->tahun_anggaran ?? $tahun);
                            $berubah = ! $terkunci && ($k !== $awalK($i) || ($k !== 'tolak' && $t !== $awalTahun));
                            $biaya = $i->estimasi() ?: $i->jumlahEfektif() * $i->jenis->harga_satuan;
                            $efek = match (true) {
                                ! $berubah => null,
                                $awalK($i) === 'setujui' && $k !== 'setujui' => 'pagu '.$i->tahun_anggaran.' kembali '.\App\Support\Rekap::rupiahPendek($biaya),
                                $awalK($i) !== 'setujui' && $k === 'setujui' => 'memakai pagu '.$t.' '.\App\Support\Rekap::rupiahPendek($biaya),
                                default => null,
                            };
                        @endphp
                        <div wire:key="ub-{{ $i->id }}" @class(['flex flex-col gap-2.5 px-3.5 py-3', 'border-t border-line' => ! $loop->first, 'bg-warning-bg/60' => $berubah, 'bg-surface-muted' => $terkunci])>
                            <div class="flex items-center gap-2">
                                @if ($terkunci) <span class="text-fg-muted">@svg('lucide-lock', 'size-4')</span> @endif
                                <span @class(['flex-1 text-sm font-semibold', 'text-fg-muted' => $terkunci])>{{ $i->jenis->namaPendek() }}</span>
                                <span class="font-caption text-xs text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }} · <x-rupiah :nilai="$biaya" /></span>
                            </div>
                            @if ($terkunci)
                                <div class="flex items-center gap-3">
                                    <x-badge tone="sukses">Terpasang {{ $i->terpasang_at?->translatedFormat('j M Y') }}</x-badge>
                                    <span class="font-caption text-xs text-fg-muted">Sudah terpasang, keputusan tidak bisa diubah</span>
                                </div>
                            @else
                                <div class="grid grid-cols-3 gap-1.5 font-caption text-[13px] font-semibold">
                                    <button wire:click="aturKeputusanUbah({{ $i->id }}, 'setujui')" @class(['flex h-9 items-center justify-center gap-1.5 rounded-sm outline -outline-offset-1', 'bg-success-bg text-success outline-[1.5px] outline-success' => $k === 'setujui', 'bg-bg text-fg-muted outline-1 outline-line hover:text-fg' => $k !== 'setujui'])>@svg('lucide-check', 'size-3.5') Setujui</button>
                                    @if ($k === 'tunda')
                                        <label class="flex h-9 cursor-pointer items-center justify-center gap-1 rounded-sm bg-warning-bg text-warning outline outline-[1.5px] -outline-offset-1 outline-warning">
                                            @svg('lucide-hourglass', 'size-3.5') Tunda ·
                                            <select wire:model.live="tahunItem.{{ $i->id }}" class="cursor-pointer appearance-none bg-transparent font-semibold outline-none">
                                                @foreach (range(now()->year, now()->year + 4) as $th) <option value="{{ $th }}">{{ $th }}</option> @endforeach
                                            </select>
                                            @svg('lucide-chevron-down', 'size-3.5')
                                        </label>
                                    @else
                                        <button wire:click="aturKeputusanUbah({{ $i->id }}, 'tunda')" class="flex h-9 items-center justify-center gap-1.5 rounded-sm bg-bg text-fg-muted outline outline-1 -outline-offset-1 outline-line hover:text-fg">@svg('lucide-hourglass', 'size-3.5') Tunda</button>
                                    @endif
                                    <button wire:click="aturKeputusanUbah({{ $i->id }}, 'tolak')" @class(['flex h-9 items-center justify-center gap-1.5 rounded-sm outline -outline-offset-1', 'bg-danger-bg text-danger outline-[1.5px] outline-danger' => $k === 'tolak', 'bg-bg text-fg-muted outline-1 outline-line hover:text-fg' => $k !== 'tolak'])>@svg('lucide-x', 'size-3.5') Tolak</button>
                                </div>
                                @if ($berubah)
                                    <div class="flex flex-wrap items-center gap-1.5 font-caption text-xs">
                                        <span class="rounded-sm bg-accent px-1.5 py-px text-[10px] font-bold uppercase tracking-[0.5px] text-fg-inverse">Diubah</span>
                                        <span>{{ $labelK($awalK($i), $i->tahun_target) }}</span>
                                        @svg('lucide-arrow-right', 'size-3 text-fg-muted')
                                        <span @class(['font-semibold', 'text-success' => $k === 'setujui', 'text-warning' => $k === 'tunda', 'text-danger' => $k === 'tolak'])>{{ $labelK($k, $t) }}</span>
                                        @if ($efek) <span class="text-fg-muted">· {{ $efek }}</span> @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-col gap-2" x-data="{ n: {{ mb_strlen($dialogCatatan) }} }">
                    <div class="flex items-center justify-between"><span class="text-[13px] font-semibold">Alasan perubahan <span class="text-danger">*</span></span><span class="font-caption text-xs text-fg-muted">wajib diisi</span></div>
                    <div class="flex flex-col gap-1 rounded-sm px-3 pb-2 pt-2.5 outline outline-[1.5px] -outline-offset-1 outline-accent">
                        <textarea wire:model="dialogCatatan" x-on:input="n = $el.value.length" maxlength="300" rows="3" class="w-full resize-none bg-transparent text-[13px] leading-5 outline-none" placeholder="Mis. Pagu {{ $tahun }} dialihkan ke simpang rawan kecelakaan."></textarea>
                        <span class="text-right font-caption text-[11px] text-fg-muted" x-text="n + ' / 300'"></span>
                    </div>
                    @error('dialogCatatan') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="flex items-center gap-2.5 rounded-sm bg-accent-soft px-3 py-2.5 font-caption text-xs text-accent-deep">@svg('lucide-history', 'size-4 shrink-0') Tercatat di riwayat · label Diubah · Dishub & pengusul dikabari</div>
                <div class="flex items-center justify-end gap-2">
                    <button wire:click="batalUbah" class="px-4 py-3 text-[15px] font-semibold text-fg-muted hover:text-fg">Batal</button>
                    <button wire:click="simpanUbahBappeda" wire:loading.attr="disabled" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60">Simpan Perubahan @svg('lucide-check', 'size-[18px]')</button>
                </div>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($u->items as $i)
                        @php $dipilih = in_array($i->id, $pilihan); @endphp
                        <div class="flex items-center gap-2">
                            <span class="text-[13px] font-medium">{{ $i->jenis->namaPendek() }}</span>
                            <span class="flex-1 truncate font-caption text-xs text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }} · <x-rupiah :nilai="$i->estimasi() ?: $i->jumlahEfektif() * $i->jenis->harga_satuan" /></span>
                            @if ($bisaPilih($i))
                                <button wire:click="$dispatch('toggle-item', { id: {{ $i->id }} })" @class(['flex items-center gap-1.5 rounded-sm px-2 py-[3px] font-caption text-[11px] font-semibold', 'bg-accent-soft text-accent' => $dipilih, 'bg-warning-bg text-warning' => ! $dipilih && $i->status === $SI::Ditunda, 'bg-surface-muted text-fg-muted hover:text-fg' => ! $dipilih && $i->status !== $SI::Ditunda])>
                                    <span class="size-1.5 rounded-full bg-current"></span>{{ $dipilih ? 'Dipilih' : ($i->status === $SI::Ditunda ? 'Ditunda · '.$i->tahun_target : 'Belum dipilih') }}
                                </button>
                            @else
                                <x-badge :tone="$i->status->tone()">{{ $i->status->label() }}{{ $i->status === $SI::Disetujui && $i->tahun_anggaran ? ' · '.$i->tahun_anggaran : '' }}</x-badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-2.5 border-t border-line pt-4">
            <span class="font-caption text-[11px] font-bold uppercase tracking-[1px] text-fg-muted">Riwayat</span>
            <ol class="flex flex-col">
                @foreach ($u->riwayat as $r)
                    @php
                        $aksi = mb_strtolower($r->aksi);
                        $warna = match (true) {
                            str_contains($aksi, 'ditolak') || str_contains($aksi, 'duplikat') || str_contains($aksi, 'dibatalkan') => 'bg-danger',
                            str_contains($aksi, 'ditunda') || str_contains($aksi, 'revisi') || str_contains($aksi, 'mengubah') => 'bg-warning',
                            str_contains($aksi, 'diputuskan') || str_contains($aksi, 'disetujui') || str_contains($aksi, 'diperiksa') || str_contains($aksi, 'terpasang') => 'bg-success',
                            default => 'bg-fg-muted',
                        };
                    @endphp
                    <li class="flex gap-3">
                        <div class="flex w-2.5 flex-col items-center pt-1"><span class="size-2 shrink-0 rounded-full {{ $warna }}"></span>@unless ($loop->last)<span class="w-px flex-1 bg-line"></span>@endunless</div>
                        <div @class(['flex flex-col gap-0.5', 'pb-2.5' => ! $loop->last])>
                            <span class="text-[13px]">{{ $r->aksi }}{{ $r->user && ! $loop->last ? ' · '.$r->user->name : '' }}</span>
                            <span class="font-caption text-[11px] text-fg-muted">{{ $r->created_at->translatedFormat('j M Y') }}</span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</div>
@else

<div class="flex flex-col gap-4 p-4 sm:p-6 lg:px-8" wire:key="detail-{{ $u->id }}">
    {{-- ── Kepala ── --}}
    <div class="flex items-end justify-between gap-4">
        <div class="flex min-w-0 flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2.5 font-caption text-xs">
                <span class="font-semibold tracking-[0.3px] text-accent">{{ $u->nomor ?? 'Draft' }}</span>
                @if ($dishub && $u->status === \App\Enums\StatusUsulan::Diperiksa)
                    <x-badge tone="perhatian">Menunggu Pemeriksaan</x-badge>
                @else
                    <x-status :status="$u->status" />
                @endif
                @if ($u->diajukan_at)
                    <span @class(['font-medium', 'text-warning' => $telat, 'text-fg-muted' => ! $telat])>{{ $hari ? "masuk {$hari} hari lalu" : 'masuk hari ini' }} · {{ $u->diajukan_at->translatedFormat('j M Y') }}</span>
                @endif
                @if ($u->revisi_ke) <x-badge tone="perhatian" :dot="false">Revisi ke-{{ $u->revisi_ke + 1 }}</x-badge> @endif
                @if ($u->items->contains('diubah', true)) <x-badge :dot="false">Diubah</x-badge> @endif
            </div>
            <h2 class="judul text-[28px] leading-none sm:text-[34px] sm:leading-[34px]">{{ $u->alamat ?: $u->lokasiSingkat() }}</h2>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-fg-muted">
                <span class="flex items-center gap-1.5">@svg('lucide-map-pin', 'size-3.5') {{ $u->lokasiSingkat() }}</span>
                <span class="flex items-center gap-1.5">@svg('lucide-user-round', 'size-3.5') {{ $u->namaPengusul() }}{{ $u->instansi_id && $u->penanggung_jawab ? ' · '.$u->penanggung_jawab : '' }}</span>
                @if ($dishub || $user->hasRole(\App\Enums\Role::Admin))
                    <span class="flex items-center gap-1.5">@svg('lucide-phone', 'size-3.5') {{ $u->user->no_hp }}</span>
                @endif
                @if ($u->skor !== null && ! $this->bolehPeriksa) <span class="font-semibold text-fg">Skor {{ $u->skor }}/5</span> @endif
            </div>
        </div>
        @if ($total > 1)
            <div class="hidden shrink-0 items-center gap-2 sm:flex">
                <span class="font-caption text-[13px] text-fg-muted">{{ $posisi }} dari {{ $total }}</span>
                <button wire:click="$dispatch('pindah-usulan', { arah: -1 })" @disabled($posisi <= 1) class="grid size-8 place-items-center rounded-sm bg-bg outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted disabled:opacity-40">@svg('lucide-chevron-up', 'size-4')</button>
                <button wire:click="$dispatch('pindah-usulan', { arah: 1 })" @disabled($posisi >= $total) class="grid size-8 place-items-center rounded-sm bg-bg outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted disabled:opacity-40">@svg('lucide-chevron-down', 'size-4')</button>
            </div>
        @endif
    </div>

    @unless ($dishub)
        <div class="rounded-md bg-bg p-4 outline outline-1 -outline-offset-1 outline-line">
            <x-stepper :usulan="$u" />
            <p class="mt-3 text-sm">{{ $u->status->penjelasan() }}</p>
        </div>
    @endunless

    @if ($dialog === 'duplikat' && $this->bolehPeriksa && ($banding = $berdekatan->firstWhere('id', $bandingId)))
        @php
            $jarak = round(\App\Models\Usulan::jarak($u->lat, $u->lng, $banding->lat, $banding->lng));
            $sama = $u->items->pluck('jenis_perlengkapan_id')->intersect($banding->items->pluck('jenis_perlengkapan_id'));
            $lebihAwal = $u->diajukan_at <= $banding->diajukan_at ? $u : $banding;
            $hariSelisih = (int) abs($u->diajukan_at->diffInDays($banding->diajukan_at));
            $teksSelisih = $hariSelisih >= 14 ? intdiv($hariSelisih, 7).' minggu' : ($hariSelisih ? $hariSelisih.' hari' : 'hari yang sama');
            $asli = $duplikatDari === $u->id ? $u : $banding;
            $dupe = $asli->is($u) ? $banding : $u;
            $bisaJadiAsli = $banding->status === \App\Enums\StatusUsulan::Diperiksa;
        @endphp
        <div class="flex flex-col gap-3">
            <button wire:click="$set('dialog', null)" class="flex w-fit items-center gap-1.5 font-caption text-[13px] font-semibold text-accent">@svg('lucide-arrow-left', 'size-3.5') Kembali ke pemeriksaan</button>
            <div class="flex flex-wrap items-end gap-x-3.5 gap-y-2">
                <h3 class="judul text-[30px] leading-[30px]">Tandai duplikat</h3>
                <span class="flex-1 text-[13px] text-fg-muted">Pilih satu usulan sebagai usulan asli. Yang lain ikut ke usulan asli.</span>
                <span class="flex items-center gap-1.5 rounded-sm bg-warning-bg px-2.5 py-1.5 font-caption text-xs font-semibold text-warning">@svg('lucide-ruler', 'size-3.5') Berjarak {{ $jarak }} m · dalam radius ±{{ config('usuljalan.radius_berdekatan') }} m</span>
            </div>
            @if ($berdekatan->count() > 1)
                <div class="flex flex-wrap items-center gap-2 font-caption text-xs">
                    <span class="text-fg-muted">Bandingkan dengan:</span>
                    @foreach ($berdekatan as $c)
                        <button wire:click="bukaDuplikat({{ $c->id }})" @class(['rounded-full px-3 py-1.5 font-semibold', 'bg-fg text-fg-inverse' => $c->id === $banding->id, 'bg-surface-muted hover:bg-line' => $c->id !== $banding->id])>{{ $c->nomor }} · {{ round(\App\Models\Usulan::jarak($u->lat, $u->lng, $c->lat, $c->lng)) }} m</button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid items-stretch gap-4 lg:grid-cols-[1fr_auto_1fr]">
            @foreach ([$u, 'jarak', $banding] as $k)
                @if ($k === 'jarak')
                    <div class="relative hidden w-12 justify-center lg:flex">
                        <span class="absolute inset-y-0 w-px bg-line"></span>
                        <span class="relative z-10 my-auto grid size-12 place-items-center rounded-full bg-fg text-center font-caption text-[11px] font-bold leading-3 text-fg-inverse">@svg('lucide-arrow-left-right', 'mx-auto size-3.5'){{ $jarak }} m</span>
                    </div>
                    @continue
                @endif
                @php
                    $pilih = $asli->is($k);
                    $ini = $k->is($u);
                    $bisa = $ini ? $bisaJadiAsli : true;
                    $fk = $k->foto->values();
                @endphp
                {{-- Kartu = pilih usulan asli; area foto = buka penampil foto (tidak ikut memilih) --}}
                <div wire:key="dup-{{ $k->id }}" role="button" tabindex="{{ $bisa ? 0 : -1 }}" aria-disabled="{{ $bisa ? 'false' : 'true' }}" aria-pressed="{{ $pilih ? 'true' : 'false' }}"
                     @if ($bisa) wire:click="$set('duplikatDari', {{ $k->id }})" x-data @keydown.enter.prevent="$wire.set('duplikatDari', {{ $k->id }})" @keydown.space.prevent="$wire.set('duplikatDari', {{ $k->id }})" @endif
                     @class(['flex flex-col overflow-hidden rounded-md bg-bg text-left shadow-sm outline -outline-offset-1 transition focus-visible:outline-2 focus-visible:outline-accent', 'cursor-pointer' => $bisa, 'cursor-not-allowed' => ! $bisa, 'outline-2 outline-accent' => $pilih, 'outline-1 outline-line hover:outline-accent/50' => ! $pilih && $bisa, 'outline-1 outline-line' => ! $pilih && ! $bisa])>
                    <span class="flex items-center gap-2.5 px-4 py-3">
                        <span @class(['grid size-5 place-items-center rounded-full', 'outline outline-2 -outline-offset-2 outline-accent' => $pilih, 'outline outline-[1.5px] -outline-offset-1 outline-fg-muted' => ! $pilih])>@if ($pilih)<span class="size-2.5 rounded-full bg-accent"></span>@endif</span>
                        <span @class(['flex-1 text-[15px] font-semibold', 'text-accent' => $pilih])>{{ $pilih ? 'Usulan asli' : 'Jadikan usulan asli' }}</span>
                        @if ($ini)
                            <span class="flex items-center gap-1 rounded-sm bg-surface-muted px-2 py-0.5 font-caption text-[11px] font-medium text-fg-muted">@svg('lucide-eye', 'size-3') Usulan ini</span>
                        @elseif ($lebihAwal->is($k) && $hariSelisih)
                            <span class="flex items-center gap-1 rounded-sm bg-accent-soft px-2 py-0.5 font-caption text-[11px] font-semibold text-accent">@svg('lucide-history', 'size-3') {{ $teksSelisih }} lebih awal</span>
                        @endif
                    </span>
                    @if ($fk->isNotEmpty())
                        <button type="button" x-data @click.stop="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($fk)), mulai: 0 })" class="group/foto relative block h-[148px] cursor-zoom-in bg-line" aria-label="Lihat foto {{ $k->nomor }}">
                            <img src="{{ $fk->first()->url() }}" class="size-full object-cover" alt="">
                            <span class="absolute bottom-2.5 left-2.5 flex items-center gap-1.5 rounded-sm bg-fg/70 px-2 py-1 font-caption text-[11px] font-medium text-fg-inverse">@svg('lucide-camera', 'size-3') Foto pengusul · 1/{{ $fk->count() }}</span>
                            <span class="absolute right-2.5 top-2.5 grid size-8 place-items-center rounded-full bg-bg/90 text-fg shadow-sm transition group-hover/foto:scale-110">@svg('lucide-maximize-2', 'size-4')</span>
                        </button>
                    @else
                        <span class="grid h-[148px] place-items-center bg-line text-fg-muted">@svg('lucide-image-off', 'size-7')</span>
                    @endif
                    <span class="flex flex-1 flex-col gap-3 p-4">
                        <span class="flex items-center gap-2.5 font-caption text-xs"><span class="font-semibold text-accent">{{ $k->nomor }}</span>
                            @if ($k->status === \App\Enums\StatusUsulan::Diperiksa) <x-badge tone="perhatian">Menunggu Pemeriksaan</x-badge> @else <x-status :status="$k->status" /> @endif
                        </span>
                        <span class="judul border-b border-line pb-2 text-[24px] leading-[26px]">{{ $k->alamat ?: $k->lokasiSingkat() }}</span>
                        <span class="flex flex-col font-caption text-[13px]">
                            @foreach ([
                                ['Lokasi', $k->lokasiSingkat(), null],
                                ['Titik', number_format($k->lat, 5, '.', '').', '.number_format($k->lng, 5, '.', '').($ini ? '' : " · {$jarak} m"), $ini ? null : 'text-warning'],
                                ['Diajukan', $k->diajukan_at?->translatedFormat('j M Y').' · '.$k->diajukan_at?->diffForHumans(), null],
                            ] as [$lbl, $val, $warna])
                                <span class="flex gap-3 border-b border-line py-2"><span class="w-[70px] shrink-0 text-fg-muted">{{ $lbl }}</span><span class="flex-1 font-medium {{ $warna }}">{{ $val }}</span></span>
                            @endforeach
                            <span class="flex items-center gap-3 py-2"><span class="w-[70px] shrink-0 text-fg-muted">Pengusul</span><span class="flex-1 font-medium">{{ $k->namaPengusul() }}</span><span class="rounded-sm bg-surface-muted px-1.5 py-0.5 text-[11px] font-medium">{{ $k->jenisPengusul() }}</span></span>
                        </span>
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Item · {{ $k->items->count() }}</span>
                        <span class="flex flex-col gap-1.5">
                            @foreach ($k->items as $i)
                                <span class="flex items-center gap-2 rounded-sm bg-surface-muted px-3 py-2 text-[13px]">
                                    <span class="text-accent">@svg('lucide-'.$i->jenis->ikon, 'size-4')</span>
                                    <span class="font-semibold">{{ $i->jenis->namaPendek() }}</span>
                                    <span class="flex-1 font-caption text-xs text-fg-muted">× {{ $i->jumlah }}</span>
                                    @if ($sama->contains($i->jenis_perlengkapan_id)) <span class="flex items-center gap-1 font-caption text-[11px] text-fg-muted">@svg('lucide-equal', 'size-3') sama</span> @endif
                                </span>
                            @endforeach
                        </span>
                        @if ($ini && ! $bisaJadiAsli)
                            <span class="font-caption text-xs text-fg-muted">{{ $banding->nomor }} sudah diproses, jadi usulan ini yang ditandai duplikat.</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-4 rounded-md bg-bg px-4 py-3.5 outline outline-1 -outline-offset-1 outline-line">
            <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-danger-bg text-danger">@svg('lucide-git-merge', 'size-[18px]')</span>
            <div class="flex flex-1 flex-col gap-1.5">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Yang terjadi setelah ditandai</span>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-[13px]">
                    <span class="flex items-center gap-1 rounded-sm bg-danger-bg px-1.5 py-0.5 font-caption text-xs font-semibold text-danger">@svg('lucide-x', 'size-3') Ditolak</span>
                    <span>Semua {{ $dupe->items->count() }} item {{ $dupe->nomor }}</span>
                    <span class="text-line">•</span>
                    <span class="flex items-center gap-1 rounded-sm bg-surface-muted px-1.5 py-0.5 font-caption text-xs font-semibold">@svg('lucide-copy', 'size-3') Duplikat</span>
                    <span>label di usulan</span>
                    <span class="text-line">•</span>
                    <span class="flex items-center gap-1 rounded-sm bg-accent-soft px-1.5 py-0.5 font-caption text-xs font-semibold text-accent">@svg('lucide-link', 'size-3') Link lacak</span>
                    <span>pengusul diarahkan ke {{ $asli->nomor }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button wire:click="$set('dialog', null)" class="flex items-center gap-1.5 text-[15px] font-semibold text-accent">@svg('lucide-circle-check', 'size-4') Bukan duplikat</button>
            <span class="flex-1 font-caption text-xs text-fg-muted">— lanjutkan pemeriksaan biasa</span>
            <span class="font-caption text-xs text-fg-muted">Pengusul dikabari lewat email</span>
            <button wire:click="tandaiDuplikat" wire:loading.attr="disabled" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60">Tandai Duplikat @svg('lucide-copy', 'size-[18px]')</button>
        </div>
    @else
    {{-- ── Foto & peta ── --}}
    <div class="flex flex-col gap-3 sm:h-[172px] sm:flex-row" x-data="{ aktif: 0 }">
        <div class="relative h-[200px] w-full shrink-0 overflow-hidden rounded-md bg-line sm:h-full sm:w-[360px]">
            @forelse ($foto as $n => $f)
                <button type="button" x-show="aktif === {{ $n }}" @if ($n) x-cloak @endif @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($foto)), mulai: aktif })" class="block size-full" aria-label="Lihat foto">
                    <img src="{{ $f->url() }}" class="size-full object-cover" alt="Foto lokasi">
                </button>
            @empty
                <div class="grid size-full place-items-center text-sm text-fg-muted">@svg('lucide-image-off', 'size-7')</div>
            @endforelse
            @if ($foto->isNotEmpty())
                <span class="absolute bottom-2.5 left-2.5 flex items-center gap-1.5 rounded-sm bg-fg/70 px-2 py-1 font-caption text-[11px] font-medium text-fg-inverse">
                    @svg('lucide-camera', 'size-3') Foto pengusul · <span x-text="aktif + 1"></span>/{{ $foto->count() }}
                </span>
            @endif
        </div>
        @if ($foto->count() > 1)
            <div class="flex gap-2 sm:w-16 sm:flex-col">
                @foreach ($foto->take(3) as $n => $f)
                    @if ($n === 2 && $foto->count() > 3)
                        <button type="button" @click="$dispatch('lihat-foto', { foto: @js(\App\Models\UsulanFoto::galeri($foto)), mulai: 2 })" class="relative h-14 flex-1 overflow-hidden rounded-sm sm:h-auto" aria-label="Lihat semua foto">
                            <img src="{{ $f->url() }}" class="size-full object-cover" alt="">
                            <span class="absolute inset-0 grid place-items-center bg-fg/55 font-caption text-sm font-semibold text-fg-inverse">+{{ $foto->count() - 3 }}</span>
                        </button>
                    @else
                        <button type="button" @click="aktif = {{ $n }}" class="h-14 flex-1 overflow-hidden rounded-sm sm:h-auto" :class="aktif === {{ $n }} ? 'outline outline-2 outline-accent' : ''">
                            <img src="{{ $f->url() }}" class="size-full object-cover" alt="">
                        </button>
                    @endif
                @endforeach
            </div>
        @endif
        @if ($u->lat)
            <div class="flex h-[172px] min-w-0 flex-1 flex-col overflow-hidden rounded-md bg-bg outline outline-1 -outline-offset-1 outline-line sm:h-auto">
                <div class="relative min-h-0 flex-1" wire:ignore
                     x-data="petaTitik(@js(['lat' => $u->lat, 'lng' => $u->lng]), @js($berdekatan->map(fn ($b) => ['lat' => $b->lat, 'lng' => $b->lng])->values()), {{ config('usuljalan.radius_berdekatan') }}, @js(svg('lucide-traffic-cone', 'size-[13px]')->toHtml()), @js(svg('lucide-copy', 'size-[11px]')->toHtml()))">
                    <div x-ref="peta" class="peta-redup absolute inset-0"></div>
                    <span class="absolute left-3 top-3 z-[500] rounded-sm bg-bg/90 px-2 py-0.5 font-caption text-[11px] font-medium">Radius {{ config('usuljalan.radius_berdekatan') }} m</span>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-3 py-2.5 font-caption">
                    @svg('lucide-crosshair', 'size-3.5 shrink-0 text-fg-muted')
                    <span class="text-[13px] font-semibold">{{ number_format($u->lat, 5, '.', '') }}, {{ number_format($u->lng, 5, '.', '') }}</span>
                    <span class="flex-1"></span>
                    <a href="https://www.google.com/maps?q={{ $u->lat }},{{ $u->lng }}" target="_blank" class="flex items-center gap-1 text-[13px] font-semibold text-accent">Buka peta @svg('lucide-external-link', 'size-[13px]')</a>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Banner ── --}}
    @if ($u->status === \App\Enums\StatusUsulan::Revisi)
        <div class="flex flex-col gap-2 rounded-md bg-warning-bg p-4 sm:flex-row sm:items-center">
            <div class="flex-1">
                <div class="text-sm font-semibold text-warning">Perlu diperbaiki: {{ collect($u->revisi_bagian)->map(fn ($b) => ucfirst($b))->implode(', ') }}</div>
                <p class="text-[13px] text-warning">{{ $u->revisi_catatan }}</p>
            </div>
            @if ($user->isPengusul()) <x-tombol :href="route('usulan.ubah', $u)" ikon="pencil">Perbaiki</x-tombol> @endif
        </div>
    @endif
    @if ($u->duplikatDari)
        <div class="rounded-md bg-surface-tint p-4 text-sm">Usulan ini sama dengan <a href="{{ route('lacak', $u->duplikatDari->nomor) }}" class="font-semibold text-accent">{{ $u->duplikatDari->nomor }}</a>. Pantau usulan tersebut untuk perkembangannya.</div>
    @endif
    @if ($dishub && $berdekatan->isNotEmpty() && $u->status === \App\Enums\StatusUsulan::Diperiksa)
        @php $b = $berdekatan->first(); @endphp
        <div class="flex items-center gap-3 rounded-md bg-warning-bg px-4 py-3 text-warning">
            @svg('lucide-triangle-alert', 'size-[18px] shrink-0')
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold">Kemungkinan duplikat — {{ $b->nomor }} berjarak {{ round(\App\Models\Usulan::jarak($u->lat, $u->lng, $b->lat, $b->lng)) }} m (dalam radius ±{{ config('usuljalan.radius_berdekatan') }} m){{ $berdekatan->count() > 1 ? ' · +'.($berdekatan->count() - 1).' lainnya' : '' }}</div>
                <div class="text-[13px]">{{ $b->items->map(fn ($i) => $i->jenis->namaPendek().' ×'.$i->jumlah)->implode(', ') }} · diajukan {{ $b->namaPengusul() }}, {{ $b->diajukan_at?->translatedFormat('j M Y') }} · status: {{ $b->status->label() }}</div>
            </div>
            <button wire:click="bukaDuplikat({{ $b->id }})" class="flex shrink-0 items-center gap-1.5 rounded-sm px-3 py-1.5 font-caption text-[13px] font-semibold outline outline-1 -outline-offset-1 outline-warning hover:bg-warning/10">Bandingkan @svg('lucide-columns-2', 'size-3.5')</button>
        </div>
    @endif

    {{-- ── Item diusulkan ── --}}
    <div class="flex flex-col gap-2.5">
        <span class="font-caption text-xs font-semibold uppercase tracking-[0.8px] text-fg-muted">Item diusulkan · {{ $u->items->count() }}</span>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($u->items as $i)
                <div class="flex gap-3 rounded-md bg-bg p-3 outline outline-1 -outline-offset-1 outline-line">
                    <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-accent-soft text-accent">@svg('lucide-'.$i->jenis->ikon, 'size-[18px]')</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-1.5">
                            <span class="text-sm font-semibold">{{ $i->jenis->namaPendek() }}</span>
                            <span class="font-caption text-xs font-semibold text-accent">{{ $i->jumlah }} {{ $i->jenis->satuan }}</span>
                        </div>
                        @unless ($dishub && $this->bolehPeriksa)
                            <div class="mt-1"><x-badge :tone="$i->status->tone()">{{ $i->status->label() }}</x-badge></div>
                        @endunless
                        @foreach (array_filter([$i->catatan_dishub ? 'Dishub: '.$i->catatan_dishub : null, $i->catatan_bappeda ? 'Bappeda: '.$i->catatan_bappeda : null]) as $c)
                            <div class="mt-1 text-xs italic text-fg-muted">“{{ $c }}”</div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @if ($u->keterangan) <p class="text-[13px] italic text-fg-muted">“{{ $u->keterangan }}”</p> @endif
    </div>

    {{-- ── Hasil pemeriksaan (Dishub, setelah diperiksa) ── --}}
    @if ($dishub && $u->diperiksa_at && ! $this->modePeriksa && ! $u->duplikatDari)
        @php $pemeriksa = $u->riwayat->first(fn ($r) => str_starts_with($r->aksi, 'Diperiksa Dishub')); @endphp
        <section class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-md bg-bg px-4 py-3.5 outline outline-1 -outline-offset-1 outline-line">
            <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-success-bg text-success">@svg('lucide-clipboard-check', 'size-[18px]')</span>
            <div class="flex flex-col gap-0.5">
                <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted">Hasil pemeriksaan</span>
                <span class="text-sm font-medium">{{ $pemeriksa?->user?->name ?? 'Dishub' }} · {{ $u->diperiksa_at->translatedFormat('j M Y') }}</span>
            </div>
            <div class="flex flex-1 flex-wrap items-center gap-1.5 font-caption text-xs font-semibold">
                <span class="rounded-sm bg-accent-soft px-2 py-1 text-accent">Skor {{ $u->skor ?? 0 }}/5</span>
                @foreach ($u->items as $i)
                    @if ($i->status === \App\Enums\StatusItem::DitolakDishub)
                        <span class="flex items-center gap-1 rounded-sm bg-danger-bg px-2 py-1 text-danger">@svg('lucide-x', 'size-3') {{ $i->jenis->namaPendek() }} · Ditolak</span>
                    @else
                        <span class="flex items-center gap-1 rounded-sm bg-success-bg px-2 py-1 text-success">@svg('lucide-check', 'size-3') {{ $i->jenis->namaPendek() }} · Lolos</span>
                    @endif
                @endforeach
            </div>
            @if ($this->bolehUbahDishub)
                <button wire:click="mulaiUbah" class="flex items-center gap-2 rounded-sm border-b-2 border-accent bg-accent-soft px-3.5 py-2 text-sm font-semibold text-accent hover:brightness-95">@svg('lucide-pencil', 'size-4') Ubah Keputusan</button>
            @elseif ($u->items->contains(fn ($i) => ! in_array($i->status, [\App\Enums\StatusItem::Lolos, \App\Enums\StatusItem::DitolakDishub])))
                <span class="font-caption text-xs text-fg-muted">Bappeda sudah memutuskan · tidak bisa diubah</span>
            @endif
        </section>
    @endif

    {{-- ── Periksa (Dishub): baru atau dibuka ulang ── --}}
    @if ($this->modePeriksa)
        @php
            $ubah = ! $this->bolehPeriksa;
            $skor = count($kriteriaDishub);
            $lolos = $u->items->filter(fn ($i) => ($keputusan[$i->id] ?? '') === 'lolos');
            $awal = fn ($i) => $i->status === \App\Enums\StatusItem::DitolakDishub ? 'tolak' : 'lolos';
            $lolosAwal = $u->items->filter(fn ($i) => $awal($i) === 'lolos')->count();
        @endphp
        <section class="flex flex-col gap-4 rounded-md bg-bg p-5 shadow-sm outline outline-1 -outline-offset-1 outline-line sm:px-6"
                 x-data="{
                     st: '',
                     simpan() {
                         if (this.st) return;
                         this.st = 'simpan';
                         $wire.simpanPeriksa().then((ok) => {
                             if (! ok) return this.st = '';
                             this.st = 'ok';
                             setTimeout(() => Livewire.dispatch('usulan-diproses', { lanjut: true }), 700);
                         }).catch(() => this.st = '');
                     },
                 }"
                 @keydown.ctrl.enter.window="{{ $ubah ? '' : 'simpan()' }}" @keydown.meta.enter.window="{{ $ubah ? '' : 'simpan()' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <h3 class="judul text-[26px] leading-[26px]">Periksa</h3>
                    <span class="text-[13px] text-fg-muted">{{ $ubah ? 'Dibuka ulang · nilai sebelumnya sudah terisi' : 'Ketuk kriteria yang terpenuhi di lapangan' }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex gap-[3px]">
                        @for ($n = 1; $n <= 5; $n++) <span @class(['h-1.5 w-[18px] rounded-[2px] transition', 'bg-accent' => $n <= $skor, 'bg-line' => $n > $skor])></span> @endfor
                    </div>
                    <span class="flex items-end gap-1"><span class="font-caption text-[13px] font-medium text-fg-muted">Skor</span><span class="judul text-[26px] leading-[26px] text-accent">{{ $skor }}/5</span></span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-5">
                @foreach (\App\Enums\Kriteria::cases() as $k)
                    @php $on = in_array($k->value, $kriteriaDishub); $klaim = in_array($k->value, $u->kriteria_klaim ?? []); @endphp
                    <button wire:click="toggleKriteria('{{ $k->value }}')" title="{{ $klaim ? 'Diklaim pengusul' : '' }}" @class([
                        'flex h-[52px] items-center gap-2.5 rounded-sm px-3 text-left text-[13px] leading-4 transition',
                        'bg-accent-soft font-semibold outline outline-[1.5px] -outline-offset-1 outline-accent' => $on,
                        'bg-bg font-medium text-fg-muted outline outline-1 -outline-offset-1 outline-line hover:outline-accent/50' => ! $on,
                    ])>
                        <span @class(['grid size-[18px] shrink-0 place-items-center rounded-sm', 'bg-accent text-fg-inverse' => $on, 'outline outline-[1.5px] -outline-offset-1 outline-[#B8BDD0]' => ! $on])>@if ($on) @svg('lucide-check', 'size-3') @endif</span>
                        <span class="flex-1">{{ $k->label() }}@if ($klaim && ! $on)<span class="text-accent"> •</span>@endif</span>
                    </button>
                @endforeach
            </div>
            @error('skor') <div class="-mt-2 text-sm text-danger">{{ $message }}</div> @enderror

            <div class="overflow-hidden rounded-sm outline outline-1 -outline-offset-1 outline-line">
                <div class="hidden items-center gap-4 bg-surface-muted px-3.5 py-2 font-caption text-[11px] font-semibold uppercase tracking-[0.8px] text-fg-muted sm:flex">
                    <span class="flex-1">Keputusan per item</span><span class="w-[140px] text-right">Estimasi biaya</span><span class="w-[190px] text-right">Lolos / Tolak</span>
                </div>
                @foreach ($u->items as $i)
                    @php
                        $tolak = ($keputusan[$i->id] ?? '') === 'tolak';
                        $diubah = $ubah && ($keputusan[$i->id] ?? '') !== $awal($i);
                    @endphp
                    <div @class(['border-t border-line px-3.5 py-2.5 first:border-t-0 sm:first:border-t', 'bg-accent-soft/70' => $diubah]) wire:key="p-{{ $i->id }}">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span @class(['text-sm font-semibold', 'text-fg-muted' => $tolak && ! $diubah])>{{ $i->jenis->namaPendek() }}</span>
                                    <span class="font-caption text-xs text-fg-muted">× {{ $i->jumlah }} {{ $i->jenis->satuan }}</span>
                                </div>
                                @if ($diubah)
                                    <div class="mt-1 flex items-center gap-1.5 font-caption text-xs font-semibold">
                                        <span class="flex items-center gap-1 rounded-sm bg-accent px-1.5 py-px text-[11px] text-fg-inverse">@svg('lucide-pencil', 'size-2.5') Diubah</span>
                                        <span @class(['text-danger' => $awal($i) === 'tolak', 'text-success' => $awal($i) === 'lolos'])>{{ ucfirst($awal($i)) }}</span>
                                        @svg('lucide-arrow-right', 'size-3 text-fg-muted')
                                        <span @class(['text-danger' => $tolak, 'text-success' => ! $tolak])>{{ $tolak ? 'Tolak' : 'Lolos' }}</span>
                                    </div>
                                @endif
                                @if ($tolak && filled($catatan[$i->id] ?? ''))
                                    <button wire:click="bukaTolak({{ $i->id }})" class="mt-0.5 line-clamp-1 text-left font-caption text-xs text-danger hover:underline" title="Ubah alasan">“{{ $catatan[$i->id] }}”</button>
                                @endif
                                @error("catatan.{$i->id}") <div class="mt-1 text-xs text-danger">{{ $message }}</div> @enderror
                            </div>
                            <x-rupiah @class(['w-[140px] text-right text-sm font-semibold', 'text-fg-muted' => $tolak]) :nilai="$i->jumlah * $i->jenis->harga_satuan" />
                            <div class="flex w-[190px] justify-end gap-1.5 font-caption text-[13px] font-semibold">
                                <button wire:click="loloskan({{ $i->id }})" @class(['flex items-center gap-1 rounded-sm px-2.5 py-1.5 outline -outline-offset-1', 'bg-success-bg text-success outline-success' => ! $tolak, 'outline-2' => ! $tolak && $diubah, 'outline-1' => $tolak || ! $diubah, 'text-fg-muted outline-line hover:outline-success' => $tolak])>@svg('lucide-check', 'size-3.5') Lolos</button>
                                <button wire:click="bukaTolak({{ $i->id }})" @class(['flex items-center gap-1 rounded-sm px-2.5 py-1.5 outline outline-1 -outline-offset-1', 'bg-danger-bg text-danger outline-danger' => $tolak, 'text-fg-muted outline-line hover:outline-danger' => ! $tolak, 'bg-danger-bg/60' => ! $tolak && $diubah])>@svg('lucide-x', 'size-3.5') Tolak</button>
                            </div>
                        </div>
                    </div>
                @endforeach
                <div class="flex items-center gap-4 border-t border-line bg-surface-muted px-3.5 py-2.5">
                    <span class="flex-1 font-caption text-[13px] text-fg-muted">{{ $lolos->count() }} dari {{ $u->items->count() }} item diloloskan{{ $ubah ? " · sebelumnya {$lolosAwal} dari {$u->items->count()}" : '' }}</span>
                    <x-rupiah class="w-[140px] text-right text-sm font-bold" :nilai="$lolos->sum(fn ($i) => $i->jumlah * $i->jenis->harga_satuan)" />
                    <span class="hidden w-[190px] sm:block"></span>
                </div>
            </div>

            @if ($ubah)
                <div class="flex flex-col gap-2" x-data="{ n: {{ mb_strlen($dialogCatatan) }} }">
                    <div class="flex items-center gap-2"><span class="text-sm font-semibold">Alasan perubahan</span><span class="font-caption text-xs font-semibold text-danger">wajib diisi</span><span class="font-caption text-xs text-fg-muted">· hanya perlu satu kalimat</span></div>
                    <label class="flex items-center gap-3 rounded-sm bg-bg px-3.5 py-3 outline outline-[1.5px] -outline-offset-1 outline-fg focus-within:outline-accent">
                        <input wire:model="dialogCatatan" x-on:input="n = $el.value.length" maxlength="150" class="flex-1 bg-transparent text-[15px] outline-none" placeholder="Mis. Salah pilih; lokasi memenuhi syarat">
                        <span class="font-caption text-xs text-fg-muted" x-text="n + ' / 150'"></span>
                    </label>
                    @error('dialogCatatan') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="flex flex-1 items-center gap-2 font-caption text-xs text-success">@svg('lucide-history', 'size-4') Tercatat di riwayat · item diberi label Diubah · pengusul & Bappeda dikabari</span>
                    <button wire:click="batalUbah" class="rounded-sm bg-bg px-4 py-3 text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">Batal</button>
                    <button wire:click="simpanUbahDishub" wire:loading.attr="disabled" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110 disabled:opacity-60">Simpan Perubahan @svg('lucide-check', 'size-[18px]')</button>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="$set('dialog', 'revisi')" class="flex items-center gap-2 rounded-sm bg-bg px-3.5 py-[11px] text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-message-square-reply', 'size-4') Minta Revisi</button>
                    @if ($berdekatan->isNotEmpty())
                        <button wire:click="bukaDuplikat" class="flex items-center gap-2 rounded-sm bg-bg px-3.5 py-[11px] text-sm font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-copy', 'size-4') Tandai Duplikat</button>
                    @endif
                    <span class="flex-1"></span>
                    <span class="hidden items-center gap-1.5 font-caption text-xs text-fg-muted lg:flex"><kbd class="rounded-sm bg-surface-muted px-1.5 py-0.5 text-[11px] font-semibold text-fg outline outline-1 -outline-offset-1 outline-line">Ctrl ↵</kbd> simpan cepat</span>
                    <button type="button" @click="simpan()" :disabled="st !== ''" :class="st === 'ok' ? 'bg-success' : (st === 'simpan' ? 'bg-accent/60 cursor-wait' : 'bg-accent hover:brightness-110')"
                            class="flex min-w-[210px] items-center justify-center gap-2 rounded-sm px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm transition-colors">
                        <span x-show="st === ''" class="flex items-center gap-2">Simpan & Berikutnya @svg('lucide-arrow-right', 'size-[18px]')</span>
                        <span x-show="st === 'simpan'" x-cloak class="flex items-center gap-2">@svg('lucide-loader-circle', 'size-[18px] animate-spin') Menyimpan…</span>
                        <span x-show="st === 'ok'" x-cloak class="flex items-center gap-2">@svg('lucide-check', 'size-[18px]') Tersimpan</span>
                    </button>
                </div>
            @endif
        </section>
    @endif

        @if ($user->isPengusul() && in_array($u->status, [\App\Enums\StatusUsulan::Draft, \App\Enums\StatusUsulan::Diperiksa]))
            <section class="flex flex-wrap items-center gap-3 rounded-md bg-bg p-4 outline outline-1 -outline-offset-1 outline-line">
                @if ($u->status === \App\Enums\StatusUsulan::Draft)
                    <span class="flex-1 text-sm">Usulan belum dikirim.</span>
                    <x-tombol variant="teks" ikon="trash-2" wire:click="hapusDraft" wire:confirm="Hapus draft ini?">Hapus</x-tombol>
                    <x-tombol :href="route('usulan.ubah', $u)" ikonKanan="arrow-right">Lanjutkan</x-tombol>
                @else
                    <span class="flex-1 text-sm">Bagikan link agar keluarga/warga bisa ikut memantau.</span>
                    @unless ($u->diperiksa_at)
                        <x-tombol variant="teks" wire:click="batalkan">Batalkan</x-tombol>
                    @endunless
                    <x-tombol variant="kedua" ikon="share-2" x-data x-on:click="salin('{{ route('lacak', $u->nomor) }}'); $dispatch('toast', { pesan: 'Link lacak disalin' })">Bagikan link lacak</x-tombol>
                @endif
            </section>
        @endif

        {{-- Bappeda: keputusan per item --}}
        @if ($this->itemBappeda->isNotEmpty())
            <section class="rounded-md bg-bg p-5 shadow-sm outline outline-1 -outline-offset-1 outline-line sm:px-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="judul text-[26px] leading-[26px]">Keputusan</h3>
                        <p class="text-sm text-fg-muted">Hasil Dishub: skor {{ $u->skor }}/5 · {{ collect($u->kriteria_dishub)->map(fn ($k) => \App\Enums\Kriteria::from($k)->label())->implode(', ') }}</p>
                    </div>
                    <label class="text-right text-sm">
                        <span class="label-kecil block">Tahun anggaran</span>
                        <select wire:model.live="tahun" class="mt-1 rounded-sm border border-line bg-bg px-2 py-1">
                            @foreach (range(now()->year, now()->year + 3) as $t) <option>{{ $t }}</option> @endforeach
                        </select>
                    </label>
                </div>
                <ul class="mt-3 divide-y divide-line">
                    @foreach ($this->itemBappeda as $i)
                        <li class="py-3" wire:key="b-{{ $i->id }}">
                            <div class="flex flex-wrap items-center gap-3">
                                <x-ikon-jenis :jenis="$i->jenis" />
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">{{ $i->jenis->nama }} @if ($i->status === \App\Enums\StatusItem::Ditunda) <x-badge tone="perhatian" :dot="false">Ditunda ke {{ $i->tahun_target }}</x-badge> @endif</div>
                                    <div class="text-sm text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }} · <x-rupiah :nilai="$i->estimasi()" /></div>
                                </div>
                                <div class="flex overflow-hidden rounded-sm border border-line text-sm font-semibold">
                                    @foreach (['setujui' => ['Setujui', 'bg-success'], 'tunda' => ['Tunda', 'bg-warning'], 'tolak' => ['Tolak', 'bg-danger']] as $v => [$l, $w])
                                        <button wire:click="$set('keputusan.{{ $i->id }}', '{{ $v }}')" @class(['px-3 py-1.5', "$w text-fg-inverse" => ($keputusan[$i->id] ?? '') === $v])>{{ $l }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @if (in_array($keputusan[$i->id] ?? '', ['tunda', 'tolak']))
                                <input wire:model.blur="catatan.{{ $i->id }}" class="input mt-2 py-2 text-sm" placeholder="{{ ($keputusan[$i->id] ?? '') === 'tunda' ? 'Alasan ditunda ke '.($tahun + 1) : 'Alasan ditolak' }}">
                                @error("catatan.{$i->id}") <div class="mt-1 text-xs text-danger">{{ $message }}</div> @enderror
                            @endif
                        </li>
                    @endforeach
                </ul>
                @php $setuju = $this->itemBappeda->filter(fn ($i) => ($keputusan[$i->id] ?? '') === 'setujui'); @endphp
                <div class="mt-3 flex items-center justify-between gap-3">
                    <span class="text-sm">{{ $setuju->count() }} item disetujui · <x-rupiah class="font-semibold" :nilai="$setuju->sum(fn ($i) => $i->estimasi())" /></span>
                    <x-tombol wire:click="simpanBappeda" ikonKanan="arrow-right">Simpan & Berikutnya</x-tombol>
                </div>
            </section>
        @endif

        {{-- Dishub: siap dipasang --}}
        @if ($user->hasRole(\App\Enums\Role::Dishub) && $u->items->contains('status', \App\Enums\StatusItem::Disetujui))
            <section class="rounded-md bg-bg p-5 outline outline-1 -outline-offset-1 outline-line sm:px-6">
                <h3 class="judul text-[26px] leading-[26px]">Siap dipasang</h3>
                <ul class="mt-2 divide-y divide-line">
                    @foreach ($u->items->where('status', \App\Enums\StatusItem::Disetujui) as $i)
                        <li class="flex items-center gap-3 py-3">
                            <x-ikon-jenis :jenis="$i->jenis" />
                            <div class="flex-1"><div class="font-medium">{{ $i->jenis->nama }}</div><div class="text-sm text-fg-muted">{{ $i->jumlahEfektif() }} {{ $i->jenis->satuan }} · anggaran {{ $i->tahun_anggaran }}</div></div>
                            <x-tombol wire:click="bukaTerpasang({{ $i->id }})" ikon="camera">Tandai Terpasang</x-tombol>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif


    {{-- ── Alasan, proposal, riwayat ── --}}
    <div class="grid items-start gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-2 rounded-md bg-bg p-4 outline outline-1 -outline-offset-1 outline-line">
            <span class="font-caption text-xs font-semibold uppercase tracking-[0.8px] text-fg-muted">Alasan dari pengusul</span>
            <div class="flex flex-wrap gap-1.5">
                @forelse ($u->kriteria_klaim ?? [] as $k)
                    <x-badge tone="proses" :dot="false">{{ \App\Enums\Kriteria::from($k)->labelAwam() }}</x-badge>
                @empty
                    <span class="text-sm text-fg-muted">—</span>
                @endforelse
            </div>
            @if ($u->proposal_path)
                <a href="{{ route('usulan.proposal', $u) }}" target="_blank" class="mt-2 flex items-center gap-3 rounded-sm bg-surface-muted p-3 hover:bg-line">
                    <span class="text-danger">@svg('lucide-file-text', 'size-5')</span>
                    <span class="flex-1 text-sm"><span class="block font-medium">Proposal {{ $u->nomor }}.pdf</span><span class="text-xs text-fg-muted">{{ $u->proposal_otomatis ? 'Dibuat otomatis dari isian' : 'Diunggah pengusul' }}</span></span>
                    @svg('lucide-external-link', 'size-4 text-fg-muted')
                </a>
            @endif
        </section>
        <section class="flex flex-col gap-2 rounded-md bg-bg p-4 outline outline-1 -outline-offset-1 outline-line">
            <span class="font-caption text-xs font-semibold uppercase tracking-[0.8px] text-fg-muted">Riwayat</span>
            <ol class="flex flex-col gap-3">
                @foreach ($u->riwayat->take(6) as $r)
                    <li class="flex gap-3">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-accent' => $loop->first, 'bg-[#C4C9D8]' => ! $loop->first])></span>
                        <div class="text-sm">
                            <div>{{ $r->aksi }}</div>
                            @if ($r->keterangan) <div class="text-[13px] italic text-fg-muted">“{{ $r->keterangan }}”</div> @endif
                            <div class="font-caption text-xs text-fg-muted">{{ $r->created_at->translatedFormat('j M Y, H.i') }} · {{ $r->user?->name ?? 'Sistem' }}</div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
    @endif

    @if ($dialog === 'revisi')
        @php
            $bagianList = ['foto' => ['camera', 'Foto'], 'lokasi' => ['map-pin', 'Lokasi'], 'kebutuhan' => ['package', 'Kebutuhan'], 'proposal' => ['file-text', 'Proposal']];
            $alasanCepat = ['foto' => 'Foto kurang jelas', 'lokasi' => 'Lokasi kurang tepat', 'kebutuhan' => 'Jenis/jumlah perlu disesuaikan', 'proposal' => 'Proposal belum lengkap'];
            $terpilih = collect($revisiBagian)->map(fn ($b) => $bagianList[$b][1] ?? $b);
        @endphp
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" x-data @keydown.escape.window="$wire.set('dialog', null)">
            <div class="flex max-h-[92dvh] w-full flex-col overflow-y-auto rounded-t-xl bg-bg shadow-xl sm:max-w-[640px] sm:rounded-md">
                <div class="flex gap-4 px-5 pb-5 pt-6 sm:px-8 sm:pt-7">
                    <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-warning-bg text-warning">@svg('lucide-message-square-reply', 'size-[22px]')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-accent">{{ $u->nomor }} · {{ $u->alamat ?: $u->lokasiSingkat() }}</span>
                        <h3 class="judul text-[30px] leading-[30px]">Minta revisi</h3>
                        <p class="text-sm leading-5 text-fg-muted">Pilih bagian yang perlu diperbaiki. Bagian lain tetap aman dan tidak perlu diisi ulang oleh pengusul.</p>
                    </div>
                    <button wire:click="$set('dialog', null)" class="grid size-8 shrink-0 place-items-center rounded-sm text-fg-muted hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-[18px]')</button>
                </div>
                <div class="flex flex-col gap-[22px] px-5 pb-6 sm:px-8">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2"><span class="text-sm font-semibold">1. Bagian yang perlu diperbaiki</span><span class="font-caption text-xs text-fg-muted">bisa pilih lebih dari satu</span></div>
                        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                            @foreach ($bagianList as $v => [$ikon, $l])
                                @php $on = in_array($v, $revisiBagian); @endphp
                                <label @class(['flex h-[52px] cursor-pointer items-center justify-center gap-2 rounded-sm text-[15px] font-semibold outline -outline-offset-1', 'bg-accent text-fg-inverse shadow-sm outline-2 outline-accent' => $on, 'bg-bg outline-1 outline-line hover:outline-accent/50' => ! $on])>
                                    <input type="checkbox" wire:model.live="revisiBagian" value="{{ $v }}" class="sr-only">
                                    @svg('lucide-'.($on ? 'circle-check' : $ikon), 'size-[18px]') {{ $l }}
                                </label>
                            @endforeach
                        </div>
                        @error('revisiBagian') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                    </div>
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2"><span class="text-sm font-semibold">2. Alasan cepat</span><span class="font-caption text-xs text-fg-muted">ketuk untuk mengisi catatan</span></div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($alasanCepat as $b => $teks)
                                @php $dipakai = str_contains($dialogCatatan, $teks); @endphp
                                <button type="button" wire:click="alasanRevisi('{{ $b }}')" @class(['flex items-center gap-1.5 rounded-full px-3.5 py-2 text-[13px]', 'bg-accent-soft font-semibold text-accent outline outline-1 -outline-offset-1 outline-accent' => $dipakai, 'bg-surface-muted font-medium' => ! $dipakai])>
                                    @svg('lucide-'.($dipakai ? 'check' : 'plus'), 'size-3.5') {{ $teks }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-col gap-2.5" x-data="{ n: {{ mb_strlen($dialogCatatan) }} }">
                        <span class="text-sm font-semibold">3. Catatan untuk pengusul</span>
                        <div class="flex flex-col gap-2 rounded-sm bg-bg px-4 pb-2.5 pt-3.5 shadow-sm outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                            <textarea wire:model="dialogCatatan" x-on:input="n = $el.value.length" maxlength="300" rows="3" class="w-full resize-none bg-transparent text-[15px] leading-[23px] outline-none" placeholder="Mis. Foto kurang jelas. Ambil dari arah jalan supaya kondisi tikungan terlihat."></textarea>
                            <div class="flex items-center justify-between font-caption text-xs text-fg-muted"><span>Tulis dengan bahasa sederhana</span><span x-text="n + ' / 300'"></span></div>
                        </div>
                        @error('dialogCatatan') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                    </div>
                    <div class="flex items-center gap-3 rounded-sm bg-surface-tint px-3.5 py-3 text-accent-deep">
                        @svg('lucide-mail', 'size-[18px] shrink-0')
                        <span class="text-[13px] leading-[18px]">Pengusul akan dikabari lewat email{{ $terpilih->isNotEmpty() ? ' dan hanya melihat bagian '.$terpilih->implode(', ') : '' }}.</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-8">
                    <span class="flex flex-1 items-center gap-2 font-caption text-xs text-fg-muted">
                        <span class="grid size-7 place-items-center rounded-full bg-accent-deep text-[11px] font-bold text-fg-inverse">{{ str($user->name)->explode(' ')->take(2)->map(fn ($k) => $k[0])->implode('') }}</span>
                        Dikirim sebagai {{ $user->name }} · Dishub
                    </span>
                    <button wire:click="$set('dialog', null)" class="rounded-sm bg-bg px-5 py-3 text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-line">Batal</button>
                    <button wire:click="mintaRevisi" wire:loading.attr="disabled" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Kirim Permintaan Revisi @svg('lucide-send', 'size-[18px] rotate-45')</button>
                </div>
            </div>
        </div>
    @endif

    @if ($dialog === 'tolak' && ($it = $u->items->firstWhere('id', $itemTolak)))
        @php $pilihanTolak = $this->alasanTolakList($it); @endphp
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" x-data @keydown.escape.window="$wire.set('dialog', null)">
            <div class="flex max-h-[92dvh] w-full flex-col overflow-y-auto rounded-t-xl bg-bg shadow-xl sm:max-w-[640px] sm:rounded-md">
                <div class="flex gap-4 px-5 pb-5 pt-6 sm:px-8 sm:pt-7">
                    <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-danger-bg text-danger">@svg('lucide-circle-x', 'size-[22px]')</span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <span class="font-caption text-[11px] font-semibold uppercase tracking-[0.6px] text-accent">{{ $u->nomor }} · {{ $u->alamat ?: $u->lokasiSingkat() }}</span>
                        <h3 class="judul text-[30px] leading-[30px]">Tolak item</h3>
                        <p class="text-sm leading-5 text-fg-muted">Item ini tidak diteruskan ke Bappeda. Item lain di usulan ini tetap diproses seperti biasa.</p>
                    </div>
                    <button wire:click="$set('dialog', null)" class="grid size-8 shrink-0 place-items-center rounded-sm text-fg-muted hover:bg-surface-muted" aria-label="Tutup">@svg('lucide-x', 'size-[18px]')</button>
                </div>
                <div class="flex flex-col gap-[22px] px-5 pb-6 sm:px-8">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2"><span class="text-sm font-semibold">1. Item yang ditolak</span><span class="font-caption text-xs text-fg-muted">hanya item ini, bukan seluruh usulan</span></div>
                        <div class="flex items-center gap-3.5 rounded-sm bg-surface-muted px-4 py-3.5 outline outline-1 -outline-offset-1 outline-line">
                            <span class="grid size-11 shrink-0 place-items-center rounded-sm bg-danger-bg text-danger">@svg('lucide-'.$it->jenis->ikon, 'size-5')</span>
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="flex flex-wrap items-center gap-2"><span class="text-base font-semibold">{{ $it->jenis->namaPendek() }}</span><span class="font-caption text-xs font-semibold text-accent">×{{ $it->jumlah }} {{ $it->jenis->satuan }}</span></span>
                                @if ($u->keterangan || $it->jenis->deskripsi) <span class="truncate text-[13px] italic text-fg-muted">{{ $u->keterangan ? '“'.$u->keterangan.'”' : $it->jenis->deskripsi }}</span> @endif
                            </div>
                            <div class="flex flex-col items-end gap-0.5">
                                <span class="font-caption text-[11px] text-fg-muted">Estimasi biaya</span>
                                <x-rupiah class="text-base font-bold" :nilai="$it->jumlah * $it->jenis->harga_satuan" />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2"><span class="text-sm font-semibold">2. Alasan cepat</span><span class="font-caption text-xs text-fg-muted">pilih satu, catatan terisi otomatis</span></div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($pilihanTolak as $kunci => [$label])
                                @php $on = $alasanTolak === $kunci; @endphp
                                <button type="button" wire:click="pilihAlasanTolak('{{ $kunci }}')" @class(['flex items-center gap-1.5 rounded-full px-3.5 py-2 text-[13px]', 'bg-danger-bg font-semibold text-danger outline outline-1 -outline-offset-1 outline-danger' => $on, 'bg-surface-muted font-medium hover:bg-line' => ! $on])>
                                    @svg('lucide-'.($on ? 'check' : 'plus'), 'size-3.5') {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-col gap-2.5" x-data="{ n: {{ mb_strlen($dialogCatatan) }} }" x-effect="n = $wire.dialogCatatan.length">
                        <div class="flex items-center gap-2"><span class="text-sm font-semibold">3. Catatan untuk pengusul</span><span class="font-caption text-xs font-semibold text-danger">wajib diisi</span></div>
                        <div class="flex flex-col gap-2 rounded-sm bg-bg px-4 pb-2.5 pt-3.5 outline outline-[1.5px] -outline-offset-1 outline-fg focus-within:outline-2 focus-within:outline-accent">
                            <textarea wire:model="dialogCatatan" x-on:input="n = $el.value.length" maxlength="300" rows="3" class="w-full resize-none bg-transparent text-[15px] leading-[23px] outline-none" placeholder="Jelaskan dengan bahasa sederhana kenapa item ini tidak dilanjutkan."></textarea>
                            <div class="flex items-center justify-between font-caption text-xs text-fg-muted"><span>{{ $alasanTolak ? 'Terisi dari alasan cepat · boleh diubah' : 'Tulis dengan bahasa sederhana' }}</span><span x-text="n + ' / 300'"></span></div>
                        </div>
                        @error('dialogCatatan') <div class="text-sm text-danger">{{ $message }}</div> @enderror
                    </div>
                    <div class="flex items-center gap-3 rounded-sm bg-surface-tint px-3.5 py-3 text-accent-deep">
                        @svg('lucide-eye', 'size-[18px] shrink-0')
                        <span class="text-[13px] leading-[18px]">Pengusul melihat alasan ini di halaman lacak usulan, dan dikabari lewat email.</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-8">
                    <span class="flex flex-1 items-center gap-2 font-caption text-xs text-fg-muted">
                        <span class="grid size-7 place-items-center rounded-full bg-accent-deep text-[11px] font-bold text-fg-inverse">{{ $user->inisial() }}</span>
                        Dikirim sebagai {{ $user->name }} · Dishub
                    </span>
                    <button wire:click="$set('dialog', null)" class="rounded-sm bg-bg px-5 py-3 text-[15px] font-semibold outline outline-1 -outline-offset-1 outline-line">Batal</button>
                    <button wire:click="tolakItem" class="flex items-center gap-2 rounded-sm bg-danger px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">Tolak Item @svg('lucide-x', 'size-[18px]')</button>
                </div>
            </div>
        </div>
    @endif

    @if ($dialog === 'terpasang')
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" x-data @keydown.escape.window="$wire.set('dialog', null)">
            <div class="max-h-[90dvh] w-full overflow-y-auto rounded-t-xl bg-bg p-5 sm:max-w-lg sm:rounded-md">
                    @php $it = $u->items->firstWhere('id', $itemTerpasang); @endphp
                    <h3 class="judul text-2xl">Tandai terpasang</h3>
                    <p class="mt-1 text-sm text-fg-muted">{{ $it?->jenis->nama }} · {{ $u->alamat ?: $u->lokasiSingkat() }}</p>
                    <div class="mt-4" x-data="{
                            koordinat: [], lat: null, lng: null, mengunggah: false,
                            async pilih(e) {
                                this.mengunggah = true;
                                const files = [...e.target.files];
                                this.koordinat = await Promise.all(files.map(f => window.koordinatFoto(f)));
                                const k = this.koordinat.find(Boolean);
                                if (k) { this.lat = k.lat; this.lng = k.lng }
                                $wire.set('fotoHasilKoordinat', this.koordinat, false);
                                $wire.uploadMultiple('fotoHasil', files, () => this.mengunggah = false, () => this.mengunggah = false);
                            },
                            gps() { navigator.geolocation?.getCurrentPosition(p => { this.lat = p.coords.latitude; this.lng = p.coords.longitude }) },
                        }" x-init="gps()">
                        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-md border-2 border-dashed border-accent/40 bg-accent-soft p-6 text-accent">
                            @svg('lucide-camera', 'size-8')
                            <span class="font-semibold" x-text="mengunggah ? 'Mengunggah…' : '{{ $fotoHasil ? count($fotoHasil).' foto siap · ganti' : 'Ambil foto hasil pemasangan' }}'"></span>
                            <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="pilih">
                        </label>
                        @error('fotoHasil') <div class="mt-1 text-sm text-danger">{{ $message }}</div> @enderror
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <label class="text-sm"><span class="font-medium">Jumlah terpasang</span><input type="number" wire:model="jumlahTerpasang" class="input mt-1"></label>
                            <label class="text-sm"><span class="font-medium">Tanggal</span><input type="date" wire:model="tanggalTerpasang" class="input mt-1"></label>
                        </div>
                        <p class="mt-2 font-caption text-xs text-fg-muted" x-text="lat ? 'Lokasi GPS ' + lat.toFixed(5) + ', ' + lng.toFixed(5) : 'Lokasi = titik usulan'"></p>
                        <div class="mt-4 flex justify-end gap-2">
                            <x-tombol variant="teks" wire:click="$set('dialog', null)">Batal</x-tombol>
                            <x-tombol x-on:click="$wire.simpanTerpasang(lat, lng)" x-bind:disabled="mengunggah" ikon="check">Selesai</x-tombol>
                        </div>
                    </div>
            </div>
        </div>
    @endif
</div>
@endif

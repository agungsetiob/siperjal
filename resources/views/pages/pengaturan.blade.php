<?php

use App\Enums\Role;
use App\Models\Desa;
use App\Models\Instansi;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LogPengaturan;
use App\Models\Pengaturan;
use App\Models\RuasJalan;
use App\Models\User;
use App\Support\PetaConfig;
use App\Models\UsulanItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $tab = 'perlengkapan';

    public string $cari = '';

    public int $persen = 5;

    public ?string $dialog = null; // pengguna, instansi, jenis, ruas

    public array $form = [];

    public array $desaBaru = []; // kecamatan_id => nama

    public string $kecamatanBaru = '';

    public string $petaKunci = '';

    public int $versi = 0; // dinaikkan saat nilai dikembalikan (gagal/urungkan) agar isian ikut tergambar ulang

    public const STATUS_RUAS = ['primer' => 'Primer', 'sekunder' => 'Sekunder', 'lokal' => 'Lokal', 'lingkungan' => 'Lingkungan'];

    public const KEWENANGAN_RUAS = ['nasional' => 'Nasional', 'provinsi' => 'Provinsi', 'kabupaten' => 'Kabupaten/Kota', 'desa' => 'Desa'];

    /** Nama kolom untuk pesan & riwayat. */
    private const LABEL = [
        'nama' => 'nama', 'name' => 'nama', 'deskripsi' => 'keterangan', 'satuan' => 'satuan', 'ikon' => 'ikon', 'warna' => 'warna',
        'email' => 'email', 'no_hp' => 'no. HP', 'instansi_id' => 'instansi', 'jenis' => 'jenis', 'kecamatan_id' => 'kecamatan',
        'status' => 'status', 'kewenangan' => 'kewenangan',
    ];

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasRole(Role::Admin), 403);
    }

    public function mount(): void
    {
        $this->persen = (int) Pengaturan::ambil('ssh_persen', 5);
    }

    // ── Data
    #[Computed]
    public function jumlahTab(): array
    {
        return [
            'pengguna' => User::count(),
            'instansi' => Instansi::count(),
            'perlengkapan' => JenisPerlengkapan::count(),
            'wilayah' => Kecamatan::count().' kec.',
            'peta' => PetaConfig::penyedia() === 'google' ? 'Google' : 'OSM',
        ];
    }

    #[Computed]
    public function jenis()
    {
        return JenisPerlengkapan::orderBy('urutan')
            ->when($this->cari, fn ($q) => $q->where('nama', 'like', "%{$this->cari}%"))
            ->withCount('items')
            ->get();
    }

    #[Computed]
    public function pengguna()
    {
        return User::with('instansi')->latest()
            ->when($this->cari, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->cari}%")->orWhere('email', 'like', "%{$this->cari}%")))
            ->get();
    }

    #[Computed]
    public function instansi()
    {
        return Instansi::with('kecamatan')->withCount('users')->orderBy('nama')
            ->when($this->cari, fn ($q) => $q->where('nama', 'like', "%{$this->cari}%"))
            ->get();
    }

    #[Computed]
    public function riwayat()
    {
        return LogPengaturan::with('user')->latest('id')->limit(5)->get();
    }

    // ── Simpan otomatis satu kolom + toast Urungkan
    private function ubah($model, string $kolom, mixed $nilai, string $keterangan): void
    {
        $lama = $model->{$kolom};
        if ($lama == $nilai) {
            return;
        }
        $model->update([$kolom => $nilai]);
        LogPengaturan::catat($keterangan);

        $token = 'set:'.auth()->id().':'.Str::random(12);
        Cache::put($token, [get_class($model), $model->getKey(), $kolom, $lama, $keterangan], now()->addMinutes(10));
        $this->dispatch('toast', pesan: str($keterangan)->before(':')->append(' diperbarui')->toString(), urungkan: $token);
    }

    #[On('urungkan')]
    public function urungkan(string $token): void
    {
        if (! str_starts_with($token, 'set:'.auth()->id().':') || ! ($d = Cache::pull($token))) {
            return;
        }
        [$kelas, $id, $kolom, $lama, $ket] = $d;
        $kelas::find($id)?->update([$kolom => $lama]);
        LogPengaturan::catat('Dibatalkan: '.$ket);
        $this->versi++;
        $this->dispatch('toast', pesan: 'Perubahan dibatalkan');
    }

    /**
     * Ubah satu isian dari tabel: hanya kolom yang ada di `$aturan`, divalidasi dulu.
     * Gagal → toast galat dan isian kembali ke nilai tersimpan (tidak ada yang berubah).
     */
    private function ubahIsian(Model $model, string $kolom, mixed $nilai, array $aturan, string $nama): void
    {
        abort_unless(array_key_exists($kolom, $aturan), 422);
        $nilai = is_string($nilai) ? trim($nilai) : $nilai;
        $nilai = $nilai === '' ? null : $nilai;
        $label = self::LABEL[$kolom] ?? $kolom;

        $v = Validator::make([$kolom => $nilai], [$kolom => $aturan[$kolom]], [
            'required' => ucfirst($label).' tidak boleh kosong.',
            'unique' => ucfirst($label).' sudah dipakai.',
            'email' => 'Format email belum benar.',
            'max' => ucfirst($label).' terlalu panjang (maks. :max huruf).',
        ]);
        if ($v->fails()) {
            $this->versi++;
            $this->dispatch('toast', pesan: $v->errors()->first(), gagal: true);

            return;
        }
        $lama = $model->{$kolom};
        $tampil = fn ($x) => $x instanceof \BackedEnum ? $x->value : (string) ($x ?? '—');
        $this->ubah($model, $kolom, $nilai, in_array($kolom, ['nama', 'name', 'satuan', 'email', 'no_hp', 'ikon', 'warna'])
            ? "{$nama}: {$label} {$tampil($lama)} → {$tampil($nilai)}"
            : "{$nama}: {$label} diubah");
    }

    private static function ikonAda(): \Closure
    {
        return function ($attr, $nilai, $gagal) {
            try {
                svg('lucide-'.$nilai);
            } catch (\Throwable) {
                $gagal('Ikon tidak ditemukan di lucide.dev.');
            }
        };
    }

    // ── Perlengkapan
    public function simpanHarga(int $id, $nilai): void
    {
        $j = JenisPerlengkapan::findOrFail($id);
        $nilai = max(0, (int) preg_replace('/\D/', '', (string) $nilai));
        $this->ubah($j, 'harga_satuan', $nilai, "{$j->namaPendek()}: Rp ".number_format($j->harga_satuan, 0, ',', '.').' → Rp '.number_format($nilai, 0, ',', '.'));
    }

    public function toggleJenis(int $id): void
    {
        $j = JenisPerlengkapan::findOrFail($id);
        $this->ubah($j, 'aktif', ! $j->aktif, $j->namaPendek().($j->aktif ? ' dimatikan dari formulir' : ' diaktifkan di formulir'));
    }

    public function ubahJenis(int $id, string $kolom, $nilai): void
    {
        $j = JenisPerlengkapan::findOrFail($id);
        $this->ubahIsian($j, $kolom, $nilai, [
            'nama' => 'required|string|max:100',
            'deskripsi' => 'nullable|string|max:150',
            'satuan' => 'required|string|max:20',
            'ikon' => ['required', 'string', 'max:50', self::ikonAda()],
            'warna' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], $j->namaPendek());
    }

    public function salinSsh(): void
    {
        $this->validate(['persen' => 'required|integer|min:-50|max:100']);
        $tahun = (int) Pengaturan::ambil('tahun_ssh', now()->year);
        abort_unless($tahun < now()->year + 1, 403);
        JenisPerlengkapan::all()->each(fn ($j) => $j->update(['harga_satuan' => (int) round($j->harga_satuan * (1 + $this->persen / 100), -3)]));
        Pengaturan::simpan('tahun_ssh', $tahun + 1);
        Pengaturan::simpan('ssh_persen', $this->persen);
        LogPengaturan::catat("SSH {$tahun} disalin ke SSH ".($tahun + 1)." ({$this->persen}%)");
        $this->dispatch('toast', pesan: 'Harga SSH '.($tahun + 1).' dibuat');
    }

    // ── Peta
    public function ubahPeta(string $penyedia): void
    {
        abort_unless(array_key_exists($penyedia, PetaConfig::PENYEDIA), 422);
        if ($penyedia === 'google' && ! PetaConfig::kunci()) {
            $this->dispatch('toast', pesan: 'Isi kunci API Google Maps dulu.', gagal: true);

            return;
        }
        if ($penyedia === PetaConfig::pilihan()) {
            return;
        }
        PetaConfig::simpan($penyedia);
        LogPengaturan::catat('Peta: dipakai '.PetaConfig::PENYEDIA[$penyedia]);
        $this->dispatch('toast', pesan: 'Peta memakai '.PetaConfig::PENYEDIA[$penyedia].'. Muat ulang halaman untuk melihatnya.');
    }

    public function updatedPetaKunci(): void
    {
        $kunci = trim($this->petaKunci);
        $this->petaKunci = '';
        if ($kunci !== '' && ! preg_match('/^[A-Za-z0-9_\-]{20,80}$/', $kunci)) {
            $this->dispatch('toast', pesan: 'Format kunci API belum benar.', gagal: true);

            return;
        }
        PetaConfig::simpan(PetaConfig::pilihan(), $kunci);
        LogPengaturan::catat($kunci === '' ? 'Peta: kunci Google Maps dihapus' : 'Peta: kunci Google Maps diperbarui');
        $this->dispatch('toast', pesan: $kunci === '' ? 'Kunci dihapus; peta memakai OpenStreetMap' : 'Kunci Google Maps tersimpan');
    }

    // ── Pengguna & instansi
    public function ubahPeran(int $id, string $role): void
    {
        $u = User::findOrFail($id);
        abort_if($u->id === auth()->id(), 422);
        $pilihan = User::pilihanPeran();
        abort_unless(isset($pilihan[$role]), 422);
        // dishub_lapangan = Dishub petugas lapangan (hanya melihat tugas pemeliharaannya)
        $lapangan = $role === 'dishub_lapangan';
        $baru = Role::from($lapangan ? Role::Dishub->value : $role);
        $keterangan = "Peran {$u->name}: {$pilihan[$u->peranPengaturan()]} → {$pilihan[$role]}";
        if ($u->role === $baru) {
            $this->ubah($u, 'petugas_lapangan', $lapangan, $keterangan);
        } else {
            $u->petugas_lapangan = $lapangan;
            $this->ubah($u, 'role', $baru, $keterangan);
        }
    }

    public function ubahPengguna(int $id, string $kolom, $nilai): void
    {
        $u = User::findOrFail($id);
        $this->ubahIsian($u, $kolom, $nilai, [
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($u->id)],
            'no_hp' => 'nullable|string|max:20',
            'instansi_id' => 'nullable|exists:instansi,id',
        ], "Pengguna {$u->name}");
    }

    public function togglePengguna(int $id): void
    {
        $u = User::findOrFail($id);
        abort_if($u->id === auth()->id(), 422);
        $this->ubah($u, 'aktif', ! $u->aktif, "Akun {$u->name} ".($u->aktif ? 'dinonaktifkan' : 'diaktifkan'));
    }

    public function ubahInstansi(int $id, string $kolom, $nilai): void
    {
        $i = Instansi::findOrFail($id);
        $this->ubahIsian($i, $kolom, $nilai, [
            'nama' => 'required|string|max:150',
            'jenis' => 'required|in:'.implode(',', array_keys(Instansi::JENIS)),
            'kecamatan_id' => 'nullable|exists:kecamatan,id',
        ], "Instansi {$i->nama}");
    }

    // ── Wilayah & jalan
    public function tambahKecamatan(): void
    {
        $this->validate(['kecamatanBaru' => 'required|string|max:100']);
        Kecamatan::create(['nama' => $this->kecamatanBaru]);
        LogPengaturan::catat("Kecamatan {$this->kecamatanBaru} ditambahkan");
        $this->reset('kecamatanBaru');
    }

    public function ubahKecamatan(int $id, $nilai): void
    {
        $k = Kecamatan::findOrFail($id);
        $this->ubahIsian($k, 'nama', $nilai, ['nama' => 'required|string|max:100'], "Kecamatan {$k->nama}");
    }

    public function ubahDesa(int $id, $nilai): void
    {
        $d = Desa::findOrFail($id);
        $this->ubahIsian($d, 'nama', $nilai, ['nama' => 'required|string|max:100'], "Desa {$d->nama}");
    }

    public function tambahDesa(int $kecamatanId): void
    {
        $nama = trim($this->desaBaru[$kecamatanId] ?? '');
        Kecamatan::findOrFail($kecamatanId);
        if ($nama === '' || mb_strlen($nama) > 100) {
            return;
        }
        Desa::create(['kecamatan_id' => $kecamatanId, 'nama' => $nama]);
        LogPengaturan::catat("Desa {$nama} ditambahkan");
        unset($this->desaBaru[$kecamatanId]);
    }

    public function hapusDesa(int $id): void
    {
        $d = Desa::findOrFail($id);
        abort_if(\App\Models\Usulan::where('desa_id', $id)->exists(), 422, 'Desa sudah dipakai usulan.');
        $d->delete();
        LogPengaturan::catat("Desa {$d->nama} dihapus");
    }

    public function ubahRuas(int $id, string $kolom, $nilai): void
    {
        $r = RuasJalan::findOrFail($id);
        $this->ubahIsian($r, $kolom, $nilai, [
            'nama' => 'required|string|max:150',
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUS_RUAS)),
            'kewenangan' => 'nullable|in:'.implode(',', array_keys(self::KEWENANGAN_RUAS)),
        ], "Ruas {$r->nama}");
    }

    // ── Dialog tambah
    public function buka(string $dialog): void
    {
        $this->resetValidation();
        $this->form = match ($dialog) {
            'pengguna' => ['name' => '', 'email' => '', 'no_hp' => '', 'role' => 'pengusul', 'instansi_id' => ''],
            'instansi' => ['nama' => '', 'jenis' => 'desa', 'kecamatan_id' => ''],
            'jenis' => ['nama' => '', 'deskripsi' => '', 'ikon' => 'triangle-alert', 'satuan' => 'unit', 'harga_satuan' => '', 'warna' => '#4556A9'],
            'ruas' => ['nama' => '', 'status' => '', 'kewenangan' => 'kabupaten'],
        };
        $this->dialog = $dialog;
    }

    public function simpanDialog(): void
    {
        $aturan = match ($this->dialog) {
            'pengguna' => ['form.name' => 'required|max:100', 'form.email' => 'required|email|unique:users,email', 'form.no_hp' => 'nullable|max:20', 'form.role' => 'required|in:'.implode(',', array_keys(User::pilihanPeran())), 'form.instansi_id' => 'nullable|exists:instansi,id'],
            'instansi' => ['form.nama' => 'required|max:150', 'form.jenis' => 'required|in:'.implode(',', array_keys(Instansi::JENIS)), 'form.kecamatan_id' => 'nullable|exists:kecamatan,id'],
            'jenis' => ['form.nama' => 'required|max:100', 'form.deskripsi' => 'nullable|max:150', 'form.ikon' => ['required', 'max:50', self::ikonAda()], 'form.satuan' => 'required|max:20', 'form.harga_satuan' => 'required|integer|min:0', 'form.warna' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']],
            'ruas' => ['form.nama' => 'required|max:150', 'form.status' => 'nullable|in:'.implode(',', array_keys(self::STATUS_RUAS)), 'form.kewenangan' => 'nullable|in:'.implode(',', array_keys(self::KEWENANGAN_RUAS))],
        };
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->validate($aturan)['form']);

        match ($this->dialog) {
            'pengguna' => User::create(['role' => $data['role'] === 'dishub_lapangan' ? Role::Dishub : $data['role'], 'petugas_lapangan' => $data['role'] === 'dishub_lapangan'] + $data + ['email_verified_at' => now()]),
            'instansi' => Instansi::create($data),
            'jenis' => JenisPerlengkapan::create($data + ['urutan' => JenisPerlengkapan::max('urutan') + 1]),
            'ruas' => RuasJalan::create($data),
        };
        LogPengaturan::catat(ucfirst($this->dialog === 'ruas' ? 'ruas jalan' : $this->dialog).' '.($data['name'] ?? $data['nama']).' ditambahkan');
        $this->dispatch('toast', pesan: 'Tersimpan');
        $this->reset('dialog', 'form');
    }

    public function updatedTab(): void
    {
        $this->reset('cari');
    }

    public function render()
    {
        return $this->view([
            'tahunSsh' => (int) Pengaturan::ambil('tahun_ssh', now()->year),
            'semuaKecamatan' => Kecamatan::with('desa')->orderBy('nama')->get(),
            'semuaInstansi' => Instansi::orderBy('nama')->get(),
            'ruas' => RuasJalan::orderBy('nama')->get(),
        ])->title('Pengaturan · SIPERJAL');
    }
};
?>

@php
    $tabs = ['pengguna' => 'Pengguna', 'instansi' => 'Instansi', 'perlengkapan' => 'Perlengkapan', 'wilayah' => 'Wilayah & Jalan', 'peta' => 'Peta'];
    $terakhir = $this->riwayat->first()?->created_at;
    $sel = 'rounded-sm bg-bg px-2.5 py-2 font-caption text-sm outline outline-1 -outline-offset-1 outline-line focus:outline-2 focus:outline-accent';
    $th = 'font-caption text-xs font-semibold uppercase tracking-[0.3px] text-fg-muted';
    // isian tabel yang bisa langsung diubah (tersimpan saat keluar dari kolom / Enter)
    $isi = 'rounded-sm bg-transparent px-2 py-1.5 outline-none hover:bg-surface-muted focus:bg-bg focus:outline-2 focus:outline-accent';
    $toggle = fn ($on) => '<span class="flex h-6 w-10 items-center rounded-full p-[3px] transition '.($on ? 'justify-end bg-accent' : 'bg-[#D4D8E4]').'"><span class="size-[18px] rounded-full bg-bg shadow-sm"></span></span>';
@endphp

<div class="min-h-[calc(100dvh-4rem)] bg-bg">
    {{-- ── Kepala + tab ── --}}
    <div class="border-b border-line">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-7 px-4 pt-10 sm:px-8 lg:px-20 lg:pt-12">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div class="flex flex-col gap-2">
                    <h1 class="judul text-5xl lg:text-[64px] lg:leading-[64px]">Pengaturan</h1>
                    <span class="text-[15px] text-fg-muted">Data dasar yang dipakai semua role. Perubahan tersimpan otomatis.</span>
                </div>
                <span class="flex w-fit items-center gap-2 rounded-sm bg-success-bg px-3 py-2 font-caption text-[13px] font-semibold text-success">
                    <span wire:loading.remove>@svg('lucide-cloud-check', 'size-4')</span><span wire:loading class="size-4 animate-spin rounded-full border-2 border-success border-t-transparent"></span>
                    Tersimpan otomatis{{ $terakhir ? ' · '.$terakhir->format('H.i') : '' }}
                </span>
            </div>
            <div class="flex gap-8 overflow-x-auto font-caption text-[15px]">
                @foreach ($tabs as $k => $label)
                    <button wire:click="$set('tab', '{{ $k }}')" @class(['flex shrink-0 items-center gap-2 border-b-[3px] pb-3.5', 'border-accent font-semibold' => $tab === $k, 'border-transparent font-medium text-fg-muted hover:text-fg' => $tab !== $k])>
                        {{ $label }}
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-accent text-fg-inverse' => $tab === $k, 'bg-surface-muted text-fg-muted' => $tab !== $k])>{{ $this->jumlahTab[$k] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mx-auto flex max-w-[1440px] flex-col gap-12 px-4 pb-20 pt-10 sm:px-8 lg:flex-row lg:px-20">
        <div class="flex min-w-0 flex-1 flex-col gap-5">
            {{-- Toolbar --}}
            @if (! in_array($tab, ['wilayah', 'peta']))
                <div class="flex items-center gap-3">
                    <label class="flex w-full max-w-[320px] items-center gap-2 rounded-sm px-3 py-2.5 outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent">
                        @svg('lucide-search', 'size-4 shrink-0 text-fg-muted')
                        <input wire:model.live.debounce.300ms="cari" class="w-full bg-transparent font-caption text-sm outline-none placeholder:text-fg-muted" placeholder="Cari {{ ['pengguna' => 'nama atau email', 'instansi' => 'instansi', 'perlengkapan' => 'jenis perlengkapan'][$tab] }}">
                    </label>
                    <span class="flex-1"></span>
                    <button wire:click="buka('{{ ['pengguna' => 'pengguna', 'instansi' => 'instansi', 'perlengkapan' => 'jenis'][$tab] }}')" class="flex items-center gap-2 rounded-sm bg-accent px-5 py-3 text-[15px] font-semibold text-fg-inverse shadow-sm hover:brightness-110">
                        {{ ['pengguna' => 'Tambah Pengguna', 'instansi' => 'Tambah Instansi', 'perlengkapan' => 'Tambah Jenis'][$tab] }} @svg('lucide-plus', 'size-[18px]')
                    </button>
                </div>
            @endif

            {{-- ── Perlengkapan ── --}}
            @if ($tab === 'perlengkapan')
                <div class="overflow-x-auto rounded-md outline outline-1 -outline-offset-1 outline-line">
                    <div class="min-w-[760px]">
                        <div class="flex gap-5 border-b border-line bg-surface-muted px-5 py-3 {{ $th }}">
                            <span class="flex-1">Jenis</span><span class="w-[110px]">Satuan</span><span class="w-[200px]">Harga satuan {{ $tahunSsh }}</span><span class="w-[90px]">Dipakai</span><span class="w-[60px]">Aktif</span>
                        </div>
                        @foreach ($this->jenis as $j)
                            <div class="group flex items-center gap-5 border-b border-line bg-bg px-5 py-3 last:border-b-0 focus-within:bg-accent-soft" wire:key="j-{{ $j->id }}-{{ $versi }}">
                                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                    {{-- Ikon & warna pin: ketuk untuk mengubah --}}
                                    <div class="shrink-0" x-data="{ buka: false }">
                                        <button type="button" @click="buka = true" @class(['relative grid size-10 place-items-center rounded-sm hover:outline hover:outline-1 hover:outline-accent group-focus-within:bg-bg', 'bg-accent-soft text-accent' => $j->aktif, 'bg-surface-muted text-fg-muted' => ! $j->aktif]) title="Ubah ikon & warna" aria-label="Ubah ikon & warna {{ $j->nama }}">
                                            @svg('lucide-'.$j->ikon, 'size-5')
                                            <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-bg" style="background: {{ $j->warna }}"></span>
                                        </button>
                                        @teleport('body')
                                            <div x-show="buka" x-cloak class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" @click.self="buka = false" @keydown.escape.window="buka = false">
                                                <div class="flex w-full flex-col gap-4 rounded-t-xl bg-bg p-6 sm:max-w-sm sm:rounded-md">
                                                    <h3 class="judul text-2xl">Ikon & warna · {{ $j->namaPendek() }}</h3>
                                                    <label class="flex flex-col gap-1.5">
                                                        <span class="text-sm font-semibold">Ikon (nama dari lucide.dev)</span>
                                                        <input value="{{ $j->ikon }}" @change="$wire.ubahJenis({{ $j->id }}, 'ikon', $event.target.value)" @keydown.enter="$el.blur()" class="input">
                                                    </label>
                                                    <label class="flex flex-col gap-1.5">
                                                        <span class="text-sm font-semibold">Warna pin</span>
                                                        <input type="color" value="{{ $j->warna }}" @change="$wire.ubahJenis({{ $j->id }}, 'warna', $event.target.value)" class="input h-11 p-1">
                                                    </label>
                                                    <div class="flex justify-end pt-1"><x-tombol @click="buka = false">Selesai</x-tombol></div>
                                                </div>
                                            </div>
                                        @endteleport
                                    </div>
                                    <div class="flex min-w-0 flex-1 flex-col">
                                        <input value="{{ $j->nama }}" @change="$wire.ubahJenis({{ $j->id }}, 'nama', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Nama" @class([$isi, 'w-full text-[15px] font-semibold', 'text-fg-muted' => ! $j->aktif])>
                                        <input value="{{ $j->deskripsi }}" @change="$wire.ubahJenis({{ $j->id }}, 'deskripsi', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Keterangan singkat" placeholder="{{ $j->aktif ? 'Tambah keterangan singkat' : 'Tidak muncul di formulir' }}" class="{{ $isi }} w-full py-1 font-caption text-[13px] text-fg-muted placeholder:text-fg-muted/70">
                                    </div>
                                </div>
                                <input value="{{ $j->satuan }}" @change="$wire.ubahJenis({{ $j->id }}, 'satuan', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Satuan" class="{{ $isi }} w-[110px] font-caption text-sm">
                                <label class="flex w-[200px] items-center gap-1.5 rounded-sm bg-bg px-3 py-[9px] font-caption text-sm outline outline-1 -outline-offset-1 outline-line focus-within:outline-2 focus-within:outline-accent"
                                       x-data="{ v: '{{ number_format($j->harga_satuan, 0, ',', '.') }}' }">
                                    <span class="text-fg-muted">Rp</span>
                                    <input x-model="v" inputmode="numeric" class="w-full bg-transparent font-semibold tabular-nums outline-none"
                                           @input="v = v.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')"
                                           @keydown.enter="$el.blur()"
                                           @change="$wire.simpanHarga({{ $j->id }}, v)">
                                </label>
                                <span class="w-[90px] font-caption text-sm text-fg-muted">{{ $j->items_count ? $j->items_count.' item' : '—' }}</span>
                                <button wire:click="toggleJenis({{ $j->id }})" class="w-[60px]" aria-label="Aktif">{!! $toggle($j->aktif) !!}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2 font-caption text-[13px] text-fg-muted">@svg('lucide-info', 'size-4') Ketuk nama, keterangan, satuan, atau ikon untuk mengubahnya. Jenis yang dimatikan tidak muncul di formulir Ajukan, tetapi usulan lama tetap tersimpan.</div>
            @endif

            {{-- ── Pengguna ── --}}
            @if ($tab === 'pengguna')
                <div class="overflow-x-auto rounded-md outline outline-1 -outline-offset-1 outline-line">
                    <div class="min-w-[760px]">
                        <div class="flex gap-5 border-b border-line bg-surface-muted px-5 py-3 {{ $th }}">
                            <span class="flex-1">Nama</span><span class="w-[220px]">Peran</span><span class="w-[180px]">Instansi</span><span class="w-[130px]">No. HP</span><span class="w-[60px]">Aktif</span>
                        </div>
                        @foreach ($this->pengguna as $p)
                            @php $saya = $p->id === auth()->id(); @endphp
                            <div class="flex items-center gap-5 border-b border-line bg-bg px-5 py-3 last:border-b-0 focus-within:bg-accent-soft" wire:key="u-{{ $p->id }}-{{ $versi }}">
                                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent-deep font-caption text-[13px] font-semibold text-fg-inverse">{{ str($p->name)->explode(' ')->filter()->take(2)->map(fn ($k) => mb_substr($k, 0, 1))->implode('') }}</span>
                                    <div class="flex min-w-0 flex-1 flex-col">
                                        <span class="flex items-center">
                                            <input value="{{ $p->name }}" @change="$wire.ubahPengguna({{ $p->id }}, 'name', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Nama" @class([$isi, 'min-w-0 flex-1 text-[15px] font-semibold', 'text-fg-muted' => ! $p->aktif])>
                                            @if ($saya) <span class="shrink-0 font-caption text-xs text-fg-muted">(Anda)</span> @endif
                                        </span>
                                        <input type="email" value="{{ $p->email }}" @change="$wire.ubahPengguna({{ $p->id }}, 'email', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Email" class="{{ $isi }} w-full py-1 font-caption text-[13px] text-fg-muted">
                                    </div>
                                </div>
                                <select @disabled($saya) wire:change="ubahPeran({{ $p->id }}, $event.target.value)" class="w-[220px] {{ $sel }}">
                                    @foreach (User::pilihanPeran() as $nilai => $label) <option value="{{ $nilai }}" @selected($p->peranPengaturan() === $nilai)>{{ $label }}</option> @endforeach
                                </select>
                                <select wire:change="ubahPengguna({{ $p->id }}, 'instansi_id', $event.target.value)" aria-label="Instansi" class="w-[180px] {{ $sel }}">
                                    <option value="">{{ $p->isPengusul() ? 'Masyarakat' : '—' }}</option>
                                    @foreach ($semuaInstansi as $si) <option value="{{ $si->id }}" @selected($p->instansi_id === $si->id)>{{ $si->nama }}</option> @endforeach
                                </select>
                                <input type="tel" value="{{ $p->no_hp }}" @change="$wire.ubahPengguna({{ $p->id }}, 'no_hp', $event.target.value)" @keydown.enter="$el.blur()" aria-label="No. HP" placeholder="—" class="{{ $isi }} w-[130px] font-caption text-sm">
                                <button @disabled($saya) wire:click="togglePengguna({{ $p->id }})" class="w-[60px] disabled:opacity-40" aria-label="Aktif">{!! $toggle($p->aktif) !!}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2 font-caption text-[13px] text-fg-muted">@svg('lucide-info', 'size-4') Ketuk nama, email, atau no. HP untuk mengubahnya. Pengguna masuk dengan kode ke email, jadi tidak ada password yang perlu diatur. Warga bisa mendaftar sendiri.</div>
            @endif

            {{-- ── Instansi ── --}}
            @if ($tab === 'instansi')
                <div class="overflow-x-auto rounded-md outline outline-1 -outline-offset-1 outline-line">
                    <div class="min-w-[760px]">
                        <div class="flex gap-5 border-b border-line bg-surface-muted px-5 py-3 {{ $th }}">
                            <span class="flex-1">Nama instansi</span><span class="w-[170px]">Jenis</span><span class="w-[170px]">Kecamatan</span><span class="w-[70px]">Akun</span>
                        </div>
                        @forelse ($this->instansi as $i)
                            <div class="flex items-center gap-5 border-b border-line bg-bg px-5 py-3 last:border-b-0" wire:key="i-{{ $i->id }}-{{ $versi }}">
                                <input value="{{ $i->nama }}" @change="$wire.ubahInstansi({{ $i->id }}, 'nama', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Nama instansi" class="min-w-0 flex-1 rounded-sm bg-transparent px-2 py-2 text-[15px] font-semibold outline-none hover:bg-surface-muted focus:bg-bg focus:outline-2 focus:outline-accent">
                                <select wire:change="ubahInstansi({{ $i->id }}, 'jenis', $event.target.value)" class="w-[170px] {{ $sel }}">
                                    @foreach (Instansi::JENIS as $v => $l) <option value="{{ $v }}" @selected($i->jenis === $v)>{{ $l }}</option> @endforeach
                                </select>
                                <select wire:change="ubahInstansi({{ $i->id }}, 'kecamatan_id', $event.target.value)" class="w-[170px] {{ $sel }}">
                                    <option value="">—</option>
                                    @foreach ($semuaKecamatan as $k) <option value="{{ $k->id }}" @selected($i->kecamatan_id === $k->id)>{{ $k->nama }}</option> @endforeach
                                </select>
                                <span class="w-[70px] font-caption text-sm text-fg-muted">{{ $i->users_count }}</span>
                            </div>
                        @empty
                            <div class="p-10 text-center text-sm text-fg-muted">Belum ada instansi.</div>
                        @endforelse
                    </div>
                </div>
            @endif

            {{-- ── Peta ── --}}
            @if ($tab === 'peta')
                @php $kunciSamar = PetaConfig::kunciSamar(); @endphp
                <div class="flex max-w-[760px] flex-col gap-6">
                    <div class="flex flex-col gap-1.5">
                        <h2 class="judul text-[26px]">Penyedia peta</h2>
                        <span class="text-[15px] text-fg-muted">Dipakai di semua halaman yang menampilkan peta dan untuk mengisi kecamatan & desa dari titik. Perubahan berlaku setelah halaman dimuat ulang.</span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (PetaConfig::PENYEDIA as $k => $nama)
                            @php $pakai = PetaConfig::pilihan() === $k; @endphp
                            <button type="button" wire:click="ubahPeta('{{ $k }}')" aria-pressed="{{ $pakai ? 'true' : 'false' }}" @class(['flex flex-col items-start gap-2 rounded-md p-5 text-left outline -outline-offset-1', 'bg-accent-soft outline-2 outline-accent' => $pakai, 'bg-bg outline-1 outline-line hover:bg-surface-muted' => ! $pakai])>
                                <span class="flex w-full items-center justify-between gap-2">
                                    <span class="flex items-center gap-2 text-[17px] font-semibold">@svg($k === 'osm' ? 'lucide-map' : 'lucide-map-pinned', 'size-5 text-accent') {{ $nama }}</span>
                                    @if ($pakai) <span class="rounded-full bg-accent px-2 py-0.5 font-caption text-xs font-semibold text-fg-inverse">Dipakai</span> @endif
                                </span>
                                <span class="text-sm text-fg-muted">
                                    @if ($k === 'osm')
                                        Leaflet + OpenStreetMap. Gratis, tanpa kunci dan tanpa kartu kredit.
                                    @else
                                        Tampilan Google. Butuh kunci API dan akun Google Cloud dengan billing aktif; gratis hanya sampai kuota bulanan Google, lewat itu ditagih.
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex flex-col gap-3 rounded-md p-5 outline outline-1 -outline-offset-1 outline-line">
                        <label class="flex flex-col gap-1.5" for="kunci-google">
                            <span class="text-[15px] font-semibold">Kunci API Google Maps</span>
                            <span class="text-sm text-fg-muted">{{ $kunciSamar ? 'Tersimpan: '.$kunciSamar.'. Isi kolom di bawah untuk menggantinya, kosongkan lalu keluar kolom untuk menghapus.' : 'Belum ada kunci. Tempel kunci lalu keluar dari kolom — tersimpan otomatis.' }}</span>
                        </label>
                        <input id="kunci-google" type="password" autocomplete="off" wire:model.live.blur="petaKunci" placeholder="AIza…" class="{{ $sel }}">
                        <ul class="flex list-disc flex-col gap-1 pl-5 text-sm text-fg-muted">
                            <li>Aktifkan <b>Maps JavaScript API</b> dan <b>Geocoding API</b> di Google Cloud.</li>
                            <li>Batasi kunci ke domain aplikasi ini (HTTP referrer) agar tidak disalahgunakan — kunci terlihat di sisi browser.</li>
                            <li>Pasang budget alert dan batas kuota harian agar tagihan tidak membengkak.</li>
                            <li>Jika Google gagal dimuat, peta otomatis kembali ke OpenStreetMap.</li>
                        </ul>
                    </div>
                </div>
            @endif

            {{-- ── Wilayah & jalan ── --}}
            @if ($tab === 'wilayah')
                <div class="grid gap-8 xl:grid-cols-2">
                    <div class="flex flex-col gap-4">
                        <h2 class="judul text-[26px]">Kecamatan & desa</h2>
                        <div class="flex flex-col divide-y divide-line rounded-md outline outline-1 -outline-offset-1 outline-line">
                            @foreach ($semuaKecamatan as $k)
                                <div class="px-5 py-2.5" wire:key="k-{{ $k->id }}-{{ $versi }}" x-data="{ buka: false }">
                                    <div class="flex items-center gap-2">
                                        <input value="{{ $k->nama }}" @change="$wire.ubahKecamatan({{ $k->id }}, $event.target.value)" @keydown.enter="$el.blur()" aria-label="Nama kecamatan" class="{{ $isi }} min-w-0 flex-1 text-[15px] font-semibold">
                                        <button type="button" @click="buka = !buka" class="flex shrink-0 items-center gap-2 rounded-sm px-2 py-1.5 font-caption text-[13px] text-fg-muted hover:bg-surface-muted" :aria-expanded="buka">
                                            {{ $k->desa->count() }} desa <span class="transition" :class="buka && 'rotate-180'">@svg('lucide-chevron-down', 'size-4')</span>
                                        </button>
                                    </div>
                                    <div x-show="buka" x-cloak class="mt-2 flex flex-wrap gap-1.5 pb-1">
                                        @foreach ($k->desa as $d)
                                            <span class="flex items-center gap-0.5 rounded-full bg-surface-muted py-0.5 pl-1 pr-1.5 font-caption text-[13px] focus-within:bg-bg focus-within:outline focus-within:outline-2 focus-within:outline-accent" wire:key="d-{{ $d->id }}">
                                                <input value="{{ $d->nama }}" @change="$wire.ubahDesa({{ $d->id }}, $event.target.value)" @keydown.enter="$el.blur()" @keydown.escape="$el.value = @js($d->nama); $el.blur()" aria-label="Nama desa"
                                                       x-init="$el.style.width = ($el.value.length + 2) + 'ch'" @input="$el.style.width = ($el.value.length + 2) + 'ch'"
                                                       class="min-w-[4ch] rounded-full bg-transparent px-2 py-0.5 outline-none" title="Ketuk untuk mengubah nama">
                                                <button wire:click="hapusDesa({{ $d->id }})" wire:confirm="Hapus desa {{ $d->nama }}?" class="grid size-5 place-items-center rounded-full text-fg-muted hover:bg-line" aria-label="Hapus desa {{ $d->nama }}">@svg('lucide-x', 'size-3')</button>
                                            </span>
                                        @endforeach
                                        <form wire:submit="tambahDesa({{ $k->id }})" class="flex">
                                            <input wire:model="desaBaru.{{ $k->id }}" class="w-36 rounded-full bg-bg px-3 py-1 font-caption text-[13px] outline outline-1 -outline-offset-1 outline-line focus:outline-accent" placeholder="+ Tambah desa">
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                            <form wire:submit="tambahKecamatan" class="flex gap-2 px-5 py-3.5">
                                <input wire:model="kecamatanBaru" class="flex-1 {{ $sel }}" placeholder="Nama kecamatan baru">
                                <button class="rounded-sm px-3 font-caption text-sm font-semibold text-accent outline outline-1 -outline-offset-1 outline-accent">Tambah</button>
                            </form>
                        </div>
                    </div>
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <h2 class="judul text-[26px]">Ruas jalan</h2>
                            <button wire:click="buka('ruas')" class="flex items-center gap-1.5 font-caption text-sm font-semibold text-accent">@svg('lucide-plus', 'size-4') Tambah ruas</button>
                        </div>
                        <div class="flex flex-col divide-y divide-line rounded-md outline outline-1 -outline-offset-1 outline-line">
                            @forelse ($ruas as $r)
                                <div class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="r-{{ $r->id }}-{{ $versi }}">
                                    <input value="{{ $r->nama }}" @change="$wire.ubahRuas({{ $r->id }}, 'nama', $event.target.value)" @keydown.enter="$el.blur()" aria-label="Nama ruas" class="min-w-40 flex-1 rounded-sm bg-transparent px-2 py-1.5 text-[15px] font-semibold outline-none hover:bg-surface-muted focus:outline-2 focus:outline-accent">
                                    <select wire:change="ubahRuas({{ $r->id }}, 'status', $event.target.value)" class="{{ $sel }}">
                                        <option value="">Status…</option>@foreach ($this::STATUS_RUAS as $v => $l)<option value="{{ $v }}" @selected($r->status === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                    <select wire:change="ubahRuas({{ $r->id }}, 'kewenangan', $event.target.value)" class="{{ $sel }}">
                                        @foreach ($this::KEWENANGAN_RUAS as $v => $l)<option value="{{ $v }}" @selected($r->kewenangan === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                </div>
                            @empty
                                <div class="p-8 text-center text-sm text-fg-muted">Belum ada ruas jalan.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- ── Samping: SSH + riwayat ── --}}
        <aside class="flex w-full flex-col gap-8 lg:w-[360px] lg:shrink-0">
            <div class="flex flex-col gap-4 rounded-md bg-surface-soft p-6">
                <span class="font-caption text-[13px] font-semibold text-fg-muted">Standar Satuan Harga</span>
                <span class="judul text-[40px] leading-10">SSH {{ $tahunSsh }}</span>
                <p class="text-sm leading-[21px] text-fg-muted">Harga ini dipakai untuk estimasi biaya di halaman Bappeda dan Laporan. Pengusul tidak melihat angka ini.</p>
                <div class="flex items-center gap-2">
                    <label class="flex items-center gap-1 rounded-sm bg-bg px-3 py-2.5 font-caption text-sm outline outline-1 -outline-offset-1 outline-line">
                        <input wire:model="persen" type="number" class="w-10 bg-transparent text-right font-semibold outline-none"> <span class="text-fg-muted">%</span>
                    </label>
                    @if ($tahunSsh < now()->year + 1)
                        <button @click="confirm(`Semua harga satuan diubah ${$wire.persen}% untuk SSH {{ $tahunSsh + 1 }}. Item yang sudah disetujui tidak berubah. Lanjutkan?`) && $wire.salinSsh()" class="flex flex-1 items-center justify-center gap-2 rounded-sm px-3.5 py-2.5 text-sm font-semibold outline outline-1 -outline-offset-1 outline-fg hover:bg-bg">
                            @svg('lucide-copy', 'size-4') Salin ke SSH {{ $tahunSsh + 1 }}
                        </button>
                    @else
                        <span class="flex flex-1 items-center justify-center gap-2 rounded-sm px-3.5 py-2.5 text-sm font-semibold text-fg-muted outline outline-1 -outline-offset-1 outline-line">@svg('lucide-check', 'size-4') SSH {{ $tahunSsh }} sudah disiapkan</span>
                    @endif
                </div>
                @error('persen') <span class="text-xs text-danger">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-4">
                <h2 class="judul text-[22px]">Riwayat perubahan</h2>
                <ol class="flex flex-col gap-[18px]">
                    @forelse ($this->riwayat as $r)
                        <li class="flex gap-3">
                            <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-accent' => $loop->first, 'bg-[#C4C9D8]' => ! $loop->first])></span>
                            <div class="flex flex-col gap-[3px]">
                                <span class="text-sm leading-5">{{ $r->keterangan }}</span>
                                <span class="font-caption text-xs text-fg-muted">{{ $r->created_at->diffForHumans() }} · {{ $r->user?->name ?? 'Sistem' }}</span>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-fg-muted">Belum ada perubahan.</li>
                    @endforelse
                </ol>
            </div>
        </aside>
    </div>

    {{-- ── Dialog tambah ── --}}
    @if ($dialog)
        <div class="fixed inset-0 z-[3000] flex items-end justify-center bg-fg/50 sm:items-center" wire:click.self="$set('dialog', null)" x-data @keydown.escape.window="$wire.set('dialog', null)">
            <form wire:submit="simpanDialog" class="flex w-full flex-col gap-4 rounded-t-xl bg-bg p-6 sm:max-w-md sm:rounded-md">
                <h3 class="judul text-2xl">{{ ['pengguna' => 'Tambah pengguna', 'instansi' => 'Tambah instansi', 'jenis' => 'Tambah jenis perlengkapan', 'ruas' => 'Tambah ruas jalan'][$dialog] }}</h3>
                @php
                    $isian = match ($dialog) {
                        'pengguna' => [['name', 'Nama', 'text'], ['email', 'Email', 'email'], ['no_hp', 'No. HP', 'tel'], ['role', 'Peran', User::pilihanPeran()], ['instansi_id', 'Instansi (khusus pengusul instansi)', ['' => '— Masyarakat / tidak ada —'] + $semuaInstansi->pluck('nama', 'id')->all()]],
                        'instansi' => [['nama', 'Nama instansi', 'text'], ['jenis', 'Jenis', Instansi::JENIS], ['kecamatan_id', 'Kecamatan', ['' => '—'] + $semuaKecamatan->pluck('nama', 'id')->all()]],
                        'jenis' => [['nama', 'Nama', 'text'], ['deskripsi', 'Keterangan singkat', 'text'], ['satuan', 'Satuan', 'text'], ['harga_satuan', 'Harga satuan (Rp)', 'number'], ['ikon', 'Ikon (nama dari lucide.dev)', 'text'], ['warna', 'Warna pin', 'color']],
                        'ruas' => [['nama', 'Nama ruas', 'text'], ['status', 'Status', ['' => '—'] + $this::STATUS_RUAS], ['kewenangan', 'Kewenangan', $this::KEWENANGAN_RUAS]],
                    };
                @endphp
                @foreach ($isian as [$k, $label, $tipe])
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">{{ $label }}</span>
                        @if (is_array($tipe))
                            <select wire:model="form.{{ $k }}" class="input">@foreach ($tipe as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        @else
                            <input wire:model="form.{{ $k }}" type="{{ $tipe }}" @class(['input', 'h-11 p-1' => $tipe === 'color'])>
                        @endif
                        @error("form.$k") <span class="text-xs text-danger">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="flex justify-end gap-2 pt-2">
                    <x-tombol variant="teks" wire:click="$set('dialog', null)">Batal</x-tombol>
                    <x-tombol type="submit">Simpan</x-tombol>
                </div>
            </form>
        </div>
    @endif
</div>

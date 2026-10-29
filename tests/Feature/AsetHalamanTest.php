<?php

namespace Tests\Feature;

use App\Enums\StatusLaporan;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LaporanKerusakan;
use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AsetHalamanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('public'); // tes tidak boleh meninggalkan berkas di storage sungguhan
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function aset(array $attr = []): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create($attr + [
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id'), 'jumlah' => 1, 'lat' => -3.45, 'lng' => 116.01,
            'kecamatan_id' => Kecamatan::where('nama', 'Batulicin')->value('id'), 'terpasang_at' => '2026-08-14', 'alamat' => 'Jl. Raya Batulicin',
        ]);
    }

    public function test_daftar_menampilkan_kode_dan_menyaring_yang_belum_berkode(): void
    {
        $ber = $this->aset();
        $belum = $this->aset(['kecamatan_id' => null, 'alamat' => 'Jl. Tanpa Kecamatan']);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        Livewire::test('pages::terpasang.index')
            ->assertSee($ber->kode)->assertSee('Belum berkode')
            ->call('saringKondisi', 'belum_berkode')
            ->assertSee('Jl. Tanpa Kecamatan')->assertDontSee($ber->kode)
            ->call('saringKondisi', 'belum_berkode')->assertSee($ber->kode)
            ->set('cari', strtolower(substr($ber->kode, 0, 7)))->assertSee($ber->kode)->assertDontSee('Jl. Tanpa Kecamatan');
        $this->assertSame($belum->id, PerlengkapanTerpasang::belumBerkode()->value('id'));
    }

    public function test_detail_menampilkan_kode_riwayat_dan_qr(): void
    {
        $a = $this->aset();
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => now()->subDays(2), 'kegiatan' => 'pembersihan', 'catatan' => 'Dibersihkan dari lumut',
            'petugas_id' => $this->user('petugas@usuljalan.test')->id]);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        Livewire::test('pages::terpasang.index')
            ->call('pilihAset', $a->id)
            ->assertSee($a->kode)->assertSee('Dibersihkan dari lumut')->assertSee('Lihat QR')->assertSee('Catat pemeliharaan')
            ->assertSee('catatan petugas')->assertSee('Sebelum SIPERJAL')->assertSee(preg_replace('#^https?://#', '', route('aset', $a->kode)));

        $this->actingAs($this->user('admin@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('pilihAset', $a->id)->assertSee($a->kode)->assertDontSee('Catat pemeliharaan'); // admin hanya melihat
    }

    public function test_mode_pilih_hanya_memilih_unit_berkode_dan_pilih_semua(): void
    {
        $a = $this->aset();
        $b = $this->aset();
        $this->aset(['kecamatan_id' => null]);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $c = Livewire::test('pages::terpasang.index')->call('mulaiTandai')->assertSet('tandai', true)
            ->call('pilihAset', $a->id)->assertSet('terpilih', [$a->id])
            ->call('pilihAset', $a->id)->assertSet('terpilih', [])
            ->call('pilihSemua');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $c->get('terpilih')); // yang belum berkode tidak ikut
        $c->assertSee('2 label')->assertSee('Unduh PDF label')->call('selesaiTandai')->assertSet('tandai', false)->assertSet('terpilih', []);
    }

    public function test_tab_riwayat_dikelompokkan_per_tanggal_dan_menyaring_sumber(): void
    {
        $a = $this->aset();
        $petugas = $this->user('petugas@usuljalan.test');
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => today(), 'kegiatan' => 'rutin', 'petugas_id' => $petugas->id]);
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => today()->subDays(3), 'kegiatan' => 'perbaikan', 'petugas_id' => $petugas->id, 'laporan_kerusakan_id' => null]);
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => today()->subMonths(2), 'kegiatan' => 'pembersihan']);

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('gantiTab', 'riwayat')
            ->assertSee('Hari ini')->assertSee('2 catatan')->assertSee('Pemeliharaan rutin')->assertDontSee('Pembersihan')
            ->set('rentang', 'pilih')->set('dari', today()->subMonths(3)->toDateString())->set('sampai', today()->toDateString())->assertSee('Pembersihan');

        // petugas lapangan: bawaan hanya catatannya sendiri
        $this->actingAs($petugas);
        Livewire::test('pages::terpasang.index', ['tab' => 'riwayat'])->assertSet('petugasFilter', $petugas->id)->assertSee('Pemeliharaan rutin');
    }

    public function test_laporan_selesai_menjadi_catatan_pemeliharaan_dan_batalnya_menghapus(): void
    {
        Notification::fake();
        Storage::fake('public');
        $a = $this->aset();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $l = LaporanKerusakan::buat(['aset' => $a], $this->user('dishub@usuljalan.test'), 'roboh', 'Roboh kena truk', [['file' => UploadedFile::fake()->image('r.jpg')]]);
        $l->update(['status' => StatusLaporan::MenungguPerbaikan]);

        $l->fresh()->selesai([['file' => UploadedFile::fake()->image('hasil.jpg')]], 'Tiang diganti baru');
        $p = Pemeliharaan::where('laporan_kerusakan_id', $l->id)->firstOrFail();
        $this->assertSame([$a->id, 'penggantian', 'Tiang diganti baru', today()->toDateString()], [$p->perlengkapan_terpasang_id, $p->kegiatan, $p->catatan, $p->tanggal->toDateString()]);
        $this->assertCount(1, $p->foto);
        $this->assertFalse($p->bolehDihapus($this->user('dishub@usuljalan.test'))); // catatan dari laporan dimiliki laporannya
        $this->assertTrue($a->fresh()->punyaRiwayat());

        $l->fresh()->batalSelesai();
        $this->assertSame(0, Pemeliharaan::count());
    }

    public function test_hapus_catatan_hanya_koordinator_atau_petugas_pemilik_dalam_10_menit(): void
    {
        $a = $this->aset();
        $petugas = $this->user('petugas@usuljalan.test');
        $punyaPetugas = Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => today(), 'kegiatan' => 'rutin', 'petugas_id' => $petugas->id]);

        $this->actingAs($this->user('warga@usuljalan.test'));
        $this->assertFalse($punyaPetugas->bolehDihapus($this->user('warga@usuljalan.test')));
        $this->assertFalse($punyaPetugas->bolehDihapus($this->user('admin@usuljalan.test')));
        $this->assertTrue($punyaPetugas->bolehDihapus($petugas));

        $punyaPetugas->forceFill(['created_at' => now()->subMinutes(11)])->save();
        $this->assertFalse($punyaPetugas->fresh()->bolehDihapus($petugas));
        $this->assertTrue($punyaPetugas->fresh()->bolehDihapus($this->user('dishub@usuljalan.test')));

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('pilihAset', $a->id)->call('hapusCatatan', $punyaPetugas->id);
        $this->assertSame(0, Pemeliharaan::count());
    }

    public function test_aset_dengan_riwayat_tidak_bisa_dihapus_dan_beranda_mengelompokkan_unit_serupa(): void
    {
        $lama = $this->aset(['alamat' => 'Simpang Kersik']);
        $kembar = $this->aset(['alamat' => 'Simpang Kersik']);
        $lain = $this->aset(['lat' => -3.46, 'alamat' => 'Tempat lain']);
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $lama->id, 'tanggal' => today(), 'kegiatan' => 'rutin']);

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('hapus', $lama->id)->assertDispatched('toast', gagal: true);
        $this->assertNotNull($lama->fresh());

        $peta = Livewire::test('pages::beranda')->instance()->dataPeta();
        $this->assertCount(2, $peta['titik']); // dua unit di titik yang sama = satu pin
        $kelompok = collect($peta['titik'])->firstWhere('jumlah', 2);
        $this->assertStringContainsString('2 unit di titik ini', $kelompok['popup']);
        $this->assertStringContainsString($lama->kode, $kelompok['popup']);
        $this->assertStringContainsString($kembar->kode, $kelompok['popup']);
        $this->assertStringContainsString(route('aset', $lama->kode), $kelompok['popup']);
    }

    public function test_urut_dekat_saya_menaruh_yang_terdekat_di_atas_dengan_jarak(): void
    {
        $jauh = $this->aset(['lat' => -3.50, 'lng' => 116.10, 'alamat' => 'Tempat Jauh']);
        $dekat = $this->aset(['lat' => -3.4501, 'lng' => 116.0101, 'alamat' => 'Tempat Dekat']);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $c = Livewire::test('pages::terpasang.index')->call('urutDekat', -3.45, 116.01);
        $this->assertSame([$dekat->id, $jauh->id], $c->instance()->daftar->pluck('id')->all());
        $c->assertSee(' m')->assertSet('dekat', [-3.45, 116.01])->call('hapusDekat')->assertSet('dekat', null);
    }

    public function test_laporan_selesai_lama_diisikan_ke_riwayat_unit_tanpa_menggandakan(): void
    {
        $a = $this->aset();
        $petugas = $this->user('petugas@usuljalan.test');
        $l = LaporanKerusakan::create(['nomor' => 'LPR-2026-09001', 'status' => StatusLaporan::Selesai, 'perlengkapan_terpasang_id' => $a->id,
            'jenis_perlengkapan_id' => $a->jenis_perlengkapan_id, 'lat' => $a->lat, 'lng' => $a->lng, 'kerusakan' => 'roboh',
            'selesai_at' => '2026-10-12 09:58:54', 'catatan_selesai' => 'Tiang diganti', 'ditutup_oleh' => $petugas->id, 'user_id' => $this->user('warga@usuljalan.test')->id]);
        LaporanKerusakan::create(['nomor' => 'LPR-2026-09002', 'status' => StatusLaporan::Selesai, 'jenis_perlengkapan_id' => $a->jenis_perlengkapan_id, // titik bebas: tanpa unit
            'lat' => -3.4, 'lng' => 116.0, 'kerusakan' => 'mati', 'selesai_at' => now(), 'user_id' => $this->user('warga@usuljalan.test')->id]);
        $this->assertSame(0, Pemeliharaan::count());

        $this->assertSame(1, LaporanKerusakan::isiRiwayatDariLaporanSelesai());
        $p = Pemeliharaan::where('laporan_kerusakan_id', $l->id)->firstOrFail();
        $this->assertSame([$a->id, '2026-10-12', 'penggantian', 'Tiang diganti', $petugas->id], [$p->perlengkapan_terpasang_id, $p->tanggal->toDateString(), $p->kegiatan, $p->catatan, $p->petugas_id]);
        $this->assertSame(0, LaporanKerusakan::isiRiwayatDariLaporanSelesai()); // aman dijalankan ulang
        $this->assertSame(1, Pemeliharaan::count());
    }
}

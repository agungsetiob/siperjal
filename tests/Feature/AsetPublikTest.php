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
use Livewire\Livewire;
use Tests\TestCase;

class AsetPublikTest extends TestCase
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

    private function aset(): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create([
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->firstOrFail()->id, 'jumlah' => 1,
            'lat' => -3.45, 'lng' => 116.01, 'kecamatan_id' => Kecamatan::where('nama', 'Batulicin')->firstOrFail()->id,
            'terpasang_at' => '2026-08-14', 'harga_satuan' => 7654321,
        ]);
    }

    private function catatan(PerlengkapanTerpasang $a, ?User $petugas = null, array $attr = []): Pemeliharaan
    {
        return Pemeliharaan::create($attr + ['perlengkapan_terpasang_id' => $a->id, 'tanggal' => '2026-10-01', 'kegiatan' => 'pembersihan',
            'catatan' => 'Rambu dibersihkan.', 'petugas_id' => ($petugas ?? $this->user('petugas@usuljalan.test'))->id]);
    }

    public function test_tamu_melihat_halaman_tanpa_data_internal(): void
    {
        $a = $this->aset();
        $p = $this->catatan($a);
        $p->foto()->create(['path' => 'pemeliharaan/rahasia-foto.jpg']);

        $this->get(route('aset', $a->kode))->assertOk()
            ->assertSee($a->kode)->assertSee('Kondisi baik')->assertSee('Pembersihan')->assertSee('Rambu dibersihkan.')
            ->assertSee('Lapor rusak')->assertSee('maps')
            ->assertDontSee('7654321')->assertDontSee('7.654.321')
            ->assertDontSee($this->user('petugas@usuljalan.test')->name)
            ->assertDontSee('rahasia-foto')->assertDontSee('Catat pemeliharaan');
    }

    public function test_kode_huruf_kecil_tetap_ditemukan(): void
    {
        $a = $this->aset();
        $this->get('/aset/'.strtolower($a->kode))->assertOk()->assertSee($a->kode)->assertDontSee('KODE TIDAK DITEMUKAN');
    }

    public function test_kode_tak_dikenal_menampilkan_state_tidak_ditemukan(): void
    {
        $a = $this->aset();
        $this->get('/aset/RMB-999-CDB-26')->assertOk()->assertSee('KODE TIDAK DITEMUKAN')->assertSee('Laporkan kerusakan tanpa kode');

        Livewire::test('pages::aset.publik', ['kode' => 'RMB-999-CDB-26'])
            ->set('ketik', 'asal')->call('cari')->assertHasErrors('ketik')
            ->set('ketik', 'RMB-998-CDB-26')->call('cari')->assertHasErrors('ketik')
            ->set('ketik', strtolower($a->kode))->call('cari')->assertHasNoErrors()->assertRedirect(route('aset', $a->kode));
    }

    public function test_dishub_melihat_petugas_laporan_dan_tombol_catat(): void
    {
        $a = $this->aset();
        $petugas = $this->user('petugas@usuljalan.test');
        $lap = LaporanKerusakan::create(['nomor' => 'LPR-2026-00777', 'perlengkapan_terpasang_id' => $a->id, 'jenis_perlengkapan_id' => $a->jenis_perlengkapan_id,
            'status' => StatusLaporan::Selesai, 'kerusakan' => 'roboh', 'lat' => $a->lat, 'lng' => $a->lng]);
        $this->catatan($a, $petugas, ['laporan_kerusakan_id' => $lap->id, 'kegiatan' => 'perbaikan']);

        $this->get(route('aset', $a->kode))->assertSee('dari laporan warga')->assertDontSee('LPR-2026-00777')->assertDontSee($petugas->name);

        $this->actingAs($petugas)->get(route('aset', $a->kode))->assertOk()
            ->assertSee($petugas->name)->assertSee('LPR-2026-00777')->assertSee('Catat pemeliharaan');
    }

    public function test_laporan_terbuka_tampil_dilaporkan_rusak(): void
    {
        $a = $this->aset();
        LaporanKerusakan::create(['nomor' => 'LPR-2026-00888', 'perlengkapan_terpasang_id' => $a->id, 'jenis_perlengkapan_id' => $a->jenis_perlengkapan_id,
            'status' => StatusLaporan::Baru, 'kerusakan' => 'roboh', 'lat' => $a->lat, 'lng' => $a->lng]);

        $this->get(route('aset', $a->kode))->assertSee('Dilaporkan rusak')->assertDontSee('Kondisi baik');
    }

    public function test_koordinator_dan_petugas_dapat_mencatat(): void
    {
        foreach (['dishub@usuljalan.test', 'petugas@usuljalan.test'] as $email) {
            $a = $this->aset();
            $this->actingAs($this->user($email));
            Livewire::test('aset.catat', ['asetId' => $a->id])
                ->dispatch('catat-pemeliharaan', aset: $a->id)
                ->assertSet('terbuka', true)
                ->set('kegiatan', 'pembersihan')->set('catatan', 'Dilap bersih')
                ->set('tambah', [UploadedFile::fake()->image('a.jpg')])->assertCount('foto', 1)
                ->call('simpan')->assertHasNoErrors()->assertSet('terbuka', false)
                ->assertDispatched('pemeliharaan-dicatat');

            $p = Pemeliharaan::where('perlengkapan_terpasang_id', $a->id)->firstOrFail();
            $this->assertSame('pembersihan', $p->kegiatan);
            $this->assertCount(1, $p->foto);
            $this->assertSame($this->user($email)->id, $p->petugas_id);
        }
    }

    public function test_tanggal_dan_catatan_divalidasi(): void
    {
        $a = $this->aset();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('aset.catat', ['asetId' => $a->id])->dispatch('catat-pemeliharaan', aset: $a->id)
            ->set('tanggal', now()->addDay()->toDateString())->call('simpan')->assertHasErrors('tanggal')
            ->set('tanggal', now()->subYears(2)->toDateString())->call('simpan')->assertHasErrors('tanggal')
            ->set('tanggal', now()->toDateString())->set('catatan', str_repeat('x', 501))->call('simpan')->assertHasErrors('catatan');
    }

    public function test_admin_dan_pengusul_ditolak(): void
    {
        $a = $this->aset();
        foreach (['admin@usuljalan.test', 'desa@usuljalan.test'] as $email) {
            $this->actingAs($this->user($email));
            Livewire::test('aset.catat', ['asetId' => $a->id])->dispatch('catat-pemeliharaan', aset: $a->id)->assertForbidden();
            Livewire::test('aset.catat', ['asetId' => $a->id])->call('simpan')->assertForbidden();
            $this->get(route('aset', $a->kode))->assertOk()->assertDontSee('Catat pemeliharaan');
        }
        $this->assertSame(0, Pemeliharaan::count());
    }

    public function test_urungkan_menghapus_catatan(): void
    {
        $a = $this->aset();
        $this->actingAs($this->user('petugas@usuljalan.test'));
        Livewire::test('aset.catat', ['asetId' => $a->id])->dispatch('catat-pemeliharaan', aset: $a->id)->call('simpan');
        $p = Pemeliharaan::firstOrFail();

        Livewire::test('aset.catat', ['asetId' => $a->id])->dispatch('urungkan', token: 'pmh-catat:'.$p->id)->assertDispatched('pemeliharaan-dicatat');
        $this->assertSame(0, Pemeliharaan::count());
    }

    public function test_urungkan_ditolak_bila_bukan_haknya(): void
    {
        $a = $this->aset();
        $p = $this->catatan($a, $this->user('dishub@usuljalan.test'));
        $this->actingAs($this->user('petugas@usuljalan.test'));
        Livewire::test('aset.catat', ['asetId' => $a->id])->dispatch('urungkan', token: 'pmh-catat:'.$p->id)->assertForbidden();
        $this->assertSame(1, Pemeliharaan::count());
    }
}

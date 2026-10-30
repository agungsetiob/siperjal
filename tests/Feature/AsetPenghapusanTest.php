<?php

namespace Tests\Feature;

use App\Enums\StatusLaporan;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LaporanKerusakan;
use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use App\Models\User;
use App\Notifications\LaporanBerubah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Penghapusan aset: rusak total/hilang/dibongkar dinonaktifkan, kode & riwayat tetap; hapus permanen hanya untuk salah input. */
class AsetPenghapusanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function aset(array $attr = []): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create($attr + [
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id'), 'jumlah' => 1, 'lat' => -3.4321, 'lng' => 116.0123,
            'kecamatan_id' => Kecamatan::where('nama', 'Batulicin')->value('id'), 'terpasang_at' => '2026-08-14', 'alamat' => 'Jl. Uji Penghapusan',
        ]);
    }

    public function test_aset_dengan_riwayat_dihapuskan_lalu_hilang_dari_daftar_peta_dan_lapor(): void
    {
        $a = $this->aset();
        Pemeliharaan::create(['perlengkapan_terpasang_id' => $a->id, 'tanggal' => today()->subMonth(), 'kegiatan' => 'rutin']);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $c = Livewire::test('pages::terpasang.index')
            ->call('bukaHapus', $a->id)->assertSet('dialog', 'hapus')
            ->assertSee('Hapuskan aset')->assertDontSee('Hapus permanen') // punya riwayat: tidak bisa dihapus permanen
            ->set('alasanHapus', 'rusak_total')->set('catatanHapus', 'Tiang patah ditabrak truk')
            ->set('fotoHapus', UploadedFile::fake()->image('patah.jpg'))
            ->call('hapuskan')->assertHasNoErrors()->assertSet('dialog', null)
            ->assertDispatched('toast', urungkan: 'aset-hapuskan:'.$a->id);

        $a->refresh();
        $this->assertTrue($a->dihapuskan());
        $this->assertSame([today()->toDateString(), 'rusak_total', 'Tiang patah ditabrak truk'], [$a->dihapuskan_at->toDateString(), $a->alasan_hapus, $a->catatan_hapus]);
        Storage::disk('public')->assertExists($a->foto_hapus);
        $this->assertNotNull($a->kode); // kode tetap
        $this->assertSame(1, $a->pemeliharaan()->count()); // riwayat tetap

        // daftar Terpasang tidak memuatnya; saringan "dihapuskan" memuatnya
        $this->assertNotContains($a->id, $c->instance()->daftar->pluck('id'));
        $c->call('saringKondisi', 'dihapuskan')->assertSee('Jl. Uji Penghapusan');
        $c->call('pilihAset', $a->id)->assertSee('Rusak total')->assertSee('Tiang patah ditabrak truk')->assertSee('Aktifkan lagi')->assertDontSee('Catat pemeliharaan');

        // peta beranda, lapor kerusakan, aset sekitar
        $this->assertFalse(collect(Livewire::test('pages::beranda')->instance()->dataPeta()['titik'])->contains(fn ($t) => str_contains($t['popup'], $a->kode)));
        $this->assertFalse(PerlengkapanTerpasang::sekitar($a->lat, $a->lng, 50)->contains('id', $a->id));
        $this->assertFalse(LaporanKerusakan::terpasangSekitar($a->lat, $a->lng)->contains('id', $a->id));

        // catat pemeliharaan & hapus permanen ditolak
        Livewire::test('aset.catat')->call('buka', $a->id)->assertNotFound();
        $this->assertTrue($a->punyaRiwayat());
    }

    public function test_halaman_pindai_menampilkan_sudah_dihapuskan_dan_lapor_dialihkan(): void
    {
        $a = $this->aset();
        $a->hapuskan('hilang', null, today()->subDay()->toDateString(), $this->user('dishub@usuljalan.test'));

        $this->get(route('aset', $a->kode))->assertOk()->assertSee('Sudah dihapuskan')->assertSee('hilang / dicuri')->assertDontSee('Lapor rusak');
        $this->get(route('lapor.aset', $a->id))->assertRedirect(route('aset', $a->kode));
    }

    public function test_laporan_berjalan_ditutup_dan_pelapor_dikabari(): void
    {
        Notification::fake();
        $a = $this->aset();
        $warga = $this->user('warga@usuljalan.test');
        $l = LaporanKerusakan::buat(['aset' => $a], $warga, 'roboh', 'Roboh', []);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        Livewire::test('pages::terpasang.index')->call('bukaHapus', $a->id)
            ->assertSee('akan ditutup')->set('alasanHapus', 'dibongkar')->call('hapuskan')->assertHasNoErrors();

        $l->refresh();
        $this->assertSame(StatusLaporan::Ditolak, $l->status);
        $this->assertStringContainsString('dihapuskan', $l->alasan_tolak);
        Notification::assertSentTo($warga, LaporanBerubah::class);
    }

    public function test_urungkan_dan_aktifkan_lagi(): void
    {
        $a = $this->aset();
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $c = Livewire::test('pages::terpasang.index')->call('bukaHapus', $a->id)->call('hapuskan');
        $this->assertTrue($a->fresh()->dihapuskan());
        $c->dispatch('urungkan', token: 'aset-hapuskan:'.$a->id);
        $this->assertFalse($a->fresh()->dihapuskan());

        $a->fresh()->hapuskan('rusak_total', null, null, $this->user('dishub@usuljalan.test'));
        $c->call('aktifkanLagi', $a->id)->assertDispatched('toast');
        $this->assertNull($a->fresh()->alasan_hapus);
    }

    public function test_validasi_dan_wewenang(): void
    {
        $a = $this->aset();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('bukaHapus', $a->id)
            ->set('alasanHapus', 'lainnya')->call('hapuskan')->assertHasErrors('catatanHapus')
            ->set('catatanHapus', 'Diganti jenis lain')->set('tanggalHapus', today()->addDay()->toDateString())->call('hapuskan')->assertHasErrors('tanggalHapus');
        $this->assertFalse($a->fresh()->dihapuskan());

        // admin hanya melihat
        $this->actingAs($this->user('admin@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('bukaHapus', $a->id)->assertForbidden();
        Livewire::test('pages::terpasang.index')->call('pilihAset', $a->id)->assertDontSee('Hapus / hapuskan aset');
    }

    public function test_data_lama_tanpa_riwayat_bisa_dihapus_permanen_dari_dialog(): void
    {
        $a = $this->aset();
        $this->actingAs($this->user('dishub@usuljalan.test'));

        Livewire::test('pages::terpasang.index')->call('bukaHapus', $a->id)
            ->assertSee('Hapus permanen')->set('hapusMode', 'permanen')->assertSee('salah input')
            ->call('hapus', $a->id)->assertSet('dialog', null)->assertDispatched('toast', pesan: 'Perlengkapan dihapus');
        $this->assertNull($a->fresh());
    }
}

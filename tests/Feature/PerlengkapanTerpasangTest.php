<?php

namespace Tests\Feature;

use App\Enums\StatusItem;
use App\Enums\StatusUsulan;
use App\Models\JenisPerlengkapan;
use App\Models\LaporanKerusakan;
use App\Models\PerlengkapanTerpasang;
use App\Models\User;
use App\Models\Usulan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PerlengkapanTerpasangTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function lama(array $attr = []): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create($attr + [
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id'),
            'jumlah' => 2, 'lat' => -3.4500, 'lng' => 116.0100, 'alamat' => 'Jl. Lama Batulicin', 'tahun' => 2018,
        ]);
    }

    public function test_tandai_terpasang_membuat_aset_dan_batalnya_menghapus(): void
    {
        Notification::fake();
        Storage::fake('public');
        $pengusul = $this->user('desa@usuljalan.test');
        $u = Usulan::create(['user_id' => $pengusul->id, 'instansi_id' => $pengusul->instansi_id, 'nomor' => 'USL-2026-00009', 'status' => StatusUsulan::Disetujui,
            'lat' => -3.4492, 'lng' => 116.0018, 'alamat' => 'Jl. Raya Batulicin']);
        $item = $u->items()->create(['jenis_perlengkapan_id' => JenisPerlengkapan::value('id'), 'jumlah' => 2, 'jumlah_disetujui' => 2, 'harga_satuan' => 750000, 'status' => StatusItem::Disetujui]);
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $item->tandaiTerpasang([['file' => UploadedFile::fake()->image('a.jpg')]], 2, null, null);
        $aset = PerlengkapanTerpasang::where('usulan_item_id', $item->id)->firstOrFail();
        $this->assertSame(2, $aset->jumlah);
        $this->assertSame([750000, 1500000], [$aset->harga_satuan, $aset->nilai()]); // harga terkunci Bappeda ikut
        $this->assertEqualsWithDelta(-3.4492, $aset->lat, 0.00001);
        $this->assertFalse($aset->adalahLama());

        $item->fresh()->batalTerpasang();
        $this->assertSame(0, PerlengkapanTerpasang::where('usulan_item_id', $item->id)->count());
    }

    public function test_perlengkapan_lama_tampil_di_beranda_dan_bisa_dilaporkan_publik_sampai_selesai(): void
    {
        Notification::fake();
        Storage::fake('public');
        $aset = $this->lama();

        $peta = Livewire::test('pages::beranda')->instance()->dataPeta();
        $this->assertCount(1, $peta['titik']);
        $this->assertStringContainsString('Jl. Lama Batulicin', $peta['titik'][0]['popup']);
        $this->assertStringContainsString(route('lapor.aset', $aset->id), $peta['titik'][0]['popup']);

        $this->get(route('lapor.aset', $aset->id))->assertOk()->assertSee('Terpasang sejak 2018');
        Livewire::test('pages::pemeliharaan.publik', ['item' => $aset])
            ->set('kerusakan', 'mati')->set('foto', [UploadedFile::fake()->image('a.jpg')])->call('kirim')
            ->assertHasNoErrors();
        $l = LaporanKerusakan::firstOrFail();
        $this->assertSame($aset->id, $l->perlengkapan_terpasang_id);
        $this->assertNull($l->usulan_item_id);
        $this->assertTrue(Livewire::test('pages::beranda')->instance()->dataPeta()['titik'][0]['rusak']);

        // Dishub menangani sampai selesai tanpa usulan asal
        $dishub = $this->user('dishub@usuljalan.test');
        $this->actingAs($dishub)->get(route('pemeliharaan', $l))->assertOk()->assertSee('Terpasang sebelum SIPERJAL');
        $l->tugaskan($dishub);
        $l->cek(true, [], 'Lampu mati');
        $l->selesai([UploadedFile::fake()->image('h.jpg')->size(10) ? ['file' => UploadedFile::fake()->image('h.jpg')] : []], 'Diganti');
        $this->assertNull($aset->fresh()->laporanTerbuka);
    }

    public function test_tautan_lama_berbasis_item_usulan_dialihkan_ke_aset(): void
    {
        $pengusul = $this->user('desa@usuljalan.test');
        $u = Usulan::create(['user_id' => $pengusul->id, 'nomor' => 'USL-2026-00010', 'status' => StatusUsulan::Terpasang, 'lat' => -3.45, 'lng' => 116.0]);
        $item = $u->items()->create(['jenis_perlengkapan_id' => JenisPerlengkapan::value('id'), 'jumlah' => 1, 'status' => StatusItem::Terpasang, 'terpasang_at' => now()]);
        $aset = $item->sinkronAset();

        $this->get(route('lapor.item', $item->id))->assertRedirect(route('lapor.aset', $aset->id))->assertStatus(301);
        $this->get(route('lapor.item', 99999))->assertNotFound();
    }

    public function test_hanya_dishub_dan_admin_membuka_halaman_dan_admin_tidak_bisa_mengubah(): void
    {
        $this->lama();
        $this->actingAs($this->user('warga@usuljalan.test'))->get(route('terpasang'))->assertForbidden();
        $this->actingAs($this->user('bappeda@usuljalan.test'))->get(route('terpasang'))->assertForbidden();

        $this->actingAs($this->user('admin@usuljalan.test'))->get(route('terpasang'))->assertOk()->assertSee('Jl. Lama Batulicin')->assertDontSee('Impor Excel');
        Livewire::test('pages::terpasang.index')->call('tambah')->assertStatus(403);

        $this->actingAs($this->user('dishub@usuljalan.test'))->get(route('terpasang'))->assertOk()->assertSee('Impor Excel');
    }

    public function test_dishub_menambah_mengubah_dan_menghapus_data_lama(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $jenis = JenisPerlengkapan::value('id');

        Livewire::test('pages::terpasang.index')
            ->call('tambah')
            ->call('simpan')->assertHasErrors(['items.0.jenis', 'lat'])
            ->set('items.0.jenis', $jenis)->set('items.0.jumlah', 3)->set('tahun', 2015)
            ->call('aturLokasi', -3.46, 116.01, ['jalan' => 'Jl. Baru'])
            ->call('simpan')->assertHasNoErrors()->assertSet('dialog', null);
        $a = PerlengkapanTerpasang::lama()->firstOrFail();
        $this->assertSame([3, 2015, 'Jl. Baru', null], [$a->jumlah, $a->tahun, $a->alamat, $a->nilai()]);

        Livewire::test('pages::terpasang.index')
            ->call('ubah', $a->id)->assertSet('items.0.jumlah', 3)
            ->set('items.0.jumlah', 5)->set('items.0.harga', '200000')->call('simpan');
        $this->assertSame([5, 200000, 1000000], [$a->fresh()->jumlah, $a->fresh()->harga_satuan, $a->fresh()->nilai()]);

        Livewire::test('pages::terpasang.index')->call('hapus', $a->id);
        $this->assertSame(0, PerlengkapanTerpasang::count());
    }

    public function test_satu_lokasi_beberapa_jenis_sekali_simpan(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'));
        [$a, $b] = JenisPerlengkapan::orderBy('id')->take(2)->pluck('id')->all();

        Livewire::test('pages::terpasang.index')
            ->call('tambah')
            ->call('aturLokasi', -3.46, 116.01, ['jalan' => 'Simpang Lima'])
            ->set('items.0.jenis', $a)->set('items.0.jumlah', 4)->set('items.0.harga', '1500000')
            ->call('tambahItem')
            ->set('items.1.jenis', $a)
            ->call('simpan')->assertHasErrors(['items.1.jenis']) // jenis yang sama dua kali
            ->set('items.1.jenis', $b)->set('items.1.jumlah', 2)
            ->call('simpan')->assertHasNoErrors()->assertDispatched('toast', pesan: '2 perlengkapan ditambahkan');

        $rows = PerlengkapanTerpasang::lama()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['Simpang Lima', 'Simpang Lima'], $rows->pluck('alamat')->all());
        $this->assertSame([6000000, null], $rows->map->nilai()->all());
        $this->get(route('terpasang'))->assertSee('nilai tercatat');
    }

    public function test_data_lama_yang_sudah_dilaporkan_tidak_bisa_dihapus_dan_data_usulan_tidak_bisa_diubah(): void
    {
        Notification::fake();
        Storage::fake('public');
        $dishub = $this->user('dishub@usuljalan.test');
        $this->actingAs($dishub);
        $a = $this->lama();
        LaporanKerusakan::buat(['aset' => $a], $dishub, 'mati', null, []);
        Livewire::test('pages::terpasang.index')->call('hapus', $a->id)->assertDispatched('toast', gagal: true);
        $this->assertSame(1, PerlengkapanTerpasang::count());

        $dariUsulan = $this->lama(['usulan_item_id' => $this->itemUsulan()->id]);
        Livewire::test('pages::terpasang.index')->call('ubah', $dariUsulan->id)->assertStatus(404);
        Livewire::test('pages::terpasang.index')->call('hapus', $dariUsulan->id)->assertStatus(404);
    }

    private function itemUsulan()
    {
        $u = Usulan::create(['user_id' => $this->user('desa@usuljalan.test')->id, 'nomor' => 'USL-2026-00011', 'status' => StatusUsulan::Terpasang, 'lat' => -3.45, 'lng' => 116.0]);

        return $u->items()->create(['jenis_perlengkapan_id' => JenisPerlengkapan::value('id'), 'jumlah' => 1, 'status' => StatusItem::Terpasang]);
    }

    public function test_impor_excel_pratinjau_simpan_dan_batalkan(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $rambu = JenisPerlengkapan::where('nama', 'like', 'Rambu%')->first();
        $this->lama(['lat' => -3.4510, 'lng' => 116.0110, 'jenis_perlengkapan_id' => $rambu->id]); // sudah ada
        $csv = implode("\n", [
            'Perlengkapan,Jumlah,Harga satuan,Latitude,Longitude,Alamat,Kecamatan,Desa,Tahun,Catatan',
            "{$rambu->nama},2,\"Rp 1.250.000\",-3.4600,116.0200,Jl. A,,,2017,",   // sah, harga bergaya rupiah
            "{$rambu->nama},1,,-3.4700,116.0250,Jl. C,,,2017,",   // sah, tanpa harga
            "{$rambu->nama},1,mahal,-3.4800,116.0300,Jl. D,,,2017,", // harga tak terbaca
            "{$rambu->nama},1,,-3.4600,116.0200,Jl. A dobel,,,2017,", // dobel di berkas
            "{$rambu->nama},1,,\"-3,4510\",116.0110,Jl. B,,,2016,", // sudah tercatat (koma desimal)
            'Perlengkapan Antah Berantah,1,,-3.46,116.02,,,,,',      // jenis tak dikenal
            "{$rambu->nama},1,,-8.0,110.0,,,,,",                    // di luar wilayah
            "{$rambu->nama},0,,-3.47,116.03,,,,,",                  // jumlah salah
            ",,,,,,,,,",                                            // kosong, diabaikan
        ]);

        $c = Livewire::test('pages::terpasang.index')
            ->call('bukaImpor')
            ->set('berkas', UploadedFile::fake()->createWithContent('data.csv', $csv))
            ->assertHasNoErrors();
        $this->assertSame(['sah' => 2, 'lewati' => 2, 'galat' => 4, 'terlalu_banyak' => false], $c->get('ringkas'));
        $this->assertCount(1, PerlengkapanTerpasang::all()); // pratinjau belum menyimpan

        $c->call('terapkanImpor')->assertSet('dialog', null)->assertDispatched('toast');
        $this->assertSame(3, PerlengkapanTerpasang::count());
        $batch = $c->get('batchTerakhir');
        $this->assertSame([1250000, null], PerlengkapanTerpasang::where('batch', $batch)->orderBy('id')->pluck('harga_satuan')->all());

        $c->dispatch('urungkan', token: 'impor-terpasang:'.$batch);
        $this->assertSame(1, PerlengkapanTerpasang::count());
    }

    public function test_impor_ditolak_untuk_admin_dan_template_hanya_dishub(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        Livewire::test('pages::terpasang.index')->call('bukaImpor')->assertStatus(403);
        $this->get(route('terpasang.template'))->assertForbidden();

        $this->actingAs($this->user('dishub@usuljalan.test'))->get(route('terpasang.template'))->assertOk();
    }

    public function test_template_berisi_sheet_daftar_perlengkapan_dan_impor_membaca_sheet_data(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $res = $this->get(route('terpasang.template'))->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $salinan = sys_get_temp_dir().'/template-uji-'.uniqid().'.xlsx';
        copy($path, $salinan);

        $reader = \Spatie\SimpleExcel\SimpleExcelReader::create($salinan);
        $this->assertSame(['Data', 'Daftar perlengkapan'], $reader->getSheetNames());
        $daftar = \Spatie\SimpleExcel\SimpleExcelReader::create($salinan)->fromSheetName('Daftar perlengkapan')->getRows()->pluck('Perlengkapan (salin ke sheet Data)')->all();
        $this->assertSame(JenisPerlengkapan::orderBy('urutan')->pluck('nama')->all(), $daftar);

        // baris contoh di sheet Data terbaca; sheet daftar tidak ikut diimpor
        $hasil = \App\Support\ImporTerpasang::baca($salinan, 'xlsx');
        $this->assertCount(1, $hasil['baris']);
        $this->assertSame('sah', $hasil['baris'][0]['status']);
        @unlink($salinan);
    }

    public function test_dishub_minta_admin_menambah_jenis_baru(): void
    {
        Notification::fake();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $ada = JenisPerlengkapan::value('nama');

        Livewire::test('pages::terpasang.index')
            ->call('bukaMinta')->assertSet('minta', true)
            ->set('mintaNama', strtoupper($ada))->call('kirimMinta')->assertHasErrors('mintaNama') // sudah ada
            ->set('mintaNama', 'Cermin tikungan')->set('mintaSatuan', 'unit')->call('kirimMinta')
            ->assertHasNoErrors()->assertSet('minta', false)->assertDispatched('toast', pesan: 'Permintaan terkirim ke admin');

        $admin = $this->user('admin@usuljalan.test');
        Notification::assertSentTo($admin, \App\Notifications\PermintaanJenis::class, function ($n) use ($admin) {
            $d = $n->toArray($admin);

            return $n->nama === 'Cermin tikungan' && $d['tautan'] === route('pengaturan', ['tab' => 'perlengkapan']);
        });
        Notification::assertNotSentTo($this->user('bappeda@usuljalan.test'), \App\Notifications\PermintaanJenis::class);

        $this->actingAs($admin);
        Livewire::test('pages::terpasang.index')->call('bukaMinta')->assertStatus(403);
    }

    public function test_kabar_permintaan_jenis_tampil_di_lonceng_admin_dan_membuka_pengaturan(): void
    {
        $admin = $this->user('admin@usuljalan.test');
        $admin->notify(new \App\Notifications\PermintaanJenis($this->user('dishub@usuljalan.test'), 'Cermin tikungan', 'unit'));
        $id = $admin->notifications()->value('id');
        $this->actingAs($admin);

        Livewire::test('lonceng')->assertSee('Permintaan jenis perlengkapan baru')->assertSee('Cermin tikungan')->assertSee('Tambahkan')
            ->call('buka', $id)->assertRedirect(route('pengaturan', ['tab' => 'perlengkapan']));
        Livewire::test('pages::notifikasi')->call('buka', $id)->assertRedirect(route('pengaturan', ['tab' => 'perlengkapan']));
    }
}

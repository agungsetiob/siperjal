<?php

namespace Tests\Feature;

use App\Enums\StatusItem;
use App\Enums\StatusUsulan;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\PerlengkapanTerpasang;
use App\Models\Usulan;
use App\Models\UsulanItem;
use App\Models\User;
use App\Support\KodeRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KodeRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function jenis(string $awal): JenisPerlengkapan
    {
        return JenisPerlengkapan::where('nama', 'like', "{$awal}%")->firstOrFail();
    }

    private function batulicin(): Kecamatan
    {
        return Kecamatan::where('nama', 'Batulicin')->firstOrFail();
    }

    private function aset(array $attr = []): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create($attr + [
            'jenis_perlengkapan_id' => $this->jenis('Rambu')->id, 'jumlah' => 1, 'lat' => -3.45, 'lng' => 116.01,
            'kecamatan_id' => $this->batulicin()->id, 'terpasang_at' => '2026-08-14',
        ]);
    }

    private function item(int $jumlah, ?string $jenis = 'Rambu'): UsulanItem
    {
        $desa = User::where('email', 'desa@usuljalan.test')->firstOrFail();
        $u = Usulan::create(['user_id' => $desa->id, 'instansi_id' => $desa->instansi_id, 'nomor' => 'USL-2026-'.str_pad((string) (Usulan::count() + 1), 5, '0', STR_PAD_LEFT),
            'status' => StatusUsulan::Terpasang, 'lat' => -3.4492, 'lng' => 116.0018, 'alamat' => 'Jl. Raya Batulicin', 'kecamatan_id' => $this->batulicin()->id]);

        return $u->items()->create(['jenis_perlengkapan_id' => $this->jenis($jenis)->id, 'jumlah' => $jumlah, 'jumlah_terpasang' => $jumlah,
            'harga_satuan' => 1000000, 'status' => StatusItem::Terpasang, 'terpasang_at' => '2026-08-14']);
    }

    public function test_kode_berurutan_per_jenis_kecamatan_dan_tahun(): void
    {
        $this->assertSame('RMB-001-BTL-26', $this->aset()->kode);
        $this->assertSame('RMB-002-BTL-26', $this->aset()->kode);
        $this->assertSame('RMB-001-BTL-25', $this->aset(['terpasang_at' => '2025-02-01'])->kode); // tahun lain mulai lagi
        $this->assertSame('PJU-001-BTL-26', $this->aset(['jenis_perlengkapan_id' => $this->jenis('Lampu penerangan')->id])->kode);
        $this->assertSame('RMB-001-KRB-26', $this->aset(['kecamatan_id' => Kecamatan::where('nama', 'Karang Bintang')->value('id')])->kode);
        $this->assertSame('RMB-003-BTL-26', $this->aset()->kode);
    }

    public function test_nomor_tidak_dipakai_ulang_setelah_aset_dihapus(): void
    {
        $a = $this->aset();
        $b = $this->aset();
        $b->delete();
        $this->assertSame('RMB-003-BTL-26', $this->aset()->kode);
        $this->assertSame('RMB-001-BTL-26', $a->kode);
    }

    public function test_aset_tanpa_kecamatan_tetap_tercatat_dan_berkode_setelah_dilengkapi(): void
    {
        $a = $this->aset(['kecamatan_id' => null, 'terpasang_at' => null, 'tahun' => 2018]);
        $this->assertNull($a->kode);
        $this->assertSame(1, PerlengkapanTerpasang::belumBerkode()->count());

        $a->update(['kecamatan_id' => $this->batulicin()->id]);
        $this->assertSame('RMB-001-BTL-18', $a->fresh()->kode);

        $a->update(['alamat' => 'Jl. Baru', 'tahun' => 2019]); // kode tidak berubah setelah dibuat
        $this->assertSame('RMB-001-BTL-18', $a->fresh()->kode);
    }

    public function test_lengkapi_memberi_kode_setelah_kode_singkat_diisi_dari_yang_tertua(): void
    {
        $this->batulicin()->update(['kode' => null]);
        $baru = $this->aset(['terpasang_at' => '2026-09-01']);
        $lama = $this->aset(['terpasang_at' => '2026-01-05']);
        $this->assertNull($baru->kode);

        $this->batulicin()->update(['kode' => 'BTL']);
        $this->assertSame(2, KodeRegister::lengkapi());
        $this->assertSame(['RMB-002-BTL-26', 'RMB-001-BTL-26'], [$baru->fresh()->kode, $lama->fresh()->kode]);
    }

    public function test_rambu_dari_usulan_dipecah_per_unit_sedangkan_marka_satu_baris(): void
    {
        $rambu = $this->item(3);
        $pertama = $rambu->sinkronAset();
        $unit = $rambu->asetUnit()->get();
        $this->assertCount(3, $unit);
        $this->assertSame([1, 1, 1], $unit->pluck('jumlah')->all());
        $this->assertSame(['RMB-001-BTL-26', 'RMB-002-BTL-26', 'RMB-003-BTL-26'], $unit->pluck('kode')->all());
        $this->assertSame($pertama->id, $unit->first()->id);

        $marka = $this->item(120, 'Marka');
        $marka->sinkronAset();
        $baris = $marka->asetUnit()->get();
        $this->assertCount(1, $baris);
        $this->assertSame([120, 'MRK-001-BTL-26'], [$baris->first()->jumlah, $baris->first()->kode]);
    }

    public function test_sinkron_aset_idempoten_dan_mengurangi_jumlah_dari_unit_terbaru(): void
    {
        $item = $this->item(3);
        $item->sinkronAset();
        $item->sinkronAset();
        $this->assertSame(3, $item->asetUnit()->count()); // dua kali sinkron tidak menggandakan
        $kode = $item->asetUnit()->pluck('kode')->all();

        $item->update(['jumlah_terpasang' => 2, 'catatan_terpasang' => 'rapi']);
        $item->sinkronAset();
        $this->assertSame(array_slice($kode, 0, 2), $item->asetUnit()->pluck('kode')->all()); // yang terbaru dihapus, kode lama tetap
        $this->assertSame(['rapi', 'rapi'], $item->asetUnit()->pluck('catatan')->all());

        $item->update(['jumlah_terpasang' => 4]);
        $item->sinkronAset();
        $this->assertSame(array_merge(array_slice($kode, 0, 2), ['RMB-004-BTL-26', 'RMB-005-BTL-26']), $item->asetUnit()->pluck('kode')->all()); // nomor yang terhapus tidak dipakai lagi
    }

    public function test_jumlah_tidak_bisa_dikurangi_di_bawah_unit_yang_punya_riwayat(): void
    {
        $item = $this->item(3);
        $item->sinkronAset();
        $terakhir = $item->asetUnit()->get()->last();
        \App\Models\Pemeliharaan::create(['perlengkapan_terpasang_id' => $terakhir->id, 'tanggal' => now(), 'kegiatan' => 'rutin']);

        $item->update(['jumlah_terpasang' => 2]);
        $this->expectException(\DomainException::class);
        $item->sinkronAset();
    }

    public function test_batal_terpasang_ditolak_bila_unit_punya_riwayat_dan_menghapus_bila_bersih(): void
    {
        $bersih = $this->item(2);
        $bersih->sinkronAset();
        $bersih->batalTerpasang();
        $this->assertSame(0, $bersih->asetUnit()->count());

        $item = $this->item(2);
        $unit = $item->sinkronAset();
        \App\Models\Pemeliharaan::create(['perlengkapan_terpasang_id' => $unit->id, 'tanggal' => now(), 'kegiatan' => 'rutin']);
        try {
            $item->batalTerpasang();
            $this->fail('seharusnya ditolak');
        } catch (\DomainException) {
            $this->assertSame(2, $item->asetUnit()->count());
        }
    }

    public function test_tautan_lama_item_usulan_menuju_unit_pertama(): void
    {
        $item = $this->item(3);
        $pertama = $item->sinkronAset();
        $this->get(route('lapor.item', $item->id))->assertRedirect(route('lapor.aset', $pertama->id))->assertStatus(301);
    }

    public function test_admin_mengisi_kode_singkat_di_pengaturan_dan_unit_menunggu_langsung_berkode(): void
    {
        $this->actingAs(User::where('email', 'admin@usuljalan.test')->firstOrFail());
        $rambu = $this->jenis('Rambu');
        $rambu->update(['kode' => null]);
        $a = $this->aset();
        $this->assertNull($a->kode);

        $c = \Livewire\Livewire::test('pages::pengaturan');
        $c->call('ubahJenis', $rambu->id, 'kode', 'r')->assertDispatched('toast', gagal: true); // 1 huruf ditolak
        $c->call('ubahJenis', $rambu->id, 'kode', 'PJU')->assertDispatched('toast', gagal: true); // sudah dipakai jenis lain
        $this->assertNull($rambu->fresh()->kode);

        $c->call('ubahJenis', $rambu->id, 'kode', 'rmb');
        $this->assertSame('RMB', $rambu->fresh()->kode); // huruf kapital otomatis
        $this->assertSame('RMB-001-BTL-26', $a->fresh()->kode);

        $c->call('ubahJenis', $rambu->id, 'kode', 'RMX'); // mengubah kode hanya berlaku untuk unit baru
        $this->assertSame('RMB-001-BTL-26', $a->fresh()->kode);
        $this->assertSame('RMX-002-BTL-26', $this->aset()->kode); // nomor urut tetap berlanjut
    }

    public function test_kode_kecamatan_diisi_admin_dan_pilihan_per_unit_per_ruas(): void
    {
        $this->actingAs(User::where('email', 'admin@usuljalan.test')->firstOrFail());
        $kec = Kecamatan::where('nama', 'Simpang Empat')->firstOrFail();
        $kec->update(['kode' => null]);
        $a = $this->aset(['kecamatan_id' => $kec->id]);
        $this->assertNull($a->kode);

        $c = \Livewire\Livewire::test('pages::pengaturan');
        $c->call('ubahKodeKecamatan', $kec->id, 'btl')->assertDispatched('toast', gagal: true); // sudah dipakai Batulicin
        $c->call('ubahKodeKecamatan', $kec->id, 'spe');
        $this->assertSame('RMB-001-SPE-26', $a->fresh()->kode);

        $marka = $this->jenis('Marka');
        $c->call('ubahJenis', $marka->id, 'per_unit', '1');
        $this->assertTrue($marka->fresh()->per_unit);
    }
}

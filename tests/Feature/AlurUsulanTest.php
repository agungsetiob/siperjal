<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusItem;
use App\Enums\StatusUsulan;
use App\Models\User;
use App\Models\Usulan;
use App\Support\Urungkan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AlurUsulanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function ajukan(): Usulan
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs($this->user('warga@usuljalan.test'));

        Livewire::test('pages::usulan.form')
            ->call('aturLokasi', -3.4492, 116.0018, ['jalan' => 'Jl. Raya Batulicin', 'kecamatan' => 'Batulicin', 'desa' => 'Batulicin'])
            ->set('unggah', UploadedFile::fake()->image('lokasi.jpg'))
            ->call('simpanFoto', -3.4492, 116.0018)
            ->call('tambah', 1, 2)
            ->call('tambah', 9, 1)
            ->call('toggleKriteria', 'sekolah')
            ->call('kirim')
            ->assertSet('terkirim', true);

        return Usulan::firstOrFail();
    }

    public function test_pengusul_mengajukan_usulan_lengkap(): void
    {
        Notification::fake();
        $u = $this->ajukan();

        $this->assertSame('USL-'.now()->year.'-00001', $u->nomor);
        $this->assertSame(StatusUsulan::Diperiksa, $u->status);
        $this->assertCount(2, $u->items);
        $this->assertNotNull($u->kecamatan_id);
        Storage::disk('local')->assertExists($u->proposal_path);
        $this->get(route('lacak', $u->nomor))->assertOk()->assertSee($u->nomor);
    }

    public function test_dishub_periksa_lalu_bappeda_hanya_melihat_item_lolos(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        [$rambu, $pita] = $u->items->sortBy('jenis_perlengkapan_id')->values()->all();

        $this->actingAs($this->user('bappeda@usuljalan.test'));
        $this->assertSame(0, Usulan::terlihatOleh(auth()->user())->count());

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->set('kriteriaDishub', ['sekolah', 'kecelakaan'])
            ->set("keputusan.{$pita->id}", 'tolak')
            ->call('simpanPeriksa')
            ->assertHasErrors("catatan.{$pita->id}")
            ->set("catatan.{$pita->id}", 'Tidak sesuai')
            ->call('simpanPeriksa')
            ->assertHasNoErrors();

        $u->refresh();
        $this->assertSame(2, $u->skor);
        $this->assertSame(StatusUsulan::MenungguBappeda, $u->status);
        $this->assertSame(StatusItem::DitolakDishub, $pita->fresh()->status);

        $this->actingAs($this->user('bappeda@usuljalan.test'));
        $this->assertSame(1, Usulan::terlihatOleh(auth()->user())->count());
        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->assertSet('keputusan.'.$rambu->id, 'setujui')
            ->call('simpanBappeda');

        $rambu->refresh();
        $this->assertSame(StatusItem::Disetujui, $rambu->status);
        $this->assertSame(1_750_000, $rambu->harga_satuan);
        $this->assertSame(StatusUsulan::Disetujui, $u->fresh()->status);
    }

    public function test_wilayah_dicocokkan_dari_nama_peta(): void
    {
        $batulicin = \App\Models\Kecamatan::where('nama', 'Batulicin')->value('id');
        $kersik = \App\Models\Desa::where('nama', 'Kersik Putih')->value('id');

        $this->assertSame([$batulicin, null], \App\Support\Wilayah::cocokkan('Kecamatan Batulicin', null));
        $this->assertSame([$batulicin, $kersik], \App\Support\Wilayah::cocokkan(['Kota Batulicin', 'Kec. Batulicin'], ['Desa Kersik Putih']));
        // Kecamatan tidak terbaca: diambil dari desanya; salah ketik kecil tetap cocok
        $this->assertSame([$batulicin, $kersik], \App\Support\Wilayah::cocokkan([], 'Kersik Puti'));
        $this->assertSame([null, null], \App\Support\Wilayah::cocokkan('Antah Berantah', 'Entah'));
    }

    public function test_kecamatan_desa_otomatis_dari_peta_dan_manual_hanya_bila_gagal(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('warga@usuljalan.test'));

        Livewire::test('pages::usulan.form')
            ->call('aturLokasi', -3.4492, 116.0018, ['jalan' => 'Jl. Raya', 'kecamatan' => ['Kecamatan Batulicin'], 'desa' => ['Kersik Putih']])
            ->assertSet('kecamatanManual', false)->assertSet('desaManual', false)
            ->assertSee('Kersik Putih')
            ->assertDontSeeHtml('wire:model.live="kecamatan_id"')->assertDontSeeHtml('wire:model.live="desa_id"')
            ->call('aturLokasi', -3.5, 116.1, ['kecamatan' => ['Antah'], 'desa' => []])
            ->assertSet('kecamatan_id', null)
            ->assertSeeHtml('wire:model.live="kecamatan_id"')
            ->assertSee('Kecamatan tidak terbaca dari peta, pilih sendiri.');
    }

    public function test_admin_hanya_melihat_usulan(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $this->actingAs($this->user('admin@usuljalan.test'));

        $this->get(route('usulan', $u))->assertOk()->assertSee($u->nomor);
        $this->get(route('usulan.baru'))->assertRedirect();
        $detail = fn () => Livewire::test('usulan.detail', ['usulanId' => $u->id]);
        $detail()->assertDontSee('Minta Revisi')->assertDontSee('Simpan & Berikutnya')
            ->set('kriteriaDishub', ['sekolah'])->call('simpanPeriksa')->assertForbidden();
        $detail()->call('simpanBappeda')->assertForbidden();
        $detail()->call('mulaiUbah')->assertForbidden();
        $this->assertSame(StatusUsulan::Diperiksa, $u->fresh()->status);
    }

    public function test_skor_nol_tidak_bisa_meloloskan_item(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $this->actingAs($this->user('dishub@usuljalan.test'));

        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->set('kriteriaDishub', [])
            ->call('simpanPeriksa')
            ->assertHasErrors('skor');
    }

    public function test_urungkan_memulihkan_keputusan(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $this->actingAs($this->user('dishub@usuljalan.test'));

        $token = Urungkan::simpan([$u->id]);
        $u->items()->update(['status' => StatusItem::Lolos]);
        $u->update(['status' => StatusUsulan::MenungguBappeda]);
        $u->catat('uji');

        $this->assertSame($u->id, Urungkan::pulihkan($token));
        $this->assertSame(StatusUsulan::Diperiksa, $u->fresh()->status);
        $this->assertTrue($u->items()->get()->every(fn ($i) => $i->status === StatusItem::Menunggu));
        $this->assertNull(Urungkan::pulihkan($token));
    }

    public function test_akun_baru_tidak_bisa_dibuat_tanpa_verifikasi_kode(): void
    {
        Livewire::test('pages::masuk')
            ->set('nama', 'Penyusup')
            ->set('no_hp', '0812345678')
            ->call('simpanProfil')
            ->assertForbidden();

        $this->assertSame(0, User::where('name', 'Penyusup')->count());
    }

    public function test_pengusul_tidak_bisa_melihat_usulan_orang_lain(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $lain = User::create(['name' => 'Lain', 'email' => 'lain@test.id', 'role' => Role::Pengusul])->refresh();

        $this->actingAs($lain)->get(route('usulan.proposal', $u))->assertForbidden();
        $this->assertSame(0, Usulan::terlihatOleh($lain)->count());
    }

    public function test_petugas_dialihkan_dari_form_ajukan(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'))
            ->get(route('usulan.baru'))
            ->assertRedirect(route('usulan'));
    }

    public function test_admin_ubah_harga_dan_bisa_diurungkan(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        $jenis = \App\Models\JenisPerlengkapan::first();
        $lama = $jenis->harga_satuan;

        $c = Livewire::test('pages::pengaturan')->call('simpanHarga', $jenis->id, '2.000.000');
        $this->assertSame(2_000_000, $jenis->fresh()->harga_satuan);

        $token = collect($c->effects['dispatches'] ?? [])->firstWhere('name', 'toast')['params']['urungkan'];
        $c->call('urungkan', $token);
        $this->assertSame($lama, $jenis->fresh()->harga_satuan);
    }

    public function test_pengaturan_hanya_untuk_admin(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'))->get(route('pengaturan'))->assertForbidden();
    }

    public function test_admin_ubah_semua_nama_di_pengaturan_dan_bisa_diurungkan(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        $kec = \App\Models\Kecamatan::whereHas('desa')->firstOrFail();
        $desa = $kec->desa()->firstOrFail();
        $jenis = \App\Models\JenisPerlengkapan::firstOrFail();
        $warga = $this->user('warga@usuljalan.test');
        $instansi = \App\Models\Instansi::firstOrFail();
        $token = fn ($c) => collect($c->effects['dispatches'] ?? [])->where('name', 'toast')->last()['params']['urungkan'] ?? null;

        $c = Livewire::test('pages::pengaturan');
        $c->call('ubahKecamatan', $kec->id, '  Kecamatan Baru ');
        $this->assertSame('Kecamatan Baru', $kec->fresh()->nama);
        $c->call('urungkan', $token($c));
        $this->assertSame($kec->nama, $kec->fresh()->nama);

        $c->call('ubahDesa', $desa->id, 'Desa Baru');
        $this->assertSame('Desa Baru', $desa->fresh()->nama);

        foreach (['nama' => 'Lampu Jalan LED', 'deskripsi' => 'Tiang 7 m', 'satuan' => 'titik', 'ikon' => 'lamp', 'warna' => '#112233'] as $kolom => $nilai) {
            $c->call('ubahJenis', $jenis->id, $kolom, $nilai);
            $this->assertSame($nilai, $jenis->fresh()->{$kolom});
        }

        $c->call('ubahPengguna', $warga->id, 'name', 'Warga Baru')
            ->call('ubahPengguna', $warga->id, 'no_hp', '0812000111')
            ->call('ubahPengguna', $warga->id, 'instansi_id', (string) $instansi->id)
            ->call('ubahPengguna', $warga->id, 'email', 'baru@contoh.id');
        $warga->refresh();
        $this->assertSame(['Warga Baru', '0812000111', $instansi->id, 'baru@contoh.id'], [$warga->name, $warga->no_hp, $warga->instansi_id, $warga->email]);
        $c->call('ubahPengguna', $warga->id, 'instansi_id', '');
        $this->assertNull($warga->fresh()->instansi_id);

        $c->call('ubahInstansi', $instansi->id, 'nama', 'Pemdes Baru');
        $this->assertSame('Pemdes Baru', $instansi->fresh()->nama);
    }

    public function test_isian_pengaturan_tidak_valid_ditolak_tanpa_mengubah_data(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        $kec = \App\Models\Kecamatan::firstOrFail();
        $jenis = \App\Models\JenisPerlengkapan::firstOrFail();
        $instansi = \App\Models\Instansi::firstOrFail();
        $warga = $this->user('warga@usuljalan.test');
        $pesan = fn ($c) => collect($c->effects['dispatches'] ?? [])->where('name', 'toast')->last()['params'];

        $c = Livewire::test('pages::pengaturan');
        $c->call('ubahKecamatan', $kec->id, '   ');
        $this->assertSame(['pesan' => 'Nama tidak boleh kosong.', 'gagal' => true], $pesan($c));
        $this->assertSame($kec->nama, $kec->fresh()->nama);
        $c->assertSet('versi', 1);

        $c->call('ubahInstansi', $instansi->id, 'nama', '');
        $this->assertSame($instansi->nama, $instansi->fresh()->nama);

        $c->call('ubahPengguna', $warga->id, 'email', 'dishub@usuljalan.test');
        $this->assertSame('Email sudah dipakai.', $pesan($c)['pesan']);
        $this->assertSame('warga@usuljalan.test', $warga->fresh()->email);

        $c->call('ubahJenis', $jenis->id, 'ikon', 'bukan-ikon-sungguhan');
        $c->call('ubahJenis', $jenis->id, 'warna', 'merah');
        $this->assertSame([$jenis->ikon, $jenis->warna], [$jenis->fresh()->ikon, $jenis->fresh()->warna]);

        Livewire::test('pages::pengaturan')->call('ubahJenis', $jenis->id, 'harga_satuan', '1')->assertStatus(422);
        Livewire::test('pages::pengaturan')->call('ubahPeran', $warga->id, 'raja')->assertStatus(422);

        // bukan admin tidak bisa memanggil aksi pengaturan
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::pengaturan')->assertForbidden();
    }

    public function test_dishub_lapangan_tandai_terpasang_dan_urungkan(): void
    {
        Notification::fake();
        Storage::fake('public');
        $u = $this->ajukan();
        $item = $u->items->first();
        $item->update(['status' => StatusItem::Disetujui, 'tahun_anggaran' => now()->year]);

        $this->actingAs($this->user('dishub@usuljalan.test'));
        $c = Livewire::test('usulan.lapangan')
            ->call('buka', $item->id)
            ->call('simpan')
            ->assertHasErrors('foto')
            ->set('foto', [UploadedFile::fake()->image('hasil.jpg')])
            ->call('simpan', -3.45, 116.0)
            ->assertHasNoErrors()
            ->assertSet('itemHasil', $item->id);

        $this->assertSame(StatusItem::Terpasang, $item->fresh()->status);
        $this->assertSame(1, \App\Models\UsulanFoto::where('usulan_item_id', $item->id)->count());

        $c->call('urungkan', 'pasang:'.$item->id);
        $this->assertSame(StatusItem::Disetujui, $item->fresh()->status);
        $this->assertSame(0, \App\Models\UsulanFoto::where('usulan_item_id', $item->id)->count());
    }

    public function test_gps_pemasangan_yang_jauh_diganti_titik_usulan(): void
    {
        Notification::fake();
        Storage::fake('public');
        $u = $this->ajukan();
        [$jauh, $dekat] = $u->items->all();
        $u->items()->update(['status' => StatusItem::Disetujui]);
        $foto = fn () => [['file' => UploadedFile::fake()->image('hasil.jpg')]];

        $this->actingAs($this->user('dishub@usuljalan.test'));
        $jauh->fresh()->tandaiTerpasang($foto(), 1, -7.9548, 111.9646); // ± 580 km
        $dekat->fresh()->tandaiTerpasang($foto(), 1, $u->lat + 0.001, $u->lng); // ± 110 m

        $this->assertEquals([$u->lat, $u->lng], [$jauh->fresh()->lat_terpasang, $jauh->fresh()->lng_terpasang]);
        $this->assertEquals($u->lat + 0.001, $dekat->fresh()->lat_terpasang);
    }

    public function test_halaman_notifikasi_dan_email_revisi(): void
    {
        $u = $this->ajukan();
        $warga = $this->user('warga@usuljalan.test');
        $warga->notify(new \App\Notifications\UsulanBerubah($u, 'Usulan perlu diperbaiki', 'Foto kurang jelas', 'revisi', ['bagian' => ['foto']]));

        $this->actingAs($warga)->get(route('notifikasi'))->assertOk()->assertSee('Usulan perlu diperbaiki');
        $html = (new \App\Notifications\UsulanBerubah($u, 'Usulan perlu diperbaiki', 'Foto kurang jelas', 'revisi', ['bagian' => ['foto']]))->toMail($warga)->render();
        $this->assertStringContainsString('Perbaiki Usulan', (string) $html);
    }

    public function test_dialog_tolak_item_mengisi_alasan_lalu_simpan(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $pita = $u->items->sortBy('jenis_perlengkapan_id')->last();

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->set('kriteriaDishub', ['sekolah'])
            ->call('bukaTolak', $pita->id)
            ->assertSet('dialog', 'tolak')
            ->call('tolakItem')
            ->assertHasErrors('dialogCatatan')
            ->call('pilihAlasanTolak', 'kabupaten')
            ->call('tolakItem')
            ->assertSet('dialog', null)
            ->assertSet("keputusan.{$pita->id}", 'tolak')
            ->call('simpanPeriksa')
            ->assertHasNoErrors();

        $this->assertSame(StatusItem::DitolakDishub, $pita->fresh()->status);
        $this->assertStringContainsString('bukan jalan kabupaten', $pita->fresh()->catatan_dishub);
    }

    public function test_tandai_duplikat_bisa_menjadikan_usulan_ini_asli(): void
    {
        Notification::fake();
        $a = $this->ajukan();
        $b = $a->replicate(['nomor']);
        $b->fill(['lat' => $a->lat + 0.0003, 'status' => StatusUsulan::Draft])->save();
        $b->items()->create(['jenis_perlengkapan_id' => 1, 'jumlah' => 1]);
        $b->kirim();

        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $a->id])
            ->call('bukaDuplikat', $b->id)
            ->set('duplikatDari', $a->id)
            ->call('tandaiDuplikat')
            ->assertSet('dialog', null);

        $this->assertSame($a->id, $b->fresh()->duplikat_dari_id);
        $this->assertSame(StatusUsulan::Ditolak, $b->fresh()->status);
        $this->assertSame(StatusUsulan::Diperiksa, $a->fresh()->status);
    }

    public function test_dishub_ubah_hasil_pemeriksaan_wajib_alasan(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $pita = $u->items->sortBy('jenis_perlengkapan_id')->last();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $u->id])->set('kriteriaDishub', ['sekolah'])->call('simpanPeriksa');

        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->call('mulaiUbah')
            ->call('bukaTolak', $pita->id)
            ->call('pilihAlasanTolak', 'standar')
            ->call('tolakItem')
            ->call('simpanUbahDishub')
            ->assertHasErrors('dialogCatatan')
            ->set('dialogCatatan', 'Titik terlalu dekat simpang')
            ->call('simpanUbahDishub')
            ->assertHasNoErrors()
            ->assertSet('mengubah', false);

        $pita->refresh();
        $this->assertSame(StatusItem::DitolakDishub, $pita->status);
        $this->assertTrue($pita->diubah);
        $this->assertSame('Dishub mengubah hasil pemeriksaan', $u->riwayat()->latest('id')->value('aksi'));
    }

    public function test_bappeda_ubah_keputusan_ke_tunda_mengosongkan_anggaran(): void
    {
        Notification::fake();
        $u = $this->ajukan();
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $u->id])->set('kriteriaDishub', ['sekolah'])->call('simpanPeriksa');
        $this->actingAs($bappeda = $this->user('bappeda@usuljalan.test'));
        Livewire::test('usulan.detail', ['usulanId' => $u->id])->call('simpanBappeda');
        $item = $u->items->first()->fresh();
        $this->assertSame(StatusItem::Disetujui, $item->status);

        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->call('mulaiUbah')
            ->call('aturKeputusanUbah', $item->id, 'tunda')
            ->set("tahunItem.{$item->id}", now()->year + 3)
            ->set('dialogCatatan', 'Pagu dialihkan')
            ->call('simpanUbahBappeda')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(StatusItem::Ditunda, $item->status);
        $this->assertNull($item->tahun_anggaran);
        $this->assertNull($item->harga_satuan);
        $this->assertSame(now()->year + 3, $item->tahun_target);
        $this->assertSame($bappeda->id, $item->diputuskan_oleh);
    }

    public function test_lacak_nomor_tidak_ada_tampil_ramah(): void
    {
        $this->get('/lacak/USL-2026-99999')->assertOk()->assertSee('Nomor tidak ditemukan');
    }

    public function test_profil_tersimpan_otomatis(): void
    {
        $this->actingAs($user = $this->user('warga@usuljalan.test'));
        Livewire::test('profil')
            ->set('nama', 'Budi S.')
            ->set('hp', '0812 3456 7890')
            ->assertSet('hp', '812-3456-7890')
            ->set('notifEmail', false)
            ->assertSet('tersimpan', true);

        $user->refresh();
        $this->assertSame(['Budi S.', '081234567890', false], [$user->name, $user->no_hp, $user->notif_email]);
        $this->get('/profil')->assertOk();
    }

    public function test_detail_yang_tidak_bisa_dibuka_tampil_gagal_dimuat(): void
    {
        $u = $this->ajukan();
        $this->actingAs($this->user('desa@usuljalan.test')); // pengusul lain
        Livewire::test('usulan.detail', ['usulanId' => $u->id])
            ->assertSet('hilang', true)
            ->assertSee('Detail usulan gagal dimuat');
    }

    public function test_ganti_tab_dan_hapus_filter(): void
    {
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pages::usulan.index')
            ->set('cari', 'zzzz')
            ->assertSee('Tidak ada yang cocok')
            ->call('hapusFilter')
            ->assertSet('cari', '')
            ->call('gantiTab', 'pasang')
            ->assertSet('tab', 'pasang')
            ->call('gantiTab', 'tidak-ada')
            ->assertStatus(404);
    }

    public function test_salin_ssh_memakai_persen_yang_diisi_dan_mengingatnya(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        $jenis = \App\Models\JenisPerlengkapan::first();
        $jenis->update(['harga_satuan' => 1_000_000]);
        $tahun = (int) \App\Models\Pengaturan::ambil('tahun_ssh');

        Livewire::test('pages::pengaturan')
            ->assertSet('persen', 5)
            ->set('persen', 8)
            ->call('salinSsh')
            ->assertSee('SSH '.($tahun + 1));

        $this->assertSame(1_080_000, $jenis->fresh()->harga_satuan);
        Livewire::test('pages::pengaturan')->assertSet('persen', 8);
    }

    public function test_salin_ssh_dibatasi_paling_jauh_satu_tahun_ke_depan(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        \App\Models\Pengaturan::simpan('tahun_ssh', now()->year);

        Livewire::test('pages::pengaturan')
            ->call('salinSsh')
            ->assertSee('SSH '.(now()->year + 1).' sudah disiapkan')
            ->call('salinSsh')
            ->assertStatus(403);

        $this->assertSame(now()->year + 1, (int) \App\Models\Pengaturan::ambil('tahun_ssh'));
    }

    public function test_tautan_panduan_pertama_kali_hilang_setelah_mengirim_usulan(): void
    {
        $warga = $this->user('warga@usuljalan.test');
        $this->actingAs($warga);

        Livewire::test('pages::usulan.form')
            ->assertSet('panduanDurasi', '3 menit')
            ->assertSee('Pertama kali? Lihat panduan 3 menit');

        Usulan::create(['user_id' => $warga->id, 'nomor' => 'USL-'.now()->year.'-00001', 'status' => StatusUsulan::Diperiksa]);

        Livewire::test('pages::usulan.form')
            ->assertSet('panduanDurasi', null)
            ->assertDontSee('Pertama kali?');
    }

    public function test_draft_tidak_menghilangkan_tautan_panduan(): void
    {
        $warga = $this->user('warga@usuljalan.test');
        $this->actingAs($warga);
        Usulan::create(['user_id' => $warga->id, 'status' => StatusUsulan::Draft]);

        Livewire::test('pages::usulan.form')->assertSet('panduanDurasi', '3 menit');
    }

    public function test_email_revisi_menyertakan_tautan_panduan(): void
    {
        $warga = $this->user('warga@usuljalan.test');
        $u = Usulan::create(['user_id' => $warga->id, 'nomor' => 'USL-'.now()->year.'-00001', 'status' => StatusUsulan::Revisi, 'alamat' => 'Jl. Uji']);

        $html = view('mail.usulan', [
            'usulan' => $u, 'judul' => 'Usulan perlu diperbaiki', 'isi' => 'Foto kurang jelas', 'jenis' => 'revisi',
            'ekstra' => ['bagian' => ['foto']], 'penerima' => $warga,
        ])->render();

        $this->assertStringContainsString('Cara memperbaikinya (2 menit)', $html);
        $this->assertStringContainsString('/panduan/pengusul/perbaiki-revisi', $html);
    }
}

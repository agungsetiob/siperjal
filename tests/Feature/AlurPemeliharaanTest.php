<?php

namespace Tests\Feature;

use App\Enums\StatusItem;
use App\Enums\StatusLaporan;
use App\Enums\StatusUsulan;
use App\Models\JenisPerlengkapan;
use App\Models\PerlengkapanTerpasang;
use App\Models\LaporanKerusakan;
use App\Models\User;
use App\Models\Usulan;
use App\Notifications\LaporanBerubah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AlurPemeliharaanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Perlengkapan terpasang dari usulan Pemdes (pengusul asli ≠ pelapor). */
    private function terpasang(): PerlengkapanTerpasang
    {
        $desa = $this->user('desa@usuljalan.test');
        $u = Usulan::create(['user_id' => $desa->id, 'instansi_id' => $desa->instansi_id, 'nomor' => 'USL-2026-00001', 'status' => StatusUsulan::Terpasang,
            'lat' => -3.4492, 'lng' => 116.0018, 'alamat' => 'Jl. Raya Batulicin', 'diajukan_at' => now()->subMonths(3)]);

        $item = $u->items()->create(['jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Lampu penerangan%')->value('id'),
            'jumlah' => 1, 'status' => StatusItem::Terpasang, 'terpasang_at' => now()->subMonth(), 'jumlah_terpasang' => 1]);

        return $item->sinkronAset();
    }

    private function lapor(PerlengkapanTerpasang $item): LaporanKerusakan
    {
        $this->actingAs($this->user('warga@usuljalan.test'));
        Livewire::test('pages::pemeliharaan.lapor')
            ->set('pilihan', 'lapor')
            ->call('pilih', $item->id)
            ->call('lanjut')
            ->assertSet('langkah', 2)
            ->call('kirim')
            ->assertHasErrors(['kerusakan', 'foto'])
            ->set('kerusakan', 'mati')
            ->set('foto', [UploadedFile::fake()->image('rusak.jpg')])
            ->set('catatan', 'Mati sejak 3 hari')
            ->call('kirim')
            ->assertHasNoErrors();

        return LaporanKerusakan::latest('id')->firstOrFail();
    }

    public function test_alur_lengkap_lapor_tugaskan_cek_selesai_dan_urungkan(): void
    {
        Notification::fake();
        Storage::fake('public');
        $item = $this->terpasang();
        $koordinator = $this->user('dishub@usuljalan.test');
        $petugas = $this->user('petugas@usuljalan.test');
        $warga = $this->user('warga@usuljalan.test');

        // 1. Warga melapor → Dishub dikabari
        $l = $this->lapor($item);
        $this->assertSame('LPR-'.now()->year.'-00001', $l->nomor);
        $this->assertSame(StatusLaporan::Baru, $l->status);
        $this->assertCount(1, $l->foto);
        Notification::assertSentTo($koordinator, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_baru');
        // petugas lapangan baru dikabari saat ditugaskan
        Notification::assertNotSentTo($petugas, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_baru');
        $this->assertSame(['selesai', 'aktif', 'nanti', 'nanti'], array_column($l->langkah(), 'keadaan'));

        // 2. Koordinator menugaskan petugas → petugas & pelapor dikabari
        $this->actingAs($koordinator);
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])
            ->call('buka', 'tugaskan')
            ->set('petugasId', $petugas->id)
            ->set('catatanTugas', 'Cek kabel di tiang sebelah')
            ->call('tugaskan')
            ->assertHasNoErrors();
        $l->refresh();
        $this->assertSame(StatusLaporan::Ditugaskan, $l->status);
        $this->assertSame($petugas->id, $l->petugas_id);
        Notification::assertSentTo($petugas, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_tugas');
        Notification::assertSentTo($warga, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_tugas');

        // 3. Petugas cek di lokasi: foto wajib → setujui, perlu perbaikan
        $this->actingAs($petugas);
        $tugas = Livewire::test('pemeliharaan.tugas', ['tab' => 'ditugaskan'])
            ->assertSee($l->nomor)
            ->call('buka', $l->id, 1)
            ->assertSet('sheet', 'cek')
            ->call('cek', true)
            ->assertHasErrors('foto')
            ->set('foto', [UploadedFile::fake()->image('cek.jpg')])
            ->set('catatan', 'Kabel putus')
            ->call('cek', true, -3.4493, 116.0018)
            ->assertHasNoErrors();
        $l->refresh();
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->status);
        $this->assertSame('Kabel putus', $l->catatan_cek);
        Notification::assertSentTo($warga, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_dikonfirmasi');

        // pelapor tidak bisa membatalkan setelah dikonfirmasi
        $this->actingAs($warga);
        Livewire::test('pages::pemeliharaan.index')->call('batalkan', $l->id)->assertForbidden();

        // 4. Petugas menandai selesai diperbaiki (foto hasil wajib) → pelapor & pengusul asli dikabari
        $this->actingAs($petugas);
        Livewire::test('pemeliharaan.tugas', ['tab' => 'perbaikan'])
            ->call('buka', $l->id)
            ->assertSet('sheet', 'selesai')
            ->set('foto', [UploadedFile::fake()->image('hasil.jpg')])
            ->call('selesai')
            ->assertHasNoErrors()
            ->assertSet('hasil', $l->id);
        $this->assertSame(StatusLaporan::Selesai, $l->fresh()->status);
        Notification::assertSentTo($warga, LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_selesai');
        Notification::assertSentTo($this->user('desa@usuljalan.test'), LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_selesai');

        // 5. Urungkan dalam 10 menit
        // dari HP petugas: tetap di Tugas Saya (tidak membuka detail koordinator)
        $l->selesai([['file' => UploadedFile::fake()->image('hasil2.jpg')]]);
        Livewire::test('pages::pemeliharaan.index')->dispatch('urungkan', token: 'lpr-selesai:'.$l->id.':hp')->assertSet('pilih', null);
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->fresh()->status);
        // dari toast desktop: laporan dibuka lagi meski daftar tab sempat kosong
        $l->fresh()->selesai([['file' => UploadedFile::fake()->image('hasil3.jpg')]]);
        Livewire::test('pages::pemeliharaan.index')->dispatch('urungkan', token: 'lpr-selesai:'.$l->id)->assertSet('pilih', $l->id)->assertSet('tab', 'perbaikan');
        $l->refresh();
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->status);
        $this->assertCount(0, $l->fotoJenis('hasil'));
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::where('type', LaporanBerubah::class)->get()
            ->filter(fn ($n) => $n->data['jenis'] === 'kerusakan_selesai')->count());
    }

    public function test_tindak_lanjut_petugas_dari_panel_detail_tanpa_peta(): void
    {
        Storage::fake('public');
        Notification::fake();
        $l = $this->lapor($this->terpasang());
        $petugas = $this->user('petugas@usuljalan.test');
        $l->tugaskan($petugas, '');

        // petugas melihat tombol Cek Kondisi di detail; admin tidak
        $this->actingAs($petugas);
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])->assertSee('Cek Kondisi');
        $this->actingAs($this->user('admin@usuljalan.test'));
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])->assertDontSee('Cek Kondisi')->assertSee('Admin hanya melihat');
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])->call('buka', $l->id)->assertForbidden();

        // sheet dibuka lewat event dari detail, lalu cek → selesai
        $this->actingAs($petugas);
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])
            ->dispatch('buka-tugas', id: $l->id)->assertSet('sheet', 'cek')
            ->set('foto', [UploadedFile::fake()->image('cek.jpg')])
            ->call('cek', true)->assertHasNoErrors()->assertDispatched('laporan-diproses', lanjut: true);
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->fresh()->status);

        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])->assertSee('Tandai Selesai Diperbaiki');
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])
            ->dispatch('buka-tugas', id: $l->id)->assertSet('sheet', 'selesai')
            ->call('selesai')->assertHasErrors('foto')
            ->set('foto', [UploadedFile::fake()->image('hasil.jpg')])
            ->call('selesai')->assertHasNoErrors()->assertDispatched('toast');
        $this->assertSame(StatusLaporan::Selesai, $l->fresh()->status);
    }

    public function test_petugas_menolak_dan_koordinator_menolak_dengan_alasan(): void
    {
        Notification::fake();
        Storage::fake('public');
        $item = $this->terpasang();
        $l = $this->lapor($item);
        $petugas = $this->user('petugas@usuljalan.test');

        $this->actingAs($this->user('dishub@usuljalan.test'));
        $l->tugaskan($petugas);

        $this->actingAs($petugas);
        Livewire::test('pemeliharaan.tugas', ['tab' => 'ditugaskan'])
            ->call('buka', $l->id)
            ->set('foto', [UploadedFile::fake()->image('cek.jpg')])
            ->call('cek', false)
            ->assertHasErrors('alasan')
            ->set('alasan', 'Kondisi baik')
            ->call('cek', false)
            ->assertHasNoErrors();
        $this->assertSame(StatusLaporan::Ditolak, $l->fresh()->status);
        $this->assertSame('gagal', $l->fresh()->langkah()[1]['keadaan']);

        // Laporan kedua ditolak koordinator tanpa cek lokasi
        $l2 = $this->lapor($item);
        $this->actingAs($this->user('dishub@usuljalan.test'));
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l2->id])
            ->call('buka', 'tolak')
            ->call('tolak')
            ->assertHasErrors('alasan')
            ->set('alasan', 'Duplikat laporan lain')
            ->call('tolak');
        $this->assertSame(StatusLaporan::Ditolak, $l2->fresh()->status);
        Notification::assertSentTo($this->user('warga@usuljalan.test'), LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_ditolak');
    }

    public function test_hak_akses_pemeliharaan(): void
    {
        Storage::fake('public');
        Notification::fake();
        $item = $this->terpasang();
        $l = $this->lapor($item);

        // Pelapor membatalkan laporannya sendiri selagi baru
        Livewire::test('pages::pemeliharaan.index')->call('batalkan', $l->id);
        $this->assertSame(StatusLaporan::Dibatalkan, $l->fresh()->status);

        // Pengusul lain tidak bisa melihat laporan orang lain; pengusul tidak bisa menugaskan
        $this->actingAs($this->user('desa@usuljalan.test'))->get(route('pemeliharaan', $l))->assertForbidden();
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])->call('buka', 'tugaskan')->assertForbidden();

        // Halaman per role
        $this->actingAs($this->user('warga@usuljalan.test'))->get(route('pemeliharaan', $l))->assertOk()->assertSee($l->nomor);
        $this->get(route('lapor'))->assertOk()->assertSee('Mau ajukan apa?');
        // Pilihan Usulan baru / Lapor kerusakan tetap ada di form Usulan agar bisa berpindah
        $this->get(route('usulan.baru'))->assertOk()->assertSee('Lapor kerusakan')->assertSee(route('lapor', ['jenis' => 'lapor']), false);
        $this->actingAs($this->user('dishub@usuljalan.test'))->get(route('pemeliharaan', $l))->assertOk()->assertSee('Laporan masuk');
        $this->get(route('lapor'))->assertForbidden();
        // Dishub: daftar seperti Usulan (bukan peta lapangan); peta lapangan hanya saat Peta di tab Ditugaskan/Perbaikan
        $this->get(route('pemeliharaan', ['tab' => 'semua']))->assertOk()->assertSee('Laporan masuk')->assertSee($l->nomor)->assertDontSeeHtml('petaTugas(');
        $this->get(route('pemeliharaan', ['tab' => 'ditugaskan', 'mode' => 'peta']))->assertOk()->assertSeeHtml('petaTugas(');
        $this->actingAs($this->user('bappeda@usuljalan.test'))->get(route('pemeliharaan'))->assertForbidden();
    }

    public function test_petugas_lapangan_hanya_melihat_dan_mengerjakan_tugasnya(): void
    {
        Storage::fake('public');
        Notification::fake();
        $item = $this->terpasang();
        $koordinator = $this->user('dishub@usuljalan.test');
        $petugas = $this->user('petugas@usuljalan.test');
        $lain = User::create(['name' => 'Dedi Lapangan', 'email' => 'dedi@usuljalan.test', 'role' => \App\Enums\Role::Dishub, 'petugas_lapangan' => true]);
        $this->assertTrue($petugas->isPetugasLapangan());
        $this->assertTrue($koordinator->isKoordinator());
        $this->assertSame('Dishub · Petugas lapangan', $lain->keteranganPeran());

        $milikSaya = $this->lapor($item);
        $milikLain = $this->lapor(PerlengkapanTerpasang::create(['jenis_perlengkapan_id' => $item->jenis_perlengkapan_id, 'jumlah' => 1, 'lat' => -3.46, 'lng' => 116.01]));
        $baru = $this->lapor(PerlengkapanTerpasang::create(['jenis_perlengkapan_id' => $item->jenis_perlengkapan_id, 'jumlah' => 1, 'lat' => -3.47, 'lng' => 116.02]));
        $this->actingAs($koordinator);
        $milikSaya->tugaskan($petugas);
        $milikLain->tugaskan($lain);

        // koordinator melihat semua, petugas pertama di daftar pilihan adalah petugas lapangan
        $this->assertSame(3, LaporanKerusakan::terlihatOleh($koordinator)->count());
        $this->assertTrue(LaporanKerusakan::petugasLapangan()->first()->petugas_lapangan);

        // petugas: hanya tugasnya, tanpa tab Baru
        $this->actingAs($petugas);
        $this->assertSame([$milikSaya->id], LaporanKerusakan::terlihatOleh($petugas)->pluck('id')->all());
        $this->get(route('pemeliharaan', ['tab' => 'semua']))->assertOk()->assertSee($milikSaya->nomor)->assertDontSee($milikLain->nomor)->assertDontSee($baru->nomor);
        // kabar lama dari tugas yang dialihkan: tidak dibuka, hanya pemberitahuan
        $this->get(route('pemeliharaan', $milikLain))->assertOk();
        Livewire::test('pages::pemeliharaan.index', ['laporan' => $milikLain])->assertSet('pilih', null)->assertDispatched('toast');
        $this->actingAs($this->user('desa@usuljalan.test'))->get(route('pemeliharaan', $milikLain))->assertForbidden();
        $this->actingAs($petugas);
        Livewire::test('pages::pemeliharaan.index')->assertSet('tab', 'ditugaskan')->assertDontSee('Baru');
        Livewire::test('pemeliharaan.tugas', ['tab' => 'ditugaskan'])->assertSee($milikSaya->nomor)->assertDontSee($milikLain->nomor);

        // petugas tidak bisa menugaskan / menolak; Cek Kondisi hanya untuk tugasnya
        Livewire::test('pemeliharaan.detail', ['laporanId' => $milikSaya->id])
            ->assertSee('Cek Kondisi')->assertDontSee('Tolak laporan')->assertDontSee('Ganti Petugas')
            ->call('buka', 'tugaskan')->assertForbidden();
        Livewire::test('pemeliharaan.detail', ['laporanId' => $milikSaya->id])->call('tolak')->assertForbidden();
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])->call('buka', $milikLain->id)->assertForbidden();
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])->call('buka', $milikSaya->id)->assertSet('sheet', 'cek');

        // koordinator tetap bisa cek kondisi laporan siapa pun
        $this->actingAs($koordinator);
        Livewire::test('pemeliharaan.tugas', ['tanpaPeta' => true])->call('buka', $milikLain->id)->assertSet('sheet', 'cek');
    }

    public function test_admin_mengatur_koordinator_atau_petugas_lapangan(): void
    {
        $this->actingAs($this->user('admin@usuljalan.test'));
        $rina = $this->user('dishub@usuljalan.test');
        $warga = $this->user('warga@usuljalan.test');

        Livewire::test('pages::pengaturan')->set('tab', 'pengguna')->assertSee('Dishub · Petugas lapangan')
            ->call('ubahPeran', $rina->id, 'dishub_lapangan');
        $this->assertTrue($rina->fresh()->isPetugasLapangan());
        Livewire::test('pages::pengaturan')->call('ubahPeran', $rina->id, 'dishub');
        $this->assertTrue($rina->fresh()->isKoordinator());
        Livewire::test('pages::pengaturan')->call('ubahPeran', $warga->id, 'dishub_lapangan');
        $this->assertTrue($warga->fresh()->isPetugasLapangan());
        Livewire::test('pages::pengaturan')->call('ubahPeran', $warga->id, 'kepala')->assertStatus(422);

        Livewire::test('pages::pengaturan')->call('buka', 'pengguna')
            ->set('form.name', 'Eko Lapangan')->set('form.email', 'eko@usuljalan.test')->set('form.role', 'dishub_lapangan')
            ->call('simpanDialog')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'eko@usuljalan.test')->first()->isPetugasLapangan());
    }

    public function test_admin_hanya_melihat_pemeliharaan(): void
    {
        Storage::fake('public');
        Notification::fake();
        $l = $this->lapor($this->terpasang());

        $this->actingAs($this->user('admin@usuljalan.test'));
        $this->get(route('pemeliharaan', $l))->assertOk()->assertSee($l->nomor);
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])
            ->assertSee('Admin hanya melihat')
            ->assertDontSee('Tugaskan Petugas')
            ->call('buka', 'tugaskan')->assertForbidden();
        Livewire::test('pemeliharaan.detail', ['laporanId' => $l->id])->call('tolak')->assertForbidden();
        $this->get(route('pemeliharaan', ['tab' => 'ditugaskan', 'mode' => 'peta']))->assertOk()->assertDontSeeHtml('petaTugas(');
        $this->assertSame(StatusLaporan::Baru, $l->fresh()->status);
    }

    public function test_tamu_melapor_tanpa_login_dan_dikabari_lewat_email(): void
    {
        Notification::fake();
        Storage::fake('public');
        $item = $this->terpasang();

        Livewire::test('pages::pemeliharaan.publik', ['item' => $item])
            ->call('kirim')
            ->assertHasErrors(['kerusakan', 'foto'])
            ->set('kerusakan', 'lainnya')
            ->set('foto', [UploadedFile::fake()->image('rusak.jpg')])
            ->call('kirim')
            ->assertHasErrors('catatan') // "Lainnya" wajib keterangan
            ->set('kerusakan', 'mati')
            ->set('nama', 'Siti Aminah')
            ->set('email', 'siti@contoh.id')
            ->call('kirim')
            ->assertHasNoErrors()
            ->assertRedirect(route('lapor.status', 'LPR-'.now()->year.'-00001'));

        $l = LaporanKerusakan::firstOrFail();
        $this->assertNull($l->user_id);
        $this->assertSame('Siti Aminah', $l->namaPelapor());
        $this->assertSame('siti@contoh.id', $l->kontak_email);
        $this->assertTrue($item->usulanItem->usulan->riwayat()->where('aksi', 'like', '%dilaporkan rusak%')->where('publik', true)->exists());
        Notification::assertSentTo($this->user('dishub@usuljalan.test'), LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_baru');

        $this->get(route('lapor.status', $l->nomor))->assertOk()->assertSee($l->nomor)->assertSee('Dicek petugas');
        session()->forget('laporan_baru'); // pesan "terkirim" (berisi email) hanya sekali, untuk pelapornya sendiri
        $this->get(route('lapor.status', $l->nomor))->assertDontSee('siti@contoh.id')->assertDontSee('Siti Aminah'); // tidak tampil ke publik
        $this->get(route('lacak', $l->nomor))->assertRedirect(route('lapor.status', $l->nomor));

        // pelapor tanpa akun dikabari lewat email saat ditugaskan
        $this->actingAs($this->user('dishub@usuljalan.test'));
        $l->tugaskan($this->user('petugas@usuljalan.test'));
        Notification::assertSentOnDemand(LaporanBerubah::class, fn ($n, $kanal, $tujuan) => $tujuan->routes['mail'] === 'siti@contoh.id' && $kanal === ['mail']);
        $this->get(route('pemeliharaan', $l))->assertOk()->assertSee('Pelapor tanpa akun');
    }

    public function test_satu_perlengkapan_satu_laporan_berjalan(): void
    {
        Notification::fake();
        Storage::fake('public');
        $item = $this->terpasang();
        $l = $this->lapor($item);

        // publik: form diganti "Sudah dilaporkan", kirim tidak membuat laporan baru
        auth()->logout();
        $this->get(route('lapor.aset', $item->id))->assertOk()->assertSee('Sudah dilaporkan')->assertSee($l->nomor);
        Livewire::test('pages::pemeliharaan.publik', ['item' => $item])
            ->set('kerusakan', 'rusak')->set('foto', [UploadedFile::fake()->image('a.jpg')])->call('kirim')
            ->assertRedirect(route('lapor.status', $l->nomor));
        $this->assertSame(1, LaporanKerusakan::count());
        $this->get('/')->assertOk()->assertSee('Dilaporkan rusak');

        // pengusul masuk: diarahkan ke form /lapor, dan tidak bisa melapor ganda
        $this->actingAs($this->user('desa@usuljalan.test'));
        Livewire::test('pages::pemeliharaan.publik', ['item' => $item])->assertRedirect(route('lapor', ['aset' => $item->id]));
        Livewire::test('pages::pemeliharaan.lapor')
            ->set('pilihan', 'lapor')
            ->call('pilih', $item->id)
            ->assertSee('Sudah dilaporkan')
            ->call('lanjut')
            ->assertStatus(422);
        $this->assertSame(1, LaporanKerusakan::count());
    }

    public function test_publik_dibatasi_ip_honeypot_dan_item_tidak_terpasang(): void
    {
        Notification::fake();
        Storage::fake('public');
        $a = $this->terpasang();
        $b = $a->replicate();
        $b->usulan_item_id = null; // perlengkapan lama (terpasang sebelum SIPERJAL)
        $b->save();
        config(['usuljalan.laporan_per_jam' => 1]);

        $kirim = fn ($item) => Livewire::test('pages::pemeliharaan.publik', ['item' => $item])
            ->set('kerusakan', 'hilang')->set('foto', [UploadedFile::fake()->image('a.jpg')])->call('kirim');
        $kirim($a)->assertHasNoErrors();
        $kirim($b)->assertHasErrors('kirim');
        $this->assertSame(1, LaporanKerusakan::count());

        // bot mengisi kolom jebakan → tidak tersimpan
        \Illuminate\Support\Facades\RateLimiter::clear('lapor:127.0.0.1');
        Livewire::test('pages::pemeliharaan.publik', ['item' => $b])
            ->set('situs', 'http://spam')->set('kerusakan', 'hilang')->set('foto', [UploadedFile::fake()->image('a.jpg')])->call('kirim')
            ->assertRedirect('/');
        $this->assertSame(1, LaporanKerusakan::count());

        $this->get(route('lapor.aset', $b->id))->assertOk();
        $this->get(route('lapor.aset', 99999))->assertNotFound();
        $this->get(route('lapor.item', 99999))->assertNotFound();
        $this->get(route('lapor.status', 'LPR-2026-99999'))->assertOk()->assertSee('Nomor tidak ditemukan');
    }

    public function test_lapor_di_titik_bebas_tanpa_perlengkapan_terpasang(): void
    {
        Notification::fake();
        Storage::fake('public');
        $warga = $this->user('warga@usuljalan.test');
        $kec = \App\Models\Kecamatan::with('desa')->whereHas('desa')->firstOrFail();
        $desa = $kec->desa->first();
        $jenis = JenisPerlengkapan::aktif()->firstOrFail();

        $this->actingAs($warga);
        Livewire::test('pages::pemeliharaan.lapor')
            ->set('pilihan', 'lapor')
            ->call('lanjut')
            ->assertHasErrors('lat')
            // tap peta: kecamatan & desa dicocokkan dari nama Nominatim
            ->call('aturLokasi', -3.5, 116.1, ['jalan' => 'Jl. Pelabuhan', 'kecamatan' => [$kec->nama], 'desa' => [$desa->nama]])
            ->assertSet('kecamatan_id', $kec->id)
            ->assertSet('desa_id', $desa->id)
            ->assertSet('kecamatanManual', false)
            ->call('lanjut')
            ->assertSet('langkah', 2)
            ->set('kerusakan', 'roboh')
            ->set('foto', [UploadedFile::fake()->image('roboh.jpg')])
            ->call('kirim')
            ->assertHasErrors('jenisId')
            ->set('jenisId', $jenis->id)
            ->call('kirim')
            ->assertHasNoErrors();

        $l = LaporanKerusakan::latest('id')->firstOrFail();
        $this->assertNull($l->usulan_item_id);
        $this->assertSame($jenis->id, $l->jenis_perlengkapan_id);
        $this->assertSame([-3.5, 116.1], [$l->lat(), $l->lng()]);
        $this->assertSame([$kec->id, $desa->id], [$l->kecamatan_id, $l->desa_id]);
        $this->assertSame('Jl. Pelabuhan', $l->lokasi());
        $this->assertStringStartsWith($jenis->namaPendek(), $l->judul());
        Notification::assertSentTo($this->user('dishub@usuljalan.test'), LaporanBerubah::class, fn ($n) => $n->jenis === 'kerusakan_baru');

        // Dishub menangani laporan tanpa item sampai selesai & urungkan
        $koordinator = $this->user('dishub@usuljalan.test');
        $this->actingAs($koordinator);
        $this->get(route('pemeliharaan', $l))->assertOk()->assertSee('Tidak tertaut data terpasang');
        $l->tugaskan($this->user('petugas@usuljalan.test'));
        $l->cek(true, [['file' => UploadedFile::fake()->image('cek.jpg')]], 'Tiang roboh');
        $l->selesai([['file' => UploadedFile::fake()->image('hasil.jpg')]]);
        $this->assertSame(StatusLaporan::Selesai, $l->fresh()->status);
        $l->fresh()->batalSelesai();
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->fresh()->status);

        $this->actingAs($warga)->get(route('pemeliharaan', $l))->assertOk()->assertSee($jenis->namaPendek());
        auth()->logout();
        $this->get(route('lapor.status', $l->nomor))->assertOk()->assertSee($jenis->namaPendek());
    }

    public function test_titik_dekat_perlengkapan_terpasang_bisa_ditautkan_dan_dilepas(): void
    {
        $item = $this->terpasang();
        $this->actingAs($this->user('warga@usuljalan.test'));
        $c = Livewire::test('pages::pemeliharaan.lapor')
            ->set('pilihan', 'lapor')
            ->call('aturLokasi', -3.4493, 116.0019, [])
            ->assertSet('kecamatanManual', true)
            ->assertSee('Perlengkapan terpasang di dekat titik ini')
            ->call('pilih', $item->id)
            ->assertSet('asetId', $item->id)
            ->assertSet('jenisId', $item->jenis_perlengkapan_id)
            ->assertSet('lat', -3.4492)
            ->call('lepas')
            ->assertSet('asetId', null)
            ->assertSet('jenisId', null);
        // titik jauh: tidak ada tawaran
        $c->call('aturLokasi', -3.6, 116.3, [])->assertDontSee('Perlengkapan terpasang di dekat titik ini');
    }

    public function test_data_versi_lama_laporan_rusak_dipindahkan(): void
    {
        $item = $this->terpasang();
        \Illuminate\Support\Facades\Schema::create('laporan_rusak', function ($t) {
            $t->id(); $t->string('nomor'); $t->foreignId('usulan_item_id'); $t->string('jenis'); $t->text('keterangan')->nullable();
            $t->string('nama_pelapor')->nullable(); $t->string('kontak_email')->nullable(); $t->decimal('lat', 10, 7)->nullable(); $t->decimal('lng', 10, 7)->nullable();
            $t->string('status'); $t->text('catatan_dishub')->nullable(); $t->foreignId('ditangani_oleh')->nullable();
            $t->timestamp('diproses_at')->nullable(); $t->timestamp('selesai_at')->nullable(); $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('laporan_rusak_foto', function ($t) {
            $t->id(); $t->foreignId('laporan_rusak_id'); $t->string('jenis'); $t->string('path'); $t->decimal('lat', 10, 7)->nullable(); $t->decimal('lng', 10, 7)->nullable(); $t->timestamps();
        });
        $id = \DB::table('laporan_rusak')->insertGetId(['nomor' => 'LPR-2026-00042', 'usulan_item_id' => $item->usulan_item_id, 'jenis' => 'miring', 'status' => 'diproses',
            'kontak_email' => 'lama@contoh.id', 'ditangani_oleh' => $this->user('dishub@usuljalan.test')->id, 'diproses_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('laporan_rusak_foto')->insert(['laporan_rusak_id' => $id, 'jenis' => 'laporan', 'path' => 'laporan/1/a.jpg', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_10_01_000002_pindahkan_laporan_rusak.php'))->up();

        $l = LaporanKerusakan::where('nomor', 'LPR-2026-00042')->firstOrFail();
        $this->assertSame(StatusLaporan::MenungguPerbaikan, $l->status);
        $this->assertSame('roboh', $l->kerusakan);
        $this->assertSame('lama@contoh.id', $l->kontak_email);
        $this->assertSame(['pelapor'], $l->foto->pluck('jenis')->all());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('laporan_rusak'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Panduan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanduanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_menu_dibangun_dari_folder_dan_berurut(): void
    {
        $menu = Panduan::menu();

        $this->assertSame(['umum', 'pengusul', 'dishub', 'bappeda', 'petugas-lapangan', 'admin'], $menu->pluck('grup')->all());
        $pengusul = $menu->firstWhere('grup', 'pengusul');
        $this->assertSame(['ajukan-usulan', 'pantau-usulan', 'perbaiki-revisi', 'lacak-dan-bagikan', 'lapor-kerusakan'], $pengusul['artikel']->pluck('slug')->all());
        $this->assertSame('Pengusul', $pengusul['label']);
        $this->assertSame('Petugas Lapangan', $menu->firstWhere('grup', 'petugas-lapangan')['label']);
    }

    public function test_artikel_diurai_menjadi_bagian_dan_komponen(): void
    {
        $a = Panduan::cari('pengusul', 'ajukan-usulan');
        $jenis = collect($a['bagian'])->pluck('jenis')->all();

        $this->assertSame(['intro', 'sebelum', 'langkah', 'langkah', 'langkah', 'langkah', 'selanjutnya'], $jenis);

        $langkah1 = collect($a['bagian'])->firstWhere('id', 'langkah-1');
        $gambar = collect($langkah1['blok'])->firstWhere('t', 'gambar');
        $this->assertCount(5, $gambar['penanda']);
        $this->assertSame(['x' => 83.3, 'y' => 29.9, 'teks' => 'Tambah foto kondisi jalan. Maksimal 10 foto.'], $gambar['penanda'][0]);
        $this->assertFalse($gambar['lebarLayar']);

        $callout = collect($langkah1['blok'])->where('t', 'callout')->pluck('label')->all();
        $this->assertSame(['TIPS', 'INFO'], $callout);
        $this->assertSame('PERHATIAN', collect(collect($a['bagian'])->firstWhere('id', 'langkah-3')['blok'])->firstWhere('t', 'callout')['label']);
    }

    public function test_gambar_biasa_markdown_menjadi_gambar_tanpa_penanda(): void
    {
        $dir = Panduan::folder().'/uji';
        @mkdir($dir);
        file_put_contents($dir.'/01-gambar.md', "---\njudul: Uji gambar\n---\n\n## 1. Langkah\n\n![Keterangan uji](../gambar/pengusul-ajukan-2.png)\n");

        try {
            $langkah = collect(Panduan::cari('uji', 'gambar')['bagian'])->firstWhere('id', 'langkah-1');
            $g = collect($langkah['blok'])->firstWhere('t', 'gambar');
            $this->assertSame('Keterangan uji', $g['ket']);
            $this->assertSame([], $g['penanda']);
            $this->assertFalse($g['lebarLayar']);
        } finally {
            @unlink($dir.'/01-gambar.md');
            @rmdir($dir);
        }
    }

    public function test_tautan_md_diarahkan_ke_halaman_panduan(): void
    {
        $a = Panduan::cari('pengusul', 'ajukan-usulan');
        $selanjutnya = collect($a['bagian'])->firstWhere('jenis', 'selanjutnya');
        $tujuan = collect($selanjutnya['blok'][0]['butir'])->pluck('tautan.slug')->all();

        $this->assertSame(['pantau-usulan', 'perbaiki-revisi'], $tujuan);
    }

    public function test_halaman_artikel_tampil_tanpa_login(): void
    {
        $this->get(route('panduan'))->assertOk()->assertSee('Meninjau usulan masuk');

        $this->get('/panduan/pengusul/ajukan-usulan')
            ->assertOk()
            ->assertSee('Mengajukan usulan perlengkapan jalan')
            ->assertSee('Sebelum mulai', false)
            ->assertSee('Ambil foto dan cek lokasi')
            ->assertSee('Tambah foto kondisi jalan.')
            ->assertSee('Apakah panduan ini membantu?');
    }

    public function test_artikel_dan_gambar_yang_tidak_ada_404(): void
    {
        $this->get('/panduan/pengusul/tidak-ada')->assertNotFound();
        $this->get('/panduan/gambar/tidak-ada.png')->assertNotFound();
        $this->get('/panduan/gambar/..%2F..%2F.env')->assertNotFound();
        $this->get('/panduan/gambar/pengusul-ajukan-1.png')->assertOk();
    }

    public function test_html_mentah_di_markdown_tidak_dirender(): void
    {
        $dir = Panduan::folder().'/uji';
        @mkdir($dir);
        file_put_contents($dir.'/01-xss.md', "---\njudul: Uji\n---\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n");

        try {
            $html = $this->get('/panduan/uji/xss')->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('href="javascript:', $html);
        } finally {
            @unlink($dir.'/01-xss.md');
            @rmdir($dir);
        }
    }

    public function test_setiap_artikel_lengkap_tautan_dan_gambarnya_valid(): void
    {
        $semua = Panduan::semua();
        $this->assertGreaterThanOrEqual(21, $semua->count());

        foreach ($semua as $meta) {
            $nama = $meta['grup'].'/'.$meta['slug'];
            $isi = file_get_contents($meta['berkas']);

            $this->assertNotSame('', $meta['ringkas'], "$nama: ringkas kosong");
            $this->assertNotNull($meta['diperbarui'], "$nama: diperbarui tidak valid");

            $artikel = Panduan::cari($meta['grup'], $meta['slug']);
            $this->assertNotEmpty(collect($artikel['bagian'])->where('jenis', 'langkah'), "$nama: tanpa langkah bernomor");

            preg_match_all('/\]\(([^)\s]+\.md)(?:#[^)]*)?\)/', $isi, $tautan);
            foreach ($tautan[1] as $href) {
                $this->assertNotNull(Panduan::cariDariTautan($href, $meta), "$nama: tautan rusak $href");
            }

            preg_match_all('/(?:\]\(|src:\s*)\.\.\/gambar\/([^)\s]+)/', $isi, $gambar);
            foreach ($gambar[1] as $berkas) {
                $this->assertFileExists(Panduan::folder().'/gambar/'.$berkas, "$nama: gambar hilang $berkas");
            }

            $this->get(route('panduan.artikel', [$meta['grup'], $meta['slug']]))->assertOk();
        }
    }

    public function test_frontmatter_layar_dibaca_sebagai_daftar_dan_nama_layarnya_dikenal(): void
    {
        [$meta] = Panduan::pisah("---\njudul: X\nlayar: [usulan-masuk, \"siap-dipasang\"]\nurutan: 2\n---\nisi");
        $this->assertSame(['usulan-masuk', 'siap-dipasang'], $meta['layar']);
        $this->assertSame('2', $meta['urutan']);

        foreach (Panduan::semua() as $a) {
            foreach ($a['layar'] as $layar) {
                $this->assertArrayHasKey($layar, Panduan::LAYAR, "{$a['grup']}/{$a['slug']}: layar tak dikenal $layar");
            }
        }
    }

    public function test_layar_dikenali_dari_rute_peran_dan_tab(): void
    {
        $dishub = User::where('email', 'dishub@usuljalan.test')->first();
        $bappeda = User::where('email', 'bappeda@usuljalan.test')->first();
        $admin = User::where('email', 'admin@usuljalan.test')->first();
        $warga = User::where('email', 'warga@usuljalan.test')->first();

        $this->assertSame('usulan-masuk', Panduan::layarDari($dishub, 'usulan', []));
        $this->assertSame('siap-dipasang', Panduan::layarDari($dishub, 'usulan', ['tab' => 'pasang']));
        $this->assertSame('keputusan', Panduan::layarDari($bappeda, 'usulan', []));
        $this->assertSame('pagu-ditunda', Panduan::layarDari($bappeda, 'usulan', ['tab' => 'ditunda']));
        $this->assertSame('usulan-saya', Panduan::layarDari($warga, 'usulan', []));
        $this->assertSame('laporan-saya', Panduan::layarDari($warga, 'pemeliharaan', []));
        $this->assertSame('pemeliharaan', Panduan::layarDari($dishub, 'pemeliharaan', []));
        $this->assertSame('pengaturan-ssh', Panduan::layarDari($admin, 'pengaturan', []));
        $this->assertSame('pengaturan-wilayah', Panduan::layarDari($admin, 'pengaturan', ['tab' => 'wilayah']));
        $this->assertSame('ajukan-usulan', Panduan::layarDari($warga, 'usulan.baru', []));
        $this->assertNull(Panduan::layarDari($warga, 'tidak-ada', []));
    }

    public function test_menu_bantuan_memberi_panduan_untuk_layar_yang_dibuka(): void
    {
        $dishub = User::where('email', 'dishub@usuljalan.test')->first();

        $this->actingAs($dishub)
            ->getJson(route('panduan.menu', ['rute' => 'usulan']))
            ->assertOk()
            ->assertJsonPath('layar', 'usulan-masuk')
            ->assertJsonPath('nama', 'Usulan masuk')
            ->assertJsonPath('artikel.0.judul', 'Meninjau usulan masuk')
            ->assertJsonPath('artikel.0.url', route('panduan.artikel', ['dishub', 'meninjau-usulan']));

        $json = $this->actingAs($dishub)->getJson(route('panduan.menu', ['rute' => 'usulan', 'tab' => 'pasang']))->json();
        $this->assertSame('siap-dipasang', $json['layar']);
        $this->assertStringContainsString('langkah', $json['artikel'][0]['ket']);

        $this->actingAs($dishub)->getJson(route('panduan.menu', ['rute' => 'tidak-ada']))
            ->assertOk()->assertJsonPath('artikel', []);
    }

    public function test_menu_bantuan_hanya_untuk_yang_masuk(): void
    {
        auth()->logout();
        $this->getJson(route('panduan.menu', ['rute' => 'usulan']))->assertUnauthorized();
    }

    public function test_tombol_bantuan_ada_di_layar_aplikasi_dan_artikel_punya_pratinjau_tautan(): void
    {
        $this->actingAs(User::where('email', 'dishub@usuljalan.test')->first())
            ->get(route('usulan'))->assertOk()->assertSee('Panduan dan bantuan');

        auth()->logout();
        $this->get('/panduan/pengusul/perbaiki-revisi')->assertOk()
            ->assertSee('property="og:title" content="Memperbaiki usulan yang diminta revisi"', false)
            ->assertSee('property="og:description" content="Panduan · 2 menit"', false)
            ->assertSee('property="og:image" content="'.route('panduan.gambar', 'revisi-2-form.png').'"', false);
    }

    public function test_kabar_revisi_menyertakan_tautan_panduan(): void
    {
        $tautan = Panduan::tautanKabar('revisi');
        $this->assertSame(route('panduan.artikel', ['pengusul', 'perbaiki-revisi']), $tautan['url']);
        $this->assertSame('2 menit', $tautan['durasi']);
        $this->assertNull(Panduan::tautanKabar('terpasang'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LaporanKerusakan;
use App\Models\Pemeliharaan;
use App\Models\PerlengkapanTerpasang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\TestCase;

class AsetExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function masuk(string $akun): static
    {
        return $this->actingAs(User::where('email', "{$akun}@usuljalan.test")->firstOrFail());
    }

    private function aset(array $attr = []): PerlengkapanTerpasang
    {
        return PerlengkapanTerpasang::create($attr + [
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id'), 'jumlah' => 1,
            'lat' => -3.45, 'lng' => 116.01, 'kecamatan_id' => Kecamatan::where('nama', 'Batulicin')->value('id'),
            'terpasang_at' => '2026-08-14', 'alamat' => 'Jl. Raya Batulicin',
        ]);
    }

    /** Isi berkas unduhan sebagai larik baris. */
    private function baca($res, string $ext): array
    {
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'aset').".{$ext}";
        copy($res->baseResponse->getFile()->getPathname(), $tmp);
        $rows = SimpleExcelReader::create($tmp)->getRows()->toArray();
        @unlink($tmp);

        return $rows;
    }

    public function test_akses(): void
    {
        $this->get(route('terpasang.unduh'))->assertRedirect(route('login'));
        $this->post(route('terpasang.label'), ['ids' => [1], 'ukuran' => 'sedang'])->assertRedirect(route('login'));
        foreach (['desa', 'warga', 'bappeda'] as $akun) {
            $this->masuk($akun)->get(route('terpasang.unduh'))->assertForbidden();
            $this->masuk($akun)->post(route('terpasang.label'), ['ids' => [1], 'ukuran' => 'sedang'])->assertForbidden();
        }
        foreach (['dishub', 'admin', 'petugas'] as $akun) {
            $this->masuk($akun)->get(route('terpasang.unduh'))->assertOk();
        }
    }

    public function test_unduh_mengikuti_filter_dan_isi_kolom(): void
    {
        PerlengkapanTerpasang::query()->delete();
        $a = $this->aset(['alamat' => '=CMD()']);
        $b = $this->aset(['usulan_item_id' => null, 'kecamatan_id' => Kecamatan::where('nama', 'Karang Bintang')->value('id')]);

        $rows = $this->baca($this->masuk('dishub')->get(route('terpasang.unduh', ['format' => 'csv'])), 'csv');
        $this->assertCount(2, $rows);
        $r = collect($rows)->firstWhere('Kode register', $a->fresh()->kode);
        $this->assertSame('-3.45, 116.01', $r['Koordinat']);
        $this->assertSame('Sebelum SIPERJAL', $r['Sumber']);
        $this->assertSame('Baik', $r['Status']);
        $this->assertSame('1 unit', $r['Jumlah']);
        $this->assertSame("'=CMD()", $r['Alamat']);

        $rows = $this->baca($this->masuk('admin')->get(route('terpasang.unduh', ['format' => 'csv', 'kecamatan' => $b->kecamatan_id])), 'csv');
        $this->assertCount(1, $rows);
        $this->assertSame($b->fresh()->kode, $rows[0]['Kode register']);

        $rows = $this->baca($this->masuk('dishub')->get(route('terpasang.unduh', ['format' => 'csv', 'sumber' => 'usulan'])), 'csv');
        $this->assertCount(0, $rows);
    }

    public function test_xlsx_bawaan_berisi_tautan_maps_dan_status_rusak(): void
    {
        PerlengkapanTerpasang::query()->delete();
        $a = $this->aset();
        $this->aset();
        LaporanKerusakan::buat(['aset' => $a], User::where('email', 'dishub@usuljalan.test')->first(), 'mati', null, []);

        $res = $this->masuk('dishub')->get(route('terpasang.unduh', ['kondisi' => 'rusak']));
        $this->assertStringContainsString('perlengkapan-terpasang-'.now()->format('Y-m-d').'.xlsx', $res->headers->get('content-disposition'));
        $zip = new \ZipArchive;
        $zip->open($res->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('HYPERLINK(&quot;https://www.google.com/maps?q=-3.45,116.01&quot;', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $rows = $this->baca($res, 'xlsx');
        $this->assertCount(1, $rows);
        $this->assertSame('Dilaporkan rusak', $rows[0]['Status']);
    }

    public function test_riwayat_pemeliharaan(): void
    {
        PerlengkapanTerpasang::query()->delete();
        $a = $this->aset();
        Pemeliharaan::catat($a, 'pembersihan', 'Dibersihkan', '2026-10-01', [], User::where('email', 'petugas@usuljalan.test')->first());

        $rows = $this->baca($this->masuk('admin')->get(route('terpasang.unduh', ['data' => 'riwayat', 'format' => 'csv'])), 'csv');
        $this->assertCount(1, $rows);
        $this->assertSame('Rutin', $rows[0]['Sumber']);
        $this->assertSame($a->fresh()->kode, $rows[0]['Kode register']);

        $this->baca($this->masuk('admin')->get(route('terpasang.unduh', ['data' => 'riwayat', 'format' => 'csv', 'sumber' => 'laporan'])), 'csv');
        $pdf = $this->masuk('dishub')->get(route('terpasang.unduh', ['data' => 'riwayat', 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->masuk('dishub')->get(route('terpasang.unduh', ['format' => 'pdf']))->assertNotFound();
    }

    public function test_label_qr(): void
    {
        PerlengkapanTerpasang::query()->delete();
        $ada = $this->aset();
        $tanpaKode = $this->aset(['kecamatan_id' => null]);
        $this->assertNull($tanpaKode->kode);

        foreach (['sedang', 'kecil'] as $ukuran) {
            $res = $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => [$ada->id, $tanpaKode->id, 99999], 'ukuran' => $ukuran]);
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'));
        }

        $this->masuk('dishub')->from('/terpasang')->post(route('terpasang.label'), ['ids' => [$tanpaKode->id], 'ukuran' => 'sedang'])
            ->assertRedirect('/terpasang')->assertSessionHasErrors('ids');
        $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => [99999], 'ukuran' => 'sedang'])->assertSessionHasErrors('ids');
        $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => [], 'ukuran' => 'sedang'])->assertSessionHasErrors('ids');
        $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => [$ada->id], 'ukuran' => 'raksasa'])->assertSessionHasErrors('ukuran');
    }

    public function test_label_maksimal_200(): void
    {
        $ids = range(1, 201);
        $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => $ids, 'ukuran' => 'kecil'])
            ->assertSessionHasErrors(['ids' => 'Maksimal 200 label sekali unduh.']);

        PerlengkapanTerpasang::query()->delete();
        foreach (range(1, 3) as $_) {
            $this->aset();
        }
        $this->masuk('dishub')->post(route('terpasang.label'), ['ids' => PerlengkapanTerpasang::pluck('id')->all(), 'ukuran' => 'kecil'])->assertOk();
    }

    public function test_label_banyak_unit_melewati_satu_lembar_tanpa_gagal(): void
    {
        $this->masuk('dishub');
        $jenis = JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id');
        $kec = Kecamatan::where('nama', 'Batulicin')->value('id');
        $ids = collect(range(1, 30))->map(fn () => PerlengkapanTerpasang::create(['jenis_perlengkapan_id' => $jenis, 'jumlah' => 1, 'lat' => -3.45, 'lng' => 116.01, 'kecamatan_id' => $kec, 'terpasang_at' => '2026-08-14'])->id)->all();

        $res = $this->post(route('terpasang.label'), ['ids' => $ids, 'ukuran' => 'sedang']);
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $res->getContent())); // 30 label sedang = 2 lembar (24 + 6)
    }
}

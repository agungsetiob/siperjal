<?php

namespace Database\Seeders;

use App\Enums\StatusLaporan as SL;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\LaporanKerusakan;
use App\Models\User;
use App\Models\PerlengkapanTerpasang;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** Contoh laporan kerusakan (pemeliharaan) di tiap status, tanpa mengirim notifikasi. */
class LaporanDemoSeeder extends Seeder
{
    public function run(): void
    {
        $warga = User::where('email', 'warga@usuljalan.test')->first();
        $desa = User::where('email', 'desa@usuljalan.test')->first();
        $koordinator = User::where('email', 'dishub@usuljalan.test')->first();
        $petugas = User::where('email', 'petugas@usuljalan.test')->first() ?? $koordinator;
        $foto = glob(database_path('seeders/foto/lokasi-*.jpg'));
        $hasil = glob(database_path('seeders/foto/hasil-*.jpg'));
        $items = PerlengkapanTerpasang::with('jenis')->orderBy('id')->get();

        $skenario = [
            [SL::Baru, 'mati', 'Mati sejak Sabtu malam. Ruas ini gelap total setelah magrib, kemarin ada motor jatuh.', $warga, 0],
            [SL::Baru, 'roboh', 'Tiangnya miring ke jalan setelah hujan deras.', $desa, 1],
            [SL::Ditugaskan, 'pudar', null, $warga, 3],
            [SL::MenungguPerbaikan, 'rusak', 'Pelat penyok, tulisan tidak terbaca.', $warga, 6],
            [SL::MenungguPerbaikan, 'roboh', null, $desa, 12],
            [SL::Selesai, 'mati', 'Lampu kedip-kedip lalu mati.', $warga, 20],
        ];

        foreach ($skenario as $n => [$status, $kerusakan, $catatan, $pelapor, $hari]) {
            $item = $items->count() ? $items[($n * 2) % $items->count()] : null;
            if (! $item) {
                return;
            }
            // jenis kerusakan yang masuk akal untuk jenis perlengkapannya
            $nama = $item->jenis->nama;
            $kerusakan = match (true) {
                str_contains($nama, 'Lampu') || str_contains($nama, 'Traffic light') => 'mati',
                str_contains($nama, 'Marka') || str_contains($nama, 'ZoSS') || str_contains($nama, 'Pita') => 'pudar',
                str_contains($nama, 'Rambu') || str_contains($nama, 'RPPJ') || str_contains($nama, 'Patok') => $kerusakan === 'mati' ? 'roboh' : $kerusakan,
                default => $kerusakan === 'mati' || $kerusakan === 'pudar' ? 'rusak' : $kerusakan,
            };
            $catatan = $catatan ? [
                'mati' => 'Mati sejak Sabtu malam. Ruas ini gelap total setelah magrib, kemarin ada motor jatuh.',
                'roboh' => 'Tiangnya miring ke jalan setelah hujan deras.',
                'pudar' => 'Sudah hampir tidak kelihatan, apalagi malam hari.',
                'rusak' => 'Penyok tertabrak truk, sebagian lepas.',
            ][$kerusakan] : null;
            $dibuat = now()->subDays($hari)->subHours(2 + $n);
            $t = LaporanKerusakan::tempatDari($item);
            $l = LaporanKerusakan::create([
                'nomor' => sprintf('LPR-%d-%05d', now()->year, LaporanKerusakan::count() + 1),
                'usulan_item_id' => $item->usulan_item_id,
                'perlengkapan_terpasang_id' => $item->id,
                'jenis_perlengkapan_id' => $t['jenis_id'],
                'lat' => $t['lat'],
                'lng' => $t['lng'],
                'alamat' => $t['alamat'],
                'kecamatan_id' => $t['kecamatan_id'],
                'desa_id' => $t['desa_id'],
                'user_id' => $pelapor->id,
                'kerusakan' => $kerusakan,
                'catatan' => $catatan,
                'status' => $status,
            ]);
            $l->forceFill(['created_at' => $dibuat, 'updated_at' => $dibuat])->saveQuietly();
            $this->salin($l, $foto[$n % count($foto)], 'pelapor', $dibuat);

            if ($status === SL::Baru) {
                continue;
            }
            $l->forceFill(['petugas_id' => $petugas->id, 'ditugaskan_oleh' => $koordinator->id, 'ditugaskan_at' => $dibuat->copy()->addHours(3),
                'catatan_tugas' => $n === 2 ? 'Pelapor bisa dihubungi kalau perlu ditunjukkan titiknya.' : null])->saveQuietly();
            if ($status === SL::Ditugaskan) {
                continue;
            }
            $dicek = $dibuat->copy()->addDay();
            $l->forceFill(['dicek_at' => $dicek, 'catatan_cek' => 'Kerusakan sesuai laporan, perlu diganti.'])->saveQuietly();
            $this->salin($l, $foto[($n + 3) % count($foto)], 'cek', $dicek);
            if ($status === SL::Selesai) {
                $selesai = $dicek->copy()->addDays(5);
                $l->forceFill(['selesai_at' => $selesai, 'ditutup_oleh' => $petugas->id])->saveQuietly();
                $this->salin($l, $hasil[$n % count($hasil)], 'hasil', $selesai);
            }
        }

        // laporan di titik bebas: perlengkapan lama yang tidak tercatat terpasang lewat aplikasi
        $kec = Kecamatan::with('desa')->first();
        [$lat, $lng] = config('usuljalan.peta_awal');
        $l = LaporanKerusakan::create([
            'nomor' => sprintf('LPR-%d-%05d', now()->year, LaporanKerusakan::count() + 1),
            'jenis_perlengkapan_id' => JenisPerlengkapan::where('nama', 'like', 'Rambu%')->value('id') ?? JenisPerlengkapan::value('id'),
            'lat' => $lat + 0.006,
            'lng' => $lng - 0.004,
            'alamat' => 'Simpang tiga dekat pasar',
            'kecamatan_id' => $kec?->id,
            'desa_id' => $kec?->desa->first()?->id,
            'user_id' => $warga->id,
            'kerusakan' => 'hilang',
            'catatan' => 'Rambu lama di simpang ini sudah tidak ada, tinggal tiangnya.',
            'status' => SL::Baru,
        ]);
        $dibuat = now()->subDays(1);
        $l->forceFill(['created_at' => $dibuat, 'updated_at' => $dibuat])->saveQuietly();
        $this->salin($l, $foto[0], 'pelapor', $dibuat);
    }

    private function salin(LaporanKerusakan $l, string $sumber, string $jenis, $waktu): void
    {
        $path = "laporan/{$l->id}/".uniqid($jenis.'-').'.jpg';
        Storage::disk('public')->put($path, file_get_contents($sumber));
        $l->foto()->create(['jenis' => $jenis, 'path' => $path, 'lat' => $l->lat(), 'lng' => $l->lng()])
            ->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->saveQuietly();
    }
}

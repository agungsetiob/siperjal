<?php

namespace Database\Seeders;

use App\Enums\StatusItem as SI;
use App\Enums\StatusUsulan as SU;
use App\Models\Desa;
use App\Models\JenisPerlengkapan;
use App\Models\User;
use App\Models\Usulan;
use Illuminate\Database\Seeder;

/** Data contoh untuk presentasi: `php artisan db:seed --class=DemoSeeder` */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $warga = User::where('email', 'warga@usuljalan.test')->first();
        $desa = User::where('email', 'desa@usuljalan.test')->first();
        $dishub = User::where('email', 'dishub@usuljalan.test')->first();
        $bappeda = User::where('email', 'bappeda@usuljalan.test')->first();
        \App\Models\Pengaturan::simpan('pagu_'.now()->year, 2_500_000_000);
        \App\Models\Pengaturan::simpan('pagu_'.(now()->year + 1), 2_500_000_000);
        $jenis = JenisPerlengkapan::all();
        $alamat = ['Simpang Empat Pasar Batulicin', 'Depan SDN Kersik Putih', 'Tikungan Jl. Lingkar Batulicin', 'Jl. Raya Batulicin', 'Pertigaan Gunung Tinggi', 'Jembatan Sungai Batulicin', 'Jl. Dharma Praja km 2', 'Depan Puskesmas Simpang Empat', 'Tanjakan Segumbang', 'Gerbang Desa Polewali Marajae'];
        $skenario = [SU::Terpasang, SU::Terpasang, SU::Terpasang, SU::Terpasang, SU::Terpasang, SU::Terpasang, SU::Disetujui, SU::Disetujui, SU::MenungguBappeda, SU::MenungguBappeda, SU::Diperiksa, SU::Diperiksa, SU::Diperiksa, SU::Ditolak, SU::Ditunda, SU::Ditunda];

        foreach ($skenario as $n => $akhir) {
            $d = Desa::inRandomOrder()->first();
            $diajukan = $akhir === SU::Ditunda ? now()->subDays(20 + $n) : now()->subDays(60 - $n * 4);
            $u = Usulan::create([
                'user_id' => ($n % 3 ? $warga : $desa)->id,
                'instansi_id' => $n % 3 ? null : $desa->instansi_id,
                'lat' => -3.45 + (mt_rand(-400, 400) / 10000),
                'lng' => 115.985 + (mt_rand(-200, 200) / 10000),
                'alamat' => $alamat[$n % count($alamat)],
                'kecamatan_id' => $d->kecamatan_id,
                'desa_id' => $d->id,
                'kriteria_klaim' => ['sekolah', 'kecelakaan'],
                'status' => SU::Draft,
            ]);
            foreach ($jenis->random(mt_rand(1, 3)) as $j) {
                $u->items()->create(['jenis_perlengkapan_id' => $j->id, 'jumlah' => mt_rand(1, 4)]);
            }
            $u->kirim();
            $u->update(['diajukan_at' => $diajukan]);
            $u->riwayat()->create(['aksi' => 'Usulan diajukan', 'created_at' => $diajukan]);

            if ($akhir === SU::Diperiksa) {
                continue;
            }
            $tolakSemua = $akhir === SU::Ditolak;
            $u->items->each(fn ($i, $k) => $i->update([
                'status' => $tolakSemua || $k === 2 ? SI::DitolakDishub : SI::Lolos,
                'jumlah_disetujui' => $i->jumlah,
                'catatan_dishub' => $tolakSemua || $k === 2 ? 'Bukan kewenangan Dishub kabupaten' : null,
            ]));
            $u->update(['skor' => $tolakSemua ? 0 : 3, 'kriteria_dishub' => $tolakSemua ? [] : ['sekolah', 'kecelakaan', 'padat'], 'diperiksa_at' => $diajukan->copy()->addDays(6)]);
            $u->riwayat()->create(['aksi' => 'Diperiksa Dishub · skor '.($tolakSemua ? 0 : 3), 'user_id' => $dishub->id, 'created_at' => $diajukan->copy()->addDays(6)]);

            if (in_array($akhir, [SU::Disetujui, SU::Terpasang, SU::Ditunda])) {
                foreach ($u->items()->where('status', SI::Lolos)->get() as $k => $i) {
                    $tunda = $akhir === SU::Ditunda || ($k === 1 && $akhir === SU::Disetujui);
                    $i->update([
                        'status' => $tunda ? SI::Ditunda : SI::Disetujui,
                        'tahun_anggaran' => $tunda ? null : now()->year,
                        'tahun_target' => $tunda ? now()->year + ($n % 2 ? 2 : 1) : null,
                        'catatan_bappeda' => $tunda ? 'Pagu '.now()->year.' habis' : null,
                        'harga_satuan' => $tunda ? null : $i->jenis->harga_satuan,
                        'diputuskan_at' => $diajukan->copy()->addDays(15),
                        'diputuskan_oleh' => $bappeda->id,
                    ]);
                    if ($akhir === SU::Terpasang) {
                        $i->update(['status' => SI::Terpasang, 'jumlah_terpasang' => $i->jumlahEfektif(), 'terpasang_at' => $diajukan->copy()->addDays(30),
                            'lat_terpasang' => $u->lat + $k * 0.0004, 'lng_terpasang' => $u->lng + $k * 0.0004]);
                        $i->sinkronAset();
                    }
                }
                $u->riwayat()->create(['aksi' => 'Diputuskan Bappeda', 'user_id' => $bappeda->id, 'created_at' => $diajukan->copy()->addDays(15)]);
            }
            $u->hitungStatus();
        }

        // perlengkapan yang terpasang sebelum SIPERJAL (tanpa usulan)
        foreach (range(1, 8) as $n) {
            $d = Desa::inRandomOrder()->first();
            $j = $jenis->random();
            \App\Models\PerlengkapanTerpasang::create([
                'jenis_perlengkapan_id' => $j->id,
                'jumlah' => mt_rand(1, 3),
                'lat' => -3.45 + (mt_rand(-400, 400) / 10000),
                'lng' => 115.985 + (mt_rand(-200, 200) / 10000),
                'alamat' => $alamat[($n + 3) % count($alamat)],
                'kecamatan_id' => $d->kecamatan_id,
                'desa_id' => $d->id,
                'tahun' => mt_rand(2015, 2024),
            ]);
        }

        $this->call(FotoDemoSeeder::class);
        $this->call(LaporanDemoSeeder::class);
    }
}

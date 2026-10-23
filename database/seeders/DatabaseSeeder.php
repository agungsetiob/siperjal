<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Desa;
use App\Models\Instansi;
use App\Models\JenisPerlengkapan;
use App\Models\Kecamatan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $jenis = [
            ['Rambu lalu lintas', 'Rambu peringatan, larangan, perintah', 'triangle-alert', 'unit', 1_750_000, '#4556A9'],
            ['Marka jalan', 'Cat thermoplastik, garis tengah/tepi, zebra cross', 'align-center-vertical', 'meter', 85_000, '#0E9F9A'],
            ['Lampu penerangan jalan', 'Tiang + lampu LED', 'lightbulb', 'titik', 9_500_000, '#D98E04'],
            ['Lampu peringatan', 'Warning light kuning berkedip, tenaga surya', 'siren', 'unit', 6_500_000, '#EA580C'],
            ['Traffic light', 'Alat pemberi isyarat lalu lintas 3 aspek', 'traffic-cone', 'unit', 185_000_000, '#16A34A'],
            ['Pagar pengaman (guardrail)', 'Pagar pengaman baja', 'fence', 'meter', 650_000, '#000100'],
            ['Patok tikungan (delineator)', 'Patok pemantul cahaya di tikungan', 'milestone', 'unit', 350_000, '#9333EA'],
            ['ZoSS (Zona Selamat Sekolah)', 'Paket marka, rambu, dan pita di depan sekolah', 'school', 'lokasi', 45_000_000, '#E0527A'],
            ['Pita getar', 'Pita penggaduh 3 lajur', 'audio-waveform', 'set', 4_500_000, '#2563EB'],
            ['Kerucut lalu lintas', 'Kerucut 70 cm dengan pemantul', 'cone', 'unit', 150_000, '#F59E0B'],
            ['Traffic barrier', 'Pembatas jalan plastik/beton', 'construction', 'unit', 1_200_000, '#475569'],
            ['RPPJ (rambu penunjuk jurusan)', 'Rambu pendahuluan petunjuk jurusan', 'signpost', 'unit', 22_000_000, '#0891B2'],
        ];
        foreach ($jenis as $i => [$nama, $deskripsi, $ikon, $satuan, $harga, $warna]) {
            JenisPerlengkapan::create(compact('nama', 'deskripsi', 'ikon', 'satuan', 'warna') + ['harga_satuan' => $harga, 'urutan' => $i]);
        }
        \App\Models\Pengaturan::simpan('tahun_ssh', now()->year);

        // Contoh wilayah; data asli diimpor admin lewat Pengaturan.
        $wilayah = [
            'Batulicin' => ['Batulicin', 'Kersik Putih', 'Gunung Tinggi', 'Polewali Marajae', 'Segumbang'],
            'Simpang Empat' => ['Tungkaran Pangeran', 'Sungai Dua', 'Barokah', 'Hidayah Makmur'],
            'Kusan Hilir' => ['Kota Pagatan', 'Juku Eja', 'Pejala', 'Satiung'],
            'Karang Bintang' => ['Karang Bintang', 'Manuntung', 'Rejosari', 'Pandan Sari'],
        ];
        foreach ($wilayah as $kec => $desa) {
            $k = Kecamatan::create(['nama' => $kec]);
            foreach ($desa as $d) {
                Desa::create(['kecamatan_id' => $k->id, 'nama' => $d]);
            }
        }

        $desaKersik = Instansi::create(['nama' => 'Pemerintah Desa Kersik Putih', 'jenis' => 'desa', 'kecamatan_id' => 1]);

        User::create(['name' => 'Admin SIPERJAL', 'email' => 'admin@usuljalan.test', 'role' => Role::Admin, 'password' => 'password']);
        User::create(['name' => 'Rina Dishub', 'email' => 'dishub@usuljalan.test', 'role' => Role::Dishub, 'jabatan' => 'Pemeriksa', 'no_hp' => '081200000003']);
        User::create(['name' => 'Ahmad Fauzi', 'email' => 'petugas@usuljalan.test', 'role' => Role::Dishub, 'petugas_lapangan' => true, 'jabatan' => 'Petugas lapangan', 'no_hp' => '081200000005']);
        User::create(['name' => 'Bima Bappeda', 'email' => 'bappeda@usuljalan.test', 'role' => Role::Bappeda, 'jabatan' => 'Perencana Anggaran', 'no_hp' => '081200000004']);
        User::create(['name' => 'Sekdes Kersik Putih', 'email' => 'desa@usuljalan.test', 'role' => Role::Pengusul, 'instansi_id' => $desaKersik->id, 'no_hp' => '081200000001']);
        User::create(['name' => 'Budi Santoso', 'email' => 'warga@usuljalan.test', 'role' => Role::Pengusul, 'no_hp' => '081200000002']);
    }
}

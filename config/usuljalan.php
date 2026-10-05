<?php

return [
    'radius_berdekatan' => 100,      // meter, peringatan usulan berdekatan
    'jarak_foto_maks' => 200,        // meter, foto dianggap jauh dari pin
    'jarak_pasang_maks' => 500,      // meter, GPS pemasangan lebih jauh dari ini → pakai titik usulan
    'maks_foto' => 10,
    'laporan_maks_foto' => 3,       // foto per laporan kerusakan (publik)
    'laporan_per_jam' => 5,         // batas laporan kerusakan per IP per jam
    'batas_hari_periksa' => 7,      // label merah di antrian Dishub
    'peta_awal' => [-3.4489, 116.0022], // Batulicin, Tanah Bumbu (ganti sesuai daerah)
    // Titik awal rute pemasangan (gudang/UPTD Dishub)
    'gudang' => [
        'nama' => env('USULJALAN_GUDANG', 'Gudang Dishub'),
        'lat' => (float) env('USULJALAN_GUDANG_LAT', -3.4844),
        'lng' => (float) env('USULJALAN_GUDANG_LNG', 115.9860),
        'jam' => '08.00',
    ],
    'kontak_telepon' => env('USULJALAN_TELEPON', '(0518) 123 456'),
    'kontak_wa' => env('USULJALAN_WA', '0812-3456-7890'),
    'pemda' => env('USULJALAN_PEMDA', 'Kabupaten Tanah Bumbu'),
    // Kanal WhatsApp belum tersambung ke gateway; saat false, pilihan WA di profil tampil "segera tersedia"
    'whatsapp_aktif' => (bool) env('USULJALAN_WA_AKTIF', false),
];

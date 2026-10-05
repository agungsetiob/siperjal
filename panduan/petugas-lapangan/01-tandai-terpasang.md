---
judul: Menandai perlengkapan terpasang
ringkas: Ikuti rute pemasangan di HP, foto hasilnya, dan catat perlengkapan sebagai terpasang.
peran: Petugas Lapangan
urutan: 1
layar: [siap-dipasang]
durasi: 3 menit
perangkat: HP
diperbarui: 2026-10-04
---

Petugas lapangan adalah pengguna Dishub yang membuka aplikasi di HP. Mode lapangan menyusun rute pemasangan, lalu mencatat setiap pemasangan lengkap dengan foto dan lokasi.

## Sebelum mulai

- Izinkan aplikasi memakai **kamera** dan **lokasi**.
- Pastikan ada perlengkapan berstatus **Disetujui**. Itu yang tampil di tab **Siap Dipasang**.

## 1. Buka rute hari ini

Buka **Usulan**, pilih tab **Siap Dipasang**, lalu ketuk **Peta**. Bilah di atas peta menampilkan "Rute hari ini" beserta jumlah titik dan perkiraan jaraknya.

```gambar
src: ../gambar/lapangan-rute.png
keterangan: Peta rute pemasangan di HP
penanda:
  - [75.6, 26.1, "Rute hari ini: jumlah titik dan perkiraan jarak."]
  - [82.1, 33.2, "Posisimu sekarang."]
  - [82.1, 46.0, "Geser kartu di bawah untuk pindah ke titik lain."]
  - [17.9, 79.1, "Tombol arah: buka Google Maps."]
  - [76.9, 79.1, "Tandai Terpasang setelah selesai memasang."]
```

- Titik diurutkan otomatis dari yang terdekat.
- Rute dimulai dari posisi HP-mu, selama masih dalam 50 km dari gudang. Kalau lebih jauh, rute dimulai dari Gudang Dishub.
- Jarak dan waktu hanya perkiraan: jarak garis lurus, kecepatan 25 km/jam, ditambah 20 menit untuk tiap titik.
- Ketuk tombol **Lokasi saya** (ikon target di pojok kanan atas peta) untuk menengahkan peta ke posisimu.

## 2. Menuju titik

Geser kartu di bawah peta untuk berpindah titik. Kartu menampilkan nomor usulan, jarak dari posisimu, nama lokasi, jenis dan jumlah perlengkapan, serta tahun anggarannya. Ketuk tombol arah untuk membuka Google Maps.

## 3. Tandai terpasang

Setelah selesai memasang, ketuk **Tandai Terpasang**.

```gambar
src: ../gambar/lapangan-sheet.png
keterangan: Lembar Tandai terpasang
penanda:
  - [56.4, 43.2, "Ambil Foto: minimal 1 foto hasil pemasangan."]
  - [48.7, 59.2, "Jumlah terpasang. Ubah kalau berbeda dari yang disetujui."]
  - [60.3, 77.4, "GPS terkunci: lokasi pemasangan terisi otomatis."]
  - [80.8, 85.7, "Keterangan boleh dikosongkan."]
  - [82.1, 95.0, "Ketuk Selesai untuk menyimpan."]
```

- **Foto hasil pemasangan**: wajib, minimal 1.
- **Jumlah terpasang**: awalnya sama dengan jumlah yang disetujui.
- **Tanggal pasang**: otomatis hari ini.
- **Lokasi**: diambil dari GPS. Kalau GPS tidak terbaca atau lebih dari 500 meter dari titik usulan, yang dipakai adalah titik usulan.
- **Tambah keterangan**: boleh dikosongkan.

> [!TIP]
> Muncul peringatan "Posisimu jauh dari lokasi usulan"? Periksa lagi apakah kamu sudah di titik yang benar sebelum mengetuk **Selesai**.

## 4. Cek hasil dan lanjut

Layar hasil menunjukkan bahwa perlengkapan sudah tampil di peta publik dan pengusul sudah dikabari. Ketuk **Lanjut ke titik berikutnya** untuk meneruskan rute.

```gambar
src: ../gambar/lapangan-hasil.png
keterangan: Layar hasil setelah ditandai terpasang
penanda:
  - [82.1, 60.4, "Sudah tampil di peta publik. Pengusul dikabari."]
  - [64.1, 72.0, "Titik berikutnya dan jaraknya."]
  - [56.4, 82.5, "Urungkan: batalkan penandaan selama pesan ini masih tampil."]
```

> [!WARNING]
> Salah menandai? Ketuk **Urungkan** di pesan hitam di bawah layar. Pesan itu hanya tampil sekitar 5 detik. Kalau terlewat, perlengkapan tetap tercatat terpasang. Hubungi koordinator Dishub bila perlu dikoreksi.

## 5. Lewat komputer

Di komputer, buka tab **Siap Dipasang** dalam mode **Peta**. Panel di kiri berisi daftar titik, dan rutenya digambar dari Gudang Dishub.

```gambar
src: ../gambar/lapangan-komputer.png
keterangan: Rute pemasangan di komputer
penanda:
  - [28.5, 41.0, "Daftar titik pemasangan, berurutan."]
  - [53.5, 32.2, "Garis rute dari gudang."]
  - [73.6, 85.9, "Gudang Dishub: titik awal rute."]
  - [7.4, 89.4, "Ekspor Rute: unduh daftar titik ke Excel."]
  - [21.5, 89.4, "Mulai Navigasi: buka rute di Google Maps."]
```

Klik **Tandai Terpasang** pada sebuah titik untuk mengisi foto, jumlah, dan tanggal, lalu **Selesai**. Versi komputer tidak punya kolom keterangan dan tidak punya tombol **Urungkan**.

## Selanjutnya

- [Mengecek laporan kerusakan](./02-cek-laporan-kerusakan.md)
- [Meninjau usulan masuk](../dishub/01-meninjau-usulan.md)

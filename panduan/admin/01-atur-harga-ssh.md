---
judul: Mengatur harga SSH
ringkas: Atur harga satuan tiap jenis perlengkapan dan siapkan harga untuk tahun anggaran berikutnya.
peran: Admin
urutan: 1
layar: [pengaturan-ssh]
durasi: 3 menit
perangkat: Desktop
diperbarui: 2026-10-06
---

SSH (Standar Satuan Harga) adalah daftar harga yang dipakai aplikasi untuk memperkirakan biaya usulan. Hanya Admin yang bisa mengubahnya. Pengusul tidak melihat angka-angkanya.

## Cara kerja harga satuan

### Apa itu harga satuan?

Harga satuan adalah harga untuk **satu unit** sebuah perlengkapan, misalnya satu set pita getar atau satu titik lampu jalan. Kumpulan semua harga satuan inilah yang disebut SSH.

Aplikasi tidak menentukan atau memeriksa angkanya. Admin yang mengisi, sesuai kebijakan instansi. Setiap perubahan tercatat di **Riwayat perubahan**.

> [!NOTE]
> Aplikasi hanya menyimpan satu daftar harga. Tulisan "SSH 2026" hanya menandai tahun daftar yang sedang dipakai.

### Bagaimana perkiraan biaya dihitung?

Perkiraan biaya = **jumlah barang × harga satuan**.

Contoh: satu set pita getar harganya Rp4.500.000. Usulan meminta 4 set, jadi perkiraan biayanya 4 × Rp4.500.000 = **Rp18.000.000**. Biaya satu usulan adalah jumlah biaya semua perlengkapan di dalamnya.

### Kapan harga bisa berubah?

- **Usulan belum disetujui Bappeda:** biaya mengikuti harga terbaru. Kalau Admin mengubah harga, angkanya ikut berubah.
- **Setelah disetujui Bappeda:** harga saat itu dicatat dan **tidak berubah lagi**, walaupun Admin mengubah daftar harga di kemudian hari. Dengan begitu biaya usulan yang sudah disetujui tidak bergeser.
- **Persetujuan dibatalkan** (diubah menjadi ditunda atau ditolak): biaya kembali mengikuti harga terbaru. Kalau nanti disetujui lagi, harga yang dicatat adalah harga pada saat itu.

### Siapa yang bisa melihat harga?

Admin, Dishub, dan Bappeda. Pengusul tidak melihat harga maupun perkiraan biaya. Angkanya muncul di halaman keputusan Dishub dan Bappeda, di bagian anggaran pada tab Ditunda, dan di halaman Laporan (termasuk unduhan Excel dan PDF).

### Kaitannya dengan anggaran (pagu)

Pagu adalah batas anggaran untuk satu tahun. Pagu diatur oleh Bappeda, bukan di halaman ini. Aplikasi menjumlahkan biaya usulan yang sudah disetujui untuk tahun itu, lalu menunjukkan sisa anggarannya.

Ini hanya gambaran. Aplikasi tidak melarang persetujuan walaupun sisa anggaran kurang. Lihat [Mengatur pagu dan item ditunda](../bappeda/02-pagu-dan-ditunda.md).

### Contoh lengkap

- **Anggaran:** pagu 2027 sebesar Rp30.000.000. Pita getar yang sudah disetujui senilai Rp18.000.000 (60% dari pagu), jadi sisanya **Rp12.000.000**.
- **Menyetujui usulan lain:** kalau Bappeda menyetujui usulan lain senilai Rp3.900.000, sisanya menjadi **Rp8.100.000**.
- **Harga dinaikkan 5%:** harga pita getar menjadi Rp4.725.000. Pita getar yang sudah disetujui tetap Rp4.500.000. Usulan yang belum disetujui memakai Rp4.725.000.

## 1. Buka Pengaturan, tab Perlengkapan

Buka menu **Pengaturan**, lalu tab **Perlengkapan**. Setiap baris adalah satu jenis perlengkapan, lengkap dengan satuan, **Harga satuan**, jumlah usulan yang memakainya, dan sakelar **Aktif**.

## 2. Ubah harga

Ketik harga baru di kolom **Rp**. Harga tersimpan begitu Enter ditekan atau kolom ditinggalkan, dan titik pemisah ribuan ditambahkan otomatis. Setelah itu muncul pesan dengan tombol **Urungkan** yang bisa dipakai selama 10 menit.

```gambar
src: ../gambar/admin-ssh.png
keterangan: Pengaturan, tab Perlengkapan
penanda:
  - [44.4, 33.7, "Harga satuan: klik kolom ini, ketik harga baru, lalu tekan Enter."]
  - [51.0, 33.2, "Tambah Jenis untuk perlengkapan baru."]
  - [67.7, 44.9, "Sakelar Aktif: matikan agar jenis tidak muncul di formulir Ajukan."]
  - [85.4, 27.8, "Isi persen, lalu Salin ke SSH tahun berikutnya."]
  - [88.9, 62.5, "Riwayat perubahan."]
```

## 3. Tambah atau matikan jenis

Klik **Tambah Jenis**, isi **Nama**, **Keterangan singkat**, **Satuan**, **Harga satuan (Rp)**, **Ikon**, dan **Warna pin**, lalu klik **Simpan**. Untuk **Ikon**, tulis nama ikon dari situs lucide.dev.

```gambar
src: ../gambar/admin-dialog-jenis.png
keterangan: Jendela Tambah jenis perlengkapan
penanda:
  - [61.1, 50.3, "Harga satuan dalam rupiah, tanpa titik."]
  - [61.1, 58.8, "Nama ikon dari situs lucide.dev."]
  - [48.6, 77.6, "Simpan. Batal menutup jendela tanpa menyimpan."]
```

Sakelar **Aktif** menyembunyikan jenis dari formulir pengajuan. Usulan lama tetap tersimpan.

Untuk mengubah jenis yang sudah ada, klik langsung nama, keterangan, atau satuannya di daftar, ketik, lalu tekan Enter atau klik di luar kolom. Klik kotak ikon untuk mengganti **Ikon** dan **Warna pin**.

> [!NOTE]
> Perubahan tersimpan otomatis dan bisa dibatalkan lewat **Urungkan**. Nama dan satuan tidak boleh kosong; kalau dikosongkan, isian kembali seperti semula.

## 4. Siapkan harga tahun berikutnya

Di kotak **Standar Satuan Harga**, isi persen kenaikan (boleh minus, antara −50 dan 100). Lalu klik **Salin ke SSH {tahun berikutnya}** dan setujui pertanyaan konfirmasinya. Semua harga naik atau turun sebesar persen itu, dibulatkan ke ribuan terdekat.

- Persen yang terakhir dipakai diingat untuk lain kali.
- Harga hanya bisa disiapkan sampai satu tahun di depan tahun berjalan. Kalau sudah, tombol berganti menjadi "SSH {tahun} sudah disiapkan".

> [!WARNING]
> Harga lama tidak disimpan terpisah. Begitu tombol ditekan, daftar langsung memakai harga baru dan tidak bisa dikembalikan dengan **Urungkan**. Yang tersisa hanya catatan di **Riwayat perubahan**. Usulan yang sudah disetujui Bappeda tidak terpengaruh. Usulan yang belum disetujui langsung memakai harga baru.

## 5. Cek riwayat

**Riwayat perubahan** di sisi kanan menampilkan perubahan terbaru: apa yang berubah, kapan, dan siapa yang mengubahnya.

## Selanjutnya

- [Membaca laporan dan ekspor](./04-baca-laporan.md)
- [Mengelola pengguna dan instansi](./02-kelola-pengguna-instansi.md)

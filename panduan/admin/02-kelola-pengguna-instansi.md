---
judul: Mengelola pengguna dan instansi
ringkas: Tambah akun petugas, atur peran, nonaktifkan akun, dan kelola daftar instansi.
peran: Admin
urutan: 2
layar: [pengaturan-pengguna]
durasi: 3 menit
perangkat: Desktop
diperbarui: 2026-10-22
---
Warga mendaftar sendiri dengan kode email. Akun petugas dan akun instansi dibuat oleh Admin. Aplikasi tidak memakai kata sandi, jadi tidak ada yang perlu diatur ulang.

## 1. Tambah pengguna

Buka **Pengaturan**, tab **Pengguna**, lalu klik **Tambah Pengguna**. Isi **Nama**, **Email** (belum boleh dipakai akun lain), **No. HP**, **Peran**, dan **Instansi** (hanya untuk pengusul dari instansi). Klik **Simpan**. Pengguna masuk dengan kode yang dikirim ke emailnya.

```gambar
src: ../gambar/admin-dialog-pengguna.png
keterangan: Jendela Tambah pengguna
penanda:
  - [59.7, 54.2, "Peran: Pengusul, Dishub · Koordinator, Dishub · Petugas lapangan, Bappeda, atau Admin."]
  - [60.4, 63.0, "Instansi: isi hanya untuk pengusul dari instansi."]
  - [48.6, 73.4, "Simpan. Batal menutup jendela tanpa menyimpan."]
```

> [!NOTE]
> Dishub punya dua pilihan peran. **Dishub · Koordinator** melihat semua laporan kerusakan, menugaskan petugas, dan menolak laporan. **Dishub · Petugas lapangan** di menu Pemeliharaan hanya melihat laporan yang ditugaskan kepadanya, lalu mengecek dan menandai selesai. Untuk Usulan, keduanya sama. Akun Dishub lama otomatis menjadi koordinator.

## 2. Ubah data, peran, atau nonaktifkan

Di daftar pengguna, klik nama, email, atau no. HP untuk mengubahnya langsung, lalu tekan Enter. **Instansi** dipilih dari daftar. Email tidak boleh sama dengan akun lain. Kolom **Peran** juga berupa pilihan yang bisa langsung diganti. Sakelar **Aktif** mematikan akun: pengguna itu tidak bisa masuk dan melihat pesan "Akun Anda dinonaktifkan". Gunakan **Cari nama atau email** untuk menemukan pengguna.

```gambar
src: ../gambar/admin-pengguna.png
keterangan: Pengaturan, tab Pengguna
penanda:
  - [48.6, 33.2, "Tambah Pengguna."]
  - [29.7, 33.7, "Peran bisa diganti langsung dari daftar."]
  - [59.9, 58.7, "Sakelar Aktif mematikan atau menghidupkan akun."]
  - [59.0, 44.8, "Akun sendiri bertanda (Anda). Peran dan sakelarnya tidak bisa diubah."]
```

Pengguna tidak bisa dihapus, hanya dinonaktifkan.

## 3. Kelola instansi

Buka tab **Instansi**. **Nama instansi**, **Jenis** (Desa/Kelurahan, Kecamatan, Sekolah, OPD, atau Lainnya), dan **Kecamatan** bisa diubah langsung di daftar. Kolom **Akun** menunjukkan berapa akun yang terhubung ke instansi itu. Klik **Tambah Instansi** untuk menambah instansi baru.

```gambar
src: ../gambar/admin-instansi.png
keterangan: Pengaturan, tab Instansi
penanda:
  - [49.7, 33.2, "Tambah Instansi."]
  - [29.5, 58.6, "Jenis dan Kecamatan bisa diubah langsung."]
  - [63.2, 56.8, "Jumlah akun yang terhubung ke instansi."]
```

Semua akun dengan instansi yang sama melihat usulan milik instansi itu. Pengusul instansi mengunggah surat usulan resmi (PDF), bukan proposal otomatis.

> [!TIP]
> Setiap perubahan tersimpan otomatis dan bisa dibatalkan lewat tombol **Urungkan** di pesan yang muncul. Lihat juga [Mengatur harga SSH](./01-atur-harga-ssh.md).

## Selanjutnya

- [Mengatur wilayah dan ruas jalan](./03-atur-wilayah-jalan.md)
- [Mengatur harga SSH](./01-atur-harga-ssh.md)

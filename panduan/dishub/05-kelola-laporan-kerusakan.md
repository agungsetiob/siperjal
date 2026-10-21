---
judul: Mengelola laporan kerusakan
ringkas: Tugaskan petugas untuk laporan baru, tolak yang tidak sesuai, dan tutup laporan setelah diperbaiki.
peran: Dishub
urutan: 5
layar: [pemeliharaan]
durasi: 4 menit
perangkat: Desktop
diperbarui: 2026-10-06
---
Laporan kerusakan perlengkapan jalan masuk ke menu **Pemeliharaan**. Laporan bisa tertaut ke perlengkapan yang terpasang lewat aplikasi (detailnya menampilkan asal usulan), atau berupa titik yang ditandai pelapor sendiri (tertulis "Tidak tertaut data terpasang"). Alurnya: Dishub menugaskan petugas, petugas mengecek di lapangan, lalu laporan ditutup setelah diperbaiki. Admin hanya bisa melihat laporan, tidak bisa menugaskan, menolak, atau menutupnya.

## 1. Buka Pemeliharaan

Di bagian atas daftar Usulan, klik **Pemeliharaan**. Menu ini tidak ada di bar atas. Angka di sebelahnya adalah jumlah laporan yang masih **Baru**.

```gambar
src: ../gambar/dishub-pemeliharaan.png
keterangan: Laporan kerusakan yang baru masuk
penanda:
  - [60.6, 25.6, "Empat langkah laporan. Ditugaskan hanya keterangan di langkah Dicek petugas."]
  - [77.8, 59.3, "Jenis kerusakan dan catatan dari pelapor. Hubungi membuka WhatsApp."]
  - [69.4, 88.1, "Saran petugas: yang tugas aktifnya paling sedikit."]
  - [38.9, 92.1, "Tolak laporan, untuk laporan iseng atau ganda."]
  - [73.6, 96.2, "Tugaskan Petugas."]
```

- Tab: **Baru**, **Ditugaskan**, **Perbaikan**, **Semua**.
- Gunakan **Cari nomor, lokasi, atau pelapor**, atau pindah ke **Peta**.
- Daftar diperbarui sendiri setiap 30 detik.

## 2. Tugaskan petugas

Pilih laporan **Baru**, lalu klik **Tugaskan Petugas**.

```gambar
src: ../gambar/dishub-dialog-tugaskan.png
keterangan: Jendela Tugaskan petugas
penanda:
  - [59.0, 39.1, "Petugas yang disarankan: tugas aktifnya paling sedikit."]
  - [62.5, 51.8, "Catatan untuk petugas. Boleh dikosongkan."]
  - [45.1, 78.1, "Klik Tugaskan {nama}: petugas langsung dapat notifikasi."]
```

1. Pilih petugas lapangan. Setiap nama menunjukkan jumlah tugas aktifnya, dan petugas yang **Disarankan** ada di paling atas.
2. Isi catatan untuk petugas bila perlu, misalnya pelapor bisa dihubungi untuk menunjukkan titiknya.

Klik **Tugaskan {nama}**. Petugas langsung menerima notifikasi tugas baru, dan status laporan menjadi **Ditugaskan**. Pakai **Ganti Petugas** untuk memindahkannya ke petugas lain.

## 3. Tolak laporan yang tidak sesuai

Pada laporan **Baru** atau **Ditugaskan**, klik **Tolak laporan**.

```gambar
src: ../gambar/dishub-dialog-tolak-laporan.png
keterangan: Jendela Tolak laporan
penanda:
  - [61.1, 46.7, "Pilih alasan cepat, atau tulis alasan sendiri di kolom di bawahnya."]
  - [45.1, 62.0, "Tolak Laporan. Pelapor dikabari beserta alasannya."]
```

Pilih alasan cepat (**Laporan iseng**, **Duplikat laporan lain**, atau **Bukan perlengkapan Dishub**) atau tulis alasan sendiri, lalu klik **Tolak Laporan**.

## 4. Tutup setelah diperbaiki

Petugas mengecek kondisi di lokasi (lihat [Mengecek laporan kerusakan](../petugas-lapangan/02-cek-laporan-kerusakan.md)). Kalau kerusakannya dibenarkan, laporan pindah ke tab **Perbaikan**.

Setelah perlengkapan selesai diperbaiki, klik **Tandai Selesai Diperbaiki**.

```gambar
src: ../gambar/dishub-dialog-selesai.png
keterangan: Jendela Selesai diperbaiki
penanda:
  - [48.6, 49.8, "Foto hasil perbaikan. Wajib minimal 1."]
  - [41.7, 65.6, "Tandai Selesai. Pelapor dan pengusul asli dikabari."]
```

Tambahkan foto hasil perbaikan dan catatan bila ada, lalu klik **Tandai Selesai**. Pelapor dan pengusul asli perlengkapan dikabari.

> [!NOTE]
> Satu perlengkapan hanya punya satu laporan yang sedang berjalan. Panel detail juga menunjukkan laporan aktif lain dalam jarak sekitar 100 meter, sehingga perjalanan petugas bisa digabung.

> [!WARNING]
> **Urungkan** untuk penandaan selesai hanya berlaku 10 menit. Sesudah itu laporan tidak bisa dibuka lagi dari sini.

## Selanjutnya

- [Mengecek laporan kerusakan](../petugas-lapangan/02-cek-laporan-kerusakan.md)
- [Meninjau usulan masuk](./01-meninjau-usulan.md)

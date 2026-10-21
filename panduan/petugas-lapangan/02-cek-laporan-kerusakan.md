---
judul: Mengecek laporan kerusakan
ringkas: Cek perlengkapan yang dilaporkan rusak di lokasi, putuskan perlu diperbaiki atau tidak, lalu tutup setelah selesai.
peran: Petugas Lapangan
urutan: 2
layar: [pemeliharaan]
durasi: 3 menit
perangkat: HP
diperbarui: 2026-10-19
---

Koordinator Dishub menugaskan laporan kerusakan kepadamu. Tugasmu: cek kondisi di lokasi, putuskan apakah perlu diperbaiki, lalu tandai selesai setelah diperbaiki.

## 1. Buka titik tugas

> [!TIP]
> Tidak lewat peta pun bisa: buka laporan dari daftar **Pemeliharaan** (di HP maupun komputer), lalu ketuk **Cek Kondisi** di bagian **Tindak lanjut**. Lembar isiannya sama persis dengan yang ada di peta.

Di HP, buka **Usulan**, pilih **Pemeliharaan**, lalu ketuk **Peta**. Tab **Ditugaskan** berisi laporan yang menunggu dicek, dan tab **Perbaikan** berisi laporan yang sudah dipastikan rusak.

```gambar
src: ../gambar/lapangan-tugas.png
keterangan: Kartu laporan di tab Ditugaskan
penanda:
  - [61.5, 53.9, "Jarak dari posisimu dan status laporan."]
  - [89.7, 70.9, "Ditugaskan ke saya: laporan ini milikmu."]
  - [75.6, 76.9, "Catatan dari koordinator, kalau ada."]
  - [17.9, 79.1, "Tombol arah: buka Google Maps."]
  - [76.9, 84.6, "Cek Kondisi."]
```

Kartu menampilkan nomor laporan `LPR-…`, jarak dari posisimu, jenis kerusakan, lokasi, dan foto dari pelapor.

> [!NOTE]
> Daftar di tab ini berisi semua laporan dengan status itu, tidak hanya yang ditugaskan kepadamu. Tulisan **Ditugaskan ke saya** menandai laporan milikmu.

## 2. Cek kondisi

Di tab **Ditugaskan**, ketuk **Cek Kondisi**, lalu isi:

```gambar
src: ../gambar/lapangan-cek-kondisi.png
keterangan: Lembar Cek kondisi
penanda:
  - [56.4, 33.8, "Foto kondisi sekarang. Wajib minimal 1."]
  - [89.7, 46.9, "Bandingkan dengan foto dari pelapor."]
  - [71.2, 71.8, "Cocok: kamu berada dalam 100 meter dari titik laporan."]
  - [88.5, 79.4, "Setujui: laporan masuk antrean perbaikan."]
```

- **Foto kondisi**: wajib, minimal 1. Ketuk **Bandingkan dengan foto pelapor** untuk melihat foto aslinya.
- **Catatan**: boleh dikosongkan.
- Keterangan lokasi menunjukkan jarakmu dari titik laporan: **Cocok** (sampai 100 meter) atau **Jauh**.

## 3. Putuskan hasilnya

- **Memang rusak**: ketuk **Setujui — perlu perbaikan**. Laporan masuk antrean perbaikan.
- **Tidak rusak**: pilih alasannya (**Kondisi baik**, **Sudah diperbaiki**, atau **Lokasi salah**), lalu ketuk **Tolak — tidak rusak**. Pelapor dikabari.

## 4. Tandai selesai diperbaiki

Setelah perbaikan dikerjakan, buka tab **Perbaikan** dan ketuk **Selesai Diperbaiki**. Dari daftar, buka laporannya lalu ketuk **Tandai Selesai Diperbaiki**.

```gambar
src: ../gambar/lapangan-perbaikan.png
keterangan: Kartu laporan di tab Perbaikan
penanda:
  - [83.3, 55.4, "Menunggu perbaikan: sudah dipastikan rusak."]
  - [89.7, 76.4, "Hasil cek dan catatanmu sebelumnya."]
  - [76.9, 84.6, "Selesai Diperbaiki."]
```

Ambil minimal 1 **Foto hasil perbaikan**, tulis catatan bila perlu, lalu ketuk **Selesai**. Pelapor dan pengusul asli perlengkapan dikabari beserta foto sebelum dan sesudah.

```gambar
src: ../gambar/lapangan-selesai.png
keterangan: Lembar Selesai diperbaiki
penanda:
  - [56.4, 56.9, "Foto hasil perbaikan. Wajib minimal 1 di HP."]
  - [83.3, 70.5, "Foto dari pelapor sebagai pembanding."]
  - [64.1, 77.8, "Catatan boleh dikosongkan."]
  - [82.1, 95.0, "Ketuk Selesai untuk menyimpan."]
```

> [!WARNING]
> Salah menandai selesai? Ketuk **Urungkan** di layar hasil. Hanya bisa dalam 10 menit.

## Selanjutnya

- [Menandai perlengkapan terpasang](./01-tandai-terpasang.md)
- [Mengelola laporan kerusakan](../dishub/05-kelola-laporan-kerusakan.md)

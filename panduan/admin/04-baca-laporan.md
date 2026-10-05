---
judul: Membaca laporan dan ekspor
ringkas: Lihat rekap usulan per tahun dan kecamatan, lalu unduh sebagai Excel atau PDF.
peran: Admin
urutan: 4
layar: [laporan]
durasi: 3 menit
perangkat: Desktop
diperbarui: 2026-10-04
---
Halaman **Laporan** dapat dibuka oleh Dishub, Bappeda, dan Admin. Pengusul tidak melihatnya. Angkanya selalu diambil dari data usulan terbaru.

## 1. Pilih tahun dan kecamatan

Buka menu **Laporan**. Pilih **Tahun** (menurut tahun usulan diajukan) dan **Kecamatan**. Pilih **Semua** kalau tidak ingin memisahkan data. Tombol **Export Excel** dan **Export PDF** ada di sebelah kanan pilihan itu.

```gambar
src: ../gambar/admin-laporan.png
keterangan: Halaman Laporan
penanda:
  - [58.3, 14.6, "Pilih tahun dan kecamatan."]
  - [84.0, 14.6, "Unduh laporan sebagai Excel atau PDF."]
  - [41.7, 38.6, "Total perkiraan biaya item yang sudah disetujui."]
  - [22.9, 52.7, "Empat kartu angka: usulan masuk, lolos Dishub, disetujui Bappeda, dan terpasang."]
```

## 2. Baca ringkasan

- Pita biru **Total estimasi biaya item disetujui** menjumlahkan biaya item yang **Disetujui** dan **Terpasang** (jumlah × harga satuan).
- Empat kartu menunjukkan **Usulan masuk**, **Item lolos Dishub**, **Item disetujui Bappeda**, dan **Item terpasang**.

## 3. Lihat rincian

- **Alur item** menunjukkan berapa persen item yang bertahan di tiap tahap. Item yang masih menunggu keputusan tidak dihitung sebagai ditolak.
- **Per kecamatan:** item yang sudah terpasang dibandingkan dengan yang belum. Kalau ada lebih dari 8 kecamatan, sisanya digabung sebagai **Lainnya**.
- **Per jenis perlengkapan:** jumlah di tiap tahap, harga satuan, dan perkiraan biaya yang disetujui, dengan baris **Total** di bawahnya.

```gambar
src: ../gambar/admin-laporan-rincian.png
keterangan: Alur item dan rekap per kecamatan
penanda:
  - [37.2, 35.8, "Alur item: makin ke bawah, makin sedikit yang bertahan."]
  - [42.4, 62.2, "Item yang ditunda atau masih menunggu tidak dihitung sebagai ditolak."]
  - [70.8, 22.7, "Terpasang dibanding belum terpasang."]
  - [79.9, 32.4, "Per kecamatan: jumlah item dan yang sudah terpasang."]
```

```gambar
src: ../gambar/admin-laporan-jenis.png
keterangan: Rekap per jenis perlengkapan
penanda:
  - [77.2, 12.9, "Harga satuan yang berlaku. Diatur Admin di Pengaturan."]
  - [89.6, 12.9, "Perkiraan biaya item yang disetujui untuk jenis ini."]
  - [27.8, 93.7, "Total semua jenis."]
```

> [!NOTE]
> Perkiraan biaya dihitung dari jumlah barang × harga satuan. Cara kerjanya ada di [Mengatur harga SSH](./01-atur-harga-ssh.md).

## 4. Unduh

Klik **Export Excel** untuk berkas `rekap-usulan-{tahun}.xlsx` dengan satu baris untuk tiap item. Klik **Export PDF** untuk ringkasan satu berkas (kertas A4 mendatar) berisi ringkasan, rekap per jenis, dan rekap per kecamatan. Tahun dan kecamatan yang dipilih ikut terbawa.

> [!WARNING]
> Halaman ini belum memuat data laporan kerusakan (Pemeliharaan).

## Selanjutnya

- [Mengatur harga SSH](./01-atur-harga-ssh.md)
- [Memutuskan usulan](../bappeda/01-putuskan-usulan.md)

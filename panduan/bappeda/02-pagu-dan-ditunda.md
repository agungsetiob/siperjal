---
judul: Mengatur pagu dan item ditunda
ringkas: Pantau pagu anggaran per tahun dan putuskan item yang ditunda ke tahun target.
peran: Bappeda
urutan: 2
layar: [pagu-ditunda, keputusan]
durasi: 3 menit
perangkat: Desktop
diperbarui: 2026-10-04
---

Item yang ditunda menunggu pagu tahun targetnya. Tab **Ditunda** mengelompokkan item per tahun dan menampilkan band pagu, supaya kamu tahu apakah anggarannya masih cukup.

## 1. Buka tab Ditunda

Item dikelompokkan per **Target {tahun}** dengan jumlah item dan **Subtotal**. Tiap baris menunjukkan **Alasan ditunda**, tanggal, dan siapa yang menundanya, serta jumlah dan **Estimasi biaya**.

```gambar
src: ../gambar/bappeda-ditunda.png
keterangan: Tab Ditunda dengan band pagu di bagian atas
penanda:
  - [22.9, 39.1, "Kelompok Target 2027: item yang menunggu pagu tahun itu."]
  - [31.3, 25.9, "Klik untuk mengatur pagu tahun ini."]
  - [67.7, 25.4, "Sisa pagu: hijau bila masih cukup."]
```

## 2. Atur pagu tahun anggaran

Di band pagu, klik **atur pagu {tahun}**, ketik angkanya, lalu tekan Enter. Tekan Esc untuk membatalkan. Muncul pesan "Pagu {tahun} disimpan". Pagu disimpan terpisah untuk tiap tahun, dan hanya Bappeda yang bisa mengubahnya.

## 3. Baca band pagu

- **Disetujui {tahun}** adalah total estimasi item yang sudah **Disetujui** atau **Terpasang** untuk tahun itu. Biayanya dihitung dengan harga saat item disetujui (lihat [Mengatur harga SSH](../admin/01-atur-harga-ssh.md)).
- Batang menunjukkan persen terpakai. Item yang sedang dicentang ditambahkan sebagai "+ Rp … jika pilihan disetujui".
- **Sisa pagu** hijau kalau masih cukup dan merah kalau minus.

```gambar
src: ../gambar/bappeda-pagu-pilihan.png
keterangan: Item dicentang: band pagu ikut menghitung
penanda:
  - [38.9, 29.3, "Tambahan dari item yang dicentang."]
  - [67.7, 25.4, "Sisa pagu setelah tambahan itu."]
  - [27.4, 88.4, "Tahun anggaran yang dipakai band."]
  - [57.6, 88.4, "Setujui 2 item. Tunda lagi dan Tolak ada di sebelah kirinya."]
```

> [!WARNING]
> Band pagu hanya informasi. Sistem tidak melarang persetujuan walaupun sisa pagu minus.

## 4. Putuskan item ditunda

Centang item, atau kotak kelompok untuk satu tahun target. Pilih **Tahun anggaran** di bar aksi, lalu klik **Setujui N item**, **Tunda lagi**, atau **Tolak**.

> [!TIP]
> **Tunda lagi** menunda ke tahun anggaran yang dipilih ditambah satu. Periksa tahun di bar aksi sebelum menekannya.

## Selanjutnya

- [Memutuskan usulan](./01-putuskan-usulan.md)
- [Mengubah keputusan](./03-ubah-keputusan.md)

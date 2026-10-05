---
judul: Memutuskan usulan
ringkas: Pilih item yang lolos Dishub, lalu setujui, tunda, atau tolak sekaligus.
peran: Bappeda
urutan: 1
layar: [keputusan]
durasi: 4 menit
perangkat: Desktop
diperbarui: 2026-10-04
---

Bappeda hanya melihat usulan yang sudah lolos pemeriksaan Dishub. Keputusan diambil per item, bukan per usulan.

## 1. Buka tab Menunggu Keputusan

Buka menu **Usulan**. Tab **Menunggu Keputusan** berisi usulan yang masih punya item **Lolos Dishub**, diurutkan dari skor pemeriksaan tertinggi.

- Ketik di **Cari ruas jalan / no. usulan** untuk menyaring, atau pindah ke **Peta**.
- Klik baris usulan untuk membuka panel di kanan: hasil pemeriksaan Dishub (**Skor n/5** dan kriteria yang **Terpenuhi**), foto, dan peta.

```gambar
src: ../gambar/bappeda-menunggu-keputusan.png
keterangan: Tab Menunggu Keputusan dengan satu usulan terbuka
penanda:
  - [16.4, 18.6, "Tab Menunggu Keputusan: usulan yang masih punya item Lolos Dishub."]
  - [44.4, 12.5, "Cari ruas jalan atau nomor usulan."]
  - [86.1, 45.0, "Hasil pemeriksaan Dishub: skor dan kriteria yang terpenuhi."]
```

## 2. Pilih item

Centang item yang akan diputuskan. Kotak di kepala usulan memilih semua itemnya, dan kotak di kepala kolom memilih semua usulan. Kolom **Estimasi biaya** menunjukkan biaya tiap item (jumlah barang × harga satuan; cara hitungnya ada di [Mengatur harga SSH](../admin/01-atur-harga-ssh.md)). Semua yang dicentang dijumlahkan di bar aksi.

## 3. Putuskan lewat bar aksi

Begitu ada item dicentang, bar aksi muncul berisi jumlah item, **Total estimasi**, dan pilihan **Tahun anggaran** (awalnya tahun depan).

```gambar
src: ../gambar/bappeda-pilih-item.png
keterangan: Item dicentang dan bar aksi di bagian bawah
penanda:
  - [53.2, 33.4, "Item yang dicentang: 2 dari 2 item usulan ini."]
  - [27.8, 88.4, "Tahun anggaran untuk item yang disetujui."]
  - [47.2, 88.4, "Tunda: item pindah ke tab Ditunda."]
  - [57.6, 88.4, "Setujui: langsung tersimpan tanpa dialog."]
```

- **Setujui N item**: langsung tersimpan tanpa dialog. Item berstatus **Disetujui** untuk tahun anggaran yang dipilih, dan harga satuan saat itu dikunci. Dishub dan pengusul dikabari.
- **Tunda**: tulis alasan, lalu item pindah ke tab **Ditunda** untuk tahun berikutnya.
- **Tolak**: tulis alasan. Item berstatus **Ditolak** dan pengusul dikabari.
- **Batal pilih**: mengosongkan centang.

```gambar
src: ../gambar/bappeda-dialog-tunda.png
keterangan: Dialog Tunda: alasan wajib diisi
penanda:
  - [32.1, 46.9, "Tulis alasan menunda. Wajib."]
  - [61.5, 64.5, "Klik Tunda untuk menyimpan. Batal menutup dialog tanpa mengubah apa pun."]
```

> [!NOTE]
> Jumlah item mengikuti usulan dan hasil pemeriksaan Dishub. Jumlah tidak diubah di tahap ini.

> [!WARNING]
> Pesan **Urungkan** hanya tampil sekitar 5 detik. Untuk koreksi yang lebih lama, gunakan [Mengubah keputusan](./03-ubah-keputusan.md).

## Selanjutnya

- [Mengatur pagu dan item ditunda](./02-pagu-dan-ditunda.md)
- [Mengubah keputusan](./03-ubah-keputusan.md)

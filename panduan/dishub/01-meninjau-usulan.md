---
judul: Meninjau usulan masuk
ringkas: Periksa kelengkapan usulan, loloskan atau tolak per item, lalu teruskan ke Bappeda.
peran: Dishub
urutan: 1
layar: [usulan-masuk]
durasi: 4 menit
perangkat: Desktop
diperbarui: 2026-10-04
---
Dishub memeriksa setiap usulan berdasarkan 5 kriteria yang dilihat di lapangan, lalu memutuskan satu per satu perlengkapan yang diusulkan (disebut *item*): **Lolos** berarti diteruskan ke Bappeda, **Tolak** berarti berhenti di Dishub.

## 1. Pilih usulan dari daftar

Buka menu **Usulan**. Tab **Perlu Diperiksa** berisi usulan baru, dengan yang paling lama menunggu di atas. Tulisan "masuk N hari lalu" berubah kuning dan tebal setelah lewat 7 hari.

```gambar
src: ../gambar/dishub-periksa.png
keterangan: Daftar usulan di kiri, rincian usulan yang dipilih di kanan
penanda:
  - [15.3, 63.5, "Daftar usulan yang perlu diperiksa. Yang dipilih diberi warna. Tulisan kuning berarti sudah menunggu lebih dari 7 hari."]
  - [83.3, 9.8, "Rincian usulan terbuka di sisi kanan: foto, peta, item, dan kartu Periksa."]
```

- Ketik di **Cari nomor, desa, atau pengusul** untuk mencari. Tombol urut membuka pilihan **Urutkan** dan **Saring** (kecamatan dan jenis perlengkapan).
- Klik **Peta** untuk melihat sebaran usulan. Klik sebuah pin, lalu **Buka & Periksa**.
- Daftar diperbarui sendiri setiap 20 detik.

## 2. Periksa kriteria di lapangan

Di kartu **Periksa**, klik kriteria yang memang terlihat di lapangan:

- Rawan kecelakaan
- Jarak pandang terbatas
- Aktivitas anak / sekolah
- Volume lalu lintas padat
- Jalan poros primer/sekunder

**Skor** bertambah satu untuk setiap kriteria yang dicentang (paling tinggi 5). Titik kecil pada sebuah kriteria berarti pengusul sendiri yang mengajukan kriteria itu. Alasan dari pengusul ada di kotak **Alasan dari pengusul**.

```gambar
src: ../gambar/dishub-kartu-periksa.png
keterangan: Kartu Periksa: kriteria, skor, dan keputusan per item
penanda:
  - [51.3, 6.8, "Klik kriteria yang terpenuhi."]
  - [69.4, 6.8, "Skor naik satu untuk tiap kriteria."]
  - [42.7, 54.4, "Keputusan per item: Lolos atau Tolak. Awalnya semua Lolos."]
  - [25.6, 79.5, "Minta Revisi dan Tandai Duplikat."]
  - [85.9, 79.5, "Simpan & Berikutnya."]
```

> [!NOTE]
> Skor berlaku untuk seluruh usulan, bukan per item. Bappeda melihat skor ini saat memutuskan.

## 3. Putuskan tiap item

Klik **Tolak** pada item yang tidak layak. Muncul jendela **Tolak item** yang meminta:

- **Alasan cepat**: **Lokasi bukan jalan kabupaten**, **Sudah ada perlengkapan serupa**, **Tidak sesuai standar teknis**, atau **Foto kurang jelas**. Memilih salah satunya mengisi catatan secara otomatis.
- **Catatan untuk pengusul**: wajib diisi, maksimal 300 huruf. Pengusul membacanya di halaman lacak dan lewat email.

```gambar
src: ../gambar/dishub-dialog-tolak.png
keterangan: Jendela Tolak item
penanda:
  - [62.5, 30.5, "Item yang ditolak. Hanya item ini, bukan seluruh usulan."]
  - [66.7, 42.6, "Pilih salah satu alasan cepat."]
  - [66.7, 54.7, "Catatan untuk pengusul. Boleh diubah."]
  - [50.5, 81.3, "Tolak Item hanya menandai di layar. Belum tersimpan."]
```

Baris paling bawah tabel menunjukkan "N dari M item diloloskan" beserta total biayanya.

> [!WARNING]
> **Tolak Item** baru menandai di layar. Keputusan baru tersimpan setelah **Simpan & Berikutnya** ditekan.

## 4. Simpan dan lanjut

Klik **Simpan & Berikutnya** (atau tekan Ctrl/Cmd + Enter). Usulan berikutnya langsung terbuka.

- Item yang lolos berstatus **Lolos Dishub** dan masuk ke daftar Bappeda. Bappeda dikabari.
- Kalau semua item ditolak, usulan berstatus **Ditolak** dan pengusul dikabari alasannya.
- Muncul pesan "Skor 0"? Centang minimal 1 kriteria supaya item bisa lolos. Untuk menolak semua item, skor 0 tidak masalah.

> [!TIP]
> Salah klik? Tekan **Urungkan** di pesan yang muncul sekitar 5 detik. Sesudahnya, pakai [Ubah Keputusan](./04-ubah-keputusan.md).

## Selanjutnya

- [Meminta revisi](./02-minta-revisi.md)
- [Menangani usulan duplikat](./03-tandai-duplikat.md)

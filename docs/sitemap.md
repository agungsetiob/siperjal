# Sitemap — Sistem Informasi Usulan Perlengkapan Jalan

## Context
Aplikasi untuk mengelola **usulan pengadaan perlengkapan jalan** dari masyarakat dan instansi (desa, kecamatan,
sekolah, dll.). Setiap usulan berisi proposal, foto lokasi dan titik koordinat, lalu melewati 3 tahap:

```
Pengusul mengajukan → Dishub memeriksa (5 kriteria) → Bappeda menyetujui → Dishub memasang (Terpasang)
```

Bappeda **hanya melihat usulan yang sudah lolos Dishub**. Keputusan Dishub dan Bappeda diambil **per item**.
Masyarakat bisa melacak usulan lewat nomor usulan dan melihat peta perlengkapan yang sudah terpasang.

**Arah utama: sesimpel mungkin, interaktif, cepat, nyaman.** Semua keputusan di dokumen ini mengikuti arah itu.
Detail isi tiap layar ada di [design-brief.md](design-brief.md). Stack teknis belum ditentukan.

## Prinsip Simpel
1. **Sedikit halaman.** Total **8 halaman**. Tiap role cukup punya **satu halaman kerja** (daftar usulan dengan tab sesuai role).
2. **Satu tombol utama per layar.** Pengguna selalu tahu langkah berikutnya.
3. **Jangan tanya yang bisa ditebak sistem.** Alamat, kecamatan, desa, koordinat, tanggal, satuan, nama instansi terisi otomatis.
4. **Tap, bukan ketik.** Jenis perlengkapan = kotak ikon, jumlah = tombol − / +, alasan = chip yang bisa di-tap.
5. **Bahasa awam untuk pengusul.** "Sering kecelakaan", bukan "rawan kecelakaan lalu lintas"; istilah teknis (status jalan primer/sekunder) diisi Dishub, bukan pengusul.
6. **Tanpa password.** Masuk cukup dengan email → kode 6 digit. Tidak ada halaman daftar, lupa password, atau reset password.
7. **Tidak pindah-pindah halaman.** Di desktop, daftar dan detail tampil berdampingan (klik baris → detail muncul di kanan). Tombol **Simpan & Berikutnya** langsung membuka usulan berikutnya.
8. **Undo, bukan konfirmasi.** Aksi biasa langsung jalan + toast "Urungkan" 5 detik. Dialog konfirmasi hanya untuk menolak.
9. **Semua tersimpan otomatis & diperbarui langsung (realtime).** Tidak ada tombol "Simpan draft" atau "Refresh".

## Sitemap

### Publik (3 halaman)
| # | Halaman | Route | Isi |
|---|---|---|---|
| P1 | Beranda Publik | `/` | **Peta perlengkapan terpasang** sebagai latar + kotak **Lacak Usulan** + tombol besar **Ajukan Usulan**. Di bawahnya: 3 langkah cara mengajukan, angka ringkas. |
| P2 | Lacak Usulan | `/lacak/:nomor` | Stepper status + daftar item & statusnya. Link ini bisa dibagikan (WA). Tanpa nama/kontak pengusul. |
| P3 | Masuk | `/masuk` | Email → kode 6 digit → masuk. Pengguna baru (masyarakat) otomatis terdaftar dan cukup mengisi **nama + no. HP** sekali. |

### Aplikasi (5 halaman)
Navigasi = **bar atas sederhana** (desktop) / **bar bawah** (HP), bukan sidebar, karena menunya sedikit.

```
Pengusul            Dishub              Bappeda             Admin
──────────          ──────────          ──────────          ──────────
Usulan Saya         Usulan              Usulan              Usulan
[+ Ajukan]          Laporan             Laporan             Laporan
                                                            Pengaturan
```
Profil (nama, no. HP, notifikasi) = **modal** dari avatar. Notifikasi = **lonceng** (dropdown).

| # | Halaman | Route | Isi |
|---|---|---|---|
| A1 | Usulan (halaman kerja) ⭐ | `/usulan` | Halaman pertama setelah masuk. **Tab berbeda per role** (lihat di bawah). Toggle **Daftar ↔ Peta**. Desktop: daftar kiri + detail kanan. |
| A2 | Detail Usulan ⭐ | `/usulan/:id` | Satu halaman gulir (tanpa tab): status, foto & peta, item, lalu riwayat. **Kartu aksi** berubah per role. Di desktop tampil sebagai panel kanan A1; di HP halaman penuh. |
| A3 | Ajukan / Revisi Usulan ⭐ | `/usulan/baru` · `/usulan/:id/revisi` | **3 langkah**: ① Foto & Lokasi → ② Apa yang dibutuhkan → ③ Proposal & Kirim. |
| A4 | Laporan | `/laporan` | Satu layar: angka ringkas, funnel, rekap per jenis & per kecamatan, **Export Excel/PDF**. |
| A5 | Pengaturan | `/pengaturan` | Admin. Tab: **Pengguna** · **Instansi** · **Perlengkapan** · **Wilayah & Jalan**. |

**Tab di halaman Usulan per role:**
| Role | Tab (angka = jumlah) | Keterangan |
|---|---|---|
| Pengusul | **Aktif** · Selesai | Perlu Revisi selalu di paling atas dengan kartu kuning. |
| Dishub | **Perlu Diperiksa** · **Siap Dipasang** · Semua | Tab 1 urut terlama; tab 2 = item disetujui Bappeda, belum terpasang. |
| Bappeda | **Menunggu Keputusan** · Ditunda · Semua | Hanya usulan yang lolos Dishub. Urut skor tertinggi. Checkbox untuk menyetujui banyak item sekaligus, dengan **total estimasi biaya** yang ter-update langsung. Tab Ditunda bisa diputuskan ulang. |
| Admin | Semua | Hanya melihat. |

## Akses per Role
| Aksi | Pengusul Instansi | Pengusul Masyarakat | Dishub | Bappeda | Admin |
|---|---|---|---|---|---|
| Lacak & peta publik | ✔ | ✔ | ✔ | ✔ | ✔ |
| Ajukan / revisi / batalkan | ✔ milik instansinya | ✔ miliknya | — | — | — |
| Lihat usulan | milik instansinya | miliknya | semua | **hanya yang lolos Dishub** | semua |
| Periksa (kriteria, lolos/tolak item, minta revisi) | — | — | ✔ | — | — |
| Putuskan (setujui/tunda/tolak item) | — | — | — | ✔ | — |
| Tandai Terpasang | — | — | ✔ | — | — |
| Laporan & export | — | — | ✔ | ✔ | ✔ |
| Ubah keputusan (koreksi) | — | — | ✔ selama Bappeda belum memutuskan | ✔ selama item belum terpasang | — |
| Tandai duplikat | — | — | ✔ | — | — |
| Lihat estimasi biaya | — | — | ✔ | ✔ | ✔ |
| Pengaturan (termasuk harga satuan) | — | — | — | — | ✔ |

## Ringkasan angka
- Halaman: **19 → 8** (3 publik + 5 aplikasi).
- Langkah mengajukan usulan: **5 → 3**, target **± 2 menit di HP**.
- Status item: **8 → 6** (tahap "Dalam Pengadaan" dihapus: langsung Disetujui → Terpasang).
- Dishub memeriksa 1 usulan: **± 5 tap** (tap kriteria → tap ✓/✕ per item → Simpan & Berikutnya).
- Bappeda: centang banyak item → **1 tombol Setujui** (tahun anggaran sudah terisi).

## Fitur pelengkap (tanpa halaman baru)
| Fitur | Masalah yang diselesaikan | Letak |
|---|---|---|
| **Estimasi biaya otomatis** | Bappeda butuh angka Rupiah untuk menyusun anggaran | Harga satuan di Pengaturan → estimasi per item, total di bar pilihan Bappeda & Laporan. Pengusul tidak mengisi biaya. |
| **Ubah Keputusan** | Salah klik yang baru disadari setelah Urungkan 5 detik lewat | Kartu Aksi di Detail. Dishub: selama Bappeda belum memutuskan. Bappeda: selama belum terpasang. Wajib alasan, tercatat di riwayat. |
| **Putuskan ulang item Ditunda** | Item ditunda sebelumnya tidak punya jalan lanjut | Tab Ditunda (Bappeda), dikelompokkan per tahun target |
| **Tandai lokasi di peta** | Foto dari laptop/galeri sering tanpa koordinat | Langkah ① form Ajukan |
| **Tandai Duplikat** | Usulan sama di lokasi yang sama menumpuk | Kartu Periksa Dishub → ditolak otomatis dengan link ke usulan asli |

## Yang dihapus demi kesederhanaan
| Dulu | Sekarang |
|---|---|
| Daftar, Lupa Password, Reset Password | Masuk dengan kode email (tanpa password) |
| Halaman Peta Publik terpisah | Jadi latar Beranda Publik |
| Beranda/dashboard per role | Halaman Usulan langsung jadi halaman kerja; yang perlu ditindak ada di tab pertama |
| Halaman Verifikasi, Persetujuan, Realisasi terpisah | Jadi **tab** di halaman Usulan |
| Halaman Peta Usulan internal | Toggle **Daftar ↔ Peta** di halaman Usulan |
| Halaman Profil | Modal dari avatar |
| Admin Pengguna & Data Master (2 halaman) | 1 halaman Pengaturan |
| Detail dengan 4 tab | Satu halaman gulir |
| Form 5 langkah + isian status jalan, ruas jalan | 3 langkah; data teknis jalan diisi Dishub |
| Status "Dalam Pengadaan" | Dihapus |
| Catatan wajib per kriteria | Catatan hanya wajib saat menolak / minta revisi |

## Keputusan
**Sudah dikonfirmasi:**
1. **Pengusul dua jenis:** instansi (akun dibuat admin) dan masyarakat umum (masuk pertama kali = otomatis terdaftar).
2. **Keputusan per item** oleh Dishub dan Bappeda.
3. **Realisasi dilacak sampai Terpasang** + foto hasil; item Terpasang tampil di peta publik.
4. **Ada halaman publik:** lacak via nomor usulan + peta perlengkapan terpasang.
5. **Arah UX: sesimpel dan seinteraktif mungkin** (prinsip di atas).

**Asumsi (perlu dikonfirmasi ke klien):**
6. **1 usulan = 1 lokasi**; boleh berisi banyak item.
7. **Masuk tanpa password** (kode ke email). Opsi kode lewat WhatsApp bisa ditambah, tetapi berbayar.
8. **Skor prioritas 0–5** dari jumlah kriteria yang terpenuhi; item hanya bisa diloloskan jika skor ≥ 1.
9. **Minta Revisi berlaku untuk seluruh usulan**; pengusul hanya melihat bagian yang ditandai Dishub.
10. **Proposal:** instansi mengunggah surat resmi (PDF). Masyarakat boleh memakai **proposal otomatis** yang dibuat sistem dari isian form. Perlu dipastikan apakah ini diterima Dishub.
11. **Tahap "Dalam Pengadaan" tidak dicatat** — item langsung dari Disetujui ke Terpasang.
12. **Peringatan usulan berdekatan** (±100 m) hanya memberi tahu, tidak memblokir; Dishub yang memutuskan duplikat atau tidak.
13. **Kewenangan jalan:** usulan di jalan nasional/provinsi tetap diterima (dengan catatan) atau ditolak?
14. **Harga satuan perkiraan** per jenis perlengkapan disediakan Dishub/Bappeda (mis. dari standar harga daerah/SSH) dan diperbarui tiap tahun.

**Pertanyaan terbuka untuk klien:**
- **Periode pengajuan:** dibuka sepanjang tahun, atau hanya periode tertentu (mis. mengikuti Musrenbang)? Jika ada periode, perlu pengaturan buka/tutup + tenggat.
- **Verifikasi pengusul masyarakat:** cukup email, atau perlu NIK untuk mencegah usulan iseng?

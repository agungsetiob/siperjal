# Design Brief — Sistem Informasi Usulan Perlengkapan Jalan

Brief ini untuk mendesain seluruh layar aplikasi. Isinya **apa yang harus ada di tiap layar** (konten, data, aksi, status). Gaya visual bebas ditentukan desainer, selama mengikuti aturan di bawah. Daftar halaman & akses role ada di [sitemap.md](sitemap.md).

> **Arah utama: simpel, interaktif, cepat, nyaman.** Kalau ragu antara menambah fitur atau membuatnya lebih sederhana, pilih yang lebih sederhana.

---

## 1. Tentang Aplikasi

Aplikasi pemerintah daerah (Indonesia) untuk mengelola **usulan perlengkapan jalan**. Masyarakat dan instansi mengajukan usulan dengan foto, titik lokasi dan proposal. **Dishub** memeriksa usulan dengan 5 kriteria, **Bappeda** menyetujui yang sudah lolos Dishub, lalu Dishub menandai perlengkapan yang sudah **terpasang**.

```
  Diajukan  →  Diperiksa Dishub  →  Diputuskan Bappeda  →  Terpasang
  (pengusul)   (5 kriteria,          (setujui / tunda /     (foto hasil)
                lolos/tolak item)     tolak per item)
```

**Bahasa UI:** Bahasa Indonesia yang ramah dan awam. **Format:** tanggal `24 Sep 2026`, nomor usulan `USL-2026-00123`, waktu relatif ("2 jam lalu", "menunggu 5 hari").

### Pengguna

| Role | Siapa | Perangkat | Yang dilakukan |
|---|---|---|---|
| **Pengusul Instansi** | Desa/kelurahan, kecamatan, sekolah, OPD | **HP** + desktop | Mengajukan usulan atas nama instansi, merevisi, memantau |
| **Pengusul Masyarakat** | Warga umum | **HP** | Mengajukan, merevisi, memantau |
| **Dishub** | Petugas Dinas Perhubungan | Desktop + HP (lapangan) | Memeriksa usulan, menandai terpasang |
| **Bappeda** | Perencana | Desktop | Memutuskan item yang lolos Dishub, melihat laporan |
| **Admin** | Pengelola sistem | Desktop | Akun, instansi, data master |
| **Publik** | Siapa saja tanpa login | HP | Melacak usulan, melihat peta terpasang |

> Aksi yang tidak boleh dilakukan suatu role **disembunyikan**, bukan di-disable.

### 12 jenis perlengkapan
Rambu lalu lintas · Marka jalan · Lampu penerangan jalan · Lampu peringatan · Traffic light · Pagar pengaman (guardrail) · Patok tikungan (delineator) · ZoSS (Zona Selamat Sekolah) · Pita getar · Kerucut lalu lintas · Traffic barrier · RPPJ (rambu penunjuk jurusan).

Tiap jenis punya **ikon** yang dipakai konsisten di kotak pilihan, daftar, dan marker peta, serta **satuan** otomatis (unit/meter/titik/lokasi/set) dan **harga satuan perkiraan** (diatur admin).

**Estimasi biaya** = jumlah × harga satuan, dihitung otomatis oleh sistem. Pengusul **tidak** mengisi atau melihat angka biaya; estimasi hanya tampil untuk Dishub, Bappeda, dan Admin.

### 5 kriteria — label awam (untuk pengusul) dan label resmi (untuk Dishub)
| Chip pengusul | Kriteria resmi Dishub |
|---|---|
| 🚑 Sering terjadi kecelakaan | Rawan kecelakaan lalu lintas |
| 👀 Pandangan terhalang / tikungan tajam | Jarak pandang terbatas |
| 🏫 Dekat sekolah / ramai anak-anak | Banyak aktivitas masyarakat, terutama anak-anak |
| 🚗 Lalu lintas ramai | Volume lalu lintas padat |
| 🛣️ Jalan utama | Akses jalan poros primer/sekunder |

Jumlah kriteria yang dinyatakan terpenuhi oleh Dishub = **skor prioritas 0–5**.

---

## 2. Aturan Desain "Simpel & Interaktif"

**Simpel**
1. **Satu tombol utama per layar**, besar, di posisi yang sama (bawah di HP, kanan bawah panel di desktop).
2. **Isi otomatis semua yang bisa ditebak:** koordinat (GPS/foto), alamat, kecamatan, desa (dari peta), nama instansi, tanggal hari ini, satuan, tahun anggaran (default tahun depan).
3. **Tap, bukan ketik:** kotak ikon untuk jenis, tombol − / + untuk jumlah, chip untuk alasan & keputusan. Ketik hanya untuk catatan.
4. **Maks. 3 langkah** untuk alur apa pun.
5. **Field opsional disembunyikan** di balik "Tambah keterangan" (tidak tampil sebagai kolom kosong).
6. **Tanpa tab di detail** — satu halaman gulir dengan urutan: status → foto & peta → item → riwayat.
7. **Pesan manusiawi:** "Usulan Anda sedang diperiksa Dishub (biasanya ± 7 hari)", bukan "Status: VERIFIKASI_PENDING".

**Interaktif**
8. **Umpan balik instan:** tombol berubah jadi loading lalu ✓, kartu bergeser keluar dari daftar setelah diproses, angka di tab langsung berkurang.
9. **Undo, bukan dialog konfirmasi:** toast "Item disetujui · Urungkan" (5 detik). Dialog hanya untuk **Tolak** dan **Minta Revisi** (karena wajib ada catatan). Lewat dari 5 detik, keputusan tetap bisa dikoreksi dengan **Ubah Keputusan** (lihat A2).
10. **Tersimpan otomatis** di form (indikator kecil "Tersimpan ✓"). Tidak ada tombol simpan draft.
11. **Realtime:** usulan baru muncul sendiri di daftar Dishub; status berubah sendiri di layar pengusul.
12. **Daftar + detail berdampingan** di desktop, dengan **Simpan & Berikutnya** dan pintasan keyboard (↑ ↓ pindah usulan, `L` lolos, `T` tolak, `Enter` simpan).
13. **Peta hidup:** pin bisa digeser, marker bisa diklik, peta dan daftar saling sinkron (hover baris → marker menyala).
14. **Animasi halus & cepat** (≤ 200 ms), skeleton saat memuat, bukan spinner layar penuh.

**Umum**
15. Mobile-first untuk pengusul dan halaman publik; desktop-first untuk Dishub & Bappeda (tetap bisa di HP).
16. Target sentuh min. 44 px, teks min. 16 px di HP.
17. Light & dark mode.
18. Halaman publik tidak pernah menampilkan nama, email, atau no. HP pengusul.

---

## 3. Kamus Status & Badge

Satu warna = satu makna: **abu** = belum mulai, **biru** = sedang diproses, **kuning** = perlu tindakan / ditunda, **merah** = ditolak, **hijau** = disetujui / terpasang.

| Entitas | Nilai (label UI) | Warna |
|---|---|---|
| Status usulan | Draft · Diperiksa Dishub · Perlu Revisi · Menunggu Bappeda · Disetujui · Terpasang · Ditunda · Ditolak | abu · biru · kuning · biru · hijau · hijau ✓ · kuning · merah |
| Status item | Menunggu · Lolos Dishub · Disetujui · Ditunda · Ditolak · Terpasang | abu · biru · hijau · kuning · merah · hijau ✓ |
| Skor prioritas | 0 · 1–2 · 3 · 4–5 | abu · kuning · oranye · merah |
| Jenis pengusul | Instansi · Masyarakat | tag netral (ikon gedung · ikon orang) |
| Label tambahan | Duplikat (ditolak karena sudah ada usulan yang sama) · Diubah (keputusan pernah dikoreksi) | tag netral |

**Status usulan dihitung otomatis dari item-itemnya:** Disetujui = minimal 1 item disetujui Bappeda; Terpasang = semua item yang disetujui sudah terpasang; Ditolak = semua item ditolak; Ditunda = sisa item semuanya ditunda.

---

## 4. Komponen Bersama

| Komponen | Keterangan |
|---|---|
| **App Shell** | Bar atas: logo · menu (2–3 item) · lonceng · avatar. Di HP: bar bawah + tombol bulat **+ Ajukan** (pengusul). |
| **Stepper Status** | 4 titik: Diajukan → Dishub → Bappeda → Terpasang, dengan tanggal dan kalimat penjelasan tahap aktif. Versi mini (4 titik kecil) di kartu daftar. |
| **Kartu Usulan** | Foto pertama · nomor · lokasi (desa, kecamatan) · ikon item · stepper mini · badge status. Dipakai di semua daftar & di HP. |
| **Map Picker** | Peta + pin yang bisa digeser + tombol **Lokasi saya**. Alamat di bawah peta ter-update saat pin digeser. |
| **Peta Sebaran** | Marker warna status + ikon jenis, cluster, popup kartu mini. Sinkron dengan daftar. |
| **Kamera & Foto** | Tombol besar **Ambil Foto** (buka kamera langsung) + "pilih dari galeri". Koordinat foto dibaca otomatis. Thumbnail bisa dihapus dengan ✕. |
| **Kotak Ikon Perlengkapan** | Grid 12 kotak ikon + nama. Tap = terpilih (muncul − / + dan jumlah). Tap lagi atau − sampai 0 = batal. |
| **Chip Pilihan** | Untuk alasan (multi) dan keputusan (✓ Lolos / ✕ Tolak; Setujui / Tunda / Tolak). |
| **Kartu Aksi** | Kotak di detail usulan berisi aksi sesuai role dan status. Hanya satu tombol utama. |
| **Toast + Urungkan** | Sukses dengan tombol Urungkan 5 detik; gagal dengan tombol Coba lagi. |
| **Dialog Catatan** | Satu kolom catatan + chip alasan cepat (mis. "Foto kurang jelas", "Lokasi bukan jalan kabupaten"). Dipakai untuk Tolak & Minta Revisi. |
| **Lightbox Foto** | Geser antar foto, tombol "Lihat di peta". |
| **Empty / Loading / Error** | Skeleton saat memuat; empty state ramah ("Belum ada usulan. Yuk ajukan yang pertama!") + tombol aksi. |

---

## 5. Peta Layar

```
Publik                              Aplikasi (setelah masuk)
──────────────────                  ─────────────────────────────────────────────
/               Beranda Publik      /usulan              Halaman kerja (tab per role)
/lacak/:nomor   Lacak Usulan          └ /usulan/:id      Detail (panel kanan / halaman penuh di HP)
/masuk          Masuk (kode email)  /usulan/baru         Ajukan (3 langkah)
                                    /usulan/:id/revisi   Revisi (hanya bagian yang ditandai)
                                    /laporan             Laporan (Dishub, Bappeda, Admin)
                                    /pengaturan          Pengaturan (Admin)
```

---

## 6. Spesifikasi Per Layar

### P1 · Beranda Publik `/` — *mobile-first*
- **Latar:** peta perlengkapan **terpasang** (marker ikon jenis, bisa di-tap → foto hasil pemasangan).
- **Kartu mengambang di atas peta:**
  - Judul singkat: "Usulkan perlengkapan jalan di sekitar Anda".
  - Kotak **Lacak Usulan** (input nomor + tombol).
  - Tombol utama **Ajukan Usulan** → `/masuk` jika belum masuk.
- **Di bawah (gulir):** 3 langkah cara mengajukan (Foto lokasi → Pilih kebutuhan → Kirim), angka ringkas tahun ini (masuk · disetujui · terpasang), 12 ikon jenis perlengkapan.

### P2 · Lacak Usulan `/lacak/:nomor`
- Nomor + tanggal, desa/kecamatan, **Stepper Status** besar dengan kalimat penjelasan, daftar item (ikon, jumlah, badge), foto hasil jika sudah terpasang.
- Tombol **Bagikan** (salin link / WhatsApp).
- **State:** nomor tidak ditemukan → kotak lacak ulang.

### P3 · Masuk `/masuk`
- Langkah 1: kolom **Email** → tombol **Kirim Kode**.
- Langkah 2: **6 kotak kode** (otomatis pindah kotak, bisa tempel), hitung mundur kirim ulang. Masuk otomatis saat 6 digit lengkap.
- **Pengguna baru** (masyarakat): satu layar tambahan **Nama** + **No. HP (WA)** → langsung ke form Ajukan (jika tadi menekan Ajukan) atau ke Usulan Saya.
- Sesi bertahan 30 hari, jadi jarang perlu masuk ulang.
- Catatan kecil: "Akun instansi dibuat oleh Dishub. Hubungi admin jika belum punya."

---

### A1 · Usulan — Halaman Kerja `/usulan` ⭐
Halaman pertama setelah masuk. **Desktop:** daftar di kiri (±40%), detail usulan terpilih di kanan (A2). **HP:** daftar kartu, tap → detail halaman penuh.

- **Atas:** tab sesuai role (dengan angka), kolom cari, tombol filter (1 tombol → chip: kecamatan, jenis, skor, tahun), toggle **Daftar ↔ Peta**.
- **Pengusul — tab Aktif · Selesai:**
  - Kartu kuning **Perlu Revisi** selalu paling atas, dengan tombol **Perbaiki**.
  - Kartu Usulan dengan stepper mini + kalimat status ("Sedang diperiksa Dishub · 3 hari").
  - Tombol **+ Ajukan Usulan** (FAB di HP).
  - Empty: ilustrasi + "Belum ada usulan" + tombol Ajukan.
- **Dishub — tab Perlu Diperiksa · Siap Dipasang · Semua:**
  - Perlu Diperiksa: urut terlama, label "menunggu 8 hari" (merah jika lewat batas), "Revisi ke-2", "Ada usulan berdekatan".
  - Siap Dipasang: **baris = item** yang disetujui Bappeda (ikon jenis, jumlah, lokasi, tahun anggaran), tombol **Tandai Terpasang** langsung di baris. Mode Peta berguna untuk rute pemasangan.
- **Bappeda — tab Menunggu Keputusan · Ditunda · Semua:**
  - Urut **skor tertinggi**. Baris = usulan yang bisa dibuka untuk melihat item-itemnya, **checkbox per item**.
  - Tiap item menampilkan **estimasi biaya**; tiap usulan menampilkan totalnya.
  - Saat ada yang dicentang, muncul bar bawah: "**12 item dipilih · Rp 184.500.000** · Tahun anggaran [2027 ▾] · **[Setujui]** · Tunda · Tolak". Total biaya ter-update langsung setiap centang.
  - Ringkasan kecil di bar: jumlah per jenis ("Rambu 20 unit · PJU 8 titik…").
  - Di atas daftar: **total yang sudah disetujui untuk tahun anggaran terpilih** (mis. "Disetujui 2027: Rp 1,2 M"), agar Bappeda bisa menjaga pagu.
  - Tab **Ditunda**: item dikelompokkan per tahun target; bisa dicentang dan diputuskan ulang dengan bar bawah yang sama.
- **Admin — tab Semua:** hanya lihat.
- **Mode Peta:** peta memenuhi area daftar; filter & tab tetap berlaku; klik marker = buka detail di kanan.

### A2 · Detail Usulan `/usulan/:id` ⭐
Satu halaman gulir, **tanpa tab**:

1. **Kepala:** nomor, badge status, pengusul (nama + tag Instansi/Masyarakat; kontak hanya untuk Dishub/Admin), skor (jika sudah diperiksa).
2. **Stepper Status** + kalimat penjelasan tahap saat ini.
3. **Kartu Aksi** (lihat di bawah) — di desktop menempel (sticky) di atas panel; di HP tombol utama menempel di bawah layar.
4. **Foto & lokasi:** galeri foto geser + peta kecil dengan pin; alamat, desa, kecamatan. Info "2 usulan lain dalam 100 m" (bisa diklik).
5. **Kebutuhan:** daftar item (ikon, jumlah, badge status item, catatan jika ada). Untuk Dishub/Bappeda/Admin: estimasi biaya per item + total.
6. **Alasan:** chip kriteria yang dipilih pengusul + uraian singkat.
7. **Proposal:** kartu file (nama, ukuran) → buka pratinjau.
8. **Riwayat** (terlipat, "Lihat riwayat"): timeline singkat siapa melakukan apa dan kapan.

**Kartu Aksi per role & status:**
| Role · Status | Isi kartu |
|---|---|
| Pengusul · Draft | "Lanjutkan mengisi" **[Lanjutkan]** · hapus (ikon) |
| Pengusul · Perlu Revisi | Catatan Dishub + bagian yang ditandai **[Perbaiki]** |
| Pengusul · lainnya | Kalimat status + **[Bagikan link lacak]** · Batalkan (hanya saat belum diperiksa, dengan Urungkan) |
| **Dishub · Diperiksa Dishub** | **Periksa** (lihat di bawah) |
| **Bappeda · Menunggu Bappeda** | Skor + catatan Dishub + total estimasi biaya, lalu per item chip **Setujui / Tunda / Tolak** (Setujui = default terpilih), tahun anggaran terisi otomatis → **[Simpan & Berikutnya]** |
| **Bappeda · Ditunda** | "Ditunda ke 2028 · alasan …", lalu chip **Setujui / Tunda lagi / Tolak** per item yang ditunda → **[Simpan]** |
| **Dishub · Disetujui** | Per item tombol **[Tandai Terpasang]** |
| **Dishub · sudah diperiksa** (Bappeda belum memutuskan) | Ringkasan hasil pemeriksaan + tombol teks **Ubah Keputusan** |
| **Bappeda · sudah diputuskan** (item belum terpasang) | Ringkasan keputusan + tombol teks **Ubah Keputusan** |
| Admin | Tanpa kartu aksi |

**Kartu Periksa (Dishub)** — target ± 5 tap:
1. **Kriteria:** 5 chip (label resmi). Yang diklaim pengusul sudah ditandai tipis; Dishub tap untuk menyatakan terpenuhi. **Skor** besar ter-update langsung.
2. **Item:** tiap item chip **✓ Lolos** / **✕ Tolak** (default ✓ jika skor ≥ 1). Jumlah bisa diubah dengan − / +.
3. **Data jalan** (satu baris, opsional): status jalan (Primer/Sekunder/Lokal/Lingkungan).
4. Tombol utama **[Simpan & Berikutnya]**. Tombol teks **Minta Revisi** → Dialog Catatan dengan chip bagian (Foto · Lokasi · Kebutuhan · Proposal) + catatan.
5. Jika ada usulan berdekatan: tampil perbandingan singkat (foto + item kedua usulan berdampingan) dan tombol teks **Tandai Duplikat** → pilih usulan aslinya → semua item otomatis **Ditolak** dengan label *Duplikat* dan tautan ke usulan asli. Pengusul diberi tahu dan bisa **melacak usulan asli** itu.
- Tolak item → Dialog Catatan dengan chip alasan cepat.

**Ubah Keputusan (koreksi)** — untuk salah klik yang baru disadari belakangan:
- **Dishub** bisa mengubah hasil pemeriksaan **selama Bappeda belum memutuskan** item tersebut. Kartu Periksa terbuka lagi dengan isian sebelumnya.
- **Bappeda** bisa mengubah keputusan **selama item belum Terpasang**.
- Wajib isi alasan singkat (satu kolom). Perubahan tercatat di Riwayat ("Diubah dari Tolak → Lolos oleh …"), item diberi label *Diubah*, dan pihak terkait (pengusul, Dishub/Bappeda) diberi tahu.
- Setelah tahap berikutnya berjalan, tombol **Ubah Keputusan** tidak tampil lagi.

### A3 · Ajukan Usulan `/usulan/baru` — *mobile-first* ⭐
Target: **± 2 menit** di HP. Progress 3 titik di atas, tombol **Lanjut** besar menempel di bawah, "Tersimpan ✓" kecil di pojok.

**① Foto & Lokasi**
- Tombol besar **📷 Ambil Foto Lokasi** (langsung kamera). Min. 1, maks. 10.
- Begitu foto pertama diambil, **peta muncul dengan pin di lokasi foto/GPS** + alamat, desa, kecamatan terisi otomatis. Pengguna cukup memastikan; pin bisa digeser.
- **Jika foto tidak punya koordinat** (mis. diunggah dari laptop) atau GPS mati: muncul pesan ramah "Lokasi foto tidak terbaca. Tandai lokasinya di peta, ya" → peta dengan kolom **cari alamat/nama tempat** + tap untuk menaruh pin. Alamat, desa, kecamatan tetap terisi otomatis dari pin.
- Tombol teks **Tandai lokasi di peta** selalu tersedia untuk memilih lokasi manual kapan saja.
- Jika ada usulan aktif dalam ±100 m: kartu kecil kuning "Sudah ada usulan di dekat sini" + tombol lihat. Tetap bisa lanjut.

**② Apa yang dibutuhkan?**
- **Kotak Ikon Perlengkapan** (12). Tap untuk memilih, − / + untuk jumlah. Min. 1.
- **"Kenapa perlu?"** 5 chip label awam (pilih yang sesuai).
- "Tambah keterangan" (opsional, terlipat): satu kolom teks.

**③ Proposal & Kirim**
- Ringkasan singkat (foto pertama, lokasi, item) — tap untuk kembali mengubah.
- **Proposal:**
  - Instansi: **Unggah PDF** (wajib).
  - Masyarakat: pilihan **Buat otomatis dari isian** (default, pratinjau PDF) atau **Unggah sendiri**.
- Instansi: nama instansi terisi otomatis; kolom penanggung jawab & no. HP terisi dari akun.
- Tombol **Kirim Usulan** → **layar sukses** dengan animasi ✓, nomor usulan besar, tombol **Salin** & **Bagikan ke WA**, "Kami kabari lewat email saat ada perkembangan."

**Revisi `/usulan/:id/revisi`:** hanya menampilkan bagian yang ditandai Dishub, dengan catatan Dishub di atasnya. Tombol **Kirim Ulang**.

### A4 · Laporan `/laporan`
- **Filter** (satu baris): tahun, kecamatan, jenis.
- **4 angka besar:** masuk · lolos Dishub · disetujui Bappeda · terpasang, masing-masing dengan **total estimasi biaya** di bawahnya.
- **Funnel** Diajukan → Lolos → Disetujui → Terpasang.
- **Rekap per jenis perlengkapan** dan **per kecamatan** (jumlah + estimasi biaya; tabel sederhana, bisa diklik → daftar usulan terfilter).
- Tombol **Export Excel** · **Export PDF** (rekap siap cetak).

### A5 · Pengaturan `/pengaturan` (Admin)
- **Pengguna:** tabel nama, email, role, instansi, aktif. **+ Tambah** (modal: nama, email, role, instansi). Tap toggle untuk aktif/nonaktif. Karena tanpa password, tidak ada reset password.
- **Instansi:** nama, jenis (Desa/Kelurahan · Kecamatan · Sekolah · OPD · Lainnya), wilayah.
- **Perlengkapan:** 12 jenis (ikon, nama, satuan, **harga satuan perkiraan (Rp)**, aktif) — urutan bisa digeser. Harga bisa diubah langsung di tabel; perubahan harga hanya berlaku untuk estimasi item yang belum disetujui Bappeda.
- **Wilayah & Jalan:** kecamatan → desa; ruas jalan + status + kewenangan; import Excel. Pengaturan kecil: radius usulan berdekatan, batas hari pemeriksaan.

### Modal Profil (dari avatar)
Nama, no. HP, email (hanya baca), role & instansi, toggle notifikasi email/WA, tombol **Keluar**.

### Notifikasi (lonceng + email)
| Kejadian | Penerima |
|---|---|
| Usulan baru / dikirim ulang | Dishub |
| Diminta revisi | Pengusul |
| Hasil pemeriksaan Dishub | Pengusul (+ Bappeda jika ada item lolos) |
| Keputusan Bappeda | Pengusul, Dishub |
| Keputusan diubah (koreksi) | Pengusul + pihak yang terdampak (Bappeda jika Dishub mengubah; Dishub jika Bappeda mengubah) |
| Usulan ditandai duplikat | Pengusul (dengan link usulan asli) |
| Item terpasang | Pengusul |
| Usulan menunggu melewati batas hari | Dishub |

---

## 7. Alur Kunci untuk Diuji di Prototipe (Flow Experience)

1. **Warga mengajukan pertama kali (HP, ± 2 menit):** Beranda publik → **Ajukan** → email → kode → nama + no. HP → 📷 foto (lokasi terisi) → tap Rambu +2, Pita getar +1 → tap "Dekat sekolah" → proposal otomatis → **Kirim** → nomor usulan → Bagikan ke WA.
2. **Lacak tanpa login:** buka link dari WA → stepper "Diperiksa Dishub · 3 hari".
3. **Dishub memeriksa beruntun (desktop):** tab Perlu Diperiksa → usulan pertama terbuka di kanan → tap 3 kriteria (skor 3) → ✓ Rambu, ✕ Pita getar (chip alasan) → **Simpan & Berikutnya** → usulan kedua langsung terbuka.
4. **Minta revisi → perbaiki:** Dishub **Minta Revisi** (chip Foto) → pengusul dapat notifikasi → kartu kuning **Perbaiki** → hanya langkah Foto yang tampil → tambah foto → **Kirim Ulang**.
5. **Bappeda menyetujui massal:** tab Menunggu Keputusan (urut skor) → centang 12 item → bar menunjukkan **Rp 184,5 jt**, di atas daftar terlihat total yang sudah disetujui untuk 2027 → **Setujui** → toast "12 item disetujui · Urungkan".
6. **Dishub menandai terpasang di lapangan (HP):** tab Siap Dipasang → mode Peta → tap marker → **Tandai Terpasang** → 📷 foto hasil (GPS & tanggal otomatis) → selesai → item muncul di peta publik, pengusul dapat notifikasi.
7. **Usulan duplikat:** pengusul lain menaruh pin 60 m dari usulan yang ada → kartu kuning → tetap kirim → Dishub melihat label "Ada usulan berdekatan" → bandingkan → **Tandai Duplikat** → pengusul kedua dapat notifikasi + link lacak usulan asli.
8. **Koreksi salah klik:** besok harinya Dishub sadar item Pita getar seharusnya lolos → buka usulan → **Ubah Keputusan** → ubah ke ✓ Lolos + alasan → item masuk antrian Bappeda, riwayat mencatat perubahan.
9. **Putuskan ulang yang ditunda:** tahun berikutnya Bappeda → tab Ditunda → kelompok "Target 2028" → centang → **Setujui**.
10. **Instansi mengajukan dari laptop:** unggah foto tanpa GPS → "Lokasi foto tidak terbaca" → cari nama jalan → tap peta → lanjut.
11. **Laporan:** Laporan → tahun 2027 → Export PDF.

**Modal Tandai Terpasang** (bottom sheet di HP): 📷 foto hasil* (min. 1), jumlah terpasang (default = jumlah disetujui), tanggal (default hari ini), lokasi (default titik usulan, GPS otomatis), "Tambah keterangan" (opsional) → **[Selesai]**.

## 8. Daftar Deliverable Desain

- [ ] Komponen bersama + kamus badge, light & dark
- [ ] **Prioritas utama:** A3 Ajukan Usulan 3 langkah (HP) + layar sukses
- [ ] **Prioritas utama:** A1 + A2 desktop untuk Dishub (daftar + panel Periksa) dan Bappeda (checkbox + bar Setujui)
- [ ] P1 Beranda Publik, P2 Lacak, P3 Masuk (HP)
- [ ] A1/A2 versi Pengusul (HP), termasuk kartu Perlu Revisi
- [ ] Tab Siap Dipasang mode Peta + modal Tandai Terpasang (HP)
- [ ] A4 Laporan, A5 Pengaturan (desktop), modal Profil
- [ ] Micro-interaction: toast Urungkan, kartu keluar dari daftar setelah diproses, auto-save, input kode 6 digit
- [ ] Kartu Aksi varian **Ubah Keputusan**, **Tandai Duplikat** (perbandingan 2 usulan), dan Bappeda tab **Ditunda**
- [ ] Langkah ① versi **foto tanpa koordinat** (tandai lokasi manual di peta)
- [ ] State kosong / loading (skeleton) / error untuk daftar, peta, dan detail

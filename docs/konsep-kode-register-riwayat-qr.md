# Konsep: Kode Register, Riwayat Pemeliharaan, QR, Ekspor Aset Terpasang

> Status: konsep siap dikerjakan pendev. Desain layar ada di `siperjal.pen`: **AS1 · Aset — Dishub (Desktop)**, **AS2 · Aset — Pindai QR (HP)**, **AS3 · Pengaturan — Kode Singkat** (jenis + kecamatan), **AS4 · Aset — Dishub (HP)**, **AS5 · Beranda — Popup Peta Berkode**. Kode juga sudah ditambahkan di PM1 (detail laporan) dan PM2 (form lapor).

## Context
Klien (Pak Agung) meminta, pada 23–24 Okt 2026:
1. Setiap unit perlengkapan terpasang punya **kode register** `JENIS-NNN-KEC-YY` (mis. `RMB-001-BTL-26`). Kode lampu PJU **ditunda**.
2. Fitur **unduh** daftar terpasang, koordinat bisa diklik → Google Maps.
3. **Riwayat pemeliharaan**: dari laporan selesai **dan** input langsung petugas (pemeliharaan rutin); lihat per item; halaman khusus riwayat per tanggal; **QR per item** (bisa batch) yang bila dipindai menampilkan info item + riwayat.

Tujuan dokumen ini: satu konsep yang sinkron dengan aplikasi yang ada (aset = `perlengkapan_terpasang`, pemeliharaan = `LaporanKerusakan`), dapat langsung diberikan ke pendev. Arah UX tetap: sesimpel & seinteraktif mungkin.

## Keputusan
Sudah dikonfirmasi:
- **1 baris = 1 unit.** Baris `jumlah > 1` dipecah; form/impor tetap menerima "jumlah" dan sistem membuat N unit.
- **Nomor urut di-reset per jenis + kecamatan + tahun pasang.** Tahun = 2 digit dari `terpasang_at`/`tahun`.

**Perlu dikonfirmasi (ditemukan saat memeriksa kode):** tidak semua jenis bisa dipecah per unit. Di `DatabaseSeeder`, satuan *Marka jalan* dan *Pagar pengaman (guardrail)* adalah `meter`, *ZoSS* `lokasi`, *Pita getar* `set`. Memecah marka 120 m menjadi 120 "unit" berkode tidak masuk akal. Usulan: kolom `per_unit` (boolean) di `jenis_perlengkapan`, bawaannya `true` untuk satuan `unit`/`titik`, dapat diubah admin di Pengaturan. Jenis `per_unit = false` tetap **1 baris = 1 ruas/paket** dengan `jumlah` (meter/set) dan **satu** kode register. Seluruh aturan di bawah tentang "memecah" hanya berlaku untuk jenis `per_unit = true`.

## Temuan kode saat ini (dasar konsep)
- `PerlengkapanTerpasang`: tidak ada kolom kode; `jumlah`, `harga_satuan`, `nilai()` (= `jumlah × harga_satuan`). Dibuat di 3 jalur:
  - `UsulanItem::sinkronAset()` (`app/Models/UsulanItem.php:113`): `$this->aset()->updateOrCreate([], …)`, satu baris per item usulan.
  - `ImporTerpasang::simpan()` (`app/Support/ImporTerpasang.php`): satu baris per baris berkas, `jumlah` dari kolom Jumlah.
  - `pages/terpasang/index` `simpan()`: satu lokasi + beberapa jenis (`items[]` dengan `jenis`, `jumlah`, `harga`, jenis `distinct`); `create` satu baris per jenis dalam transaksi. Mode ubah memakai `items[0]` dan hanya untuk data lama (`lama()->findOrFail`).
- Relasi yang menghalangi pemecahan: `UsulanItem::aset()` **hasOne**; kolom `perlengkapan_terpasang.usulan_item_id` **unique** (migrasi `2026_10_19_000001`); `sinkronAset()` memakai `updateOrCreate([], …)`.
- `JenisPerlengkapan` & `Kecamatan`: **tidak ada kode singkat**. `kecamatan_id` dan `tahun`/`terpasang_at` di aset **nullable**.
- Tidak ada pemeliharaan rutin; satu-satunya riwayat per aset = `laporanKerusakan` (status Selesai punya `selesai_at`, `catatan_selesai`, foto `hasil`, `ditutup_oleh`).
- `/terpasang` tidak punya tampilan detail (hanya dialog ubah, khusus data lama). `<x-kategori>` punya 3 tujuan (Usulan, Pemeliharaan, Terpasang).
- QR/tautan lama yang sudah beredar: `/lapor/aset/{id}` (id aset) dan `/lapor/{item}` (id item usulan, redirect 301 lewat `where('usulan_item_id', …)->firstOrFail()`). **Keduanya harus tetap hidup.**
- Nomor laporan `LPR-…` dibuat dengan `count() + 1` di `LaporanKerusakan::buat()`. Pola ini **tidak boleh** ditiru untuk kode register (lihat A).
- Belum ada library QR. Ekspor: dompdf + spatie/simple-excel.

## Konsep fitur & pengalaman

### A. Kode register
- Format `{KODE_JENIS}-{NNN}-{KODE_KEC}-{YY}`. NNN minimal 3 digit dan melebar bila lewat 999 (`RMB-1000-BTL-26`). Unik, **tidak berubah** setelah dibuat, kecuali admin mengoreksi secara eksplisit (tercatat di `LogPengaturan`). Mengubah lokasi/tahun aset **tidak** mengubah kodenya.
- Dibuat otomatis di semua jalur pembuatan aset lewat satu service `App\Support\KodeRegister::buat(jenis, kecamatan, tahun): string`.
- **Nomor urut dari tabel penghitung, bukan `MAX()+1` atau `count()+1`:**
  - Tabel `kode_register_urut`: `jenis_perlengkapan_id`, `kecamatan_id`, `tahun` (smallint), `terakhir` (unsigned int), unique `(jenis_perlengkapan_id, kecamatan_id, tahun)`.
  - Di dalam `DB::transaction`: `insertOrIgnore` baris penghitung (terakhir = 0), lalu `lockForUpdate()` baris itu, `terakhir + 1`, simpan, dan rakit kodenya. Dua penyimpanan bersamaan jadi antre, bukan menghasilkan nomor ganda.
  - Kolom `perlengkapan_terpasang.kode` tetap `unique` sebagai pengaman terakhir.
  - Karena penghitung hanya naik, nomor dari aset yang dihapus (`batalTerpasang()`, hapus data lama, batalkan impor) **tidak dipakai ulang**.
- Admin mengisi **kode singkat** di Pengaturan, memakai pola `ubahIsian()` yang ada (validasi: 2–4 huruf kapital, unik). Mengubah kode singkat hanya berlaku untuk unit baru.
  - Tab **Perlengkapan**: kolom Kode, dan di kolom Satuan pilihan **Per unit / Per ruas** (= `per_unit`).
  - Tab **Wilayah & Jalan**: kolom Kode per kecamatan, dengan contoh kode jadi dan jumlah unit yang masih menunggu kode.
- Aset tanpa kode jenis, kode kecamatan, kecamatan, atau tahun **tetap boleh dibuat**, tetapi `kode` dibiarkan `null` dan muncul di filter **"Belum berkode"** di `/terpasang`. Begitu datanya lengkap (admin mengisi kode singkat / Dishub mengisi kecamatan atau tahun), kode dibuat otomatis. Ini mencegah alur "tandai terpasang" gagal hanya karena master belum lengkap.
- Data lama (migrasi):
  1. Untuk jenis `per_unit`, pecah baris `jumlah = N > 1`: **baris asli tetap menjadi unit pertama (id tidak berubah)**, lalu buat N−1 baris baru dengan data yang sama dan `jumlah = 1`. Dengan begitu `/lapor/aset/{id}` yang sudah tercetak dan `laporan_kerusakan.perlengkapan_terpasang_id` lama otomatis menunjuk unit pertama, tanpa pemetaan ulang.
  2. Back-fill kode berurutan menurut `terpasang_at` (atau `tahun`), lalu `id`, sambil mengisi tabel penghitung.
  3. Unit tanpa kecamatan/tahun dilewati (masuk "Belum berkode").
  4. Catat jumlah baris yang dipecah dan yang dilewati di output migrasi.
- Tampil di: daftar terpasang, popup peta beranda, halaman lapor publik, detail pemeliharaan, ekspor, label QR. Bisa dicari di kotak cari `/terpasang`.
- Kode lampu PJU: **di luar cakupan**; kolom nullable `kode_lampu` opsional, tanpa UI.

### A2. Relasi usulan → banyak unit
- `UsulanItem::aset()` → `asetUnit()` **hasMany** (atau ganti nama dan sesuaikan semua pemakai). Hapus unique pada `perlengkapan_terpasang.usulan_item_id` (index biasa tetap ada).
- `sinkronAset()` dibuat **idempoten**: target jumlah unit = `per_unit ? (jumlah_terpasang ?? jumlah) : 1`.
  - Perbarui data bersama (lat/lng, alamat, kecamatan, desa, `terpasang_at`, `tahun`, catatan, harga) di semua unit yang ada. Kode tidak ikut berubah.
  - Kurang dari target → buat unit baru, masing-masing dengan kode baru.
  - Lebih dari target → hapus unit **terbaru** yang tidak punya `pemeliharaan` maupun `laporan_kerusakan`. Bila yang tersisa untuk dihapus semuanya punya riwayat, tolak dengan pesan jelas ("3 unit sudah punya riwayat; jumlah tidak bisa dikurangi di bawah 3").
  - Tautan laporan: `LaporanKerusakan::where('usulan_item_id', …)->whereNull('perlengkapan_terpasang_id')` diarahkan ke unit **pertama** (id terkecil).
- `batalTerpasang()` (tombol Urungkan) **ditolak** bila ada unit item itu yang sudah punya `pemeliharaan` atau `laporan_kerusakan`. Dalam pemakaian normal (Urungkan beberapa detik setelah menandai) kondisi ini tidak terjadi, jadi aturannya hanya pengaman.
- Rute `/lapor/{item}`: ubah menjadi `->orderBy('id')->firstOrFail()` agar selalu ke unit pertama.

### B. Detail aset + riwayat per item
- Klik baris di `/terpasang` membuka **panel detail** (pola master-detail seperti Usulan/Pemeliharaan; di HP halaman penuh): kode (besar, bisa disalin), jenis, lokasi + mini-peta, tahun pasang, sumber (nomor usulan, atau "sebelum SIPERJAL"), harga satuan, tombol **Lihat QR**, **Catat pemeliharaan**, dan **Linimasa riwayat**.
- Linimasa diawali peristiwa **Terpasang** (tanggal pasang, nomor usulan atau "sebelum SIPERJAL", siapa yang menandai). Peristiwa ini dibaca dari data aset, bukan baris di tabel `pemeliharaan`.
- Daftar punya filter cepat **N dilaporkan rusak** dan **N belum berkode**. Di HP ada tambahan **Dekat saya** (urut jarak dari GPS), karena petugas sering membuka daftar di lapangan.
- Tombol **Ubah** hanya untuk data lama, sama seperti sekarang (`ubah()` memakai `lama()`). Aset dari usulan diubah lewat usulannya. Di mode ubah satu unit, kolom jumlah disembunyikan.
- Koordinat = tautan `https://www.google.com/maps?q={lat},{lng}` (buka tab baru) di panel detail, daftar, dan ekspor.

### C. Pencatatan pemeliharaan (model data baru)
Tabel baru `pemeliharaan` (riwayat tunggal, satu sumber kebenaran):
`id, perlengkapan_terpasang_id, tanggal, jenis_kegiatan (rutin|perbaikan|penggantian|pembersihan|lainnya), catatan, petugas_id, laporan_kerusakan_id (nullable, unique), timestamps` + foto (`pemeliharaan_foto`, atau generalisasi `LaporanFoto`).
- **Dari laporan**: `LaporanKerusakan::selesai()` otomatis membuat baris `pemeliharaan` (`laporan_kerusakan_id` terisi, tanggal = `selesai_at`, catatan & foto hasil ditautkan, petugas = penutup). `batalSelesai()` menghapusnya (selaras jendela 10 menit yang ada). Laporan tanpa aset (`perlengkapan_terpasang_id` null) tidak membuat baris.
- **Rutin / non-laporan**: Dishub (koordinator & petugas lapangan) menekan **Catat pemeliharaan** dari detail aset, atau **dari hasil scan QR** (alur tercepat di lapangan): pilih kegiatan (chip), catatan opsional, foto opsional, tanggal bawaan hari ini. Satu layar, satu tombol Simpan, toast + Urungkan (token `pmh-catat:{id}`).
- Izin: catat = `isKoordinator()` atau petugas lapangan; hapus = koordinator, atau petugas untuk catatan miliknya ≤10 menit. Admin hanya melihat. `abort_unless` di server.
- Tidak perlu notifikasi ke pelapor untuk catatan rutin.
- Penghapusan aset: `hapus()` di `/terpasang` dan `ImporTerpasang::batalkan()` sekarang hanya mengecek `laporanKerusakan`; tambahkan juga `pemeliharaan` (`whereDoesntHave('pemeliharaan')`).

### D. Halaman Riwayat (per tanggal)
- **Diputuskan: tab.** Pemilih kategori menjadi Usulan · Pemeliharaan · **Aset**; di dalam Aset ada tab **Terpasang** dan **Riwayat** (empat tujuan terlalu sempit di panel 440 px).
- Tampilan: dikelompokkan per tanggal (header tanggal, lalu baris: kode, kegiatan, jenis + lokasi singkat, petugas, penanda "dari laporan LPR-…" atau "Rutin"). Filter: rentang tanggal (Hari ini / 7 hari / Bulan ini / pilih), jenis, kecamatan, sumber (laporan / rutin), petugas. Cari kode. Klik baris → detail aset dengan catatan itu disorot.
- Petugas lapangan: bawaan terfilter ke catatan miliknya.
- Unduh Excel/PDF riwayat menurut filter yang aktif.

### E. QR per item + batch
- Isi QR = URL publik **`/aset/{kode}`**. Kode berurutan, jadi URL ini **mudah ditebak** (RMB-001, RMB-002, …). Ini diterima karena halaman hanya berisi data yang memang sudah publik: semua aset terpasang sudah tampil di peta beranda beserta popupnya. Halaman ini tidak boleh menampilkan harga, nama lengkap petugas, atau data pelapor. Bila kelak ada data yang tidak boleh ditebak, tambahkan token acak per aset di URL QR; untuk sekarang tidak perlu.
- `/lapor/aset/{id}` dan `/lapor/{item}` lama tetap berfungsi; halaman baru menyediakan tombol "Laporkan kerusakan" yang menuju `/lapor/aset/{id}`.
- **Halaman publik hasil scan** (`pages/aset/publik`, layout `layouts/publik`, HP-first, tanpa login): kode besar, jenis + foto/ikon, lokasi + tautan Maps, tanggal pasang, kondisi (Baik / Dilaporkan rusak), **riwayat pemeliharaan** (tanggal, kegiatan, catatan ringkas, foto hasil bila disetujui klien), tombol **Laporkan kerusakan**. Kode tidak ditemukan → state "Kode tidak ditemukan" dengan kolom ketik ulang kode, format `JENIS-NNN-KEC-YY` (bukan 404, pola `/lacak`).
- Bila yang memindai sudah login sebagai Dishub: bilah sesi + tombol **Catat pemeliharaan** (scan → catat dalam 2 ketukan) dan nama petugas di riwayat.
- **Generate QR**: `bacon/bacon-qr-code` (SVG, tanpa ekstensi GD). Satu item: **Lihat QR** di detail (modal, Unduh PNG/SVG, Cetak). **Batch**: di `/terpasang` mode pilih (centang baris / "pilih semua hasil") → panel **Cetak label QR**: pilih ukuran (sedang 60×30 mm, 24/lembar · kecil 40×20 mm, 65/lembar), pratinjau lembar A4, **Unduh PDF label** (dompdf). Maksimal 200 label per unduhan, dengan pesan jelas.
- Unit tanpa kode ("Belum berkode") tidak bisa dicetak QR-nya; tampilkan alasannya.

### F. Unduh daftar terpasang
- Tombol **Unduh** di kepala `/terpasang` (Excel, + CSV opsional) mengikuti filter aktif. Kolom: Kode register, Jenis, Jumlah + satuan (untuk jenis non-unit), Kecamatan, Desa, Alamat, Koordinat (`=HYPERLINK(...)` ke Maps), Tahun pasang, Sumber (nomor usulan / sebelum SIPERJAL), Harga satuan, Status (baik/dilaporkan), Pemeliharaan terakhir. Pola `LaporanExportController` + spatie/simple-excel.
- Template impor diperbarui: kolom Kode opsional (kosong = dibuat otomatis); impor tidak boleh menimpa kode yang ada. Untuk jenis `per_unit`, satu baris berkas dengan Jumlah N menghasilkan N unit.

## Dampak pemecahan unit ke fitur lain (hasil periksa kode, 26 Okt 2026)
`PerlengkapanTerpasang` dipakai di file berikut. Angka yang memakai `jumlah` tetap benar (karena `jumlah = 1` per unit); yang berubah adalah semua yang **menghitung baris** dan semua yang **menampilkan satu penanda per baris**.

| File | Pemakaian sekarang | Yang harus disesuaikan |
|---|---|---|
| `pages/terpasang/index.blade.php` | `jumlahSemua`: `count()`, `lama()->count()`, `sum(jumlah * harga_satuan)`; daftar `limit(300)`; baris menampilkan `{jumlah} {satuan}`; form `items.*.jumlah` (1–999) | Nilai tetap benar. "N tercatat" sekarang berarti unit, pastikan teksnya berbunyi "unit". Batas 300 baris lebih cepat tercapai: ganti ke paginasi. Baris menampilkan kode, bukan jumlah (kecuali jenis non-unit). `simpan()` membuat N unit per jenis lewat `KodeRegister`. `hapus()` cek juga `pemeliharaan`. |
| `pages/beranda.blade.php` | `terpasang`: `limit(500)`, satu penanda peta per baris; "N titik sudah terpasang" / "N perlengkapan terpasang" dari `count()` | 5 unit di koordinat sama = 5 penanda bertumpuk dan batas 500 cepat habis. Kelompokkan per `(jenis, lat, lng)` untuk peta; penanda diberi angka jumlah unit; popup menampilkan daftar kode (tautan ke `/aset/{kode}`) dengan status masing-masing, dan satu tombol Laporkan kerusakan. Teks "titik" dihitung dari kelompok, bukan unit. |
| `pages/laporan.blade.php` | Tahapan memakai `UsulanItem` (tidak terdampak); "+N terpasang sebelum SIPERJAL" dari `PerlengkapanTerpasang::lama()->count()` | Angka "+N" akan naik setelah pemecahan, dan satuannya beda dengan "Item terpasang" (item usulan). Ubah teksnya menjadi "+N unit terpasang sebelum SIPERJAL" atau hitung per kelompok. |
| `pages/pemeliharaan/lapor.blade.php` + `LaporanKerusakan::perlengkapanTerpasang()` / `terpasangSekitar()` | Pilihan "perlengkapan terpasang di dekat sini" `take(3)` dari `sekitar()` | Unit di titik yang sama akan muncul sebagai 3 pilihan identik. Kelompokkan per `(jenis, lat, lng)`; laporan dari kelompok ditautkan ke unit pertama yang tidak punya laporan aktif. Petugas dapat memindahkannya ke unit lain saat Cek Kondisi (cukup dropdown kode). |
| `pages/pemeliharaan/publik.blade.php` | `/lapor/aset/{item}` route-model binding ke aset | Tetap jalan (baris asli = unit pertama). Tampilkan kode. |
| `components/pemeliharaan/⚡detail.blade.php` (+ `detail-pelapor`) | Menampilkan `{jumlah} {satuan}` dan mengelompokkan laporan berdekatan per `perlengkapan_terpasang_id` | Tampilkan kode register. Deteksi "laporan aktif lain di dekatnya" sebaiknya per kelompok lokasi, bukan per unit. |
| `pages/usulan/form.blade.php` | `sudahTerpasang` = `sekitar()` → "Sudah ada N perlengkapan terpasang di dekat sini" | N jadi jumlah unit; kelompokkan per jenis agar tidak menakut-nakuti pengusul ("3 lampu" bukan daftar 3 baris). |
| `app/Support/ImporTerpasang.php` | Dedup "Sudah tercatat di titik ini" via `sekitar(…, 10 m, jenis)`; `simpan()` satu baris per baris berkas; `batalkan()` hanya cek laporan | Dedup tetap benar. `simpan()` membuat N unit + kode. `batalkan()` cek juga `pemeliharaan`. Pesan "N perlengkapan diimpor" pakai jumlah unit. |
| `routes/web.php` | `/lapor/{item}` → `where('usulan_item_id')->firstOrFail()` | Tambah `orderBy('id')`. Tambah `/aset/{kode}`, `/terpasang/unduh`, `/terpasang/qr`. |
| `UsulanItem.php`, `LaporanKerusakan.php` | `aset()` hasOne; `sinkronAset()`; `batalTerpasang()`; `selesai()`/`batalSelesai()` | Lihat A2 dan C. |
| `database/seeders/DemoSeeder.php`, `LaporanDemoSeeder.php` | Membuat aset dengan `jumlah` acak 1–3; nomor laporan `count()+1` | Buat lewat jalur yang sama dengan aplikasi (service), agar seed menghasilkan unit + kode. |
| `tests/Feature/PerlengkapanTerpasangTest.php`, `AlurPemeliharaanTest.php` | Mengasumsikan 1 baris dengan `jumlah` 2–5 (mis. baris 50, 123, 128, 142–149) | Perbarui ekspektasi: jenis per unit → N baris `jumlah = 1`, masing-masing berkode. |

## Perubahan teknis (ringkas untuk pendev)
- Migrasi:
  - `kode` (nullable, unique) di `perlengkapan_terpasang`; `kode` (nullable, unique) di `jenis_perlengkapan` & `kecamatan`; `per_unit` (boolean) di `jenis_perlengkapan`.
  - Hapus unique `usulan_item_id`.
  - Tabel `kode_register_urut`, `pemeliharaan` (+ foto).
  - Pecah baris (jenis `per_unit`), back-fill kode (lihat A).
  - `kode` sengaja tetap nullable karena ada status "Belum berkode".
- Kode: `App\Support\KodeRegister`; model `Pemeliharaan`; `UsulanItem::asetUnit()` hasMany + `sinkronAset()` idempoten + pengaman `batalTerpasang()`; hook di `LaporanKerusakan::selesai()/batalSelesai()`; ubah `ImporTerpasang`, form terpasang; `pages/terpasang/index` (tab Terpasang/Riwayat, panel detail, mode pilih, unduh); `pages/aset/publik`; `QrController`/`LabelQrController`; `<x-kategori>` (Terpasang → Aset); peta & popup beranda (kelompok lokasi); Pengaturan (kode jenis, kode kecamatan, per_unit).
- Rute baru: `/aset/{kode}` (publik), `/terpasang/unduh`, `/terpasang/qr` (POST batch → PDF). Riwayat adalah tab di `/terpasang` (mis. `?tab=riwayat`), bukan rute sendiri.
- Panduan: tambah artikel dishub/petugas-lapangan (catat pemeliharaan, cetak QR) dan pengusul/umum (scan QR); teks UI harus persis; `PanduanTest` memeriksa tautan.
- Update CLAUDE.md bagian peta kode.

## Urutan pengerjaan yang disarankan
1. Kode singkat jenis/kecamatan + `per_unit` + `kode_register_urut` + `KodeRegister` + relasi hasMany + migrasi pecah unit & back-fill (fondasi). Sesuaikan tabel "Dampak" di atas dalam tahap yang sama, agar beranda/lapor tidak menampilkan penanda bertumpuk.
2. Panel detail aset + tautan Maps + unduh Excel.
3. Tabel `pemeliharaan` + hook dari laporan selesai + form Catat pemeliharaan.
4. Halaman publik `/aset/{kode}` + QR satu item.
5. Batch cetak label QR.
6. Tab Riwayat per tanggal + unduh riwayat.
7. Panduan + tes.

## Pertanyaan terbuka untuk klien (tidak menghambat mulai)
- **Jenis bersatuan meter/set/lokasi** (marka, guardrail, pita getar, ZoSS): setuju satu kode per ruas/paket, bukan per meter?
- Kode singkat resmi tiap jenis (12 jenis) & tiap kecamatan (daftar lengkap). Contoh klien `RMB` berarti "Rambu lalu lintas" satu kode untuk semua rambu (peringatan, larangan, petunjuk)?
- Apakah halaman scan publik boleh menampilkan foto hasil perbaikan & catatan, atau hanya tanggal + jenis kegiatan?
- Unit lama tanpa tahun pasang / tanpa kecamatan: dibiarkan "Belum berkode" sampai dilengkapi Dishub (usulan kami), atau diberi tahun tertentu?
- Ukuran fisik label QR yang akan dicetak.

## Verifikasi
- `php artisan test`, tambah tes:
  - kode unik & berurutan per (jenis, kecamatan, tahun); dua pembuatan bersamaan tidak menghasilkan nomor ganda; nomor tidak dipakai ulang setelah hapus;
  - 3 jalur pembuatan menghasilkan N unit berkode untuk jenis `per_unit` dan 1 baris untuk jenis meter/set;
  - `sinkronAset()` dua kali berturut-turut tidak menggandakan unit; mengurangi jumlah tidak menghapus unit yang punya riwayat;
  - `batalTerpasang()` ditolak bila ada unit dengan riwayat/laporan;
  - migrasi pemecahan: id baris asli tetap = unit pertama; laporan lama tetap tertaut;
  - `selesai()` membuat dan `batalSelesai()` menghapus baris pemeliharaan;
  - izin catat (koordinator/petugas ya, admin tidak);
  - `/aset/{kode}` publik tanpa harga/nama petugas/data pelapor; `/lapor/aset/{id}` dan `/lapor/{item}` lama tetap jalan;
  - unduh mengikuti filter; batch QR menolak > 200.
- Uji manual: seed demo → buka `/terpasang`, buka detail, Lihat QR, scan dengan HP, catat pemeliharaan, cek tab Riwayat, unduh Excel & buka tautan Maps, cetak label batch, cek peta beranda tidak menampilkan penanda bertumpuk. `npm run build` sebelum cek tampilan.

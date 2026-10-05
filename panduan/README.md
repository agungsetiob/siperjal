# Panduan Usulan Jalan — ditulis di Markdown

Satu file `.md` = satu artikel panduan. Halaman, menu samping, dan daftar isi dibangun otomatis dari isi file.
Desain acuan ada di `pencil-new.pen`, frame:

- **PG1 · Panduan — Artikel (Desktop)**
- **PG1 · Panduan — Artikel (HP)**
- **PANDUAN · Markdown → Komponen (Spesifikasi)**

## Struktur folder

```
panduan/
├─ pengusul/            ← nama folder = grup peran di menu samping
│  ├─ 01-ajukan-usulan.md   ← awalan angka = urutan, sisanya = slug halaman
│  ├─ 02-pantau-usulan.md
│  └─ 03-perbaiki-revisi.md
├─ dishub/
│  └─ 01-meninjau-usulan.md
└─ gambar/              ← semua screenshot
```

## Frontmatter

| Kunci | Dipakai untuk |
| --- | --- |
| `judul` | Judul besar halaman dan label di menu samping |
| `ringkas` | Kalimat di bawah judul dan hasil pencarian |
| `peran` | Grup di menu samping (Pengusul, Dishub, Bappeda, Petugas Lapangan) |
| `urutan` | Posisi di dalam grup |
| `durasi`, `perangkat`, `diperbarui` | Baris info di bawah judul |
| `layar` | (opsional) Daftar layar aplikasi tempat panduan ini relevan, mis. `layar: [usulan-masuk, siap-dipasang]`. Menu tombol **?** menampilkan panduan yang menyebut layar yang sedang dibuka. |
| `gambar` | (opsional) Gambar pratinjau tautan (WhatsApp). Bawaannya gambar pertama di isi. |

## Sintaks → komponen

| Tulis di `.md` | Tampil sebagai |
| --- | --- |
| `## 1. Judul langkah` | **MD · Judul Langkah** (bulatan nomor + judul Anton), masuk daftar isi |
| `## Sebelum mulai` | Kotak krem berisi daftar centang |
| `- butir` | **MD · Butir** |
| `> [!TIP]` / `> [!NOTE]` / `> [!WARNING]` | **MD · Callout** hijau "TIPS" / biru "INFO" / kuning "PERHATIAN" |
| `![keterangan](../gambar/x.png)` | Screenshot berbingkai + keterangan di bawahnya |
| blok ` ```gambar ` (lihat di bawah) | Screenshot + **MD · Penanda** bernomor + **MD · Keterangan Penanda** |
| `` `USL-2026-00123` `` | Kotak kode (Geist Mono) |

### Gambar dengan penanda

````md
```gambar
src: ../gambar/ajukan-1-foto-lokasi.png
keterangan: Langkah 1 dari 3 — Foto & Lokasi
penanda:
  - [91, 27, "Tambah foto kondisi jalan."]   # [x %, y %, teks]
  - [63, 44, "Geser pin jika lokasi kurang tepat."]
```
````

- `x`, `y` dalam **persen** dari kiri-atas gambar, jadi tetap pas di ukuran layar apa pun.
- Taruh penanda di **tepi** tombol, bukan di tengah teksnya, supaya label tombol tetap terbaca.
- Jaga x di antara 8–92 agar bulatan tidak terpotong tepi gambar.
- Di desktop: gambar di kiri, arahan di kanan. Di HP: gambar di tengah, arahan di bawahnya.

## Titik masuk ke panduan

| Di mana | Apa |
| --- | --- |
| Bar atas desktop | Tombol **?** (juga tombol keyboard `?`): panduan untuk layar yang sedang dibuka, lalu **Semua panduan**. |
| HP, Ajukan Usulan | Tautan "Pertama kali? Lihat panduan N menit". Hilang setelah pengguna pernah mengirim usulan. |
| HP, Profil | Baris **Panduan & bantuan** di bagian Bantuan. |
| Email kabar | Kabar revisi menyertakan tautan panduan (WhatsApp menyusul bila sudah tersambung). |
| Alamat tetap | `/panduan`, `/panduan/{grup}/{slug}`, dan `#langkah-N` untuk langsung ke satu langkah. |

Nama layar yang dikenal (`Panduan::LAYAR`): `usulan-saya`, `ajukan-usulan`, `revisi`, `laporan-saya`, `lapor-kerusakan`,
`usulan-masuk`, `siap-dipasang`, `keputusan`, `pagu-ditunda`, `pemeliharaan`, `laporan`, `pengaturan-ssh`,
`pengaturan-pengguna`, `pengaturan-wilayah`, `profil`, `notifikasi`, `masuk`. Layar dikenali dari rute, peran, dan tab
(`Panduan::layarDari()`). Layar baru: tambah namanya di `Panduan::LAYAR` dan aturannya di `layarDari()`.

## Menambah panduan baru

1. Ambil screenshot (ekspor frame dari desain atau screenshot HP), simpan di `gambar/`.
2. Salin file `.md` yang sudah ada, ganti frontmatter dan langkahnya.
3. Cek pratinjau di `/panduan`, pastikan penanda pas.
4. Commit & deploy. Menu samping dan daftar isi ikut diperbarui.

## Catatan untuk implementasi

- Parser: `App\Support\Panduan` (league/commonmark + pembaca frontmatter sederhana `kunci: nilai`, tanpa YAML penuh).
- Halaman: `PanduanController` → `/panduan` (daftar), `/panduan/{grup}/{slug}` (artikel), `/panduan/gambar/{nama}` (screenshot dari `panduan/gambar/`).
- Blok ` ```gambar ` dibaca oleh `Panduan::gambarDariBlok()`. Di GitHub/VS Code blok ini tetap tampil sebagai kode biasa, jadi file tetap terbaca.
- Screenshot yang lebih lebar daripada tinggi (layar desktop) tampil selebar kolom; screenshot HP tampil di bingkai ponsel.
- Daftar isi: semua `##` dari file. Pencarian: judul, ringkas, judul bagian, dan isi (di browser).
- Tautan antar artikel: `[teks](./02-pantau-usulan.md)` otomatis menjadi tautan halaman.

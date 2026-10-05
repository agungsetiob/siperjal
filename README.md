# SIPERJAL

Sistem informasi usulan perlengkapan jalan (Dishub & Bappeda). Dokumen konsep di `docs/`.

## Menjalankan (lokal)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # set APP_LOCALE=id, APP_TIMEZONE=Asia/Makassar
touch database/database.sqlite
php artisan migrate --seed && php artisan storage:link
php artisan db:seed --class=DemoSeeder   # opsional: 14 usulan contoh untuk presentasi
npm run build
php artisan serve
```

Masuk di `/masuk` dengan email. Di mode lokal, kode 6 digit tampil di layar (email tercatat di `storage/logs`).

| Akun contoh | Peran |
|---|---|
| warga@usuljalan.test | Pengusul masyarakat |
| desa@usuljalan.test | Pengusul instansi (Desa Kersik Putih) |
| dishub@usuljalan.test | Dishub |
| bappeda@usuljalan.test | Bappeda |
| admin@usuljalan.test | Admin (menu Pengaturan) |

Produksi: isi `MAIL_*` (SMTP) agar kode masuk & notifikasi terkirim, ganti data wilayah contoh di Pengaturan › Wilayah, dan sesuaikan `config/usuljalan.php` (titik awal peta, kontak).

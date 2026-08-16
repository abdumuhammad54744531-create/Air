# Deploy ke Domain / Hostinger

1. Di Hostinger, buat database MySQL dan user database. Catat host, nama database, username, dan password.
2. Hubungkan GitHub repository `abdumuhammad54744531-create/Air`, lalu deploy branch `main` ke domain atau subdomain.
3. Pilihan terbaik: atur **Document Root** domain ke folder `public` di dalam proyek.
   Jika panel tidak menyediakan pengaturan ini, aplikasi tetap dapat berjalan dari folder proyek utama melalui `.htaccess` bawaan.
4. Pastikan folder berikut dapat ditulis oleh PHP: `storage`, `storage/sessions`, `storage/uploads`, `storage/hydraulic`, dan `storage/backups`.
5. Buka `https://domain-anda.com/install` sekali saja. Pilih **Domain**, masukkan URL HTTPS dan kredensial database Hostinger, kemudian selesaikan instalasi.
6. Setelah selesai, installer terkunci otomatis. Masuk menggunakan akun admin lalu segera ganti kata sandi awal dan API key perangkat contoh.

## Konfigurasi produksi

Jika mengisi `.env` secara manual, gunakan pola berikut:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-anda.com
APP_TIMEZONE=Asia/Makassar
DB_HOST=host-database-hostinger
DB_PORT=3306
DB_DATABASE=nama_database
DB_USERNAME=user_database
DB_PASSWORD=password_database
APP_ALLOW_REINSTALL=false
```

Jangan unggah `.env`, folder `storage`, atau backup database dari komputer lokal. File-file tersebut telah diabaikan oleh Git.

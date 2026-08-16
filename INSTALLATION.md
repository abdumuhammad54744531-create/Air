# Panduan Instalasi Laragon

1. Pastikan Laragon menjalankan Apache dan MySQL.
2. Salin folder proyek menjadi `C:\laragon\www\aplikasi-web-air`.
3. Klik **Reload** di Laragon agar virtual host dibuat.
4. Buka `http://aplikasi-web-air.test/install`.
5. Gunakan host `127.0.0.1`, port `3306`, database `monitoring_air`, username `root`, dan password kosong untuk konfigurasi Laragon standar.

Instalasi manual:

```powershell
Copy-Item .env.example .env
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root < database\schema.sql
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root monitoring_air < database\seed.sql
```

Sesuaikan `APP_URL` apabila aplikasi dibuka melalui `http://localhost/aplikasi-web-air/public`.

Untuk produksi, gunakan `APP_ENV=production`, `APP_DEBUG=false`, ganti seluruh API key demo, ganti kata sandi awal, aktifkan HTTPS, dan batasi izin tulis hanya pada `storage`. Panduan lengkap deploy domain tersedia pada [DEPLOYMENT_HOSTINGER.md](DEPLOYMENT_HOSTINGER.md).

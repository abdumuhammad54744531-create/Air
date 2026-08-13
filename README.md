# Sistem Informasi Monitoring dan Manajemen Air (SIMMA)

SIMMA adalah aplikasi PHP/MySQL berbasis MVC untuk mengelola lokasi, perangkat IoT, sensor, pembacaan air, peringatan, pemeliharaan, kalibrasi, laporan, dan informasi publik.

Modul Pengelolaan Air menyediakan data sumber, reservoir, wilayah layanan, jaringan distribusi, wizard simulasi enam langkah, simulasi time-step, snapshot hasil, rekomendasi otomatis, laporan neraca air, dan integrasi debit Arduino/ESP32. Editor jaringan bergaya EPANET mendukung titik master otomatis maupun junction kosong, drag-and-drop posisi, banyak cabang atau pipa paralel pada satu titik, garis berarah, serta ikon dinamis untuk junction, sumber, reservoir, tangki, pompa, valve, dan meter.

Modul **Desain Otomatis Pipa dan Reservoir** memakai jaringan proyek yang sama untuk menghitung proyeksi kebutuhan, volume reservoir, kandidat diameter berdasarkan diameter dalam, Hazen–Williams atau Darcy–Weisbach, kelas tekanan, skenario operasi, dan alternatif biaya. Setiap kandidat dijalankan pada mesin EPANET; alternatif yang gagal atau tidak konvergen tidak dapat direkomendasikan. Hasil tersimpan per analisis dan tersedia sebagai laporan cetak/PDF.

## Persyaratan

- PHP 8.2+ dengan `pdo_mysql` dan `json`
- MySQL 8 / MariaDB 10.5+
- Apache dengan `mod_rewrite`
- Laragon pada Windows

## Instalasi cepat

1. Tempatkan proyek di `C:\laragon\www\aplikasi-web-air`.
2. Jalankan Apache dan MySQL dari Laragon.
3. Buka `http://aplikasi-web-air.test` atau `http://localhost/aplikasi-web-air/public`.
4. Installer akan memeriksa sistem, membuat `.env`, database, tabel, dan data contoh.
5. Masuk menggunakan `admin` / `Admin123!`, lalu segera ganti kata sandi.

Untuk basis data lama, buka menu **Jaringan Distribusi → Desain Otomatis** setelah memperbarui berkas aplikasi. Tabel modul dan master diameter akan dibuat satu kali secara aman. Migrasi manual tetap tersedia melalui `php database/migrations/20260806_automatic_design.php`.

Alternatif manual dijelaskan di [INSTALLATION.md](INSTALLATION.md).

## Struktur folder

- `app/Core`: bootstrap, konfigurasi, database, dan helper keamanan
- `app/Controllers`: autentikasi, dashboard, CRUD, API, dan halaman publik
- `app/Views`: layout serta halaman antarmuka
- `database`: skema dan data awal
- `public`: front controller dan aset web
- `storage`: upload, backup, dan penanda instalasi

## REST API singkat

Endpoint utama: `POST /api/v1/device/data`, dengan header `Authorization: Bearer API_KEY_PERANGKAT`. API key demo untuk `ALT-001` adalah `demo-api-key-alt-001`. Lihat [API_DOCUMENTATION.md](API_DOCUMENTATION.md).

## Backup

Gunakan `mysqldump monitoring_air > storage/backups/monitoring_air.sql`. Simpan backup di luar web root untuk produksi dan batasi akses folder `storage`.

## Halaman publik

Aktifkan `PUBLIC_PAGE_ENABLED=true` di `.env`. Hanya lokasi, alat, dan sensor dengan `is_public=1` yang akan tampil.

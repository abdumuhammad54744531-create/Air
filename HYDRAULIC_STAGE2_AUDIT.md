# Tahap 2 — Validasi dan Mesin Hidraulika

Tanggal implementasi: 30 Juli 2026

## Hasil

- Validator jaringan memeriksa node, sumber, tank, pipa, pompa, valve, meter, kurva, pola, endpoint, dan node terisolasi.
- Builder menerjemahkan data aplikasi ke model EPANET berunit LPS.
- Engine yang digunakan adalah OWA EPANET 2.3.5 Windows 64-bit pada `tools/epanet`.
- Tombol `Validasi Jaringan` dan `Run Analisis` tersedia di Editor Jaringan Distribusi.
- Panel `Tampilan Output` dapat menampilkan hasil per titik dan link langsung pada diagram.
- Hasil titik mencakup tekanan, head, demand aktual/rencana, defisit, persentase pemenuhan, kualitas, dan status.
- Hasil link mencakup debit, kecepatan, headloss per km, total headloss, arah aliran aktual, dan status.
- Extended Period Simulation menyediakan pemilih waktu hasil pada panel output.
- Run diblokir bila ada error validasi dan tidak mengubah data input.

## Uji engine

Ketiga model uji berikut berhasil dijalankan dengan exit code 0:

1. Reservoir → Junction → Pipe
2. Source/Well → Pump → Junction
3. Dua sumber alternatif → Junction

Jalankan ulang dengan:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\HydraulicStage2Test.php
```

## Snapshot data aktual

Pada pemeriksaan terakhir, jaringan aktual mempunyai 17 titik dan 31 link. Validator menemukan 30 error dan 2 peringatan. Penyebab utama adalah koefisien kekasaran pipa lama masih 0, sumber belum mempunyai head/elevasi, meter belum mempunyai target, dan satu node belum tersambung. Semua ini ditampilkan pada modal validasi agar dapat diperbaiki dari data titik/pipa.

## Batas tahap

Tahap 2 menyiapkan dan menjalankan model. Penyimpanan hasil per node/link, pewarnaan hasil pada diagram, tabel time-series, serta ekspor hasil direncanakan pada Tahap 3.

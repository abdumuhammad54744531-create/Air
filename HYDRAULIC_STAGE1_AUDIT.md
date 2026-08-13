# Audit dan Pemetaan Hidraulika Tahap 1

## Arsitektur

Aplikasi menggunakan PHP 8 dengan controller dan view internal, PDO–MySQL,
Bootstrap 5, JavaScript tanpa framework, dan SVG untuk editor jaringan.

## Pemetaan field lama

| Data aplikasi | Model hidraulika | Keputusan |
|---|---|---|
| `distribution_nodes.code` | Node ID | Dipakai langsung dan wajib unik |
| `name` | Label node | Tetap administratif |
| `elevation_m` | Elevation | Dipakai oleh junction/tank |
| `base_demand_lps` | Base demand | Dipakai oleh junction |
| `demand_pattern` | Legacy pattern code | Dipertahankan; pilihan baru memakai `demand_pattern_id` |
| `initial_pressure_m` | Tekanan lapangan lama | Tidak pernah dianggap hasil simulasi |
| `minimum_pressure_m` | Minimum pressure/PDA | Dipakai sebagai batas minimum |
| `maximum_pressure_m` | Batas peringatan | Tidak menjadi kondisi awal engine |
| `emitter_coefficient` | Emitter coefficient | Dipakai untuk emitter/leakage node |
| `initial_quality` | Initial quality | Dipakai sesuai jenis node |
| `source_quality` | Source quality | Dipakai hanya bila node menjadi source |
| `total_head_m` | Reservoir head | Dipakai oleh reservoir |
| `pipe_length_m` | Pipe length | Manual bila `use_manual_length=1` |
| `pipe_diameter_mm` | Pipe diameter | Dipakai oleh PIPE/VALVE |
| `roughness_coefficient` | Roughness | Dipakai sesuai formula headloss |
| `minor_loss_coefficient` | Minor loss | Dipakai oleh PIPE/VALVE |
| `check_valve` | CV pipe | Dipakai hanya untuk PIPE |
| `status` | OPEN/CLOSED mapping | Status aplikasi tetap disimpan |
| `planned_flow_lps` | Nilai pembanding | Bukan hasil debit |
| `max_pipe_capacity_lps` | Batas evaluasi | Bukan hasil engine |
| `loss_percent` | Skenario leakage | Tidak pernah dipakai sebagai headloss |
| `pump_status`, `pump_capacity_lps`, `pump_hours` | Properti pompa lama | Dipertahankan; form baru memakai field PUMP dinamis |

## Field yang salah tempat atau ganda

- Pompa dan valve secara hidraulika adalah link. Node pompa lama tidak dihapus dan
  dicatat untuk ditinjau sebelum migrasi manual ke link `PUMP`.
- `demand_pattern` dan `pump_curve` lama berupa teks. Keduanya dipertahankan,
  sementara data baru memakai tabel referensi.
- `loss_percent` adalah kebocoran skenario, bukan headloss.
- `initial_pressure_m` adalah data lapangan/pembanding, bukan hasil simulasi.
- Data sumber air master tidak disalin; node menyimpan `master_source_id`.

## Migrasi aman

Migrasi `database/migrations/20260730_hydraulic_stage1.php` hanya menambah tabel
dan kolom nullable/default. Link lama dipetakan sebagai `PIPE`. Jumlah node dan
link dibandingkan sebelum/sesudah, dan objek yang perlu tinjauan dicatat dalam
`hydraulic_migration_logs`.

## Batas Tahap 1

Tahap ini menyiapkan struktur data dan form dinamis. Validasi jaringan, payload
EPANET, eksekusi engine, penyimpanan run, hasil, dan visualisasi merupakan Tahap
2–4 dan belum boleh mengubah input asli.

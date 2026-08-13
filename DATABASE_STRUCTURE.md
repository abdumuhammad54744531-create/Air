# Struktur Database

Database `monitoring_air` memakai InnoDB, UTF-8, foreign key, unique key, dan soft delete pada data master.

Kelompok tabel:

- Identitas dan akses: `users`, `roles`, `permissions`, `role_permissions`, `user_roles`
- Master: `locations`, `devices`, `sensors`
- Telemetri: `sensor_readings`, `raw_device_payloads`, `device_heartbeats`, `device_status_logs`
- API: `api_keys`, `api_request_logs`
- Operasional: `alert_rules`, `alerts`, `alert_actions`, `maintenances`, `maintenance_items`, `damage_reports`, `calibrations`
- Publik: `public_settings`, `public_contents`, `announcements`
- Audit/sistem: `report_logs`, `activity_logs`, `application_settings`, `backups`, `login_attempts`, `password_resets`
- Pengelolaan air: `water_sources`, `reservoirs`, `service_areas`, `distribution_networks`
- Simulasi: `simulation_headers`, `simulation_sources`, `simulation_routes`, `simulation_reservoirs`, `simulation_service_areas`, `simulation_scenarios`, `simulation_scenario_sources`, `simulation_time_steps`, `simulation_results`

`sensor_readings` memiliki index pada `device_id`, `sensor_id`, `recorded_at`, `quality_status`, gabungan `(sensor_id, recorded_at)`, serta unique key `(device_id, packet_number, sensor_id, recorded_at)`.

Hasil simulasi menyimpan snapshot nama, debit, kebutuhan, populasi, kehilangan, dan kapasitas. Perubahan data master setelah simulasi tidak mengubah hasil yang telah tersimpan.

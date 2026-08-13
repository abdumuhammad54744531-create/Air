# Dokumentasi REST API v1

Base URL: `http://aplikasi-web-air.test/api/v1`

Endpoint perangkat memerlukan header:

```http
Authorization: Bearer API_KEY_PERANGKAT
Content-Type: application/json
```

## Kirim pembacaan

`POST /device/data`

```json
{
  "device_code": "ALT-001",
  "timestamp": "2026-07-30 10:30:00",
  "packet_number": "PKT-000001",
  "battery_voltage": 12.4,
  "signal_strength": -72,
  "readings": [
    {"sensor_code": "TMA-001", "parameter": "tinggi_muka_air", "value": 1.75, "unit": "m"}
  ]
}
```

Respons sukses menggunakan HTTP 201. Payload duplikat menggunakan HTTP 409; validasi gagal 422; autentikasi gagal 401.

## Heartbeat

`POST /device/heartbeat`

```json
{"device_code":"ALT-001","timestamp":"2026-07-30 10:35:00","status":"online","battery_voltage":12.3,"signal_strength":-70,"firmware_version":"1.0.5"}
```

## Konfigurasi dan waktu

- `GET /device/config` — konfigurasi alat dan sensor (Bearer wajib)
- `GET /device/time` — waktu server

## Endpoint publik

- `GET /public/latest`
- `GET /public/history`
- `GET /public/locations`

Endpoint publik memfilter data berdasarkan status publik lokasi, alat, dan sensor.

## Pembacaan debit sumber Arduino/ESP32

`POST /sensor/readings`

```json
{
  "device_id": "ALT-001",
  "api_key": "API_KEY_PERANGKAT",
  "source_code": "SRC-001",
  "flow_rate_lps": 45.72,
  "water_level_cm": 48,
  "pressure": 1.2,
  "battery_voltage": 12.4,
  "signal_strength": -70,
  "recorded_at": "2026-07-30 12:00:00"
}
```

Sumber air harus sudah dihubungkan dengan sensor debit pada menu Data Sumber Air. Sistem memvalidasi API key, menyimpan riwayat pembacaan, memperbarui debit sensor terkini pada sumber, serta mengubah status alat menjadi online.

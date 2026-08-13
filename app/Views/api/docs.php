<section class="page-head"><div><p class="eyebrow">Integrasi perangkat</p><h2>Dokumentasi REST API</h2><p>API versi 1 untuk perangkat IoT dan data publik.</p></div><span class="status-badge status-aktif">v1 Aktif</span></section>
<div class="api-layout"><aside class="panel api-nav"><a href="#auth">Autentikasi</a><a href="#data">Kirim Data</a><a href="#heartbeat">Heartbeat</a><a href="#public">API Publik</a></aside><div class="vstack gap-4">
<section class="panel p-4" id="auth"><h3>Autentikasi</h3><p>Endpoint perangkat memakai API key unik pada header Bearer.</p><pre><code>Authorization: Bearer API_KEY_PERANGKAT
Content-Type: application/json</code></pre><div class="alert alert-warning mb-0">API key demo: <code>demo-api-key-alt-001</code>. Regenerasi sebelum produksi.</div></section>
<section class="panel p-4" id="data"><div class="endpoint"><span>POST</span><code>/api/v1/device/data</code></div><p>Menerima beberapa pembacaan sensor dalam satu paket.</p><pre><code>{
  "device_code": "ALT-001",
  "timestamp": "2026-07-30 10:30:00",
  "packet_number": "PKT-000001",
  "battery_voltage": 12.4,
  "signal_strength": -72,
  "readings": [
    {"sensor_code":"TMA-001","parameter":"tinggi_muka_air","value":1.75,"unit":"m"},
    {"sensor_code":"DEB-001","parameter":"debit","value":45.72,"unit":"L/s"}
  ]
}</code></pre></section>
<section class="panel p-4" id="heartbeat"><div class="endpoint"><span>POST</span><code>/api/v1/device/heartbeat</code></div><pre><code>{"device_code":"ALT-001","timestamp":"2026-07-30 10:35:00","status":"online","battery_voltage":12.3,"signal_strength":-70,"firmware_version":"1.0.5"}</code></pre></section>
<section class="panel p-4" id="public"><h3>Endpoint publik</h3><p><code>GET /api/v1/public/latest</code> · <code>GET /api/v1/public/history</code> · <code>GET /api/v1/public/locations</code></p><p class="mb-0">Hanya lokasi, alat, dan sensor yang diizinkan administrator yang dikembalikan.</p></section></div></div>


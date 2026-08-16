<?php
$rows = $data['rows'] ?? [];
$errors = $data['errors'] ?? [];
$selectedLocation = $locationId ?: 0;
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Manajemen data</p>
    <h2>Data Sensor</h2>
    <p>Data dibaca langsung dari Google Sheet alat yang terhubung. Pembaruan pada sheet akan muncul otomatis di halaman ini.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="status-badge status-aktif"><i class="bi bi-arrow-repeat"></i> Sinkron langsung</span>
    <button class="btn btn-outline-primary" data-export-table="#googleSheetSensorTable"><i class="bi bi-download"></i> CSV</button>
  </div>
</section>

<div class="panel mb-3">
  <div class="p-3 p-md-4 d-flex flex-wrap justify-content-between gap-3 align-items-end">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
      <div class="col-md-6 col-lg-5">
        <label class="form-label mb-1" for="sheetLocation">Lokasi Sumber Air</label>
        <select class="form-select" name="location_id" id="sheetLocation" onchange="this.form.submit()">
          <option value="">Semua lokasi yang terhubung</option>
          <?php foreach ($locations as $location): ?>
            <option value="<?= (int)$location['id'] ?>" <?= $selectedLocation === (int)$location['id'] ? 'selected' : '' ?>><?= e(($location['code'] ? $location['code'].' · ' : '').$location['name']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Tampilkan</button></div>
    </form>
    <small class="text-secondary" id="googleSheetSyncStatus" data-updated-at="<?= e((string)($data['updated_at'] ?? '')) ?>"><i class="bi bi-clock-history"></i> Dimuat <?= e((string)($data['updated_at'] ?? '-')) ?> · <?= (int)($data['device_count'] ?? 0) ?> alat terhubung</small>
  </div>
</div>

<?php if (!$locations): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i> Belum ada alat yang dihubungkan ke Google Sheet. Buka <a href="<?= url('devices') ?>">Data Alat</a>, pilih lokasi sumber air, lalu isi URL Google Sheet dan GID tab-nya.</div>
<?php endif ?>
<?php foreach ($errors as $error): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(($error['device_name'] ?? 'Google Sheet').': '.($error['message'] ?? 'Tidak dapat dimuat.')) ?></div>
<?php endforeach ?>

<div class="panel" data-google-sheet-sensors data-endpoint="<?= url('sensors/google-sheet-data') ?>" data-location-id="<?= (int)$selectedLocation ?>" data-refresh-seconds="30">
  <div class="table-toolbar">
    <div><strong>Riwayat pembacaan sensor</strong><p class="mb-0 text-secondary small">Urutan berdasarkan tanggal dan waktu terbaru dari Google Sheet.</p></div>
    <span class="record-count" id="googleSheetRecordCount"><?= count($rows) ?> data ditampilkan</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle data-table mb-0" id="googleSheetSensorTable">
      <thead><tr><th>#</th><th>Tanggal</th><th>Waktu</th><th>Lokasi Sumber Air</th><th>Alat</th><th>Suhu (°C)</th><th>pH</th><th>TDS (mg/L)</th><th>Kecepatan (m/s)</th><th>Tinggi Air (m)</th><th>Sheet</th></tr></thead>
      <tbody id="googleSheetSensorRows">
      <?php if (!$rows): ?><tr data-empty-row><td colspan="11"><div class="empty-state"><i class="bi bi-table"></i><h4>Belum ada data sensor</h4><p>Hubungkan Data Alat ke Google Sheet, lalu data pembacaan akan tampil di sini.</p></div></td></tr><?php endif ?>
      <?php foreach ($rows as $index => $row): ?>
        <tr><td><?= $index + 1 ?></td><td><?= e($row['date']) ?></td><td><?= e($row['time']) ?></td><td><?= e($row['location_name']) ?></td><td><?= e($row['device_name']) ?></td><td><?= e($row['temperature'] ?? '—') ?></td><td><?= e($row['ph'] ?? '—') ?></td><td><?= e($row['tds'] ?? '—') ?></td><td><?= e($row['velocity'] ?? '—') ?></td><td><?= e($row['water_level'] ?? '—') ?></td><td><?= e($row['sheet_name']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

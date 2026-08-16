<?php $rows = $sheetData['rows'] ?? []; $errors = $sheetData['errors'] ?? []; $selectedDeviceId = (int)($deviceId ?? 0); ?>
<section class="page-head">
  <div>
    <p class="eyebrow">Manajemen data</p>
    <h2>Data Sensor</h2>
    <p>Data dibaca langsung dari Google Sheet pada Data Alat. Pembaruan pada sheet akan muncul otomatis di halaman ini.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="status-badge status-aktif"><i class="bi bi-arrow-repeat"></i> Sinkron langsung</span>
    <button class="btn btn-outline-primary" data-export-table="#googleSheetSensorTable"><i class="bi bi-download"></i> CSV</button>
  </div>
</section>

<div class="alert alert-info"><i class="bi bi-info-circle-fill"></i> Sumber pembacaan diatur pada <a href="<?= url('devices') ?>">Data Alat</a>: URL Google Sheet dan GID tab.</div>

<div class="panel mb-3"><div class="p-3 p-md-4">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-7 col-lg-5"><label class="form-label mb-1" for="sheetDevice">Pilih Data Alat</label>
      <select class="form-select" name="device_id" id="sheetDevice" onchange="this.form.submit()">
        <?php if (!$devices): ?><option value="">Belum ada alat terhubung</option><?php endif ?>
        <?php foreach ($devices as $device): ?><option value="<?= (int)$device['id'] ?>" <?= $selectedDeviceId === (int)$device['id'] ? 'selected' : '' ?>><?= e(($device['code'] ? $device['code'].' · ' : '').$device['name']) ?></option><?php endforeach ?>
      </select>
    </div>
    <div class="col-sm-4 col-md-3"><label class="form-label mb-1" for="googleSheetRefreshRate">Pembaruan otomatis</label><select class="form-select" id="googleSheetRefreshRate" aria-label="Interval pembaruan data"><option value="5">5 detik (tercepat)</option><option value="10">10 detik</option><option value="15">15 detik</option><option value="30" selected>30 detik</option><option value="60">1 menit</option><option value="120">2 menit</option></select></div>
    <div class="col-12"><small class="text-secondary" id="googleSheetSyncStatus"><i class="bi bi-clock-history"></i> Dimuat <?= e((string)($sheetData['updated_at'] ?? '-')) ?> · <?= (int)($sheetData['device_count'] ?? 0) ?> alat dibaca · Pembaruan otomatis aktif</small></div>
  </form>
</div></div>
<?php foreach ($errors as $error): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(($error['device_name'] ?? 'Google Sheet').': '.($error['message'] ?? 'Tidak dapat dimuat.')) ?></div>
<?php endforeach ?>
<?php if ($selectedDevice && empty($selectedDevice['google_sheet_url'])): ?>
  <div class="alert alert-warning"><i class="bi bi-link-45deg"></i> Alat <strong><?= e($selectedDevice['code'].' · '.$selectedDevice['name']) ?></strong> belum dihubungkan ke URL Google Sheet. Isi URL dan GID-nya pada Data Alat terlebih dahulu.</div>
<?php endif ?>

<div class="panel" data-google-sheet-sensors data-endpoint="<?= url('sensors/google-sheet-data') ?>" data-device-id="<?= $selectedDeviceId ?>" data-refresh-seconds="30">
  <div class="table-toolbar">
    <div><strong>Riwayat pembacaan sensor</strong><p class="mb-0 text-secondary small">Membaca maksimal 60 baris teratas dari Google Sheet untuk menjaga halaman tetap ringan.</p></div>
    <span class="record-count" id="googleSheetRecordCount"><?= count($rows) ?> data ditampilkan</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle data-table mb-0" id="googleSheetSensorTable">
      <thead><tr><th>#</th><th>Tanggal</th><th>Waktu</th><th>Suhu (°C)</th><th>pH</th><th>TDS (mg/L)</th><th>Kecepatan (m/s)</th><th>Tinggi Air (m)</th></tr></thead>
      <tbody id="googleSheetSensorRows">
      <?php if (!$rows): ?><tr data-empty-row><td colspan="8"><div class="empty-state"><i class="bi bi-table"></i><h4>Belum ada data sensor</h4><p>Hubungkan Data Alat ke Google Sheet, lalu data pembacaan akan tampil di sini.</p></div></td></tr><?php endif ?>
      <?php foreach ($rows as $index => $row): ?>
        <tr><td><?= $index + 1 ?></td><td><?= e($row['date']) ?></td><td><?= e($row['time']) ?></td><td><?= e($row['temperature'] ?? '—') ?></td><td><?= e($row['ph'] ?? '—') ?></td><td><?= e($row['tds'] ?? '—') ?></td><td><?= e($row['velocity'] ?? '—') ?></td><td><?= e($row['water_level'] ?? '—') ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

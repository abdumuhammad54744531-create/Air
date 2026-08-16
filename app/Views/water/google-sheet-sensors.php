<?php $rows = $data['rows'] ?? []; $errors = $data['errors'] ?? []; ?>
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
<?php foreach ($errors as $error): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(($error['device_name'] ?? 'Google Sheet').': '.($error['message'] ?? 'Tidak dapat dimuat.')) ?></div>
<?php endforeach ?>

<div class="panel" data-google-sheet-sensors data-endpoint="<?= url('sensors/google-sheet-data') ?>" data-refresh-seconds="30">
  <div class="table-toolbar">
    <div><strong>Riwayat pembacaan sensor</strong><p class="mb-0 text-secondary small">Urutan berdasarkan tanggal dan waktu terbaru dari Google Sheet.</p></div>
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

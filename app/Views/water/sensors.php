<section class="page-head">
  <div>
    <p class="eyebrow">Integrasi Arduino / ESP32</p>
    <h2>Monitoring Sensor Air</h2>
    <p>Riwayat pembacaan perangkat yang diterima oleh REST API.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?=url('api-docs')?>"><i class="bi bi-braces"></i> Dokumentasi API</a>
</section>

<div class="panel" id="waterSensorMonitor" data-sync-url="<?=e(url('water-sensor-monitoring/data'))?>" data-refresh-seconds="10">
  <div class="d-flex justify-content-between align-items-center px-3 pt-3">
    <small class="text-secondary"><span class="live-dot"></span> Diperbarui otomatis setiap 10 detik</small>
    <small class="text-secondary">Sinkron terakhir: <strong id="sensorSyncTime"><?=date('d/m/Y H:i:s')?></strong> WITA</small>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Waktu</th><th>Perangkat</th><th>Sensor</th><th>Nilai</th><th>Baterai</th><th>Sinyal</th><th>Status</th></tr></thead>
      <tbody id="waterSensorRows">
        <?php foreach($rows as $row):?>
          <tr>
            <td><?=e($row['recorded_at'])?></td>
            <td><strong><?=e($row['device_name'])?></strong><small class="d-block"><?=e($row['device_code'])?></small></td>
            <td><?=e($row['sensor_name'])?><small class="d-block"><?=e($row['sensor_code'])?></small></td>
            <td><strong><?=number_format($row['calibrated_value'],2,',','.')?></strong> <?=e($row['unit'])?></td>
            <td><?=$row['battery_voltage']!==null?e($row['battery_voltage']).' V':'—'?></td>
            <td><?=$row['signal_strength']!==null?e($row['signal_strength']).' dBm':'—'?></td>
            <td><span class="status-badge status-<?=e($row['quality_status'])?>"><?=e(ucfirst($row['quality_status']))?></span></td>
          </tr>
        <?php endforeach?>
        <?php if(!$rows):?><tr><td colspan="7" class="text-center text-secondary py-4">Belum ada data sensor yang diterima.</td></tr><?php endif?>
      </tbody>
    </table>
  </div>
</div>

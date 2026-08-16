<?php
$debit=(float)($latest['debit']['value']??0); $height=(float)($latest['tinggi_muka_air']['value']??0);
$speed=(float)($latest['kecepatan_aliran']['value']??($height>0?$debit/1000/($fixed['source_width']*$height):0));
$difference=$debit-$fixed['peak_demand']; $quantitySafe=$difference>=0; $qualitySafe=true;
$qualityParameters=['suhu_air'=>'Suhu','ph'=>'pH','tds'=>'TDS','kekeruhan'=>'Kekeruhan','oksigen_terlarut'=>'Oksigen Terlarut'];
foreach($qualityParameters as $key=>$label)if(isset($latest[$key])&&$latest[$key]['quality_status']!=='normal')$qualitySafe=false;
$fmt=fn($value,$decimals=2)=>number_format((float)$value,$decimals,',','.');
$mapSyncSignature=implode('|',array_map(fn($location)=>implode(':',[$location['code'],$location['latitude'],$location['longitude'],$location['updated_at']]),$locations));
?>
<main class="monitor-portal" data-public-sheet-refresh="30">
<header class="portal-header">
  <div class="portal-logo"><i class="bi bi-droplet-half"></i></div>
  <div class="portal-title"><h1>SISTEM PEMANTAUAN KUANTITAS DAN KUALITAS<br>SUMBER MATA AIR BERBASIS IoT</h1><p>Dashboard Monitoring Sistem Penyediaan Air Minum (SPAM)</p></div>
  <div class="portal-identity"><div><span>Nama</span><b>: <?=e($fixed['researcher_name'])?></b></div><div><span>NIM</span><b>: <?=e($fixed['researcher_id'])?></b></div><div><span>Program Studi</span><b>: <?=e($fixed['study_program'])?></b></div></div>
  <div class="portal-period"><div><i class="bi bi-calendar3"></i><span><b>Periode Data</b><strong><?=date('d/m/Y',strtotime('-7 days',strtotime($latestTime)))?> – <?=date('d/m/Y',strtotime($latestTime))?></strong></span></div><hr><small>Update Terakhir: <?=date('d/m/Y H:i',strtotime($latestTime))?> WITA</small><em><i></i> Terhubung</em><a href="<?=url('login')?>" title="Masuk admin"><i class="bi bi-person-lock"></i></a></div>
</header>

<section class="portal-location-bar">
  <form method="get" action="<?=url('publik')?>">
    <label for="publicLocation"><i class="bi bi-geo-alt-fill"></i><span>Pilih Lokasi Pemantauan<small>Hanya lokasi yang dipublikasikan oleh administrator</small></span></label>
    <select id="publicLocation" name="location" onchange="this.form.submit()">
      <?php foreach($locations as $location):?><option value="<?=$location['id']?>" <?=((int)$location['id']===$selectedLocationId)?'selected':''?>><?=e($location['name'])?> — <?=e($location['city'])?> (<?=$location['device_count']?> alat)</option><?php endforeach?>
    </select>
    <noscript><button type="submit">Tampilkan</button></noscript>
  </form>
  <div class="selected-location">
    <span><b><?=e($selectedLocation['name']??'Belum ada lokasi publik')?></b><small><?=e(($selectedLocation['type']??'').' · '.($selectedLocation['city']??''))?></small></span>
    <strong><?=count($devices)?> <small>alat publik</small></strong>
  </div>
  <label class="ms-auto d-flex align-items-center gap-2 small text-secondary">Pembaruan <select id="publicSheetRefreshRate" class="form-select form-select-sm" aria-label="Interval pembaruan web publik" style="width:auto"><option value="5">5 dtk</option><option value="10">10 dtk</option><option value="15">15 dtk</option><option value="30" selected>30 dtk</option><option value="60">1 mnt</option><option value="120">2 mnt</option></select></label>
</section>
<?php if($devices):?><section class="portal-devices">
  <?php foreach($devices as $device):?><article><i class="bi bi-router-fill"></i><span><b><?=e($device['name'])?></b><small><?=e($device['code'])?> · <?=e($device['type'])?></small></span><em class="<?=($device['connection_status']==='online')?'online':'offline'?>"><i></i><?=e(ucwords(str_replace('_',' ',$device['connection_status'])))?></em></article><?php endforeach?>
</section><?php else:?><div class="portal-empty"><i class="bi bi-router"></i> Belum ada alat yang diizinkan tampil pada lokasi ini.</div><?php endif?>

<section class="portal-top-grid">
  <article class="portal-card status-summary"><h2>RINGKASAN STATUS</h2>
    <div class="summary-item"><span class="hex <?=$quantitySafe?'safe':'unsafe'?>"><i class="bi bi-droplet"></i></span><div><small>KUANTITAS AIR</small><strong><?=$quantitySafe?'AMAN':'TIDAK AMAN'?></strong><p><?=$quantitySafe?'Debit tersedia mencukupi':'Debit belum mencukupi kebutuhan'?></p></div></div>
    <div class="summary-item"><span class="hex <?=$qualitySafe?'safe':'unsafe'?>"><i class="bi bi-shield-check"></i></span><div><small>KUALITAS AIR</small><strong><?=$qualitySafe?'AMAN':'TIDAK AMAN'?></strong><p><?=$qualitySafe?'Semua parameter dalam batas aman':'Ada parameter di luar batas aman'?></p></div></div>
  </article>
  <article class="portal-card main-indicators"><h2>INDIKATOR UTAMA</h2><div class="indicator-row">
    <div class="indicator"><i class="bi bi-water"></i><span>Debit Sumber<br>(Terkini)</span><strong><?=$fmt($debit)?> <small>L/s</small></strong><em class="<?=$quantitySafe?'safe':'unsafe'?>"><?=$quantitySafe?'AMAN':'TIDAK AMAN'?></em></div>
    <div class="indicator"><i class="bi bi-people-fill"></i><span>Kebutuhan Jam Puncak<br>(Tetapan)</span><strong><?=$fmt($fixed['peak_demand'])?> <small>L/s</small></strong><em class="safe">TETAPAN</em></div>
    <div class="indicator"><i class="bi bi-columns-gap"></i><span>Selisih Debit - Kebutuhan</span><strong><?=$fmt($difference)?> <small>L/s</small></strong><em class="<?=$quantitySafe?'safe':'unsafe'?>"><?=$quantitySafe?'AMAN':'KURANG'?></em></div>
    <div class="indicator"><i class="bi bi-rulers"></i><span>Tinggi Air (Terkini)</span><strong><?=$fmt($height)?> <small>m</small></strong></div>
    <div class="indicator"><i class="bi bi-wind"></i><span>Kecepatan Aliran</span><strong><?=$fmt($speed)?> <small>m/s</small></strong></div>
  </div></article>
</section>

<section class="portal-middle-grid">
  <article class="portal-card fixed-parameters"><h2>PARAMETER TETAP</h2><div class="fixed-line"><span>Lebar Mata Air (B)</span><b><?=$fmt($fixed['source_width'])?> <small>m</small></b></div><div class="fixed-line"><span>Kebutuhan Jam Puncak (Q<sub>req</sub>)</span><b><?=$fmt($fixed['peak_demand'])?> <small>L/s</small></b></div><div class="formula"><h3>RUMUS DEBIT</h3><strong>Q = V × A<br>A = B × H</strong><p>Q = Debit (m³/s)<br>V = Kecepatan aliran (m/s)<br>A = Luas penampang basah (m²)<br>B = Lebar mata air (m)<br>H = Tinggi air (m)</p></div></article>
  <article class="portal-card trend-card"><h2>TREND DEBIT SUMBER MATA AIR</h2><div class="trend-legend"><b>L/s</b><span><i></i> Debit (L/s)</span><span><i class="req"></i> Kebutuhan Jam Puncak</span></div><div class="portal-chart"><canvas id="portalDebitChart" data-trend='<?=e(json_encode($trend))?>' data-demand="<?=$fixed['peak_demand']?>"></canvas></div></article>
  <article class="portal-card quality-card"><h2>STATUS PARAMETER KUALITAS AIR (TERKINI)</h2><div class="table-responsive"><table><thead><tr><th>Parameter</th><th>Nilai</th><th>Baku Mutu*</th><th>Status</th></tr></thead><tbody>
  <?php $standards=['suhu_air'=>'≤ 30 °C','ph'=>'6 – 9','tds'=>'≤ 500 mg/L','kekeruhan'=>'≤ 25 NTU','oksigen_terlarut'=>'≥ 4 mg/L']; foreach($qualityParameters as $key=>$label):if(!isset($latest[$key]))continue;$r=$latest[$key];?>
  <tr><td><?=e($label)?></td><td><b><?=$fmt($r['value'])?></b> <?=e($r['unit'])?></td><td><?=e($standards[$key]??'—')?></td><td><span class="<?=$r['quality_status']==='normal'?'safe':'unsafe'?>"><?=$r['quality_status']==='normal'?'AMAN':strtoupper(e($r['quality_status']))?></span></td></tr><?php endforeach?>
  <tr><td>Kecepatan Aliran</td><td><b><?=$fmt($speed)?></b> m/s</td><td>—</td><td><span class="safe">AMAN</span></td></tr><tr><td>Tinggi Air</td><td><b><?=$fmt($height)?></b> m</td><td>—</td><td><span class="safe">AMAN</span></td></tr>
  </tbody></table></div></article>
</section>

<section class="portal-bottom-grid">
  <article class="portal-card monitoring-table"><h2>DATA MONITORING (SAMPEL JAM PUNCAK)</h2><div class="table-responsive"><table><thead><tr><th>Tanggal</th><th>Waktu</th><th>Suhu<br>(°C)</th><th>pH</th><th>TDS<br>(mg/L)</th><th>Kecepatan<br>(m/s)</th><th>Tinggi Air<br>(m)</th><th>Luas A<br>(m²)</th><th>Debit Q<br>(L/s)</th><th>Kebutuhan<br>(L/s)</th><th>Selisih<br>(L/s)</th><th>Status Kuantitas</th><th>Status Kualitas</th></tr></thead><tbody>
  <?php foreach($samples as $row):$q=(float)($row['debit']['value']??0);$h=(float)($row['tinggi_muka_air']['value']??0);$v=$h>0?$q/1000/($fixed['source_width']*$h):0;$diff=$q-$fixed['peak_demand'];$qSafe=$diff>=0;?>
  <tr><td><?=date('d/m/Y',strtotime($row['recorded_at']))?></td><td><?=date('H.i',strtotime($row['recorded_at']))?></td><td><?=$fmt($row['suhu_air']['value']??0)?></td><td><?=$fmt($row['ph']['value']??0)?></td><td><?=$fmt($row['tds']['value']??0)?></td><td><?=$fmt($v)?></td><td><?=$fmt($h)?></td><td><?=$fmt($fixed['source_width']*$h,3)?></td><td><?=$fmt($q)?></td><td><?=$fmt($fixed['peak_demand'])?></td><td class="<?=$qSafe?'':'negative'?>"><?=$fmt($diff)?></td><td><span class="<?=$qSafe?'safe':'unsafe'?>"><?=$qSafe?'AMAN':'TIDAK AMAN'?></span></td><td><span class="safe">AMAN</span></td></tr><?php endforeach?>
  </tbody></table></div><div class="table-note"><span>* Baku mutu mengacu pada PP No. 22 Tahun 2021 Lampiran VI.</span><b>Keterangan: <em class="safe"><i class="bi bi-shield-check"></i> AMAN</em><em class="unsafe"><i class="bi bi-shield-x"></i> TIDAK AMAN</em></b></div></article>
  <aside><article class="portal-card conclusion"><h2>KESIMPULAN</h2><div><span><b>KUANTITAS</b>Debit sumber mata air saat ini <strong><?=$quantitySafe?'MENCUKUPI':'BELUM MENCUKUPI'?></strong> kebutuhan jam puncak.</span><i class="bi <?=$quantitySafe?'bi-check-lg':'bi-x-lg'?>"></i></div><div><span><b>KUALITAS</b>Parameter kualitas air berada dalam batas <strong><?=$qualitySafe?'aman':'perlu perhatian'?></strong>.</span><i class="bi <?=$qualitySafe?'bi-check-lg':'bi-x-lg'?>"></i></div></article><article class="portal-card notes"><h2>CATATAN</h2><ul><li>Data diperoleh dari sensor IoT secara real-time.</li><li>Debit dihitung menggunakan rumus Q = V × (B × H).</li><li>Data ditampilkan berdasarkan pembacaan terbaru.</li></ul></article></aside>
</section>
<section class="portal-map-card portal-map-bottom">
  <div class="portal-map-head"><div><h2><i class="bi bi-map-fill"></i> PETA LOKASI MATA AIR</h2><p>Klik titik untuk melihat keterangan · Peta online · Lokasi sinkron otomatis setiap 30 detik</p></div><div class="portal-map-legend"><span><i class="active"></i> Lokasi dipilih</span><span><i></i> Lokasi lainnya</span></div></div>
  <div id="publicLocationsMap" data-selected="<?=$selectedLocationId?>" data-base-url="<?=e(url('publik'))?>" data-sync-url="<?=e(url('api/v1/public/locations'))?>" data-signature="<?=e($mapSyncSignature)?>" data-locations='<?=e(json_encode($locations))?>'></div>
</section>
</main>

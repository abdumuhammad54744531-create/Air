<?php
$typeLabels=['source'=>'Sumber Air','reservoir'=>'Reservoir','service_area'=>'Wilayah Layanan','node'=>'Titik Manual'];
$typeIcons=['source'=>'bi-droplet-fill','reservoir'=>'bi-box-fill','service_area'=>'bi-houses-fill','node'=>'bi-circle-fill'];
$manualKindIcons=['junction'=>'bi-circle-fill','source'=>'bi-droplet-fill','reservoir'=>'bi-box-fill','tank'=>'bi-database-fill','pompa'=>'bi-gear-wide-connected','valve'=>'bi-hourglass-split','meter'=>'bi-speedometer2'];
$originNodes=$nodes;
$destinationNodes=$nodes;
$masterNodes=$masterNodes??[];
$pumpCount=count(array_filter($networks,fn($link)=>strtoupper((string)($link['link_type']??'PIPE'))==='PUMP'));
$reservoirCount=count(array_filter($nodes,fn($node)=>$node['type']==='reservoir'||($node['node_kind']??'')==='tank'));
?>
<section class="page-head network-page-head">
  <div>
    <p class="eyebrow">Pengelolaan Air · Diagram Otomatis</p>
    <h2>Jaringan Distribusi</h2>
    <p><strong><?=e($project['code'].' · '.$project['name'])?></strong> — setiap proyek mempunyai diagram, titik, pipa, dan analisisnya sendiri.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <select class="form-select network-project-switcher" id="networkProjectSwitcher" aria-label="Pilih proyek jaringan"><?php foreach($projects as $item):?><option value="<?=$item['id']?>" <?=$item['id']==$project['id']?'selected':''?>><?=e($item['code'].' · '.$item['name'])?></option><?php endforeach?></select>
    <a class="btn btn-outline-secondary" href="<?=url('network-projects')?>"><i class="bi bi-folder2-open"></i> Daftar Proyek</a>
    <button class="btn btn-outline-success" type="button" id="networkValidateHydraulic"><i class="bi bi-shield-check"></i> Validasi Jaringan</button>
    <button class="btn btn-success" type="button" id="networkAnalysisMode"><i class="bi bi-cpu-fill"></i> Analisis / Desain</button>
    <button class="btn btn-outline-success" type="button" id="networkOpenPattern"><i class="bi bi-graph-up-arrow"></i> Pola Kebutuhan 24 Jam</button>
    <button class="btn btn-outline-primary" type="button" id="networkAddNode"><i class="bi bi-record-circle"></i> Tambah Titik</button>
    <button class="btn btn-outline-primary" type="button" id="networkDrawRoute"><i class="bi bi-pencil"></i> Gambar Pipa Langsung</button>
    <button class="btn btn-outline-warning" type="button" id="networkDrawPump"><i class="bi bi-gear-wide-connected"></i> Gambar Pompa</button>
  </div>
</section>

<section class="network-stat-grid">
  <article><span class="network-stat-icon blue"><i class="bi bi-diagram-3"></i></span><div><small>Total Titik · <?=$stats['manual_nodes']?> manual</small><strong><?=number_format($stats['nodes'],0,',','.')?></strong></div></article>
  <article><span class="network-stat-icon cyan"><i class="bi bi-bezier2"></i></span><div><small>Total Jalur</small><strong><?=number_format($stats['routes'],0,',','.')?></strong></div></article>
  <article><span class="network-stat-icon green"><i class="bi bi-check2-circle"></i></span><div><small>Jalur Aktif</small><strong><?=number_format($stats['active'],0,',','.')?></strong></div></article>
  <article><span class="network-stat-icon violet"><i class="bi bi-speedometer"></i></span><div><small>Debit Rencana</small><strong><?=number_format($stats['planned_flow'],2,',','.')?> <em>L/s</em></strong></div></article>
  <article><span class="network-stat-icon orange"><i class="bi bi-percent"></i></span><div><small>Rata-rata Kehilangan</small><strong><?=number_format($stats['loss'],2,',','.')?> <em>%</em></strong></div></article>
</section>

<div class="network-guide">
  <span><i class="bi bi-1-circle-fill"></i> Tambah titik pada grid</span><i class="bi bi-arrow-right"></i>
  <span><i class="bi bi-2-circle-fill"></i> Pilih Gambar Pipa Langsung</span><i class="bi bi-arrow-right"></i>
  <span><i class="bi bi-3-circle-fill"></i> Tarik dari satu titik ke titik lain</span><i class="bi bi-arrow-right"></i>
  <span><i class="bi bi-4-circle-fill"></i> Pilih Gambar Pompa untuk link PUMP</span>
  <strong><i class="bi bi-info-circle-fill"></i> Satu titik boleh memiliki banyak cabang pipa.</strong>
  <strong><i class="bi bi-keyboard"></i> Klik titik/pipa lalu tekan Delete untuk menghapus.</strong>
</div>

<section class="network-workspace">
  <div class="network-board-panel">
    <div class="network-toolbar">
      <div class="network-legend">
        <span><i class="legend-node source"></i> Sumber Air</span>
        <span><i class="legend-node reservoir"></i> Reservoir</span>
        <span><i class="legend-node service-area"></i> Wilayah</span>
        <span><i class="legend-node junction"></i> Junction Manual</span>
        <span><i class="legend-line active"></i> Jalur Aktif</span>
        <span><i class="legend-pump"><i class="bi bi-gear-fill"></i></i> Pompa</span>
        <span><i class="legend-line inactive"></i> Tidak Aktif</span>
      </div>
      <div class="network-zoom-tools" role="group" aria-label="Pengaturan tampilan diagram">
        <button type="button" title="Pusatkan seluruh jaringan" id="networkCameraReset"><i class="bi bi-crosshair"></i></button>
        <button type="button" title="Kanvas layar penuh" id="networkBoardFullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
      </div>
    </div>
    <div class="network-board-scroll">
      <div id="distributionNetworkBoard"
        data-position-url="<?=e(url('distribution-networks/position'))?>"
        data-create-node-url="<?=e(url('distribution-networks/node/quick'))?>"
        data-create-route-url="<?=e(url('distribution-networks/route/quick'))?>"
        data-hydraulic-validate-url="<?=e(url('distribution-networks/hydraulic/validate'))?>"
        data-hydraulic-run-url="<?=e(url('distribution-networks/hydraulic/run'))?>"
        data-delete-route-url="<?=e(url('distribution-networks'))?>"
        data-delete-node-url="<?=e(url('distribution-networks/node'))?>"
        data-can-delete="<?=has_role(['super_admin','administrator'])?'1':'0'?>"
        data-project-id="<?=$project['id']?>"
        data-nodes="<?=e(json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"
        data-master-nodes="<?=e(json_encode($masterNodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"
        data-routes="<?=e(json_encode($networks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>">
        <div class="network-column-title source-title">SUMBER AIR</div>
        <div class="network-column-title reservoir-title">RESERVOIR</div>
        <div class="network-column-title area-title">WILAYAH LAYANAN</div>
        <svg class="network-lines" id="distributionNetworkLines" aria-hidden="true">
          <defs>
            <marker id="networkArrowActive" viewBox="0 0 9 9" markerUnits="userSpaceOnUse" markerWidth="14" markerHeight="14" refX="8" refY="4.5" orient="auto-start-reverse" overflow="visible"><path d="M0,0 L0,9 L8.5,4.5 z" fill="#0b82d8"></path></marker>
            <marker id="networkArrowInactive" viewBox="0 0 9 9" markerUnits="userSpaceOnUse" markerWidth="14" markerHeight="14" refX="8" refY="4.5" orient="auto-start-reverse" overflow="visible"><path d="M0,0 L0,9 L8.5,4.5 z" fill="#94a3b8"></path></marker>
          </defs>
        </svg>
        <div id="networkNodes">
          <?php foreach($nodes as $node):$visualType=$node['type']==='node'&&in_array($node['master_type']??null,['source','reservoir','service_area'],true)?$node['master_type']:$node['type'];$visualIcon=$visualType==='node'?($manualKindIcons[$node['node_kind']]??'bi-circle-fill'):($typeIcons[$visualType]??'bi-circle-fill');?>
            <button type="button" class="network-node <?=e(str_replace('_','-',$node['type']))?> <?=$node['type']==='node'?'kind-'.e(str_replace('_','-',$visualType)):''?>"
              data-node-key="<?=e($node['key'])?>" data-node-type="<?=e($node['type'])?>"
              style="left:<?=$node['x']?>%;top:<?=$node['y']?>%"
              title="Klik untuk memilih · Geser untuk memindahkan">
              <span class="node-icon"><i class="bi <?=$visualIcon?>"></i></span>
              <span class="node-copy"><strong><?=e($node['name'])?></strong><small><?=e($node['code'])?></small></span>
              <span class="node-kind-label"><?=e($visualType==='node'?ucfirst($node['node_kind']):$typeLabels[$visualType])?></span>
              <span class="node-elevation">Elev. <?=number_format($node['elevation'],2,',','.')?> m</span>
              <span class="node-demand"><?php if($node['type']==='source'):?>Debit <?=number_format($node['sensor_flow']?:$node['normal_flow'],2,',','.')?> L/s<?php elseif($node['type']==='reservoir'):?>Kapasitas <?=number_format($node['capacity'],1,',','.')?> m³<?php elseif($node['type']==='service_area'):?>Kebutuhan <?=number_format($node['demand'],2,',','.')?> L/s<?php else:?>Demand <?=number_format($node['base_demand'],2,',','.')?> L/s<?php endif?></span>
              <?php if(!empty($node['description'])):?><span class="node-description"><?=e($node['description'])?></span><?php endif?>
            </button>
          <?php endforeach?>
        </div>
        <?php if(!$nodes):?>
          <div class="network-empty">
            <i class="bi bi-diagram-3"></i>
            <h3>Belum ada titik jaringan</h3>
            <p>Tambahkan sumber air, reservoir, atau wilayah layanan terlebih dahulu.</p>
          </div>
        <?php endif?>
      </div>
      <div class="network-selection-hint" id="networkSelectionHint"><i class="bi bi-cursor-fill"></i> Tambahkan titik atau pilih Gambar Pipa Langsung untuk mulai menggambar jaringan.</div>
    </div>
  </div>

  <aside class="network-side-panel">
    <section class="network-layer-panel">
      <button class="network-layer-title network-layer-toggle" type="button" id="networkLayerToggle" aria-expanded="false" aria-controls="networkLayerOptions">
        <span><i class="bi bi-layers"></i></span><div><h3>Tampilan Diagram</h3><p>Tekan untuk memilih informasi yang ditampilkan.</p></div><i class="bi bi-chevron-down network-layer-chevron"></i>
      </button>
      <div class="network-layer-options" id="networkLayerOptions" hidden>
       <div class="network-layer-group"><strong>Label Titik</strong>
        <label><input type="checkbox" data-network-layer="node-name" checked> Nama titik</label>
        <label><input type="checkbox" data-network-layer="node-code" checked> Kode titik</label>
        <label><input type="checkbox" data-network-layer="node-kind"> Jenis titik</label>
        <label><input type="checkbox" data-network-layer="node-elevation"> Elevasi</label>
        <label><input type="checkbox" data-network-layer="node-demand"> Kebutuhan/debit</label>
        <label><input type="checkbox" data-network-layer="node-description"> Keterangan node</label>
      </div>
      <div class="network-layer-group"><strong>Label Pipa</strong>
        <label><input type="checkbox" data-network-layer="pipe-name" checked> Nama/nomor pipa</label>
        <label><input type="checkbox" data-network-layer="pipe-length" checked> Panjang pipa</label>
        <label><input type="checkbox" data-network-layer="pipe-diameter"> Diameter pipa</label>
        <label><input type="checkbox" data-network-layer="pipe-type"> Jenis/material pipa</label>
        <label><input type="checkbox" data-network-layer="pipe-capacity"> Kapasitas maksimum</label>
        <label><input type="checkbox" data-network-layer="pipe-flow" checked> Debit aliran</label>
        <label><input type="checkbox" data-network-layer="pipe-loss"> Kehilangan air</label>
        <label><input type="checkbox" data-network-layer="pipe-roughness"> Kekasaran</label>
        <label><input type="checkbox" data-network-layer="pipe-minor-loss"> Minor loss</label>
        <label><input type="checkbox" data-network-layer="pipe-check-valve"> Check valve</label>
        <label><input type="checkbox" data-network-layer="pipe-pump"> Data pompa</label>
        <label><input type="checkbox" data-network-layer="pipe-status"> Status jalur</label>
        <label><input type="checkbox" data-network-layer="pipe-description"> Keterangan pipa</label>
      </div>
      <div class="network-layer-group network-range-group"><strong>Ukuran dan Arah</strong>
        <div class="network-range-control">
          <div><label for="networkFontScale"><i class="bi bi-fonts"></i> Ukuran tulisan</label><output id="networkFontScaleValue">100%</output></div>
          <input type="range" id="networkFontScale" min="70" max="170" step="10" value="100" aria-label="Ukuran tulisan diagram">
          <div class="network-range-ends"><span>Kecil</span><span>Besar</span></div>
        </div>
        <div class="network-range-control">
          <div><label for="networkArrowDirection"><i class="bi bi-arrow-left-right"></i> Arah panah</label><output id="networkArrowDirectionValue">Asal → Tujuan</output></div>
          <input type="range" id="networkArrowDirection" min="-1" max="1" step="1" value="1" aria-label="Arah panah jalur">
          <div class="network-range-ends"><span>Tujuan → Asal</span><span>Tanpa</span><span>Asal → Tujuan</span></div>
        </div>
        <div class="network-range-control">
          <div><label for="networkArrowScale"><i class="bi bi-cursor-fill"></i> Ukuran panah</label><output id="networkArrowScaleValue">100%</output></div>
          <input type="range" id="networkArrowScale" min="60" max="180" step="10" value="100" aria-label="Ukuran panah jalur">
          <div class="network-range-ends"><span>Kecil</span><span>Besar</span></div>
        </div>
        <div class="network-range-control">
          <div><label for="networkPointScale"><i class="bi bi-record-circle"></i> Ukuran titik</label><output id="networkPointScaleValue">100%</output></div>
          <input type="range" id="networkPointScale" min="60" max="180" step="10" value="100" aria-label="Ukuran titik jaringan">
          <div class="network-range-ends"><span>Kecil</span><span>Besar</span></div>
        </div>
      </div>
      </div>
    </section>
    <section class="network-layer-panel network-output-panel">
      <button class="network-layer-title network-layer-toggle" type="button" id="networkOutputToggle" aria-expanded="false" aria-controls="networkOutputOptions">
        <span><i class="bi bi-activity"></i></span><div><h3>Tampilan Output</h3><p>Pilih hasil analisis yang ditampilkan pada titik dan pipa.</p></div><i class="bi bi-chevron-down network-layer-chevron"></i>
      </button>
      <div class="network-layer-options" id="networkOutputOptions" hidden>
        <div class="network-output-state" id="networkOutputState"><i class="bi bi-info-circle"></i><span>Jalankan analisis EPANET untuk menghasilkan output.</span></div>
        <div class="network-layer-group"><strong>Waktu Hasil</strong>
          <div class="input-group input-group-sm"><select class="form-select" id="networkOutputTime" aria-label="Waktu hasil analisis"><option value="latest">Hasil terkini</option></select><button class="btn btn-outline-primary" type="button" id="networkOutputPlay" title="Putar simulasi 24 jam" disabled><i class="bi bi-play-fill"></i></button></div>
        </div>
        <div class="network-layer-group"><strong>Output Titik</strong>
          <label><input type="checkbox" data-network-output="node-pressure" checked> Tekanan (m)</label>
          <label><input type="checkbox" data-network-output="node-head" checked> Total head (m)</label>
          <label><input type="checkbox" data-network-output="node-demand"> Demand aktual (L/s)</label>
          <label><input type="checkbox" data-network-output="node-requested"> Demand rencana (L/s)</label>
          <label><input type="checkbox" data-network-output="node-deficit"> Defisit demand (L/s)</label>
          <label><input type="checkbox" data-network-output="node-fulfillment"> Pemenuhan kebutuhan (%)</label>
          <label><input type="checkbox" data-network-output="node-quality"> Kualitas air</label>
          <label><input type="checkbox" data-network-output="node-status"> Status tekanan</label>
        </div>
        <div class="network-layer-group"><strong>Output Pipa / Link</strong>
          <label><input type="checkbox" data-network-output="link-flow" checked> Debit aliran (L/s)</label>
          <label><input type="checkbox" data-network-output="link-velocity" checked> Kecepatan (m/s)</label>
          <label><input type="checkbox" data-network-output="link-unit-headloss"> Headloss per km (m/km)</label>
          <label><input type="checkbox" data-network-output="link-headloss"> Total headloss (m)</label>
          <label><input type="checkbox" data-network-output="link-direction"> Arah aliran aktual</label>
          <label><input type="checkbox" data-network-output="link-status"> Status link</label>
        </div>
        <div class="network-layer-group"><strong>Warna Hasil</strong>
          <label><input type="checkbox" data-network-output="color-pressure" checked> Warna titik berdasarkan tekanan</label>
          <label><input type="checkbox" data-network-output="color-velocity" checked> Warna pipa berdasarkan kecepatan</label>
        </div>
        <div class="network-layer-group network-range-group"><strong>Ukuran Tampilan Output</strong>
          <div class="network-range-control"><div><label for="networkOutputFontScale"><i class="bi bi-fonts"></i> Ukuran tulisan hasil</label><output id="networkOutputFontScaleValue">100%</output></div><input type="range" id="networkOutputFontScale" min="70" max="180" step="10" value="100"><div class="network-range-ends"><span>Kecil</span><span>Besar</span></div></div>
          <div class="network-range-control"><div><label for="networkOutputLabelScale"><i class="bi bi-bounding-box"></i> Ukuran kotak label</label><output id="networkOutputLabelScaleValue">100%</output></div><input type="range" id="networkOutputLabelScale" min="70" max="180" step="10" value="100"><div class="network-range-ends"><span>Kecil</span><span>Besar</span></div></div>
          <div class="network-range-control"><div><label for="networkOutputLineScale"><i class="bi bi-bezier2"></i> Ketebalan garis hasil</label><output id="networkOutputLineScaleValue">100%</output></div><input type="range" id="networkOutputLineScale" min="60" max="200" step="10" value="100"><div class="network-range-ends"><span>Tipis</span><span>Tebal</span></div></div>
          <div class="network-range-control"><div><label for="networkOutputMarkerScale"><i class="bi bi-record-circle"></i> Ukuran penanda warna</label><output id="networkOutputMarkerScaleValue">100%</output></div><input type="range" id="networkOutputMarkerScale" min="60" max="200" step="10" value="100"><div class="network-range-ends"><span>Kecil</span><span>Besar</span></div></div>
        </div>
      </div>
    </section>
    <section class="network-inspector" id="networkInspector">
      <div class="inspector-empty">
        <span><i class="bi bi-cursor"></i></span>
        <h3>Detail Titik</h3>
        <p>Klik simbol titik pada diagram untuk melihat atau mengubah datanya.</p>
      </div>
    </section>
  </aside>
</section>

<section class="panel mt-3 network-data-panel">
  <nav class="network-data-tabs nav nav-tabs" role="tablist" aria-label="Data jaringan">
    <button class="nav-link active" id="networkPointsTab" data-bs-toggle="tab" data-bs-target="#networkPointsPane" type="button" role="tab" aria-controls="networkPointsPane" aria-selected="true"><i class="bi bi-record-circle"></i> Daftar Titik <span><?=count($nodes)?></span></button>
    <button class="nav-link" id="networkPipesTab" data-bs-toggle="tab" data-bs-target="#networkPipesPane" type="button" role="tab" aria-controls="networkPipesPane" aria-selected="false"><i class="bi bi-bezier2"></i> Daftar Jalur Pipa <span><?=count($networks)?></span></button>
  </nav>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="networkPointsPane" role="tabpanel" aria-labelledby="networkPointsTab" tabindex="0">
      <div class="panel-title-row px-3 pt-3">
        <div><h3>Daftar Titik Jaringan</h3><p>Ubah jenis titik atau hapus titik manual beserta pipa yang terhubung.</p></div>
        <div class="d-flex gap-2"><a class="btn btn-primary btn-sm" href="<?=url('distribution-networks/bulk?tab=nodes&project='.$project['id'])?>"><i class="bi bi-pencil-square"></i> Edit Massal</a><button class="btn btn-outline-primary btn-sm" data-export-table="#networkPointTable"><i class="bi bi-download"></i> CSV</button></div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0" id="networkPointTable">
          <thead><tr><th>Kode / Nama Titik</th><th>Jenis</th><th>Elevasi</th><th>Debit / Kebutuhan</th><th>Pipa Terhubung</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php if(!$nodes):?><tr><td colspan="7" class="text-center text-secondary py-4">Belum ada titik jaringan.</td></tr><?php endif?>
            <?php foreach($nodes as $node):
              $visualType=$node['type']==='node'&&in_array($node['master_type']??null,['source','reservoir','service_area'],true)?$node['master_type']:$node['type'];
              $connectedCount=count(array_filter($networks,fn($route)=>$route['origin_key']===$node['key']||$route['destination_key']===$node['key']));
              $nodeKind=$node['type']==='node'?ucwords(str_replace('_',' ',$node['node_kind'])):$typeLabels[$node['type']];
              $nodeFlow=$node['type']==='source'?($node['sensor_flow']?:$node['normal_flow']):($node['type']==='service_area'?$node['demand']:($node['type']==='node'?$node['base_demand']:null));
            ?>
              <tr>
                <td><strong><?=e($node['code'])?></strong><small class="d-block text-secondary"><?=e($node['name'])?></small></td>
                <td><span class="network-kind-chip"><i class="bi <?=$visualType==='node'?($manualKindIcons[$node['node_kind']]??'bi-circle-fill'):($typeIcons[$visualType]??'bi-circle-fill')?>"></i><?=e($visualType==='node'?$nodeKind:$typeLabels[$visualType])?></span></td>
                <td><?=number_format((float)$node['elevation'],2,',','.')?> m</td>
                <td><?=$nodeFlow===null?'—':number_format((float)$nodeFlow,2,',','.').' L/s'?></td>
                <td><strong><?=$connectedCount?></strong> pipa</td>
                <td><span class="status-badge status-<?=e($node['status'])?>"><?=e(ucwords(str_replace('_',' ',$node['status'])))?></span></td>
                <td><div class="table-actions">
                  <?php if($node['type']==='node'):?>
                    <button class="icon-btn network-edit-node" type="button" data-node-key="<?=e($node['key'])?>" title="Ubah titik"><i class="bi bi-pencil"></i></button>
                    <?php if(has_role(['super_admin','administrator'])):?><form method="post" action="<?=url('distribution-networks/node')?>" onsubmit="return confirm('Hapus titik ini? Semua pipa yang terhubung juga akan diarsipkan.')"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="node_id" value="<?=$node['id']?>"><input type="hidden" name="_method" value="DELETE"><button class="icon-btn danger" title="Hapus titik"><i class="bi bi-trash"></i></button></form><?php endif?>
                  <?php else:?><a class="icon-btn" href="<?=e($node['edit_url'])?>" title="Ubah data master"><i class="bi bi-pencil"></i></a><?php endif?>
                </div></td>
              </tr>
            <?php endforeach?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="tab-pane fade" id="networkPipesPane" role="tabpanel" aria-labelledby="networkPipesTab" tabindex="0">
      <div class="panel-title-row px-3 pt-3">
        <div><h3>Daftar Jalur Pipa</h3><p>Klik dua kali garis pipa pada diagram untuk mengubah satu data.</p></div>
        <div class="d-flex gap-2"><a class="btn btn-primary btn-sm" href="<?=url('distribution-networks/bulk?tab=routes&project='.$project['id'])?>"><i class="bi bi-pencil-square"></i> Edit Massal</a><button class="btn btn-outline-primary btn-sm" data-export-table="#networkTable"><i class="bi bi-download"></i> CSV</button></div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0" id="networkTable">
          <thead><tr><th>Nama Jalur</th><th>Asal</th><th>Tujuan</th><th>Panjang</th><th>Diameter</th><th>Debit</th><th>Kehilangan</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php if(!$networks):?><tr><td colspan="9" class="text-center text-secondary py-4">Belum ada jalur. Klik dua titik pada diagram untuk membuat jalur pertama.</td></tr><?php endif?>
            <?php foreach($networks as $route):?>
              <tr>
                <td><strong><?=e($route['route_name'])?></strong><small class="d-block text-secondary"><?=e($route['pipe_type']?:'Jenis pipa belum diisi')?></small></td>
                <td><?=e($route['origin_name'])?></td><td><?=e($route['destination_name'])?></td>
                <td><?=number_format($route['pipe_length_m'],2,',','.')?> m</td>
                <td><?=number_format($route['pipe_diameter_mm'],1,',','.')?> mm</td>
                <td><?=number_format($route['planned_flow_lps'],2,',','.')?> L/s</td>
                <td><?=number_format($route['loss_percent'],2,',','.')?>%</td>
                <td><span class="status-badge status-<?=e($route['status'])?>"><?=e(ucwords(str_replace('_',' ',$route['status'])))?></span></td>
                <td><div class="table-actions">
                  <button class="icon-btn network-edit-route" type="button" data-route-id="<?=$route['id']?>" title="Edit jalur"><i class="bi bi-pencil"></i></button>
                  <?php if(has_role(['super_admin','administrator'])):?><form method="post" action="<?=url('distribution-networks/'.$route['id'])?>" onsubmit="return confirm('Arsipkan jalur ini?')"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="_method" value="DELETE"><button class="icon-btn danger" title="Hapus"><i class="bi bi-trash"></i></button></form><?php endif?>
                </div></td>
              </tr>
            <?php endforeach?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<div class="modal fade" id="networkRouteModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" action="<?=url('distribution-networks')?>" id="networkRouteForm">
      <div class="modal-header">
        <div><p class="eyebrow mb-1">Data Jalur Pipa</p><h3 class="modal-title h5" id="networkRouteModalTitle">Hubungkan Titik</h3></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="network_id" id="networkRouteId"><input type="hidden" name="_method" id="networkRouteMethod">
        <p class="required-note"><span>*</span> Wajib diisi</p>
        <nav class="property-tabs" id="networkLinkTabs" aria-label="Bagian properti link">
          <button type="button" class="active" data-property-tab="connection">Sambungan</button>
          <button type="button" data-property-tab="dimension">Dimensi</button>
          <button type="button" data-property-tab="hydraulic">Hidraulika</button>
          <button type="button" data-property-tab="condition">Kondisi</button>
          <button type="button" data-property-tab="leakage">Kebocoran</button>
          <button type="button" data-property-tab="result">Hasil Analisis</button>
        </nav>
        <div class="route-summary" id="networkRouteSummary"><span>Pilih titik asal dan tujuan.</span></div>
        <div class="row g-3">
          <div class="col-md-4" data-link-section="connection"><label class="form-label" for="networkLinkType">Jenis Link <span class="required-mark">*</span></label><select class="form-select" name="link_type" id="networkLinkType" required><option value="PIPE">Pipa (PIPE)</option><option value="PUMP">Pompa (PUMP)</option><option value="VALVE">Valve (VALVE)</option></select></div>
          <div class="col-md-6" data-link-section="connection"><label class="form-label" for="networkOriginKey">Titik Asal <span class="required-mark">*</span></label><select class="form-select" name="origin_key" id="networkOriginKey" required><option value="">Pilih titik asal...</option><?php foreach($originNodes as $node):?><option value="<?=e($node['key'])?>"><?=e($typeLabels[$node['type']].' · '.$node['name'])?></option><?php endforeach?></select><input type="hidden" name="origin_type" id="networkOriginType"><input type="hidden" name="origin_id" id="networkOriginId"></div>
          <div class="col-md-6" data-link-section="connection"><label class="form-label" for="networkDestinationKey">Titik Tujuan <span class="required-mark">*</span></label><select class="form-select" name="destination_key" id="networkDestinationKey" required><option value="">Pilih titik tujuan...</option><?php foreach($destinationNodes as $node):?><option value="<?=e($node['key'])?>"><?=e($typeLabels[$node['type']].' · '.$node['name'])?></option><?php endforeach?></select><input type="hidden" name="destination_type" id="networkDestinationType"><input type="hidden" name="destination_id" id="networkDestinationId"></div>
          <div class="col-md-6" data-link-section="connection"><label class="form-label" for="networkRouteName">Nama Link <span class="required-mark">*</span></label><input class="form-control" name="route_name" id="networkRouteName" required></div>
          <div class="col-md-3" data-link-section="connection"><label class="form-label" for="networkStartElevation">Elevasi Awal</label><input class="form-control" type="number" step="any" name="start_elevation_m" id="networkStartElevation" readonly></div>
          <div class="col-md-3" data-link-section="connection"><label class="form-label" for="networkEndElevation">Elevasi Akhir</label><input class="form-control" type="number" step="any" name="end_elevation_m" id="networkEndElevation" readonly></div>

          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="use_manual_length" id="networkUseManualLength" checked><label class="form-check-label" for="networkUseManualLength">Gunakan panjang manual</label></div></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><label class="form-label" for="networkPipeLength">Panjang Manual (m) <span class="required-mark">*</span></label><input class="form-control" type="number" min="0.01" step="any" name="pipe_length_m" id="networkPipeLength" required></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><label class="form-label" for="networkGeometricLength">Panjang Geometri (m)</label><input class="form-control" type="number" step="any" name="geometric_length_m" id="networkGeometricLength" readonly><small class="form-text">Tidak menimpa panjang manual otomatis.</small></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE VALVE"><label class="form-label" for="networkPipeDiameter">Diameter (mm) <span class="required-mark">*</span></label><input class="form-control" type="number" min="0.01" step="any" name="pipe_diameter_mm" id="networkPipeDiameter"></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><label class="form-label" for="networkPipeType">Material</label><select class="form-select" name="pipe_type" id="networkPipeType"><option value="">Pilih...</option><option>HDPE</option><option>PVC</option><option>Galvanis</option><option>Baja</option><option>Beton</option><option>Lainnya</option></select></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><label class="form-label" for="networkMaterialCode">Kode Material</label><input class="form-control" name="material_code" id="networkMaterialCode"></div>
          <div class="col-md-4" data-link-section="dimension" data-link-types="PIPE"><label class="form-label" for="networkInstallationYear">Tahun Pemasangan</label><input class="form-control" type="number" min="1900" max="2200" name="installation_year" id="networkInstallationYear"></div>

          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PIPE"><label class="form-label" for="networkRoughness">Koefisien Kekasaran <span class="required-mark">*</span></label><input class="form-control" type="number" min="0.0001" step="any" name="roughness_coefficient" id="networkRoughness"><input type="hidden" name="roughness_formula" id="networkRoughnessFormula" value="H-W"><small class="form-text" id="networkRoughnessHelp">Terisi otomatis menurut material dan rumus analisis. Material Lainnya dapat diisi manual.</small></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PIPE VALVE"><label class="form-label" for="networkMinorLoss">Koefisien Minor Loss</label><input class="form-control" type="number" min="0" step="any" name="minor_loss_coefficient" id="networkMinorLoss"></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PIPE"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="check_valve" id="networkCheckValve"><label class="form-check-label" for="networkCheckValve">Check valve / CV</label></div></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkPumpDefinitionMode">Definisi Pompa EPANET <span class="required-mark">*</span></label><select class="form-select" name="pump_definition_mode" id="networkPumpDefinitionMode"><option value="HEAD">Kurva HEAD (Q–H)</option><option value="POWER">Daya konstan POWER</option></select></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP" data-pump-mode="HEAD"><label class="form-label" for="networkPumpCurveId">Kurva Pompa <span class="required-mark">**</span></label><select class="form-select" name="pump_curve_id" id="networkPumpCurveId"><option value="">Buat kurva Q–H baru...</option><?php foreach($pumpCurves as $curve):?><option value="<?=$curve['id']?>" data-points="<?=e($curve['points_json']??'[]')?>"><?=e($curve['code'].' · '.$curve['name'])?></option><?php endforeach?></select><small class="form-text">Pilih kurva tersimpan atau kosongkan untuk membuat kurva baru di bawah.</small></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkEfficiencyCurveId">Kurva Efisiensi</label><select class="form-select" name="efficiency_curve_id" id="networkEfficiencyCurveId"><option value="">Opsional</option><?php foreach($efficiencyCurves as $curve):?><option value="<?=$curve['id']?>"><?=e($curve['code'].' · '.$curve['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP" data-pump-mode="POWER"><label class="form-label" for="networkNominalPower">Daya Nominal (kW) <span class="required-mark">*</span></label><input class="form-control" type="number" min="0.001" step="any" name="nominal_power_kw" id="networkNominalPower"></div>
          <div class="col-12" data-link-section="hydraulic" data-link-types="PUMP" data-pump-mode="HEAD"><div class="pump-curve-builder" id="networkPumpCurveBuilder"><div class="pump-curve-builder-head"><div><h5><i class="bi bi-graph-down-arrow"></i> Diagram Kurva Pompa Q–H</h5><p>Debit harus naik dan head harus turun. Isi minimal dua titik operasi sesuai data pabrikan pompa.</p></div><button class="btn btn-sm btn-outline-primary" type="button" id="networkAddPumpCurvePoint"><i class="bi bi-plus-lg"></i> Tambah Titik</button></div><div class="row g-3"><div class="col-md-3"><label class="form-label" for="networkPumpCurveCode">Kode Kurva Baru</label><input class="form-control" name="pump_curve_code" id="networkPumpCurveCode" placeholder="Contoh: PC-P01"></div><div class="col-md-4"><label class="form-label" for="networkPumpCurveName">Nama Kurva Baru</label><input class="form-control" name="pump_curve_name" id="networkPumpCurveName" placeholder="Kurva Pompa Utama"></div></div><div class="pump-curve-layout mt-3"><div><div class="pump-curve-point-head"><span>Debit Q (L/s)</span><span>Head H (m)</span><span>Aksi</span></div><div id="networkPumpCurvePoints"><?php foreach([[0,40],[10,30],[20,10]] as [$flow,$head]):?><div class="pump-curve-point"><input class="form-control form-control-sm" type="number" min="0" step="any" name="pump_curve_flow[]" value="<?=$flow?>" aria-label="Debit kurva pompa"><input class="form-control form-control-sm" type="number" min="0" step="any" name="pump_curve_head[]" value="<?=$head?>" aria-label="Head kurva pompa"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-pump-point title="Hapus titik"><i class="bi bi-trash"></i></button></div><?php endforeach?></div></div><div class="pump-curve-chart"><canvas id="networkPumpCurveChart" aria-label="Diagram kurva pompa debit terhadap head"></canvas></div></div></div></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkRelativeSpeed">Kecepatan Relatif</label><input class="form-control" type="number" min="0.0001" step="any" name="relative_speed" id="networkRelativeSpeed" value="1"></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkSpeedPattern">Speed Pattern 24 Jam</label><select class="form-select" name="speed_pattern_id" id="networkSpeedPattern"><option value="">Kecepatan tetap</option><?php foreach($demandPatterns as $pattern):?><option value="<?=$pattern['id']?>"><?=e($pattern['code'].' · '.$pattern['name'])?></option><?php endforeach?></select><small class="form-text">Multiplier pattern mengatur kecepatan relatif pompa setiap periode.</small></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label">Jumlah Unit / Aktif</label><div class="input-group"><input class="form-control" type="number" min="1" name="unit_count" id="networkUnitCount" value="1"><input class="form-control" type="number" min="0" name="active_unit_count" id="networkActiveUnitCount" value="1"></div></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkOperatingSchedule">Jadwal Operasi</label><select class="form-select" name="operating_schedule_id" id="networkOperatingSchedule"><option value="">Tanpa jadwal</option><?php foreach($operatingSchedules as $schedule):?><option value="<?=$schedule['id']?>"><?=e($schedule['code'].' · '.$schedule['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkControlMode">Mode Kontrol</label><select class="form-select" name="control_mode" id="networkControlMode"><option>MANUAL</option><option>TIME</option><option>TANK_LEVEL</option><option>PRESSURE</option></select></div>
          <div class="col-md-3" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkPumpStartLevelLink">Start Level</label><input class="form-control" type="number" step="any" name="start_level_m" id="networkPumpStartLevelLink"></div>
          <div class="col-md-3" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkPumpStopLevelLink">Stop Level</label><input class="form-control" type="number" step="any" name="stop_level_m" id="networkPumpStopLevelLink"></div>
          <div class="col-md-3" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkPumpStartPressureLink">Start Pressure</label><input class="form-control" type="number" step="any" name="start_pressure_m" id="networkPumpStartPressureLink"></div>
          <div class="col-md-3" data-link-section="hydraulic" data-link-types="PUMP"><label class="form-label" for="networkPumpStopPressureLink">Stop Pressure</label><input class="form-control" type="number" step="any" name="stop_pressure_m" id="networkPumpStopPressureLink"></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="VALVE"><label class="form-label" for="networkValveLinkType">Jenis Valve <span class="required-mark">*</span></label><select class="form-select" name="valve_type" id="networkValveLinkType"><option value="">Pilih...</option><?php foreach(['PRV','PSV','PBV','FCV','TCV','GPV'] as $valve):?><option><?=$valve?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-link-section="hydraulic" data-link-types="VALVE"><label class="form-label" for="networkValveLinkSetting">Setting Valve <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="valve_setting" id="networkValveLinkSetting"></div>

          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkMaxCapacity">Kapasitas Maksimum (L/s)</label><input class="form-control" type="number" min="0" step="any" name="max_pipe_capacity_lps" id="networkMaxCapacity"></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkPlannedFlow">Debit Rencana (L/s)</label><input class="form-control" type="number" min="0" step="any" name="planned_flow_lps" id="networkPlannedFlow"></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkMaxVelocity">Batas Velocity (m/s)</label><input class="form-control" type="number" min="0" step="any" name="max_velocity_mps" id="networkMaxVelocity"></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkMaxUnitHeadloss">Batas Unit Headloss (m/km)</label><input class="form-control" type="number" min="0" step="any" name="max_unit_headloss_m_per_km" id="networkMaxUnitHeadloss"></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkPriority">Prioritas Operasi</label><input class="form-control" type="number" min="1" name="flow_priority" id="networkPriority" value="1"></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkInitialStatus">Status Awal Engine</label><select class="form-select" name="initial_status" id="networkInitialStatus"><option>OPEN</option><option>CLOSED</option></select></div>
          <div class="col-md-4" data-link-section="condition"><label class="form-label" for="networkStatus">Status Aplikasi</label><select class="form-select" name="status" id="networkStatus"><option value="aktif">Aktif</option><option value="tidak_aktif">Tidak Aktif</option><option value="perawatan">Perawatan</option></select></div>
          <div class="col-12" data-link-section="condition"><label class="form-label" for="networkDescription">Keterangan</label><textarea class="form-control" name="description" id="networkDescription" rows="3"></textarea></div>

          <div class="col-md-5" data-link-section="leakage" data-link-types="PIPE"><label class="form-label" for="networkLeakageModel">Model Kebocoran</label><select class="form-select" name="leakage_model" id="networkLeakageModel"><option value="NONE">Tanpa Kebocoran</option><option value="NODE_EMITTER">Node Emitter</option><option value="PIPE_PERCENT">Persentase Pipa</option><option value="CUSTOM">Kustom</option></select></div>
          <div class="col-md-4" data-link-section="leakage" data-link-types="PIPE"><label class="form-label" for="networkLoss">Skenario Kebocoran (%)</label><input class="form-control" type="number" min="0" max="100" step="any" name="loss_percent" id="networkLoss" value="0"><small class="form-text">Bukan headloss hasil EPANET.</small></div>
          <input type="hidden" name="polyline_json" id="networkPolylineJson">
          <div class="col-12 property-result-empty" data-link-section="result"><i class="bi bi-activity"></i><h4>Belum ada hasil analisis</h4><p>Tab ini akan menampilkan flow, velocity, headloss, arah aliran, utilization, status, dan waktu hasil pada Tahap 3.</p></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <?php if(has_role(['super_admin','administrator'])):?><button class="btn btn-outline-danger d-none" type="button" id="networkDeleteRoute"><i class="bi bi-trash"></i> Hapus Pipa</button><?php endif?>
        <div class="d-flex gap-2 ms-auto"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary"><i class="bi bi-check2"></i> Simpan Data Pipa</button></div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="networkHydraulicModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" id="networkHydraulicForm">
      <div class="modal-header">
        <div><p class="eyebrow mb-1">Tahap 2 · Mesin EPANET</p><h3 class="modal-title h5 mb-0">Analisis Hidraulika Jaringan</h3></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php $hydraulicPumps=array_values(array_filter($networks,fn($item)=>($item['link_type']??'PIPE')==='PUMP'));$pumpCurveMap=[];foreach($pumpCurves as $item)$pumpCurveMap[(int)$item['id']]=$item;$patternMap=[];foreach($demandPatterns as $item)$patternMap[(int)$item['id']]=$item;$scheduleMap=[];foreach($operatingSchedules as $item)$scheduleMap[(int)$item['id']]=$item; ?>
        <div class="hydraulic-option-grid">
          <label><span>Tipe Analisis <b>*</b></span><select class="form-select" name="analysis_type" id="networkAnalysisType" required><option value="EXTENDED" selected>Extended Period · 24 jam</option><option value="STEADY">Steady State</option></select></label>
          <label><span>Rumus Kehilangan <b>*</b></span><select class="form-select" name="headloss_formula" id="networkHeadlossFormula" required><option value="H-W">Hazen-Williams</option><option value="D-W">Darcy-Weisbach</option><option value="C-M">Chezy-Manning</option></select></label>
          <label><span>Model Kebutuhan <b>*</b></span><select class="form-select" name="demand_model" required><option value="PDA">Pressure Driven (PDA)</option><option value="DDA">Demand Driven (DDA)</option></select></label>
          <label><span>Pengali Kebutuhan <b>*</b></span><input class="form-control" type="number" min="0" step="0.01" name="demand_multiplier" value="1" required></label>
          <label><span>Tekanan Minimum (m)</span><input class="form-control" type="number" step="0.01" name="minimum_pressure_m" value="5"></label>
          <label><span>Tekanan Pelayanan (m)</span><input class="form-control" type="number" step="0.01" name="required_pressure_m" value="15"></label>
          <label><span>Pressure Exponent</span><input class="form-control" type="number" min="0.01" step="0.01" name="pressure_exponent" value="0.5"></label>
          <label><span>Durasi (jam:menit)</span><input class="form-control" name="duration" value="24:00" pattern="\d{1,3}:\d{2}"></label>
          <label><span>Hydraulic Timestep</span><input class="form-control" name="hydraulic_timestep" value="1:00" pattern="\d{1,3}:\d{2}"></label>
          <label><span>Report Timestep</span><input class="form-control" name="report_timestep" value="1:00" pattern="\d{1,3}:\d{2}"></label>
          <label><span>Pattern Timestep</span><input class="form-control" name="pattern_timestep" value="1:00" pattern="\d{1,3}:\d{2}"></label>
        </div>
        <section class="hydraulic-pattern-panel mt-3" id="networkDemandPatternPanel">
          <div class="hydraulic-section-heading"><div><span><i class="bi bi-graph-up-arrow"></i></span><div><h4>Pola Kebutuhan Air 24 Jam</h4><p>Nilai adalah faktor pengali terhadap base demand. Grafik ini dipakai langsung pada simulasi Extended Period.</p></div></div><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="apply_global_pattern" value="1" id="networkApplyGlobalPattern" checked><span class="form-check-label">Terapkan ke seluruh junction</span></label></div>
          <?php $defaultHourlyPattern=[.55,.50,.48,.50,.60,.85,1.20,1.35,1.15,1.00,.90,.95,1.05,1.10,1.00,.95,1.05,1.25,1.45,1.40,1.15,.90,.75,.65]; ?>
          <div class="hydraulic-pattern-layout"><div class="hour-pattern hydraulic-hour-pattern"><?php foreach($defaultHourlyPattern as $hour=>$factor):?><label><span><?=str_pad((string)$hour,2,'0',STR_PAD_LEFT)?>:00</span><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="hourly_pattern[]" value="<?=number_format($factor,2,'.','')?>" data-hydraulic-hour="<?=$hour?>"></label><?php endforeach?></div><div class="hydraulic-pattern-chart"><canvas id="networkDemandPatternChart" aria-label="Grafik pola kebutuhan air 24 jam"></canvas></div></div>
        </section>
        <section class="hydraulic-pump-panel mt-3">
          <div class="hydraulic-section-heading"><div><span><i class="bi bi-gear-wide-connected"></i></span><div><h4>Data Pompa EPANET</h4><p>Pompa memakai kurva HEAD atau daya POWER. Speed pattern mengatur perubahan operasi selama simulasi 24 jam.</p></div></div><strong><?=count($hydraulicPumps)?> pompa</strong></div>
          <?php if($hydraulicPumps):?><div class="table-responsive"><table class="table table-sm hydraulic-pump-table mb-0"><thead><tr><th>Pompa / Hubungan</th><th>Parameter EPANET</th><th>Kecepatan & Pattern</th><th>Unit</th><th>Status & Kontrol</th></tr></thead><tbody><?php foreach($hydraulicPumps as $pump): $curve=$pumpCurveMap[(int)($pump['pump_curve_id']??0)]??null;$speedPattern=$patternMap[(int)($pump['speed_pattern_id']??0)]??null;$schedule=$scheduleMap[(int)($pump['operating_schedule_id']??0)]??null;?><tr><td><strong><?=e($pump['route_name'])?></strong><small><?=e($pump['origin_name'].' → '.$pump['destination_name'])?></small></td><td><?php if($curve):?><span class="badge text-bg-primary">HEAD</span> <?=e($curve['code'].' · '.$curve['name'])?><?php else:?><span class="badge text-bg-info">POWER</span> <?=number_format((float)($pump['nominal_power_kw']??0),2,',','.')?> kW<?php endif?></td><td><strong><?=number_format((float)($pump['relative_speed']??1),2,',','.')?>×</strong><small><?=$speedPattern?e($speedPattern['code'].' · '.$speedPattern['name']):'Tanpa speed pattern'?></small></td><td><?=max(0,(int)($pump['active_unit_count']??1))?> / <?=max(1,(int)($pump['unit_count']??1))?> aktif</td><td><strong><?=e($pump['initial_status']??'OPEN')?></strong><small><?=e($pump['control_mode']??'MANUAL')?><?=$schedule?' · '.e($schedule['code']):''?></small></td></tr><?php endforeach?></tbody></table></div><?php else:?><div class="hydraulic-pump-empty"><i class="bi bi-info-circle"></i><span>Belum ada link bertipe <strong>PUMP</strong>. Klik dua kali jalur pada diagram, ubah Jenis Link menjadi Pompa, lalu isi kurva HEAD atau daya POWER.</span></div><?php endif?>
          <?php if(has_role(['super_admin','administrator'])&&$pumpCurves):?><details class="mt-3"><summary class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash3"></i> Kelola / hapus kurva pompa (<?=count($pumpCurves)?>)</summary><div class="table-responsive mt-2"><table class="table table-sm align-middle mb-2"><thead><tr><th>Kode</th><th>Nama</th><th>Pemakaian</th><th>Aksi</th></tr></thead><tbody><?php foreach($pumpCurves as $curve):?><tr><td><?=e($curve['code'])?></td><td><?=e($curve['name'])?></td><td><?=(int)($curve['usage_count']??0)?> pompa</td><td><?php if((int)($curve['usage_count']??0)===0):?><button class="btn btn-sm btn-outline-danger" type="submit" form="deletePumpCurve<?=$curve['id']?>" onclick="return confirm('Hapus kurva pompa <?=e($curve['code'])?>?')"><i class="bi bi-trash"></i> Hapus</button><?php else:?><button class="btn btn-sm btn-light" type="button" disabled title="Kurva masih digunakan"><i class="bi bi-lock"></i> Dipakai</button><?php endif?></td></tr><?php endforeach?></tbody></table></div><button class="btn btn-sm btn-danger" type="submit" form="cleanupPumpCurves" onclick="return confirm('Hapus semua kurva pompa yang tidak sedang digunakan?')"><i class="bi bi-trash3"></i> Hapus Semua yang Tidak Dipakai</button></details><?php endif?>
        </section>
        <div id="networkHydraulicResult" class="hydraulic-result">
          <div class="hydraulic-result-empty"><i class="bi bi-shield-check"></i><strong>Siap memeriksa model</strong><span>Validasi tidak mengubah data titik atau pipa.</span></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-secondary"><i class="bi bi-lock"></i> Proses Tahap 2 hanya membaca data dan membuat berkas kerja sementara.</small>
        <div class="d-flex gap-2"><button class="btn btn-outline-success" type="button" id="networkHydraulicValidateInModal"><i class="bi bi-shield-check"></i> Validasi</button><button class="btn btn-success" type="submit" id="networkHydraulicRunSubmit"><i class="bi bi-play-fill"></i> Jalankan EPANET</button></div>
      </div>
    </form>
  </div>
</div>

<?php if(has_role(['super_admin','administrator'])):?>
  <?php foreach($pumpCurves as $curve): if((int)($curve['usage_count']??0)>0)continue;?><form id="deletePumpCurve<?=$curve['id']?>" method="post" action="<?=url('distribution-networks/pump-curves/delete')?>"><?=csrf_field()?><input type="hidden" name="curve_id" value="<?=$curve['id']?>"><input type="hidden" name="project_id" value="<?=$project['id']?>"></form><?php endforeach?>
  <form id="cleanupPumpCurves" method="post" action="<?=url('distribution-networks/pump-curves/cleanup')?>"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"></form>
<?php endif?>

<div class="modal fade" id="networkAnalysisModeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" action="<?=url('automatic-design/quick')?>" id="networkQuickDesignForm">
      <div class="modal-header"><div><p class="eyebrow mb-1">Analisis dan Desain Jaringan</p><h3 class="modal-title h5 mb-0">Pilih Proses yang Akan Dijalankan</h3></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>">
        <div class="network-analysis-mode-grid">
          <label class="network-analysis-mode-card"><input type="radio" name="quick_mode" value="CHECK" checked><span><i class="bi bi-clipboard2-pulse"></i><strong>Cek Analisis</strong><small>Menjalankan EPANET dan menampilkan debit, tekanan, kecepatan, serta headloss tanpa mengubah diameter pipa.</small></span></label>
          <label class="network-analysis-mode-card"><input type="radio" name="quick_mode" value="DESIGN"><span><i class="bi bi-magic"></i><strong>Desain Otomatis</strong><small>Mencari diameter standar yang memenuhi patokan, lalu menerapkan hasil terbaik ke data pipa jaringan.</small></span></label>
        </div>
        <div class="alert alert-info mt-3 mb-0" id="networkCheckModeInfo"><i class="bi bi-info-circle me-2"></i>Mode cek hanya membaca model. Tekan <strong>Buka Cek Analisis</strong> untuk mengatur metode dan menjalankan EPANET.</div>
        <section id="networkQuickDesignOptions" class="network-quick-design-options mt-3" hidden>
          <h4>Patokan desain otomatis</h4><p>Diameter dapat dihitung bebas sampai desimal atau dipilih dari ukuran standar katalog berdasarkan patokan berikut.</p>
          <div class="hydraulic-option-grid">
            <label><span>Mode Ukuran Diameter <b>*</b></span><select class="form-select" name="diameter_mode" required><option value="FREE" selected>Diameter bebas (hasil perhitungan)</option><option value="CATALOG">Diameter standar katalog</option></select><small>Mode bebas menghasilkan ukuran desimal dan tidak dibatasi DN yang tersedia di katalog.</small></label>
            <label><span>Jenis / Material Pipa <b>*</b></span><select class="form-select" name="pipe_material" required><?php foreach (($pipeDesignMaterials??[]) as $index=>$material): ?><option value="<?=e($material)?>" <?=$index===0?'selected':''?>><?=e($material)?></option><?php endforeach; ?><option value="ALL">Semua material aktif (khusus mode katalog)</option></select><small>Material tetap diperlukan untuk menentukan nilai kekasaran dan kelas tekanan.</small></label>
            <label><span>Rumus Headloss <b>*</b></span><select class="form-select" name="hydraulic_method"><option value="H-W">Hazen–Williams</option><option value="D-W">Darcy–Weisbach</option></select></label>
            <label><span>Pengali Demand <b>*</b></span><input class="form-control" type="number" min="0.01" step="0.01" name="demand_multiplier" value="1" required><small>1,00 memakai demand node saat ini.</small></label>
            <label><span>Faktor demand jam rendah</span><input class="form-control" type="number" min="0.01" max="1" step="0.01" name="minimum_demand_factor" value="0.75"><small>0,75 memeriksa kondisi 11,25 L/s dari demand rencana 15 L/s. Turunkan hanya bila kecepatan wajib diperiksa pada debit yang lebih rendah.</small></label>
            <label><span>Kecepatan minimum (m/s)</span><input class="form-control" type="number" min="0" step="0.01" name="minimum_velocity_mps" value="0.60"><small>Pada Desain Seimbang, nilai di bawah batas ini menggagalkan alternatif; pada tujuan lain tetap dicatat sebagai peringatan operasi.</small></label>
            <label><span>Kecepatan target (m/s)</span><input class="form-control" type="number" min="0.01" step="0.01" name="target_velocity_mps" value="1.20"></label>
            <label><span>Kecepatan maksimum (m/s)</span><input class="form-control" type="number" min="0.01" step="0.01" name="maximum_velocity_mps" value="2.00"></label>
            <label><span>Headloss maksimum (m/km)</span><input class="form-control" type="number" min="0.01" step="0.01" name="maximum_unit_headloss_m_per_km" value="10"></label>
            <label><span>Tekanan minimum (m)</span><input class="form-control" type="number" step="0.01" name="minimum_pressure_m" value="10"></label>
            <label><span>Tekanan target (m)</span><input class="form-control" type="number" step="0.01" name="target_pressure_m" value="20"></label>
            <label><span>Tekanan maksimum (m)</span><input class="form-control" type="number" step="0.01" name="maximum_pressure_m" value="60"></label>
            <label><span>Tujuan pemilihan</span><select class="form-select" name="optimization_goal"><option value="SMALLEST_DIAMETER">Diameter terkecil yang aman</option><option value="BALANCED" selected>Desain seimbang · semua patokan</option><option value="LOWEST_INITIAL_COST">Biaya terendah yang lolos</option><option value="LOWEST_HEADLOSS">Headloss terendah</option><option value="TARGET_PRESSURE">Mendekati tekanan target</option><option value="TARGET_VELOCITY">Mendekati kecepatan target</option></select><small>Desain Seimbang hanya menerapkan hasil yang memenuhi tekanan, demand, headloss, kelas pipa, serta kecepatan minimum dan maksimum—termasuk pada jam kebutuhan rendah.</small></label>
          </div>
          <section class="network-pump-design mt-3">
            <label class="form-check network-pump-design-toggle"><input class="form-check-input" type="checkbox" name="design_pumps" value="1" id="networkDesignPumps" <?=$pumpCount?'checked':'disabled'?>> <span class="form-check-label"><strong>Desain pompa otomatis</strong><small><?=$pumpCount?$pumpCount.' pompa akan dihitung bersama diameter pipa.':'Belum ada jalur PUMP pada proyek ini.'?></small></span></label>
            <div id="networkPumpDesignOptions" class="hydraulic-option-grid mt-3" <?=$pumpCount?'':'hidden'?>>
              <label><span>Debit titik kerja (L/s)</span><input class="form-control" type="number" min="0" step="0.01" name="pump_design_flow_lps" placeholder="Otomatis dari demand"><small>Kosongkan agar dihitung dari total demand jaringan.</small></label>
              <label><span>Head titik kerja (m)</span><input class="form-control" type="number" min="0" step="0.01" name="pump_design_head_m" placeholder="Otomatis dari elevasi"><small>Kosongkan agar dihitung dari head asal, tujuan, dan tekanan target.</small></label>
              <label><span>Tambahan headloss (m)</span><input class="form-control" type="number" min="0" step="0.01" name="pump_head_allowance_m" value="10"></label>
              <label><span>Cadangan debit (%)</span><input class="form-control" type="number" min="0" step="0.1" name="pump_flow_safety_percent" value="10"></label>
              <label><span>Cadangan head (%)</span><input class="form-control" type="number" min="0" step="0.1" name="pump_head_safety_percent" value="10"></label>
              <label><span>Efisiensi pompa (%)</span><input class="form-control" type="number" min="1" max="100" step="0.1" name="pump_efficiency_percent" value="75"><small>Dipakai untuk memperkirakan kebutuhan daya.</small></label>
              <label><span>Jam operasi pompa per hari</span><input class="form-control" type="number" min="1" max="24" step="0.5" name="pump_operating_hours_day" value="12"><small>Debit otomatis = kebutuhan harian dibagi jam operasi. Operasi sebaiknya dikendalikan level tank.</small></label>
            </div>
          </section>
          <section class="network-pump-design mt-3">
            <label class="form-check network-pump-design-toggle"><input class="form-check-input" type="checkbox" name="design_reservoir" value="1" <?=$reservoirCount?'checked':'disabled'?>> <span class="form-check-label"><strong>Desain ukuran bak otomatis</strong><small><?=$reservoirCount?'Bak dihitung sebagai penyeimbang saat sumber tersedia kontinu.':'Tambahkan titik reservoir/bak agar dimensinya dapat dirancang.'?></small></span></label>
            <div class="hydraulic-option-grid mt-3" <?=$reservoirCount?'':'hidden'?>>
              <label><span>Cadangan operasi bak (jam)</span><input class="form-control" type="number" min="0.5" max="72" step="0.5" name="reservoir_storage_hours" value="6"><small>Asumsi awal yang dapat diubah; sistem menghitung volume dari demand desain.</small></label>
              <label><span>Cadangan volume (%)</span><input class="form-control" type="number" min="0" max="200" step="1" name="reservoir_reserve_percent" value="10"></label>
              <label><span>Freeboard bak (m)</span><input class="form-control" type="number" min="0" step="0.1" name="reservoir_freeboard_m" value="0.5"></label>
            </div>
          </section>
          <div class="alert alert-warning mt-3 mb-0"><label class="form-check"><input class="form-check-input" type="checkbox" name="confirm_apply" value="1" required><span class="form-check-label"><strong>Saya memahami hasil desain akan langsung diterapkan.</strong> Sistem hanya menerapkan diameter dan kurva pompa yang konvergen serta memenuhi semua patokan wajib.</span></label></div>
        </section>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="button" class="btn btn-success" id="networkOpenHydraulicCheck"><i class="bi bi-play-circle"></i> Buka Cek Analisis</button><button type="submit" class="btn btn-primary" id="networkRunQuickDesign" hidden><i class="bi bi-magic"></i> Jalankan dan Terapkan Desain</button></div>
    </form>
  </div>
</div>

<section class="network-design-progress" id="networkDesignProgress" hidden aria-live="polite" aria-modal="true" role="dialog" aria-labelledby="networkDesignProgressTitle">
  <div class="network-design-progress-card">
    <div class="network-design-progress-icon"><i class="bi bi-diagram-3-fill"></i></div>
    <p class="eyebrow">Proses Desain Jaringan</p>
    <h3 id="networkDesignProgressTitle">Desain sedang diproses</h3>
    <p id="networkDesignProgressMessage">Jangan menutup halaman. Perhitungan berlangsung di latar tanpa membuka atau memuat ulang tab lain.</p>
    <div class="network-design-progress-track" aria-hidden="true"><span id="networkDesignProgressBar"></span></div>
    <ol class="network-design-progress-steps" id="networkDesignProgressSteps">
      <li class="active"><i class="bi bi-check2"></i><span>Menyiapkan model jaringan</span></li>
      <li><i class="bi bi-check2"></i><span>Membentuk kandidat diameter dan kurva pompa</span></li>
      <li><i class="bi bi-check2"></i><span>Menguji alternatif dengan EPANET</span></li>
      <li><i class="bi bi-check2"></i><span>Menerapkan hasil yang aman</span></li>
    </ol>
    <div class="network-design-progress-meta"><span id="networkDesignElapsed">Waktu berjalan 00:00</span><strong id="networkDesignProgressState">Sedang berjalan</strong></div>
    <div class="network-design-progress-actions" id="networkDesignProgressActions" hidden>
      <button class="btn btn-light" type="button" id="networkDesignClose">Tutup</button>
      <a class="btn btn-primary" href="#" id="networkDesignOpenResult">Lihat hasil jaringan</a>
    </div>
  </div>
</section>

<div class="modal fade" id="networkNodeModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" action="<?=url('distribution-networks/node')?>" id="networkNodeForm">
      <div class="modal-header">
        <div><p class="eyebrow mb-1">Properti Node EPANET</p><h3 class="modal-title h5">Data Titik Jaringan</h3></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="node_id" id="networkNodeId"><input type="hidden" name="_method" id="networkNodeMethod">
        <p class="required-note"><span>*</span> Wajib diisi · <span>**</span> Wajib salah satu · Data teknis lain dapat dilengkapi kemudian</p>
        <nav class="property-tabs" id="networkNodeTabs" aria-label="Bagian properti titik">
          <button type="button" class="active" data-property-tab="basic">Data Dasar</button>
          <button type="button" data-property-tab="demand">Kebutuhan Air</button>
          <button type="button" data-property-tab="pressure">Tekanan</button>
          <button type="button" data-property-tab="quality">Kualitas</button>
          <button type="button" data-property-tab="sensor">Sensor</button>
          <button type="button" data-property-tab="result">Hasil Analisis</button>
        </nav>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="networkNodeCode">Kode Titik <span class="required-mark">*</span></label><input class="form-control" name="code" id="networkNodeCode" required></div>
          <div class="col-md-8"><label class="form-label" for="networkNodeName">Nama Titik <span class="required-mark">*</span></label><input class="form-control" name="name" id="networkNodeName" required></div>
          <div class="col-md-6"><label class="form-label" for="networkNodeKind">Jenis Titik <span class="required-mark">*</span></label><select class="form-select" name="node_type" id="networkNodeKind" required><option value="junction">Junction</option><option value="source">Sumber Air</option><option value="reservoir">Reservoir</option><option value="tank">Tank / Bak</option><option value="pompa">Stasiun Pompa</option><option value="valve">Valve</option><option value="meter">Meter / Titik Ukur</option></select><small class="form-text">Ikon titik pada diagram berubah otomatis mengikuti pilihan.</small></div>
          <div class="col-md-6"><label class="form-label" for="networkNodeLinkedKey">Hubungkan dengan Data Master</label><select class="form-select" name="linked_key" id="networkNodeLinkedKey"><option value="">Belum dihubungkan</option><?php foreach(['source','reservoir','service_area'] as $masterType):$items=array_values(array_filter($masterNodes,fn($node)=>$node['type']===$masterType));if(!$items)continue;?><optgroup label="<?=e($typeLabels[$masterType])?>"><?php foreach($items as $node):?><option value="<?=e($node['key'])?>"><?=e($node['code'].' · '.$node['name'])?></option><?php endforeach?></optgroup><?php endforeach?></select><small class="form-text">Pilih sumber air, reservoir, atau wilayah layanan yang ingin menjadi data acuan titik ini.</small></div>
          <div class="col-12 node-property-heading" data-node-kinds="junction"><i class="bi bi-circle-fill"></i><span><strong>Properti Junction</strong><small>Tempat pipa bertemu dan air masuk atau keluar dari jaringan.</small></span></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeElevation">Elevasi (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="elevation_m" id="networkNodeElevation" data-required-kinds="junction"></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeDemand">Base Demand (L/s)</label><input class="form-control" type="number" step="any" name="base_demand_lps" id="networkNodeDemand"></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeDemandPatternId">Demand Pattern</label><select class="form-select" name="demand_pattern_id" id="networkNodeDemandPatternId"><option value="">Tanpa pattern</option><?php foreach($demandPatterns as $pattern):?><option value="<?=$pattern['id']?>"><?=e($pattern['code'].' · '.$pattern['name'])?></option><?php endforeach?></select><input type="hidden" name="demand_pattern" id="networkNodeDemandPattern"><small class="form-text">Pattern baru wajib berasal dari tabel demand pattern.</small></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeInitialPressure">Tekanan Awal (m)</label><input class="form-control" type="number" step="any" name="initial_pressure_m" id="networkNodeInitialPressure"></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeMinPressure">Tekanan Minimum (m)</label><input class="form-control" type="number" step="any" name="minimum_pressure_m" id="networkNodeMinPressure"></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeMaxPressure">Tekanan Maksimum (m)</label><input class="form-control" type="number" step="any" name="maximum_pressure_m" id="networkNodeMaxPressure"></div>
          <div class="col-md-4" data-node-kinds="junction"><label class="form-label" for="networkNodeEmitter">Koefisien Emitter</label><input class="form-control" type="number" step="any" name="emitter_coefficient" id="networkNodeEmitter"></div>

          <div class="col-12 node-property-heading" data-node-kinds="source reservoir"><i class="bi bi-droplet-fill"></i><span><strong>Properti Sumber / Reservoir</strong><small>Sumber eksternal dengan hydraulic head yang ditentukan.</small></span></div>
          <div class="col-md-4" data-node-kinds="source reservoir"><label class="form-label" for="networkNodeTotalHead">Total Head (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="total_head_m" id="networkNodeTotalHead" data-required-kinds="source reservoir"></div>
          <div class="col-md-4" data-node-kinds="source reservoir"><label class="form-label" for="networkNodeHeadPattern">Head Pattern</label><input class="form-control" name="head_pattern" id="networkNodeHeadPattern" placeholder="Opsional"></div>
          <div class="col-md-4" data-node-kinds="junction source reservoir tank"><label class="form-label" for="networkNodeInitialQuality">Kualitas Awal</label><input class="form-control" type="number" step="any" name="initial_quality" id="networkNodeInitialQuality"></div>
          <div class="col-md-4" data-node-kinds="junction source reservoir"><label class="form-label" for="networkNodeSourceQuality">Kualitas Sumber</label><input class="form-control" type="number" step="any" name="source_quality" id="networkNodeSourceQuality"></div>

          <div class="col-12 node-property-heading" data-node-kinds="tank"><i class="bi bi-database-fill"></i><span><strong>Properti Tank / Bak</strong><small>Penyimpanan dengan volume dan tinggi muka air yang berubah.</small></span></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankElevation">Elevasi Dasar (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="elevation_m" id="networkTankElevation" data-required-kinds="tank"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankInitialLevel">Tinggi Air Awal (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="initial_level_m" id="networkTankInitialLevel" data-required-kinds="tank"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankMinLevel">Tinggi Minimum (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="minimum_level_m" id="networkTankMinLevel" data-required-kinds="tank"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankMaxLevel">Tinggi Maksimum (m) <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="maximum_level_m" id="networkTankMaxLevel" data-required-kinds="tank"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankDiameter">Diameter Tank (m) <span class="required-mark">*</span></label><input class="form-control" type="number" min="0.01" step="any" name="tank_diameter_m" id="networkTankDiameter" data-required-kinds="tank"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankMinVolume">Volume Minimum (m³)</label><input class="form-control" type="number" step="any" name="minimum_volume_m3" id="networkTankMinVolume"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankVolumeCurve">Volume Curve</label><input class="form-control" name="volume_curve" id="networkTankVolumeCurve"></div>
          <div class="col-md-4" data-node-kinds="tank"><label class="form-label" for="networkTankMixing">Model Pencampuran</label><select class="form-select" name="mixing_model" id="networkTankMixing"><option value="mixed">Complete Mixing</option><option value="2comp">Two Compartment</option><option value="fifo">FIFO</option><option value="lifo">LIFO</option></select></div>

          <div class="col-12 node-property-heading" data-node-kinds="pompa"><i class="bi bi-gear-wide-connected"></i><span><strong>Properti Pompa</strong><small>Menambahkan energi/head ke aliran; Pump Curve atau Daya Pompa wajib diisi salah satu.</small></span></div>
          <div class="col-md-4" data-node-kinds="pompa"><label class="form-label" for="networkPumpCurveIdNode">Pump Curve <span class="required-mark">**</span></label><select class="form-select" name="pump_curve_id" id="networkPumpCurveIdNode"><option value="">Pilih kurva bertitik...</option><?php foreach($pumpCurves as $curve):?><option value="<?=$curve['id']?>"><?=e($curve['code'].' · '.$curve['name'])?></option><?php endforeach?></select><input type="hidden" name="pump_curve" id="networkPumpCurveNode"><small class="form-text">Kurva harus mempunyai minimal dua titik flow-head.</small></div>
          <div class="col-md-4" data-node-kinds="pompa"><label class="form-label" for="networkPumpPowerNode">Daya Pompa (kW) <span class="required-mark">**</span></label><input class="form-control" type="number" step="any" name="pump_power_kw" id="networkPumpPowerNode"></div>
          <div class="col-md-4" data-node-kinds="pompa"><label class="form-label" for="networkPumpSpeedNode">Kecepatan Relatif</label><input class="form-control" type="number" step="any" name="pump_speed" id="networkPumpSpeedNode" value="1"></div>
          <div class="col-md-4" data-node-kinds="pompa"><label class="form-label" for="networkPumpPatternNode">Speed Pattern</label><input class="form-control" name="speed_pattern" id="networkPumpPatternNode"></div>

          <div class="col-12 node-property-heading" data-node-kinds="valve"><i class="bi bi-hourglass-split"></i><span><strong>Properti Valve</strong><small>Mengatur tekanan, aliran, atau kehilangan energi sesuai tipe valve.</small></span></div>
          <div class="col-md-6" data-node-kinds="valve"><label class="form-label" for="networkValveType">Tipe Valve <span class="required-mark">*</span></label><select class="form-select" name="valve_type" id="networkValveType" data-required-kinds="valve"><option value="">Pilih...</option><option value="PRV">PRV · Pressure Reducing</option><option value="PSV">PSV · Pressure Sustaining</option><option value="PBV">PBV · Pressure Breaker</option><option value="FCV">FCV · Flow Control</option><option value="TCV">TCV · Throttle Control</option><option value="GPV">GPV · General Purpose</option></select></div>
          <div class="col-md-6" data-node-kinds="valve"><label class="form-label" for="networkValveSetting">Setting Valve <span class="required-mark">*</span></label><input class="form-control" type="number" step="any" name="valve_setting" id="networkValveSetting" data-required-kinds="valve"><small class="form-text">Satuan mengikuti tipe: tekanan, debit, atau koefisien kehilangan.</small></div>

          <div class="col-12 node-property-heading" data-node-kinds="meter"><i class="bi bi-speedometer2"></i><span><strong>Properti Meter</strong><small>Titik pengukuran parameter jaringan.</small></span></div>
          <div class="col-md-6" data-node-kinds="meter"><label class="form-label" for="networkMeterParameter">Parameter <span class="required-mark">*</span></label><select class="form-select" name="meter_parameter" id="networkMeterParameter" data-required-kinds="meter"><option value="">Pilih...</option><?php foreach(['FLOW'=>'Debit','PRESSURE'=>'Tekanan','LEVEL'=>'Tinggi Air','WATER_QUALITY'=>'Kualitas Air','ENERGY'=>'Energi','TURBIDITY'=>'Turbiditas','PH'=>'pH','TEMPERATURE'=>'Suhu','CUSTOM'=>'Kustom'] as $value=>$label):?><option value="<?=$value?>"><?=$label?></option><?php endforeach?></select></div>
          <div class="col-md-6" data-node-kinds="meter"><label class="form-label" for="networkMeterUnit">Satuan <span class="required-mark">*</span></label><input class="form-control" name="meter_unit" id="networkMeterUnit" data-required-kinds="meter" placeholder="L/s, m, bar, mg/L"></div>

          <div class="col-md-4" data-node-kinds="junction" data-node-section="demand"><label class="form-label" for="networkDemandCategory">Kategori Kebutuhan</label><input class="form-control" name="demand_category" id="networkDemandCategory"></div>
          <div class="col-md-4" data-node-kinds="junction" data-node-section="pressure"><label class="form-label" for="networkRequiredPressure">Tekanan Pelayanan (m)</label><input class="form-control" type="number" step="any" name="required_pressure_m" id="networkRequiredPressure"></div>
          <div class="col-md-4" data-node-kinds="junction" data-node-section="pressure"><label class="form-label" for="networkPressureExponent">Pressure Exponent</label><input class="form-control" type="number" min=".01" step="any" name="pressure_exponent" id="networkPressureExponent" value=".5"></div>
          <div class="col-md-4" data-node-kinds="junction" data-node-section="sensor"><label class="form-label" for="networkMeasuredPressure">Tekanan Terukur (m)</label><input class="form-control" type="number" step="any" name="measured_pressure_m" id="networkMeasuredPressure"></div>
          <div class="col-md-4" data-node-kinds="junction" data-node-section="sensor"><label class="form-label" for="networkPressureMeasuredAt">Waktu Pengukuran</label><input class="form-control" type="datetime-local" name="pressure_measured_at" id="networkPressureMeasuredAt"></div>

          <div class="col-md-4" data-node-kinds="source" data-node-section="basic"><label class="form-label" for="networkHydraulicRepresentation">Representasi Hidraulika</label><select class="form-select" name="hydraulic_representation" id="networkHydraulicRepresentation"><option value="">Pilih...</option><option value="RESERVOIR">Reservoir / head tetap</option><option value="TANK">Tank / bak</option><option value="WELL_PUMP">Sumur + pompa</option></select></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="pressure"><label class="form-label" for="networkSourceHead">Source Head (m)</label><input class="form-control" type="number" step="any" name="source_head_m" id="networkSourceHead"></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="pressure"><label class="form-label" for="networkStaticLevel">Muka Air Statis (m)</label><input class="form-control" type="number" step="any" name="static_water_level_m" id="networkStaticLevel"></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="pressure"><label class="form-label" for="networkDynamicLevel">Muka Air Dinamis (m)</label><input class="form-control" type="number" step="any" name="dynamic_water_level_m" id="networkDynamicLevel"></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="demand"><label class="form-label" for="networkMaximumWithdrawal">Batas Pengambilan (L/s)</label><input class="form-control" type="number" min="0" step="any" name="maximum_withdrawal_lps" id="networkMaximumWithdrawal"></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="demand"><label class="form-label" for="networkMinimumOperatingFlow">Debit Operasi Minimum (L/s)</label><input class="form-control" type="number" min="0" step="any" name="minimum_operating_flow_lps" id="networkMinimumOperatingFlow"></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="demand"><label class="form-label" for="networkSourcePattern">Pattern Sumber</label><select class="form-select" name="source_pattern_id" id="networkSourcePattern"><option value="">Tanpa pattern</option><?php foreach($demandPatterns as $pattern):?><option value="<?=$pattern['id']?>"><?=e($pattern['code'].' · '.$pattern['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="source" data-node-section="basic"><label class="form-label" for="networkConnectedPump">Pompa Terhubung</label><select class="form-select" name="connected_pump_node_id" id="networkConnectedPump"><option value="">Belum dipilih</option><?php foreach(array_filter($nodes,fn($item)=>$item['type']==='node'&&$item['node_kind']==='pompa') as $pumpNode):?><option value="<?=$pumpNode['id']?>"><?=e($pumpNode['code'].' · '.$pumpNode['name'])?></option><?php endforeach?></select></div>

          <div class="col-md-4" data-node-kinds="tank" data-node-section="quality"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="tank_overflow" id="networkTankOverflow"><label class="form-check-label" for="networkTankOverflow">Izinkan overflow</label></div></div>

          <div class="col-md-4" data-node-kinds="pompa" data-node-section="basic"><label class="form-label" for="networkPumpInlet">Titik Masuk</label><select class="form-select" name="inlet_node_id" id="networkPumpInlet"><option value="">Pilih...</option><?php foreach(array_filter($nodes,fn($item)=>$item['type']==='node') as $manualNode):?><option value="<?=$manualNode['id']?>"><?=e($manualNode['code'].' · '.$manualNode['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="basic"><label class="form-label" for="networkPumpOutlet">Titik Keluar</label><select class="form-select" name="outlet_node_id" id="networkPumpOutlet"><option value="">Pilih...</option><?php foreach(array_filter($nodes,fn($item)=>$item['type']==='node') as $manualNode):?><option value="<?=$manualNode['id']?>"><?=e($manualNode['code'].' · '.$manualNode['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpEfficiencyCurve">Kurva Efisiensi</label><select class="form-select" name="efficiency_curve_id" id="networkPumpEfficiencyCurve"><option value="">Opsional</option><?php foreach($efficiencyCurves as $curve):?><option value="<?=$curve['id']?>"><?=e($curve['code'].' · '.$curve['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkNominalPowerNode">Daya Nominal (kW)</label><input class="form-control" type="number" min="0" step="any" name="nominal_power_kw" id="networkNominalPowerNode"></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label">Jumlah Unit / Aktif</label><div class="input-group"><input class="form-control" type="number" min="1" name="unit_count" id="networkPumpUnitCount" value="1"><input class="form-control" type="number" min="0" name="active_unit_count" id="networkPumpActiveUnitCount" value="1"></div></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpInitialStatus">Status Awal</label><select class="form-select" name="initial_status" id="networkPumpInitialStatus"><option>OPEN</option><option>CLOSED</option></select></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpControlMode">Mode Kontrol</label><select class="form-select" name="control_mode" id="networkPumpControlMode"><option>MANUAL</option><option>TIME</option><option>TANK_LEVEL</option><option>PRESSURE</option></select></div>
          <div class="col-md-4" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpSchedule">Jadwal Operasi</label><select class="form-select" name="operating_schedule_id" id="networkPumpSchedule"><option value="">Tanpa jadwal</option><?php foreach($operatingSchedules as $schedule):?><option value="<?=$schedule['id']?>"><?=e($schedule['code'].' · '.$schedule['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-3" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpStartLevel">Start Level (m)</label><input class="form-control" type="number" step="any" name="start_level_m" id="networkPumpStartLevel"></div>
          <div class="col-md-3" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpStopLevel">Stop Level (m)</label><input class="form-control" type="number" step="any" name="stop_level_m" id="networkPumpStopLevel"></div>
          <div class="col-md-3" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpStartPressure">Start Pressure (m)</label><input class="form-control" type="number" step="any" name="start_pressure_m" id="networkPumpStartPressure"></div>
          <div class="col-md-3" data-node-kinds="pompa" data-node-section="demand"><label class="form-label" for="networkPumpStopPressure">Stop Pressure (m)</label><input class="form-control" type="number" step="any" name="stop_pressure_m" id="networkPumpStopPressure"></div>

          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterTargetType">Pemasangan Pada</label><select class="form-select" name="meter_target_type" id="networkMeterTargetType"><option value="">Pilih...</option><?php foreach(['NODE','LINK','SOURCE','TANK','PUMP'] as $target):?><option><?=$target?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterTargetId">ID Target</label><input class="form-control" type="number" min="1" name="meter_target_id" id="networkMeterTargetId"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterSensor">Sensor</label><select class="form-select" name="meter_sensor_id" id="networkMeterSensor"><option value="">Tanpa sensor</option><?php foreach($availableSensors as $sensor):?><option value="<?=$sensor['id']?>"><?=e($sensor['code'].' · '.$sensor['name'])?></option><?php endforeach?></select></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterCurrentValue">Nilai Terkini</label><input class="form-control" type="number" step="any" name="meter_current_value" id="networkMeterCurrentValue"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterCalibratedValue">Nilai Kalibrasi</label><input class="form-control" type="number" step="any" name="meter_calibrated_value" id="networkMeterCalibratedValue"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterCalibrationFactor">Faktor Kalibrasi</label><input class="form-control" type="number" step="any" name="meter_calibration_factor" id="networkMeterCalibrationFactor" value="1"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterMinimumLimit">Batas Minimum</label><input class="form-control" type="number" step="any" name="meter_minimum_limit" id="networkMeterMinimumLimit"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterMaximumLimit">Batas Maksimum</label><input class="form-control" type="number" step="any" name="meter_maximum_limit" id="networkMeterMaximumLimit"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkMeterMeasuredAt">Waktu Sensor</label><input class="form-control" type="datetime-local" name="meter_measured_at" id="networkMeterMeasuredAt"></div>
          <div class="col-md-4" data-node-kinds="meter" data-node-section="sensor"><label class="form-label" for="networkCommunicationStatus">Status Komunikasi</label><select class="form-select" name="communication_status" id="networkCommunicationStatus"><option value="">Belum diketahui</option><option value="ONLINE">ONLINE</option><option value="OFFLINE">OFFLINE</option><option value="STALE">STALE</option></select></div>
          <div class="col-12 property-result-empty" data-node-section="result"><i class="bi bi-activity"></i><h4>Belum ada hasil analisis</h4><p>Input asli tidak akan berubah. Pressure, head, demand terkirim, deficit, dan status pelayanan akan ditampilkan di sini setelah Tahap 3.</p></div>
          <div class="col-md-4"><label class="form-label" for="networkNodeStatus">Status</label><select class="form-select" name="node_status" id="networkNodeStatus"><option value="aktif">Aktif</option><option value="tidak_aktif">Tidak Aktif</option><option value="perawatan">Perawatan</option></select></div>
          <div class="col-12"><label class="form-label" for="networkNodeDescription">Keterangan</label><textarea class="form-control" name="node_description" id="networkNodeDescription" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button class="btn btn-outline-danger d-none" type="button" id="networkDeleteNode"><i class="bi bi-trash"></i> Hapus Titik</button>
        <div class="d-flex gap-2 ms-auto"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary"><i class="bi bi-check2"></i> Simpan Data Titik</button></div>
      </div>
    </form>
  </div>
</div>

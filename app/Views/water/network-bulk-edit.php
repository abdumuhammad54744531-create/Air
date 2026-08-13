<?php
$nodeKinds=['junction'=>'Junction','source'=>'Sumber','reservoir'=>'Reservoir','tank'=>'Tangki','pompa'=>'Pompa','valve'=>'Valve','meter'=>'Meter'];
$statuses=['aktif'=>'Aktif','tidak_aktif'=>'Tidak Aktif','perawatan'=>'Perawatan'];
$priorities=['sangat_tinggi'=>'Sangat Tinggi','tinggi'=>'Tinggi','sedang'=>'Sedang','rendah'=>'Rendah'];
$yesNo=['0'=>'Tidak','1'=>'Ya'];
$nullableOption=fn(array $items,string $empty='Tidak dipilih')=>[''=>$empty]+$items;
$patternOptions=[''=>'Tanpa pattern'];foreach($demandPatterns as $item)$patternOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
$pumpCurveOptions=[''=>'Tanpa kurva'];foreach($pumpCurves as $item)$pumpCurveOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
$efficiencyOptions=[''=>'Tanpa kurva'];foreach($efficiencyCurves as $item)$efficiencyOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
$scheduleOptions=[''=>'Tanpa jadwal'];foreach($operatingSchedules as $item)$scheduleOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
$sensorOptions=[''=>'Tanpa sensor'];foreach($availableSensors as $item)$sensorOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
$originOptions=[];$destinationOptions=[];$manualNodeOptions=[''=>'Tidak dipilih'];
foreach($bulkNodeOptions as $item){
    $label=$item['code'].' · '.$item['name'].' ('.$item['type_label'].')';
    if(in_array($item['type'],['source','reservoir','node'],true))$originOptions[$item['key']]=$label;
    if(in_array($item['type'],['reservoir','service_area','node'],true))$destinationOptions[$item['key']]=$label;
    if($item['type']==='node')$manualNodeOptions[(string)$item['id']]=$item['code'].' · '.$item['name'];
}
$input=function(string $prefix,array $record,array $field):void{
    [$name,$label,$type]=$field+['','','text'];
    $options=$field[3]??[];$attrs=$field[4]??'';
    $value=$record[$name]??'';
    if($type==='datetime-local'&&$value)$value=substr(str_replace(' ','T',(string)$value),0,16);
    $fullName=$prefix.'['.$name.']';
    $classes='form-control form-control-sm';
    echo '<label class="bulk-field"><span>'.e($label).'</span>';
    if($type==='select'){
        echo '<select class="form-select form-select-sm'.(str_contains($attrs,'bulk-pipe-material')?' bulk-pipe-material':'').'" name="'.e($fullName).'" '.$attrs.'>';
        foreach($options as $optionValue=>$optionLabel)echo '<option value="'.e((string)$optionValue).'" '.((string)$value===(string)$optionValue?'selected':'').'>'.e($optionLabel).'</option>';
        echo '</select>';
    }elseif($type==='textarea'){
        echo '<textarea class="'.$classes.'" name="'.e($fullName).'" rows="2" '.$attrs.'>'.e((string)$value).'</textarea>';
    }else{
        $step=$type==='number'?' step="any"':'';
        echo '<input class="'.$classes.(str_contains($attrs,'bulk-roughness')?' bulk-roughness':'').'" type="'.e($type).'" name="'.e($fullName).'" value="'.e((string)$value).'"'.$step.' '.$attrs.'>';
    }
    echo '</label>';
};
$renderGroups=function(string $prefix,array $record,array $groups)use($input):void{
    foreach($groups as $title=>$fields){
        echo '<section class="bulk-field-group"><h4>'.e($title).'</h4><div class="bulk-field-grid">';
        foreach($fields as $field)$input($prefix,$record,$field);
        echo '</div></section>';
    }
};
$flattenFields=function(array $groups):array{
    $fields=[];foreach($groups as $items)foreach($items as $field)$fields[]=$field;return $fields;
};
$cellInput=function(string $prefix,array $record,array $field):void{
    [$name,$label,$type]=$field+['','','text'];
    $options=$field[3]??[];$attrs=$field[4]??'';$value=$record[$name]??'';
    if($type==='datetime-local'&&$value)$value=substr(str_replace(' ','T',(string)$value),0,16);
    $fullName=$prefix.'['.$name.']';
    if($type==='select'){
        echo '<select class="form-select form-select-sm" name="'.e($fullName).'" '.$attrs.'>';
        foreach($options as $optionValue=>$optionLabel)echo '<option value="'.e((string)$optionValue).'" '.((string)$value===(string)$optionValue?'selected':'').'>'.e($optionLabel).'</option>';
        echo '</select>';
    }elseif($type==='textarea'){
        echo '<textarea class="form-control form-control-sm" name="'.e($fullName).'" rows="2" '.$attrs.'>'.e((string)$value).'</textarea>';
    }else{
        echo '<input class="form-control form-control-sm" type="'.e($type).'" name="'.e($fullName).'" value="'.e((string)$value).'"'.($type==='number'?' step="any"':'').' '.$attrs.'>';
    }
};
$renderTable=function(array $records,array $columns,callable $prefix,callable $identity)use($cellInput):void{
    $columnCount=1+count($columns);
    echo '<div class="table-responsive bulk-wide-table-wrap"><table class="table table-bordered align-middle bulk-wide-table"><thead>';
    echo '<tr><th class="bulk-sticky-key">Data</th>';
    foreach($columns as $field)echo '<th>'.e($field[1]).'</th>';
    echo '</tr></thead><tbody>';
    foreach($records as $record){
        echo '<tr><th class="bulk-sticky-key"><strong>'.e($identity($record)).'</strong><small>'.e((string)($record['type_label']??$record['link_type']??'')).'</small></th>';
        foreach($columns as $field){
            echo '<td>';
            $types=$field[5]??null;
            if($types!==null&&!in_array($record['type']??'',(array)$types,true))echo '<span class="bulk-not-applicable">—</span>';
            else $cellInput($prefix($record),$record,$field);
            echo '</td>';
        }
        echo '</tr>';
    }
    if(!$records)echo '<tr><td colspan="'.$columnCount.'" class="text-center text-secondary py-4">Belum ada data.</td></tr>';
    echo '</tbody></table></div>';
};

$sourceGroups=[
    'Identitas & lokasi'=>[
        ['code','Kode *','text',[],'required'],['name','Nama sumber *','text',[],'required'],['source_type','Jenis sumber *','text',[],'required'],
        ['location_id','ID lokasi','number'],['sensor_id','Sensor','select',$sensorOptions],['latitude','Latitude','number'],['longitude','Longitude','number'],['elevation_m','Elevasi (m)','number'],
    ],
    'Debit & kondisi'=>[
        ['min_flow_lps','Debit minimum (L/s)','number'],['normal_flow_lps','Debit normal (L/s)','number'],['max_flow_lps','Debit maksimum (L/s)','number'],
        ['current_sensor_flow_lps','Debit sensor (L/s)','number'],['measurement_season','Musim pengukuran','text'],['water_quality','Kualitas air','text'],
        ['source_loss_percent','Kehilangan (%)','number'],['last_measured_at','Waktu pengukuran','datetime-local'],['status','Status','select',$statuses],['is_public','Tampil publik','select',$yesNo],['description','Keterangan','textarea'],
    ],
];
$reservoirGroups=[
    'Identitas & lokasi'=>[
        ['code','Kode *','text',[],'required'],['name','Nama reservoir *','text',[],'required'],['reservoir_type','Jenis reservoir *','text',[],'required'],
        ['location_id','ID lokasi','number'],['latitude','Latitude','number'],['longitude','Longitude','number'],['elevation_m','Elevasi (m)','number'],
    ],
    'Dimensi, volume & operasi'=>[
        ['length_m','Panjang (m)','number'],['width_m','Lebar (m)','number'],['height_m','Tinggi (m)','number'],
        ['geometric_volume_m3','Volume geometris (m³)','number'],['effective_percent','Volume efektif (%)','number'],['effective_capacity_m3','Kapasitas efektif (m³)','number'],
        ['minimum_operational_m3','Volume minimum (m³)','number'],['initial_volume_m3','Volume awal (m³)','number'],['initial_water_level_m','Tinggi air awal (m)','number'],
        ['max_inflow_lps','Inflow maksimum (L/s)','number'],['max_outflow_lps','Outflow maksimum (L/s)','number'],['loss_percent','Kehilangan (%)','number'],
        ['status','Status','select',$statuses],['description','Keterangan','textarea'],
    ],
];
$areaGroups=[
    'Identitas & pelanggan'=>[
        ['code','Kode *','text',[],'required'],['name','Nama wilayah *','text',[],'required'],['population','Penduduk','number'],
        ['house_connections','Sambungan rumah','number'],['public_facilities','Fasilitas umum','number'],['priority','Prioritas','select',$priorities],['is_public','Tampil publik','select',$yesNo],
    ],
    'Kebutuhan air'=>[
        ['liters_per_person_day','Liter/orang/hari','number'],['public_facility_liters_day','Kebutuhan fasilitas (L/hari)','number'],
        ['max_day_factor','Faktor hari maksimum','number'],['peak_hour_factor','Faktor jam puncak','number'],['network_loss_percent','Kehilangan jaringan (%)','number'],
        ['service_hours_day','Jam pelayanan/hari','number'],['average_demand_lps','Kebutuhan rata-rata (L/s)','number'],
        ['max_day_demand_lps','Kebutuhan hari maksimum (L/s)','number'],['peak_hour_demand_lps','Kebutuhan jam puncak (L/s)','number'],['description','Keterangan','textarea'],
    ],
];
$manualGroups=[
    'Identitas titik'=>[
        ['code','Kode *','text',[],'required'],['name','Nama titik *','text',[],'required'],['node_type','Jenis titik','select',$nodeKinds],
        ['linked_type','Jenis data induk','select',[''=>'Tidak ditautkan','source'=>'Sumber','reservoir'=>'Reservoir','service_area'=>'Wilayah layanan']],
        ['linked_id','ID data induk','number'],['status','Status','select',$statuses],['description','Keterangan','textarea'],
    ],
    'Demand, elevasi & tekanan'=>[
        ['elevation_m','Elevasi (m)','number'],['base_demand_lps','Base demand (L/s)','number'],['demand_category','Kategori demand','text'],
        ['demand_pattern_id','Demand pattern','select',$patternOptions],['demand_pattern','Kode pattern manual','text'],['emitter_coefficient','Emitter coefficient','number'],
        ['initial_pressure_m','Tekanan awal (m)','number'],['minimum_pressure_m','Tekanan minimum (m)','number'],['required_pressure_m','Tekanan pelayanan (m)','number'],
        ['maximum_pressure_m','Tekanan maksimum (m)','number'],['pressure_exponent','Pressure exponent','number'],['measured_pressure_m','Tekanan terukur (m)','number'],
        ['pressure_measured_at','Waktu ukur tekanan','datetime-local'],['initial_quality','Kualitas awal','number'],['source_quality','Kualitas sumber','number'],
    ],
    'Sumber, reservoir & tangki'=>[
        ['total_head_m','Total head (m)','number'],['head_pattern','Head pattern','text'],
        ['hydraulic_representation','Representasi hidraulika','select',[''=>'Tidak dipilih','RESERVOIR'=>'Reservoir','TANK'=>'Tank','WELL_PUMP'=>'Sumur + pompa']],
        ['source_head_m','Head sumber (m)','number'],['static_water_level_m','Muka air statis (m)','number'],['dynamic_water_level_m','Muka air dinamis (m)','number'],
        ['source_pattern_id','ID pattern sumber','number'],['maximum_withdrawal_lps','Pengambilan maksimum (L/s)','number'],['minimum_operating_flow_lps','Debit operasi minimum (L/s)','number'],
        ['connected_pump_node_id','Pompa terhubung','select',$manualNodeOptions],['initial_level_m','Level awal tangki (m)','number'],
        ['minimum_level_m','Level minimum tangki (m)','number'],['maximum_level_m','Level maksimum tangki (m)','number'],
        ['tank_diameter_m','Diameter tangki (m)','number'],['minimum_volume_m3','Volume minimum (m³)','number'],['volume_curve','Kurva volume manual','text'],
        ['mixing_model','Model pencampuran','select',['mixed'=>'Mixed','2comp'=>'Two compartment','fifo'=>'FIFO','lifo'=>'LIFO']],['tank_overflow','Overflow tangki','select',$yesNo],
    ],
    'Pompa & valve'=>[
        ['pump_curve_id','Kurva pompa','select',$pumpCurveOptions],['efficiency_curve_id','Kurva efisiensi','select',$efficiencyOptions],
        ['pump_curve','Kode kurva manual','text'],['pump_power_kw','Daya pompa lama (kW)','number'],['nominal_power_kw','Daya nominal (kW)','number'],
        ['pump_speed','Kecepatan relatif','number'],['speed_pattern','Pattern kecepatan','text'],['inlet_node_id','Titik masuk','select',$manualNodeOptions],
        ['outlet_node_id','Titik keluar','select',$manualNodeOptions],['unit_count','Jumlah unit','number'],['active_unit_count','Unit aktif','number'],
        ['initial_status','Status awal','select',['OPEN'=>'OPEN','CLOSED'=>'CLOSED']],['control_mode','Mode kontrol','select',['MANUAL'=>'MANUAL','TIME'=>'TIME','TANK_LEVEL'=>'TANK_LEVEL','PRESSURE'=>'PRESSURE']],
        ['start_level_m','Start level (m)','number'],['stop_level_m','Stop level (m)','number'],['start_pressure_m','Start pressure (m)','number'],['stop_pressure_m','Stop pressure (m)','number'],
        ['operating_schedule_id','Jadwal operasi','select',$scheduleOptions],['valve_type','Jenis valve','select',[''=>'Tidak dipilih','PRV'=>'PRV','PSV'=>'PSV','PBV'=>'PBV','FCV'=>'FCV','TCV'=>'TCV','GPV'=>'GPV']],
        ['valve_setting','Setting valve','number'],
    ],
    'Meter & komunikasi'=>[
        ['meter_parameter','Parameter meter','text'],['meter_unit','Satuan meter','text'],
        ['meter_target_type','Jenis target','select',[''=>'Tidak dipilih','NODE'=>'NODE','LINK'=>'LINK','SOURCE'=>'SOURCE','TANK'=>'TANK','PUMP'=>'PUMP']],
        ['meter_target_id','ID target','number'],['meter_sensor_id','Sensor meter','select',$sensorOptions],
        ['meter_current_value','Nilai terkini','number'],['meter_calibrated_value','Nilai terkalibrasi','number'],['meter_calibration_factor','Faktor kalibrasi','number'],
        ['meter_minimum_limit','Batas minimum','number'],['meter_maximum_limit','Batas maksimum','number'],['meter_measured_at','Waktu pengukuran','datetime-local'],
        ['communication_status','Status komunikasi','text'],
    ],
];
$routeGroups=[
    'Sambungan & jenis link'=>[
        ['route_name','Nama link *','text',[],'required'],['origin_key','Titik asal *','select',$originOptions,'required'],['destination_key','Titik tujuan *','select',$destinationOptions,'required'],
        ['link_type','Jenis link','select',['PIPE'=>'Pipa','PUMP'=>'Pompa','VALVE'=>'Valve']],['status','Status aplikasi','select',$statuses],
        ['initial_status','Status awal engine','select',['OPEN'=>'OPEN','CLOSED'=>'CLOSED']],['description','Keterangan','textarea'],
    ],
    'Dimensi & material pipa'=>[
        ['use_manual_length','Gunakan panjang manual','select',$yesNo],['pipe_length_m','Panjang pipa (m)','number'],['geometric_length_m','Panjang geometri (m)','number'],
        ['pipe_diameter_mm','Diameter (mm)','number'],['pipe_type','Material','select',[''=>'Pilih material','HDPE'=>'HDPE','PVC'=>'PVC','Galvanis'=>'Galvanis','Baja'=>'Baja','Beton'=>'Beton','Lainnya'=>'Lainnya'],'data-bulk-material'],
        ['material_code','Kode material','text'],['installation_year','Tahun pemasangan','number'],['roughness_coefficient','Koefisien kekasaran','number',[],'data-bulk-roughness'],
        ['minor_loss_coefficient','Koefisien minor loss','number'],['check_valve','Check valve','select',$yesNo],
    ],
    'Kapasitas, kondisi & kebocoran'=>[
        ['start_elevation_m','Elevasi awal (m)','number'],['end_elevation_m','Elevasi akhir (m)','number'],['elevation_difference_m','Beda elevasi (m)','number'],
        ['max_pipe_capacity_lps','Kapasitas maksimum (L/s)','number'],['planned_flow_lps','Debit rencana (L/s)','number'],['max_velocity_mps','Batas kecepatan (m/s)','number'],
        ['max_unit_headloss_m_per_km','Batas headloss (m/km)','number'],['flow_priority','Prioritas operasi','number'],['leakage_model','Model kebocoran','select',['NONE'=>'Tanpa kebocoran','NODE_EMITTER'=>'Node emitter','PIPE_PERCENT'=>'Persentase pipa','CUSTOM'=>'Kustom']],
        ['loss_percent','Skenario kebocoran (%)','number'],['polyline_json','Data geometri garis','textarea'],
    ],
    'Data pompa & kontrol'=>[
        ['pump_curve_id','Kurva pompa','select',$pumpCurveOptions],['efficiency_curve_id','Kurva efisiensi','select',$efficiencyOptions],
        ['nominal_power_kw','Daya nominal (kW)','number'],['relative_speed','Kecepatan relatif','number'],['speed_pattern_id','ID pattern kecepatan','number'],
        ['unit_count','Jumlah unit','number'],['active_unit_count','Unit aktif','number'],['control_mode','Mode kontrol','select',['MANUAL'=>'MANUAL','TIME'=>'TIME','TANK_LEVEL'=>'TANK_LEVEL','PRESSURE'=>'PRESSURE']],
        ['start_level_m','Start level (m)','number'],['stop_level_m','Stop level (m)','number'],['start_pressure_m','Start pressure (m)','number'],['stop_pressure_m','Stop pressure (m)','number'],
        ['operating_schedule_id','Jadwal operasi','select',$scheduleOptions],['pump_status','Status pompa lama','text'],['pump_capacity_lps','Kapasitas pompa lama (L/s)','number'],['pump_hours','Jam pompa lama','number'],
    ],
    'Data valve'=>[
        ['valve_type','Jenis valve','select',[''=>'Tidak dipilih','PRV'=>'PRV','PSV'=>'PSV','PBV'=>'PBV','FCV'=>'FCV','TCV'=>'TCV','GPV'=>'GPV']],
        ['valve_setting','Setting valve','number'],
    ],
];
$nodeColumnsByName=[];
$addNodeColumns=function(array $groups,array $types)use(&$nodeColumnsByName):void{
    foreach($groups as $fields)foreach($fields as $field){
        $name=$field[0];
        if(!isset($nodeColumnsByName[$name])){$field[5]=$types;$nodeColumnsByName[$name]=$field;}
        else $nodeColumnsByName[$name][5]=array_values(array_unique([...$nodeColumnsByName[$name][5],...$types]));
    }
};
$addNodeColumns($sourceGroups,['source']);
$addNodeColumns($reservoirGroups,['reservoir']);
$addNodeColumns($areaGroups,['service_area']);
$addNodeColumns($manualGroups,['node']);
$orderColumns=function(array $columns,array $preferred):array{
    $byName=[];foreach($columns as $field)$byName[$field[0]]=$field;
    $ordered=[];foreach($preferred as $name)if(isset($byName[$name])){$ordered[]=$byName[$name];unset($byName[$name]);}
    return [...$ordered,...array_values($byName)];
};
$nodeColumns=$orderColumns(array_values($nodeColumnsByName),[
    'code','name','node_type','elevation_m','base_demand_lps','normal_flow_lps',
    'effective_capacity_m3','peak_hour_demand_lps','status','priority','description'
]);
$routeColumns=$orderColumns($flattenFields($routeGroups),[
    'route_name','origin_key','destination_key','link_type','pipe_type','pipe_length_m',
    'pipe_diameter_mm','max_pipe_capacity_lps','planned_flow_lps','loss_percent',
    'roughness_coefficient','status','description'
]);
?>
<div class="network-page-head">
  <div>
    <p class="eyebrow">Jaringan Distribusi</p>
    <h1>Edit Massal Titik dan Pipa</h1>
    <p><strong><?=e($project['code'].' · '.$project['name'])?></strong> — seluruh properti dapat diubah dari halaman ini dan disimpan sekaligus.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?=url('distribution-networks?project='.$project['id'])?>"><i class="bi bi-arrow-left"></i> Kembali ke Diagram</a>
</div>

<section class="panel network-bulk-panel">
  <nav class="network-data-tabs nav nav-tabs" role="tablist">
    <button class="nav-link <?=$activeTab==='nodes'?'active':''?>" data-bs-toggle="tab" data-bs-target="#bulkNodesPane" type="button"><i class="bi bi-record-circle"></i> Edit Massal Titik <span><?=count($bulkNodes)?></span></button>
    <button class="nav-link <?=$activeTab==='routes'?'active':''?>" data-bs-toggle="tab" data-bs-target="#bulkRoutesPane" type="button"><i class="bi bi-bezier2"></i> Edit Massal Pipa <span><?=count($bulkRoutes)?></span></button>
  </nav>
  <div class="tab-content">
    <div class="tab-pane fade <?=$activeTab==='nodes'?'show active':''?>" id="bulkNodesPane">
      <form method="post" action="<?=url('distribution-networks/bulk')?>">
        <?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="mode" value="nodes">
        <div class="bulk-editor-note"><i class="bi bi-clipboard-check"></i><span>Tabel biasa seperti Excel. Klik sel awal lalu tekan Ctrl+V untuk menempelkan beberapa baris dan kolom dari Excel. Geser ke kanan untuk melihat semua data.</span></div>
        <?php
          $nodePrefix=fn(array $row):string=>'nodes['.$row['key'].']';
          $nodeIdentity=fn(array $row):string=>$row['code'].' · '.$row['name'];
        ?>
        <div class="bulk-table-section" id="bulkNodeTable">
          <div class="bulk-table-section-title"><h3>Daftar Titik Jaringan</h3><span><?=count($bulkNodes)?> data</span></div>
          <?php $renderTable($bulkNodes,$nodeColumns,$nodePrefix,$nodeIdentity)?>
        </div>
        <div class="bulk-editor-actions"><span><?=count($bulkNodes)?> titik siap diperbarui</span><button class="btn btn-primary"><i class="bi bi-check2-all"></i> Simpan Semua Titik</button></div>
      </form>
    </div>

    <div class="tab-pane fade <?=$activeTab==='routes'?'show active':''?>" id="bulkRoutesPane">
      <form method="post" action="<?=url('distribution-networks/bulk')?>">
        <?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="mode" value="routes">
        <div class="bulk-editor-note bulk-roughness-toolbar">
          <i class="bi bi-info-circle"></i><span>Seluruh properti pipa, pompa, dan valve tersedia. Koefisien material standar diisi otomatis menurut rumus berikut.</span>
          <label><span>Rumus acuan</span><select class="form-select form-select-sm" name="roughness_formula" id="bulkRoughnessFormula"><option value="H-W">Hazen–Williams</option><option value="D-W">Darcy–Weisbach</option><option value="C-M">Chezy–Manning</option></select></label>
          <small id="bulkRoughnessUnit">H-W memakai faktor C.</small>
        </div>
        <?php
          foreach($bulkRoutes as &$route){$route['origin_key']=$route['origin_type'].':'.$route['origin_id'];$route['destination_key']=$route['destination_type'].':'.$route['destination_id'];}
          unset($route);
        ?>
        <div class="bulk-table-section" id="bulkRouteTable">
          <div class="bulk-table-section-title"><h3>Semua Pipa, Pompa, dan Valve</h3><span><?=count($bulkRoutes)?> data</span></div>
          <?php $renderTable(
              $bulkRoutes,
              $routeColumns,
              fn(array $row):string=>'routes['.$row['id'].']',
              fn(array $row):string=>$row['route_name']
          )?>
        </div>
        <div class="bulk-editor-actions"><span><?=count($bulkRoutes)?> link siap diperbarui</span><button class="btn btn-primary"><i class="bi bi-check2-all"></i> Simpan Semua Link</button></div>
      </form>
    </div>
  </div>
</section>

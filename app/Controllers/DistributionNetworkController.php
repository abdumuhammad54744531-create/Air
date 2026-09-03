<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\ServiceAreaSchemaService;
use PDOException;

final class DistributionNetworkController
{
    public function handle(string $method, ?int $id = null): void
    {
        require_auth(['super_admin','administrator','operator']);
        ServiceAreaSchemaService::ensureElevationColumn();
        if ($method === 'POST' && ($_POST['_method'] ?? '') === 'DELETE') {
            $this->delete($id ?: (int)($_POST['network_id'] ?? 0));
            return;
        }
        if ($method === 'POST') {
            $this->store();
            return;
        }
        $this->index();
    }

    public function savePosition(): never
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        $project=$this->currentProject();
        $type = (string)($_POST['node_type'] ?? '');
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $x = max(-9999, min(9999, (float)($_POST['position_x'] ?? 0)));
        $y = max(-9999, min(9999, (float)($_POST['position_y'] ?? 0)));
        if (!in_array($type, ['source','reservoir','service_area','node'], true) || !$entityId || !$this->entityExists($type, $entityId)) {
            json_response(['success'=>false,'message'=>'Titik jaringan tidak valid.'],422);
        }
        Database::query(
            "INSERT INTO distribution_node_positions(project_id,node_type,entity_id,position_x,position_y,updated_by,created_at,updated_at)
             VALUES(?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE position_x=VALUES(position_x),position_y=VALUES(position_y),updated_by=VALUES(updated_by),updated_at=NOW()",
            [$project['id'],$type,$entityId,$x,$y,user()['id']]
        );
        json_response(['success'=>true,'position_x'=>$x,'position_y'=>$y]);
    }

    public function deletePumpCurve(): never
    {
        require_auth(['super_admin','administrator']);verify_csrf();$id=(int)($_POST['curve_id']??0);$projectId=(int)($_POST['project_id']??0);
        $curve=Database::query("SELECT * FROM hydraulic_curves WHERE id=? AND curve_type='PUMP' AND deleted_at IS NULL",[$id])->fetch();
        if(!$curve){flash('danger','Kurva pompa tidak ditemukan.');redirect('distribution-networks?project='.$projectId);}
        $uses=(int)Database::query("SELECT (SELECT COUNT(*) FROM distribution_networks WHERE pump_curve_id=? AND deleted_at IS NULL)+(SELECT COUNT(*) FROM distribution_nodes WHERE pump_curve_id=? AND deleted_at IS NULL)",[$id,$id])->fetchColumn();
        if($uses>0){flash('danger','Kurva '.$curve['code'].' masih dipakai oleh '.$uses.' pompa. Ganti kurva pada pompa tersebut terlebih dahulu.');redirect('distribution-networks?project='.$projectId);}
        Database::query("UPDATE hydraulic_curves SET status='tidak_aktif',deleted_at=NOW(),updated_at=NOW() WHERE id=?",[$id]);activity('hapus','hydraulic-curves',$id,$curve,null);flash('success','Kurva pompa '.$curve['code'].' berhasil dihapus.');redirect('distribution-networks?project='.$projectId);
    }

    public function cleanupPumpCurves(): never
    {
        require_auth(['super_admin','administrator']);verify_csrf();$projectId=(int)($_POST['project_id']??0);
        $ids=Database::query("SELECT c.id FROM hydraulic_curves c WHERE c.curve_type='PUMP' AND c.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM distribution_networks n WHERE n.pump_curve_id=c.id AND n.deleted_at IS NULL) AND NOT EXISTS(SELECT 1 FROM distribution_nodes d WHERE d.pump_curve_id=c.id AND d.deleted_at IS NULL)")->fetchAll(\PDO::FETCH_COLUMN);
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));Database::query("UPDATE hydraulic_curves SET status='tidak_aktif',deleted_at=NOW(),updated_at=NOW() WHERE id IN ($marks)",$ids);activity('bersihkan-kurva-pompa','hydraulic-curves',null,null,['deleted_ids'=>array_map('intval',$ids)]);}
        flash('success',count($ids).' kurva pompa yang tidak digunakan berhasil dihapus. Kurva yang sedang dipakai tetap aman.');redirect('distribution-networks?project='.$projectId);
    }

    public function createNode(): never
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        $project=$this->currentProject();
        $x = max(-9999,min(9999,(float)($_POST['position_x']??50)));
        $y = max(-9999,min(9999,(float)($_POST['position_y']??50)));
        $temporaryCode = 'TMP-'.bin2hex(random_bytes(8));
        Database::query("INSERT INTO distribution_nodes(project_id,code,name,node_type,status,created_by,created_at,updated_at) VALUES(?,?,?,'junction','aktif',?,NOW(),NOW())",[$project['id'],$temporaryCode,'Titik Baru',user()['id']]);
        $id = (int)Database::connection()->lastInsertId();
        $code = 'J-'.str_pad((string)$id,3,'0',STR_PAD_LEFT);
        $name = 'Titik '.$code;
        Database::query("UPDATE distribution_nodes SET code=?,name=? WHERE id=?",[$code,$name,$id]);
        Database::query("INSERT INTO distribution_node_positions(project_id,node_type,entity_id,position_x,position_y,updated_by,created_at,updated_at) VALUES(?,'node',?,?,?,?,NOW(),NOW())",[$project['id'],$id,$x,$y,user()['id']]);
        activity('tambah','distribution-nodes',$id,null,['code'=>$code,'name'=>$name,'position_x'=>$x,'position_y'=>$y]);
        json_response(['success'=>true,'message'=>'Titik baru berhasil dibuat.','node'=>['id'=>$id,'code'=>$code,'name'=>$name]]);
    }

    public function createRoute(): never
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        $project=$this->currentProject();
        $originKey = trim((string)($_POST['origin_key'] ?? ''));
        $destinationKey = trim((string)($_POST['destination_key'] ?? ''));
        if (!str_contains($originKey,':') || !str_contains($destinationKey,':')) {
            json_response(['success'=>false,'message'=>'Titik asal dan tujuan tidak valid.'],422);
        }
        [$originType,$originId] = explode(':',$originKey,2);
        [$destinationType,$destinationId] = explode(':',$destinationKey,2);
        $originId=(int)$originId;$destinationId=(int)$destinationId;
        $validDirection = $originType==='node' && $destinationType==='node'
            && !($originId===$destinationId);
        if (!$validDirection || !$this->entityExists($originType,$originId) || !$this->entityExists($destinationType,$destinationId)) {
            json_response(['success'=>false,'message'=>'Arah pipa atau titik jaringan tidak valid.'],422);
        }
        $originName=$this->entityName($originType,$originId);
        $destinationName=$this->entityName($destinationType,$destinationId);
        $length=max(1,min(100000,(float)($_POST['pipe_length_m']??1)));
        $data=[
            'project_id'=>$project['id'],
            'route_name'=>'Pipa '.$originName.' ke '.$destinationName,
            'origin_type'=>$originType,'origin_id'=>$originId,
            'destination_type'=>$destinationType,'destination_id'=>$destinationId,
            'pipe_length_m'=>round($length,2),'geometric_length_m'=>round($length,2),'pipe_diameter_mm'=>100,
            'roughness_coefficient'=>100,'link_type'=>'PIPE','use_manual_length'=>0,'leakage_model'=>'NONE',
            'max_pipe_capacity_lps'=>1,'planned_flow_lps'=>0,
            'loss_percent'=>0,'pump_status'=>'tanpa_pompa','flow_priority'=>1,
            'status'=>'aktif','description'=>'Data awal otomatis; silakan klik dua kali pipa untuk melengkapi.',
            'created_by'=>user()['id'],
        ];
        $fields=array_keys($data);
        $placeholders=implode(',',array_fill(0,count($fields),'?'));
        Database::query("INSERT INTO distribution_networks(`".implode('`,`',$fields)."`,created_at,updated_at) VALUES($placeholders,NOW(),NOW())",array_values($data));
        $id=(int)Database::connection()->lastInsertId();
        activity('tambah','distribution-networks',$id,null,$data);
        json_response(['success'=>true,'message'=>'Pipa dibuat dengan data awal.','route_id'=>$id]);
    }

    public function bulkEdit(): void
    {
        require_auth(['super_admin','administrator','operator']);
        $project=$this->currentProject();
        $bulkNodes = [];
        foreach (Database::query("SELECT s.*,s.normal_flow_lps AS value FROM water_sources s JOIN distribution_node_positions p ON p.node_type='source' AND p.entity_id=s.id AND p.project_id=? WHERE s.deleted_at IS NULL ORDER BY s.name",[$project['id']])->fetchAll() as $row) {
            $bulkNodes[] = $row + ['key'=>'source:'.$row['id'],'type'=>'source','type_label'=>'Sumber Air','node_kind'=>'source','value_label'=>'Debit normal','value_unit'=>'L/s','has_elevation'=>true,'has_status'=>true];
        }
        foreach (Database::query("SELECT r.*,r.effective_capacity_m3 AS value FROM reservoirs r JOIN distribution_node_positions p ON p.node_type='reservoir' AND p.entity_id=r.id AND p.project_id=? WHERE r.deleted_at IS NULL ORDER BY r.name",[$project['id']])->fetchAll() as $row) {
            $bulkNodes[] = $row + ['key'=>'reservoir:'.$row['id'],'type'=>'reservoir','type_label'=>'Reservoir','node_kind'=>'reservoir','value_label'=>'Kapasitas','value_unit'=>'m³','has_elevation'=>true,'has_status'=>true];
        }
        foreach (Database::query("SELECT a.*,a.peak_hour_demand_lps AS value,a.priority AS status FROM service_areas a JOIN distribution_node_positions p ON p.node_type='service_area' AND p.entity_id=a.id AND p.project_id=? WHERE a.deleted_at IS NULL ORDER BY a.name",[$project['id']])->fetchAll() as $row) {
            $bulkNodes[] = $row + ['key'=>'service_area:'.$row['id'],'type'=>'service_area','type_label'=>'Wilayah Layanan','node_kind'=>'service_area','value_label'=>'Kebutuhan puncak','value_unit'=>'L/s','has_elevation'=>true,'has_status'=>true];
        }
        foreach (Database::query("SELECT n.*,n.base_demand_lps AS value FROM distribution_nodes n WHERE project_id=? AND deleted_at IS NULL ORDER BY id",[$project['id']])->fetchAll() as $row) {
            $bulkNodes[] = $row + ['key'=>'node:'.$row['id'],'type'=>'node','type_label'=>'Titik Manual','node_kind'=>$row['node_type'],'value_label'=>'Base demand','value_unit'=>'L/s','has_elevation'=>true,'has_status'=>true];
        }
        $bulkRoutes = Database::query(
            "SELECT * FROM distribution_networks WHERE project_id=? AND deleted_at IS NULL ORDER BY flow_priority,route_name",[$project['id']]
        )->fetchAll();
        view('water/network-bulk-edit',[
            'title'=>'Edit Massal Jaringan',
            'bulkNodes'=>$bulkNodes,
            'bulkRoutes'=>$bulkRoutes,
            'activeTab'=>($_GET['tab']??'nodes')==='routes'?'routes':'nodes',
            'project'=>$project,
            'bulkNodeOptions'=>$bulkNodes,
            'demandPatterns'=>Database::query("SELECT id,code,name FROM demand_patterns WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll(),
            'pumpCurves'=>Database::query("SELECT id,code,name FROM hydraulic_curves WHERE deleted_at IS NULL AND status='aktif' AND curve_type='PUMP' ORDER BY name")->fetchAll(),
            'efficiencyCurves'=>Database::query("SELECT id,code,name FROM hydraulic_curves WHERE deleted_at IS NULL AND status='aktif' AND curve_type='EFFICIENCY' ORDER BY name")->fetchAll(),
            'operatingSchedules'=>Database::query("SELECT id,code,name FROM operating_schedules WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll(),
            'availableSensors'=>Database::query("SELECT id,code,name FROM sensors WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll(),
        ]);
    }

    public function bulkUpdate(): void
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        $project=$this->currentProject();
        $mode = ($_POST['mode']??'nodes') === 'routes' ? 'routes' : 'nodes';
        $connection = Database::connection();
        $connection->beginTransaction();
        try {
            if ($mode === 'nodes') {
                foreach ((array)($_POST['nodes']??[]) as $key=>$row) {
                    if (!is_array($row) || !str_contains((string)$key,':')) continue;
                    [$type,$rawId] = explode(':',(string)$key,2);
                    $id=(int)$rawId;
                    if (!$id || !$this->entityExists($type,$id)) continue;
                    $code=trim((string)($row['code']??''));$name=trim((string)($row['name']??''));
                    if ($code==='' || $name==='') throw new \RuntimeException('Kode dan nama titik wajib diisi.');
                    if ($type==='source') {
                        $this->bulkUpdateRecord('water_sources',$id,$row,[
                            'code'=>'required','name'=>'required','location_id'=>'int-null','sensor_id'=>'int-null','source_type'=>'required',
                            'latitude'=>'number-null','longitude'=>'number-null','elevation_m'=>'number-null','min_flow_lps'=>'number-zero',
                            'normal_flow_lps'=>'number-zero','max_flow_lps'=>'number-zero','current_sensor_flow_lps'=>'number-null',
                            'measurement_season'=>'text-null','water_quality'=>'text-null','status'=>['aktif','tidak_aktif','perawatan'],
                            'source_loss_percent'=>'number-zero','last_measured_at'=>'datetime-null','is_public'=>'bool','description'=>'text-null',
                        ]);
                    } elseif ($type==='reservoir') {
                        $this->bulkUpdateRecord('reservoirs',$id,$row,[
                            'code'=>'required','name'=>'required','location_id'=>'int-null','latitude'=>'number-null','longitude'=>'number-null',
                            'elevation_m'=>'number-null','reservoir_type'=>'required','length_m'=>'number-zero','width_m'=>'number-zero','height_m'=>'number-zero',
                            'geometric_volume_m3'=>'number-zero','effective_percent'=>'number-zero','effective_capacity_m3'=>'number-zero',
                            'minimum_operational_m3'=>'number-zero','initial_volume_m3'=>'number-zero','initial_water_level_m'=>'number-zero',
                            'max_inflow_lps'=>'number-null','max_outflow_lps'=>'number-null','loss_percent'=>'number-zero',
                            'status'=>['aktif','tidak_aktif','perawatan'],'description'=>'text-null',
                        ]);
                    } elseif ($type==='service_area') {
                        $this->bulkUpdateRecord('service_areas',$id,$row,[
                            'code'=>'required','name'=>'required','elevation_m'=>'number-null','population'=>'int-zero','house_connections'=>'int-zero','public_facilities'=>'int-zero',
                            'liters_per_person_day'=>'number-zero','public_facility_liters_day'=>'number-zero','max_day_factor'=>'number-zero',
                            'peak_hour_factor'=>'number-zero','network_loss_percent'=>'number-zero','service_hours_day'=>'number-zero',
                            'average_demand_lps'=>'number-zero','max_day_demand_lps'=>'number-zero','peak_hour_demand_lps'=>'number-zero',
                            'priority'=>['sangat_tinggi','tinggi','sedang','rendah'],'is_public'=>'bool','description'=>'text-null',
                        ]);
                    } elseif ($type==='node' && Database::query("SELECT id FROM distribution_nodes WHERE id=? AND project_id=? AND deleted_at IS NULL",[$id,$project['id']])->fetchColumn()) {
                        $this->bulkUpdateRecord('distribution_nodes',$id,$row,$this->bulkNodeFieldSpec(),$project['id']);
                    }
                }
                activity('edit_massal','distribution-nodes',null,null,['jumlah'=>count((array)($_POST['nodes']??[]))]);
            } else {
                foreach ((array)($_POST['routes']??[]) as $rawId=>$row) {
                    $id=(int)$rawId;if (!$id || !is_array($row)) continue;
                    $name=trim((string)($row['route_name']??''));if ($name==='') throw new \RuntimeException('Nama pipa wajib diisi.');
                    foreach (['origin','destination'] as $endpoint) {
                        $key=(string)($row[$endpoint.'_key']??'');
                        if (!str_contains($key,':')) throw new \RuntimeException('Titik asal dan tujuan pipa wajib dipilih.');
                        [$row[$endpoint.'_type'],$endpointId]=explode(':',$key,2);
                        $row[$endpoint.'_id']=(int)$endpointId;
                    }
                    if (!$this->entityExists($row['origin_type'],$row['origin_id']) || !$this->entityExists($row['destination_type'],$row['destination_id'])) {
                        throw new \RuntimeException('Titik asal atau tujuan pipa tidak ditemukan.');
                    }
                    $formula=in_array(($_POST['roughness_formula']??''),['H-W','D-W','C-M'],true)?$_POST['roughness_formula']:'H-W';
                    $standard=$this->standardRoughness((string)($row['pipe_type']??''),$formula);
                    if ($standard!==null) $row['roughness_coefficient']=$standard;
                    if (($row['link_type']??'PIPE')==='PIPE' && (float)($row['roughness_coefficient']??0)<=0) {
                        throw new \RuntimeException('Koefisien kekasaran pipa harus lebih besar dari 0.');
                    }
                    $this->bulkUpdateRecord('distribution_networks',$id,$row,$this->bulkRouteFieldSpec(),$project['id']);
                }
                activity('edit_massal','distribution-networks',null,null,['jumlah'=>count((array)($_POST['routes']??[]))]);
            }
            $connection->commit();
            flash('success',$mode==='nodes'?'Data titik berhasil diperbarui secara massal.':'Data pipa berhasil diperbarui secara massal.');
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) $connection->rollBack();
            flash('danger',$error instanceof PDOException && str_contains($error->getMessage(),'Duplicate')?'Ada kode yang digunakan lebih dari satu kali.':$error->getMessage());
        }
        redirect('distribution-networks/bulk?tab='.($mode==='routes'?'routes':'nodes').'&project='.$project['id']);
    }

    public function saveNode(): void
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        $project=$this->currentProject();
        $id = (int)($_POST['node_id']??0);
        if (!$id || !Database::query("SELECT id FROM distribution_nodes WHERE id=? AND project_id=? AND deleted_at IS NULL",[$id,$project['id']])->fetchColumn()) {
            flash('danger','Titik jaringan tidak ditemukan.');
            redirect('distribution-networks');
        }
        if (($_POST['_method']??'')==='DELETE') {
            $this->deleteNode($id);
            return;
        }
        $linkedKey = trim((string)($_POST['linked_key']??''));
        $linkedType = null;$linkedId = null;
        if ($linkedKey && str_contains($linkedKey,':')) {
            [$candidateType,$candidateId] = explode(':',$linkedKey,2);
            if (in_array($candidateType,['source','reservoir','service_area'],true) && $this->entityExists($candidateType,(int)$candidateId)) {
                $linkedType=$candidateType;$linkedId=(int)$candidateId;
            }
        }
        $demandPatternId=(int)($_POST['demand_pattern_id']??0);
        $demandPatternCode=$demandPatternId?(Database::query("SELECT code FROM demand_patterns WHERE id=? AND deleted_at IS NULL AND status='aktif'",[$demandPatternId])->fetchColumn()?:null):null;
        $pumpCurveId=(int)($_POST['pump_curve_id']??0);
        $efficiencyCurveId=(int)($_POST['efficiency_curve_id']??0);
        $data = [
            'code'=>trim((string)($_POST['code']??'')),
            'name'=>trim((string)($_POST['name']??'')),
            'node_type'=>(string)($_POST['node_type']??'junction'),
            'linked_type'=>$linkedType,
            'linked_id'=>$linkedId,
            'elevation_m'=>$this->number('elevation_m',true),
            'base_demand_lps'=>$this->number('base_demand_lps',true),
            'initial_pressure_m'=>$this->number('initial_pressure_m',true),
            'minimum_pressure_m'=>$this->number('minimum_pressure_m',true),
            'maximum_pressure_m'=>$this->number('maximum_pressure_m',true),
            'emitter_coefficient'=>$this->number('emitter_coefficient',true),
            'demand_pattern'=>$demandPatternCode ?: (trim((string)($_POST['demand_pattern']??'')) ?: null),
            'initial_quality'=>$this->number('initial_quality',true),
            'source_quality'=>$this->number('source_quality',true),
            'total_head_m'=>$this->number('total_head_m',true),
            'head_pattern'=>trim((string)($_POST['head_pattern']??'')) ?: null,
            'initial_level_m'=>$this->number('initial_level_m',true),
            'minimum_level_m'=>$this->number('minimum_level_m',true),
            'maximum_level_m'=>$this->number('maximum_level_m',true),
            'tank_diameter_m'=>$this->number('tank_diameter_m',true),
            'minimum_volume_m3'=>$this->number('minimum_volume_m3',true),
            'volume_curve'=>trim((string)($_POST['volume_curve']??'')) ?: null,
            'mixing_model'=>(string)($_POST['mixing_model']??'mixed'),
            'pump_curve'=>trim((string)($_POST['pump_curve']??'')) ?: null,
            'pump_power_kw'=>$this->number('pump_power_kw',true),
            'pump_speed'=>$this->number('pump_speed',true) ?: 1,
            'speed_pattern'=>trim((string)($_POST['speed_pattern']??'')) ?: null,
            'valve_type'=>trim((string)($_POST['valve_type']??'')) ?: null,
            'valve_setting'=>$this->number('valve_setting',true),
            'meter_parameter'=>trim((string)($_POST['meter_parameter']??'')) ?: null,
            'meter_unit'=>trim((string)($_POST['meter_unit']??'')) ?: null,
            'required_pressure_m'=>$this->nullableNumber('required_pressure_m'),
            'demand_category'=>trim((string)($_POST['demand_category']??'')) ?: null,
            'pressure_exponent'=>max(.01,$this->number('pressure_exponent',true) ?: .5),
            'measured_pressure_m'=>$this->nullableNumber('measured_pressure_m'),
            'pressure_measured_at'=>($measuredAt=trim((string)($_POST['pressure_measured_at']??'')))!==''?str_replace('T',' ',$measuredAt):null,
            'demand_pattern_id'=>$demandPatternId ?: null,
            'master_source_id'=>$linkedType==='source'?$linkedId:null,
            'hydraulic_representation'=>in_array(($_POST['hydraulic_representation']??''),['RESERVOIR','TANK','WELL_PUMP'],true)?$_POST['hydraulic_representation']:null,
            'source_head_m'=>$this->nullableNumber('source_head_m'),
            'static_water_level_m'=>$this->nullableNumber('static_water_level_m'),
            'dynamic_water_level_m'=>$this->nullableNumber('dynamic_water_level_m'),
            'source_pattern_id'=>(int)($_POST['source_pattern_id']??0) ?: null,
            'maximum_withdrawal_lps'=>$this->nullableNumber('maximum_withdrawal_lps'),
            'minimum_operating_flow_lps'=>$this->nullableNumber('minimum_operating_flow_lps'),
            'connected_pump_node_id'=>(int)($_POST['connected_pump_node_id']??0) ?: null,
            'tank_overflow'=>isset($_POST['tank_overflow'])?1:0,
            'pump_curve_id'=>$pumpCurveId ?: null,
            'efficiency_curve_id'=>$efficiencyCurveId ?: null,
            'inlet_node_id'=>(int)($_POST['inlet_node_id']??0) ?: null,
            'outlet_node_id'=>(int)($_POST['outlet_node_id']??0) ?: null,
            'nominal_power_kw'=>$this->nullableNumber('nominal_power_kw'),
            'unit_count'=>max(1,(int)($_POST['unit_count']??1)),
            'active_unit_count'=>max(0,(int)($_POST['active_unit_count']??1)),
            'initial_status'=>in_array(($_POST['initial_status']??''),['OPEN','CLOSED'],true)?$_POST['initial_status']:'OPEN',
            'control_mode'=>in_array(($_POST['control_mode']??''),['MANUAL','TIME','TANK_LEVEL','PRESSURE'],true)?$_POST['control_mode']:'MANUAL',
            'start_level_m'=>$this->nullableNumber('start_level_m'),
            'stop_level_m'=>$this->nullableNumber('stop_level_m'),
            'start_pressure_m'=>$this->nullableNumber('start_pressure_m'),
            'stop_pressure_m'=>$this->nullableNumber('stop_pressure_m'),
            'operating_schedule_id'=>(int)($_POST['operating_schedule_id']??0) ?: null,
            'meter_target_type'=>in_array(($_POST['meter_target_type']??''),['NODE','LINK','SOURCE','TANK','PUMP'],true)?$_POST['meter_target_type']:null,
            'meter_target_id'=>(int)($_POST['meter_target_id']??0) ?: null,
            'meter_sensor_id'=>(int)($_POST['meter_sensor_id']??0) ?: null,
            'meter_current_value'=>$this->nullableNumber('meter_current_value'),
            'meter_calibrated_value'=>$this->nullableNumber('meter_calibrated_value'),
            'meter_calibration_factor'=>$this->number('meter_calibration_factor',true) ?: 1,
            'meter_minimum_limit'=>$this->nullableNumber('meter_minimum_limit'),
            'meter_maximum_limit'=>$this->nullableNumber('meter_maximum_limit'),
            'meter_measured_at'=>($meterMeasuredAt=trim((string)($_POST['meter_measured_at']??'')))!==''?str_replace('T',' ',$meterMeasuredAt):null,
            'communication_status'=>trim((string)($_POST['communication_status']??'')) ?: null,
            'status'=>(string)($_POST['node_status']??'aktif'),
            'description'=>trim((string)($_POST['node_description']??'')) ?: null,
        ];
        // Master adalah sumber kebenaran titik yang ditautkan: nilai teknis tidak boleh berbeda.
        if ($linkedType==='source') {
            $master=Database::query("SELECT name,elevation_m,min_flow_lps,normal_flow_lps,max_flow_lps,status FROM water_sources WHERE id=? AND deleted_at IS NULL",[$linkedId])->fetch();
            $data=array_replace($data,[
                'name'=>$master['name'],'node_type'=>'source','status'=>$master['status'],
                'elevation_m'=>$master['elevation_m'],'total_head_m'=>$master['elevation_m'],'source_head_m'=>$master['elevation_m'],
                'hydraulic_representation'=>'RESERVOIR','minimum_operating_flow_lps'=>$master['min_flow_lps'],
                'maximum_withdrawal_lps'=>$master['max_flow_lps'],'base_demand_lps'=>0,
            ]);
        } elseif ($linkedType==='reservoir') {
            $master=Database::query("SELECT name,elevation_m,length_m,width_m,height_m,initial_water_level_m,minimum_operational_m3,status FROM reservoirs WHERE id=? AND deleted_at IS NULL",[$linkedId])->fetch();
            $area=max(.01,(float)$master['length_m']*(float)$master['width_m']);
            $data=array_replace($data,[
                'name'=>$master['name'],'node_type'=>'tank','status'=>$master['status'],'elevation_m'=>$master['elevation_m'],
                'initial_level_m'=>$master['initial_water_level_m'],'minimum_level_m'=>0,'maximum_level_m'=>$master['height_m'],
                'tank_diameter_m'=>2*sqrt($area/M_PI),'minimum_volume_m3'=>$master['minimum_operational_m3'],
            ]);
        } elseif ($linkedType==='service_area') {
            $master=Database::query("SELECT name,elevation_m,peak_hour_demand_lps FROM service_areas WHERE id=? AND deleted_at IS NULL",[$linkedId])->fetch();
            $data=array_replace($data,[
                'name'=>$master['name'],'node_type'=>'junction','status'=>'aktif','elevation_m'=>$master['elevation_m'],
                'base_demand_lps'=>$master['peak_hour_demand_lps'],
            ]);
        }
        if (!$data['code'] || !$data['name'] || !in_array($data['node_type'],['junction','source','reservoir','tank','pompa','valve','meter'],true)) {
            flash('danger','Kode, nama, dan jenis titik wajib diisi.');
            redirect('distribution-networks');
        }
        $requiredByType = [
            'junction'=>['elevation_m'],
            'source'=>['total_head_m'],
            'reservoir'=>['total_head_m'],
            'tank'=>['elevation_m','initial_level_m','minimum_level_m','maximum_level_m','tank_diameter_m'],
            'valve'=>['valve_type','valve_setting'],
            'meter'=>['meter_parameter','meter_unit'],
        ];
        foreach ($requiredByType[$data['node_type']] ?? [] as $requiredField) {
            if (!array_key_exists($requiredField,$data) || $data[$requiredField]===null || $data[$requiredField]==='') {
                flash('danger','Lengkapi semua kolom bertanda bintang untuk jenis titik yang dipilih.');
                redirect('distribution-networks');
            }
        }
        if ($demandPatternId && !$demandPatternCode) {
            flash('danger','Demand pattern yang dipilih tidak tersedia.');
            redirect('distribution-networks');
        }
        if ($data['node_type']==='pompa') {
            if ($data['inlet_node_id'] && $data['inlet_node_id']===$data['outlet_node_id']) {
                flash('danger','Titik masuk dan keluar pompa tidak boleh sama.');
                redirect('distribution-networks');
            }
            if ($data['active_unit_count']>$data['unit_count'] || $data['pump_speed']<=0) {
                flash('danger','Unit aktif tidak boleh melebihi jumlah unit dan speed pompa harus lebih besar dari 0.');
                redirect('distribution-networks');
            }
            if (!$pumpCurveId && !$data['nominal_power_kw'] && trim((string)($_POST['pump_curve']??''))==='') {
                flash('danger','Pompa wajib memiliki kurva pompa bertitik atau daya nominal.');
                redirect('distribution-networks');
            }
            if ($pumpCurveId && !$this->validCurve($pumpCurveId,'PUMP')) {
                flash('danger','Kurva pompa tidak valid. Gunakan minimal dua titik flow-head dengan flow berurutan dan head tidak negatif.');
                redirect('distribution-networks');
            }
        }
        if ($data['node_type']==='meter' && (!$data['meter_target_type'] || !$data['meter_target_id'])) {
            flash('danger','Meter wajib memiliki jenis target dan ID target.');
            redirect('distribution-networks');
        }
        try {
            $before = Database::query("SELECT * FROM distribution_nodes WHERE id=? AND project_id=?",[$id,$project['id']])->fetch();
            $sets=implode(',',array_map(fn($field)=>"`$field`=?",array_keys($data)));
            Database::query("UPDATE distribution_nodes SET $sets,updated_at=NOW() WHERE id=? AND project_id=?",[...array_values($data),$id,$project['id']]);
            activity('edit','distribution-nodes',$id,$before,$data);
            flash('success','Data titik jaringan berhasil diperbarui tanpa mengubah sambungan pipa.');
        } catch (PDOException $e) {
            flash('danger',str_contains($e->getMessage(),'Duplicate')?'Kode titik sudah digunakan.':'Data titik tidak dapat disimpan.');
        }
        redirect('distribution-networks');
    }

    private function index(): void
    {
        $project=$this->currentProject();
        $positionRows = Database::query("SELECT node_type,entity_id,position_x,position_y FROM distribution_node_positions WHERE project_id=?",[$project['id']])->fetchAll();
        $positions = [];
        foreach ($positionRows as $position) {
            $positions[$position['node_type'].':'.$position['entity_id']] = [(float)$position['position_x'],(float)$position['position_y']];
        }

        // Data master dipilih dari formulir titik. Kanvas hanya menampilkan titik jaringan proyek.
        $nodes = [];
        // Posisi diagram bersifat khusus proyek, sedangkan data master bersifat global.
        // Jangan memakai INNER JOIN ke tabel posisi: master yang belum pernah digeser
        // tetap harus muncul di kanvas dan pada pilihan "Hubungkan dengan Data Master".
        $sources = Database::query("SELECT s.id,s.code,s.name,s.latitude,s.longitude,s.elevation_m,s.min_flow_lps,s.normal_flow_lps,s.max_flow_lps,s.current_sensor_flow_lps,s.status,s.description FROM water_sources s WHERE s.deleted_at IS NULL ORDER BY s.name")->fetchAll();
        $reservoirs = Database::query("SELECT r.id,r.code,r.name,r.elevation_m,r.length_m,r.width_m,r.height_m,r.effective_capacity_m3,r.initial_volume_m3,r.initial_water_level_m,r.minimum_operational_m3,r.status,r.description FROM reservoirs r WHERE r.deleted_at IS NULL ORDER BY r.name")->fetchAll();
        $areas = Database::query("SELECT a.id,a.code,a.name,a.elevation_m,a.population,a.peak_hour_demand_lps,a.priority,a.description FROM service_areas a WHERE a.deleted_at IS NULL ORDER BY FIELD(a.priority,'sangat_tinggi','tinggi','sedang','rendah'),a.name")->fetchAll();
        $masterNodes=[];
        $this->appendNodes($masterNodes, $sources, 'source', [], 12);
        $this->appendNodes($masterNodes, $reservoirs, 'reservoir', [], 48);
        $this->appendNodes($masterNodes, $areas, 'service_area', [], 84);
        $mastersByKey=[];
        foreach ($masterNodes as $master) $mastersByKey[$master['key']]=$master;
        $manualNodes = Database::query("SELECT * FROM distribution_nodes WHERE project_id=? AND deleted_at IS NULL ORDER BY id",[$project['id']])->fetchAll();
        foreach ($manualNodes as $index=>$row) {
            $row=$this->synchronizeLinkedMasterRow($row,$mastersByKey);
            $key='node:'.$row['id'];
            [$x,$y]=$positions[$key]??[50,50];
            $nodes[]=[
                'key'=>$key,'type'=>'node','id'=>(int)$row['id'],'code'=>$row['code'],'name'=>$row['name'],
                'x'=>round($x,3),'y'=>round($y,3),'elevation'=>(float)$row['elevation_m'],'status'=>$row['status'],
                'node_kind'=>$row['node_type'],'base_demand'=>(float)$row['base_demand_lps'],
                'initial_pressure'=>(float)$row['initial_pressure_m'],'minimum_pressure'=>(float)$row['minimum_pressure_m'],
                'maximum_pressure'=>(float)$row['maximum_pressure_m'],'emitter_coefficient'=>(float)$row['emitter_coefficient'],
                'demand_pattern'=>$row['demand_pattern'],'initial_quality'=>(float)$row['initial_quality'],'source_quality'=>(float)$row['source_quality'],
                'total_head'=>(float)$row['total_head_m'],'head_pattern'=>$row['head_pattern'],
                'initial_level'=>(float)$row['initial_level_m'],'minimum_level'=>(float)$row['minimum_level_m'],
                'maximum_level'=>(float)$row['maximum_level_m'],'tank_diameter'=>(float)$row['tank_diameter_m'],
                'minimum_volume'=>(float)$row['minimum_volume_m3'],'volume_curve'=>$row['volume_curve'],'mixing_model'=>$row['mixing_model'],
                'pump_curve'=>$row['pump_curve'],'pump_power'=>(float)$row['pump_power_kw'],'pump_speed'=>(float)$row['pump_speed'],
                'speed_pattern'=>$row['speed_pattern'],'valve_type'=>$row['valve_type'],'valve_setting'=>(float)$row['valve_setting'],
                'meter_parameter'=>$row['meter_parameter'],'meter_unit'=>$row['meter_unit'],
                'linked_key'=>$row['linked_type']&&$row['linked_id']?$row['linked_type'].':'.$row['linked_id']:'',
                'master_type'=>$row['linked_type']?:null,
                'required_pressure'=>(float)$row['required_pressure_m'],'demand_category'=>$row['demand_category'],
                'pressure_exponent'=>(float)$row['pressure_exponent'],'measured_pressure'=>$row['measured_pressure_m'],
                'pressure_measured_at'=>$row['pressure_measured_at'],'demand_pattern_id'=>$row['demand_pattern_id'],
                'master_source_id'=>$row['master_source_id'],'hydraulic_representation'=>$row['hydraulic_representation'],
                'source_head'=>(float)$row['source_head_m'],'static_water_level'=>$row['static_water_level_m'],
                'dynamic_water_level'=>$row['dynamic_water_level_m'],'source_pattern_id'=>$row['source_pattern_id'],
                'maximum_withdrawal'=>$row['maximum_withdrawal_lps'],'minimum_operating_flow'=>$row['minimum_operating_flow_lps'],
                'connected_pump_node_id'=>$row['connected_pump_node_id'],'tank_overflow'=>(int)$row['tank_overflow'],
                'pump_curve_id'=>$row['pump_curve_id'],'efficiency_curve_id'=>$row['efficiency_curve_id'],
                'inlet_node_id'=>$row['inlet_node_id'],'outlet_node_id'=>$row['outlet_node_id'],
                'nominal_power'=>$row['nominal_power_kw'],'unit_count'=>(int)$row['unit_count'],'active_unit_count'=>(int)$row['active_unit_count'],
                'initial_status'=>$row['initial_status'],'control_mode'=>$row['control_mode'],
                'start_level'=>$row['start_level_m'],'stop_level'=>$row['stop_level_m'],
                'start_pressure'=>$row['start_pressure_m'],'stop_pressure'=>$row['stop_pressure_m'],
                'operating_schedule_id'=>$row['operating_schedule_id'],
                'meter_target_type'=>$row['meter_target_type'],'meter_target_id'=>$row['meter_target_id'],
                'meter_sensor_id'=>$row['meter_sensor_id'],'meter_current_value'=>$row['meter_current_value'],
                'meter_calibrated_value'=>$row['meter_calibrated_value'],'meter_calibration_factor'=>$row['meter_calibration_factor'],
                'meter_minimum_limit'=>$row['meter_minimum_limit'],'meter_maximum_limit'=>$row['meter_maximum_limit'],
                'meter_measured_at'=>$row['meter_measured_at'],'communication_status'=>$row['communication_status'],
                'description'=>$row['description'],'edit_url'=>null,
            ];
        }

        $networkRows = Database::query("SELECT * FROM distribution_networks WHERE project_id=? AND deleted_at IS NULL ORDER BY flow_priority,route_name",[$project['id']])->fetchAll();
        $nodeIndex = [];
        foreach ($nodes as $node) $nodeIndex[$node['key']] = $node;
        $networks = [];
        foreach ($networkRows as $row) {
            $originKey = $row['origin_type'].':'.$row['origin_id'];
            $destinationKey = $row['destination_type'].':'.$row['destination_id'];
            if ($row['origin_type']!=='node' || $row['destination_type']!=='node') continue;
            if (!isset($nodeIndex[$originKey], $nodeIndex[$destinationKey])) continue;
            $row['origin_key'] = $originKey;
            $row['destination_key'] = $destinationKey;
            $row['origin_name'] = $nodeIndex[$originKey]['name'];
            $row['destination_name'] = $nodeIndex[$destinationKey]['name'];
            $networks[] = $row;
        }

        $stats = [
            'nodes'=>count($nodes),
            'routes'=>count($networks),
            'active'=>count(array_filter($networks,fn($row)=>$row['status']==='aktif')),
            'planned_flow'=>array_sum(array_map(fn($row)=>(float)($row['planned_flow_lps']??0),$networks)),
            'loss'=>count($networks) ? array_sum(array_map(fn($row)=>(float)($row['loss_percent']??0),$networks))/count($networks) : 0,
            'manual_nodes'=>count($manualNodes),
        ];
        $demandPatterns=Database::query("SELECT id,code,name FROM demand_patterns WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll();
        $pumpCurves=Database::query("SELECT c.id,c.code,c.name,c.points_json,c.description,((SELECT COUNT(*) FROM distribution_networks n WHERE n.pump_curve_id=c.id AND n.deleted_at IS NULL)+(SELECT COUNT(*) FROM distribution_nodes d WHERE d.pump_curve_id=c.id AND d.deleted_at IS NULL)) usage_count FROM hydraulic_curves c WHERE c.deleted_at IS NULL AND c.status='aktif' AND c.curve_type='PUMP' ORDER BY c.name")->fetchAll();
        $efficiencyCurves=Database::query("SELECT id,code,name,points_json FROM hydraulic_curves WHERE deleted_at IS NULL AND status='aktif' AND curve_type='EFFICIENCY' ORDER BY name")->fetchAll();
        $operatingSchedules=Database::query("SELECT id,code,name FROM operating_schedules WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll();
        $availableSensors=Database::query("SELECT id,code,name FROM sensors WHERE deleted_at IS NULL AND status='aktif' ORDER BY name")->fetchAll();
        $pipeDesignMaterials=['HDPE','uPVC','PVC','Ductile Iron','Steel','Galvanis'];
        try {
            $catalogMaterials=Database::query("SELECT DISTINCT material FROM pipe_diameter_catalog WHERE is_active=1 AND deleted_at IS NULL AND material IS NOT NULL AND material<>'' ORDER BY material")->fetchAll();
            if ($catalogMaterials) $pipeDesignMaterials=array_values(array_column($catalogMaterials,'material'));
        } catch (\Throwable $e) {
            // Katalog dibuat oleh modul desain otomatis; pilihan bawaan menjaga editor tetap dapat dibuka sebelum migrasi.
        }
        view('water/network-editor',[
            'title'=>'Jaringan Distribusi',
            'nodes'=>$nodes,
            'masterNodes'=>$masterNodes,
            'networks'=>$networks,
            'stats'=>$stats,
            'demandPatterns'=>$demandPatterns,
            'pumpCurves'=>$pumpCurves,
            'efficiencyCurves'=>$efficiencyCurves,
            'operatingSchedules'=>$operatingSchedules,
            'availableSensors'=>$availableSensors,
            'pipeDesignMaterials'=>$pipeDesignMaterials,
            'project'=>$project,
            'projects'=>Database::query("SELECT id,code,name,status FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,name")->fetchAll(),
        ]);
    }

    private function appendNodes(array &$nodes, array $rows, string $type, array $positions, float $defaultX): void
    {
        $count = max(1,count($rows));
        foreach ($rows as $index=>$row) {
            $key = $type.':'.$row['id'];
            [$x,$y] = $positions[$key] ?? [$defaultX, (($index + 1) * 100 / ($count + 1))];
            $nodes[] = [
                'key'=>$key,
                'type'=>$type,
                'id'=>(int)$row['id'],
                'code'=>$row['code'],
                'name'=>$row['name'],
                'x'=>round($x,3),
                'y'=>round($y,3),
                'elevation'=>(float)($row['elevation_m']??0),
                'status'=>$row['status']??'aktif',
                'normal_flow'=>$type==='source'?(float)($row['normal_flow_lps']??0):null,
                'minimum_flow'=>$type==='source'?(float)($row['min_flow_lps']??0):null,
                'maximum_flow'=>$type==='source'?(float)($row['max_flow_lps']??0):null,
                'sensor_flow'=>$type==='source'?(float)($row['current_sensor_flow_lps']??0):null,
                'latitude'=>$type==='source'?($row['latitude']??null):null,
                'longitude'=>$type==='source'?($row['longitude']??null):null,
                'capacity'=>$type==='reservoir'?(float)($row['effective_capacity_m3']??0):null,
                'initial_volume'=>$type==='reservoir'?(float)($row['initial_volume_m3']??0):null,
                'initial_level'=>$type==='reservoir'?(float)($row['initial_water_level_m']??0):null,
                'maximum_level'=>$type==='reservoir'?(float)($row['height_m']??0):null,
                'minimum_volume'=>$type==='reservoir'?(float)($row['minimum_operational_m3']??0):null,
                'tank_diameter'=>$type==='reservoir'?2*sqrt(max(.01,(float)($row['length_m']??0)*(float)($row['width_m']??0))/M_PI):null,
                'population'=>$type==='service_area'?(int)($row['population']??0):null,
                'demand'=>$type==='service_area'?(float)($row['peak_hour_demand_lps']??0):null,
                'priority'=>$type==='service_area'?($row['priority']??'sedang'):null,
                'description'=>$row['description']??null,
                'edit_url'=>url(match($type){'source'=>'water-sources','reservoir'=>'reservoirs',default=>'service-areas'}.'/'.$row['id']),
            ];
        }
    }

    /** Tampilan titik selalu mengikuti data master terbaru tanpa menunggu titik disimpan ulang. */
    private function synchronizeLinkedMasterRow(array $node, array $mastersByKey): array
    {
        $key=($node['linked_type']??null)&&($node['linked_id']??null)?$node['linked_type'].':'.$node['linked_id']:null;
        $master=$key?$mastersByKey[$key]??null:null;
        if (!$master) return $node;
        if ($master['type']==='source') return array_replace($node,[
            'name'=>$master['name'],'node_type'=>'source','status'=>$master['status'],'elevation_m'=>$master['elevation'],
            'total_head_m'=>$master['elevation'],'source_head_m'=>$master['elevation'],'hydraulic_representation'=>'RESERVOIR',
            'minimum_operating_flow_lps'=>$master['minimum_flow'],'maximum_withdrawal_lps'=>$master['maximum_flow'],'base_demand_lps'=>0,
        ]);
        if ($master['type']==='reservoir') return array_replace($node,[
            'name'=>$master['name'],'node_type'=>'tank','status'=>$master['status'],'elevation_m'=>$master['elevation'],
            'initial_level_m'=>$master['initial_level'],'minimum_level_m'=>0,'maximum_level_m'=>$master['maximum_level'],
            'tank_diameter_m'=>$master['tank_diameter'],'minimum_volume_m3'=>$master['minimum_volume'],
        ]);
        if ($master['type']==='service_area') return array_replace($node,[
            'name'=>$master['name'],'node_type'=>'junction','status'=>'aktif','elevation_m'=>$master['elevation'],
            'base_demand_lps'=>$master['demand'],
        ]);
        return $node;
    }

    private function store(): void
    {
        verify_csrf();
        $project=$this->currentProject();
        $id = (int)($_POST['network_id'] ?? 0);
        $linkType=in_array(($_POST['link_type']??'PIPE'),['PIPE','PUMP','VALVE'],true)?$_POST['link_type']:'PIPE';
        $useManualLength=isset($_POST['use_manual_length'])?1:0;
        $pumpDefinitionMode=($_POST['pump_definition_mode']??'HEAD')==='POWER'?'POWER':'HEAD';
        $pumpCurveId=$pumpDefinitionMode==='HEAD'?(int)($_POST['pump_curve_id']??0):0;$newPumpCurve=null;
        if($linkType==='PUMP'&&$pumpDefinitionMode==='HEAD'&&!$pumpCurveId){try{$newPumpCurve=$this->pumpCurveInput();}catch(\RuntimeException $error){flash('danger',$error->getMessage());redirect('distribution-networks');}}
        $data = [
            'project_id'=>$project['id'],
            'route_name'=>trim((string)($_POST['route_name']??'')),
            'origin_type'=>(string)($_POST['origin_type']??''),
            'origin_id'=>(int)($_POST['origin_id']??0),
            'destination_type'=>(string)($_POST['destination_type']??''),
            'destination_id'=>(int)($_POST['destination_id']??0),
            'pipe_length_m'=>$this->number('pipe_length_m'),
            'pipe_diameter_mm'=>$this->number('pipe_diameter_mm'),
            'pipe_type'=>trim((string)($_POST['pipe_type']??'')) ?: null,
            'roughness_coefficient'=>$this->number('roughness_coefficient',true),
            'minor_loss_coefficient'=>$this->number('minor_loss_coefficient',true),
            'check_valve'=>isset($_POST['check_valve'])?1:0,
            'start_elevation_m'=>$this->number('start_elevation_m',true),
            'end_elevation_m'=>$this->number('end_elevation_m',true),
            'max_pipe_capacity_lps'=>$this->number('max_pipe_capacity_lps'),
            'planned_flow_lps'=>$this->number('planned_flow_lps',true),
            'loss_percent'=>min(100,$this->number('loss_percent',true)),
            'flow_priority'=>max(1,(int)($_POST['flow_priority']??1)),
            'status'=>(string)($_POST['status']??'aktif'),
            'description'=>trim((string)($_POST['description']??'')) ?: null,
            'link_type'=>$linkType,
            'use_manual_length'=>$useManualLength,
            'geometric_length_m'=>$this->nullableNumber('geometric_length_m'),
            'material_code'=>trim((string)($_POST['material_code']??'')) ?: (trim((string)($_POST['pipe_type']??'')) ?: null),
            'installation_year'=>(int)($_POST['installation_year']??0) ?: null,
            'max_velocity_mps'=>$this->nullableNumber('max_velocity_mps'),
            'max_unit_headloss_m_per_km'=>$this->nullableNumber('max_unit_headloss_m_per_km'),
            'leakage_model'=>in_array(($_POST['leakage_model']??''),['NONE','NODE_EMITTER','PIPE_PERCENT','CUSTOM'],true)?$_POST['leakage_model']:'NONE',
            'polyline_json'=>trim((string)($_POST['polyline_json']??'')) ?: null,
            'pump_curve_id'=>$pumpCurveId ?: null,
            'efficiency_curve_id'=>(int)($_POST['efficiency_curve_id']??0) ?: null,
            'nominal_power_kw'=>$pumpDefinitionMode==='POWER'?$this->nullableNumber('nominal_power_kw'):null,
            'relative_speed'=>max(.0001,$this->number('relative_speed',true) ?: 1),
            'speed_pattern_id'=>(int)($_POST['speed_pattern_id']??0) ?: null,
            'initial_status'=>in_array(($_POST['initial_status']??''),['OPEN','CLOSED'],true)?$_POST['initial_status']:'OPEN',
            'unit_count'=>max(1,(int)($_POST['unit_count']??1)),
            'active_unit_count'=>max(0,(int)($_POST['active_unit_count']??1)),
            'control_mode'=>in_array(($_POST['control_mode']??''),['MANUAL','TIME','TANK_LEVEL','PRESSURE'],true)?$_POST['control_mode']:'MANUAL',
            'start_level_m'=>$this->nullableNumber('start_level_m'),
            'stop_level_m'=>$this->nullableNumber('stop_level_m'),
            'start_pressure_m'=>$this->nullableNumber('start_pressure_m'),
            'stop_pressure_m'=>$this->nullableNumber('stop_pressure_m'),
            'operating_schedule_id'=>(int)($_POST['operating_schedule_id']??0) ?: null,
            'valve_type'=>trim((string)($_POST['valve_type']??'')) ?: null,
            'valve_setting'=>$this->nullableNumber('valve_setting'),
        ];
        if ($linkType==='PIPE') {
            $formula=in_array(($_POST['roughness_formula']??''),['H-W','D-W','C-M'],true)?$_POST['roughness_formula']:'H-W';
            $standard=$this->standardRoughness((string)($data['pipe_type']??''),$formula);
            if ($standard!==null) $data['roughness_coefficient']=$standard;
        }
        if (!$data['route_name'] || !$data['origin_id'] || !$data['destination_id']) {
            flash('danger','Nama link serta titik asal dan tujuan wajib diisi.');
            redirect('distribution-networks');
        }
        if ($data['origin_type']!=='node' || $data['destination_type']!=='node') {
            flash('danger','Pipa hanya dapat menghubungkan Titik Jaringan. Tambahkan titik dan pilih data master pada titik tersebut terlebih dahulu.');
            redirect('distribution-networks');
        }
        if (!$this->entityExists($data['origin_type'],$data['origin_id']) || !$this->entityExists($data['destination_type'],$data['destination_id'])) {
            flash('danger','Titik asal atau tujuan tidak ditemukan pada data Pengelolaan Air.');
            redirect('distribution-networks');
        }
        if ($data['origin_type']===$data['destination_type'] && $data['origin_id']===$data['destination_id']) {
            flash('danger','Titik asal dan tujuan tidak boleh sama.');
            redirect('distribution-networks');
        }
        if ($linkType==='PIPE' && ($data['pipe_length_m']<=0 || $data['pipe_diameter_mm']<=0 || $data['roughness_coefficient']<=0)) {
            flash('danger','Pipa wajib memiliki panjang, diameter, dan koefisien kekasaran lebih besar dari 0.');
            redirect('distribution-networks');
        }
        if ($linkType==='PUMP') {
            if ($data['active_unit_count']>$data['unit_count'] || $data['relative_speed']<=0) {
                flash('danger','Unit aktif pompa tidak boleh melebihi jumlah unit dan speed harus lebih besar dari 0.');
                redirect('distribution-networks');
            }
            if ($pumpDefinitionMode==='HEAD'&&!$pumpCurveId&&!$newPumpCurve) {
                flash('danger','Link pompa wajib memiliki kurva pompa bertitik atau daya nominal.');
                redirect('distribution-networks');
            }
            if ($pumpDefinitionMode==='POWER'&&(!$data['nominal_power_kw']||$data['nominal_power_kw']<=0)) {
                flash('danger','Definisi POWER memerlukan daya nominal pompa lebih besar dari 0 kW.');
                redirect('distribution-networks');
            }
            if ($pumpCurveId && !$this->validCurve($pumpCurveId,'PUMP')) {
                flash('danger','Kurva pompa tidak valid atau belum mempunyai titik flow-head yang cukup.');
                redirect('distribution-networks');
            }
        }
        if ($linkType==='VALVE' && (!$data['valve_type'] || $data['pipe_diameter_mm']<=0 || $data['valve_setting']===null)) {
            flash('danger','Valve wajib memiliki tipe, diameter, dan setting.');
            redirect('distribution-networks');
        }
        $data['elevation_difference_m'] = round($data['start_elevation_m']-$data['end_elevation_m'],2);
        $pdo=Database::connection();
        try {
            $pdo->beginTransaction();
            if($newPumpCurve){$curveCode=$newPumpCurve['code']?:'PC-'.date('Ymd-His');$baseCode=$curveCode;$suffix=2;while(Database::query("SELECT COUNT(*) FROM hydraulic_curves WHERE code=?",[$curveCode])->fetchColumn())$curveCode=$baseCode.'-'.$suffix++;Database::query("INSERT INTO hydraulic_curves(code,name,curve_type,points_json,status,description,created_by,created_at,updated_at) VALUES(?,?,'PUMP',?,'aktif','Dibuat dari editor diagram jaringan',?,NOW(),NOW())",[$curveCode,$newPumpCurve['name']?:('Kurva '.$data['route_name']),json_encode($newPumpCurve['points'],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),user()['id']]);$pumpCurveId=(int)$pdo->lastInsertId();$data['pump_curve_id']=$pumpCurveId;}
            if ($id) {
                $before = Database::query("SELECT * FROM distribution_networks WHERE id=? AND project_id=? AND deleted_at IS NULL",[$id,$project['id']])->fetch();
                if (!$before) {
                    flash('danger','Jalur tidak ditemukan.');
                    redirect('distribution-networks');
                }
                $sets = implode(',',array_map(fn($field)=>"`$field`=?",array_keys($data)));
                Database::query("UPDATE distribution_networks SET $sets,updated_at=NOW() WHERE id=? AND project_id=?", [...array_values($data),$id,$project['id']]);
                activity('edit','distribution-networks',$id,$before,$data);
            } else {
                $data['created_by'] = user()['id'];
                $fields = array_keys($data);
                Database::query(
                    "INSERT INTO distribution_networks (`".implode('`,`',$fields)."`,created_at,updated_at) VALUES(".implode(',',array_fill(0,count($fields),'?')).",NOW(),NOW())",
                    array_values($data)
                );
                activity('tambah','distribution-networks',(int)Database::connection()->lastInsertId(),null,$data);
            }
            $pdo->commit();
            flash('success','Jalur distribusi berhasil disimpan dan digambar pada diagram.');
        } catch (\Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('danger','Jalur tidak dapat disimpan. Periksa kembali nilai yang diisi.');
        }
        redirect('distribution-networks');
    }

    private function delete(?int $id): void
    {
        verify_csrf();
        $project=$this->currentProject();
        if (!$id || !has_role(['super_admin','administrator'])) {
            http_response_code(403);
            return;
        }
        Database::query("UPDATE distribution_networks SET deleted_at=NOW(),updated_at=NOW() WHERE id=? AND project_id=?",[$id,$project['id']]);
        activity('hapus','distribution-networks',$id);
        flash('success','Jalur distribusi telah diarsipkan.');
        redirect('distribution-networks');
    }

    private function deleteNode(int $id): void
    {
        $project=$this->currentProject();
        if (!has_role(['super_admin','administrator'])) {
            http_response_code(403);
            return;
        }
        Database::query("UPDATE distribution_nodes SET deleted_at=NOW(),updated_at=NOW() WHERE id=? AND project_id=?",[$id,$project['id']]);
        Database::query("UPDATE distribution_networks SET deleted_at=NOW(),updated_at=NOW() WHERE project_id=? AND ((origin_type='node' AND origin_id=?) OR (destination_type='node' AND destination_id=?))",[$project['id'],$id,$id]);
        Database::query("DELETE FROM distribution_node_positions WHERE project_id=? AND node_type='node' AND entity_id=?",[$project['id'],$id]);
        activity('hapus','distribution-nodes',$id);
        flash('success','Titik dan jalur pipa yang terhubung telah diarsipkan.');
        redirect('distribution-networks');
    }

    private function entityExists(string $type, int $id): bool
    {
        if ($type==='node') {
            $project=$this->currentProject();
            return (bool)Database::query("SELECT id FROM distribution_nodes WHERE id=? AND project_id=? AND deleted_at IS NULL",[$id,$project['id']])->fetchColumn();
        }
        $table = match($type) {
            'source'=>'water_sources',
            'reservoir'=>'reservoirs',
            'service_area'=>'service_areas',
            'node'=>'distribution_nodes',
            default=>null,
        };
        return $table ? (bool)Database::query("SELECT id FROM `$table` WHERE id=? AND deleted_at IS NULL",[$id])->fetchColumn() : false;
    }

    private function entityName(string $type, int $id): string
    {
        if ($type==='node') {
            $project=$this->currentProject();
            return (string)(Database::query("SELECT name FROM distribution_nodes WHERE id=? AND project_id=? AND deleted_at IS NULL",[$id,$project['id']])->fetchColumn()?:'Titik');
        }
        $table = match($type) {
            'source'=>'water_sources','reservoir'=>'reservoirs','service_area'=>'service_areas','node'=>'distribution_nodes',default=>null,
        };
        if (!$table) return 'Titik';
        return (string)(Database::query("SELECT name FROM `$table` WHERE id=? AND deleted_at IS NULL",[$id])->fetchColumn() ?: 'Titik');
    }

    private function currentProject(): array
    {
        $requested=(int)($_POST['project_id']??$_GET['project']??$_SESSION['network_project_id']??0);
        $project=$requested?Database::query("SELECT * FROM network_projects WHERE id=? AND deleted_at IS NULL",[$requested])->fetch():null;
        if (!$project) $project=Database::query("SELECT * FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,id LIMIT 1")->fetch();
        if (!$project) throw new \RuntimeException('Belum ada proyek jaringan aktif.');
        $_SESSION['network_project_id']=(int)$project['id'];
        return $project;
    }

    private function bulkUpdateRecord(string $table, int $id, array $row, array $spec, ?int $projectId=null): void
    {
        $data=[];
        foreach ($spec as $field=>$type) {
            if (!array_key_exists($field,$row)) continue;
            $raw=is_string($row[$field])?trim($row[$field]):$row[$field];
            if (is_array($type)) {
                if (!in_array((string)$raw,$type,true)) throw new \RuntimeException("Nilai $field tidak valid.");
                $value=(string)$raw;
            } else {
                $value=match($type) {
                    'required'=>$raw!==''?(string)$raw:throw new \RuntimeException("$field wajib diisi."),
                    'text-null'=>$raw===''?null:(string)$raw,
                    'number-null'=>$raw===''?null:(is_numeric(str_replace(',','.',(string)$raw))?(float)str_replace(',','.',(string)$raw):throw new \RuntimeException("$field harus berupa angka.")),
                    'number-zero'=>$raw===''?0:(is_numeric(str_replace(',','.',(string)$raw))?(float)str_replace(',','.',(string)$raw):throw new \RuntimeException("$field harus berupa angka.")),
                    'int-null'=>$raw===''?null:(int)$raw,
                    'int-zero'=>$raw===''?0:(int)$raw,
                    'bool'=>(int)(bool)$raw,
                    'datetime-null'=>$raw===''?null:str_replace('T',' ',(string)$raw),
                    default=>$raw,
                };
            }
            $data[$field]=$value;
        }
        if (!$data) return;
        $sets=implode(',',array_map(fn($field)=>"`$field`=?",array_keys($data)));
        $where='id=? AND deleted_at IS NULL';$params=[...array_values($data),$id];
        if ($projectId!==null) {$where.=' AND project_id=?';$params[]=$projectId;}
        Database::query("UPDATE `$table` SET $sets,updated_at=NOW() WHERE $where",$params);
    }

    private function bulkNodeFieldSpec(): array
    {
        $numbers=['elevation_m','base_demand_lps','initial_pressure_m','minimum_pressure_m','maximum_pressure_m','emitter_coefficient',
            'initial_quality','source_quality','total_head_m','initial_level_m','minimum_level_m','maximum_level_m','tank_diameter_m',
            'minimum_volume_m3','pump_power_kw','pump_speed','valve_setting','required_pressure_m','pressure_exponent','measured_pressure_m',
            'source_head_m','static_water_level_m','dynamic_water_level_m','maximum_withdrawal_lps','minimum_operating_flow_lps',
            'nominal_power_kw','start_level_m','stop_level_m','start_pressure_m','stop_pressure_m','meter_current_value',
            'meter_calibrated_value','meter_calibration_factor','meter_minimum_limit','meter_maximum_limit'];
        $spec=[
            'code'=>'required','name'=>'required',
            'node_type'=>['junction','source','reservoir','tank','pompa','valve','meter'],
            'linked_type'=>['','source','reservoir','service_area'],'linked_id'=>'int-null',
            'demand_pattern'=>'text-null','head_pattern'=>'text-null','volume_curve'=>'text-null',
            'mixing_model'=>['mixed','2comp','fifo','lifo'],'pump_curve'=>'text-null','speed_pattern'=>'text-null',
            'valve_type'=>'text-null','meter_parameter'=>'text-null','meter_unit'=>'text-null',
            'status'=>['aktif','tidak_aktif','perawatan'],'description'=>'text-null',
            'demand_category'=>'text-null','pressure_measured_at'=>'datetime-null',
            'demand_pattern_id'=>'int-null','master_source_id'=>'int-null',
            'hydraulic_representation'=>['','RESERVOIR','TANK','WELL_PUMP'],'source_pattern_id'=>'int-null',
            'connected_pump_node_id'=>'int-null','tank_overflow'=>'bool','pump_curve_id'=>'int-null','efficiency_curve_id'=>'int-null',
            'inlet_node_id'=>'int-null','outlet_node_id'=>'int-null','unit_count'=>'int-zero','active_unit_count'=>'int-zero',
            'initial_status'=>['OPEN','CLOSED'],'control_mode'=>['MANUAL','TIME','TANK_LEVEL','PRESSURE'],
            'operating_schedule_id'=>'int-null','meter_target_type'=>['','NODE','LINK','SOURCE','TANK','PUMP'],
            'meter_target_id'=>'int-null','meter_sensor_id'=>'int-null','meter_measured_at'=>'datetime-null','communication_status'=>'text-null',
        ];
        foreach ($numbers as $field) $spec[$field]='number-null';
        return $spec;
    }

    private function bulkRouteFieldSpec(): array
    {
        $numbers=['pipe_length_m','pipe_diameter_mm','roughness_coefficient','minor_loss_coefficient','start_elevation_m','end_elevation_m',
            'elevation_difference_m','max_pipe_capacity_lps','planned_flow_lps','loss_percent','pump_capacity_lps','pump_hours',
            'geometric_length_m','max_velocity_mps','max_unit_headloss_m_per_km','nominal_power_kw','relative_speed',
            'start_level_m','stop_level_m','start_pressure_m','stop_pressure_m','valve_setting'];
        $spec=[
            'route_name'=>'required','origin_type'=>['node'],'origin_id'=>'int-zero',
            'destination_type'=>['node'],'destination_id'=>'int-zero',
            'pipe_type'=>'text-null','check_valve'=>'bool','pump_status'=>'text-null','flow_priority'=>'int-zero',
            'status'=>['aktif','tidak_aktif','perawatan'],'description'=>'text-null','link_type'=>['PIPE','PUMP','VALVE'],
            'use_manual_length'=>'bool','material_code'=>'text-null','installation_year'=>'int-null',
            'leakage_model'=>['NONE','NODE_EMITTER','PIPE_PERCENT','CUSTOM'],'polyline_json'=>'text-null',
            'pump_curve_id'=>'int-null','efficiency_curve_id'=>'int-null','speed_pattern_id'=>'int-null',
            'initial_status'=>['OPEN','CLOSED'],'unit_count'=>'int-zero','active_unit_count'=>'int-zero',
            'control_mode'=>['MANUAL','TIME','TANK_LEVEL','PRESSURE'],'operating_schedule_id'=>'int-null',
            'valve_type'=>'text-null',
        ];
        foreach ($numbers as $field) $spec[$field]='number-null';
        return $spec;
    }

    private function standardRoughness(string $material, string $formula): ?float
    {
        $key=strtoupper(trim($material));
        $standards=[
            'HDPE'=>['H-W'=>150.0,'D-W'=>0.0015,'C-M'=>0.009],
            'PVC'=>['H-W'=>150.0,'D-W'=>0.0015,'C-M'=>0.009],
            'GALVANIS'=>['H-W'=>120.0,'D-W'=>0.15,'C-M'=>0.016],
            'BAJA'=>['H-W'=>130.0,'D-W'=>0.045,'C-M'=>0.012],
            'BETON'=>['H-W'=>130.0,'D-W'=>0.30,'C-M'=>0.013],
        ];
        return $standards[$key][$formula]??null;
    }

    private function pumpCurveInput(): ?array
    {
        $flows=(array)($_POST['pump_curve_flow']??[]);$heads=(array)($_POST['pump_curve_head']??[]);$points=[];$count=max(count($flows),count($heads));
        for($index=0;$index<$count;$index++){$flow=trim((string)($flows[$index]??''));$head=trim((string)($heads[$index]??''));if($flow===''&&$head==='')continue;if($flow===''||$head===''||!is_numeric(str_replace(',','.',$flow))||!is_numeric(str_replace(',','.',$head)))throw new \RuntimeException('Setiap titik kurva pompa wajib memiliki debit Q dan head H yang valid.');$q=(float)str_replace(',','.',$flow);$h=(float)str_replace(',','.',$head);if($q<0||$h<=0)throw new \RuntimeException('Debit kurva tidak boleh negatif dan head harus lebih besar dari 0.');$points[]=['flow_lps'=>$q,'head_m'=>$h];}
        if(!$points)return null;if(count($points)<2)throw new \RuntimeException('Kurva pompa memerlukan minimal dua titik Q–H.');
        for($index=1;$index<count($points);$index++){if($points[$index]['flow_lps']<=$points[$index-1]['flow_lps'])throw new \RuntimeException('Debit Q pada kurva pompa harus meningkat dari titik pertama ke berikutnya.');if($points[$index]['head_m']>=$points[$index-1]['head_m'])throw new \RuntimeException('Head H pada kurva pompa harus menurun saat debit meningkat.');}
        $code=strtoupper(preg_replace('/[^A-Za-z0-9_-]+/','-',trim((string)($_POST['pump_curve_code']??''))));return ['code'=>trim($code,'-'),'name'=>trim((string)($_POST['pump_curve_name']??'')),'points'=>$points];
    }

    private function number(string $field, bool $allowZero = false): float
    {
        $value = str_replace(',','.',trim((string)($_POST[$field]??'')));
        if ($value==='' || !is_numeric($value)) return 0;
        $number = (float)$value;
        return $allowZero ? $number : max(0,$number);
    }

    private function nullableNumber(string $field): ?float
    {
        $value=str_replace(',','.',trim((string)($_POST[$field]??'')));
        return $value!=='' && is_numeric($value) ? (float)$value : null;
    }

    private function validCurve(int $id, string $type): bool
    {
        $json=Database::query("SELECT points_json FROM hydraulic_curves WHERE id=? AND curve_type=? AND status='aktif' AND deleted_at IS NULL",[$id,$type])->fetchColumn();
        if (!$json) return false;
        $points=json_decode((string)$json,true);
        if (!is_array($points) || count($points)<2) return false;
        $lastFlow=null;
        foreach ($points as $point) {
            if (!is_array($point) || !is_numeric($point['flow_lps']??null) || !is_numeric($point['head_m']??null)) return false;
            $flow=(float)$point['flow_lps'];$head=(float)$point['head_m'];
            if ($head<0 || ($lastFlow!==null && $flow<=$lastFlow)) return false;
            $lastFlow=$flow;
        }
        return true;
    }
}

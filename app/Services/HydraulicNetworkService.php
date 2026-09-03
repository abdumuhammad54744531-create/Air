<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Env;
use RuntimeException;

final class HydraulicNetworkService
{
    public function loadModel(?int $projectId=null): array
    {
        ServiceAreaSchemaService::ensureElevationColumn();
        if (!$projectId) $projectId=(int)(Database::query("SELECT id FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,id LIMIT 1")->fetchColumn()?:0);
        if (!$projectId) throw new RuntimeException('Proyek jaringan tidak ditemukan.');
        $positionRows=Database::query("SELECT node_type,entity_id,position_x,position_y FROM distribution_node_positions WHERE project_id=?",[$projectId])->fetchAll();
        $positions=[];
        foreach ($positionRows as $position) $positions[$position['node_type'].':'.$position['entity_id']]=[(float)$position['position_x'],(float)$position['position_y']];
        $networkRows=array_values(array_filter(
            Database::query("SELECT * FROM distribution_networks WHERE project_id=? AND deleted_at IS NULL",[$projectId])->fetchAll(),
            fn(array $row)=>$row['origin_type']==='node' && $row['destination_type']==='node'
        ));
        $connectedNodeKeys=[];
        foreach ($networkRows as $row) {
            $connectedNodeKeys['node:'.(int)$row['origin_id']]=true;
            $connectedNodeKeys['node:'.(int)$row['destination_id']]=true;
        }

        $nodes=[];$engineIds=[];
        $append=function(array $row,string $entityType,string $nodeType,array $extra=[]) use (&$nodes,&$engineIds,$positions): void {
            $key=$entityType.':'.$row['id'];$base=$this->engineId((string)$row['code']);$engineId=$base;$suffix=2;
            while (isset($engineIds[$engineId])) $engineId=substr($base,0,27).'-'.$suffix++;
            $engineIds[$engineId]=$key;
            [$x,$y]=$positions[$key]??[50,50];
            $nodes[$key]=array_merge([
                'key'=>$key,'entity_type'=>$entityType,'id'=>(int)$row['id'],'engine_id'=>$engineId,
                'code'=>$row['code'],'name'=>$row['name'],'node_type'=>$nodeType,'elevation_m'=>$row['elevation_m']!==null?(float)$row['elevation_m']:null,
                'status'=>$row['status']??'aktif','x'=>(float)$x,'y'=>(float)$y,
            ],$extra);
        };

        foreach (Database::query("SELECT * FROM distribution_nodes WHERE project_id=? AND deleted_at IS NULL",[$projectId])->fetchAll() as $row) {
            if (!isset($connectedNodeKeys['node:'.$row['id']])) continue;
            $row=$this->synchronizeLinkedMaster($row);
            $append($row,'node',(string)$row['node_type'],[
                'base_demand_lps'=>(float)$row['base_demand_lps'],'demand_pattern_id'=>$row['demand_pattern_id'],
                'emitter_coefficient'=>(float)$row['emitter_coefficient'],'initial_quality'=>(float)$row['initial_quality'],
                'source_quality'=>(float)$row['source_quality'],'head_m'=>(float)($row['source_head_m']??$row['total_head_m']),
                'total_head_m'=>(float)$row['total_head_m'],'head_pattern'=>$row['head_pattern'],
                'minimum_pressure_m'=>$row['minimum_pressure_m']!==null?(float)$row['minimum_pressure_m']:null,
                'required_pressure_m'=>$row['required_pressure_m']!==null?(float)$row['required_pressure_m']:null,
                'maximum_pressure_m'=>$row['maximum_pressure_m']!==null?(float)$row['maximum_pressure_m']:null,
                'pressure_exponent'=>(float)$row['pressure_exponent'],'measured_pressure_m'=>$row['measured_pressure_m']!==null?(float)$row['measured_pressure_m']:null,
                'initial_level_m'=>(float)$row['initial_level_m'],'minimum_level_m'=>(float)$row['minimum_level_m'],
                'maximum_level_m'=>(float)$row['maximum_level_m'],'tank_diameter_m'=>(float)$row['tank_diameter_m'],
                'minimum_volume_m3'=>(float)$row['minimum_volume_m3'],'volume_curve'=>$row['volume_curve'],
                'tank_overflow'=>(int)$row['tank_overflow'],'hydraulic_representation'=>$row['hydraulic_representation'],
                'master_source_id'=>$row['master_source_id'],'maximum_withdrawal_lps'=>$row['maximum_withdrawal_lps']!==null?(float)$row['maximum_withdrawal_lps']:null,
                'minimum_operating_flow_lps'=>$row['minimum_operating_flow_lps']!==null?(float)$row['minimum_operating_flow_lps']:null,
                'connected_pump_node_id'=>$row['connected_pump_node_id'],'pump_curve_id'=>$row['pump_curve_id'],
                'nominal_power_kw'=>$row['nominal_power_kw']!==null?(float)$row['nominal_power_kw']:(float)$row['pump_power_kw'],
                'pump_speed'=>(float)$row['pump_speed'],'inlet_node_id'=>$row['inlet_node_id'],'outlet_node_id'=>$row['outlet_node_id'],
                'unit_count'=>(int)$row['unit_count'],'active_unit_count'=>(int)$row['active_unit_count'],
                'meter_target_type'=>$row['meter_target_type'],'meter_target_id'=>$row['meter_target_id'],
                'meter_parameter'=>$row['meter_parameter'],'meter_unit'=>$row['meter_unit'],'meter_sensor_id'=>$row['meter_sensor_id'],
            ]);
        }

        $links=[];
        foreach ($networkRows as $row) {
            $originKey=$row['origin_type'].':'.$row['origin_id'];$destinationKey=$row['destination_type'].':'.$row['destination_id'];
            $links[]=$row+[
                'key'=>'link:'.$row['id'],'engine_id'=>$this->engineId('L-'.$row['id'].'-'.$row['route_name']),
                'origin_key'=>$originKey,'destination_key'=>$destinationKey,
                'origin_engine_id'=>$nodes[$originKey]['engine_id']??null,'destination_engine_id'=>$nodes[$destinationKey]['engine_id']??null,
            ];
        }

        $patterns=[];
        foreach (Database::query("SELECT * FROM demand_patterns WHERE status='aktif' AND deleted_at IS NULL")->fetchAll() as $row) {
            $patterns[(int)$row['id']]=$row+['engine_id'=>$this->engineId((string)$row['code']),'multipliers'=>json_decode((string)$row['multipliers_json'],true)?:[]];
        }
        $curves=[];
        foreach (Database::query("SELECT * FROM hydraulic_curves WHERE status='aktif' AND deleted_at IS NULL")->fetchAll() as $row) {
            $curves[(int)$row['id']]=$row+['engine_id'=>$this->engineId((string)$row['code']),'points'=>json_decode((string)$row['points_json'],true)?:[]];
        }
        return ['project_id'=>$projectId,'nodes'=>$nodes,'links'=>$links,'patterns'=>$patterns,'curves'=>$curves];
    }

    /** Nilai master terbaru selalu digunakan walaupun titik ditautkan sebelum fitur sinkronisasi tersedia. */
    private function synchronizeLinkedMaster(array $node): array
    {
        $type=(string)($node['linked_type']??'');$id=(int)($node['linked_id']??0);
        if (!$id) return $node;
        if ($type==='source') {
            $master=Database::query("SELECT name,elevation_m,min_flow_lps,max_flow_lps,status FROM water_sources WHERE id=? AND deleted_at IS NULL",[$id])->fetch();
            if ($master) return array_replace($node,[
                'name'=>$master['name'],'node_type'=>'source','status'=>$master['status'],'elevation_m'=>$master['elevation_m'],
                'total_head_m'=>$master['elevation_m'],'source_head_m'=>$master['elevation_m'],'hydraulic_representation'=>'RESERVOIR',
                'minimum_operating_flow_lps'=>$master['min_flow_lps'],'maximum_withdrawal_lps'=>$master['max_flow_lps'],'base_demand_lps'=>0,
            ]);
        }
        if ($type==='reservoir') {
            $master=Database::query("SELECT name,elevation_m,length_m,width_m,height_m,initial_water_level_m,minimum_operational_m3,status FROM reservoirs WHERE id=? AND deleted_at IS NULL",[$id])->fetch();
            if ($master) {
                $area=max(.01,(float)$master['length_m']*(float)$master['width_m']);
                return array_replace($node,[
                    'name'=>$master['name'],'node_type'=>'tank','status'=>$master['status'],'elevation_m'=>$master['elevation_m'],
                    'initial_level_m'=>$master['initial_water_level_m'],'minimum_level_m'=>0,'maximum_level_m'=>$master['height_m'],
                    'tank_diameter_m'=>2*sqrt($area/M_PI),'minimum_volume_m3'=>$master['minimum_operational_m3'],
                ]);
            }
        }
        if ($type==='service_area') {
            $master=Database::query("SELECT name,elevation_m,peak_hour_demand_lps FROM service_areas WHERE id=? AND deleted_at IS NULL",[$id])->fetch();
            if ($master) return array_replace($node,[
                'name'=>$master['name'],'node_type'=>'junction','status'=>'aktif','elevation_m'=>$master['elevation_m'],
                'base_demand_lps'=>$master['peak_hour_demand_lps'],
            ]);
        }
        return $node;
    }

    public function validate(array $model): array
    {
        $items=[];$degrees=array_fill_keys(array_keys($model['nodes']),0);$sourceKeys=[];
        $add=function(string $severity,string $object,string $field,string $message,?string $focusKey=null) use (&$items): void {
            $items[]=['severity'=>$severity,'object'=>$object,'field'=>$field,'message'=>$message,'focus_key'=>$focusKey];
        };
        $codes=[];
        foreach ($model['nodes'] as $key=>$node) {
            if (isset($codes[$node['engine_id']])) $add('error',$node['name'],'code','Kode node EPANET tidak unik setelah normalisasi.',$key);
            $codes[$node['engine_id']]=$key;
            if ($node['node_type']==='junction') {
                if ($node['elevation_m']===null) $add('error',$node['name'],'elevation_m','Junction belum mempunyai elevasi.',$key);
                if (($node['base_demand_lps']??0)<0) $add('error',$node['name'],'base_demand_lps','Base demand tidak boleh negatif.',$key);
                if (($node['demand_pattern_id']??null) && !isset($model['patterns'][(int)$node['demand_pattern_id']])) $add('error',$node['name'],'demand_pattern_id','Demand pattern tidak tersedia.',$key);
            } elseif (in_array($node['node_type'],['source','reservoir'],true)) {
                $sourceKeys[$key]=true;
                $sourceHead=$node['head_m']??$node['total_head_m']??$node['elevation_m']??null;
                if ($sourceHead===null || !is_numeric($sourceHead)) $add('error',$node['name'],'source_head_m','Reservoir/sumber belum mempunyai hydraulic head. Nilai 0 m boleh digunakan sebagai datum.',$key);
            } elseif ($node['node_type']==='tank') {
                $sourceKeys[$key]=true;
                if ($node['elevation_m']===null || ($node['tank_diameter_m']??0)<=0 || ($node['maximum_level_m']??0)<=($node['minimum_level_m']??0)) $add('error',$node['name'],'tank','Data elevasi, diameter, atau level tank belum valid.',$key);
            } elseif ($node['node_type']==='pompa') {
                $add('warning',$node['name'],'node_type','Pompa lama masih berupa node. Untuk model EPANET final gunakan link PUMP.',$key);
            } elseif ($node['node_type']==='meter' && !($node['meter_target_type']??null)) {
                $add('error',$node['name'],'meter_target_type','Meter belum mempunyai target pengukuran.',$key);
            }
            if ($node['entity_type']==='source') {
                $sourceKeys[$key]=true;
                if ($node['status']!=='aktif') $add('warning',$node['name'],'status','Sumber tidak aktif.',$key);
                if (($node['head_m']??null)===null) $add('error',$node['name'],'elevation_m','Sumber belum mempunyai elevasi/head.',$key);
            }
        }
        foreach ($model['links'] as $link) {
            $name=(string)$link['route_name'];$focus='link:'.$link['id'];
            if (!$link['origin_engine_id'] || !$link['destination_engine_id']) {$add('error',$name,'endpoint','Titik asal atau tujuan tidak tersedia.',$focus);continue;}
            if ($link['origin_key']===$link['destination_key']) $add('error',$name,'endpoint','Titik asal dan tujuan tidak boleh sama.',$focus);
            $degrees[$link['origin_key']]++;$degrees[$link['destination_key']]++;
            $type=$link['link_type']?:'PIPE';
            if ($type==='PIPE') {
                if ((float)$link['pipe_length_m']<=0) $add('error',$name,'pipe_length_m','Panjang pipa harus lebih besar dari 0.',$focus);
                if ((float)$link['pipe_diameter_mm']<=0) $add('error',$name,'pipe_diameter_mm','Diameter pipa harus lebih besar dari 0.',$focus);
                if ((float)$link['roughness_coefficient']<=0) $add('error',$name,'roughness_coefficient','Koefisien kekasaran harus lebih besar dari 0.',$focus);
            } elseif ($type==='PUMP') {
                if (!(int)$link['pump_curve_id'] && (float)$link['nominal_power_kw']<=0) $add('error',$name,'pump_curve_id','Pompa memerlukan kurva bertitik atau daya nominal.',$focus);
                if ((int)$link['pump_curve_id'] && !isset($model['curves'][(int)$link['pump_curve_id']])) $add('error',$name,'pump_curve_id','Kurva pompa tidak ditemukan.',$focus);
                if ((int)($link['speed_pattern_id']??0) && !isset($model['patterns'][(int)$link['speed_pattern_id']])) $add('error',$name,'speed_pattern_id','Speed pattern pompa tidak ditemukan.',$focus);
                if ((float)$link['relative_speed']<=0 || (int)$link['active_unit_count']>(int)$link['unit_count']) $add('error',$name,'pump','Speed atau jumlah unit aktif pompa tidak valid.',$focus);
            } elseif ($type==='VALVE') {
                if (!in_array($link['valve_type'],['PRV','PSV','PBV','FCV','TCV','GPV'],true)) $add('error',$name,'valve_type','Jenis valve tidak valid.',$focus);
                if ((float)$link['pipe_diameter_mm']<=0 || $link['valve_setting']===null) $add('error',$name,'valve_setting','Diameter dan setting valve wajib diisi.',$focus);
            }
        }
        foreach ($degrees as $key=>$degree) {
            $node=$model['nodes'][$key];
            if ($degree===0) $add($node['entity_type']==='source'?'warning':'error',$node['name'],'connection','Node tidak tersambung ke link mana pun.',$key);
            if ($node['node_type']==='meter' && $degree>0) $add('warning',$node['name'],'meter_target_type','Meter menjadi endpoint link; pastikan meter memang mewakili node hidraulika.',$key);
        }
        $sourceCount=count($sourceKeys);
        if ($sourceCount===0) $add('error','Jaringan','source','Tidak ada reservoir, tank, atau sumber head untuk memasok jaringan.');
        $errors=count(array_filter($items,fn($item)=>$item['severity']==='error'));
        $warnings=count(array_filter($items,fn($item)=>$item['severity']==='warning'));
        if (!$items) $add('info','Jaringan','validation','Seluruh pemeriksaan dasar jaringan lulus.');
        return ['valid'=>$errors===0,'errors'=>$errors,'warnings'=>$warnings,'info'=>count($items)-$errors-$warnings,'items'=>$items,'counts'=>['nodes'=>count($model['nodes']),'links'=>count($model['links']),'sources'=>$sourceCount]];
    }

    public function buildPayload(array $model, array $requestedOptions=[]): array
    {
        $options=$this->options($requestedOptions);$payload=['options'=>$options,'nodes'=>[],'reservoirs'=>[],'tanks'=>[],'pipes'=>[],'pumps'=>[],'valves'=>[],'patterns'=>[],'curves'=>[],'controls'=>[],'coordinates'=>[]];
        $globalPatternId=$options['analysisType']==='EXTENDED'&&$options['applyGlobalDemandPattern']?'SIM24GLOBAL':null;
        foreach ($model['nodes'] as $node) {
            $coordinate=['id'=>$node['engine_id'],'x'=>$node['x']*10,'y'=>(100-$node['y'])*10];$payload['coordinates'][]=$coordinate;
            if ($node['node_type']==='tank') {
                $payload['tanks'][]=['id'=>$node['engine_id'],'key'=>$node['key'],'elevationM'=>$node['elevation_m']??0,'initialLevelM'=>$node['initial_level_m']??0,'minimumLevelM'=>$node['minimum_level_m']??0,'maximumLevelM'=>$node['maximum_level_m']??0,'diameterM'=>$node['tank_diameter_m']??1,'minimumVolumeM3'=>$node['minimum_volume_m3']??0,'overflow'=>(bool)($node['tank_overflow']??false)];
            } elseif (in_array($node['node_type'],['source','reservoir'],true) || $node['entity_type']==='source') {
                $payload['reservoirs'][]=['id'=>$node['engine_id'],'key'=>$node['key'],'headM'=>$node['head_m']??$node['total_head_m']??$node['elevation_m']??0,'patternId'=>null];
            } else {
                $patternId=$globalPatternId?:($node['demand_pattern_id']&&isset($model['patterns'][(int)$node['demand_pattern_id']])?$model['patterns'][(int)$node['demand_pattern_id']]['engine_id']:null);
                $payload['nodes'][]=['id'=>$node['engine_id'],'key'=>$node['key'],'type'=>'JUNCTION','elevationM'=>$node['elevation_m']??0,'baseDemandLps'=>($node['base_demand_lps']??0)*$options['demandMultiplier'],'patternId'=>$patternId,'emitterCoefficient'=>$node['emitter_coefficient']??0];
            }
        }
        foreach ($model['links'] as $link) {
            if (!$link['origin_engine_id'] || !$link['destination_engine_id']) continue;
            $base=['id'=>$link['engine_id'],'key'=>$link['key'],'fromNode'=>$link['origin_engine_id'],'toNode'=>$link['destination_engine_id']];
            $type=$link['link_type']?:'PIPE';
            if ($type==='PUMP') {
                $curve=(int)$link['pump_curve_id']&&isset($model['curves'][(int)$link['pump_curve_id']])?$model['curves'][(int)$link['pump_curve_id']]['engine_id']:null;
                $speedPattern=(int)($link['speed_pattern_id']??0)&&isset($model['patterns'][(int)$link['speed_pattern_id']])?$model['patterns'][(int)$link['speed_pattern_id']]['engine_id']:null;
                $payload['pumps'][]=$base+['curveId'=>$curve,'powerKw'=>$link['nominal_power_kw']!==null?(float)$link['nominal_power_kw']:null,'speed'=>(float)$link['relative_speed'],'speedPatternId'=>$speedPattern,'status'=>$link['initial_status'],'unitCount'=>(int)($link['unit_count']??1),'activeUnitCount'=>(int)($link['active_unit_count']??1),'controlMode'=>$link['control_mode']??'MANUAL'];
                if(($link['control_mode']??'MANUAL')==='TANK_LEVEL'){$tankKey=$this->firstDownstreamTankKey($model,(string)$link['destination_key']);$tank=$tankKey?($model['nodes'][$tankKey]??null):null;$start=$link['start_level_m']??null;$stop=$link['stop_level_m']??null;if($tank&&$start!==null&&$stop!==null&&(float)$stop>(float)$start){$payload['controls'][]=['linkId'=>$link['engine_id'],'status'=>'CLOSED','nodeId'=>$tank['engine_id'],'relation'=>'ABOVE','levelM'=>(float)$stop];$payload['controls'][]=['linkId'=>$link['engine_id'],'status'=>'OPEN','nodeId'=>$tank['engine_id'],'relation'=>'BELOW','levelM'=>(float)$start];}}
            } elseif ($type==='VALVE') {
                $payload['valves'][]=$base+['diameterMm'=>(float)$link['pipe_diameter_mm'],'valveType'=>$link['valve_type'],'setting'=>(float)$link['valve_setting'],'minorLoss'=>(float)$link['minor_loss_coefficient'],'status'=>$link['initial_status']];
            } else {
                $payload['pipes'][]=$base+[
                    'lengthM'=>(float)$link['pipe_length_m'],
                    'diameterMm'=>(float)$link['pipe_diameter_mm'],
                    'roughness'=>$this->roughnessForMaterial(
                        (string)($link['pipe_type']?:$link['material_code']),
                        $options['headlossFormula'],
                        (float)$link['roughness_coefficient']
                    ),
                    'minorLoss'=>(float)$link['minor_loss_coefficient'],
                    'status'=>$link['check_valve']?'CV':($link['initial_status']?:($link['status']==='aktif'?'OPEN':'CLOSED')),
                    'checkValve'=>(bool)$link['check_valve']
                ];
            }
        }
        foreach ($model['patterns'] as $pattern) $payload['patterns'][]=['id'=>$pattern['engine_id'],'multipliers'=>array_map('floatval',$pattern['multipliers'])];
        if($globalPatternId)$payload['patterns'][]=['id'=>$globalPatternId,'multipliers'=>$options['globalDemandPattern']];
        foreach ($model['curves'] as $curve) $payload['curves'][]=['id'=>$curve['engine_id'],'type'=>$curve['curve_type'],'points'=>$curve['points']];
        return $payload;
    }

    public function toInp(array $payload): string
    {
        $line=[];$line[]='[TITLE]';$line[]='SIMMA Hydraulic Analysis';$line[]='';
        $line[]='[JUNCTIONS]';$line[]=';ID Elevation Demand Pattern';
        foreach ($payload['nodes'] as $node) $line[]=implode("\t",[$node['id'],$this->n($node['elevationM']),$this->n($node['baseDemandLps']),$node['patternId']??'']);
        $line[]='';$line[]='[RESERVOIRS]';$line[]=';ID Head Pattern';
        foreach ($payload['reservoirs'] as $node) $line[]=implode("\t",[$node['id'],$this->n($node['headM']),$node['patternId']??'']);
        $line[]='';$line[]='[TANKS]';$line[]=';ID Elev Init Min Max Diam MinVol VolCurve Overflow';
        foreach ($payload['tanks'] as $node) $line[]=implode("\t",[$node['id'],$this->n($node['elevationM']),$this->n($node['initialLevelM']),$this->n($node['minimumLevelM']),$this->n($node['maximumLevelM']),$this->n($node['diameterM']),$this->n($node['minimumVolumeM3']),'*',$node['overflow']?'YES':'NO']);
        $line[]='';$line[]='[PIPES]';$line[]=';ID Node1 Node2 Length Diameter Roughness MinorLoss Status';
        foreach ($payload['pipes'] as $link) $line[]=implode("\t",[$link['id'],$link['fromNode'],$link['toNode'],$this->n($link['lengthM']),$this->n($link['diameterMm']),$this->n($link['roughness']),$this->n($link['minorLoss']),$link['status']]);
        $line[]='';$line[]='[PUMPS]';$line[]=';ID Node1 Node2 Parameters';
        foreach ($payload['pumps'] as $link) {
            $parameter=$link['curveId']?'HEAD '.$link['curveId']:'POWER '.$this->n($link['powerKw']??0);
            if (abs($link['speed']-1)>.00001) $parameter.=' SPEED '.$this->n($link['speed']);
            if (!empty($link['speedPatternId'])) $parameter.=' PATTERN '.$link['speedPatternId'];
            $line[]=implode("\t",[$link['id'],$link['fromNode'],$link['toNode'],$parameter]);
        }
        $line[]='';$line[]='[VALVES]';$line[]=';ID Node1 Node2 Diameter Type Setting MinorLoss';
        foreach ($payload['valves'] as $link) $line[]=implode("\t",[$link['id'],$link['fromNode'],$link['toNode'],$this->n($link['diameterMm']),$link['valveType'],$this->n($link['setting']),$this->n($link['minorLoss'])]);
        $line[]='';$line[]='[STATUS]';
        foreach ($payload['pumps'] as $link) if (($link['status']??'OPEN')!=='OPEN') $line[]=$link['id']."\t".$link['status'];
        foreach ($payload['valves'] as $link) if (($link['status']??'OPEN')!=='OPEN') $line[]=$link['id']."\t".$link['status'];
        $line[]='';$line[]='[CONTROLS]';
        foreach($payload['controls']??[] as $control)$line[]='LINK '.$control['linkId'].' '.$control['status'].' IF NODE '.$control['nodeId'].' '.$control['relation'].' '.$this->n($control['levelM']);
        $line[]='';$line[]='[PATTERNS]';
        foreach ($payload['patterns'] as $pattern) foreach (array_chunk($pattern['multipliers'],6) as $values) $line[]=$pattern['id']."\t".implode("\t",array_map(fn($value)=>$this->n($value),$values));
        $line[]='';$line[]='[CURVES]';
        foreach ($payload['curves'] as $curve) foreach ($curve['points'] as $point) {
            $x=$point['flow_lps']??$point['x']??0;$y=$point['head_m']??$point['efficiency_percent']??$point['y']??0;
            $line[]=$curve['id']."\t".$this->n($x)."\t".$this->n($y);
        }
        $o=$payload['options'];$line[]='';$line[]='[OPTIONS]';$line[]='UNITS '.$o['flowUnit'];$line[]='HEADLOSS '.$o['headlossFormula'];$line[]='DEMAND MODEL '.$o['demandModel'];
        if ($o['demandModel']==='PDA') {$line[]='MINIMUM PRESSURE '.$this->n($o['minimumPressureM']);$line[]='REQUIRED PRESSURE '.$this->n($o['requiredPressureM']);$line[]='PRESSURE EXPONENT '.$this->n($o['pressureExponent']);}
        $line[]='';$line[]='[TIMES]';$line[]='DURATION '.$o['duration'];$line[]='HYDRAULIC TIMESTEP '.$o['hydraulicTimestep'];$line[]='REPORT TIMESTEP '.$o['reportTimestep'];$line[]='PATTERN TIMESTEP '.$o['patternTimestep'];
        $line[]='';$line[]='[REPORT]';$line[]='STATUS YES';$line[]='SUMMARY YES';$line[]='NODES ALL';$line[]='LINKS ALL';
        $line[]='';$line[]='[COORDINATES]';foreach ($payload['coordinates'] as $point) $line[]=implode("\t",[$point['id'],$this->n($point['x']),$this->n($point['y'])]);
        $line[]='';$line[]='[END]';return implode(PHP_EOL,$line).PHP_EOL;
    }

    private function firstDownstreamTankKey(array $model,string $startKey): ?string
    {
        $outgoing=[];foreach($model['links']??[] as $link){if(($link['status']??'aktif')!=='aktif'||($link['initial_status']??'OPEN')==='CLOSED')continue;$outgoing[(string)$link['origin_key']][]=(string)$link['destination_key'];}
        $queue=[$startKey];$visited=[];while($queue){$key=array_shift($queue);if(isset($visited[$key]))continue;$visited[$key]=true;if(($model['nodes'][$key]['node_type']??'')==='tank')return $key;foreach($outgoing[$key]??[] as $next)if(!isset($visited[$next]))$queue[]=$next;}return null;
    }

    public function run(array $payload): array
    {
        $engine=$this->epanetEngine();
        $directory=App::ROOT.'/storage/hydraulic/'.date('Ymd');
        if (!is_dir($directory) && !mkdir($directory,0775,true) && !is_dir($directory)) throw new RuntimeException('Folder kerja hidraulika tidak dapat dibuat.');
        $token=date('His').'-'.bin2hex(random_bytes(5));$input=$directory.'/'.$token.'.inp';$report=$directory.'/'.$token.'.rpt';$binary=$directory.'/'.$token.'.bin';
        file_put_contents($input,$this->toInp($payload),LOCK_EX);
        $command=[$engine,$input,$report,$binary];
        if (PHP_OS_FAMILY!=='Windows') {
            $libraryPath=(string)Env::get('EPANET_LIBRARY_PATH','/usr/local/lib');
            $command=['/usr/bin/env','LD_LIBRARY_PATH='.$libraryPath,$engine,$input,$report,$binary];
        }
        // EPANET 2.3 membuat file hidraulika sementara di working directory.
        // Root aplikasi production bersifat read-only bagi www-data, sehingga
        // proses harus berjalan di folder kerja hidraulika yang memang writable.
        $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$directory);
        if (!is_resource($process)) throw new RuntimeException('Engine EPANET tidak dapat dijalankan.');
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($process);
        $reportText=is_file($report)?file_get_contents($report):'';
        $engineErrors=[];if (preg_match_all('/\\*\\*\\*\\s*(Error[^\\r\\n]*)/i',$reportText."\n".$stderr,$matches)) $engineErrors=array_values(array_unique(array_map('trim',$matches[1])));
        return ['success'=>$exitCode===0&&!$engineErrors,'exit_code'=>$exitCode,'engine_errors'=>$engineErrors,'stdout'=>trim($stdout),'stderr'=>trim($stderr),'report_excerpt'=>$this->reportExcerpt($reportText),'results'=>$this->parseResults($reportText,$payload),'files'=>['input'=>$input,'report'=>$report,'binary'=>is_file($binary)?$binary:null]];
    }

    private function epanetEngine(): string
    {
        $configured=trim((string)Env::get('EPANET_BIN',''));
        $candidates=$configured!==''?[$configured]:(PHP_OS_FAMILY==='Windows'
            ? [App::ROOT.'/tools/epanet/runepanet.exe']
            : [App::ROOT.'/tools/epanet/runepanet','/usr/local/bin/runepanet','/usr/bin/runepanet']);
        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) continue;
            if (PHP_OS_FAMILY!=='Windows' && !is_executable($candidate)) {
                throw new RuntimeException('Engine EPANET Linux ditemukan tetapi tidak memiliki izin eksekusi: '.$candidate);
            }
            return $candidate;
        }
        $expected=PHP_OS_FAMILY==='Windows'?'runepanet.exe':'runepanet dan libepanet2.so';
        throw new RuntimeException('Engine EPANET belum tersedia untuk '.PHP_OS_FAMILY.'. Diperlukan '.$expected.'.');
    }

    private function options(array $input): array
    {
        $requestedFormula=(string)($input['headloss_formula']??'H-W');
        $requestedModel=(string)($input['demand_model']??'PDA');
        $formula=in_array($requestedFormula,['H-W','D-W','C-M'],true)?$requestedFormula:'H-W';
        $model=in_array($requestedModel,['DDA','PDA'],true)?$requestedModel:'PDA';
        $type=($input['analysis_type']??'STEADY')==='EXTENDED'?'EXTENDED':'STEADY';
        $defaultPattern=[.55,.50,.48,.50,.60,.85,1.20,1.35,1.15,1.00,.90,.95,1.05,1.10,1.00,.95,1.05,1.25,1.45,1.40,1.15,.90,.75,.65];$pattern=array_values(array_map(fn($value)=>max(0,(float)$value),(array)($input['hourly_pattern']??[])));if(count($pattern)!==24)$pattern=$defaultPattern;
        return ['flowUnit'=>'LPS','headlossFormula'=>$formula,'demandModel'=>$model,'minimumPressureM'=>(float)($input['minimum_pressure_m']??5),'requiredPressureM'=>(float)($input['required_pressure_m']??15),'pressureExponent'=>(float)($input['pressure_exponent']??.5),'demandMultiplier'=>max(0,(float)($input['demand_multiplier']??1)),'analysisType'=>$type,'duration'=>$type==='STEADY'?'0:00':$this->timeValue($input['duration']??'24:00'),'hydraulicTimestep'=>$this->timeValue($input['hydraulic_timestep']??'1:00'),'reportTimestep'=>$this->timeValue($input['report_timestep']??'1:00'),'patternTimestep'=>$this->timeValue($input['pattern_timestep']??'1:00'),'applyGlobalDemandPattern'=>isset($input['apply_global_pattern']),'globalDemandPattern'=>$pattern];
    }

    /**
     * EPANET memakai arti koefisien yang berbeda untuk setiap rumus:
     * H-W = faktor C, D-W = kekasaran absolut (mm pada satuan SI),
     * C-M = koefisien Manning n. Material "Lainnya" selalu memakai nilai manual.
     */
    private function roughnessForMaterial(string $material, string $formula, float $manual): float
    {
        $key=strtoupper(trim($material));
        $aliases=['GALVANIZED'=>'GALVANIS','STEEL'=>'BAJA','CONCRETE'=>'BETON'];
        $key=$aliases[$key]??$key;
        $standards=[
            'HDPE'=>['H-W'=>150.0,'D-W'=>0.0015,'C-M'=>0.009],
            'PVC'=>['H-W'=>150.0,'D-W'=>0.0015,'C-M'=>0.009],
            'GALVANIS'=>['H-W'=>120.0,'D-W'=>0.15,'C-M'=>0.016],
            'BAJA'=>['H-W'=>130.0,'D-W'=>0.045,'C-M'=>0.012],
            'BETON'=>['H-W'=>130.0,'D-W'=>0.30,'C-M'=>0.013],
        ];
        return $standards[$key][$formula]??max(0.000001,$manual);
    }

    private function engineId(string $value): string
    {
        $value=preg_replace('/[^A-Za-z0-9_.-]+/','-',trim($value))?:'OBJ';return substr(trim($value,'-'),0,31)?:'OBJ';
    }
    private function n(mixed $value): string { return rtrim(rtrim(number_format((float)$value,6,'.',''),'0'),'.') ?: '0'; }
    private function timeValue(mixed $value): string { return preg_match('/^\\d{1,3}:\\d{2}$/',(string)$value)?(string)$value:'1:00'; }
    private function reportExcerpt(string $report): string
    {
        $lines=preg_split('/\\R/',$report)?:[];return implode("\n",array_slice($lines,max(0,count($lines)-80)));
    }

    private function parseResults(string $report,array $payload): array
    {
        $nodeMeta=[];$linkMeta=[];$patternMeta=[];
        foreach ($payload['patterns'] as $pattern) $patternMeta[$pattern['id']]=array_values(array_map('floatval',$pattern['multipliers']??[]));
        foreach ($payload['nodes'] as $node) $nodeMeta[$node['id']]=$node+['_result_type'=>'junction'];
        foreach ($payload['reservoirs'] as $node) $nodeMeta[$node['id']]=$node+['_result_type'=>'source'];
        foreach ($payload['tanks'] as $node) $nodeMeta[$node['id']]=$node+['_result_type'=>'tank'];
        foreach ($payload['pipes'] as $link) $linkMeta[$link['id']]=$link+['_result_type'=>'pipe'];
        foreach ($payload['pumps'] as $link) $linkMeta[$link['id']]=$link+['_result_type'=>'pump'];
        foreach ($payload['valves'] as $link) $linkMeta[$link['id']]=$link+['_result_type'=>'valve'];
        $periods=[];$section=null;$time='0:00:00';$hasRows=false;
        foreach (preg_split('/\\R/',$report)?:[] as $line) {
            if (preg_match('/^\\s*Node Results(?: at\\s+(.+?))?:\\s*$/i',$line,$match)) {
                $section='nodes';$time=trim($match[1]??'0:00:00');$periods[$time]??=['time'=>$time,'nodes'=>[],'links'=>[]];$hasRows=false;continue;
            }
            if (preg_match('/^\\s*Link Results(?: at\\s+(.+?))?:\\s*$/i',$line,$match)) {
                $section='links';$time=trim($match[1]??$time);$periods[$time]??=['time'=>$time,'nodes'=>[],'links'=>[]];$hasRows=false;continue;
            }
            if (!$section) continue;
            if (preg_match('/^\\s*(\\S+)\\s+(-?\\d+(?:\\.\\d+)?)\\s+(-?\\d+(?:\\.\\d+)?)\\s+(-?\\d+(?:\\.\\d+)?)/',$line,$match)) {
                $id=$match[1];
                if ($section==='nodes' && isset($nodeMeta[$id])) {
                    $meta=$nodeMeta[$id];$demand=(float)$match[2];$requested=(float)($meta['baseDemandLps']??0);$pattern=$patternMeta[$meta['patternId']??'']??[];
                    if($pattern){$minutes=0;if(preg_match('/(\d+):(\d+)/',$time,$clock))$minutes=(int)$clock[1]*60+(int)$clock[2];$step=60;if(preg_match('/^(\d+):(\d+)/',(string)($payload['options']['patternTimestep']??'1:00'),$tick))$step=max(1,(int)$tick[1]*60+(int)$tick[2]);$requested*=($pattern[(int)floor($minutes/$step)%count($pattern)]??1);}
                    $delivered=max(0,$demand);$deficit=max(0,$requested-$delivered);$resultType=$meta['_result_type']??'junction';
                    $periods[$time]['nodes'][$meta['key']]=[
                        'engine_id'=>$id,'demand_lps'=>$demand,'requested_demand_lps'=>$requested,'delivered_demand_lps'=>$delivered,
                        'demand_deficit_lps'=>$deficit,'fulfillment_percent'=>$requested>0?min(100,$delivered/$requested*100):100,
                        'head_m'=>(float)$match[3],'pressure_m'=>(float)$match[4],'quality'=>null,
                        'status'=>$resultType==='source'?'batas_sumber':($resultType==='tank'?'level_tank':((float)$match[4]<0?'tekanan_negatif':((float)$match[4]<($payload['options']['minimumPressureM']??5)?'tekanan_rendah':'memenuhi'))),
                    ];$hasRows=true;
                } elseif ($section==='links' && isset($linkMeta[$id])) {
                    $meta=$linkMeta[$id];$flow=(float)$match[2];$rawHeadloss=(float)$match[4];$length=(float)($meta['lengthM']??0);$resultType=$meta['_result_type']??'pipe';$unitHeadloss=$resultType==='pipe'||$resultType==='valve'?$rawHeadloss:null;$headloss=$unitHeadloss!==null?$unitHeadloss*$length/1000:null;
                    $periods[$time]['links'][$meta['key']]=[
                        'engine_id'=>$id,'flow_lps'=>$flow,'absolute_flow_lps'=>abs($flow),'velocity_mps'=>abs((float)$match[3]),
                        'unit_headloss_m_per_km'=>$unitHeadloss,'headloss_m'=>$headloss,'pump_head_gain_m'=>$resultType==='pump'?abs($rawHeadloss):null,
                        'direction'=>$flow>=0?'asal_ke_tujuan':'tujuan_ke_asal','status'=>($meta['status']??'OPEN'),
                    ];$hasRows=true;
                }
                continue;
            }
            if ($hasRows && trim($line)==='') {$section=null;$hasRows=false;}
        }
        $periods=array_values($periods);$latest=$periods?end($periods):['time'=>null,'nodes'=>[],'links'=>[]];
        return ['available'=>(bool)$periods,'latest'=>$latest,'periods'=>$periods,'units'=>['demand'=>'L/s','head'=>'m','pressure'=>'m','flow'=>'L/s','velocity'=>'m/s','unit_headloss'=>'m/km','headloss'=>'m']];
    }
}

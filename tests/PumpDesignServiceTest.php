<?php
declare(strict_types=1);

spl_autoload_register(function(string $class): void {$prefix='App\\';if(!str_starts_with($class,$prefix))return;$file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;});

use App\Services\PumpDesignService;

$failed=[];$assert=function(bool $condition,string $message)use(&$failed): void {echo ($condition?'[PASS] ':'[FAIL] ').$message.PHP_EOL;if(!$condition)$failed[]=$message;};
$model=[
    'nodes'=>[
        'source:1'=>['entity_type'=>'source','node_type'=>'reservoir','head_m'=>50,'elevation_m'=>50,'base_demand_lps'=>0],
        'node:2'=>['entity_type'=>'node','node_type'=>'junction','elevation_m'=>80,'base_demand_lps'=>10],
    ],
    'links'=>[
        ['id'=>7,'route_name'=>'Pompa Uji','link_type'=>'PUMP','origin_key'=>'source:1','destination_key'=>'node:2','planned_flow_lps'=>0,'pump_capacity_lps'=>0],
    ],
    'curves'=>[],
];
$service=new PumpDesignService();
$designs=$service->design($model,['target_pressure_m'=>20,'pump_head_allowance_m'=>10,'pump_flow_safety_percent'=>10,'pump_head_safety_percent'=>10,'pump_efficiency_percent'=>75]);
$design=$designs[0];
$assert(count($designs)===1,'Satu jalur PUMP menghasilkan satu desain pompa');
$assert(abs($design['flow_lps']-22)<.001&&abs($design['head_m']-66)<.001,'Debit dan head otomatis memakai demand harian, jam operasi, elevasi, allowance, dan faktor keamanan');
$assert(count($design['points'])===3&&$design['points'][0]['head_m']>$design['points'][1]['head_m']&&$design['points'][1]['head_m']>$design['points'][2]['head_m'],'Kurva Q-H tiga titik menurun dan valid');
$applied=$service->applyToModel($model,$designs);$curveId=$applied['links'][0]['pump_curve_id'];
$assert(isset($applied['curves'][$curveId])&&count($applied['curves'][$curveId]['points'])===3,'Kurva virtual ditautkan ke pompa untuk pengujian EPANET');
$manual=$service->design($model,['pump_design_flow_lps'=>25,'pump_design_head_m'=>40,'pump_efficiency_percent'=>80]);
$assert($manual[0]['flow_lps']===25.0&&$manual[0]['head_m']===40.0,'Titik kerja manual mengesampingkan perhitungan otomatis');
$assert($manual[0]['estimated_power_kw']>0,'Perkiraan daya pompa dihitung dari Q, H, dan efisiensi');
$tankModel=$model;$tankModel['nodes']['tank:3']=['entity_type'=>'node','node_type'=>'tank','elevation_m'=>120,'initial_level_m'=>4,'minimum_level_m'=>0,'maximum_level_m'=>5,'base_demand_lps'=>0];$tankModel['links'][]=['id'=>8,'route_name'=>'Pipa ke tank','link_type'=>'PIPE','origin_key'=>'node:2','destination_key'=>'tank:3','status'=>'aktif','initial_status'=>'OPEN'];
$tankDesign=$service->design($tankModel,['target_pressure_m'=>20,'pump_head_allowance_m'=>10,'pump_flow_safety_percent'=>0,'pump_head_safety_percent'=>0]);
$assert($tankDesign[0]['destination_required_head_m']===124.0&&$tankDesign[0]['head_m']===84.0,'Desain pompa membaca kebutuhan head tank di hilir, bukan hanya node setelah pompa');
$controlledTankModel=$service->applyToModel($tankModel,$tankDesign);$assert($controlledTankModel['links'][0]['control_mode']==='TANK_LEVEL'&&$controlledTankModel['links'][0]['stop_level_m']>$controlledTankModel['links'][0]['start_level_m'],'Pompa otomatis mendapat kontrol hidup-mati berdasarkan level tank');
$tankModel['nodes']['node:4']=['entity_type'=>'node','node_type'=>'junction','elevation_m'=>80,'base_demand_lps'=>5];$tankModel['links'][]=['id'=>9,'route_name'=>'Distribusi tank','link_type'=>'PIPE','origin_key'=>'tank:3','destination_key'=>'node:4','status'=>'aktif','initial_status'=>'OPEN'];$flowModel=$service->applyDesignFlows($tankModel,$tankDesign);$flowById=[];foreach($flowModel['links'] as $flowLink)$flowById[(int)$flowLink['id']]=(float)($flowLink['planned_flow_lps']??0);$assert(abs($flowById[8]-$tankDesign[0]['flow_lps'])<.001&&abs($flowById[9]-5)<.001,'Debit pipa pengisian mengikuti pompa sedangkan pipa keluar tank mengikuti demand hilir');
$continuous=$service->design($model,['pump_operating_hours_day'=>24,'pump_flow_safety_percent'=>10]);$assert(abs($continuous[0]['flow_lps']-11)<.001,'Operasi 24 jam menghasilkan debit mendekati demand dengan cadangan');
exit($failed?1:0);

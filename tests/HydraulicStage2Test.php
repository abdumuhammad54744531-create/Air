<?php
declare(strict_types=1);

use App\Core\App;
use App\Services\HydraulicNetworkService;

spl_autoload_register(function (string $class): void {
    $prefix='App\\';
    if (!str_starts_with($class,$prefix)) return;
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require $file;
});
require dirname(__DIR__).'/app/Core/helpers.php';
App::boot();

$service=new HydraulicNetworkService();
$options=[
    'flowUnit'=>'LPS','headlossFormula'=>'H-W','demandModel'=>'DDA',
    'minimumPressureM'=>5.0,'requiredPressureM'=>15.0,'pressureExponent'=>0.5,
    'demandMultiplier'=>1.0,'analysisType'=>'STEADY','duration'=>'0:00',
    'hydraulicTimestep'=>'1:00','reportTimestep'=>'1:00','patternTimestep'=>'1:00',
];
$coordinates=fn(array $ids): array => array_map(
    fn(string $id,int $index)=>['id'=>$id,'x'=>100+$index*100,'y'=>100],
    $ids,array_keys($ids)
);
$base=fn(): array => [
    'options'=>$options,'nodes'=>[],'reservoirs'=>[],'tanks'=>[],'pipes'=>[],
    'pumps'=>[],'valves'=>[],'patterns'=>[],'curves'=>[],'coordinates'=>[],
];

$models=[];

$payload=$base();
$payload['reservoirs'][]=['id'=>'R1','key'=>'source:1','headM'=>50.0,'patternId'=>null];
$payload['nodes'][]=['id'=>'J1','key'=>'node:1','type'=>'JUNCTION','elevationM'=>10.0,'baseDemandLps'=>2.0,'patternId'=>null,'emitterCoefficient'=>0];
$payload['pipes'][]=['id'=>'P1','key'=>'link:1','fromNode'=>'R1','toNode'=>'J1','lengthM'=>1000.0,'diameterMm'=>150.0,'roughness'=>120.0,'minorLoss'=>0.0,'status'=>'OPEN','checkValve'=>false];
$payload['coordinates']=$coordinates(['R1','J1']);
$models['reservoir-junction-pipe']=$payload;

$payload=$base();
$payload['reservoirs'][]=['id'=>'R-TANK','key'=>'source:10','headM'=>60.0,'patternId'=>null];
$payload['tanks'][]=['id'=>'T1','key'=>'node:10','elevationM'=>20.0,'initialLevelM'=>2.0,'minimumLevelM'=>0.0,'maximumLevelM'=>5.0,'diameterM'=>8.0,'minimumVolumeM3'=>0.0,'overflow'=>false];
$payload['pipes'][]=['id'=>'P-TANK','key'=>'link:10','fromNode'=>'R-TANK','toNode'=>'T1','lengthM'=>500.0,'diameterMm'=>200.0,'roughness'=>120.0,'minorLoss'=>0.0,'status'=>'OPEN','checkValve'=>false];
$payload['coordinates']=$coordinates(['R-TANK','T1']);
$models['reservoir-tank-without-volume-curve']=$payload;

$payload=$base();
$payload['options']['analysisType']='EXTENDED';$payload['options']['duration']='3:00';
$payload['reservoirs'][]=['id'=>'WELL1','key'=>'source:2','headM'=>30.0,'patternId'=>null];
$payload['nodes'][]=['id'=>'J2','key'=>'node:2','type'=>'JUNCTION','elevationM'=>12.0,'baseDemandLps'=>3.0,'patternId'=>'DEM24','emitterCoefficient'=>0];
$payload['patterns'][]=['id'=>'DEM24','multipliers'=>[.6,.8,1.2,1.0]];$payload['patterns'][]=['id'=>'SPD24','multipliers'=>[1,.9,1.1,1]];
$payload['curves'][]=['id'=>'PC1','type'=>'PUMP','points'=>[['flow_lps'=>0,'head_m'=>35],['flow_lps'=>5,'head_m'=>28],['flow_lps'=>10,'head_m'=>10]]];
$payload['pumps'][]=['id'=>'PU1','key'=>'link:2','fromNode'=>'WELL1','toNode'=>'J2','curveId'=>'PC1','powerKw'=>null,'speed'=>1.0,'speedPatternId'=>'SPD24','status'=>'OPEN'];
$payload['coordinates']=$coordinates(['WELL1','J2']);
$models['source-well-pump-pattern-24h']=$payload;

$payload=$base();
$payload['options']['analysisType']='EXTENDED';$payload['options']['duration']='2:00';
$payload['reservoirs'][]=['id'=>'R2','key'=>'source:3','headM'=>45.0,'patternId'=>null];
$payload['reservoirs'][]=['id'=>'R3','key'=>'source:4','headM'=>43.0,'patternId'=>null];
$payload['nodes'][]=['id'=>'J3','key'=>'node:3','type'=>'JUNCTION','elevationM'=>10.0,'baseDemandLps'=>4.0,'patternId'=>null,'emitterCoefficient'=>0];
$payload['pipes'][]=['id'=>'P2','key'=>'link:3','fromNode'=>'R2','toNode'=>'J3','lengthM'=>700.0,'diameterMm'=>150.0,'roughness'=>120.0,'minorLoss'=>0.0,'status'=>'OPEN','checkValve'=>false];
$payload['pipes'][]=['id'=>'P3','key'=>'link:4','fromNode'=>'R3','toNode'=>'J3','lengthM'=>850.0,'diameterMm'=>125.0,'roughness'=>120.0,'minorLoss'=>0.0,'status'=>'OPEN','checkValve'=>false];
$payload['coordinates']=$coordinates(['R2','R3','J3']);
$models['multiple-alternative-sources']=$payload;

$globalModel=['nodes'=>['node:99'=>['engine_id'=>'J99','key'=>'node:99','node_type'=>'junction','entity_type'=>'node','x'=>0,'y'=>0,'elevation_m'=>10,'base_demand_lps'=>2,'demand_pattern_id'=>null,'emitter_coefficient'=>0]],'links'=>[],'patterns'=>[],'curves'=>[]];
$globalPayload=$service->buildPayload($globalModel,['analysis_type'=>'EXTENDED','duration'=>'24:00','apply_global_pattern'=>'1','hourly_pattern'=>array_fill(0,24,1.25)]);$globalOk=count($globalPayload['patterns'])===1&&count($globalPayload['patterns'][0]['multipliers'])===24&&$globalPayload['nodes'][0]['patternId']==='SIM24GLOBAL';echo '['.($globalOk?'PASS':'FAIL')."] global-demand-pattern-24h\n";
$failed=!$globalOk;
$zeroHeadModel=['nodes'=>[
    'source:1'=>['engine_id'=>'S0','key'=>'source:1','name'=>'Sumber datum nol','node_type'=>'reservoir','entity_type'=>'source','head_m'=>0.0,'total_head_m'=>0.0,'elevation_m'=>0.0,'status'=>'aktif'],
    'node:1'=>['engine_id'=>'J0','key'=>'node:1','name'=>'Junction','node_type'=>'junction','entity_type'=>'node','elevation_m'=>0.0,'base_demand_lps'=>0.0],
],'links'=>[['id'=>1,'route_name'=>'Pipa datum nol','origin_key'=>'source:1','destination_key'=>'node:1','origin_engine_id'=>'S0','destination_engine_id'=>'J0','link_type'=>'PIPE','pipe_length_m'=>10,'pipe_diameter_mm'=>100,'roughness_coefficient'=>120]],'patterns'=>[],'curves'=>[]];
$zeroValidation=$service->validate($zeroHeadModel);$zeroHeadOk=$zeroValidation['valid'];echo '['.($zeroHeadOk?'PASS':'FAIL')."] source-head-zero-valid-datum\n";$failed=$failed||!$zeroHeadOk;
$missingHeadModel=$zeroHeadModel;$missingHeadModel['nodes']['source:1']['head_m']=null;$missingHeadModel['nodes']['source:1']['total_head_m']=null;$missingHeadModel['nodes']['source:1']['elevation_m']=null;$missingValidation=$service->validate($missingHeadModel);$missingHeadOk=!$missingValidation['valid'];echo '['.($missingHeadOk?'PASS':'FAIL')."] source-head-null-invalid\n";$failed=$failed||!$missingHeadOk;
foreach ($models as $name=>$model) {
    $result=$service->run($model);
    $periodCount=count($result['results']['periods']);
    $parsed=$result['results']['available']
        && count($result['results']['latest']['nodes'])===count($model['nodes'])+count($model['reservoirs'])+count($model['tanks'])
        && count($result['results']['latest']['links'])===count($model['pipes'])+count($model['pumps'])+count($model['valves'])
        && ($model['options']['analysisType']!=='EXTENDED'||$periodCount>=3);
    $state=$result['success']&&$parsed?'PASS':'FAIL';
    echo sprintf("[%s] %s (exit=%d, periods=%d)\n",$state,$name,$result['exit_code'],$periodCount);
    if (!$result['success']||!$parsed) {
        $failed=true;
        if (!$parsed) echo "Hasil node/link EPANET tidak berhasil diparsing lengkap.\n";
        echo implode("\n",$result['engine_errors'])."\n".$result['stderr']."\n".$result['report_excerpt']."\n";
    }
    if($name==='source-well-pump-pattern-24h'){
        $first=$result['results']['periods'][0]??[];$requested=(float)($first['nodes']['node:2']['requested_demand_lps']??-1);$gain=(float)($first['links']['link:2']['pump_head_gain_m']??0);$patternOk=abs($requested-1.8)<.001&&$gain>0;echo '['.($patternOk?'PASS':'FAIL')."] patterned-request-and-pump-head-gain\n";$failed=$failed||!$patternOk;
    }
}
exit($failed?1:0);

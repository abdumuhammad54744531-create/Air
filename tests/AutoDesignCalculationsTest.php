<?php
declare(strict_types=1);

spl_autoload_register(function(string $class): void {$prefix='App\\';if(!str_starts_with($class,$prefix))return;$file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;});
use App\Services\DemandCalculationService;use App\Services\PipeSizingService;use App\Services\ReservoirSizingService;

$failures=[];$assert=function(bool $condition,string $message)use(&$failures){echo ($condition?'[PASS] ':'[FAIL] ').$message.PHP_EOL;if(!$condition)$failures[]=$message;};$near=fn(float $a,float $b,float $t=1e-6)=>abs($a-$b)<=$t;
$pipe=new PipeSizingService();
$assert($near($pipe->litersPerSecondToCubicMetersPerSecond(10),.01),'Konversi 10 L/s = 0,01 m³/s');
$assert($near($pipe->area(100),M_PI*.1*.1/4),'Luas penampang memakai diameter dalam');
$assert($near($pipe->velocity(10,100),1.2732395447,1e-8),'Kecepatan Q/A');
$hw=$pipe->hazenWilliams(1000,10,100,150);$assert($near($hw,14.5896,.001),'Hazen-Williams SI');
$dw=$pipe->darcyWeisbach(1000,10,100,.0015);$assert($dw['headloss_m']>14&&$dw['headloss_m']<14.3,'Darcy-Weisbach dan Swamee-Jain');
$assert($near($pipe->minorLoss(10,100,2),2*1.2732395447**2/(2*PipeSizingService::GRAVITY),1e-8),'Kehilangan minor K·V²/2g');
$assert($near($pipe->pressureConversions(10)['bar'],.980665),'Konversi meter kolom air ke bar');
$pipe->validateDimensions(110,10,90);$assert(true,'Validasi OD, tebal dinding, dan ID konsisten');
$assert($pipe->pressureClassCheck(60,55,10,1.2)['passed'],'Kelas PN10 lolos tekanan 60 m dengan faktor 1,2');

$demand=(new DemandCalculationService())->calculate(['base_year'=>2026,'design_year'=>2036,'initial_population'=>1000,'population_growth_percent'=>2,'population_projection_method'=>'GEOMETRIC','domestic_lpd'=>120,'non_domestic_percent'=>10,'water_loss_percent'=>20,'max_day_factor'=>1.15,'peak_hour_factor'=>1.5]);
$assert($demand['projected_population']===1219,'Proyeksi geometrik dibulatkan');$assert($demand['peak_hour_lps']>$demand['max_day_lps']&&$demand['max_day_lps']>$demand['average_lps'],'Urutan debit rata-rata, maksimum, puncak');
$mass=(new DemandCalculationService())->massCurveVolume(240,array_fill(0,24,1));$assert($near($mass['operational_volume_m3'],0),'Pola demand dan suplai seragam tidak membutuhkan balancing storage');

$reservoir=new ReservoirSizingService();$assert($near($reservoir->rectangleVolume(10,5,3,2),300),'Volume persegi panjang multi-kompartemen');$assert($near($reservoir->squareVolume(5,4),100),'Volume persegi');$assert($near($reservoir->cylinderVolume(10,4),M_PI*100),'Volume silinder');
$required=$reservoir->requiredVolume(['method'=>'DAILY_PERCENT','max_day_m3'=>1000,'storage_percent'=>20,'reserve_percent'=>10,'fire_volume_m3'=>50,'emergency_volume_m3'=>20,'dead_volume_m3'=>30]);$assert($near($required['total_required_m3'],327),'Cadangan, kebakaran, darurat, dan volume mati tidak terhitung ganda');
$alternatives=$reservoir->generateAlternatives(100,['shape'=>'RECTANGLE','length_min_m'=>4,'length_max_m'=>8,'length_step_m'=>1,'width_min_m'=>4,'width_max_m'=>8,'width_step_m'=>1,'height_min_m'=>2,'height_max_m'=>4,'height_step_m'=>.5,'freeboard_m'=>.5,'compartments'=>1],5);$assert(count($alternatives)>=5&&$alternatives[0]['effective_volume_m3']>=100,'Generator dimensi memenuhi volume minimum');
exit($failures?1:0);

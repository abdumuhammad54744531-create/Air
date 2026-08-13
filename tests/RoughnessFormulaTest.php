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
$model=$service->loadModel(1);
$expected=['H-W'=>150.0,'D-W'=>0.0015,'C-M'=>0.009];
$failed=false;
foreach($expected as $formula=>$roughness){
    $payload=$service->buildPayload($model,['headloss_formula'=>$formula]);
    $pipe=array_values(array_filter($payload['pipes'],fn(array $item):bool=>$item['key']==='link:84'))[0]??null;
    $actual=(float)($pipe['roughness']??-1);
    $pass=abs($actual-$roughness)<0.0000001;
    echo sprintf("[%s] PVC %s = %s\n",$pass?'PASS':'FAIL',$formula,$actual);
    if(!$pass)$failed=true;
}
exit($failed?1:0);

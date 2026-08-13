<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Services\HydraulicNetworkService;

spl_autoload_register(function (string $class): void {
    $prefix='App\\';
    if (!str_starts_with($class,$prefix)) return;
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require $file;
});
require dirname(__DIR__).'/app/Core/helpers.php';
App::boot();

$pdo=Database::connection();
$pdo->beginTransaction();
try {
    Database::query("INSERT INTO network_projects(code,name,status,is_default,created_at,updated_at) VALUES('TEST-ISOLASI','Uji Isolasi','draft',0,NOW(),NOW())");
    $projectId=(int)$pdo->lastInsertId();
    $service=new HydraulicNetworkService();
    $newProject=$service->loadModel($projectId);
    $mainProject=$service->loadModel((int)Database::query("SELECT id FROM network_projects WHERE is_default=1 AND deleted_at IS NULL LIMIT 1")->fetchColumn());
    if ($newProject['links']!==[]) throw new RuntimeException('Proyek baru membawa link dari proyek utama.');
    if ($newProject['nodes']!==[]) throw new RuntimeException('Proyek baru membawa titik dari proyek utama.');
    if (count($mainProject['links'])===0) throw new RuntimeException('Proyek utama kehilangan link.');
    $pdo->rollBack();
    echo sprintf("[PASS] project isolation (new_nodes=0, new_links=0, main_links=%d)\n",count($mainProject['links']));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,"[FAIL] ".$error->getMessage().PHP_EOL);
    exit(1);
}

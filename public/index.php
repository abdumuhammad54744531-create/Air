<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\CrudController;
use App\Controllers\ApiController;
use App\Controllers\PublicController;
use App\Controllers\WaterManagementController;
use App\Controllers\DistributionNetworkController;
use App\Controllers\HydraulicAnalysisController;
use App\Controllers\NetworkProjectController;
use App\Controllers\AutomaticDesignController;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
require dirname(__DIR__) . '/app/Core/helpers.php';
App::boot();

$path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$scriptBase = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptBase && str_starts_with($path, $scriptBase)) $path = trim(substr($path, strlen($scriptBase)), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!is_file(App::ROOT . '/storage/installed.lock') && !str_starts_with($path, 'install')) {
    header('Location: ' . url('install')); exit;
}

try {
    if ($path === 'install') { require App::ROOT . '/install/index.php'; exit; }
    if ($path === '' || $path === 'publik') { (new PublicController())->home(); exit; }
    if ($path === 'login') { $method === 'POST' ? (new AuthController())->login() : (new AuthController())->form(); exit; }
    if ($path === 'logout') { (new AuthController())->logout(); exit; }
    if ($path === 'dashboard') { (new DashboardController())->index(); exit; }
    if ($path === 'dashboard/data') { (new DashboardController())->data(); exit; }
    if (preg_match('#^api/v1/(.+)$#', $path, $m)) { (new ApiController())->handle($m[1], $method); exit; }
    if ($path === 'water-dashboard') { (new WaterManagementController())->dashboard(); exit; }
    if ($path === 'water-simulation') { (new WaterManagementController())->wizard(); exit; }
    if ($path === 'water-simulation/run' && $method === 'POST') { (new WaterManagementController())->run(); exit; }
    if ($path === 'water-results') { (new WaterManagementController())->results(); exit; }
    if (preg_match('#^water-results/(\d+)$#',$path,$m)) { (new WaterManagementController())->results((int)$m[1]); exit; }
    if ($path === 'water-sensor-monitoring') { (new WaterManagementController())->sensorMonitoring(); exit; }
    if ($path === 'water-sensor-monitoring/data') { (new WaterManagementController())->sensorMonitoringData(); exit; }
    if ($path === 'water-reports') { (new WaterManagementController())->reports(); exit; }
    if ($path === 'network-projects') { (new NetworkProjectController())->handle($method); exit; }
    if ($path === 'automatic-design') { (new AutomaticDesignController())->index(); exit; }
    if ($path === 'automatic-design/save' && $method === 'POST') { (new AutomaticDesignController())->save(); exit; }
    if ($path === 'automatic-design/run' && $method === 'POST') { (new AutomaticDesignController())->run(); exit; }
    if ($path === 'automatic-design/select' && $method === 'POST') { (new AutomaticDesignController())->select(); exit; }
    if ($path === 'automatic-design/apply' && $method === 'POST') { (new AutomaticDesignController())->apply(); exit; }
    if ($path === 'automatic-design/quick' && $method === 'POST') { (new AutomaticDesignController())->quick(); exit; }
    if ($path === 'automatic-design/catalog' && $method === 'POST') { (new AutomaticDesignController())->catalog(); exit; }
    if (preg_match('#^automatic-design/report/(\d+)$#',$path,$m)) { (new AutomaticDesignController())->report((int)$m[1]); exit; }
    if ($path === 'distribution-networks/position' && $method === 'POST') { (new DistributionNetworkController())->savePosition(); exit; }
    if ($path === 'distribution-networks/hydraulic/validate' && $method === 'POST') { (new HydraulicAnalysisController())->validate(); exit; }
    if ($path === 'distribution-networks/hydraulic/run' && $method === 'POST') { (new HydraulicAnalysisController())->run(); exit; }
    if ($path === 'distribution-networks/node/quick' && $method === 'POST') { (new DistributionNetworkController())->createNode(); exit; }
    if ($path === 'distribution-networks/route/quick' && $method === 'POST') { (new DistributionNetworkController())->createRoute(); exit; }
    if ($path === 'distribution-networks/node' && $method === 'POST') { (new DistributionNetworkController())->saveNode(); exit; }
    if ($path === 'distribution-networks/pump-curves/delete' && $method === 'POST') { (new DistributionNetworkController())->deletePumpCurve(); exit; }
    if ($path === 'distribution-networks/pump-curves/cleanup' && $method === 'POST') { (new DistributionNetworkController())->cleanupPumpCurves(); exit; }
    if ($path === 'distribution-networks/bulk' && $method === 'GET') { (new DistributionNetworkController())->bulkEdit(); exit; }
    if ($path === 'distribution-networks/bulk' && $method === 'POST') { (new DistributionNetworkController())->bulkUpdate(); exit; }
    if ($path === 'distribution-networks') { (new DistributionNetworkController())->handle($method); exit; }
    if (preg_match('#^distribution-networks/(\d+)$#',$path,$m)) { (new DistributionNetworkController())->handle($method,(int)$m[1]); exit; }
    if (preg_match('#^(locations|devices|sensors|monitoring|alerts|maintenances|damages|calibrations|users|announcements|activity-logs|settings|water-sources|reservoirs|service-areas|distribution-networks|simulation-scenarios)(?:/(\d+))?$#', $path, $m)) {
        (new CrudController())->handle($m[1], $method, isset($m[2]) ? (int)$m[2] : null); exit;
    }
    if ($path === 'map') { require_auth(); (new DashboardController())->map(); exit; }
    if ($path === 'reports') { require_auth(); (new DashboardController())->reports(); exit; }
    if ($path === 'api-docs') { require_auth(); view('api/docs', ['title' => 'Dokumentasi API']); exit; }
    http_response_code(404); view('errors/404', ['title' => 'Halaman Tidak Ditemukan'], user() ? 'layouts/admin' : 'layouts/public');
} catch (Throwable $e) {
    http_response_code(500);
    $message = \App\Core\Env::get('APP_DEBUG', false) ? $e->getMessage() : 'Terjadi kesalahan pada sistem.';
    view('errors/500', ['title' => 'Kesalahan Sistem', 'message' => $message], user() ? 'layouts/admin' : 'layouts/public');
}

<?php
use App\Core\App;
use App\Core\Database;
use App\Core\Env;

$step = $_GET['step'] ?? 'check';
$errors = [];
$requirements = [
    'PHP 8.2+' => version_compare(PHP_VERSION,'8.2.0','>='),
    'Ekstensi PDO MySQL' => extension_loaded('pdo_mysql'),
    'Ekstensi JSON' => extension_loaded('json'),
    'Folder storage dapat ditulis' => is_writable(App::ROOT . '/storage'),
];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $env = [
            'APP_NAME'=>$_POST['app_name'] ?? 'Sistem Informasi Monitoring dan Manajemen Air',
            'APP_ENV'=>'local','APP_DEBUG'=>'true','APP_URL'=>$_POST['app_url'] ?? 'http://aplikasi-web-air.test',
            'APP_TIMEZONE'=>'Asia/Makassar','DB_HOST'=>$_POST['db_host'] ?? '127.0.0.1','DB_PORT'=>$_POST['db_port'] ?? '3306',
            'DB_DATABASE'=>$_POST['db_database'] ?? 'monitoring_air','DB_USERNAME'=>$_POST['db_username'] ?? 'root','DB_PASSWORD'=>$_POST['db_password'] ?? '',
            'PUBLIC_PAGE_ENABLED'=>'true','DEVICE_OFFLINE_MINUTES'=>'15','DASHBOARD_REFRESH_SECONDS'=>'30','SESSION_TIMEOUT_MINUTES'=>'120'
        ];
        $contents='';
        foreach($env as $k=>$v) $contents .= $k.'="'.str_replace('"','\"',(string)$v)."\"\r\n";
        file_put_contents(App::ROOT.'/.env',$contents,LOCK_EX);
        Env::load(App::ROOT.'/.env');
        $pdo = Database::connection(true);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.preg_replace('/[^a-zA-Z0-9_]/','',$env['DB_DATABASE']).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `'.preg_replace('/[^a-zA-Z0-9_]/','',$env['DB_DATABASE']).'`');
        foreach ([App::ROOT.'/database/schema.sql',App::ROOT.'/database/seed.sql',App::ROOT.'/database/water_simulation.sql'] as $file) {
            $sql=file_get_contents($file);
            $sql=preg_replace('/DELIMITER \\/\\/.*?DELIMITER ;/s','',$sql);
            if (str_ends_with($file,'seed.sql')) {
                $sql=preg_replace('/CREATE PROCEDURE seed_readings\\(\\).*?DROP PROCEDURE seed_readings;/s','',$sql);
            }
            $pdo->exec($sql);
        }
        require App::ROOT.'/database/migrations/20260730_hydraulic_stage1.php';
        require App::ROOT.'/database/migrations/20260731_network_projects.php';
        require App::ROOT.'/database/migrations/20260806_automatic_design.php';
        file_put_contents(App::ROOT.'/storage/installed.lock',date(DATE_ATOM));
        flash('success','Instalasi selesai. Masuk dengan akun administrator.'); redirect('login');
    } catch(Throwable $e) { $errors[]=$e->getMessage(); }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalasi Sistem Monitoring Air</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f0f7ff}.install-card{max-width:760px;margin:5vh auto;border:0;border-radius:22px;box-shadow:0 18px 55px #0b3b6f20}.brand{background:linear-gradient(135deg,#063970,#087ea4);color:#fff;border-radius:22px 22px 0 0}</style></head>
<body><main class="container"><section class="card install-card"><div class="brand p-4"><h1 class="h3 mb-1">Instalasi Sistem Monitoring Air</h1><p class="mb-0 opacity-75">Konfigurasi cepat untuk Laragon</p></div><div class="card-body p-4 p-md-5">
<?php foreach($errors as $error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach ?>
<h2 class="h5 mb-3">Pemeriksaan sistem</h2><?php foreach($requirements as $label=>$ok): ?><div class="d-flex justify-content-between border-bottom py-2"><span><?=e($label)?></span><span class="badge text-bg-<?=$ok?'success':'danger'?>"><?=$ok?'Siap':'Belum siap'?></span></div><?php endforeach ?>
<form method="post" class="row g-3 mt-3"><?=csrf_field()?>
<div class="col-md-7"><label class="form-label">Nama aplikasi</label><input class="form-control" name="app_name" value="Sistem Informasi Monitoring dan Manajemen Air" required></div>
<div class="col-md-5"><label class="form-label">URL aplikasi</label><input class="form-control" name="app_url" value="http://aplikasi-web-air.test" required></div>
<div class="col-md-5"><label class="form-label">Host database</label><input class="form-control" name="db_host" value="127.0.0.1" required></div>
<div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="db_port" value="3306" required></div>
<div class="col-md-5"><label class="form-label">Database</label><input class="form-control" name="db_database" value="monitoring_air" required></div>
<div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="db_username" value="root" required></div>
<div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" name="db_password"></div>
<div class="col-12"><button class="btn btn-primary btn-lg w-100" <?=in_array(false,$requirements,true)?'disabled':''?>>Pasang aplikasi dan database</button></div>
</form></div></section></main></body></html>

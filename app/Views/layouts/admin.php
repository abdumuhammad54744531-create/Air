<?php $flash=pull_flash(); $nav=[
 ['dashboard','bi-grid-1x2-fill','Dashboard'],
 ['water-dashboard','bi-diagram-3-fill','Dashboard Air'],['water-sources','bi-droplet-fill','Data Sumber Air'],['reservoirs','bi-box-fill','Data Reservoir'],
 ['service-areas','bi-houses-fill','Wilayah Layanan'],['network-projects','bi-folder2-open','Proyek Jaringan'],['distribution-networks','bi-activity','Analisis Jaringan'],['automatic-design','bi-magic','Desain Otomatis'],
 ['water-simulation','bi-calculator-fill','Simulasi Debit'],['simulation-scenarios','bi-layers-fill','Skenario Alternatif'],
 ['water-results','bi-clipboard-data-fill','Hasil Simulasi'],['water-sensor-monitoring','bi-broadcast','Monitoring Sensor'],['water-reports','bi-file-earmark-bar-graph-fill','Laporan Air'],
 ['monitoring','bi-activity','Monitoring'],['map','bi-geo-alt-fill','Peta Monitoring'],
 ['locations','bi-pin-map-fill','Data Lokasi'],['devices','bi-router-fill','Data Alat'],['sensors','bi-speedometer2','Data Sensor'],
 ['alerts','bi-bell-fill','Peringatan'],['maintenances','bi-tools','Pemeliharaan'],['damages','bi-exclamation-octagon-fill','Kerusakan'],
 ['calibrations','bi-sliders','Kalibrasi'],['announcements','bi-megaphone-fill','Informasi Publik'],['reports','bi-file-earmark-bar-graph-fill','Laporan'],
 ['users','bi-people-fill','Pengguna'],['api-docs','bi-braces','API Perangkat'],['activity-logs','bi-clock-history','Log Aktivitas'],
 ['settings','bi-gear-fill','Pengaturan']
]; $current=trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'); ?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=e(csrf_token())?>"><title><?=e($title??'Dashboard')?> — SIMMA</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?=url('assets/css/app.css')?>?v=3.7.3" rel="stylesheet"></head>
<body><div class="app-shell">
<aside class="sidebar" id="sidebar"><a class="brand" href="<?=url('dashboard')?>"><span class="brand-mark"><i class="bi bi-droplet-half"></i></span><span class="brand-copy"><strong>SIMMA</strong><small>Monitoring & Manajemen Air</small></span></a>
<nav class="sidebar-nav"><?php foreach($nav as [$href,$icon,$label]): if(in_array($href,['users','activity-logs','settings'])&&!has_role('super_admin'))continue; if($href==='water-dashboard'):?><small class="nav-section-label">PENGELOLAAN AIR</small><?php endif?><?php if($href==='network-projects'):?><small class="nav-section-label">JARINGAN DISTRIBUSI</small><?php endif?>
<a href="<?=url($href)?>" class="<?=str_contains($current,$href)?'active':''?> <?=in_array($href,['network-projects','distribution-networks','automatic-design'],true)?'nav-submenu':''?>"><i class="bi <?=$icon?>"></i><span><?=$label?></span><?php if($href==='alerts'&&!empty($stats['active_alerts'])):?><em><?=$stats['active_alerts']?></em><?php endif?></a><?php endforeach?></nav>
<div class="sidebar-footer"><a href="<?=url('publik')?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i><span>Buka Web Publik</span></a><a href="<?=url('logout')?>"><i class="bi bi-box-arrow-left"></i><span>Keluar</span></a></div></aside>
<div class="main-area"><header class="topbar"><button class="icon-btn" id="sidebarToggle" aria-label="Buka/tutup menu"><i class="bi bi-list"></i></button>
<div><h1><?=e($title??'Dashboard')?></h1><small><?=date('l, d F Y')?> · WITA</small></div><div class="top-actions">
<a class="icon-btn position-relative" href="<?=url('alerts')?>"><i class="bi bi-bell"></i><span class="pulse-dot"></span></a>
<div class="user-chip"><span class="avatar"><?=e(strtoupper(substr(user()['name']??'U',0,1)))?></span><span><strong><?=e(user()['name']??'Pengguna')?></strong><small><?=e(str_replace('_',' ',user()['role']??''))?></small></span></div></div></header>
<main class="content"><?php if($flash):?><div class="alert alert-<?=e($flash['type'])?> alert-dismissible fade show"><i class="bi bi-info-circle me-2"></i><?=e($flash['message'])?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif?><?=$content?></main>
<footer class="app-footer">SIMMA v1.0.0 · Zona waktu Asia/Makassar</footer></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script><script src="<?=url('assets/js/app.js')?>?v=3.7.3"></script>
</body></html>

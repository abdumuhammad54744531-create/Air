<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argc ?? 0) !== 2) {
    fwrite(STDERR, "Usage: php patch-panel.php /path/to/index.php\n");
    exit(2);
}
$path = $argv[1];
$source = is_file($path) ? file_get_contents($path) : false;
if (!is_string($source)) {
    fwrite(STDERR, "Panel source tidak dapat dibaca: {$path}\n");
    exit(3);
}
if (str_contains($source, "'id' => 'air'")) {
    echo "Panel Air sudah terdaftar.\n";
    exit(0);
}
$replacements = [
    "        'voucher-admin' => '/srv/apps/voucher-admin/public/index.php',\n" => "        'voucher-admin' => '/srv/apps/voucher-admin/public/index.php',\n        'air' => '/srv/apps/air/public/index.php',\n",
    "        'alikasi-rab' => '/usr/local/sbin/deploy-alikasi-rab',\n" => "        'alikasi-rab' => '/usr/local/sbin/deploy-alikasi-rab',\n        'air' => '/usr/local/sbin/deploy-air',\n",
];
foreach ($replacements as $needle => $replacement) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Struktur panel berubah; marker wajib tidak ditemukan.\n");
        exit(4);
    }
    $source = str_replace($needle, $replacement, $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Marker panel tidak unik.\n");
        exit(5);
    }
}
$cardMarker = "    [\n        'id' => 'phpmyadmin',";
$airCard = <<<'PHP'
    [
        'id' => 'air',
        'name' => 'SIMMA · Monitoring Air',
        'initials' => 'AI',
        'description' => 'Monitoring sumber air, sensor, jaringan distribusi, simulasi, dan analisis hidraulik EPANET.',
        'url' => 'https://air-buton.oisara.my.id',
        'domain' => 'air-buton.oisara.my.id',
        'path' => '/srv/apps/air',
        'scope' => 'Online · GitHub · EPANET Linux',
        'online' => $applicationStatus['apps']['air'] ?? false,
        'accent' => 'blue',
        'deploy_key' => 'air',
    ],

PHP;
if (!str_contains($source, $cardMarker)) {
    fwrite(STDERR, "Lokasi kartu aplikasi panel tidak ditemukan.\n");
    exit(6);
}
$source = str_replace($cardMarker, $airCard.$cardMarker, $source, $count);
if ($count !== 1) {
    fwrite(STDERR, "Lokasi kartu aplikasi tidak unik.\n");
    exit(7);
}
$temporary = $path.'.air.tmp';
if (file_put_contents($temporary, $source, LOCK_EX) === false || !rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Panel hasil patch tidak dapat dipasang.\n");
    exit(8);
}
echo "Kartu dan Deploy Air ditambahkan ke panel.\n";

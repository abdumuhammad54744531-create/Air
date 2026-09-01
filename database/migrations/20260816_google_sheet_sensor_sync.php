<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

require_once dirname(__DIR__,2).'/app/Core/Env.php';
require_once dirname(__DIR__,2).'/app/Core/Database.php';
Env::load(dirname(__DIR__,2).'/.env');

$columns = Database::query('SHOW COLUMNS FROM devices')->fetchAll();
$names = array_column($columns, 'Field');
foreach ([
    'google_sheet_url' => 'ADD COLUMN google_sheet_url VARCHAR(500) NULL AFTER notes',
    'google_sheet_gid' => 'ADD COLUMN google_sheet_gid VARCHAR(40) NULL AFTER google_sheet_url',
    'google_sheet_name' => 'ADD COLUMN google_sheet_name VARCHAR(150) NULL AFTER google_sheet_gid',
] as $name => $sql) if (!in_array($name, $names, true)) Database::query("ALTER TABLE devices {$sql}");

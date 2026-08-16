<?php
use App\Core\Database;

$columns = Database::query('SHOW COLUMNS FROM devices')->fetchAll();
$names = array_column($columns, 'Field');
foreach ([
    'google_sheet_url' => 'ADD COLUMN google_sheet_url VARCHAR(500) NULL AFTER notes',
    'google_sheet_gid' => 'ADD COLUMN google_sheet_gid VARCHAR(40) NULL AFTER google_sheet_url',
    'google_sheet_name' => 'ADD COLUMN google_sheet_name VARCHAR(150) NULL AFTER google_sheet_gid',
] as $name => $sql) if (!in_array($name, $names, true)) Database::query("ALTER TABLE devices {$sql}");

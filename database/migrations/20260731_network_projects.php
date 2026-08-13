<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

require_once dirname(__DIR__,2).'/app/Core/Env.php';
require_once dirname(__DIR__,2).'/app/Core/Database.php';
Env::load(dirname(__DIR__,2).'/.env');

$pdo=Database::connection();
$database=(string)Env::get('DB_DATABASE','monitoring_air');

function projectColumnExists(\PDO $pdo,string $database,string $table,string $column): bool
{
    $query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?');
    $query->execute([$database,$table,$column]);
    return (bool)$query->fetchColumn();
}
function projectIndexExists(\PDO $pdo,string $database,string $table,string $index): bool
{
    $query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND index_name=?');
    $query->execute([$database,$table,$index]);
    return (bool)$query->fetchColumn();
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS network_projects (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(60) NOT NULL UNIQUE,
      name VARCHAR(160) NOT NULL,
      description TEXT NULL,
      status ENUM('draft','aktif','arsip') NOT NULL DEFAULT 'aktif',
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      created_by BIGINT UNSIGNED NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      INDEX idx_network_project_status(status,deleted_at)
    ) ENGINE=InnoDB");
    $defaultId=(int)($pdo->query("SELECT id FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,id LIMIT 1")->fetchColumn()?:0);
    if (!$defaultId) {
        $pdo->exec("INSERT INTO network_projects(code,name,description,status,is_default,created_at,updated_at)
          VALUES('PRJ-UTAMA','Proyek Jaringan Utama','Proyek awal yang berisi seluruh diagram jaringan sebelum fitur proyek ditambahkan.','aktif',1,NOW(),NOW())");
        $defaultId=(int)$pdo->lastInsertId();
    }
    foreach (['distribution_nodes','distribution_networks','distribution_node_positions'] as $table) {
        if (!projectColumnExists($pdo,$database,$table,'project_id')) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER id");
        }
        $pdo->exec("UPDATE `$table` SET project_id=$defaultId WHERE project_id IS NULL");
        $pdo->exec("ALTER TABLE `$table` MODIFY project_id BIGINT UNSIGNED NOT NULL");
        $index='idx_'.$table.'_project';
        if (!projectIndexExists($pdo,$database,$table,$index)) $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` (project_id)");
    }
    if (projectIndexExists($pdo,$database,'distribution_node_positions','uq_distribution_node')) {
        $pdo->exec("ALTER TABLE distribution_node_positions DROP INDEX uq_distribution_node");
    }
    if (!projectIndexExists($pdo,$database,'distribution_node_positions','uq_project_distribution_node')) {
        $pdo->exec("ALTER TABLE distribution_node_positions ADD UNIQUE KEY uq_project_distribution_node(project_id,node_type,entity_id)");
    }
    echo "Network projects migration complete. Default project ID: $defaultId".PHP_EOL;
} catch (Throwable $error) {
    throw $error;
}

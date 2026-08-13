<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

require_once dirname(__DIR__, 2).'/app/Core/Env.php';
require_once dirname(__DIR__, 2).'/app/Core/Database.php';
Env::load(dirname(__DIR__, 2).'/.env');

$pdo = Database::connection();
$database = (string)Env::get('DB_DATABASE', 'monitoring_air');

$pdo->exec("CREATE TABLE IF NOT EXISTS hydraulic_migration_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 migration_name VARCHAR(120) NOT NULL,
 object_type VARCHAR(60) NOT NULL,
 object_id BIGINT UNSIGNED NULL,
 severity VARCHAR(20) NOT NULL DEFAULT 'info',
 message VARCHAR(500) NOT NULL,
 context_json JSON NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_hydraulic_migration(migration_name,severity)
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS demand_patterns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 interval_minutes INT NOT NULL DEFAULT 60,
 multipliers_json JSON NOT NULL,
 status VARCHAR(30) DEFAULT 'aktif',
 description TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS hydraulic_curves (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 curve_type ENUM('PUMP','EFFICIENCY','VOLUME','HEADLOSS') NOT NULL,
 points_json JSON NOT NULL,
 status VARCHAR(30) DEFAULT 'aktif',
 description TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS operating_schedules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 schedule_json JSON NOT NULL,
 status VARCHAR(30) DEFAULT 'aktif',
 description TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS service_area_demand_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 service_area_id BIGINT UNSIGNED NOT NULL,
 node_id BIGINT UNSIGNED NOT NULL,
 allocation_method ENUM('AGGREGATED','PIPE_LENGTH','CONNECTION_COUNT','MANUAL_PERCENT','SERVICE_AREA') NOT NULL DEFAULT 'AGGREGATED',
 allocation_percentage DECIMAL(8,4) NULL,
 calculated_demand_lps DECIMAL(16,4) NOT NULL DEFAULT 0,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL,
 UNIQUE KEY uq_service_area_node(service_area_id,node_id)
) ENGINE=InnoDB");

function addColumn(PDO $pdo, string $database, string $table, string $column, string $definition): void
{
    $statement=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?");
    $statement->execute([$database,$table,$column]);
    if (!(int)$statement->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

$nodeColumns = [
    'required_pressure_m'=>'DECIMAL(10,3) NULL',
    'demand_category'=>'VARCHAR(80) NULL',
    'pressure_exponent'=>'DECIMAL(8,4) NOT NULL DEFAULT 0.5',
    'measured_pressure_m'=>'DECIMAL(10,3) NULL',
    'pressure_measured_at'=>'DATETIME NULL',
    'demand_pattern_id'=>'BIGINT UNSIGNED NULL',
    'master_source_id'=>'BIGINT UNSIGNED NULL',
    'hydraulic_representation'=>"ENUM('RESERVOIR','TANK','WELL_PUMP') NULL",
    'source_head_m'=>'DECIMAL(12,3) NULL',
    'static_water_level_m'=>'DECIMAL(12,3) NULL',
    'dynamic_water_level_m'=>'DECIMAL(12,3) NULL',
    'source_pattern_id'=>'BIGINT UNSIGNED NULL',
    'maximum_withdrawal_lps'=>'DECIMAL(16,4) NULL',
    'minimum_operating_flow_lps'=>'DECIMAL(16,4) NULL',
    'connected_pump_node_id'=>'BIGINT UNSIGNED NULL',
    'tank_overflow'=>'TINYINT(1) NOT NULL DEFAULT 0',
    'pump_curve_id'=>'BIGINT UNSIGNED NULL',
    'efficiency_curve_id'=>'BIGINT UNSIGNED NULL',
    'inlet_node_id'=>'BIGINT UNSIGNED NULL',
    'outlet_node_id'=>'BIGINT UNSIGNED NULL',
    'nominal_power_kw'=>'DECIMAL(16,3) NULL',
    'unit_count'=>'INT NOT NULL DEFAULT 1',
    'active_unit_count'=>'INT NOT NULL DEFAULT 1',
    'initial_status'=>"ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN'",
    'control_mode'=>"ENUM('MANUAL','TIME','TANK_LEVEL','PRESSURE') NOT NULL DEFAULT 'MANUAL'",
    'start_level_m'=>'DECIMAL(12,3) NULL',
    'stop_level_m'=>'DECIMAL(12,3) NULL',
    'start_pressure_m'=>'DECIMAL(12,3) NULL',
    'stop_pressure_m'=>'DECIMAL(12,3) NULL',
    'operating_schedule_id'=>'BIGINT UNSIGNED NULL',
    'meter_target_type'=>"ENUM('NODE','LINK','SOURCE','TANK','PUMP') NULL",
    'meter_target_id'=>'BIGINT UNSIGNED NULL',
    'meter_sensor_id'=>'BIGINT UNSIGNED NULL',
    'meter_current_value'=>'DECIMAL(18,6) NULL',
    'meter_calibrated_value'=>'DECIMAL(18,6) NULL',
    'meter_calibration_factor'=>'DECIMAL(12,6) NOT NULL DEFAULT 1',
    'meter_minimum_limit'=>'DECIMAL(18,6) NULL',
    'meter_maximum_limit'=>'DECIMAL(18,6) NULL',
    'meter_measured_at'=>'DATETIME NULL',
    'communication_status'=>'VARCHAR(30) NULL',
];
foreach ($nodeColumns as $column=>$definition) addColumn($pdo,$database,'distribution_nodes',$column,$definition);

$linkColumns = [
    'link_type'=>"ENUM('PIPE','PUMP','VALVE') NOT NULL DEFAULT 'PIPE'",
    'use_manual_length'=>'TINYINT(1) NOT NULL DEFAULT 1',
    'geometric_length_m'=>'DECIMAL(16,2) NULL',
    'material_code'=>'VARCHAR(60) NULL',
    'installation_year'=>'YEAR NULL',
    'max_velocity_mps'=>'DECIMAL(12,4) NULL',
    'max_unit_headloss_m_per_km'=>'DECIMAL(12,4) NULL',
    'leakage_model'=>"ENUM('NONE','NODE_EMITTER','PIPE_PERCENT','CUSTOM') NOT NULL DEFAULT 'NONE'",
    'polyline_json'=>'JSON NULL',
    'pump_curve_id'=>'BIGINT UNSIGNED NULL',
    'efficiency_curve_id'=>'BIGINT UNSIGNED NULL',
    'nominal_power_kw'=>'DECIMAL(16,3) NULL',
    'relative_speed'=>'DECIMAL(10,4) NOT NULL DEFAULT 1',
    'speed_pattern_id'=>'BIGINT UNSIGNED NULL',
    'initial_status'=>"ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN'",
    'unit_count'=>'INT NOT NULL DEFAULT 1',
    'active_unit_count'=>'INT NOT NULL DEFAULT 1',
    'control_mode'=>"ENUM('MANUAL','TIME','TANK_LEVEL','PRESSURE') NOT NULL DEFAULT 'MANUAL'",
    'start_level_m'=>'DECIMAL(12,3) NULL',
    'stop_level_m'=>'DECIMAL(12,3) NULL',
    'start_pressure_m'=>'DECIMAL(12,3) NULL',
    'stop_pressure_m'=>'DECIMAL(12,3) NULL',
    'operating_schedule_id'=>'BIGINT UNSIGNED NULL',
    'valve_type'=>'VARCHAR(10) NULL',
    'valve_setting'=>'DECIMAL(16,4) NULL',
];
foreach ($linkColumns as $column=>$definition) addColumn($pdo,$database,'distribution_networks',$column,$definition);

$beforeNodes=(int)$pdo->query("SELECT COUNT(*) FROM distribution_nodes")->fetchColumn();
$beforeLinks=(int)$pdo->query("SELECT COUNT(*) FROM distribution_networks")->fetchColumn();
$pdo->exec("UPDATE distribution_networks SET link_type='PIPE' WHERE link_type IS NULL OR link_type=''");
$mappedLinks=$pdo->exec("UPDATE distribution_networks SET material_code=pipe_type WHERE material_code IS NULL AND pipe_type IS NOT NULL");
$pdo->exec("UPDATE distribution_nodes SET meter_parameter=CASE LOWER(meter_parameter)
 WHEN 'flow' THEN 'FLOW' WHEN 'pressure' THEN 'PRESSURE' WHEN 'level' THEN 'LEVEL'
 WHEN 'quality' THEN 'WATER_QUALITY' ELSE meter_parameter END
 WHERE node_type='meter' AND meter_parameter IS NOT NULL");
$legacyPumpNodes=$pdo->query("SELECT id,code,name FROM distribution_nodes WHERE node_type='pompa' AND deleted_at IS NULL")->fetchAll();
$legacyPumpLinks=$pdo->query("SELECT id,route_name,pump_status,pump_capacity_lps FROM distribution_networks WHERE deleted_at IS NULL AND pump_status IS NOT NULL AND pump_status<>'tanpa_pompa'")->fetchAll();

$log=$pdo->prepare("INSERT INTO hydraulic_migration_logs(migration_name,object_type,object_id,severity,message,context_json) VALUES('20260730_hydraulic_stage1',?,?,?,?,?)");
$log->execute(['database',null,'info','Migrasi aditif selesai; tidak ada kolom lama yang dihapus.',json_encode(['nodes'=>$beforeNodes,'links'=>$beforeLinks,'material_mapped'=>$mappedLinks])]);
foreach ($legacyPumpNodes as $pump) {
    $log->execute(['node',(int)$pump['id'],'warning','Node pompa lama dipertahankan dan perlu ditinjau sebelum dipindahkan menjadi link PUMP.',json_encode($pump)]);
}
foreach ($legacyPumpLinks as $pumpLink) {
    $log->execute(['link',(int)$pumpLink['id'],'warning','Link mempunyai data pompa lama tetapi tetap dipertahankan sebagai PIPE sampai pengguna mengonfirmasi tipe PUMP.',json_encode($pumpLink)]);
}

$afterNodes=(int)$pdo->query("SELECT COUNT(*) FROM distribution_nodes")->fetchColumn();
$afterLinks=(int)$pdo->query("SELECT COUNT(*) FROM distribution_networks")->fetchColumn();
echo "Hydraulic Stage 1 migration complete.\n";
echo "Nodes: $beforeNodes -> $afterNodes\n";
echo "Links: $beforeLinks -> $afterLinks\n";
echo "Legacy pump nodes requiring review: ".count($legacyPumpNodes)."\n";

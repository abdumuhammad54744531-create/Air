USE monitoring_air;

CREATE TABLE IF NOT EXISTS water_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, location_id BIGINT UNSIGNED NULL, sensor_id BIGINT UNSIGNED NULL,
 code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, source_type VARCHAR(60) NOT NULL,
 latitude DECIMAL(10,7), longitude DECIMAL(10,7), elevation_m DECIMAL(10,2),
 min_flow_lps DECIMAL(16,4) NOT NULL DEFAULT 0, normal_flow_lps DECIMAL(16,4) NOT NULL DEFAULT 0,
 max_flow_lps DECIMAL(16,4) NOT NULL DEFAULT 0, current_sensor_flow_lps DECIMAL(16,4),
 measurement_season VARCHAR(30) DEFAULT 'normal', water_quality VARCHAR(60) DEFAULT 'baik',
 status VARCHAR(30) DEFAULT 'aktif', source_loss_percent DECIMAL(6,2) DEFAULT 0,
 description TEXT, last_measured_at DATETIME, photo VARCHAR(255), is_public TINYINT(1) DEFAULT 0,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL,
 FOREIGN KEY(sensor_id) REFERENCES sensors(id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_source_status(status), INDEX idx_source_location(location_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservoirs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, location_id BIGINT UNSIGNED NULL, code VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL, latitude DECIMAL(10,7), longitude DECIMAL(10,7), elevation_m DECIMAL(10,2),
 reservoir_type VARCHAR(60) NOT NULL, length_m DECIMAL(12,3) NOT NULL, width_m DECIMAL(12,3) NOT NULL,
 height_m DECIMAL(12,3) NOT NULL, geometric_volume_m3 DECIMAL(16,3) NOT NULL DEFAULT 0,
 effective_percent DECIMAL(6,2) DEFAULT 90, effective_capacity_m3 DECIMAL(16,3) NOT NULL DEFAULT 0,
 minimum_operational_m3 DECIMAL(16,3) DEFAULT 0, initial_volume_m3 DECIMAL(16,3) DEFAULT 0,
 initial_water_level_m DECIMAL(12,3) DEFAULT 0, max_inflow_lps DECIMAL(16,4),
 max_outflow_lps DECIMAL(16,4), loss_percent DECIMAL(6,2) DEFAULT 0, status VARCHAR(30) DEFAULT 'aktif',
 description TEXT, created_by BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_areas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL,
 population INT UNSIGNED NOT NULL DEFAULT 0, house_connections INT UNSIGNED DEFAULT 0, public_facilities INT UNSIGNED DEFAULT 0,
 liters_per_person_day DECIMAL(12,2) NOT NULL DEFAULT 60, public_facility_liters_day DECIMAL(16,2) DEFAULT 0,
 max_day_factor DECIMAL(8,3) DEFAULT 1.15, peak_hour_factor DECIMAL(8,3) DEFAULT 1.75,
 network_loss_percent DECIMAL(6,2) DEFAULT 0, service_hours_day DECIMAL(6,2) DEFAULT 24,
 average_demand_lps DECIMAL(16,4) DEFAULT 0, max_day_demand_lps DECIMAL(16,4) DEFAULT 0,
 peak_hour_demand_lps DECIMAL(16,4) DEFAULT 0, priority VARCHAR(30) DEFAULT 'sedang',
 description TEXT, is_public TINYINT(1) DEFAULT 0, created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS distribution_networks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, route_name VARCHAR(150) NOT NULL,
 origin_type ENUM('source','reservoir') NOT NULL, origin_id BIGINT UNSIGNED NOT NULL,
 destination_type ENUM('reservoir','service_area') NOT NULL, destination_id BIGINT UNSIGNED NOT NULL,
 pipe_length_m DECIMAL(16,2) DEFAULT 0, pipe_diameter_mm DECIMAL(12,2) DEFAULT 0, pipe_type VARCHAR(60),
 roughness_coefficient DECIMAL(12,4) DEFAULT 0, minor_loss_coefficient DECIMAL(12,4) DEFAULT 0, check_valve TINYINT(1) DEFAULT 0,
 start_elevation_m DECIMAL(10,2), end_elevation_m DECIMAL(10,2), elevation_difference_m DECIMAL(10,2),
 max_pipe_capacity_lps DECIMAL(16,4), planned_flow_lps DECIMAL(16,4), loss_percent DECIMAL(6,2) DEFAULT 0,
 pump_status VARCHAR(30) DEFAULT 'tanpa_pompa', pump_capacity_lps DECIMAL(16,4), pump_hours DECIMAL(6,2),
 flow_priority INT DEFAULT 1, status VARCHAR(30) DEFAULT 'aktif', description TEXT,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_network_origin(origin_type,origin_id), INDEX idx_network_destination(destination_type,destination_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS distribution_node_positions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 node_type VARCHAR(30) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 position_x DECIMAL(7,3) NOT NULL DEFAULT 50,
 position_y DECIMAL(7,3) NOT NULL DEFAULT 50,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_distribution_node(node_type,entity_id),
 FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS distribution_nodes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 node_type VARCHAR(30) NOT NULL DEFAULT 'junction',
 linked_type VARCHAR(30) NULL,
 linked_id BIGINT UNSIGNED NULL,
 elevation_m DECIMAL(10,2) DEFAULT 0,
 base_demand_lps DECIMAL(16,4) DEFAULT 0,
 initial_pressure_m DECIMAL(10,2) DEFAULT 0,
 minimum_pressure_m DECIMAL(10,2) DEFAULT 0,
 maximum_pressure_m DECIMAL(10,2) DEFAULT 0,
 emitter_coefficient DECIMAL(16,4) DEFAULT 0,
 demand_pattern VARCHAR(80) NULL, initial_quality DECIMAL(16,4) DEFAULT 0, source_quality DECIMAL(16,4) DEFAULT 0,
 total_head_m DECIMAL(12,3) DEFAULT 0, head_pattern VARCHAR(80) NULL,
 initial_level_m DECIMAL(12,3) DEFAULT 0, minimum_level_m DECIMAL(12,3) DEFAULT 0,
 maximum_level_m DECIMAL(12,3) DEFAULT 0, tank_diameter_m DECIMAL(12,3) DEFAULT 0,
 minimum_volume_m3 DECIMAL(16,3) DEFAULT 0, volume_curve VARCHAR(80) NULL, mixing_model VARCHAR(30) DEFAULT 'mixed',
 pump_curve VARCHAR(80) NULL, pump_power_kw DECIMAL(16,3) DEFAULT 0, pump_speed DECIMAL(10,4) DEFAULT 1,
 speed_pattern VARCHAR(80) NULL, valve_type VARCHAR(20) NULL, valve_setting DECIMAL(16,4) DEFAULT 0,
 meter_parameter VARCHAR(60) NULL, meter_unit VARCHAR(30) NULL,
 status VARCHAR(30) DEFAULT 'aktif',
 description TEXT,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_distribution_node_link(linked_type,linked_id)
) ENGINE=InnoDB;

ALTER TABLE distribution_networks MODIFY origin_type VARCHAR(30) NOT NULL;
ALTER TABLE distribution_networks MODIFY destination_type VARCHAR(30) NOT NULL;
ALTER TABLE distribution_node_positions MODIFY node_type VARCHAR(30) NOT NULL;
DROP PROCEDURE IF EXISTS add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE add_column_if_missing(IN table_name_value VARCHAR(64), IN column_name_value VARCHAR(64), IN definition_value TEXT)
BEGIN
 IF NOT EXISTS (
   SELECT 1 FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = table_name_value AND column_name = column_name_value
 ) THEN
   SET @alter_sql = CONCAT('ALTER TABLE `', table_name_value, '` ADD COLUMN `', column_name_value, '` ', definition_value);
   PREPARE alter_statement FROM @alter_sql;
   EXECUTE alter_statement;
   DEALLOCATE PREPARE alter_statement;
 END IF;
END$$
DELIMITER ;
CALL add_column_if_missing('distribution_networks','roughness_coefficient','DECIMAL(12,4) DEFAULT 0 AFTER `pipe_type`');
CALL add_column_if_missing('distribution_networks','minor_loss_coefficient','DECIMAL(12,4) DEFAULT 0 AFTER `roughness_coefficient`');
CALL add_column_if_missing('distribution_networks','check_valve','TINYINT(1) DEFAULT 0 AFTER `minor_loss_coefficient`');
CALL add_column_if_missing('distribution_nodes','demand_pattern','VARCHAR(80) NULL AFTER `emitter_coefficient`');
CALL add_column_if_missing('distribution_nodes','initial_quality','DECIMAL(16,4) DEFAULT 0 AFTER `demand_pattern`');
CALL add_column_if_missing('distribution_nodes','source_quality','DECIMAL(16,4) DEFAULT 0 AFTER `initial_quality`');
CALL add_column_if_missing('distribution_nodes','total_head_m','DECIMAL(12,3) DEFAULT 0 AFTER `source_quality`');
CALL add_column_if_missing('distribution_nodes','head_pattern','VARCHAR(80) NULL AFTER `total_head_m`');
CALL add_column_if_missing('distribution_nodes','initial_level_m','DECIMAL(12,3) DEFAULT 0 AFTER `head_pattern`');
CALL add_column_if_missing('distribution_nodes','minimum_level_m','DECIMAL(12,3) DEFAULT 0 AFTER `initial_level_m`');
CALL add_column_if_missing('distribution_nodes','maximum_level_m','DECIMAL(12,3) DEFAULT 0 AFTER `minimum_level_m`');
CALL add_column_if_missing('distribution_nodes','tank_diameter_m','DECIMAL(12,3) DEFAULT 0 AFTER `maximum_level_m`');
CALL add_column_if_missing('distribution_nodes','minimum_volume_m3','DECIMAL(16,3) DEFAULT 0 AFTER `tank_diameter_m`');
CALL add_column_if_missing('distribution_nodes','volume_curve','VARCHAR(80) NULL AFTER `minimum_volume_m3`');
CALL add_column_if_missing('distribution_nodes','mixing_model','VARCHAR(30) DEFAULT ''mixed'' AFTER `volume_curve`');
CALL add_column_if_missing('distribution_nodes','pump_curve','VARCHAR(80) NULL AFTER `mixing_model`');
CALL add_column_if_missing('distribution_nodes','pump_power_kw','DECIMAL(16,3) DEFAULT 0 AFTER `pump_curve`');
CALL add_column_if_missing('distribution_nodes','pump_speed','DECIMAL(10,4) DEFAULT 1 AFTER `pump_power_kw`');
CALL add_column_if_missing('distribution_nodes','speed_pattern','VARCHAR(80) NULL AFTER `pump_speed`');
CALL add_column_if_missing('distribution_nodes','valve_type','VARCHAR(20) NULL AFTER `speed_pattern`');
CALL add_column_if_missing('distribution_nodes','valve_setting','DECIMAL(16,4) DEFAULT 0 AFTER `valve_type`');
CALL add_column_if_missing('distribution_nodes','meter_parameter','VARCHAR(60) NULL AFTER `valve_setting`');
CALL add_column_if_missing('distribution_nodes','meter_unit','VARCHAR(30) NULL AFTER `meter_parameter`');
DROP PROCEDURE add_column_if_missing;

CREATE TABLE IF NOT EXISTS simulation_headers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_number VARCHAR(60) NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL, simulation_date DATE NOT NULL, period_label VARCHAR(100), season VARCHAR(30) NOT NULL,
 duration_type VARCHAR(30) NOT NULL, simulation_days INT NOT NULL DEFAULT 1, flow_mode VARCHAR(40) NOT NULL,
 status VARCHAR(30) DEFAULT 'draft', notes TEXT, created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 source_code_snapshot VARCHAR(50), source_name_snapshot VARCHAR(150), flow_mode VARCHAR(40),
 source_flow_lps DECIMAL(16,4), loss_percent DECIMAL(6,2), effective_flow_lps DECIMAL(16,4),
 priority INT DEFAULT 1, withdrawal_limit_lps DECIMAL(16,4), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE,
 FOREIGN KEY(source_id) REFERENCES water_sources(id), INDEX idx_sim_source(simulation_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_routes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL,
 origin_type VARCHAR(30), origin_id BIGINT UNSIGNED, origin_name_snapshot VARCHAR(150),
 destination_type VARCHAR(30), destination_id BIGINT UNSIGNED, destination_name_snapshot VARCHAR(150),
 allocation_percent DECIMAL(7,3), allocated_flow_lps DECIMAL(16,4), loss_percent DECIMAL(6,2),
 delivered_flow_lps DECIMAL(16,4), operation_hours DECIMAL(6,2), priority INT, notes TEXT,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_reservoirs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL, reservoir_id BIGINT UNSIGNED NOT NULL,
 reservoir_name_snapshot VARCHAR(150), effective_capacity_m3 DECIMAL(16,3), initial_volume_m3 DECIMAL(16,3),
 total_inflow_lps DECIMAL(16,4), total_outflow_lps DECIMAL(16,4), loss_percent DECIMAL(6,2),
 final_volume_m3 DECIMAL(16,3), fill_percent DECIMAL(8,2), overflow_m3 DECIMAL(16,3), service_duration_hours DECIMAL(16,2),
 empty_status TINYINT(1) DEFAULT 0, overflow_status TINYINT(1) DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE,
 FOREIGN KEY(reservoir_id) REFERENCES reservoirs(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_service_areas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL, service_area_id BIGINT UNSIGNED NOT NULL,
 area_name_snapshot VARCHAR(150), population_snapshot INT, average_demand_lps DECIMAL(16,4),
 max_day_demand_lps DECIMAL(16,4), peak_hour_demand_lps DECIMAL(16,4), design_demand_lps DECIMAL(16,4),
 allocated_flow_lps DECIMAL(16,4), delivered_flow_lps DECIMAL(16,4), difference_lps DECIMAL(16,4),
 fulfillment_percent DECIMAL(8,2), service_status VARCHAR(30), priority_snapshot VARCHAR(30),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE,
 FOREIGN KEY(service_area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_scenarios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NULL, scenario_name VARCHAR(180) NOT NULL,
 season VARCHAR(30), population_growth_percent DECIMAL(6,2) DEFAULT 0, source_reduction_percent DECIMAL(6,2) DEFAULT 0,
 assumptions TEXT, status VARCHAR(30) DEFAULT 'draft', created_by BIGINT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL, FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_scenario_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scenario_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 is_active TINYINT(1) DEFAULT 1, flow_lps DECIMAL(16,4), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(scenario_id) REFERENCES simulation_scenarios(id) ON DELETE CASCADE,
 FOREIGN KEY(source_id) REFERENCES water_sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_time_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL, step_number INT NOT NULL,
 step_time DATETIME, source_flow_lps DECIMAL(16,4), effective_flow_lps DECIMAL(16,4),
 reservoir_initial_m3 DECIMAL(16,3), reservoir_inflow_m3 DECIMAL(16,3), reservoir_outflow_m3 DECIMAL(16,3),
 reservoir_final_m3 DECIMAL(16,3), delivered_flow_lps DECIMAL(16,4), demand_flow_lps DECIMAL(16,4),
 surplus_deficit_lps DECIMAL(16,4), overflow_m3 DECIMAL(16,3), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE,
 UNIQUE KEY uq_sim_step(simulation_id,step_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_results (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, simulation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 total_source_flow_lps DECIMAL(16,4), total_effective_flow_lps DECIMAL(16,4), total_loss_lps DECIMAL(16,4),
 total_demand_lps DECIMAL(16,4), total_delivered_lps DECIMAL(16,4), surplus_deficit_lps DECIMAL(16,4),
 fulfillment_percent DECIMAL(8,2), initial_reservoir_m3 DECIMAL(16,3), final_reservoir_m3 DECIMAL(16,3),
 overflow_m3 DECIMAL(16,3), reservoir_endurance_hours DECIMAL(16,2), served_areas INT, shortage_areas INT,
 largest_source_name VARCHAR(150), highest_loss_route_name VARCHAR(150), result_status VARCHAR(30),
 recommendations JSON, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(simulation_id) REFERENCES simulation_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

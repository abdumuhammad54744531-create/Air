CREATE DATABASE IF NOT EXISTS monitoring_air CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE monitoring_air;

CREATE TABLE IF NOT EXISTS roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE,
 description VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS permissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE,
 module VARCHAR(100) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS role_permissions (
 role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(role_id,permission_id),
 FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE, FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL UNIQUE,
 email VARCHAR(150) NOT NULL UNIQUE, phone VARCHAR(30), position VARCHAR(100), institution VARCHAR(150), photo VARCHAR(255),
 password VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, must_change_password TINYINT(1) NOT NULL DEFAULT 0,
 last_login_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_roles (
 user_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(user_id,role_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS locations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, type VARCHAR(80) NOT NULL,
 province VARCHAR(100), city VARCHAR(100), district VARCHAR(100), village VARCHAR(100), address TEXT, latitude DECIMAL(10,7), longitude DECIMAL(10,7),
 elevation DECIMAL(10,2), person_in_charge VARCHAR(150), phone VARCHAR(30), email VARCHAR(150), photo VARCHAR(255), description TEXT,
 is_active TINYINT(1) DEFAULT 1, is_public TINYINT(1) DEFAULT 0, first_installed_at DATE NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 INDEX idx_location_status(is_active,is_public)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, location_id BIGINT UNSIGNED, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL,
 serial_number VARCHAR(100), brand VARCHAR(100), model VARCHAR(100), type VARCHAR(100), installed_at DATE, purchased_at DATE, manufacture_year YEAR,
 warranty_until DATE, vendor VARCHAR(150), contract_number VARCHAR(100), power_source VARCHAR(50), communication_type VARCHAR(50), ip_address VARCHAR(45),
 sim_number VARCHAR(50), network_operator VARCHAR(80), send_interval_seconds INT DEFAULT 300, firmware_version VARCHAR(50), token VARCHAR(255),
 status VARCHAR(40) DEFAULT 'belum_dipasang', connection_status VARCHAR(40) DEFAULT 'tidak_diketahui', last_data_at DATETIME, last_heartbeat_at DATETIME,
   battery_voltage DECIMAL(8,2), signal_strength INT, physical_condition VARCHAR(100), photo VARCHAR(255), manual_document VARCHAR(255), notes TEXT,
   google_sheet_url VARCHAR(500), google_sheet_gid VARCHAR(40), google_sheet_name VARCHAR(150),
 is_public TINYINT(1) DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL, INDEX idx_device_status(status,connection_status), INDEX idx_device_location(location_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sensors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL,
 parameter VARCHAR(80) NOT NULL, unit VARCHAR(30) NOT NULL, instrument_min DECIMAL(16,6), instrument_max DECIMAL(16,6),
 normal_min DECIMAL(16,6), normal_max DECIMAL(16,6), warning_min DECIMAL(16,6), warning_max DECIMAL(16,6),
 danger_min DECIMAL(16,6), danger_max DECIMAL(16,6), calibration_factor DECIMAL(16,8) DEFAULT 1, offset_value DECIMAL(16,8) DEFAULT 0,
 decimal_places TINYINT DEFAULT 2, reading_interval_seconds INT DEFAULT 300, status VARCHAR(30) DEFAULT 'aktif', is_public TINYINT(1) DEFAULT 0,
 display_order INT DEFAULT 0, chart_color VARCHAR(20) DEFAULT '#1d4ed8', icon VARCHAR(80), last_calibrated_at DATE, next_calibration_at DATE, notes TEXT,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE, INDEX idx_sensor_device(device_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS raw_device_payloads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, packet_number VARCHAR(100) NOT NULL,
 payload JSON NOT NULL, received_at DATETIME NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE, UNIQUE KEY uq_raw_packet(device_id,packet_number)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sensor_readings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, sensor_id BIGINT UNSIGNED NOT NULL, raw_payload_id BIGINT UNSIGNED,
 packet_number VARCHAR(100), recorded_at DATETIME NOT NULL, received_at DATETIME NOT NULL, raw_value DECIMAL(18,6) NOT NULL,
 calibrated_value DECIMAL(18,6) NOT NULL, unit VARCHAR(30), quality_status VARCHAR(30) DEFAULT 'normal', validation_status VARCHAR(30) DEFAULT 'belum_diperiksa',
 source VARCHAR(40) DEFAULT 'api_perangkat', signal_strength INT, battery_voltage DECIMAL(8,2), notes TEXT, validated_by BIGINT UNSIGNED, validated_at DATETIME,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(device_id) REFERENCES devices(id), FOREIGN KEY(sensor_id) REFERENCES sensors(id), FOREIGN KEY(raw_payload_id) REFERENCES raw_device_payloads(id) ON DELETE SET NULL,
 INDEX idx_reading_device(device_id), INDEX idx_reading_sensor(sensor_id), INDEX idx_reading_recorded(recorded_at), INDEX idx_reading_quality(quality_status),
 INDEX idx_reading_sensor_time(sensor_id,recorded_at), UNIQUE KEY uq_reading(device_id,packet_number,sensor_id,recorded_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS device_heartbeats (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, device_time DATETIME, status VARCHAR(30),
 battery_voltage DECIMAL(8,2), signal_strength INT, firmware_version VARCHAR(50), received_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(device_id) REFERENCES devices(id), INDEX idx_heartbeat_device_time(device_id,received_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS device_status_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, status VARCHAR(40), details JSON,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(device_id) REFERENCES devices(id), INDEX idx_status_device(device_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS api_keys (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, key_name VARCHAR(100), key_hash CHAR(64) NOT NULL UNIQUE,
 key_prefix VARCHAR(16), allowed_ip VARCHAR(45), is_active TINYINT(1) DEFAULT 1, expires_at DATETIME, last_used_at DATETIME,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS api_request_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED, endpoint VARCHAR(150), method VARCHAR(10), ip_address VARCHAR(45),
 http_status SMALLINT, request_payload JSON, response_payload JSON, duration_ms INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE SET NULL, INDEX idx_api_created(created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS alert_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sensor_id BIGINT UNSIGNED, name VARCHAR(150), operator VARCHAR(10), threshold_value DECIMAL(18,6),
 priority VARCHAR(20), is_active TINYINT(1) DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME, FOREIGN KEY(sensor_id) REFERENCES sensors(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS alerts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED, sensor_id BIGINT UNSIGNED, occurred_at DATETIME, alert_type VARCHAR(100),
 value DECIMAL(18,6), threshold_value DECIMAL(18,6), priority VARCHAR(20), message TEXT, status VARCHAR(30) DEFAULT 'baru',
 read_by BIGINT UNSIGNED, read_at DATETIME, confirmed_by BIGINT UNSIGNED, confirmed_at DATETIME, action_taken TEXT, notes TEXT,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
 FOREIGN KEY(device_id) REFERENCES devices(id), FOREIGN KEY(sensor_id) REFERENCES sensors(id), INDEX idx_alert_status(status,priority)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS alert_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, alert_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED, action VARCHAR(100), notes TEXT,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(alert_id) REFERENCES alerts(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maintenances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, maintenance_number VARCHAR(50) NOT NULL UNIQUE, device_id BIGINT UNSIGNED, maintenance_type VARCHAR(100),
 planned_date DATE, performed_date DATE, technician_name VARCHAR(150), condition_before TEXT, action_taken TEXT, spare_parts TEXT, cost DECIMAL(15,2),
 condition_after TEXT, inspection_result TEXT, next_schedule DATE, photo_before VARCHAR(255), photo_after VARCHAR(255), document VARCHAR(255),
 status VARCHAR(40), notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
 FOREIGN KEY(device_id) REFERENCES devices(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maintenance_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, maintenance_id BIGINT UNSIGNED NOT NULL, item_name VARCHAR(150), quantity DECIMAL(12,2), unit VARCHAR(30), cost DECIMAL(15,2),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(maintenance_id) REFERENCES maintenances(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS damage_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_number VARCHAR(50) NOT NULL UNIQUE, device_id BIGINT UNSIGNED, reported_at DATETIME,
 reporter_name VARCHAR(150), damage_type VARCHAR(100), severity VARCHAR(30), description TEXT, photo VARCHAR(255), status VARCHAR(40),
 technician_name VARCHAR(150), started_at DATETIME, completed_at DATETIME, cause TEXT, action_taken TEXT, cost DECIMAL(15,2), result TEXT, notes TEXT,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
 FOREIGN KEY(device_id) REFERENCES devices(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS calibrations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, calibration_number VARCHAR(50) NOT NULL UNIQUE, sensor_id BIGINT UNSIGNED, calibrated_at DATE,
 technician_name VARCHAR(150), method VARCHAR(150), reference_instrument VARCHAR(150), before_value DECIMAL(18,6), reference_value DECIMAL(18,6),
 after_value DECIMAL(18,6), calibration_factor DECIMAL(16,8), offset_value DECIMAL(16,8), result VARCHAR(40), next_calibration_at DATE,
 certificate VARCHAR(255), notes TEXT, approved_by BIGINT UNSIGNED, approved_at DATETIME,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
 FOREIGN KEY(sensor_id) REFERENCES sensors(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS public_settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS public_contents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_key VARCHAR(100) UNIQUE, title VARCHAR(200), content LONGTEXT, status VARCHAR(30),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS announcements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, category VARCHAR(50),
 summary TEXT, content LONGTEXT, featured_image VARCHAR(255), attachment VARCHAR(255), author_id BIGINT UNSIGNED, published_at DATETIME,
 status VARCHAR(30) DEFAULT 'draft', show_on_home TINYINT(1) DEFAULT 0, display_order INT DEFAULT 0, view_count INT DEFAULT 0,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME,
 FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS report_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, report_type VARCHAR(100), filters JSON, format VARCHAR(20),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, action VARCHAR(100), module VARCHAR(100), reference_id BIGINT,
 data_before JSON, data_after JSON, ip_address VARCHAR(45), user_agent VARCHAR(500), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_activity_user(user_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS application_settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT, setting_type VARCHAR(30) DEFAULT 'text',
 description VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS backups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255), file_size BIGINT, status VARCHAR(30), created_by BIGINT UNSIGNED,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, identity VARCHAR(150), ip_address VARCHAR(45), user_agent VARCHAR(500), successful TINYINT(1),
 attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_login_limit(ip_address,successful,attempted_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, token_hash CHAR(64), expires_at DATETIME, used_at DATETIME,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

USE monitoring_air;
INSERT IGNORE INTO roles(name,slug,description) VALUES
('Super Administrator','super_admin','Akses penuh'),('Administrator','administrator','Pengelola sistem'),
('Operator','operator','Petugas lapangan'),('Pimpinan','pimpinan','Akses baca');
INSERT IGNORE INTO permissions(name,slug,module) VALUES
('Lihat Dashboard','dashboard.view','dashboard'),('Kelola Lokasi','locations.manage','locations'),('Kelola Alat','devices.manage','devices'),
('Kelola Sensor','sensors.manage','sensors'),('Lihat Laporan','reports.view','reports'),('Kelola Pengguna','users.manage','users');
INSERT IGNORE INTO users(id,name,username,email,password,is_active,must_change_password) VALUES
(1,'Super Administrator','admin','admin@localhost.test','$2y$10$fZOZ5GDLjBo54hcQZkeHEOrGQibGFFGCVvQK0K.u9y5lEqd/nus4K',1,1);
INSERT IGNORE INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE slug='super_admin';
INSERT IGNORE INTO locations(id,code,name,type,province,city,district,village,address,latitude,longitude,elevation,person_in_charge,is_active,is_public) VALUES
(1,'LOK-001','Mata Air Tirta Sejahtera','Mata air','Sulawesi Tenggara','Kota Kendari','Poasia','Anduonohu','Kawasan sumber mata air',-3.998459,122.526300,42,'Ahmad Rahman',1,1),
(2,'LOK-002','Bendungan Wawotobi','Bendungan','Sulawesi Tenggara','Konawe','Wawotobi','Wawotobi','Area bendungan utama',-3.866700,122.050000,35,'Nur Aisyah',1,1),
(3,'LOK-003','IPA Sungai Wanggu','Instalasi pengolahan air','Sulawesi Tenggara','Kota Kendari','Kadia','Wanggu','Instalasi pengolahan air',-3.995000,122.510000,18,'La Ode Hasan',1,1);
INSERT IGNORE INTO devices(id,location_id,code,name,serial_number,brand,model,type,installed_at,power_source,communication_type,send_interval_seconds,firmware_version,status,connection_status,last_data_at,last_heartbeat_at,battery_voltage,signal_strength,is_public) VALUES
(1,1,'ALT-001','Logger Mata Air Utama','SN-AIR-001','HydroSense','HS-200','Data logger','2026-01-10','Panel surya','4G',300,'1.0.5','aktif','online',NOW(),NOW(),12.4,-72,1),
(2,1,'ALT-002','Sensor Kualitas Terpadu','SN-AIR-002','AquaTech','AQ-7','Sensor kualitas air','2026-01-10','Kombinasi','LoRaWAN',300,'2.1.0','aktif','online',NOW(),NOW(),12.1,-68,1),
(3,2,'ALT-003','AWLR Bendungan','SN-AIR-003','HydroSense','AWLR-X','Automatic Water Level Recorder','2026-02-15','Panel surya','4G',600,'1.2.1','aktif','online',NOW(),NOW(),13.0,-65,1),
(4,3,'ALT-004','Flow Meter IPA','SN-AIR-004','FlowPro','FP-100','Flow meter','2026-03-01','PLN','LAN',300,'3.0.0','dalam_perawatan','offline',DATE_SUB(NOW(),INTERVAL 2 DAY),DATE_SUB(NOW(),INTERVAL 2 DAY),11.0,-90,1),
(5,3,'ALT-005','Sensor Outlet IPA','SN-AIR-005','AquaTech','AQ-5','Sensor kualitas air','2026-03-01','PLN','Wi-Fi',300,'2.0.3','aktif','online',NOW(),NOW(),12.2,-60,1);
INSERT IGNORE INTO sensors(id,device_id,code,name,parameter,unit,normal_min,normal_max,warning_min,warning_max,danger_min,danger_max,calibration_factor,offset_value,status,is_public,chart_color) VALUES
(1,1,'TMA-001','Tinggi Muka Air','tinggi_muka_air','m',0.4,2.0,0.25,2.5,0.1,3.0,1,0,'aktif',1,'#2563eb'),
(2,1,'DEB-001','Debit Sumber','debit','L/s',43.83,60,38,65,30,75,1,0,'aktif',1,'#0891b2'),
(3,2,'PH-001','Derajat Keasaman','ph','pH',6.5,8.5,6,9,5,10,1,0,'aktif',1,'#7c3aed'),
(4,2,'SUH-001','Suhu Air','suhu_air','°C',20,30,18,32,15,35,1,0,'aktif',1,'#f59e0b'),
(5,2,'TDS-001','Total Dissolved Solids','tds','mg/L',0,500,0,600,0,800,1,0,'aktif',1,'#10b981');
INSERT IGNORE INTO api_keys(device_id,key_name,key_hash,key_prefix,is_active) VALUES
(1,'Kunci utama ALT-001',SHA2('demo-api-key-alt-001',256),'demo-api',1);
INSERT IGNORE INTO announcements(title,slug,category,summary,content,author_id,published_at,status,show_on_home) VALUES
('Pemantauan kualitas air berjalan normal','pemantauan-berjalan-normal','Pengumuman','Seluruh sensor utama beroperasi dan data diperbarui otomatis.','Sistem monitoring telah menerima data dari perangkat secara berkala.',1,NOW(),'terbit',1),
('Jadwal pemeliharaan Flow Meter IPA','jadwal-pemeliharaan-flow-meter','Pemeliharaan','Pemeliharaan terjadwal dilakukan minggu ini.','Perangkat tetap dipantau oleh operator selama pemeliharaan.',1,NOW(),'terbit',1);
INSERT IGNORE INTO maintenances(maintenance_number,device_id,maintenance_type,planned_date,technician_name,status,notes) VALUES
('PM-2026-001',4,'Pemeriksaan rutin',CURDATE(),'Teknisi Lapangan','dijadwalkan','Pemeriksaan sambungan dan kalibrasi flow meter.');
INSERT IGNORE INTO alerts(device_id,sensor_id,occurred_at,alert_type,value,threshold_value,priority,message,status) VALUES
(4,NULL,NOW(),'alat_offline',NULL,NULL,'tinggi','Flow Meter IPA tidak mengirim data selama lebih dari batas offline.','baru');

DELIMITER //
CREATE PROCEDURE seed_readings()
BEGIN
 DECLARE d INT DEFAULT 0;
 WHILE d < 7 DO
   INSERT IGNORE INTO raw_device_payloads(device_id,packet_number,payload,received_at) VALUES
   (1,CONCAT('SEED-',d),JSON_OBJECT('source','seeder'),DATE_SUB(NOW(),INTERVAL d DAY));
   SET @rid = LAST_INSERT_ID();
   INSERT IGNORE INTO sensor_readings(device_id,sensor_id,raw_payload_id,packet_number,recorded_at,received_at,raw_value,calibrated_value,unit,quality_status,validation_status,source)
   VALUES
   (1,1,@rid,CONCAT('SEED-',d),DATE_SUB(NOW(),INTERVAL d DAY),DATE_SUB(NOW(),INTERVAL d DAY),0.48+(d*0.02),0.48+(d*0.02),'m','normal','valid','sinkronisasi'),
   (1,2,@rid,CONCAT('SEED-',d),DATE_SUB(NOW(),INTERVAL d DAY),DATE_SUB(NOW(),INTERVAL d DAY),45.72-(d*0.55),45.72-(d*0.55),'L/s','normal','valid','sinkronisasi'),
   (2,3,NULL,CONCAT('SEED-PH-',d),DATE_SUB(NOW(),INTERVAL d DAY),DATE_SUB(NOW(),INTERVAL d DAY),7.2+(d*0.05),7.2+(d*0.05),'pH','normal','valid','sinkronisasi'),
   (2,4,NULL,CONCAT('SEED-SUH-',d),DATE_SUB(NOW(),INTERVAL d DAY),DATE_SUB(NOW(),INTERVAL d DAY),25.5+(d*0.1),25.5+(d*0.1),'°C','normal','valid','sinkronisasi'),
   (2,5,NULL,CONCAT('SEED-TDS-',d),DATE_SUB(NOW(),INTERVAL d DAY),DATE_SUB(NOW(),INTERVAL d DAY),258+(d*2),258+(d*2),'mg/L','normal','valid','sinkronisasi');
   SET d = d + 1;
 END WHILE;
END//
DELIMITER ;
CALL seed_readings();
DROP PROCEDURE seed_readings;
INSERT IGNORE INTO application_settings(setting_key,setting_value,setting_type,description) VALUES
('app_name','Sistem Informasi Monitoring dan Manajemen Air','text','Nama aplikasi'),
('institution_name','Instansi Pengelola Sumber Daya Air','text','Nama instansi'),
('dashboard_refresh_seconds','30','number','Interval pembaruan dashboard'),
('device_offline_minutes','15','number','Batas perangkat dinyatakan offline'),
('public_page_enabled','1','boolean','Status halaman publik');


<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleSheetSensorService;

final class CrudController
{
    private array $definitions = [
        'locations'=>['table'=>'locations','title'=>'Data Lokasi','fields'=>['code'=>'Kode Lokasi','name'=>'Nama Lokasi','type'=>'Jenis Lokasi','province'=>'Provinsi','city'=>'Kabupaten/Kota','district'=>'Kecamatan','village'=>'Desa/Kelurahan','address'=>'Alamat','latitude'=>'Latitude','longitude'=>'Longitude','elevation'=>'Ketinggian (m)','person_in_charge'=>'Penanggung Jawab','phone'=>'Nomor Telepon','email'=>'Email','photo'=>'Foto Dokumentasi','description'=>'Deskripsi','is_active'=>'Status Aktif','is_public'=>'Tampil Publik']],
        'devices'=>['table'=>'devices','title'=>'Data Alat','fields'=>['code'=>'Kode Alat','name'=>'Nama Alat','serial_number'=>'Nomor Seri','brand'=>'Merek','model'=>'Model','type'=>'Jenis Alat','location_id'=>'Lokasi Sumber Air','water_source_id'=>'Sumber Air / Penampang','google_sheet_url'=>'URL Google Sheet Data Sensor','google_sheet_gid'=>'ID Tab / GID Sheet','google_sheet_name'=>'Nama Tab Sheet','installed_at'=>'Tanggal Pemasangan','power_source'=>'Sumber Daya','communication_type'=>'Komunikasi','send_interval_seconds'=>'Interval Kirim (detik)','firmware_version'=>'Firmware','status'=>'Status','is_public'=>'Tampil Publik']],
        'sensors'=>['table'=>'sensors','title'=>'Data Sensor','fields'=>['code'=>'Kode Sensor','name'=>'Nama Sensor','device_id'=>'Alat','parameter'=>'Parameter','unit'=>'Satuan','normal_min'=>'Normal Min','normal_max'=>'Normal Maks','warning_min'=>'Waspada Min','warning_max'=>'Waspada Maks','danger_min'=>'Bahaya Min','danger_max'=>'Bahaya Maks','calibration_factor'=>'Faktor Kalibrasi','offset_value'=>'Offset','decimal_places'=>'Desimal','status'=>'Status','is_public'=>'Tampil Publik','chart_color'=>'Warna Grafik']],
        'monitoring'=>['table'=>'sensor_readings','title'=>'Monitoring Data','readonly'=>true,'fields'=>[]],
        'alerts'=>['table'=>'alerts','title'=>'Peringatan','readonly'=>true,'fields'=>[]],
        'maintenances'=>['table'=>'maintenances','title'=>'Pemeliharaan','fields'=>['maintenance_number'=>'Nomor','device_id'=>'Alat','maintenance_type'=>'Jenis','planned_date'=>'Tanggal Rencana','performed_date'=>'Tanggal Pelaksanaan','technician_name'=>'Petugas','action_taken'=>'Tindakan','cost'=>'Biaya','next_schedule'=>'Jadwal Berikutnya','status'=>'Status','notes'=>'Catatan']],
        'damages'=>['table'=>'damage_reports','title'=>'Kerusakan Alat','fields'=>['report_number'=>'Nomor Laporan','device_id'=>'Alat','reported_at'=>'Tanggal Laporan','reporter_name'=>'Pelapor','damage_type'=>'Jenis Kerusakan','severity'=>'Tingkat','description'=>'Deskripsi','technician_name'=>'Teknisi','status'=>'Status','action_taken'=>'Tindakan','cost'=>'Biaya','notes'=>'Catatan']],
        'calibrations'=>['table'=>'calibrations','title'=>'Kalibrasi Sensor','fields'=>['calibration_number'=>'Nomor Kalibrasi','sensor_id'=>'Sensor','calibrated_at'=>'Tanggal','technician_name'=>'Petugas','method'=>'Metode','before_value'=>'Nilai Sebelum','reference_value'=>'Nilai Referensi','after_value'=>'Nilai Sesudah','calibration_factor'=>'Faktor','offset_value'=>'Offset','result'=>'Hasil','next_calibration_at'=>'Kalibrasi Berikutnya','notes'=>'Catatan']],
        'users'=>['table'=>'users','title'=>'Pengguna','roles'=>['super_admin'],'fields'=>['name'=>'Nama Lengkap','username'=>'Username','email'=>'Email','phone'=>'Telepon','position'=>'Jabatan','institution'=>'Instansi','is_active'=>'Aktif']],
        'announcements'=>['table'=>'announcements','title'=>'Informasi Publik','fields'=>['title'=>'Judul','slug'=>'Slug','category'=>'Kategori','summary'=>'Ringkasan','content'=>'Isi','published_at'=>'Tanggal Publikasi','status'=>'Status','show_on_home'=>'Tampil di Beranda']],
        'water-sources'=>['table'=>'water_sources','title'=>'Data Sumber Air','fields'=>['location_id'=>'Lokasi','sensor_id'=>'Sensor Debit','code'=>'Kode Sumber','name'=>'Nama Sumber','source_type'=>'Jenis Sumber','latitude'=>'Latitude','longitude'=>'Longitude','elevation_m'=>'Elevasi (m)','min_flow_lps'=>'Debit Minimum (L/s)','normal_flow_lps'=>'Debit Normal (L/s)','max_flow_lps'=>'Debit Maksimum (L/s)','current_sensor_flow_lps'=>'Debit Sensor Terkini','measurement_season'=>'Musim Pengukuran','water_quality'=>'Kualitas Air','status'=>'Status','source_loss_percent'=>'Kehilangan Sumber (%)','last_measured_at'=>'Pengukuran Terakhir','description'=>'Keterangan','is_public'=>'Tampil Publik']],
        'reservoirs'=>['table'=>'reservoirs','title'=>'Data Reservoir','fields'=>['location_id'=>'Lokasi','code'=>'Kode Reservoir','name'=>'Nama Reservoir','reservoir_type'=>'Jenis Reservoir','latitude'=>'Latitude','longitude'=>'Longitude','elevation_m'=>'Elevasi (m)','length_m'=>'Panjang Bak (m)','width_m'=>'Lebar Bak (m)','height_m'=>'Tinggi Bak (m)','geometric_volume_m3'=>'Volume Geometris (m³)','effective_percent'=>'Volume Efektif (%)','effective_capacity_m3'=>'Kapasitas Efektif (m³)','minimum_operational_m3'=>'Volume Minimum (m³)','initial_volume_m3'=>'Volume Awal (m³)','initial_water_level_m'=>'Tinggi Air Awal (m)','max_inflow_lps'=>'Debit Masuk Maksimum','max_outflow_lps'=>'Debit Keluar Maksimum','loss_percent'=>'Kehilangan (%)','status'=>'Status','description'=>'Keterangan']],
        'service-areas'=>['table'=>'service_areas','title'=>'Data Wilayah Layanan','fields'=>['code'=>'Kode Wilayah','name'=>'Nama Wilayah','population'=>'Jumlah Penduduk','house_connections'=>'Sambungan Rumah','public_facilities'=>'Fasilitas Umum','liters_per_person_day'=>'Kebutuhan/orang/hari','public_facility_liters_day'=>'Kebutuhan Fasilitas (L/hari)','max_day_factor'=>'Faktor Hari Maksimum','peak_hour_factor'=>'Faktor Jam Puncak','network_loss_percent'=>'Kehilangan Jaringan (%)','service_hours_day'=>'Jam Pelayanan/hari','average_demand_lps'=>'Debit Rata-rata','max_day_demand_lps'=>'Debit Hari Maksimum','peak_hour_demand_lps'=>'Debit Jam Puncak','priority'=>'Prioritas','description'=>'Keterangan','is_public'=>'Tampil Publik']],
        'distribution-networks'=>['table'=>'distribution_networks','title'=>'Jaringan Distribusi','fields'=>['route_name'=>'Nama Jalur','origin_type'=>'Jenis Titik Asal','origin_id'=>'ID Titik Asal','destination_type'=>'Jenis Tujuan','destination_id'=>'ID Tujuan','pipe_length_m'=>'Panjang Pipa (m)','pipe_diameter_mm'=>'Diameter Pipa (mm)','pipe_type'=>'Jenis Pipa','start_elevation_m'=>'Elevasi Awal','end_elevation_m'=>'Elevasi Akhir','elevation_difference_m'=>'Beda Elevasi','max_pipe_capacity_lps'=>'Kapasitas Maksimum Pipa','planned_flow_lps'=>'Debit Rencana','loss_percent'=>'Kehilangan (%)','pump_status'=>'Status Pompa','pump_capacity_lps'=>'Kapasitas Pompa','pump_hours'=>'Jam Operasi Pompa','flow_priority'=>'Prioritas Aliran','status'=>'Status','description'=>'Keterangan']],
        'simulation-scenarios'=>['table'=>'simulation_scenarios','title'=>'Skenario Alternatif','fields'=>['scenario_name'=>'Nama Skenario','season'=>'Musim','population_growth_percent'=>'Pertumbuhan Penduduk (%)','source_reduction_percent'=>'Penurunan Debit Sumber (%)','assumptions'=>'Asumsi','status'=>'Status']],
        'activity-logs'=>['table'=>'activity_logs','title'=>'Log Aktivitas','roles'=>['super_admin'],'readonly'=>true,'fields'=>[]],
        'settings'=>['table'=>'application_settings','title'=>'Pengaturan Aplikasi','roles'=>['super_admin'],'fields'=>['setting_key'=>'Kunci','setting_value'=>'Nilai','setting_type'=>'Tipe','description'=>'Keterangan']],
    ];

    private array $requiredFields = [
        'locations'=>['code','name','type','province','city','latitude','longitude'],
        'devices'=>['code','name','type','location_id','power_source','communication_type','send_interval_seconds','status'],
        'sensors'=>['code','name','device_id','parameter','unit','calibration_factor','offset_value','decimal_places','status'],
        'maintenances'=>['maintenance_number','device_id','maintenance_type','planned_date','technician_name','status'],
        'damages'=>['report_number','device_id','reported_at','reporter_name','damage_type','severity','description','status'],
        'calibrations'=>['calibration_number','sensor_id','calibrated_at','technician_name','method','result'],
        'users'=>['name','username','email'],
        'announcements'=>['title','slug','category','summary','content','status'],
        'settings'=>['setting_key','setting_value','setting_type'],
        'water-sources'=>['location_id','code','name','source_type','min_flow_lps','normal_flow_lps','max_flow_lps','status'],
        'reservoirs'=>['code','name','reservoir_type','length_m','width_m','height_m','effective_percent','status'],
        'service-areas'=>['code','name','population','liters_per_person_day','max_day_factor','peak_hour_factor','network_loss_percent','service_hours_day','priority'],
        'distribution-networks'=>['route_name','origin_type','origin_id','destination_type','destination_id','pipe_length_m','pipe_diameter_mm','max_pipe_capacity_lps','status'],
        'simulation-scenarios'=>['scenario_name','season','status'],
    ];

    public function handle(string $module, string $method, ?int $id): void
    {
        $def = $this->definitions[$module] ?? null;
        if (!$def) { http_response_code(404); return; }
        $def['required'] = $this->requiredFields[$module] ?? [];
        require_auth($def['roles'] ?? []);
        if ($module === 'locations') $this->ensureLocationPhotoSchema();
        if ($module === 'devices') $this->ensureDeviceSourceField();
        if (in_array($module, ['devices', 'sensors'], true)) (new GoogleSheetSensorService())->ensureSchema();
        if ($module === 'sensors' && $method === 'GET') { $this->sensorSheetIndex(); return; }
        if ($method === 'DELETE' || ($method === 'POST' && ($_POST['_method'] ?? '') === 'DELETE')) { $this->delete($module,$def,$id); return; }
        if ($method === 'POST') { $this->store($module, $def, $id); return; }
        $this->index($module, $def, $id);
    }

    public function googleSheetData(): void
    {
        require_auth();
        $deviceId = isset($_GET['device_id']) && ctype_digit((string)$_GET['device_id']) ? (int)$_GET['device_id'] : null;
        try {
            json_response((new GoogleSheetSensorService())->readings($deviceId));
        } catch (\Throwable $e) {
            json_response(['rows'=>[], 'errors'=>[['message'=>'Data Google Sheet belum dapat dimuat.']], 'updated_at'=>date('Y-m-d H:i:s'), 'device_count'=>0], 503);
        }
    }

    private function sensorSheetIndex(): void
    {
        $service = new GoogleSheetSensorService();
        $devices = $service->devices();
        $deviceId = isset($_GET['device_id']) && ctype_digit((string)$_GET['device_id']) ? (int)$_GET['device_id'] : null;
        if (!$deviceId && $devices) $deviceId = (int)$devices[0]['id'];
        $selectedDevice = null;
        foreach ($devices as $device) if ((int)$device['id'] === $deviceId) { $selectedDevice = $device; break; }
        try {
            $sheetData = $service->readings($deviceId);
        } catch (\Throwable $e) {
            $sheetData = ['rows'=>[], 'errors'=>[['message'=>'Data Google Sheet belum dapat dimuat. Pastikan URL sheet dapat diakses publik.']], 'updated_at'=>date('Y-m-d H:i:s'), 'device_count'=>0];
        }
        view('water/google-sheet-sensors', compact('sheetData', 'devices', 'deviceId', 'selectedDevice') + ['title'=>'Data Sensor']);
    }

    private function index(string $module, array $def, ?int $id): void
    {
        $q = trim($_GET['q'] ?? '');
        $table = $def['table'];
        $where = in_array($table,['activity_logs','sensor_readings'],true) ? '1=1' : 'deleted_at IS NULL';
        $params = [];
        if ($q && !empty($def['fields'])) {
            $searchable = array_slice(array_keys($def['fields']),0,4);
            $where .= ' AND (' . implode(' OR ', array_map(fn($f)=>"`$f` LIKE ?", $searchable)) . ')';
            $params = array_fill(0,count($searchable),"%{$q}%");
        }
        $rows = Database::query("SELECT * FROM `$table` WHERE $where ORDER BY id DESC LIMIT 100", $params)->fetchAll();
        $record = $id ? Database::query("SELECT * FROM `$table` WHERE id=?",[$id])->fetch() : null;
        $lookups = [
            'locations'=>Database::query("SELECT id,name FROM locations WHERE deleted_at IS NULL ORDER BY name")->fetchAll(),
            'devices'=>Database::query("SELECT id,name FROM devices WHERE deleted_at IS NULL ORDER BY name")->fetchAll(),
            'sensors'=>Database::query("SELECT id,name FROM sensors WHERE deleted_at IS NULL ORDER BY name")->fetchAll(),
            'water_sources'=>Database::query("SELECT ws.id,ws.code,ws.name,ws.location_id,l.name location_name FROM water_sources ws LEFT JOIN locations l ON l.id=ws.location_id WHERE ws.deleted_at IS NULL ORDER BY ws.name")->fetchAll(),
        ];
        $locationPhotos=[];
        if ($module === 'locations' && $record) {
            $locationPhotos=Database::query("SELECT id,photo_path,caption,sort_order FROM location_photos WHERE location_id=? AND deleted_at IS NULL ORDER BY sort_order,id",[$record['id']])->fetchAll();
        }
        view('crud/index', compact('module','def','rows','record','lookups','locationPhotos') + ['title'=>$def['title']]);
    }

    private function store(string $module, array $def, ?int $id): void
    {
        if (!empty($def['readonly'])) { http_response_code(405); return; }
        verify_csrf();
        $deletePhotoIds=$module==='locations' ? array_values(array_filter(array_map('intval',(array)($_POST['delete_photo_ids']??[])))) : [];
        $removeLegacyPhoto=$module==='locations' && isset($_POST['remove_legacy_photo']);
        $legacyPhoto=$module==='locations' && $id ? (string)(Database::query("SELECT photo FROM locations WHERE id=?",[$id])->fetch()['photo']??'') : '';
        $data = [];
        foreach ($def['fields'] as $field=>$label) {
            if (in_array($field,['is_active','is_public','show_on_home'],true)) $data[$field] = isset($_POST[$field]) ? 1 : 0;
            elseif (array_key_exists($field,$_POST)) $data[$field] = trim((string)$_POST[$field]) === '' ? null : trim((string)$_POST[$field]);
        }
        $uploadedPhotos=[];
        if ($module === 'locations' && isset($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
            $files=$_FILES['photos']; $directory=\App\Core\App::ROOT.'/public/uploads/locations';
            if (!is_dir($directory)) mkdir($directory,0775,true);
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            foreach ($files['name'] as $index=>$name) {
                if ((int)($files['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
                if ((int)($files['error'][$index]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($files['tmp_name'][$index]??''))) { flash('danger','Salah satu foto dokumentasi gagal diunggah.'); redirect($module . ($id ? '/' . $id : '')); }
                if ((int)($files['size'][$index]??0)>5*1024*1024) { flash('danger','Ukuran setiap foto maksimal 5 MB.'); redirect($module . ($id ? '/' . $id : '')); }
                $mime=(new \finfo(FILEINFO_MIME_TYPE))->file((string)$files['tmp_name'][$index]);
                if (!isset($extensions[$mime])) { flash('danger','Foto harus berformat JPG, PNG, atau WEBP.'); redirect($module . ($id ? '/' . $id : '')); }
                $filename='location-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$extensions[$mime];
                if (!move_uploaded_file((string)$files['tmp_name'][$index],$directory.'/'.$filename)) { flash('danger','Foto dokumentasi tidak dapat disimpan.'); redirect($module . ($id ? '/' . $id : '')); }
                $uploadedPhotos[]='uploads/locations/'.$filename;
            }
            if ($uploadedPhotos) $data['photo']=$uploadedPhotos[0];
        } elseif ($module === 'locations' && $id) unset($data['photo']);
        foreach ($def['required'] ?? [] as $field) {
            if (!array_key_exists($field,$data) || $data[$field] === null || $data[$field] === '') {
                flash('danger','Kolom ' . ($def['fields'][$field] ?? $field) . ' wajib diisi.');
                redirect($module . ($id ? '/' . $id : ''));
            }
        }
        foreach (['latitude'=>[-90,90],'longitude'=>[-180,180]] as $field=>$range) {
            if (!array_key_exists($field,$data) || $data[$field] === null) continue;
            $normalized = str_replace(',','.',(string)$data[$field]);
            if (!is_numeric($normalized)) {
                flash('danger','Format ' . $def['fields'][$field] . ' tidak valid. Gunakan angka desimal, contoh: -3.998459.');
                redirect($module . ($id ? '/' . $id : ''));
            }
            $coordinate = (float)$normalized;
            if ($coordinate < $range[0] || $coordinate > $range[1]) {
                flash('danger',$def['fields'][$field] . " harus berada antara {$range[0]} dan {$range[1]}.");
                redirect($module . ($id ? '/' . $id : ''));
            }
            $data[$field] = $coordinate;
        }
        if ($module === 'reservoirs') {
            $geometric = (float)($data['length_m']??0) * (float)($data['width_m']??0) * (float)($data['height_m']??0);
            $data['geometric_volume_m3'] = round($geometric,3);
            $data['effective_capacity_m3'] = round($geometric * (float)($data['effective_percent']??100) / 100,3);
        }
        if ($module === 'devices' && !empty($data['water_source_id'])) {
            $source=Database::query("SELECT location_id FROM water_sources WHERE id=? AND deleted_at IS NULL",[(int)$data['water_source_id']])->fetch();
            if (!$source || (int)$source['location_id']!==(int)($data['location_id']??0)) {
                flash('danger','Sumber air/penampang yang dipilih harus berada pada Lokasi Sumber Air yang sama.');
                redirect($module . ($id ? '/' . $id : ''));
            }
        }
        if ($module === 'service-areas') {
            $daily = (float)($data['population']??0) * (float)($data['liters_per_person_day']??0) + (float)($data['public_facility_liters_day']??0);
            $average = $daily / 86400;
            $data['average_demand_lps'] = round($average,4);
            $data['max_day_demand_lps'] = round($average * (float)($data['max_day_factor']??1),4);
            $data['peak_hour_demand_lps'] = round($average * (float)($data['peak_hour_factor']??1),4);
        }
        if ($module === 'distribution-networks') {
            if (($data['origin_type']??'') === 'service_area' || ($data['destination_type']??'') === 'source') {
                flash('danger','Arah jaringan tidak diizinkan. Wilayah hanya dapat menjadi tujuan dan sumber air tidak dapat menjadi tujuan.');
                redirect($module . ($id ? '/' . $id : ''));
            }
            if (($data['origin_type']??'') === ($data['destination_type']??'') && (int)($data['origin_id']??0) === (int)($data['destination_id']??0)) {
                flash('danger','Titik asal dan tujuan tidak boleh sama.'); redirect($module . ($id ? '/' . $id : ''));
            }
            $data['elevation_difference_m'] = round((float)($data['start_elevation_m']??0) - (float)($data['end_elevation_m']??0),2);
        }
        if (isset($data['email']) && $data['email'] && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) { flash('danger','Format email tidak valid.'); redirect($module); }
        $table = $def['table'];
        try {
            $savedId=$id;
            if ($id) {
                $before = Database::query("SELECT * FROM `$table` WHERE id=?",[$id])->fetch();
                $sets = implode(',',array_map(fn($f)=>"`$f`=?",array_keys($data)));
                Database::query("UPDATE `$table` SET $sets,updated_at=NOW() WHERE id=?", [...array_values($data),$id]);
                activity('edit',$module,$id,$before,$data);
            } else {
                if ($module === 'users') {
                    $data['password'] = password_hash('Ganti123!', PASSWORD_DEFAULT); $data['must_change_password']=1;
                }
                if (in_array($module,['water-sources','reservoirs','service-areas','distribution-networks','simulation-scenarios'],true)) {
                    $data['created_by'] = user()['id'];
                }
                $fields = array_keys($data);
                $sql = "INSERT INTO `$table` (`".implode('`,`',$fields)."`,created_at,updated_at) VALUES(".implode(',',array_fill(0,count($fields),'?')).",NOW(),NOW())";
                Database::query($sql,array_values($data));
                $savedId=(int)Database::connection()->lastInsertId();
                activity('tambah',$module,$savedId,null,$data);
            }
            if ($module==='locations' && $savedId && $legacyPhoto && !$removeLegacyPhoto) {
                $exists=Database::query("SELECT id FROM location_photos WHERE location_id=? AND photo_path=? LIMIT 1",[$savedId,$legacyPhoto])->fetch();
                if (!$exists) Database::query("INSERT INTO location_photos(location_id,photo_path,sort_order,created_at) VALUES(?,?,0,NOW())",[$savedId,$legacyPhoto]);
            }
            if ($module==='locations' && $uploadedPhotos && $savedId) {
                $next=(int)(Database::query("SELECT COALESCE(MAX(sort_order),0) max_sort FROM location_photos WHERE location_id=?",[$savedId])->fetch()['max_sort']??0);
                foreach($uploadedPhotos as $path) Database::query("INSERT INTO location_photos(location_id,photo_path,sort_order,created_at) VALUES(?,?,?,NOW())",[$savedId,$path,++$next]);
            }
            if ($module==='locations' && $savedId && ($uploadedPhotos || $deletePhotoIds || $removeLegacyPhoto)) {
                foreach($deletePhotoIds as $photoId) Database::query("UPDATE location_photos SET deleted_at=NOW() WHERE id=? AND location_id=?",[$photoId,$savedId]);
                $remaining=Database::query("SELECT photo_path FROM location_photos WHERE location_id=? AND deleted_at IS NULL ORDER BY sort_order,id LIMIT 1",[$savedId])->fetch();
                $legacyStill=$legacyPhoto && Database::query("SELECT id FROM location_photos WHERE location_id=? AND photo_path=? AND deleted_at IS NULL LIMIT 1",[$savedId,$legacyPhoto])->fetch();
                $primary=$remaining['photo_path']??($removeLegacyPhoto || !$legacyStill ? null : $legacyPhoto);
                Database::query("UPDATE locations SET photo=?,updated_at=NOW() WHERE id=?",[$primary?:null,$savedId]);
            }
        } catch (\PDOException $e) {
            $message = str_contains($e->getMessage(),'Duplicate')
                ? 'Kode, username, email, atau nilai unik tersebut sudah digunakan.'
                : (str_contains($e->getMessage(),'out of range')
                    ? 'Terdapat nilai angka di luar batas yang diperbolehkan.'
                    : 'Data tidak dapat disimpan. Periksa kembali format dan nilai yang diisi.');
            flash('danger',$message);
            redirect($module . ($id ? '/' . $id : ''));
        }
        flash('success',$def['title'].' berhasil disimpan.'); redirect($module);
    }

    private function ensureLocationPhotoSchema(): void
    {
        Database::query("CREATE TABLE IF NOT EXISTS location_photos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            location_id BIGINT UNSIGNED NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            INDEX idx_location_photos_location (location_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function ensureDeviceSourceField(): void
    {
        try {
            $column=Database::query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devices' AND COLUMN_NAME='water_source_id'")->fetch();
            if(!$column) Database::query("ALTER TABLE devices ADD COLUMN water_source_id BIGINT UNSIGNED NULL AFTER location_id");
            try { Database::query("CREATE INDEX idx_devices_water_source ON devices(water_source_id)"); } catch (\Throwable) {}
            // Hubungkan otomatis hanya bila satu lokasi memiliki tepat satu sumber air.
            // Dengan begitu data lama aman dan tidak salah memilih saat satu lokasi punya banyak sumber.
            Database::query("UPDATE devices d JOIN (SELECT location_id,MIN(id) source_id FROM water_sources WHERE deleted_at IS NULL GROUP BY location_id HAVING COUNT(*)=1) ws ON ws.location_id=d.location_id SET d.water_source_id=ws.source_id WHERE d.water_source_id IS NULL");
        } catch (\Throwable) {}
    }

    private function delete(string $module, array $def, ?int $id): void
    {
        verify_csrf();
        if (!$id || !has_role(['super_admin','administrator'])) { http_response_code(403); return; }
        Database::query("UPDATE `{$def['table']}` SET deleted_at=NOW() WHERE id=?",[$id]);
        activity('hapus',$module,$id); flash('success','Data dipindahkan ke arsip.'); redirect($module);
    }
}

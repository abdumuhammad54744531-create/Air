<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleSheetSensorService;

final class PublicController
{
    /** Lightweight endpoint used by the public page to check for a new sheet row. */
    public function liveStatus(): void
    {
        $this->ensureDeviceSourceLinks();
        $locationId=(int)($_GET['location']??0);
        $devices=Database::query("SELECT d.id,d.google_sheet_url,sc.profile_points_json FROM devices d JOIN locations l ON l.id=d.location_id
            LEFT JOIN source_cross_sections sc ON sc.source_id=d.water_source_id
            WHERE d.location_id=? AND d.is_public=1 AND d.deleted_at IS NULL AND l.is_public=1 AND l.is_active=1 AND l.deleted_at IS NULL",[$locationId])->fetchAll();
        $widthRow=Database::query("SELECT setting_value FROM application_settings WHERE setting_key='source_width' LIMIT 1")->fetch();
        $readings=$this->googleSheetReadings($devices,$this->sourceWidthForLocation($locationId,(float)($widthRow['setting_value']??2.15)),$this->sourceProfileForLocation($locationId));
        json_response([
            'success'=>true,
            'latest_at'=>$readings[0]['recorded_at']??null,
            'checked_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    public function home(): void
    {
        $this->ensureDeviceSourceLinks();
        $locations=Database::query("SELECT l.id,l.code,l.name,l.type,l.province,l.city,l.district,l.village,l.address,
            l.latitude,l.longitude,l.elevation,l.photo,l.description,l.updated_at,COUNT(d.id) device_count,
            SUM(CASE WHEN d.connection_status='online' THEN 1 ELSE 0 END) online_devices,
            GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR ', ') device_names,MAX(d.last_data_at) last_update
            FROM locations l LEFT JOIN devices d ON d.location_id=l.id AND d.is_public=1 AND d.deleted_at IS NULL
            WHERE l.is_public=1 AND l.is_active=1 AND l.deleted_at IS NULL
            GROUP BY l.id,l.code,l.name,l.type,l.province,l.city,l.district,l.village,l.address,
            l.latitude,l.longitude,l.elevation,l.photo,l.description,l.updated_at ORDER BY l.name")->fetchAll();
        $locations=$this->attachLocationPhotos($locations);
        $requestedLocation=(int)($_GET['location']??0);
        $allowedIds=array_map('intval',array_column($locations,'id'));
        $selectedLocationId=in_array($requestedLocation,$allowedIds,true)?$requestedLocation:($allowedIds[0]??0);
        $selectedLocation=null;
        foreach($locations as $location)if((int)$location['id']===$selectedLocationId)$selectedLocation=$location;
        $devices=Database::query("SELECT d.id,d.code,d.name,d.type,d.status,d.connection_status,d.last_data_at,d.battery_voltage,d.signal_strength,d.google_sheet_url,d.water_source_id,
            ws.code water_source_code,ws.name water_source_name,sc.profile_points_json
            FROM devices d LEFT JOIN water_sources ws ON ws.id=d.water_source_id AND ws.deleted_at IS NULL
            LEFT JOIN source_cross_sections sc ON sc.source_id=ws.id
            WHERE d.location_id=? AND d.is_public=1 AND d.deleted_at IS NULL ORDER BY d.name",[$selectedLocationId])->fetchAll();
        $readings = Database::query("SELECT sr.recorded_at,s.parameter,s.name sensor_name,s.unit,sr.calibrated_value value,sr.quality_status
            FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id JOIN devices d ON d.id=sr.device_id
            JOIN locations l ON l.id=d.location_id WHERE l.id=? AND l.is_public=1 AND d.is_public=1 AND s.is_public=1
            ORDER BY sr.recorded_at DESC LIMIT 120",[$selectedLocationId])->fetchAll();
        $latest=[]; $samples=[];
        foreach($readings as $reading){
            $latest[$reading['parameter']] ??= $reading;
            $key=$reading['recorded_at']; $samples[$key] ??= ['recorded_at'=>$key];
            $samples[$key][$reading['parameter']]=$reading;
        }
        $trend=Database::query("SELECT DATE(sr.recorded_at) reading_date,ROUND(AVG(sr.calibrated_value),2) value
            FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id JOIN devices d ON d.id=sr.device_id
            WHERE d.location_id=? AND s.parameter='debit' AND s.is_public=1 AND d.is_public=1
            AND sr.recorded_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
            GROUP BY DATE(sr.recorded_at) ORDER BY reading_date",[$selectedLocationId])->fetchAll();
        $rows=Database::query("SELECT setting_key,setting_value FROM application_settings WHERE setting_key IN ('source_width','peak_demand','institution_name','researcher_name','researcher_id','study_program')")->fetchAll();
        $settings=array_column($rows,'setting_value','setting_key');
        $fixed=['source_width'=>(float)($settings['source_width']??2.15),'peak_demand'=>(float)($settings['peak_demand']??43.83),
            'institution_name'=>$settings['institution_name']??'Instansi Pengelola Sumber Daya Air',
            'researcher_name'=>$settings['researcher_name']??'Aswad Asrasal','researcher_id'=>$settings['researcher_id']??'10202200015',
            'study_program'=>$settings['study_program']??'Program Doktor Teknik Sipil'];
        $fixed['source_width']=$this->sourceWidthForLocation($selectedLocationId,$fixed['source_width']);
        $locationProfile=$this->sourceProfileForLocation($selectedLocationId);
        $sheetReadings=$this->googleSheetReadings($devices,$fixed['source_width'],$locationProfile);
        $crossSectionLinks=Database::query("SELECT ws.id,ws.code,ws.name,l.name location_name,sc.updated_at
            FROM water_sources ws JOIN source_cross_sections sc ON sc.source_id=ws.id
            LEFT JOIN locations l ON l.id=ws.location_id WHERE ws.location_id=? AND ws.deleted_at IS NULL ORDER BY sc.updated_at DESC",[$selectedLocationId])->fetchAll();
        $sheetConnectedDeviceIds=[];
        foreach($sheetReadings as $sheetReading) {
            $sheetDeviceId=(int)($sheetReading['device_id']??0);
            if($sheetDeviceId>0) $sheetConnectedDeviceIds[$sheetDeviceId]=true;
        }
        foreach($devices as &$device) $device['sheet_connected']=isset($sheetConnectedDeviceIds[(int)$device['id']]);
        unset($device);
        if ($sheetReadings) {
            $readings=$sheetReadings; $latest=[]; $samples=[];
            foreach($readings as $reading){
                $latest[$reading['parameter']] ??= $reading;
                $key=$reading['recorded_at']; $samples[$key] ??= ['recorded_at'=>$key];
                $samples[$key][$reading['parameter']]=$reading;
            }
            $trend=[];
            foreach(array_reverse(array_slice(array_values($samples),0,24)) as $sample) {
                $debitReading=$sample['debit']??null;
                if(!$debitReading) continue;
                $trend[]=['reading_date'=>substr($sample['recorded_at'],0,10),'label'=>$sample['recorded_at'],'value'=>round((float)$debitReading['value'],2)];
            }
            $latest=$this->averageSheetMetrics($readings);
        }
        $latestTime=$readings[0]['recorded_at']??date('Y-m-d H:i:s');
        view('public/home',['title'=>'Portal Pemantauan Sumber Mata Air','latest'=>$latest,
            'samples'=>array_slice(array_values($samples),0,12),'trend'=>$trend,'fixed'=>$fixed,'latestTime'=>$latestTime,
            'locations'=>$locations,'selectedLocation'=>$selectedLocation,'selectedLocationId'=>$selectedLocationId,'devices'=>$devices,
            'crossSectionLinks'=>$crossSectionLinks],'layouts/public');
    }

    private function attachLocationPhotos(array $locations): array
    {
        if (!$locations) return $locations;
        Database::query("CREATE TABLE IF NOT EXISTS location_photos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, location_id BIGINT UNSIGNED NOT NULL,
            photo_path VARCHAR(255) NOT NULL, caption VARCHAR(255) NULL, sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
            INDEX idx_location_photos_location (location_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ids=array_map('intval',array_column($locations,'id'));
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $photos=Database::query("SELECT location_id,photo_path FROM location_photos WHERE deleted_at IS NULL AND location_id IN ({$placeholders}) ORDER BY sort_order,id",$ids)->fetchAll();
        $byLocation=[];
        foreach($photos as $photo)$byLocation[(int)$photo['location_id']][]=$photo['photo_path'];
        foreach($locations as &$location) {
            $all=[];
            if (!empty($location['photo'])) $all[]=$location['photo'];
            foreach($byLocation[(int)$location['id']]??[] as $photo)if(!in_array($photo,$all,true))$all[]=$photo;
            $location['photos']=$all;
        }
        unset($location);
        return $locations;
    }

    private function googleSheetReadings(array $devices, float $sourceWidth, ?array $profilePoints=null): array
    {
        $service=new GoogleSheetSensorService(); $readings=[];
        foreach($devices as $device){
            if (empty($device['google_sheet_url'])) continue;
            try { $rows=$service->readings((int)$device['id'],400)['rows']; } catch (\Throwable) { continue; }
            foreach($rows as $row){
                $recordedAt=(string)($row['sort_at']??'');
                if ($recordedAt===''||$recordedAt==='0000-00-00 00:00:00') continue;
                $temperature=(float)str_replace(',','.',(string)($row['temperature']??0));
                $ph=(float)str_replace(',','.',(string)($row['ph']??0));
                $tds=(float)str_replace(',','.',(string)($row['tds']??0));
                $velocity=(float)str_replace(',','.',(string)($row['velocity']??0));
                $waterLevel=(float)str_replace(',','.',(string)($row['water_level']??0));
                // Setiap baris Google Sheet memakai tinggi airnya sendiri. Jika profil
                // penampang tersedia untuk lokasi alat ini, luas dan lebar dihitung
                // ulang dari bentuk profil tersebut, bukan menggunakan lebar tetap.
                $deviceProfile=$this->profilePoints((string)($device['profile_points_json']??''))?:$profilePoints;
                $geometry=$deviceProfile?$this->profileMetrics($deviceProfile,$waterLevel):[
                    'area_m2'=>$sourceWidth*$waterLevel,
                    'average_width_m'=>$sourceWidth,
                    'water_surface_width_m'=>$sourceWidth,
                ];
                $debit=$velocity*$geometry['area_m2']*1000;
                $metrics=[
                    ['suhu_air','Suhu Air',$temperature,'°C',$temperature<=30?'normal':'warning'],
                    ['ph','pH',$ph,'pH',$ph>=6&&$ph<=9?'normal':'warning'],
                    ['tds','TDS',$tds,'mg/L',$tds<=500?'normal':'warning'],
                    ['kecepatan_aliran','Kecepatan Aliran',$velocity,'m/s','normal'],
                    ['tinggi_muka_air','Tinggi Air',$waterLevel,'m','normal'],
                    ['lebar_muka_air','Lebar Muka Air',$geometry['water_surface_width_m'],'m','normal'],
                    ['lebar_penampang','Lebar Rata-rata',$geometry['average_width_m'],'m','normal'],
                    ['luas_penampang','Luas Penampang',$geometry['area_m2'],'m²','normal'],
                    ['debit','Debit Sumber',$debit,'L/s','normal'],
                ];
                foreach($metrics as [$parameter,$name,$value,$unit,$quality])$readings[]=[
                    'device_id'=>(int)$device['id'],'recorded_at'=>$recordedAt,'parameter'=>$parameter,
                    'sensor_name'=>$name,'unit'=>$unit,'value'=>$value,'quality_status'=>$quality
                ];
            }
        }
        usort($readings,fn($a,$b)=>strcmp($b['recorded_at'],$a['recorded_at']));
        return $readings;
    }

    private function averageSheetMetrics(array $readings): array
    {
        $groups=[];
        foreach($readings as $reading){
            $parameter=$reading['parameter'];
            $groups[$parameter] ??=['first'=>$reading,'values'=>[],'has_warning'=>false];
            $groups[$parameter]['values'][]=(float)$reading['value'];
            $groups[$parameter]['has_warning'] = $groups[$parameter]['has_warning'] || $reading['quality_status']!=='normal';
        }
        $averages=[];
        foreach($groups as $parameter=>$group){
            $item=$group['first']; $item['value']=round(array_sum($group['values'])/count($group['values']),2);
            // Nilai yang ditampilkan di portal adalah rata-rata 60 pembacaan.
            // Karena itu status harus dinilai dari nilai rata-rata tersebut, bukan
            // menjadi WARNING hanya karena satu pembacaan lama pernah menyimpang.
            $value=(float)$item['value'];
            $item['quality_status']=match($parameter) {
                'suhu_air' => $value<=30 ? 'normal' : 'warning',
                'ph' => $value>=6 && $value<=9 ? 'normal' : 'warning',
                'tds' => $value<=500 ? 'normal' : 'warning',
                default => $group['has_warning'] ? 'warning' : 'normal',
            };
            $averages[$parameter]=$item;
        }
        return $averages;
    }

    private function sourceWidthForLocation(int $locationId,float $fallback): float
    {
        try {
            $row=Database::query("SELECT sc.average_width_m FROM source_cross_sections sc JOIN water_sources ws ON ws.id=sc.source_id WHERE ws.location_id=? AND ws.deleted_at IS NULL AND sc.average_width_m>0 ORDER BY sc.updated_at DESC LIMIT 1",[$locationId])->fetch();
            if($row && (float)$row['average_width_m']>0)return (float)$row['average_width_m'];
        } catch (\Throwable) {}
        return $fallback;
    }

    private function sourceProfileForLocation(int $locationId): ?array
    {
        try {
            $row=Database::query("SELECT sc.profile_points_json FROM source_cross_sections sc JOIN water_sources ws ON ws.id=sc.source_id WHERE ws.location_id=? AND ws.deleted_at IS NULL ORDER BY sc.updated_at DESC LIMIT 1",[$locationId])->fetch();
            return $this->profilePoints((string)($row['profile_points_json']??''));
        } catch (\Throwable) { return null; }
    }

    private function profilePoints(string $json): ?array
    {
        $points=json_decode($json,true);
        if(!is_array($points)||count($points)<2)return null;
        $valid=[];foreach($points as $point)if(isset($point['x'],$point['z'])&&is_numeric($point['x'])&&is_numeric($point['z']))$valid[]=['x'=>max(0,(float)$point['x']),'z'=>max(0,(float)$point['z'])];
        usort($valid,fn($a,$b)=>$a['x']<=>$b['x']);return count($valid)>=2?$valid:null;
    }

    private function ensureDeviceSourceLinks(): void
    {
        try {
            Database::query("CREATE TABLE IF NOT EXISTS source_cross_sections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_id BIGINT UNSIGNED NOT NULL UNIQUE,profile_points_json LONGTEXT NOT NULL,water_level_mode VARCHAR(20) NOT NULL DEFAULT 'sensor',water_level_m DECIMAL(12,4) NULL,wet_area_m2 DECIMAL(14,4) NULL,average_width_m DECIMAL(14,4) NULL,water_surface_width_m DECIMAL(14,4) NULL,max_water_depth_m DECIMAL(14,4) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_cross_section_source(source_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $column=Database::query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devices' AND COLUMN_NAME='water_source_id'")->fetch();
            if(!$column) Database::query("ALTER TABLE devices ADD COLUMN water_source_id BIGINT UNSIGNED NULL AFTER location_id");
            try { Database::query("CREATE INDEX idx_devices_water_source ON devices(water_source_id)"); } catch (\Throwable) {}
            Database::query("UPDATE devices d JOIN (SELECT location_id,MIN(id) source_id FROM water_sources WHERE deleted_at IS NULL GROUP BY location_id HAVING COUNT(*)=1) ws ON ws.location_id=d.location_id SET d.water_source_id=ws.source_id WHERE d.water_source_id IS NULL");
        } catch (\Throwable) {}
    }

    private function profileMetrics(array $points,float $waterLevel): array
    {
        $area=0.0;$surfaceWidth=0.0;$minZ=min(array_column($points,'z'));
        foreach($points as $i=>$a){$b=$points[$i+1]??null;if(!$b)continue;$dx=$b['x']-$a['x'];if($dx<=0)continue;$da=$waterLevel-$a['z'];$db=$waterLevel-$b['z'];
            if($da<=0&&$db<=0)continue;
            if($da>=0&&$db>=0){$area+=(($da+$db)/2)*$dx;$surfaceWidth+=$dx;continue;}
            $ratio=$da/($da-$db);$part=$dx*($da>0?$ratio:1-$ratio);$area+=.5*max($da,$db)*$part;$surfaceWidth+=$part;
        }
        $depth=max(0,$waterLevel-$minZ);return [
            'area_m2'=>max(0,$area),
            'average_width_m'=>$depth>0?$area/$depth:0,
            'water_surface_width_m'=>max(0,$surfaceWidth),
        ];
    }
}

<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleSheetSensorService;

final class PublicController
{
    /** Lightweight endpoint used by the public page to check for a new sheet row. */
    public function liveStatus(): void
    {
        $locationId=(int)($_GET['location']??0);
        $devices=Database::query("SELECT d.id,d.google_sheet_url FROM devices d JOIN locations l ON l.id=d.location_id
            WHERE d.location_id=? AND d.is_public=1 AND d.deleted_at IS NULL AND l.is_public=1 AND l.is_active=1 AND l.deleted_at IS NULL",[$locationId])->fetchAll();
        $widthRow=Database::query("SELECT setting_value FROM application_settings WHERE setting_key='source_width' LIMIT 1")->fetch();
        $readings=$this->googleSheetReadings($devices,(float)($widthRow['setting_value']??2.15));
        json_response([
            'success'=>true,
            'latest_at'=>$readings[0]['recorded_at']??null,
            'checked_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    public function home(): void
    {
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
        $devices=Database::query("SELECT id,code,name,type,status,connection_status,last_data_at,battery_voltage,signal_strength,google_sheet_url
            FROM devices WHERE location_id=? AND is_public=1 AND deleted_at IS NULL ORDER BY name",[$selectedLocationId])->fetchAll();
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
        $sheetReadings=$this->googleSheetReadings($devices,$fixed['source_width']);
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
            'locations'=>$locations,'selectedLocation'=>$selectedLocation,'selectedLocationId'=>$selectedLocationId,'devices'=>$devices],'layouts/public');
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

    private function googleSheetReadings(array $devices, float $sourceWidth): array
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
                $debit=$velocity*$sourceWidth*$waterLevel*1000;
                $metrics=[
                    ['suhu_air','Suhu Air',$temperature,'°C',$temperature<=30?'normal':'warning'],
                    ['ph','pH',$ph,'pH',$ph>=6&&$ph<=9?'normal':'warning'],
                    ['tds','TDS',$tds,'mg/L',$tds<=500?'normal':'warning'],
                    ['kecepatan_aliran','Kecepatan Aliran',$velocity,'m/s','normal'],
                    ['tinggi_muka_air','Tinggi Air',$waterLevel,'m','normal'],
                    ['debit','Debit Sumber',$debit,'L/s','normal'],
                ];
                foreach($metrics as [$parameter,$name,$value,$unit,$quality])$readings[]=['recorded_at'=>$recordedAt,'parameter'=>$parameter,'sensor_name'=>$name,'unit'=>$unit,'value'=>$value,'quality_status'=>$quality];
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
}

<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleSheetSensorService;

final class PublicController
{
    public function home(): void
    {
        $locations=Database::query("SELECT l.id,l.code,l.name,l.type,l.province,l.city,l.district,l.village,l.address,
            l.latitude,l.longitude,l.elevation,l.description,l.updated_at,COUNT(d.id) device_count,
            SUM(CASE WHEN d.connection_status='online' THEN 1 ELSE 0 END) online_devices,
            GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR ', ') device_names,MAX(d.last_data_at) last_update
            FROM locations l LEFT JOIN devices d ON d.location_id=l.id AND d.is_public=1 AND d.deleted_at IS NULL
            WHERE l.is_public=1 AND l.is_active=1 AND l.deleted_at IS NULL
            GROUP BY l.id,l.code,l.name,l.type,l.province,l.city,l.district,l.village,l.address,
            l.latitude,l.longitude,l.elevation,l.description,l.updated_at ORDER BY l.name")->fetchAll();
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
            $daily=[];
            foreach($readings as $reading) if($reading['parameter']==='debit') {
                $date=substr($reading['recorded_at'],0,10); $daily[$date] ??=[]; $daily[$date][]=(float)$reading['value'];
            }
            $trend=[];
            foreach($daily as $date=>$values)$trend[]=['reading_date'=>$date,'value'=>round(array_sum($values)/count($values),2)];
            usort($trend,fn($a,$b)=>strcmp($a['reading_date'],$b['reading_date']));
        }
        $latestTime=$readings[0]['recorded_at']??date('Y-m-d H:i:s');
        view('public/home',['title'=>'Portal Pemantauan Sumber Mata Air','latest'=>$latest,
            'samples'=>array_slice(array_values($samples),0,12),'trend'=>$trend,'fixed'=>$fixed,'latestTime'=>$latestTime,
            'locations'=>$locations,'selectedLocation'=>$selectedLocation,'selectedLocationId'=>$selectedLocationId,'devices'=>$devices],'layouts/public');
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
}

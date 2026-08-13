<?php
namespace App\Controllers;

use App\Core\Database;
use PDO;
use Throwable;

final class ApiController
{
    public function handle(string $endpoint, string $method): void
    {
        if ($endpoint === 'device/time' && $method === 'GET') json_response(['success'=>true,'server_time'=>date('Y-m-d H:i:s'),'timezone'=>date_default_timezone_get()]);
        if (str_starts_with($endpoint,'public/')) { $this->publicEndpoint($endpoint); }
        if ($endpoint === 'sensor/readings' && $method === 'POST') $this->sensorReading();
        if (!in_array($endpoint,['device/data','device/heartbeat','device/status','device/log','device/config'],true)) json_response(['success'=>false,'message'=>'Endpoint tidak ditemukan','error_code'=>'NOT_FOUND'],404);
        $device = $this->authenticate();
        if ($endpoint === 'device/config' && $method === 'GET') $this->config($device);
        if ($method !== 'POST') json_response(['success'=>false,'message'=>'Metode tidak diizinkan','error_code'=>'METHOD_NOT_ALLOWED'],405);
        $payload = json_decode(file_get_contents('php://input'),true);
        if (!is_array($payload)) json_response(['success'=>false,'message'=>'JSON tidak valid','error_code'=>'INVALID_JSON'],422);
        if ($endpoint === 'device/data') $this->data($device,$payload);
        if ($endpoint === 'device/heartbeat') $this->heartbeat($device,$payload);
        Database::query('INSERT INTO device_status_logs(device_id,status,details,created_at) VALUES(?,?,?,NOW())',[$device['id'],$payload['status'] ?? 'info',json_encode($payload)]);
        json_response(['success'=>true,'message'=>'Status berhasil diterima','server_time'=>date('Y-m-d H:i:s')]);
    }

    private function authenticate(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/i',$header,$m)) json_response(['success'=>false,'message'=>'Bearer API key wajib diisi','error_code'=>'UNAUTHORIZED'],401);
        $hash = hash('sha256',trim($m[1]));
        $device = Database::query("SELECT d.* FROM api_keys k JOIN devices d ON d.id=k.device_id WHERE k.key_hash=? AND k.is_active=1 AND (k.expires_at IS NULL OR k.expires_at>NOW()) AND d.deleted_at IS NULL LIMIT 1",[$hash])->fetch();
        if (!$device) json_response(['success'=>false,'message'=>'API key tidak valid atau tidak aktif','error_code'=>'INVALID_API_KEY'],401);
        return $device;
    }

    private function data(array $device,array $payload): void
    {
        if (($payload['device_code'] ?? '') !== $device['code'] || empty($payload['timestamp']) || empty($payload['packet_number']) || !is_array($payload['readings'] ?? null)) {
            json_response(['success'=>false,'message'=>'Payload belum lengkap','error_code'=>'VALIDATION_ERROR','detail'=>'device_code, timestamp, packet_number, dan readings wajib diisi'],422);
        }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            Database::query('INSERT INTO raw_device_payloads(device_id,packet_number,payload,received_at) VALUES(?,?,?,NOW())',[$device['id'],$payload['packet_number'],json_encode($payload)]);
            $groupId = (int)$pdo->lastInsertId();
            foreach ($payload['readings'] as $reading) {
                $sensor = Database::query("SELECT * FROM sensors WHERE device_id=? AND code=? AND deleted_at IS NULL",[$device['id'],$reading['sensor_code'] ?? ''])->fetch();
                if (!$sensor || !is_numeric($reading['value'] ?? null)) throw new \RuntimeException('Sensor atau nilai pembacaan tidak valid.');
                $raw = (float)$reading['value']; $value = $raw*(float)$sensor['calibration_factor']+(float)$sensor['offset_value'];
                $quality = 'normal';
                if (($sensor['danger_min'] !== null && $value < $sensor['danger_min']) || ($sensor['danger_max'] !== null && $value > $sensor['danger_max'])) $quality='bahaya';
                elseif (($sensor['warning_min'] !== null && $value < $sensor['warning_min']) || ($sensor['warning_max'] !== null && $value > $sensor['warning_max'])) $quality='waspada';
                Database::query("INSERT INTO sensor_readings(device_id,sensor_id,raw_payload_id,packet_number,recorded_at,received_at,raw_value,calibrated_value,unit,quality_status,validation_status,source,signal_strength,battery_voltage,created_at) VALUES(?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,NOW())",[
                    $device['id'],$sensor['id'],$groupId,$payload['packet_number'],$payload['timestamp'],$raw,$value,$reading['unit'] ?? $sensor['unit'],$quality,'belum_diperiksa','api_perangkat',$payload['signal_strength'] ?? null,$payload['battery_voltage'] ?? null
                ]);
                if ($quality !== 'normal') Database::query("INSERT INTO alerts(device_id,sensor_id,occurred_at,alert_type,value,threshold_value,priority,message,status,created_at,updated_at) VALUES(?,?,NOW(),?,?,?,?,?,'baru',NOW(),NOW())",[
                    $device['id'],$sensor['id'],'nilai_di_luar_batas',$value,$quality==='bahaya' ? ($sensor['danger_max'] ?? $sensor['danger_min']) : ($sensor['warning_max'] ?? $sensor['warning_min']),$quality==='bahaya'?'tinggi':'sedang',"{$sensor['name']} berada pada status {$quality}"
                ]);
            }
            Database::query("UPDATE devices SET status='aktif',connection_status='online',last_data_at=?,updated_at=NOW() WHERE id=?",[$payload['timestamp'],$device['id']]);
            $pdo->commit();
            json_response(['success'=>true,'message'=>'Data berhasil diterima','data_id'=>$groupId,'server_time'=>date('Y-m-d H:i:s')],201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = str_contains($e->getMessage(),'Duplicate') ? 'DUPLICATE_DATA' : 'PROCESSING_ERROR';
            json_response(['success'=>false,'message'=>'Data gagal diproses','error_code'=>$code,'detail'=>$e->getMessage()],409);
        }
    }

    private function heartbeat(array $device,array $payload): void
    {
        Database::query("INSERT INTO device_heartbeats(device_id,device_time,status,battery_voltage,signal_strength,firmware_version,received_at) VALUES(?,?,?,?,?,?,NOW())",[$device['id'],$payload['timestamp'] ?? date('Y-m-d H:i:s'),$payload['status'] ?? 'online',$payload['battery_voltage'] ?? null,$payload['signal_strength'] ?? null,$payload['firmware_version'] ?? null]);
        Database::query("UPDATE devices SET connection_status='online',last_heartbeat_at=NOW(),battery_voltage=?,signal_strength=?,firmware_version=COALESCE(?,firmware_version),updated_at=NOW() WHERE id=?",[$payload['battery_voltage'] ?? null,$payload['signal_strength'] ?? null,$payload['firmware_version'] ?? null,$device['id']]);
        json_response(['success'=>true,'message'=>'Heartbeat berhasil diterima','server_time'=>date('Y-m-d H:i:s')]);
    }

    private function config(array $device): void
    {
        $sensors=Database::query("SELECT code,name,parameter,unit,normal_min,normal_max,warning_min,warning_max,danger_min,danger_max,calibration_factor,offset_value FROM sensors WHERE device_id=? AND deleted_at IS NULL",[$device['id']])->fetchAll();
        json_response(['success'=>true,'device'=>['code'=>$device['code'],'send_interval_seconds'=>$device['send_interval_seconds']],'sensors'=>$sensors]);
    }

    private function publicEndpoint(string $endpoint): never
    {
        if ($endpoint==='public/locations') $data=Database::query("SELECT code,name,type,city,latitude,longitude,updated_at FROM locations WHERE is_public=1 AND is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
        elseif ($endpoint==='public/latest') $data=Database::query("SELECT l.name location,d.name device,s.name sensor,s.parameter,s.unit,sr.calibrated_value value,sr.quality_status,sr.recorded_at FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id JOIN devices d ON d.id=sr.device_id JOIN locations l ON l.id=d.location_id WHERE l.is_public=1 AND d.is_public=1 AND s.is_public=1 ORDER BY sr.recorded_at DESC LIMIT 50")->fetchAll();
        else $data=Database::query("SELECT s.parameter,s.unit,sr.calibrated_value value,sr.quality_status,sr.recorded_at FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id WHERE s.is_public=1 AND sr.recorded_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY sr.recorded_at DESC LIMIT 500")->fetchAll();
        json_response(['success'=>true,'data'=>$data,'server_time'=>date('Y-m-d H:i:s')]);
    }

    private function sensorReading(): never
    {
        $payload=json_decode(file_get_contents('php://input'),true);
        if(!is_array($payload))json_response(['success'=>false,'message'=>'JSON tidak valid','error_code'=>'INVALID_JSON'],422);
        foreach(['device_id','api_key','source_code','flow_rate_lps','recorded_at'] as $field)if(!isset($payload[$field])||$payload[$field]==='')json_response(['success'=>false,'message'=>"Field {$field} wajib diisi",'error_code'=>'VALIDATION_ERROR'],422);
        $device=Database::query("SELECT * FROM devices WHERE (id=? OR code=?) AND deleted_at IS NULL LIMIT 1",[(int)$payload['device_id'],(string)$payload['device_id']])->fetch();
        if(!$device)json_response(['success'=>false,'message'=>'Perangkat tidak ditemukan','error_code'=>'DEVICE_NOT_FOUND'],404);
        $valid=Database::query("SELECT id FROM api_keys WHERE device_id=? AND key_hash=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW())",[$device['id'],hash('sha256',(string)$payload['api_key'])])->fetch();
        if(!$valid)json_response(['success'=>false,'message'=>'API key tidak valid','error_code'=>'INVALID_API_KEY'],401);
        $source=Database::query("SELECT * FROM water_sources WHERE code=? AND deleted_at IS NULL",[(string)$payload['source_code']])->fetch();
        if(!$source||!$source['sensor_id'])json_response(['success'=>false,'message'=>'Sumber air belum terhubung ke sensor debit','error_code'=>'SOURCE_SENSOR_NOT_CONFIGURED'],422);
        if(!is_numeric($payload['flow_rate_lps'])||(float)$payload['flow_rate_lps']<0)json_response(['success'=>false,'message'=>'Debit tidak valid','error_code'=>'INVALID_FLOW'],422);
        $packet='SENSOR-'.date('YmdHis').'-'.bin2hex(random_bytes(3));$flow=(float)$payload['flow_rate_lps'];
        Database::query("INSERT INTO sensor_readings(device_id,sensor_id,packet_number,recorded_at,received_at,raw_value,calibrated_value,unit,quality_status,validation_status,source,signal_strength,battery_voltage,notes,created_at) VALUES(?,?,?,?,NOW(),?,?,?,'normal','belum_diperiksa','api_perangkat',?,?,?,NOW())",[
            $device['id'],$source['sensor_id'],$packet,$payload['recorded_at'],$flow,$flow,'L/s',$payload['signal_strength']??null,$payload['battery_voltage']??null,
            json_encode(['water_level_cm'=>$payload['water_level_cm']??null,'pressure'=>$payload['pressure']??null])
        ]);
        Database::query("UPDATE water_sources SET current_sensor_flow_lps=?,last_measured_at=?,updated_at=NOW() WHERE id=?",[$flow,$payload['recorded_at'],$source['id']]);
        Database::query("UPDATE devices SET connection_status='online',status='aktif',last_data_at=?,battery_voltage=?,signal_strength=?,updated_at=NOW() WHERE id=?",[$payload['recorded_at'],$payload['battery_voltage']??null,$payload['signal_strength']??null,$device['id']]);
        json_response(['success'=>true,'message'=>'Pembacaan sensor berhasil disimpan','data'=>['source_code'=>$source['code'],'flow_rate_lps'=>$flow,'packet_number'=>$packet],'server_time'=>date('Y-m-d H:i:s')],201);
    }
}

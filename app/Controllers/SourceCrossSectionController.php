<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleSheetSensorService;

final class SourceCrossSectionController
{
    public function handle(string $method): void
    {
        require_auth(['super_admin','administrator','operator']);
        $this->ensureSchema();
        $this->ensureDeviceSourceLinks();
        $sources=Database::query("SELECT ws.id,ws.code,ws.name,ws.location_id,l.name location_name FROM water_sources ws LEFT JOIN locations l ON l.id=ws.location_id WHERE ws.deleted_at IS NULL ORDER BY ws.name")->fetchAll();
        $sourceId=(int)($_GET['source']??$_POST['source_id']??0);
        if(!$sourceId && $sources)$sourceId=(int)$sources[0]['id'];
        $source=null; foreach($sources as $item)if((int)$item['id']===$sourceId){$source=$item;break;}
        if(!$source){flash('danger','Pilih sumber air yang valid.');redirect('water-sources');}
        if($method==='POST'){$this->save($source);return;}
        $section=Database::query('SELECT * FROM source_cross_sections WHERE source_id=?',[$sourceId])->fetch()?:[];
        $points=$this->points((string)($section['profile_points_json']??''));
        $sensorHeight=$this->sensorHeight((int)$source['location_id']);
        $linkedDevices=Database::query("SELECT code,name FROM devices WHERE water_source_id=? AND deleted_at IS NULL ORDER BY name",[$sourceId])->fetchAll();
        $sensorSeries=$this->sensorSeries($sourceId,(int)$source['location_id']);
        view('water/source-cross-section',compact('sources','source','section','points','sensorHeight','linkedDevices','sensorSeries')+['title'=>'Penampang Mata Air']);
    }

    private function save(array $source): void
    {
        verify_csrf();
        $points=$this->points((string)($_POST['profile_points_json']??''));
        if(count($points)<2){flash('danger','Gambar minimal harus memiliki dua titik dasar penampang.');redirect('source-cross-section?source='.$source['id']);}
        $simulated=str_replace(',','.',trim((string)($_POST['simulation_water_level_m']??'')));
        $waterLevel=is_numeric($simulated)?(float)$simulated:$this->sensorHeight((int)$source['location_id']);
        if($waterLevel===null||$waterLevel<0){flash('danger','Isi Tinggi Air Simulasi agar lebar dan luas penampang dapat dihitung.');redirect('source-cross-section?source='.$source['id']);}
        $result=$this->calculate($points,$waterLevel);
        $exists=Database::query('SELECT id FROM source_cross_sections WHERE source_id=?',[$source['id']])->fetch();
        $data=[json_encode($points,JSON_UNESCAPED_UNICODE),'simulasi',$waterLevel,$result['area_m2'],$result['average_width_m'],$result['surface_width_m'],$result['max_depth_m'],$source['id']];
        if($exists) Database::query('UPDATE source_cross_sections SET profile_points_json=?,water_level_mode=?,water_level_m=?,wet_area_m2=?,average_width_m=?,water_surface_width_m=?,max_water_depth_m=?,updated_at=NOW() WHERE source_id=?',$data);
        else Database::query('INSERT INTO source_cross_sections(profile_points_json,water_level_mode,water_level_m,wet_area_m2,average_width_m,water_surface_width_m,max_water_depth_m,source_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())',[...array_slice($data,0,7),$source['id']]);
        activity('simpan_penampang','water_sources',(int)$source['id'],null,['simulation_water_level_m'=>$waterLevel,'average_width_m'=>$result['average_width_m'],'wet_area_m2'=>$result['area_m2']]);
        flash('success','Penampang dan perhitungan otomatis berhasil disimpan.');redirect('source-cross-section?source='.$source['id']);
    }

    private function sensorHeight(int $locationId): ?float
    {
        if($locationId<1)return null;
        $devices=Database::query("SELECT id,google_sheet_url FROM devices WHERE location_id=? AND deleted_at IS NULL AND COALESCE(google_sheet_url,'')<>'' ORDER BY id",[$locationId])->fetchAll();
        foreach($devices as $device){
            try{
                $rows=(new GoogleSheetSensorService())->readings((int)$device['id'],60)['rows'];
                foreach($rows as $row)if(($row['water_level']??'')!==''&&is_numeric(str_replace(',','.',(string)$row['water_level'])))return (float)str_replace(',','.',(string)$row['water_level']);
            }catch(\Throwable){}
        }
        return null;
    }

    /** Recent Google Sheet water-level rows, ordered old to new for playback. */
    private function sensorSeries(int $sourceId,int $locationId): array
    {
        $devices=Database::query("SELECT id,google_sheet_url FROM devices WHERE deleted_at IS NULL AND COALESCE(google_sheet_url,'')<>'' AND (water_source_id=? OR (water_source_id IS NULL AND location_id=?)) ORDER BY id",[$sourceId,$locationId])->fetchAll();
        $series=[];
        foreach($devices as $device) {
            try { $rows=(new GoogleSheetSensorService())->readings((int)$device['id'],60)['rows']; }
            catch(\Throwable) { continue; }
            foreach($rows as $row) {
                $height=str_replace(',','.',trim((string)($row['water_level']??'')));
                $at=trim((string)($row['sort_at']??''));
                if($at===''||!is_numeric($height)) continue;
                $series[$at]=['at'=>$at,'height'=>round((float)$height,4)];
            }
        }
        ksort($series);
        return array_slice(array_values($series),-60);
    }

    private function points(string $json): array
    {
        $raw=json_decode($json,true);if(!is_array($raw))return [];$items=[];
        foreach($raw as $point){$x=str_replace(',','.',(string)($point['x']??''));$z=str_replace(',','.',(string)($point['z']??''));if(is_numeric($x)&&is_numeric($z))$items[]=['x'=>max(0,round((float)$x,4)),'z'=>max(0,round((float)$z,4))];}
        usort($items,fn($a,$b)=>$a['x']<=>$b['x']);return $items;
    }

    private function calculate(array $points,float $waterLevel): array
    {
        $area=0.0;$surfaceWidth=0.0;$minZ=min(array_column($points,'z'));
        foreach($points as $index=>$a){$b=$points[$index+1]??null;if(!$b)continue;$dx=$b['x']-$a['x'];if($dx<=0)continue;$da=$waterLevel-$a['z'];$db=$waterLevel-$b['z'];
            if($da<=0&&$db<=0)continue;
            if($da>=0&&$db>=0){$area+=(($da+$db)/2)*$dx;$surfaceWidth+=$dx;continue;}
            $ratio=$da/($da-$db);$part=$dx*($da>0?$ratio:1-$ratio);$area+=.5*max($da,$db)*$part;$surfaceWidth+=$part;
        }
        $maxDepth=max(0,$waterLevel-$minZ);return ['area_m2'=>round(max(0,$area),4),'surface_width_m'=>round(max(0,$surfaceWidth),4),'max_depth_m'=>round($maxDepth,4),'average_width_m'=>round($maxDepth>0?$area/$maxDepth:0,4)];
    }

    private function ensureSchema(): void
    {
        Database::query("CREATE TABLE IF NOT EXISTS source_cross_sections (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL UNIQUE,
          profile_points_json LONGTEXT NOT NULL, water_level_mode VARCHAR(20) NOT NULL DEFAULT 'sensor', water_level_m DECIMAL(12,4) NULL,
          wet_area_m2 DECIMAL(14,4) NULL, average_width_m DECIMAL(14,4) NULL, water_surface_width_m DECIMAL(14,4) NULL, max_water_depth_m DECIMAL(14,4) NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY(source_id) REFERENCES water_sources(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function ensureDeviceSourceLinks(): void
    {
        try {
            $column=Database::query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devices' AND COLUMN_NAME='water_source_id'")->fetch();
            if(!$column) Database::query("ALTER TABLE devices ADD COLUMN water_source_id BIGINT UNSIGNED NULL AFTER location_id");
            try { Database::query("CREATE INDEX idx_devices_water_source ON devices(water_source_id)"); } catch (\Throwable) {}
            Database::query("UPDATE devices d JOIN (SELECT location_id,MIN(id) source_id FROM water_sources WHERE deleted_at IS NULL GROUP BY location_id HAVING COUNT(*)=1) ws ON ws.location_id=d.location_id SET d.water_source_id=ws.source_id WHERE d.water_source_id IS NULL");
        } catch (\Throwable) {}
    }
}

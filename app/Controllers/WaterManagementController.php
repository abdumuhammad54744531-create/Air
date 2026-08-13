<?php
namespace App\Controllers;

use App\Core\Database;
use Throwable;

final class WaterManagementController
{
    public function dashboard(): void
    {
        require_auth();
        $stats=Database::query("SELECT
          (SELECT COUNT(*) FROM water_sources WHERE deleted_at IS NULL) sources,
          (SELECT COUNT(*) FROM reservoirs WHERE deleted_at IS NULL) reservoirs,
          (SELECT COUNT(*) FROM service_areas WHERE deleted_at IS NULL) areas,
          (SELECT COUNT(*) FROM distribution_networks WHERE deleted_at IS NULL) networks,
          (SELECT COUNT(*) FROM simulation_headers WHERE deleted_at IS NULL) simulations,
          (SELECT COUNT(*) FROM simulation_results WHERE result_status IN ('kurang','kritis')) shortages")->fetch();
        $latest=Database::query("SELECT h.id,h.simulation_number,h.name,h.simulation_date,h.season,r.total_effective_flow_lps,r.total_demand_lps,r.surplus_deficit_lps,r.fulfillment_percent,r.result_status
          FROM simulation_headers h LEFT JOIN simulation_results r ON r.simulation_id=h.id WHERE h.deleted_at IS NULL ORDER BY h.id DESC LIMIT 8")->fetchAll();
        $sourceTotals=Database::query("SELECT COALESCE(SUM(normal_flow_lps),0) normal_flow,COALESCE(SUM(current_sensor_flow_lps),0) sensor_flow FROM water_sources WHERE status='aktif' AND deleted_at IS NULL")->fetch();
        $demand=Database::query("SELECT COALESCE(SUM(peak_hour_demand_lps/(1-network_loss_percent/100)),0) demand FROM service_areas WHERE deleted_at IS NULL")->fetchColumn();
        view('water/dashboard',['title'=>'Dashboard Pengelolaan Air','stats'=>$stats,'latest'=>$latest,'sourceTotals'=>$sourceTotals,'demand'=>$demand]);
    }

    public function wizard(): void
    {
        require_auth(['super_admin','administrator','operator']);
        $sources=Database::query("SELECT ws.*,l.name location_name FROM water_sources ws LEFT JOIN locations l ON l.id=ws.location_id WHERE ws.status='aktif' AND ws.deleted_at IS NULL ORDER BY ws.name")->fetchAll();
        $reservoirs=Database::query("SELECT * FROM reservoirs WHERE status='aktif' AND deleted_at IS NULL ORDER BY name")->fetchAll();
        $areas=Database::query("SELECT * FROM service_areas WHERE deleted_at IS NULL ORDER BY FIELD(priority,'sangat_tinggi','tinggi','sedang','rendah'),name")->fetchAll();
        $networks=Database::query("SELECT * FROM distribution_networks WHERE status='aktif' AND deleted_at IS NULL ORDER BY flow_priority,route_name")->fetchAll();
        view('water/wizard',compact('sources','reservoirs','areas','networks')+['title'=>'Simulasi Debit']);
    }

    public function run(): void
    {
        require_auth(['super_admin','administrator','operator']); verify_csrf();
        $sourceIds=array_values(array_unique(array_map('intval',$_POST['source_ids']??[])));
        $areaIds=array_values(array_unique(array_map('intval',$_POST['area_ids']??[])));
        if(!$sourceIds||!$areaIds){flash('danger','Pilih minimal satu sumber air dan satu wilayah layanan.');redirect('water-simulation');}
        $flowMode=$_POST['flow_mode']??'normal'; $days=max(1,min(365,(int)($_POST['simulation_days']??1)));
        $durationType=$_POST['duration_type']??'per_hari'; $routeLoss=max(0,min(99,(float)($_POST['route_loss_percent']??0)));
        $reservoirId=(int)($_POST['reservoir_id']??0);
        $sourceMarks=implode(',',array_fill(0,count($sourceIds),'?'));
        $areaMarks=implode(',',array_fill(0,count($areaIds),'?'));
        $sources=Database::query("SELECT * FROM water_sources WHERE id IN ($sourceMarks) AND status='aktif' AND deleted_at IS NULL",$sourceIds)->fetchAll();
        $areas=Database::query("SELECT * FROM service_areas WHERE id IN ($areaMarks) AND deleted_at IS NULL",$areaIds)->fetchAll();
        $reservoir=$reservoirId?Database::query("SELECT * FROM reservoirs WHERE id=? AND status='aktif' AND deleted_at IS NULL",[$reservoirId])->fetch():null;
        if(count($sources)!==count($sourceIds)||count($areas)!==count($areaIds)){flash('danger','Sumber atau wilayah tidak valid.');redirect('water-simulation');}

        $sourceSnapshots=[];$totalRaw=0;$totalEffective=0;$largestSource=null;
        foreach($sources as $source){
            $flow=$this->resolveSourceFlow($source,$flowMode,$_POST['manual_flow'][$source['id']]??null);
            $effective=$flow*(1-(float)$source['source_loss_percent']/100);
            $sourceSnapshots[]=['row'=>$source,'flow'=>$flow,'effective'=>$effective];
            $totalRaw+=$flow;$totalEffective+=$effective;
            if(!$largestSource||$effective>$largestSource['effective'])$largestSource=['name'=>$source['name'],'effective'=>$effective];
        }
        $afterNetwork=$totalEffective*(1-$routeLoss/100);
        $areaDemands=[];$totalDemand=0;
        foreach($areas as $area){
            $peak=(float)$area['peak_hour_demand_lps'];
            $design=$peak/max(.01,1-(float)$area['network_loss_percent']/100);
            $areaDemands[]=['row'=>$area,'design'=>$design];$totalDemand+=$design;
        }
        $durationSeconds=$days*86400; $initialVolume=$reservoir?(float)$reservoir['initial_volume_m3']:0;
        $capacity=$reservoir?(float)$reservoir['effective_capacity_m3']:0; $overflow=0;$finalVolume=$initialVolume;
        if($reservoir){
            $availableM3=$initialVolume+$afterNetwork*$durationSeconds/1000;
            $reservoirLoss=$availableM3*(float)$reservoir['loss_percent']/100;
            $deliverableM3=max(0,$availableM3-$reservoirLoss);
            $delivered=min($totalDemand,$deliverableM3*1000/$durationSeconds);
            $finalVolume=max(0,$deliverableM3-$delivered*$durationSeconds/1000);
            if($finalVolume>$capacity){$overflow=$finalVolume-$capacity;$finalVolume=$capacity;}
        }else{$delivered=min($afterNetwork,$totalDemand);}
        $surplus=$delivered-$totalDemand;$fulfillment=$totalDemand>0?$delivered/$totalDemand*100:100;
        $status=$fulfillment>=100?'mencukupi':($fulfillment>=80?'perhatian':'kritis');
        $recommendations=$this->recommend($fulfillment,$surplus,$reservoir,$finalVolume,$capacity,$routeLoss,$totalEffective);

        $pdo=Database::connection();
        try{
            $pdo->beginTransaction();
            $number='SIM-'.date('Ymd-His').'-'.random_int(10,99);
            Database::query("INSERT INTO simulation_headers(simulation_number,name,simulation_date,period_label,season,duration_type,simulation_days,flow_mode,status,notes,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,? ,?,?,NOW(),NOW())",[
                $number,trim($_POST['name']??'Simulasi Baru'),$_POST['simulation_date']??date('Y-m-d'),trim($_POST['period_label']??''),$_POST['season']??'normal',$durationType,$days,$flowMode,'selesai',trim($_POST['notes']??''),user()['id']
            ]);
            $simulationId=(int)$pdo->lastInsertId();
            foreach($sourceSnapshots as $snapshot)Database::query("INSERT INTO simulation_sources(simulation_id,source_id,source_code_snapshot,source_name_snapshot,flow_mode,source_flow_lps,loss_percent,effective_flow_lps,priority,created_at) VALUES(?,?,?,?,?,?,?,?,1,NOW())",[
                $simulationId,$snapshot['row']['id'],$snapshot['row']['code'],$snapshot['row']['name'],$flowMode,$snapshot['flow'],$snapshot['row']['source_loss_percent'],$snapshot['effective']
            ]);
            $served=0;$shortage=0;
            foreach($areaDemands as $item){
                $share=$totalDemand>0?$item['design']/$totalDemand:0;$areaDelivered=$delivered*$share;
                $percent=$item['design']>0?$areaDelivered/$item['design']*100:100;$areaStatus=$this->serviceStatus($percent);
                $percent>=100?$served++:$shortage++;
                Database::query("INSERT INTO simulation_service_areas(simulation_id,service_area_id,area_name_snapshot,population_snapshot,average_demand_lps,max_day_demand_lps,peak_hour_demand_lps,design_demand_lps,allocated_flow_lps,delivered_flow_lps,difference_lps,fulfillment_percent,service_status,priority_snapshot,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[
                    $simulationId,$item['row']['id'],$item['row']['name'],$item['row']['population'],$item['row']['average_demand_lps'],$item['row']['max_day_demand_lps'],$item['row']['peak_hour_demand_lps'],$item['design'],$totalEffective*$share,$areaDelivered,$areaDelivered-$item['design'],$percent,$areaStatus,$item['row']['priority']
                ]);
                Database::query("INSERT INTO simulation_routes(simulation_id,origin_type,origin_id,origin_name_snapshot,destination_type,destination_id,destination_name_snapshot,allocation_percent,allocated_flow_lps,loss_percent,delivered_flow_lps,operation_hours,priority,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[
                    $simulationId,$reservoir?'reservoir':'source',$reservoir?$reservoir['id']:$sources[0]['id'],$reservoir?$reservoir['name']:'Gabungan Sumber','service_area',$item['row']['id'],$item['row']['name'],$share*100,$totalEffective*$share,$routeLoss,$areaDelivered,24,1
                ]);
            }
            $endurance=0;
            if($reservoir){
                $endurance=$totalDemand>0?$finalVolume*1000/$totalDemand/3600:0;
                Database::query("INSERT INTO simulation_reservoirs(simulation_id,reservoir_id,reservoir_name_snapshot,effective_capacity_m3,initial_volume_m3,total_inflow_lps,total_outflow_lps,loss_percent,final_volume_m3,fill_percent,overflow_m3,service_duration_hours,empty_status,overflow_status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[
                    $simulationId,$reservoir['id'],$reservoir['name'],$capacity,$initialVolume,$afterNetwork,$delivered,$reservoir['loss_percent'],$finalVolume,$capacity>0?$finalVolume/$capacity*100:0,$overflow,$endurance,$finalVolume<=0?1:0,$overflow>0?1:0
                ]);
            }
            $steps=$durationType==='per_jam'?min($days*24,744):$days;$stepSeconds=$durationType==='per_jam'?3600:86400;$stepVolume=$initialVolume;
            for($step=1;$step<=$steps;$step++){
                $inM3=$afterNetwork*$stepSeconds/1000;$outM3=$delivered*$stepSeconds/1000;
                $end=max(0,$stepVolume+$inM3-$outM3);$stepOverflow=0;
                if($reservoir&&$end>$capacity){$stepOverflow=$end-$capacity;$end=$capacity;}
                Database::query("INSERT INTO simulation_time_steps(simulation_id,step_number,step_time,source_flow_lps,effective_flow_lps,reservoir_initial_m3,reservoir_inflow_m3,reservoir_outflow_m3,reservoir_final_m3,delivered_flow_lps,demand_flow_lps,surplus_deficit_lps,overflow_m3,created_at) VALUES(?,?,DATE_ADD(?,INTERVAL ? HOUR),?,?,?,?,?,?,?,?,?,?,NOW())",[
                    $simulationId,$step,($_POST['simulation_date']??date('Y-m-d')).' 00:00:00',$durationType==='per_jam'?$step:$step*24,$totalRaw,$totalEffective,$stepVolume,$inM3,$outM3,$reservoir?$end:0,$delivered,$totalDemand,$surplus,$stepOverflow
                ]);$stepVolume=$end;
            }
            Database::query("INSERT INTO simulation_results(simulation_id,total_source_flow_lps,total_effective_flow_lps,total_loss_lps,total_demand_lps,total_delivered_lps,surplus_deficit_lps,fulfillment_percent,initial_reservoir_m3,final_reservoir_m3,overflow_m3,reservoir_endurance_hours,served_areas,shortage_areas,largest_source_name,highest_loss_route_name,result_status,recommendations,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[
                $simulationId,$totalRaw,$totalEffective,$totalRaw-$totalEffective+$totalEffective*$routeLoss/100,$totalDemand,$delivered,$surplus,$fulfillment,$initialVolume,$finalVolume,$overflow,$endurance,$served,$shortage,$largestSource['name']??null,$routeLoss>0?'Kehilangan jaringan simulasi':null,$status,json_encode($recommendations)
            ]);
            $pdo->commit();activity('jalankan_simulasi','pengelolaan_air',$simulationId);flash('success','Simulasi berhasil dihitung dan disimpan.');redirect('water-results/'.$simulationId);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function results(?int $id=null): void
    {
        require_auth();
        if(!$id){
            $rows=Database::query("SELECT h.*,r.total_effective_flow_lps,r.total_demand_lps,r.surplus_deficit_lps,r.fulfillment_percent,r.result_status FROM simulation_headers h LEFT JOIN simulation_results r ON r.simulation_id=h.id WHERE h.deleted_at IS NULL ORDER BY h.id DESC")->fetchAll();
            view('water/results-list',['title'=>'Hasil Simulasi','rows'=>$rows]);return;
        }
        $header=Database::query("SELECT h.*,r.* FROM simulation_headers h JOIN simulation_results r ON r.simulation_id=h.id WHERE h.id=? AND h.deleted_at IS NULL",[$id])->fetch();
        if(!$header){http_response_code(404);view('errors/404',['title'=>'Hasil Tidak Ditemukan']);return;}
        $sources=Database::query("SELECT * FROM simulation_sources WHERE simulation_id=? ORDER BY effective_flow_lps DESC",[$id])->fetchAll();
        $areas=Database::query("SELECT * FROM simulation_service_areas WHERE simulation_id=? ORDER BY fulfillment_percent",[$id])->fetchAll();
        $reservoirs=Database::query("SELECT * FROM simulation_reservoirs WHERE simulation_id=?",[$id])->fetchAll();
        $steps=Database::query("SELECT * FROM simulation_time_steps WHERE simulation_id=? ORDER BY step_number",[$id])->fetchAll();
        $routes=Database::query("SELECT * FROM simulation_routes WHERE simulation_id=?",[$id])->fetchAll();
        view('water/result',compact('header','sources','areas','reservoirs','steps','routes')+['title'=>'Hasil '.$header['simulation_number']]);
    }

    public function sensorMonitoring(): void
    {
        require_auth();
        $rows=$this->latestSensorRows();
        view('water/sensors',['title'=>'Monitoring Sensor Air','rows'=>$rows]);
    }

    public function sensorMonitoringData(): void
    {
        require_auth();
        json_response([
            'success'=>true,
            'updated_at'=>date('Y-m-d H:i:s'),
            'rows'=>$this->latestSensorRows(),
        ]);
    }

    public function reports(): void
    {
        require_auth();
        $summary=Database::query("SELECT h.simulation_number,h.name,h.simulation_date,h.season,r.total_source_flow_lps,r.total_effective_flow_lps,r.total_demand_lps,r.total_delivered_lps,r.surplus_deficit_lps,r.fulfillment_percent,r.result_status FROM simulation_headers h JOIN simulation_results r ON r.simulation_id=h.id WHERE h.deleted_at IS NULL ORDER BY h.id DESC")->fetchAll();
        view('water/reports',['title'=>'Laporan Pengelolaan Air','summary'=>$summary]);
    }

    private function resolveSourceFlow(array $source,string $mode,mixed $manual): float
    {
        return max(0,match($mode){
            'minimum'=>(float)$source['min_flow_lps'],'maximum'=>(float)$source['max_flow_lps'],
            'sensor_last'=>(float)($source['current_sensor_flow_lps']??$source['normal_flow_lps']),
            'manual'=>is_numeric($manual)?(float)$manual:0,default=>(float)$source['normal_flow_lps'],
        });
    }
    private function serviceStatus(float $percent): string {return $percent<=0?'tidak_terlayani':($percent<80?'sangat_kurang':($percent<100?'kurang':($percent<120?'cukup':'sangat_cukup')));}
    private function latestSensorRows(): array
    {
        return Database::query("SELECT sr.recorded_at,d.code device_code,d.name device_name,s.code sensor_code,s.name sensor_name,sr.calibrated_value,sr.unit,sr.battery_voltage,sr.signal_strength,sr.quality_status FROM sensor_readings sr JOIN devices d ON d.id=sr.device_id JOIN sensors s ON s.id=sr.sensor_id ORDER BY sr.recorded_at DESC LIMIT 200")->fetchAll();
    }
    private function recommend(float $fulfillment,float $surplus,array|false|null $reservoir,float $final,float $capacity,float $loss,float $effective): array
    {
        $items=[];if($fulfillment<100)$items[]='Aktifkan sumber alternatif atau kurangi alokasi wilayah prioritas rendah.';
        if($fulfillment<80)$items[]='Debit sumber belum mencukupi kebutuhan layanan pada skenario ini.';
        if($loss>15)$items[]='Periksa jaringan karena persentase kehilangan air tinggi.';
        if($reservoir&&$final<=0)$items[]='Reservoir diperkirakan kosong; tambah debit masuk atau kurangi debit keluar.';
        if($reservoir&&$capacity>0&&$final/$capacity>0.95)$items[]='Reservoir berpotensi meluap; atur ulang alokasi dan jam operasi.';
        if(!$items)$items[]='Ketersediaan air mencukupi. Pertahankan sumber cadangan dan pemantauan sensor.';
        return $items;
    }
}

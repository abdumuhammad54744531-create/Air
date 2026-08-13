<?php
declare(strict_types=1);

namespace App\Services;

final class DesignValidationService
{
    public function validateConfiguration(array $analysis,array $criteria,array $pipeInputs,array $catalogById): array
    {
        $errors=[];$add=function(string $field,string $message,?string $object=null)use(&$errors){$errors[]=['field'=>$field,'message'=>$message,'object'=>$object];};
        if(trim((string)($analysis['name']??''))==='')$add('name','Nama analisis wajib diisi.');
        if(!in_array(($analysis['hydraulic_method']??''),['H-W','D-W'],true))$add('hydraulic_method','Metode hidraulika tidak valid.');
        if((float)($criteria['minimum_velocity_mps']??0)>(float)($criteria['maximum_velocity_mps']??0))$add('minimum_velocity_mps','Kecepatan minimum tidak boleh melebihi kecepatan maksimum.');
        if((float)($criteria['minimum_pressure_m']??0)>(float)($criteria['maximum_pressure_m']??0))$add('minimum_pressure_m','Tekanan minimum tidak boleh melebihi tekanan maksimum.');
        $weights=(array)($analysis['weights']??[]);if($weights&&abs(array_sum(array_map('floatval',$weights))-100)>.01)$add('optimization_weights','Jumlah bobot multi-kriteria harus 100%.');
        foreach($pipeInputs as $pipe){
            $object=(string)($pipe['route_name']??('Ruas '.($pipe['network_link_id']??'')));
            if((float)($pipe['pipe_length_m']??0)<=0)$add('pipe_length_m','Panjang pipa harus lebih besar dari nol.',$object);
            $origin=(string)($pipe['origin_key']??(($pipe['origin_type']??'').':'.($pipe['origin_id']??'')));
            $destination=(string)($pipe['destination_key']??(($pipe['destination_type']??'').':'.($pipe['destination_id']??'')));
            if($origin!==':'&&$destination!==':'&&$origin===$destination)$add('endpoint','Node awal dan akhir tidak boleh sama.',$object);
            $allowed=array_values(array_filter(array_map('intval',(array)($pipe['allowed_catalog_ids']??[]))));
            if(($pipe['is_diameter_locked']??false)&&!(int)($pipe['fixed_catalog_id']??0))$add('fixed_catalog_id','Diameter terkunci tetapi ukuran tetap belum dipilih.',$object);
            if(!($pipe['is_diameter_locked']??false)&&!$allowed)$add('allowed_catalog_ids','Rentang diameter kandidat kosong.',$object);
            foreach($allowed as $catalogId)if(!isset($catalogById[$catalogId]))$add('allowed_catalog_ids','Diameter kandidat tidak ditemukan pada master.',$object);
            if((float)($pipe['minimum_dn_mm']??0)>(float)($pipe['maximum_dn_mm']??PHP_FLOAT_MAX))$add('diameter_range','Diameter minimum lebih besar dari diameter maksimum.',$object);
            if(($analysis['design_mode']??'AUTO_DESIGN')==='MANUAL_CHECK'&&!(int)($pipe['is_diameter_locked']??0))$add('is_diameter_locked','Mode pemeriksaan manual mengharuskan diameter tetap dipilih.',$object);
        }
        return ['valid'=>!$errors,'errors'=>$errors];
    }

    public function evaluateScenario(array $nodeResults,array $linkResults,array $criteria,array $pipeCatalog): array
    {
        $reasons=[];$warnings=[];$nodeRows=[];$linkRows=[];$pressures=[];$velocities=[];$headlosses=[];
        $minimumPressure=(float)($criteria['minimum_pressure_m']??10);$maximumPressure=(float)($criteria['maximum_pressure_m']??60);
        $minimumVelocity=(float)($criteria['minimum_velocity_mps']??.6);$maximumVelocity=(float)($criteria['maximum_velocity_mps']??2);
        $maximumUnitHeadloss=(float)($criteria['maximum_unit_headloss_m_per_km']??10);
        foreach($nodeResults as $key=>$row){
            if(!isset($row['pressure_m']))continue;$pressure=(float)$row['pressure_m'];$pressures[]=$pressure;$status='PASS';
            if(in_array(($row['node_type']??'junction'),['source','reservoir','tank'],true)||($row['entity_type']??'node')==='source'){$nodeRows[$key]=$row+['status'=>'SOURCE_BOUNDARY'];array_pop($pressures);continue;}
            // Batas tekanan pelayanan hanya berlaku pada junction yang benar-benar
            // mengambil air. Junction tanpa demand (mis. discharge pompa/manifold)
            // adalah titik transmisi; keselamatannya dinilai melalui kelas tekanan
            // pipa yang terhubung, bukan batas tekanan pelanggan.
            $requestedDemand=max(0,(float)($row['requested_demand_lps']??$row['base_demand_lps']??$row['demand_lps']??0));
            if($requestedDemand<=1e-9){$status='TRANSIT';array_pop($pressures);}
            elseif($pressure<$minimumPressure){$status='PRESSURE_LOW';$reasons[]="Tekanan node {$key} kurang ({$pressure} m).";}
            elseif($pressure>$maximumPressure){$status='PRESSURE_HIGH';$reasons[]="Tekanan node {$key} berlebih ({$pressure} m).";}
            if((float)($row['demand_deficit_lps']??0)>(float)($criteria['continuity_tolerance']??.001)){$status='DEMAND_NOT_MET';$reasons[]="Demand node {$key} tidak terpenuhi (defisit {$row['demand_deficit_lps']} L/s).";}
            $nodeRows[$key]=$row+['status'=>$status];
        }
        foreach($linkResults as $key=>$row){
            // Desain otomatis hanya menilai ruas PIPE yang memiliki kandidat
            // diameter. Pompa/valve tetap dijalankan oleh mesin hidraulika,
            // tetapi bukan objek desain diameter pipa.
            $catalog=$pipeCatalog[$key]??null;
            if(!$catalog)continue;
            $velocity=abs((float)($row['velocity_mps']??0));$headloss=abs((float)($row['headloss_m']??0));$unit=abs((float)($row['unit_headloss_m_per_km']??0));
            $velocities[]=$velocity;$headlosses[]=$headloss;$status='PASS';
            if($velocity>$maximumVelocity){$status='VELOCITY_HIGH';$reasons[]="Kecepatan ruas {$key} terlalu tinggi ({$velocity} m/s).";}
            elseif($velocity<$minimumVelocity){
                $status='VELOCITY_LOW';
                if(($criteria['minimum_velocity_active']??true)&&!($criteria['allow_low_velocity']??false))$reasons[]="Kecepatan ruas {$key} terlalu rendah ({$velocity} m/s).";
                else $warnings[]="Kecepatan ruas {$key} rendah ({$velocity} m/s).";
            }
            if(($criteria['maximum_unit_headloss_active']??true)&&$unit>$maximumUnitHeadloss){if($row['fixed_head_boundary_link']??false){if($status==='PASS')$status='HEADLOSS_BOUNDARY_WARNING';$warnings[]="Headloss ruas {$key} {$unit} m/km melebihi patokan, tetapi jalur menghubungkan dua muka energi tetap sehingga diameter tidak dapat menurunkan kemiringan energi tersebut.";}else{$status='HEADLOSS_HIGH';$reasons[]="Headloss ruas {$key} berlebih ({$unit} m/km).";}}
            if($catalog&&isset($row['start_pressure_m'],$row['end_pressure_m'])){
                if(($catalog['transient_status']??'')==='INCOMPLETE')$warnings[]="Water hammer ruas {$key} belum dapat dihitung; data kecepatan gelombang/perubahan kecepatan belum lengkap dan hanya allowance manual yang dipakai.";
                $check=(new PipeSizingService())->pressureClassCheck((float)$row['start_pressure_m'],(float)$row['end_pressure_m'],(float)$catalog['allowable_pressure_bar'],(float)($criteria['pressure_safety_factor']??1),(float)($catalog['transient_allowance_bar']??0));
                if(!$check['passed']){$status='PRESSURE_CLASS_FAIL';$reasons[]="Kelas tekanan ruas {$key} tidak cukup.";}
                $row['design_pressure_bar']=$check['design_pressure_bar'];
            }
            $linkRows[$key]=$row+['status'=>$status];
        }
        $reasons=array_values(array_unique($reasons));$warnings=array_values(array_unique($warnings));
        return ['passed'=>!$reasons,'status'=>$reasons?'FAIL':($warnings?'WARNING':'PASS'),'reasons'=>$reasons,'warnings'=>$warnings,'nodes'=>$nodeRows,'links'=>$linkRows,
            'metrics'=>['minimum_pressure_m'=>$pressures?min($pressures):null,'maximum_pressure_m'=>$pressures?max($pressures):null,'minimum_velocity_mps'=>$velocities?min($velocities):null,'maximum_velocity_mps'=>$velocities?max($velocities):null,'total_headloss_m'=>array_sum($headlosses)]];
    }

    public function ensureFailedCandidateCannotWin(array $alternatives): array
    {
        return array_values(array_filter($alternatives,fn($item)=>($item['status']??'FAIL')==='PASS'));
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\DemandCalculationService;
use App\Services\DesignOptimizationService;
use App\Services\DesignValidationService;
use App\Services\HydraulicNetworkService;
use App\Services\PipeSizingService;
use App\Services\PumpDesignService;
use App\Services\ReservoirSizingService;
use PDO;
use Throwable;

final class AutomaticDesignController
{
    private const CRITERIA=[
        'minimum_velocity_mps'=>['Kecepatan minimum',.6,'m/s'], 'maximum_velocity_mps'=>['Kecepatan maksimum',2,'m/s'],
        'target_velocity_mps'=>['Kecepatan target',1.2,'m/s'], 'minimum_pressure_m'=>['Tekanan minimum',10,'m'],
        'target_pressure_m'=>['Tekanan target',20,'m'], 'maximum_pressure_m'=>['Tekanan maksimum',60,'m'],
        'maximum_unit_headloss_m_per_km'=>['Headloss maksimum',10,'m/km'], 'pressure_safety_factor'=>['Faktor keamanan tekanan',1.2,'-'],
        'continuity_tolerance'=>['Toleransi kontinuitas',.001,'L/s'], 'energy_tolerance'=>['Toleransi energi',.001,'m'],
        'allow_low_velocity_minimum_demand'=>['Izinkan kecepatan rendah saat debit minimum',1,'0/1'],
        'minimum_velocity_active'=>['Aktifkan batas kecepatan minimum',1,'0/1'], 'minimum_demand_velocity_mps'=>['Kecepatan minimum skenario rendah',.3,'m/s'],
        'reservoir_volume_safety_factor'=>['Faktor keamanan volume reservoir',1.1,'-'],
    ];

    public function index(): void
    {
        require_auth(['super_admin','administrator','operator']);
        $this->ensureSchema();
        $projects=Database::query("SELECT * FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,name")->fetchAll();
        $projectId=(int)($_GET['project_id']??$_SESSION['network_project_id']??($projects[0]['id']??0));
        if($projectId)$_SESSION['network_project_id']=$projectId;
        $analyses=Database::query("SELECT a.*,p.name project_name FROM auto_design_analyses a JOIN network_projects p ON p.id=a.project_id WHERE a.deleted_at IS NULL AND (?=0 OR a.project_id=?) ORDER BY a.updated_at DESC",[$projectId,$projectId])->fetchAll();
        $analysisId=(int)($_GET['analysis_id']??($analyses[0]['id']??0));
        $data=['analysis'=>null,'criteria'=>$this->defaultCriteria(),'pipeInputs'=>[],'scenarios'=>[],'alternatives'=>[],'reservoirAlternatives'=>[],'selectedPipes'=>[],'selectedNodes'=>[]];
        if($analysisId)$data=$this->loadAnalysis($analysisId);
        $catalog=Database::query("SELECT * FROM pipe_diameter_catalog WHERE deleted_at IS NULL ORDER BY material,dn_mm,pressure_class")->fetchAll();
        view('water/automatic-design',array_merge($data,['title'=>'Desain Otomatis Pipa & Reservoir','projects'=>$projects,'projectId'=>$projectId,'analyses'=>$analyses,'catalog'=>$catalog]));
    }

    public function save(): never
    {
        require_auth(['super_admin','administrator','operator']);verify_csrf();$this->ensureSchema();
        $pdo=Database::connection();$id=(int)($_POST['analysis_id']??0);$creating=$id===0;
        try{
            $pdo->beginTransaction();
            $values=$this->analysisValues($_POST);
            if(!$id){
                $columns=array_keys($values);$sql="INSERT INTO auto_design_analyses(".implode(',',$columns).",created_by,created_at,updated_at) VALUES(".implode(',',array_fill(0,count($columns),'?')).",?,NOW(),NOW())";
                Database::query($sql,[...array_values($values),user()['id']]);$id=(int)$pdo->lastInsertId();
            }else{
                $this->ownedAnalysis($id);$sets=implode(',',array_map(fn($c)=>"$c=?",array_keys($values)));
                Database::query("UPDATE auto_design_analyses SET $sets,updated_at=NOW() WHERE id=?",[...array_values($values),$id]);
            }
            $this->saveCriteria($id,$_POST['criteria']??[]);
            $this->synchronizePipeInputs($id,(int)$values['project_id'],$_POST);
            $this->saveScenarios($id,$_POST['scenarios']??[],$values);
            Database::query("UPDATE auto_design_analyses SET status='READY',last_error=NULL WHERE id=?",[$id]);
            $pdo->commit();activity($creating?'tambah':'edit','automatic-design',$id,null,$values);
            flash('success','Konfigurasi desain tersimpan dan siap dihitung.');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('danger','Konfigurasi belum tersimpan: '.$e->getMessage());}
        redirect('automatic-design?project_id='.(int)($_POST['project_id']??0).'&analysis_id='.$id);
    }

    public function run(): never
    {
        require_auth(['super_admin','administrator','operator']);verify_csrf();$this->ensureSchema();$id=(int)($_POST['analysis_id']??0);$pdo=Database::connection();
        try{
            $result=$this->calculateAndStore($id);
            $limitNote=$result['truncated']?" Dari estimasi {$result['estimated_combinations']} kombinasi, pengujian dibatasi oleh iterasi/kombinasi/timeout.":'';flash('success',"Perhitungan selesai: {$result['tested_combinations']} kombinasi diuji menggunakan EPANET.$limitNote");
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($id)Database::query("UPDATE auto_design_analyses SET status='FAILED',last_error=? WHERE id=?",[substr($e->getMessage(),0,65000),$id]);flash('danger','Desain otomatis gagal: '.$e->getMessage());}
        redirect('automatic-design?analysis_id='.$id.'#hasil-desain');
    }

    public function select(): never
    {
        require_auth(['super_admin','administrator','operator']);verify_csrf();$id=(int)($_POST['analysis_id']??0);$type=(string)($_POST['selection_type']??'pipe');$selected=(int)($_POST['selected_id']??0);
        $analysis=$this->ownedAnalysis($id);$table=$type==='reservoir'?'auto_design_reservoir_alternatives':'auto_design_alternatives';
        $row=Database::query("SELECT * FROM $table WHERE id=? AND analysis_id=? AND status IN ('PASS','WARNING')",[$selected,$id])->fetch();
        if(!$row){flash('danger','Alternatif gagal tidak dapat dipilih.');redirect('automatic-design?analysis_id='.$id);}
        Database::query("UPDATE $table SET is_selected=0 WHERE analysis_id=?",[$id]);Database::query("UPDATE $table SET is_selected=1 WHERE id=?",[$selected]);
        Database::query("UPDATE auto_design_analyses SET ".($type==='reservoir'?'selected_reservoir_alternative_id':'selected_alternative_id')."=? WHERE id=?",[$selected,$id]);
        activity('pilih-alternatif','automatic-design',$id,null,['type'=>$type,'selected_id'=>$selected]);flash('success','Alternatif terpilih berhasil disimpan.');redirect('automatic-design?project_id='.$analysis['project_id'].'&analysis_id='.$id.'#hasil-desain');
    }

    public function apply(): never
    {
        require_auth(['super_admin','administrator']);verify_csrf();$this->ensureSchema();$id=(int)($_POST['analysis_id']??0);$analysis=$this->ownedAnalysis($id);$alternativeId=(int)($analysis['selected_alternative_id']??0);
        if(!$alternativeId){flash('danger','Pilih alternatif pipa sebelum menerapkannya ke proyek.');redirect('automatic-design?analysis_id='.$id.'#hasil-desain');}
        try{$count=$this->applyAlternativeToProject($analysis,$alternativeId);flash('success',$count.' diameter hasil desain diterapkan ke jaringan proyek. Data sebelumnya tetap terekam pada log aktivitas.');}catch(Throwable $e){flash('danger','Desain tidak dapat diterapkan: '.$e->getMessage());}
        redirect('automatic-design?project_id='.$analysis['project_id'].'&analysis_id='.$id.'#hasil-desain');
    }

    public function quick(): never
    {
        require_auth(['super_admin','administrator']);verify_csrf();$this->ensureSchema();$projectId=(int)($_POST['project_id']??0);$pdo=Database::connection();$analysisId=0;$async=($_POST['_async']??'')==='1';
        try{
            if(($_POST['quick_mode']??'')!=='DESIGN'||($_POST['confirm_apply']??'')!=='1')throw new \RuntimeException('Konfirmasi penerapan desain belum diberikan.');
            $pipeMaterial=trim((string)($_POST['pipe_material']??'ALL'))?:'ALL';
            $diameterMode=($_POST['diameter_mode']??'FREE')==='CATALOG'?'CATALOG':'FREE';
            if($diameterMode==='FREE'&&$pipeMaterial==='ALL')throw new \RuntimeException('Mode diameter bebas memerlukan satu jenis material agar kekasaran pipa dapat dihitung.');
            $catalogSql="SELECT id FROM pipe_diameter_catalog WHERE is_active=1 AND deleted_at IS NULL";$catalogParams=[];
            if($pipeMaterial!=='ALL'){$catalogSql.=" AND material=?";$catalogParams[]=$pipeMaterial;}
            $catalogIds=array_map('intval',Database::query($catalogSql,$catalogParams)->fetchAll(PDO::FETCH_COLUMN));
            if(!$catalogIds)throw new \RuntimeException($pipeMaterial==='ALL'?'Katalog diameter aktif masih kosong.':'Tidak ada diameter aktif untuk jenis pipa '.$pipeMaterial.'.');
            $pipeInputs=[];foreach(Database::query("SELECT id FROM distribution_networks WHERE project_id=? AND deleted_at IS NULL AND COALESCE(link_type,'PIPE')='PIPE'",[$projectId])->fetchAll() as $link)$pipeInputs[(int)$link['id']]=['allowed_catalog_ids'=>$catalogIds,'diameter_group'=>$diameterMode==='FREE'?'__FREE__':null];
            if(!$pipeInputs)throw new \RuntimeException('Proyek belum memiliki pipa yang dapat didesain.');
            $materialNote=$pipeMaterial==='ALL'?'semua material aktif':$pipeMaterial;$diameterNote=$diameterMode==='FREE'?'diameter bebas hasil perhitungan':'diameter standar katalog';
            $allowedGoals=['SMALLEST_DIAMETER','BALANCED','LOWEST_INITIAL_COST','LOWEST_HEADLOSS','TARGET_PRESSURE','TARGET_VELOCITY'];$requestedGoal=(string)($_POST['optimization_goal']??'BALANCED');if(!in_array($requestedGoal,$allowedGoals,true))$requestedGoal='BALANCED';
            $designReservoir=isset($_POST['design_reservoir']);
            $input=array_merge($_POST,['project_id'=>$projectId,'analysis_number'=>'CEPAT-'.date('Ymd-His'),'name'=>'Desain Cepat Jaringan '.date('d/m/Y H:i'),'analysis_date'=>date('Y-m-d'),'notes'=>'Desain terpadu pipa, pompa, dan bak; sumber diasumsikan tersedia kontinu sesuai kapasitas sumber; '.$diameterNote.'; kandidat jenis pipa: '.$materialNote,'design_mode'=>'AUTO_DESIGN','optimization_goal'=>$requestedGoal,'demand_method'=>'PROJECT_DATA','population_projection_method'=>'MANUAL','projected_population'=>0,'max_day_factor'=>1,'peak_hour_factor'=>1,'reservoir_method'=>$designReservoir?'DETENTION_TIME':'DAILY_PERCENT','reservoir_operating_hours'=>$designReservoir?max(.5,(float)($_POST['reservoir_storage_hours']??6)):0,'reservoir_storage_percent'=>0,'reservoir_reserve_percent'=>$designReservoir?max(0,(float)($_POST['reservoir_reserve_percent']??10)):0,'reservoir_shape'=>'RECTANGLE','reservoir_compartments'=>1,'reservoir_freeboard_m'=>max(0,(float)($_POST['reservoir_freeboard_m']??.5)),'length_min_m'=>2,'length_max_m'=>30,'length_step_m'=>.5,'width_min_m'=>2,'width_max_m'=>30,'width_step_m'=>.5,'height_min_m'=>2,'height_max_m'=>8,'height_step_m'=>.25,'max_alternatives'=>5,'max_iterations'=>max(1,(int)($_POST['max_iterations']??150)),'max_combinations'=>max(1,(int)($_POST['max_combinations']??300)),'calculation_timeout_seconds'=>max(20,(int)($_POST['calculation_timeout_seconds']??120))]);
            $values=$this->analysisValues($input);$columns=array_keys($values);$pdo->beginTransaction();Database::query("INSERT INTO auto_design_analyses(".implode(',',$columns).",created_by,created_at,updated_at) VALUES(".implode(',',array_fill(0,count($columns),'?')).",?,NOW(),NOW())",[...array_values($values),user()['id']]);$analysisId=(int)$pdo->lastInsertId();
            $strictBalanced=$requestedGoal==='BALANCED';
            $criteria=['minimum_velocity_mps'=>(float)($_POST['minimum_velocity_mps']??.6),'maximum_velocity_mps'=>(float)($_POST['maximum_velocity_mps']??2),'target_velocity_mps'=>(float)($_POST['target_velocity_mps']??1.2),'minimum_pressure_m'=>(float)($_POST['minimum_pressure_m']??10),'target_pressure_m'=>(float)($_POST['target_pressure_m']??20),'maximum_pressure_m'=>(float)($_POST['maximum_pressure_m']??60),'maximum_unit_headloss_m_per_km'=>(float)($_POST['maximum_unit_headloss_m_per_km']??10),'minimum_velocity_active'=>$strictBalanced?1:0,'allow_low_velocity_minimum_demand'=>$strictBalanced?0:1,'pressure_safety_factor'=>1.2];$this->saveCriteria($analysisId,$criteria);$this->synchronizePipeInputs($analysisId,$projectId,['pipes'=>$pipeInputs]);
            $multiplier=max(0.01,(float)($_POST['demand_multiplier']??1));$minimumDemandFactor=max(.01,min(1,(float)($_POST['minimum_demand_factor']??.75)));$scenarios=[['code'=>'PEAK','name'=>'Demand desain','scenario_type'=>'PEAK_HOUR','demand_multiplier'=>$multiplier,'source_head_adjustment_m'=>0,'fire_flow_lps'=>(float)($_POST['fire_flow_lps']??0),'is_active'=>1,'is_required'=>1]];if($strictBalanced)$scenarios[]=['code'=>'MIN','name'=>'Jam kebutuhan rendah (kecepatan wajib)','scenario_type'=>'MINIMUM_DEMAND','demand_multiplier'=>$multiplier*$minimumDemandFactor,'source_head_adjustment_m'=>0,'fire_flow_lps'=>0,'is_active'=>1,'is_required'=>1];$this->saveScenarios($analysisId,$scenarios,$values);Database::query("UPDATE auto_design_analyses SET status='READY' WHERE id=?",[$analysisId]);$pdo->commit();

            $pumpDesigns=[];$modelOverride=(new HydraulicNetworkService())->loadModel($projectId);
            $availableSource=0.0;foreach($modelOverride['nodes'] as $node)if(($node['entity_type']??'')==='source')$availableSource+=max((float)($node['maximum_flow_lps']??0),(float)($node['normal_flow_lps']??0));
            $requiredAverage=(float)($values['average_demand_lps']??0)*$multiplier;
            if($availableSource>0&&$requiredAverage>$availableSource+1e-9)throw new \RuntimeException('Kapasitas sumber kontinu '.$availableSource.' L/s lebih kecil dari kebutuhan rata-rata '.$requiredAverage.' L/s. Dimensi pipa, pompa, dan bak tidak dapat menutup kekurangan air baku.');
            if(isset($_POST['design_pumps'])){
                $pumpService=new PumpDesignService();
                $pumpDesigns=$pumpService->design($modelOverride,array_merge($_POST,['demand_multiplier'=>$multiplier,'target_pressure_m'=>(float)($_POST['target_pressure_m']??20)]));
                if(!$pumpDesigns)throw new \RuntimeException('Desain pompa dipilih, tetapi proyek belum memiliki jalur berjenis PUMP.');
                $maximumPumpFlow=max(array_map(fn($design)=>(float)$design['flow_lps'],$pumpDesigns));
                if($availableSource>0&&$maximumPumpFlow>$availableSource+1e-9)throw new \RuntimeException('Debit pompa rancangan '.$maximumPumpFlow.' L/s melebihi kapasitas sumber tersedia '.$availableSource.' L/s. Tambah jam operasi pompa atau kapasitas sumber.');
                $modelOverride=$pumpService->applyToModel($modelOverride,$pumpDesigns);
                $modelOverride=$pumpService->applyDesignFlows($modelOverride,$pumpDesigns,$multiplier);
            }

            $result=$this->calculateAndStore($analysisId,$modelOverride);$recommended=(int)(Database::query("SELECT id FROM auto_design_alternatives WHERE analysis_id=? AND is_recommended=1 AND status IN ('PASS','WARNING') LIMIT 1",[$analysisId])->fetchColumn()?:0);if(!$recommended){$reason=trim((string)(Database::query("SELECT failure_reasons FROM auto_design_alternatives WHERE analysis_id=? ORDER BY rank_number,id LIMIT 1",[$analysisId])->fetchColumn()?:''));$freeNote=$diameterMode==='FREE'?' Diameter bebas berarti mesin boleh memakai angka desimal apa pun, tetapi hasil tetap wajib memenuhi semua batas hidraulika yang dipilih.':'';throw new \RuntimeException('Tidak ada kombinasi diameter '.$materialNote.' yang memenuhi batas keselamatan.'.$freeNote.($reason!==''?' Penyebab alternatif terdekat: '.str_replace("\n",' ',$reason):''));}
            Database::query("UPDATE auto_design_alternatives SET is_selected=(id=?) WHERE analysis_id=?",[$recommended,$analysisId]);Database::query("UPDATE auto_design_analyses SET selected_alternative_id=? WHERE id=?",[$recommended,$analysisId]);$count=$this->applyAlternativeToProject($this->ownedAnalysis($analysisId),$recommended,$pumpDesigns);
            $pumpNote=$pumpDesigns?' '.count($pumpDesigns).' kurva/daya pompa,':'';$reservoirNote=$designReservoir?' dan dimensi bak aman':'';$message="Desain terpadu selesai memakai $diameterNote dan material $materialNote. {$result['tested_combinations']} kombinasi diuji; $count diameter/kelas pipa,$pumpNote$reservoirNote diterapkan.";
            if($async)json_response(['success'=>true,'message'=>$message,'redirect'=>url('distribution-networks?project='.$projectId),'analysis_id'=>$analysisId,'tested_combinations'=>$result['tested_combinations'],'pipe_count'=>$count,'pump_count'=>count($pumpDesigns)]);
            flash('success',$message.' Jalankan Cek Analisis untuk melihat output baru.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();if($analysisId)Database::query("UPDATE auto_design_analyses SET status='FAILED',last_error=? WHERE id=?",[substr($e->getMessage(),0,65000),$analysisId]);
            if($async)json_response(['success'=>false,'message'=>'Desain cepat gagal: '.$e->getMessage(),'analysis_id'=>$analysisId],422);
            flash('danger','Desain cepat gagal: '.$e->getMessage());
        }
        redirect('distribution-networks?project='.$projectId);
    }

    public function catalog(): never
    {
        require_auth(['super_admin','administrator']);verify_csrf();$this->ensureSchema();$id=(int)($_POST['catalog_id']??0);$method=(string)($_POST['_method']??'');
        if($id&&$method==='DELETE'){Database::query("UPDATE pipe_diameter_catalog SET is_active=0,deleted_at=NOW() WHERE id=?",[$id]);flash('success','Ukuran katalog diarsipkan.');redirect('automatic-design#katalog-pipa');}
        $fields=['material','dn_mm','outside_diameter_mm','wall_thickness_mm','inside_diameter_mm','pressure_class','allowable_pressure_bar','hazen_williams_c','darcy_roughness_mm','price_per_meter','description'];$values=[];foreach($fields as $field)$values[$field]=trim((string)($_POST[$field]??''))?:null;
        if(!$values['material']||!$values['dn_mm']||!$values['outside_diameter_mm']||$values['wall_thickness_mm']===null||!$values['inside_diameter_mm']||!$values['pressure_class']){flash('danger','Material, DN, OD, tebal dinding, diameter dalam, dan kelas tekanan wajib diisi.');redirect('automatic-design#katalog-pipa');}
        try{(new PipeSizingService())->validateDimensions((float)$values['outside_diameter_mm'],(float)$values['wall_thickness_mm'],(float)$values['inside_diameter_mm']);}catch(Throwable $e){flash('danger',$e->getMessage());redirect('automatic-design#katalog-pipa');}
        if($id){$set=implode(',',array_map(fn($x)=>"$x=?",$fields));Database::query("UPDATE pipe_diameter_catalog SET $set,is_active=1,updated_at=NOW() WHERE id=?",[...array_values($values),$id]);}
        else Database::query("INSERT INTO pipe_diameter_catalog(".implode(',',$fields).",is_active,is_custom,created_by,created_at,updated_at) VALUES(".implode(',',array_fill(0,count($fields),'?')).",1,1,?,NOW(),NOW())",[...array_values($values),user()['id']]);
        flash('success','Master diameter pipa berhasil disimpan.');redirect('automatic-design#katalog-pipa');
    }

    public function report(int $id): void
    {
        require_auth(['super_admin','administrator','operator']);$data=$this->loadAnalysis($id);$alternativeId=(int)($_GET['alternative_id']??$data['analysis']['selected_alternative_id']??0);
        if(!$alternativeId)$alternativeId=(int)(Database::query("SELECT id FROM auto_design_alternatives WHERE analysis_id=? AND is_recommended=1 LIMIT 1",[$id])->fetchColumn()?:0);
        $alternative=$alternativeId?Database::query("SELECT * FROM auto_design_alternatives WHERE id=? AND analysis_id=?",[$alternativeId,$id])->fetch():null;
        $pipes=$alternative?Database::query("SELECT p.*,n.route_name FROM auto_design_alternative_pipes p LEFT JOIN distribution_networks n ON n.id=p.network_link_id WHERE p.alternative_id=? ORDER BY p.scenario_code,n.route_name",[$alternativeId])->fetchAll():[];
        $nodes=$alternative?Database::query("SELECT * FROM auto_design_alternative_nodes WHERE alternative_id=? ORDER BY scenario_code,node_name",[$alternativeId])->fetchAll():[];
        $reservoir=Database::query("SELECT * FROM auto_design_reservoir_alternatives WHERE analysis_id=? ORDER BY is_selected DESC,is_recommended DESC,rank_number LIMIT 1",[$id])->fetch();
        view('water/automatic-design-report',array_merge($data,compact('alternative','pipes','nodes','reservoir'),['title'=>'Laporan Desain Otomatis']),'layouts/admin');
    }

    private function calculateAndStore(int $id,?array $modelOverride=null): array
    {
        $pdo=Database::connection();$data=$this->loadAnalysis($id);$analysis=$data['analysis'];Database::query("UPDATE auto_design_analyses SET status='RUNNING',last_error=NULL WHERE id=?",[$id]);
        $model=$modelOverride??(new HydraulicNetworkService())->loadModel((int)$analysis['project_id']);$configuredHead=$analysis['source_energy_basis']==='WATER_LEVEL'?(float)$analysis['source_normal_level_m']:(float)$analysis['source_total_head_m'];if($configuredHead!=0){foreach($model['nodes'] as &$node)if(in_array($node['node_type'],['source','reservoir'],true)||$node['entity_type']==='source'){$node['head_m']=$configuredHead;$node['total_head_m']=$configuredHead;}unset($node);}
        $totalDemand=0.0;foreach($model['nodes'] as $node)if(!in_array($node['node_type'],['source','reservoir','tank'],true))$totalDemand+=max(0,(float)($node['base_demand_lps']??0));$maximumMultiplier=1.0;foreach($data['scenarios'] as $scenario)if($scenario['is_active'])$maximumMultiplier=max($maximumMultiplier,(float)$scenario['demand_multiplier']);foreach($model['links'] as &$link)if(($link['link_type']??'PIPE')==='PIPE'&&(float)($link['planned_flow_lps']??0)<=0)$link['planned_flow_lps']=max(.001,$totalDemand*$maximumMultiplier);unset($link);
        $catalog=Database::query("SELECT * FROM pipe_diameter_catalog WHERE is_active=1 AND deleted_at IS NULL")->fetchAll();$catalogById=[];foreach($catalog as $row)$catalogById[(int)$row['id']]=$row;
        $validation=(new DesignValidationService())->validateConfiguration($analysis,$data['criteria'],$data['pipeInputs'],$catalogById);if(!$validation['valid'])throw new \RuntimeException(implode(' ',array_map(fn($x)=>(($x['object']??null)?$x['object'].': ':'').$x['message'],$validation['errors'])));
        $analysis['weights']=json_decode((string)($analysis['optimization_weights_json']??'{}'),true)?:[];$result=(new DesignOptimizationService())->optimize($model,$analysis,$data['criteria'],$data['pipeInputs'],$catalog,$data['scenarios']);$reservoirs=(float)$analysis['reservoir_total_required_m3']>0?$this->reservoirAlternatives($analysis):[];
        $pdo->beginTransaction();$old=Database::query("SELECT id FROM auto_design_alternatives WHERE analysis_id=?",[$id])->fetchAll(PDO::FETCH_COLUMN);if($old){$marks=implode(',',array_fill(0,count($old),'?'));Database::query("DELETE FROM auto_design_alternative_pipes WHERE alternative_id IN ($marks)",$old);Database::query("DELETE FROM auto_design_alternative_nodes WHERE alternative_id IN ($marks)",$old);}Database::query("DELETE FROM auto_design_alternatives WHERE analysis_id=?",[$id]);Database::query("DELETE FROM auto_design_reservoir_alternatives WHERE analysis_id=?",[$id]);foreach($result['alternatives'] as $alternative)$this->insertAlternative($id,$alternative);foreach($reservoirs as $alt)$this->insertReservoirAlternative($id,$alt);Database::query("UPDATE auto_design_analyses SET status='COMPLETED',last_error=NULL,updated_at=NOW() WHERE id=?",[$id]);$pdo->commit();activity('run','automatic-design',$id,null,['tested'=>$result['tested_combinations'],'elapsed'=>$result['elapsed_seconds']]);return $result;
    }

    private function applyAlternativeToProject(array $analysis,int $alternativeId,array $pumpDesigns=[]): int
    {
        $alternative=Database::query("SELECT * FROM auto_design_alternatives WHERE id=? AND analysis_id=? AND status IN ('PASS','WARNING') AND converged=1",[$alternativeId,$analysis['id']])->fetch();if(!$alternative)throw new \RuntimeException('Alternatif terpilih tidak layak diterapkan.');$peak=(string)(Database::query("SELECT code FROM auto_design_scenarios WHERE analysis_id=? AND is_active=1 ORDER BY (scenario_type='PEAK_HOUR') DESC,id LIMIT 1",[$analysis['id']])->fetchColumn()?:'');$sql="SELECT r.*,c.hazen_williams_c,c.darcy_roughness_mm FROM auto_design_alternative_pipes r LEFT JOIN pipe_diameter_catalog c ON c.id=r.catalog_id WHERE r.alternative_id=?".($peak!==''?" AND r.scenario_code=?":"");$rows=Database::query($sql,$peak!==''?[$alternativeId,$peak]:[$alternativeId])->fetchAll();if(!$rows)throw new \RuntimeException('Detail diameter alternatif tidak ditemukan.');
        $pdo=Database::connection();$pdo->beginTransaction();try{foreach($rows as $row){$rough=$analysis['hydraulic_method']==='D-W'?$row['darcy_roughness_mm']:$row['hazen_williams_c'];if($rough===null)$rough=$this->materialRoughness((string)$row['material'],(string)$analysis['hydraulic_method']);$classCode=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)($row['pressure_class']??''));$materialCode=$row['catalog_id']?'AUTO-CAT-'.$row['catalog_id']:'AUTO-FREE'.($classCode!==''?'-'.$classCode:'');Database::query("UPDATE distribution_networks SET pipe_diameter_mm=?,pipe_type=?,material_code=?,roughness_coefficient=?,planned_flow_lps=?,updated_at=NOW() WHERE id=? AND project_id=? AND deleted_at IS NULL",[$row['inside_diameter_mm'],$row['material'],$materialCode,$rough,abs((float)$row['flow_lps']),$row['network_link_id'],$analysis['project_id']]);}$reservoirId=$this->applyRecommendedReservoir((int)$analysis['id'],(int)$analysis['project_id']);if($reservoirId){$height=(float)(Database::query("SELECT height_m FROM reservoirs WHERE id=?",[$reservoirId])->fetchColumn()?:0);if($height>0)foreach($pumpDesigns as &$pumpDesign)if(!empty($pumpDesign['control_tank_key'])){$pumpDesign['pump_start_level_m']=round($height*.25,3);$pumpDesign['pump_stop_level_m']=round($height*.90,3);}unset($pumpDesign);}$this->persistPumpDesigns((int)$analysis['project_id'],$pumpDesigns);$pdo->commit();activity('terapkan-desain','automatic-design',(int)$analysis['id'],null,['alternative_id'=>$alternativeId,'pipe_count'=>count($rows),'pump_count'=>count($pumpDesigns),'reservoir_id'=>$reservoirId]);return count($rows);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function applyRecommendedReservoir(int $analysisId,int $projectId): ?int
    {
        $alternative=Database::query("SELECT * FROM auto_design_reservoir_alternatives WHERE analysis_id=? AND status IN ('PASS','WARNING') ORDER BY is_selected DESC,is_recommended DESC,rank_number,id LIMIT 1",[$analysisId])->fetch();
        if(!$alternative)return null;
        $reservoirId=(int)(Database::query("SELECT r.id FROM reservoirs r JOIN distribution_node_positions p ON p.node_type='reservoir' AND p.entity_id=r.id AND p.project_id=? WHERE r.deleted_at IS NULL ORDER BY r.id LIMIT 1",[$projectId])->fetchColumn()?:0);
        if(!$reservoirId)throw new \RuntimeException('Dimensi bak telah dihitung, tetapi proyek belum memiliki titik reservoir/bak yang dapat diperbarui.');
        $length=max(.01,(float)$alternative['length_or_diameter_m']);$width=max(.01,(float)($alternative['width_m']?:$alternative['length_or_diameter_m']));$effectiveHeight=max(.01,(float)$alternative['effective_height_m']);
        $effectiveVolume=(float)$alternative['effective_volume_m3'];$initialVolume=$effectiveVolume*.5;$initialLevel=$effectiveHeight*.5;
        $note="\nDesain otomatis bak: ukuran efektif {$length} x {$width} x {$effectiveHeight} m; freeboard ".(float)$alternative['freeboard_m']." m; kapasitas efektif {$effectiveVolume} m3; asumsi sumber tersedia kontinu.";
        Database::query("UPDATE reservoirs SET length_m=?,width_m=?,height_m=?,geometric_volume_m3=?,effective_percent=100,effective_capacity_m3=?,initial_volume_m3=?,initial_water_level_m=?,description=CONCAT(COALESCE(description,''),?),updated_at=NOW() WHERE id=? AND deleted_at IS NULL",[$length,$width,$effectiveHeight,$effectiveVolume,$effectiveVolume,$initialVolume,$initialLevel,$note,$reservoirId]);
        Database::query("UPDATE auto_design_reservoir_alternatives SET is_selected=(id=?) WHERE analysis_id=?",[(int)$alternative['id'],$analysisId]);
        return $reservoirId;
    }

    private function persistPumpDesigns(int $projectId,array $designs): void
    {
        foreach($designs as $design){
            $linkId=(int)($design['network_link_id']??0);if(!$linkId)continue;
            $code='AUTO-PUMP-'.$projectId.'-'.$linkId.'-'.date('YmdHis');$base=$code;$suffix=2;
            while(Database::query("SELECT COUNT(*) FROM hydraulic_curves WHERE code=?",[$code])->fetchColumn())$code=$base.'-'.$suffix++;
            $description='Desain otomatis; duty point Q '.number_format((float)$design['flow_lps'],3,'.','').' L/s, H '.number_format((float)$design['head_m'],3,'.','').' m, estimasi daya '.number_format((float)$design['estimated_power_kw'],3,'.','').' kW, asumsi operasi '.number_format((float)($design['operating_hours_day']??12),1,'.','').' jam/hari.';
            Database::query("INSERT INTO hydraulic_curves(code,name,curve_type,points_json,status,description,created_by,created_at,updated_at) VALUES(?,?,'PUMP',?,'aktif',?,?,NOW(),NOW())",[$code,'Kurva desain '.$design['route_name'],json_encode($design['points'],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),$description,user()['id']]);
            $curveId=(int)Database::connection()->lastInsertId();
            Database::query("UPDATE distribution_networks SET pump_curve_id=?,nominal_power_kw=NULL,planned_flow_lps=?,pump_capacity_lps=?,control_mode=?,start_level_m=?,stop_level_m=?,description=CONCAT(COALESCE(description,''),?),updated_at=NOW() WHERE id=? AND project_id=? AND link_type='PUMP' AND deleted_at IS NULL",[$curveId,$design['flow_lps'],$design['flow_lps'],!empty($design['control_tank_key'])?'TANK_LEVEL':'MANUAL',$design['pump_start_level_m']??null,$design['pump_stop_level_m']??null,"\n".$description,$linkId,$projectId]);
        }
    }

    private function materialRoughness(string $material,string $method): float
    {
        $field=$method==='D-W'?'darcy_roughness_mm':'hazen_williams_c';$value=Database::query("SELECT $field FROM pipe_diameter_catalog WHERE material=? AND is_active=1 AND deleted_at IS NULL AND $field IS NOT NULL ORDER BY id LIMIT 1",[$material])->fetchColumn();
        if($value!==false&&$value!==null)return (float)$value;
        return $method==='D-W'?.0015:150.0;
    }

    private function analysisValues(array $post): array
    {
        $projectId=(int)($post['project_id']??0);if(!$projectId)throw new \RuntimeException('Proyek jaringan wajib dipilih.');
        $demand=(new DemandCalculationService())->calculate([
            'base_year'=>(int)($post['base_year']??date('Y')),'design_year'=>(int)($post['design_year']??date('Y')+10),'initial_population'=>(float)($post['initial_population']??0),
            'population_growth_percent'=>(float)($post['population_growth_percent']??0),'population_projection_method'=>$post['population_projection_method']??'GEOMETRIC','projected_population'=>(float)($post['projected_population']??0),
            'domestic_lpd'=>(float)($post['domestic_lpd']??0),'non_domestic_percent'=>(float)($post['non_domestic_percent']??0),'water_loss_percent'=>(float)($post['water_loss_percent']??0),
            'max_day_factor'=>(float)($post['max_day_factor']??1.15),'peak_hour_factor'=>(float)($post['peak_hour_factor']??1.5),'fire_flow_lps'=>(float)($post['fire_flow_lps']??0),
        ]);
        $direct=(float)($post['direct_design_flow_lps']??0);if(($post['demand_method']??'')==='DIRECT'&&$direct>0){$demand['peak_hour_lps']=$direct;$demand['final_design_flow_lps']=$direct+$demand['fire_flow_lps'];}
        if(($post['demand_method']??'PROJECT_DATA')==='PROJECT_DATA'){$projectDemand=(float)(Database::query("SELECT COALESCE(SUM(base_demand_lps),0) FROM distribution_nodes WHERE project_id=? AND deleted_at IS NULL",[$projectId])->fetchColumn()?:0);if($projectDemand>0){$demand['peak_hour_lps']=$projectDemand;$demand['max_day_lps']=$projectDemand/max(.001,(float)($post['peak_hour_factor']??1.5));$demand['average_lps']=$demand['max_day_lps']/max(.001,(float)($post['max_day_factor']??1.15));$demand['average_m3_day']=$demand['average_lps']*86.4;$demand['final_design_flow_lps']=$projectDemand+$demand['fire_flow_lps'];}}
        $pattern=array_values(array_map('floatval',(array)($post['hourly_pattern']??[])));if(count($pattern)!==24)$pattern=array_fill(0,24,1);
        $massCurve=(new DemandCalculationService())->massCurveVolume($demand['average_m3_day'],$pattern);
        $volume=(new ReservoirSizingService())->requiredVolume(['method'=>$post['reservoir_method']??'DAILY_PERCENT','max_day_m3'=>$demand['max_day_lps']*86.4,'mass_curve_volume_m3'=>$massCurve['operational_volume_m3'],'design_flow_lps'=>$demand['final_design_flow_lps'],'storage_hours'=>(float)($post['reservoir_operating_hours']??6),'storage_percent'=>(float)($post['reservoir_storage_percent']??20),'reserve_percent'=>(float)($post['reservoir_reserve_percent']??10),'fire_volume_m3'=>(float)($post['reservoir_fire_volume_m3']??0),'emergency_volume_m3'=>(float)($post['reservoir_emergency_volume_m3']??0),'dead_volume_m3'=>(float)($post['reservoir_dead_volume_m3']??0)]);
        $range=['shape'=>$post['reservoir_shape']??'RECTANGLE','length_min_m'=>(float)($post['length_min_m']??4),'length_max_m'=>(float)($post['length_max_m']??20),'length_step_m'=>(float)($post['length_step_m']??1),'width_min_m'=>(float)($post['width_min_m']??4),'width_max_m'=>(float)($post['width_max_m']??20),'width_step_m'=>(float)($post['width_step_m']??1),'side_min_m'=>(float)($post['side_min_m']??4),'side_max_m'=>(float)($post['side_max_m']??20),'side_step_m'=>(float)($post['side_step_m']??1),'diameter_min_m'=>(float)($post['diameter_min_m']??4),'diameter_max_m'=>(float)($post['diameter_max_m']??20),'diameter_step_m'=>(float)($post['diameter_step_m']??1),'height_min_m'=>(float)($post['height_min_m']??2),'height_max_m'=>(float)($post['height_max_m']??6),'height_step_m'=>(float)($post['height_step_m']??.5),'freeboard_m'=>(float)($post['reservoir_freeboard_m']??.5),'compartments'=>max(1,(int)($post['reservoir_compartments']??1))];
        if(trim((string)($post['name']??''))==='')throw new \RuntimeException('Nama analisis wajib diisi.');return [
            'project_id'=>$projectId,'analysis_number'=>trim((string)($post['analysis_number']??('AUTO-'.date('Ymd-His')))),'name'=>trim((string)($post['name']??'')),
            'location'=>trim((string)($post['location']??''))?:null,'planner_name'=>trim((string)($post['planner_name']??''))?:null,'analysis_date'=>$post['analysis_date']??date('Y-m-d'),'notes'=>trim((string)($post['notes']??''))?:null,
            'unit_system'=>'SI','design_standard'=>trim((string)($post['design_standard']??''))?:null,'hydraulic_method'=>in_array(($post['hydraulic_method']??''),['H-W','D-W'],true)?$post['hydraulic_method']:'H-W','design_mode'=>$post['design_mode']??'AUTO_DESIGN','optimization_goal'=>$post['optimization_goal']??'LOWEST_INITIAL_COST','demand_method'=>$post['demand_method']??'PROJECT_DATA',
            'base_year'=>(int)($post['base_year']??date('Y')),'design_year'=>(int)($post['design_year']??date('Y')+10),'initial_population'=>(int)($post['initial_population']??0),'population_growth_percent'=>(float)($post['population_growth_percent']??0),'population_projection_method'=>$post['population_projection_method']??'GEOMETRIC','projected_population'=>(int)$demand['projected_population'],
            'domestic_lpd'=>(float)($post['domestic_lpd']??0),'non_domestic_percent'=>(float)($post['non_domestic_percent']??0),'water_loss_percent'=>(float)($post['water_loss_percent']??0),'max_day_factor'=>(float)($post['max_day_factor']??1.15),'peak_hour_factor'=>(float)($post['peak_hour_factor']??1.5),
            'average_demand_lps'=>$demand['average_lps'],'max_day_demand_lps'=>$demand['max_day_lps'],'peak_hour_demand_lps'=>$demand['peak_hour_lps'],'fire_flow_lps'=>(float)($post['fire_flow_lps']??0),'final_design_flow_lps'=>$demand['final_design_flow_lps'],'hourly_pattern_json'=>json_encode($pattern),
            'source_name'=>trim((string)($post['source_name']??''))?:null,'source_type'=>trim((string)($post['source_type']??''))?:null,'source_energy_basis'=>$post['source_energy_basis']??'TOTAL_HEAD','source_min_level_m'=>(float)($post['source_min_level_m']??0),'source_normal_level_m'=>(float)($post['source_normal_level_m']??0),'source_max_level_m'=>(float)($post['source_max_level_m']??0),'source_total_head_m'=>(float)($post['source_total_head_m']??0),'source_available_flow_lps'=>(float)($post['source_available_flow_lps']??0),'source_service_hours'=>(float)($post['source_service_hours']??24),'source_supply_mode'=>$post['source_supply_mode']??'GRAVITY',
            'reservoir_type'=>trim((string)($post['reservoir_type']??''))?:null,'reservoir_base_elevation_m'=>(float)($post['reservoir_base_elevation_m']??0),'reservoir_min_level_m'=>(float)($post['reservoir_min_level_m']??0),'reservoir_normal_level_m'=>(float)($post['reservoir_normal_level_m']??0),'reservoir_max_level_m'=>(float)($post['reservoir_max_level_m']??0),'reservoir_effective_height_m'=>(float)($post['reservoir_effective_height_m']??3),'reservoir_freeboard_m'=>(float)($post['reservoir_freeboard_m']??.5),'reservoir_shape'=>$post['reservoir_shape']??'RECTANGLE','reservoir_compartments'=>max(1,(int)($post['reservoir_compartments']??1)),'reservoir_method'=>$post['reservoir_method']??'DAILY_PERCENT','reservoir_operating_hours'=>(float)($post['reservoir_operating_hours']??6),'reservoir_storage_percent'=>(float)($post['reservoir_storage_percent']??20),'reservoir_reserve_percent'=>(float)($post['reservoir_reserve_percent']??10),'reservoir_fire_volume_m3'=>(float)($post['reservoir_fire_volume_m3']??0),'reservoir_emergency_volume_m3'=>(float)($post['reservoir_emergency_volume_m3']??0),'reservoir_dead_volume_m3'=>(float)($post['reservoir_dead_volume_m3']??0),'reservoir_operational_volume_m3'=>$volume['operational_volume_m3'],'reservoir_total_required_m3'=>$volume['total_required_m3'],'reservoir_range_json'=>json_encode($range),
            'optimization_weights_json'=>json_encode($post['weights']??['cost'=>35,'pressure'=>20,'velocity'=>15,'headloss'=>15,'safety'=>10,'uniformity'=>5]),'max_alternatives'=>max(1,(int)($post['max_alternatives']??10)),'max_iterations'=>max(1,(int)($post['max_iterations']??100)),'max_combinations'=>max(1,(int)($post['max_combinations']??500)),'calculation_timeout_seconds'=>max(10,(int)($post['calculation_timeout_seconds']??120)),
        ];
    }

    private function saveCriteria(int $id,array $posted): void {foreach(self::CRITERIA as $key=>[$label,$default,$unit]){$value=is_array($posted)&&array_key_exists($key,$posted)?(float)$posted[$key]:$default;Database::query("INSERT INTO auto_design_criteria(analysis_id,criterion_key,criterion_label,numeric_value,unit,is_active,standard_source,explanation,created_at,updated_at) VALUES(?,?,?,?,?,1,'Kriteria desain dapat diedit','Nilai proyek; wajib diverifikasi tenaga ahli',NOW(),NOW()) ON DUPLICATE KEY UPDATE numeric_value=VALUES(numeric_value),unit=VALUES(unit),updated_at=NOW()",[$id,$key,$label,$value,$unit]);}}
    private function defaultCriteria(): array {$out=[];foreach(self::CRITERIA as $key=>$meta)$out[$key]=$meta[1];return $out;}

    private function synchronizePipeInputs(int $analysisId,int $projectId,array $post): void
    {
        $links=Database::query("SELECT id FROM distribution_networks WHERE project_id=? AND deleted_at IS NULL AND COALESCE(link_type,'PIPE')='PIPE'",[$projectId])->fetchAll();$activeCatalog=Database::query("SELECT id FROM pipe_diameter_catalog WHERE is_active=1 AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);$posted=$post['pipes']??[];
        foreach($links as $link){$linkId=(int)$link['id'];$row=$posted[$linkId]??[];$allowed=array_values(array_filter(array_map('intval',$row['allowed_catalog_ids']??$activeCatalog)));Database::query("INSERT INTO auto_design_pipe_inputs(analysis_id,network_link_id,demand_along_lps,is_diameter_locked,fixed_catalog_id,minimum_dn_mm,maximum_dn_mm,allowed_catalog_ids_json,diameter_group,fitting_count,minor_loss_coefficient,transient_enabled,wave_speed_mps,velocity_change_mps,valve_closure_seconds,pressure_allowance_bar,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE demand_along_lps=VALUES(demand_along_lps),is_diameter_locked=VALUES(is_diameter_locked),fixed_catalog_id=VALUES(fixed_catalog_id),minimum_dn_mm=VALUES(minimum_dn_mm),maximum_dn_mm=VALUES(maximum_dn_mm),allowed_catalog_ids_json=VALUES(allowed_catalog_ids_json),diameter_group=VALUES(diameter_group),fitting_count=VALUES(fitting_count),minor_loss_coefficient=VALUES(minor_loss_coefficient),transient_enabled=VALUES(transient_enabled),wave_speed_mps=VALUES(wave_speed_mps),velocity_change_mps=VALUES(velocity_change_mps),valve_closure_seconds=VALUES(valve_closure_seconds),pressure_allowance_bar=VALUES(pressure_allowance_bar),updated_at=NOW()",[$analysisId,$linkId,(float)($row['demand_along_lps']??0),isset($row['is_diameter_locked'])?1:0,(int)($row['fixed_catalog_id']??0)?:null,(float)($row['minimum_dn_mm']??0)?:null,(float)($row['maximum_dn_mm']??0)?:null,json_encode($allowed),trim((string)($row['diameter_group']??''))?:null,(int)($row['fitting_count']??0),(float)($row['minor_loss_coefficient']??0),isset($row['transient_enabled'])?1:0,(float)($row['wave_speed_mps']??0)?:null,(float)($row['velocity_change_mps']??0)?:null,(float)($row['valve_closure_seconds']??0)?:null,(float)($row['pressure_allowance_bar']??0)?:null]);}
    }

    private function saveScenarios(int $id,array $posted,array $analysis): void
    {
        $basis=$analysis['source_energy_basis']==='WATER_LEVEL'?(float)$analysis['source_normal_level_m']:(float)$analysis['source_total_head_m'];$defaults=[
            ['code'=>'AVG','name'=>'Debit rata-rata','scenario_type'=>'AVERAGE','demand_multiplier'=>max(.01,$analysis['average_demand_lps']/max(.01,$analysis['peak_hour_demand_lps'])),'is_active'=>1,'is_required'=>1],
            ['code'=>'MAX_DAY','name'=>'Hari maksimum','scenario_type'=>'MAXIMUM_DAY','demand_multiplier'=>max(.01,$analysis['max_day_demand_lps']/max(.01,$analysis['peak_hour_demand_lps'])),'is_active'=>1,'is_required'=>1],
            ['code'=>'PEAK','name'=>'Jam puncak','scenario_type'=>'PEAK_HOUR','demand_multiplier'=>1,'is_active'=>1,'is_required'=>1],
            ['code'=>'MIN','name'=>'Demand minimum','scenario_type'=>'MINIMUM_DEMAND','demand_multiplier'=>.3,'is_active'=>1,'is_required'=>1],
            ['code'=>'RES_MIN','name'=>'Muka air sumber minimum','scenario_type'=>'MINIMUM_SOURCE_LEVEL','demand_multiplier'=>1,'source_head_adjustment_m'=>(float)$analysis['source_min_level_m']-$basis,'is_active'=>1,'is_required'=>1],
            ['code'=>'RES_MAX','name'=>'Muka air sumber maksimum','scenario_type'=>'MAXIMUM_SOURCE_LEVEL','demand_multiplier'=>1,'source_head_adjustment_m'=>(float)$analysis['source_max_level_m']-$basis,'is_active'=>1,'is_required'=>1],
        ];if((float)$analysis['fire_flow_lps']>0)$defaults[]=['code'=>'FIRE','name'=>'Kondisi kebakaran','scenario_type'=>'FIRE','demand_multiplier'=>1,'fire_flow_lps'=>(float)$analysis['fire_flow_lps'],'is_active'=>1,'is_required'=>1];
        $codes=[];foreach($posted as $row)$codes[]=strtoupper(trim((string)($row['code']??'')));foreach($defaults as $row)if(!in_array($row['code'],$codes,true))$posted[]=$row;
        foreach($posted as $row){$code=strtoupper(trim((string)($row['code']??'')));if(!$code)continue;Database::query("INSERT INTO auto_design_scenarios(analysis_id,code,name,scenario_type,demand_multiplier,source_head_adjustment_m,fire_flow_lps,outage_link_id,pump_state,is_required,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),scenario_type=VALUES(scenario_type),demand_multiplier=VALUES(demand_multiplier),source_head_adjustment_m=VALUES(source_head_adjustment_m),fire_flow_lps=VALUES(fire_flow_lps),outage_link_id=VALUES(outage_link_id),pump_state=VALUES(pump_state),is_required=VALUES(is_required),is_active=VALUES(is_active),updated_at=NOW()",[$id,$code,trim((string)($row['name']??$code)),$row['scenario_type']??'CUSTOM',(float)($row['demand_multiplier']??1),(float)($row['source_head_adjustment_m']??0),(float)($row['fire_flow_lps']??0),(int)($row['outage_link_id']??0)?:null,$row['pump_state']??'UNCHANGED',isset($row['is_required'])?1:0,isset($row['is_active'])?1:0]);}
    }

    private function loadAnalysis(int $id): array
    {
        $analysis=$this->ownedAnalysis($id);$criteria=$this->defaultCriteria();foreach(Database::query("SELECT * FROM auto_design_criteria WHERE analysis_id=?",[$id])->fetchAll() as $row)$criteria[$row['criterion_key']]=(float)$row['numeric_value'];
        $pipes=Database::query("SELECT i.*,n.route_name,n.pipe_length_m,n.origin_type,n.origin_id,n.destination_type,n.destination_id,CONCAT(n.origin_type,':',n.origin_id) origin_key,CONCAT(n.destination_type,':',n.destination_id) destination_key FROM auto_design_pipe_inputs i JOIN distribution_networks n ON n.id=i.network_link_id WHERE i.analysis_id=? ORDER BY n.route_name",[$id])->fetchAll();foreach($pipes as &$row)$row['allowed_catalog_ids']=json_decode((string)$row['allowed_catalog_ids_json'],true)?:[];unset($row);
        $alternatives=Database::query("SELECT * FROM auto_design_alternatives WHERE analysis_id=? ORDER BY rank_number,id",[$id])->fetchAll();$detailId=(int)($analysis['selected_alternative_id']??0);if(!$detailId)foreach($alternatives as $item)if($item['is_recommended']){$detailId=(int)$item['id'];break;}
        return ['analysis'=>$analysis,'criteria'=>$criteria,'pipeInputs'=>$pipes,'scenarios'=>Database::query("SELECT * FROM auto_design_scenarios WHERE analysis_id=? ORDER BY id",[$id])->fetchAll(),'alternatives'=>$alternatives,'reservoirAlternatives'=>Database::query("SELECT * FROM auto_design_reservoir_alternatives WHERE analysis_id=? ORDER BY rank_number,id",[$id])->fetchAll(),'selectedPipes'=>$detailId?Database::query("SELECT p.*,n.route_name FROM auto_design_alternative_pipes p LEFT JOIN distribution_networks n ON n.id=p.network_link_id WHERE p.alternative_id=? ORDER BY p.scenario_code,n.route_name",[$detailId])->fetchAll():[],'selectedNodes'=>$detailId?Database::query("SELECT * FROM auto_design_alternative_nodes WHERE alternative_id=? ORDER BY scenario_code,node_name",[$detailId])->fetchAll():[]];
    }
    private function ownedAnalysis(int $id): array {$row=Database::query("SELECT a.*,p.name project_name,p.code project_code FROM auto_design_analyses a JOIN network_projects p ON p.id=a.project_id WHERE a.id=? AND a.deleted_at IS NULL",[$id])->fetch();if(!$row)throw new \RuntimeException('Analisis desain tidak ditemukan.');return $row;}
    private function reservoirAlternatives(array $analysis): array {$range=json_decode((string)$analysis['reservoir_range_json'],true)?:[];return (new ReservoirSizingService())->generateAlternatives(max(.001,(float)$analysis['reservoir_total_required_m3']),$range,(int)$analysis['max_alternatives']);}

    private function insertAlternative(int $analysisId,array $a): void
    {
        Database::query("INSERT INTO auto_design_alternatives(analysis_id,rank_number,combination_code,total_cost,lifecycle_cost,minimum_pressure_m,maximum_pressure_m,minimum_velocity_mps,maximum_velocity_mps,total_headloss_m,technical_score,final_score,status,failure_reasons,is_recommended,is_selected,converged,calculation_seconds,scenario_summary_json,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[$analysisId,$a['rank'],$a['combination_code'],$a['total_cost'],$a['lifecycle_cost'],$a['minimum_pressure_m'],$a['maximum_pressure_m'],$a['minimum_velocity_mps'],$a['maximum_velocity_mps'],$a['total_headloss_m'],$a['technical_score']??0,$a['final_score']??null,$a['status'],implode("\n",$a['failure_reasons']??[]),(int)$a['is_recommended'],0,(int)$a['converged'],$a['calculation_seconds'],json_encode($a['scenario_results']),user()['id']]);$altId=(int)Database::connection()->lastInsertId();
        foreach($a['scenario_results'] as $scenario){if(empty($scenario['evaluation']))continue;$code=$scenario['scenario']['code'];foreach($scenario['evaluation']['links'] as $key=>$row){$linkId=(int)($row['network_link_id']??0);$catalog=$a['combination'][$linkId]??null;if(!$catalog||!isset($catalog['inside_diameter_mm']))continue;Database::query("INSERT INTO auto_design_alternative_pipes(alternative_id,scenario_code,network_link_id,catalog_id,material,dn_mm,outside_diameter_mm,wall_thickness_mm,inside_diameter_mm,pressure_class,allowable_pressure_bar,flow_lps,velocity_mps,reynolds_number,friction_factor,major_headloss_m,minor_headloss_m,unit_headloss_m_per_km,start_pressure_m,end_pressure_m,design_pressure_bar,cost,status,failure_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$altId,$code,$linkId,$catalog['id']??null,$catalog['material']??null,$catalog['dn_mm']??null,$catalog['outside_diameter_mm']??null,$catalog['wall_thickness_mm']??null,$catalog['inside_diameter_mm'],$catalog['pressure_class']??null,$catalog['allowable_pressure_bar']??null,$row['flow_lps']??null,$row['velocity_mps']??null,$row['reynolds_number']??null,$row['friction_factor']??null,$row['major_headloss_m']??$row['headloss_m']??null,$row['minor_headloss_m']??null,$row['unit_headloss_m_per_km']??null,$row['start_pressure_m']??null,$row['end_pressure_m']??null,$row['design_pressure_bar']??null,($catalog['price_per_meter']??0)*($row['length_m']??0),$row['status'],null]);}
            foreach($scenario['evaluation']['nodes'] as $key=>$row)Database::query("INSERT INTO auto_design_alternative_nodes(alternative_id,scenario_code,node_key,node_name,elevation_m,demand_lps,hydraulic_head_m,pressure_m,pressure_kpa,pressure_bar,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)",[$altId,$code,$key,$row['name']??$key,$row['elevation_m']??null,$row['demand_lps']??null,$row['head_m']??null,$row['pressure_m']??null,$row['pressure_kpa']??null,$row['pressure_bar']??null,$row['status']]);}
    }
    private function insertReservoirAlternative(int $id,array $a): void {Database::query("INSERT INTO auto_design_reservoir_alternatives(analysis_id,rank_number,shape,length_or_diameter_m,width_m,effective_height_m,freeboard_m,construction_height_m,compartments,effective_volume_m3,total_volume_m3,excess_volume_m3,footprint_m2,status,selection_reason,is_recommended,is_selected,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())",[$id,$a['rank'],$a['shape'],$a['length_or_diameter_m'],$a['width_m'],$a['effective_height_m'],$a['freeboard_m'],$a['construction_height_m'],$a['compartments'],$a['effective_volume_m3'],$a['total_volume_m3'],$a['excess_volume_m3'],$a['footprint_m2'],$a['status'],$a['reason'],(int)$a['is_recommended']]);}
    private function ensureSchema(): void
    {
        if(Database::query("SHOW TABLES LIKE 'auto_design_analyses'")->fetchColumn())return;
        ob_start();try{require dirname(__DIR__,2).'/database/migrations/20260806_automatic_design.php';}finally{ob_end_clean();}
    }
}

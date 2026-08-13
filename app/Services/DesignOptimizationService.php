<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DesignOptimizationService
{
    public function __construct(
        private readonly HydraulicNetworkService $hydraulic=new HydraulicNetworkService(),
        private readonly PipeSizingService $pipes=new PipeSizingService(),
        private readonly DesignValidationService $validator=new DesignValidationService()
    ){}

    public function estimateCombinationCount(array $candidateSets): int
    {
        $count=1;foreach($candidateSets as $set){$size=max(1,count($set));if($count>PHP_INT_MAX/$size)return PHP_INT_MAX;$count*=$size;}return $count;
    }

    public function candidateSets(array $model,array $pipeInputs,array $catalog,float|array $criteria): array
    {
        $targetVelocity=is_array($criteria)?max(.01,(float)($criteria['target_velocity_mps']??1.2)):max(.01,$criteria);
        $minimumVelocity=is_array($criteria)?max(.01,(float)($criteria['minimum_velocity_mps']??.6)):max(.01,$targetVelocity/2);
        if(is_array($criteria)&&(int)($criteria['minimum_velocity_active']??1)===0)$minimumVelocity=max(.05,min($minimumVelocity,$targetVelocity/6));
        $maximumVelocity=is_array($criteria)?max($minimumVelocity,(float)($criteria['maximum_velocity_mps']??2)):max($targetVelocity,$targetVelocity*2);
        $catalogById=[];foreach($catalog as $item)$catalogById[(int)$item['id']]=$item;
        $inputByLink=[];foreach($pipeInputs as $input)$inputByLink[(int)$input['network_link_id']]=$input;
        $sets=[];
        foreach($model['links'] as $link){
            if(($link['link_type']??'PIPE')!=='PIPE')continue;$id=(int)$link['id'];$input=$inputByLink[$id]??[];
            if((int)($input['is_diameter_locked']??0)===1){
                $fixed=(int)($input['fixed_catalog_id']??0);if(!$fixed||!isset($catalogById[$fixed]))throw new RuntimeException("Diameter tetap untuk ruas {$link['route_name']} belum valid.");
                $sets[$id]=[$this->withTransient($catalogById[$fixed],$input)];continue;
            }
            $allowed=$input['allowed_catalog_ids']??json_decode((string)($input['allowed_catalog_ids_json']??'[]'),true)?:[];
            $minimum=(float)($input['minimum_dn_mm']??0);$maximum=(float)($input['maximum_dn_mm']??PHP_FLOAT_MAX);
            if(trim((string)($input['diameter_group']??''))==='__FREE__'){
                // Diameter bebas tetap membutuhkan acuan material/kekasaran dan
                // kelas tekanan. Pilih kelas aktif tertinggi agar hasil tidak gagal
                // hanya karena urutan baris katalog kebetulan menempatkan PN rendah
                // lebih dahulu.
                $template=null;foreach($allowed as $catalogId){$candidate=$catalogById[(int)$catalogId]??null;if(!$candidate)continue;if(!$template||(float)($candidate['allowable_pressure_bar']??0)>(float)($template['allowable_pressure_bar']??0))$template=$candidate;}
                if(!$template)throw new RuntimeException("Material acuan diameter bebas untuk ruas {$link['route_name']} tidak ditemukan.");
                $flow=max(.0001,(float)($link['planned_flow_lps']?:$link['max_pipe_capacity_lps']?:1));$velocities=[$targetVelocity];
                for($i=1;$i<=6;$i++)$velocities[]=$targetVelocity-($targetVelocity-$minimumVelocity)*$i/6;
                for($i=1;$i<=5;$i++)$velocities[]=$targetVelocity+($maximumVelocity-$targetVelocity)*$i/5;
                $items=[];$seen=[];$referenceDiameter=max(.001,(float)($template['inside_diameter_mm']??$template['dn_mm']??1));$outsideRatio=max(1,(float)($template['outside_diameter_mm']??$referenceDiameter)/$referenceDiameter);$price=max(0,(float)($template['price_per_meter']??0));
                $baseClass=max(0,(float)($template['allowable_pressure_bar']??0));$maximumPumpHead=0.0;
                foreach($model['links']??[] as $modelLink)if(strtoupper((string)($modelLink['link_type']??'PIPE'))==='PUMP'){$curve=$model['curves'][(int)($modelLink['pump_curve_id']??0)]??null;foreach($curve['points']??[] as $point)$maximumPumpHead=max($maximumPumpHead,(float)($point['head_m']??$point['head']??0));}
                $requiredClass=$maximumPumpHead>0?$maximumPumpHead*max(1,(float)($criteria['pressure_safety_factor']??1.2))/10.19716213:0;
                $allowable=$baseClass;foreach([6.0,10.0,12.5,16.0,20.0,25.0,32.0,40.0,50.0,63.0] as $pn)if($pn+1e-9>=max($baseClass,$requiredClass)){$allowable=$pn;break;}
                if($allowable>$baseClass+1e-9){$template['allowable_pressure_bar']=$allowable;$template['pressure_class']='DESIGN-PN'.rtrim(rtrim(number_format($allowable,1,'.',''),'0'),'.');}
                foreach($velocities as $velocity){$diameter=round($this->pipes->estimateDiameterMm($flow,max(.01,$velocity)),3);if($diameter<$minimum||$diameter>$maximum||isset($seen[(string)$diameter]))continue;$seen[(string)$diameter]=true;$item=$template;$item['id']=null;$item['dn_mm']=$diameter;$item['inside_diameter_mm']=$diameter;$item['outside_diameter_mm']=round($diameter*$outsideRatio,3);$item['wall_thickness_mm']=round(($item['outside_diameter_mm']-$diameter)/2,3);$classFactor=$baseClass>0?max(1,$allowable/$baseClass):1;$item['price_per_meter']=$price>0?$price*(($diameter/$referenceDiameter)**1.6)*($classFactor**.25):0;$item['is_continuous']=1;$items[]=$this->withTransient($item,$input);}
                if(!$items)throw new RuntimeException("Rentang diameter bebas untuk ruas {$link['route_name']} kosong.");$sets[$id]=$items;continue;
            }
            $items=[];foreach($allowed as $catalogId){$item=$catalogById[(int)$catalogId]??null;if($item&&(float)$item['dn_mm']>=$minimum&&(float)$item['dn_mm']<=$maximum)$items[]=$this->withTransient($item,$input);}
            if(!$items)throw new RuntimeException("Tidak ada diameter kandidat untuk ruas {$link['route_name']}.");
            $estimated=$this->pipes->estimateDiameterMm(max(.0001,(float)($link['planned_flow_lps']?:$link['max_pipe_capacity_lps']?:1)),$targetVelocity);
            usort($items,fn($a,$b)=>[abs((float)$a['inside_diameter_mm']-$estimated),(float)$a['inside_diameter_mm']]<=>[abs((float)$b['inside_diameter_mm']-$estimated),(float)$b['inside_diameter_mm']]);
            $sets[$id]=$items;
        }
        return $sets;
    }

    public function optimize(array $model,array $analysis,array $criteria,array $pipeInputs,array $catalog,array $scenarios): array
    {
        $started=microtime(true);$sets=$this->candidateSets($model,$pipeInputs,$catalog,$criteria);
        $estimated=$this->estimateCombinationCount($sets);$maximum=max(1,(int)($analysis['max_combinations']??500));$iterationLimit=min($maximum,max(1,(int)($analysis['max_iterations']??100)));
        $groups=[];foreach($pipeInputs as $input){$group=trim((string)($input['diameter_group']??''));if($group!==''&&$group!=='__FREE__')$groups[$group][]=(int)$input['network_link_id'];}
        $rawLimit=$groups?min($estimated,$iterationLimit*10):$iterationLimit;$combinations=$this->boundedCombinations($sets,(int)$rawLimit);
        if($groups)$combinations=array_slice(array_values(array_filter($combinations,fn($combination)=>$this->groupsMatch($combination,$groups))),0,$iterationLimit);
        if(!$combinations)throw new RuntimeException('Tidak ada kombinasi diameter yang memenuhi aturan kelompok ruas. Samakan daftar diameter kandidat pada ruas dalam kelompok yang sama.');
        $alternatives=[];$method=(string)($analysis['hydraulic_method']??'H-W');
        foreach($combinations as $index=>$combination){
            if(microtime(true)-$started>(int)($analysis['calculation_timeout_seconds']??120))break;
            $scenarioResults=[];$reasons=[];$warnings=[];$metrics=[];$converged=true;$pipeCost=0;$diameterSum=0;$diameterValues=[];
            foreach($combination as $linkId=>$item){$link=$this->linkById($model,(int)$linkId);$pipeCost+=(float)($item['price_per_meter']??0)*(float)($link['pipe_length_m']??0);$diameterSum+=(float)$item['inside_diameter_mm'];$diameterValues[]=(float)$item['inside_diameter_mm'];}
            foreach($scenarios as $scenario){
                if(!(int)($scenario['is_active']??1))continue;$scenarioModel=$this->applyCombination($model,$combination,$method,$scenario);
                // Desain diameter harus menguji seluruh demand rencana (DDA).
                // Tekanan target adalah sasaran pemeringkatan, bukan ambang yang
                // diam-diam mengurangi demand seperti pada simulasi operasi PDA.
                $payload=$this->hydraulic->buildPayload($scenarioModel,['headloss_formula'=>$method,'demand_model'=>'DDA','analysis_type'=>'STEADY','demand_multiplier'=>(float)($scenario['demand_multiplier']??1),'minimum_pressure_m'=>(float)($criteria['minimum_pressure_m']??10),'required_pressure_m'=>(float)($criteria['target_pressure_m']??15)]);
                $engine=$this->hydraulic->run($payload);
                if(!$engine['success']||!($engine['results']['available']??false)){$converged=false;$reasons[]="Skenario {$scenario['code']}: solver tidak konvergen.";$scenarioResults[]=['scenario'=>$scenario,'status'=>'FAIL','engine_errors'=>$engine['engine_errors']??[]];continue;}
                $latest=$engine['results']['latest'];$enriched=$this->enrichResults($scenarioModel,$latest,$combination,$method);
                $scenarioCriteria=$criteria;$scenarioCriteria['allow_low_velocity']=(int)($scenario['scenario_type']==='MINIMUM_DEMAND'&&($criteria['allow_low_velocity_minimum_demand']??0));
                $evaluation=$this->validator->evaluateScenario($enriched['nodes'],$enriched['links'],$scenarioCriteria,$enriched['catalog_by_link']);
                if((int)($scenario['is_required']??1)&&!$evaluation['passed'])$reasons=[...$reasons,...$evaluation['reasons']];
                $warnings=[...$warnings,...$evaluation['warnings']];$metrics[]=$evaluation['metrics'];$scenarioResults[]=['scenario'=>$scenario,'status'=>$evaluation['status'],'evaluation'=>$evaluation];
            }
            $aggregate=$this->aggregateMetrics($metrics);$status=$reasons?'FAIL':($warnings?'WARNING':'PASS');
            $alternatives[]=['combination_code'=>'ALT-'.str_pad((string)($index+1),4,'0',STR_PAD_LEFT),'combination'=>$combination,'total_cost'=>$pipeCost,'lifecycle_cost'=>$pipeCost,'diameter_sum_mm'=>$diameterSum,'diameter_variants'=>count(array_unique($diameterValues)),
                ...$aggregate,'status'=>$status,'failure_reasons'=>array_values(array_unique($reasons)),'warnings'=>array_values(array_unique($warnings)),'converged'=>$converged,'scenario_results'=>$scenarioResults,'calculation_seconds'=>microtime(true)-$started];
        }
        $ranked=$this->rankAlternatives($alternatives,(string)($analysis['optimization_goal']??'LOWEST_INITIAL_COST'),(array)($analysis['weights']??[]),$criteria);
        return ['estimated_combinations'=>$estimated,'tested_combinations'=>count($alternatives),'truncated'=>$estimated>count($alternatives),'alternatives'=>$ranked,'elapsed_seconds'=>microtime(true)-$started];
    }

    public function rankAlternatives(array $alternatives,string $goal,array $weights=[],array $criteria=[]): array
    {
        $passed=array_values(array_filter($alternatives,fn($item)=>in_array($item['status'],['PASS','WARNING'],true)&&($item['converged']??false)));
        $failed=array_values(array_filter($alternatives,fn($item)=>!in_array($item['status']??'FAIL',['PASS','WARNING'],true)||!($item['converged']??false)));
        if($passed){
            foreach($passed as &$item){
                $targetPressure=(float)($criteria['target_pressure_m']??20);$targetVelocity=(float)($criteria['target_velocity_mps']??1.2);$minimumVelocity=(float)($item['minimum_velocity_mps']??$item['maximum_velocity_mps']??0);$maximumVelocity=(float)($item['maximum_velocity_mps']??0);
                $item['pressure_deviation']=abs((float)($item['minimum_pressure_m']??0)-$targetPressure);$item['velocity_deviation']=(abs($minimumVelocity-$targetVelocity)+abs($maximumVelocity-$targetVelocity))/2;$item['safety_penalty']=1/max(.001,(float)($item['minimum_pressure_m']??0));$item['uniformity_penalty']=(float)($item['diameter_variants']??1);
            }unset($item);
            $ranges=$this->ranges($passed,['total_cost','total_headloss_m','pressure_deviation','velocity_deviation','safety_penalty','uniformity_penalty']);
            foreach($passed as &$item)$item['final_score']=$this->score($item,$goal,$weights,$ranges);
            unset($item);
            usort($passed,fn($a,$b)=>[$a['final_score'],$a['total_cost']]<=>[$b['final_score'],$b['total_cost']]);
        }
        // Kandidat gagal yang ditampilkan sebagai "alternatif terdekat" harus
        // mempunyai pelanggaran paling sedikit, bukan sekadar harga termurah.
        // Ini membuat diagnosis konflik hidraulika jauh lebih berguna.
        usort($failed,fn($a,$b)=>[count($a['failure_reasons']??[]),!($a['converged']??false),(float)$a['total_cost']]<=>[count($b['failure_reasons']??[]),!($b['converged']??false),(float)$b['total_cost']]);
        $all=[...$passed,...$failed];foreach($all as $index=>&$item){$item['rank']=$index+1;$item['is_recommended']=$index===0&&in_array($item['status'],['PASS','WARNING'],true)&&($item['converged']??false);$item['technical_score']=isset($item['final_score'])?max(0,100-$item['final_score']):0;}unset($item);return $all;
    }

    private function boundedCombinations(array $sets,int $limit): array
    {
        $keys=array_keys($sets);$result=[];$seen=[];$append=function(array $combination)use(&$result,&$seen,$keys,$limit): void {if(count($result)>=$limit)return;$signature=implode('|',array_map(fn($key)=>round((float)($combination[$key]['inside_diameter_mm']??0),3).':'.round((float)($combination[$key]['allowable_pressure_bar']??0),3),$keys));if(isset($seen[$signature]))return;$seen[$signature]=true;$result[]=$combination;};
        $maximumSetSize=max(array_map('count',$sets));for($index=0;$index<$maximumSetSize&&count($result)<$limit;$index++){$combination=[];foreach($keys as $key)$combination[$key]=$sets[$key][min($index,count($sets[$key])-1)];$append($combination);}
        $walk=function(int $position,array $current)use(&$walk,&$result,$sets,$keys,$limit,$append){if(count($result)>=$limit)return;if($position===count($keys)){$append($current);return;}$key=$keys[$position];foreach($sets[$key] as $item){$current[$key]=$item;$walk($position+1,$current);if(count($result)>=$limit)break;}};$walk(0,[]);return $result;
    }
    private function applyCombination(array $model,array $combination,string $method,array $scenario): array
    {
        foreach($model['links'] as &$link){$id=(int)$link['id'];if(isset($combination[$id])){$item=$combination[$id];$link['pipe_diameter_mm']=(float)$item['inside_diameter_mm'];$link['pipe_type']='Material khusus';$link['material_code']='AUTO-'.$item['id'];$link['roughness_coefficient']=$method==='D-W'?(float)$item['darcy_roughness_mm']:(float)$item['hazen_williams_c'];}
            if((int)($scenario['outage_link_id']??0)===$id){$link['initial_status']='CLOSED';$link['status']='tidak_aktif';}}
        unset($link);
        $pumpState=(string)($scenario['pump_state']??'UNCHANGED');if($pumpState!=='UNCHANGED'){foreach($model['links'] as &$link)if(($link['link_type']??'PIPE')==='PUMP')$link['initial_status']=$pumpState==='OFF'?'CLOSED':'OPEN';unset($link);}
        $adjustment=(float)($scenario['source_head_adjustment_m']??0);if($adjustment){foreach($model['nodes'] as &$node){if(in_array($node['node_type'],['source','reservoir'],true))$node['head_m']=(float)($node['head_m']??$node['total_head_m']??0)+$adjustment;elseif($node['node_type']==='tank')$node['initial_level_m']=max((float)($node['minimum_level_m']??0),(float)($node['initial_level_m']??0)+$adjustment);}unset($node);}
        $fire=(float)($scenario['fire_flow_lps']??0);if($fire>0){$target=null;$largest=-INF;foreach($model['nodes'] as $key=>$node)if(!in_array($node['node_type'],['source','reservoir','tank'],true)&&($node['base_demand_lps']??0)>$largest){$target=$key;$largest=(float)($node['base_demand_lps']??0);}if($target!==null)$model['nodes'][$target]['base_demand_lps']=(float)($model['nodes'][$target]['base_demand_lps']??0)+$fire;}
        return $model;
    }
    private function enrichResults(array $model,array $latest,array $combination,string $method): array
    {
        $nodes=[];foreach($latest['nodes'] as $key=>$row){$meta=$model['nodes'][$key]??[];$pressure=$this->pipes->pressureConversions((float)$row['pressure_m']);$nodes[$key]=$row+['name'=>$meta['name']??$key,'node_type'=>$meta['node_type']??'junction','entity_type'=>$meta['entity_type']??'node','elevation_m'=>$meta['elevation_m']??null,'base_demand_lps'=>(float)($meta['base_demand_lps']??0),'requested_demand_lps'=>(float)($row['requested_demand_lps']??$meta['base_demand_lps']??0),'pressure_kpa'=>$pressure['kpa'],'pressure_bar'=>$pressure['bar']];}
        $links=[];$catalogByLink=[];foreach($model['links'] as $link){$key='link:'.$link['id'];if(!isset($latest['links'][$key]))continue;$row=$latest['links'][$key];$start=$nodes[$link['origin_key']]['pressure_m']??0;$end=$nodes[$link['destination_key']]['pressure_m']??0;$item=$combination[(int)$link['id']]??null;$originNode=$model['nodes'][$link['origin_key']]??[];$destinationNode=$model['nodes'][$link['destination_key']]??[];$fixedTypes=['source','reservoir','tank'];$fixedHeadBoundaryLink=in_array((string)($originNode['node_type']??''),$fixedTypes,true)&&in_array((string)($destinationNode['node_type']??''),$fixedTypes,true);
            if($item){$calculated=$this->pipes->calculate(['flow_lps'=>abs((float)$row['flow_lps']),'inside_diameter_mm'=>(float)$item['inside_diameter_mm'],'length_m'=>(float)$link['pipe_length_m'],'minor_loss_coefficient'=>(float)$link['minor_loss_coefficient'],'hazen_williams_c'=>(float)$item['hazen_williams_c'],'darcy_roughness_mm'=>(float)$item['darcy_roughness_mm']],$method);$row=array_merge($row,$calculated);$catalogByLink[$key]=$item;}
            $links[$key]=$row+['route_name'=>$link['route_name'],'network_link_id'=>(int)$link['id'],'length_m'=>(float)$link['pipe_length_m'],'start_pressure_m'=>$start,'end_pressure_m'=>$end,'fixed_head_boundary_link'=>$fixedHeadBoundaryLink];}
        return ['nodes'=>$nodes,'links'=>$links,'catalog_by_link'=>$catalogByLink];
    }
    private function aggregateMetrics(array $metrics): array
    {
        $values=fn($key)=>array_values(array_filter(array_column($metrics,$key),fn($v)=>$v!==null));$minP=$values('minimum_pressure_m');$maxP=$values('maximum_pressure_m');$minV=$values('minimum_velocity_mps');$maxV=$values('maximum_velocity_mps');
        return ['minimum_pressure_m'=>$minP?min($minP):null,'maximum_pressure_m'=>$maxP?max($maxP):null,'minimum_velocity_mps'=>$minV?min($minV):null,'maximum_velocity_mps'=>$maxV?max($maxV):null,'total_headloss_m'=>array_sum($values('total_headloss_m'))];
    }
    private function linkById(array $model,int $id): array {foreach($model['links'] as $link)if((int)$link['id']===$id)return $link;throw new RuntimeException("Ruas $id tidak ditemukan.");}
    private function groupsMatch(array $combination,array $groups): bool {foreach($groups as $ids){$selected=[];foreach($ids as $id)if(isset($combination[$id]))$selected[]=(int)$combination[$id]['id'];if(count(array_unique($selected))>1)return false;}return true;}
    private function withTransient(array $catalog,array $input): array {$catalog['transient_allowance_bar']=max(0,(float)($input['pressure_allowance_bar']??0));$catalog['transient_status']='NOT_ENABLED';if((int)($input['transient_enabled']??0)===1){$check=$this->pipes->waterHammer(isset($input['wave_speed_mps'])?(float)$input['wave_speed_mps']:null,isset($input['velocity_change_mps'])?(float)$input['velocity_change_mps']:null);$catalog['transient_status']=$check['available']?'CALCULATED':'INCOMPLETE';if($check['available'])$catalog['transient_allowance_bar']+=(float)$check['pressure_bar'];}return $catalog;}
    private function ranges(array $items,array $keys): array {$ranges=[];foreach($keys as $key){$values=array_map(fn($item)=>(float)($item[$key]??0),$items);$ranges[$key]=['min'=>min($values),'max'=>max($values)];}return $ranges;}
    private function norm(float $value,array $range): float {return $range['max']-$range['min']>1e-12?($value-$range['min'])/($range['max']-$range['min']):0;}
    private function score(array $item,string $goal,array $weights,array $ranges): float
    {
        if($goal==='LOWEST_HEADLOSS')return (float)$item['total_headloss_m'];if($goal==='TARGET_PRESSURE')return (float)$item['pressure_deviation'];if($goal==='TARGET_VELOCITY')return (float)$item['velocity_deviation'];if($goal==='SMALLEST_DIAMETER')return (float)($item['diameter_sum_mm']??PHP_FLOAT_MAX);if(in_array($goal,['LOWEST_INITIAL_COST','LOWEST_LIFECYCLE_COST'],true))return (float)$item['total_cost'];
        $w=$goal==='BALANCED'?['cost'=>15,'pressure'=>25,'velocity'=>25,'headloss'=>20,'safety'=>10,'uniformity'=>5]:array_merge(['cost'=>35,'pressure'=>20,'velocity'=>15,'headloss'=>15,'safety'=>10,'uniformity'=>5],$weights);return $this->norm((float)$item['total_cost'],$ranges['total_cost'])*$w['cost']+$this->norm((float)$item['pressure_deviation'],$ranges['pressure_deviation'])*$w['pressure']+$this->norm((float)$item['velocity_deviation'],$ranges['velocity_deviation'])*$w['velocity']+$this->norm((float)$item['total_headloss_m'],$ranges['total_headloss_m'])*$w['headloss']+$this->norm((float)$item['safety_penalty'],$ranges['safety_penalty'])*$w['safety']+$this->norm((float)$item['uniformity_penalty'],$ranges['uniformity_penalty'])*$w['uniformity'];
    }
}

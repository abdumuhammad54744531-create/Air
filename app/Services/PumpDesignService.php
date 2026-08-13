<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PumpDesignService
{
    public function design(array $model,array $options=[]): array
    {
        $pumps=array_values(array_filter($model['links']??[],fn(array $link): bool => strtoupper((string)($link['link_type']??'PIPE'))==='PUMP'));
        if(!$pumps)return [];

        $multiplier=max(.01,(float)($options['demand_multiplier']??1));
        $totalDemand=0.0;
        foreach($model['nodes']??[] as $node){
            if(in_array((string)($node['node_type']??''),['source','reservoir','tank'],true)||($node['entity_type']??'')==='source')continue;
            $totalDemand+=max(0,(float)($node['base_demand_lps']??0))*$multiplier;
        }

        $manualFlow=max(0,(float)($options['pump_design_flow_lps']??0));
        $manualHead=max(0,(float)($options['pump_design_head_m']??0));
        $operatingHours=min(24,max(1,(float)($options['pump_operating_hours_day']??12)));
        $flowFactor=1+max(0,(float)($options['pump_flow_safety_percent']??10))/100;
        $headFactor=1+max(0,(float)($options['pump_head_safety_percent']??10))/100;
        $allowance=max(0,(float)($options['pump_head_allowance_m']??10));
        $targetPressure=(float)($options['target_pressure_m']??20);
        $efficiency=min(100,max(1,(float)($options['pump_efficiency_percent']??75)));
        $designs=[];

        foreach($pumps as $link){
            $origin=$model['nodes'][$link['origin_key']]??null;
            $destination=$model['nodes'][$link['destination_key']]??null;
            if(!$origin||!$destination)throw new RuntimeException('Pompa '.$link['route_name'].' belum mempunyai titik asal dan tujuan yang valid.');

            $referenceFlow=$totalDemand>0?$totalDemand*24/$operatingHours:max(max(0,(float)($link['planned_flow_lps']??0)),max(0,(float)($link['pump_capacity_lps']??0)));
            $flow=$manualFlow>0?$manualFlow:max(.1,$referenceFlow*$flowFactor);
            $originHead=$this->availableHead($origin,$targetPressure);
            $downstream=$this->downstreamRequirement($model,(string)$link['destination_key'],$targetPressure);
            $destinationHead=$downstream['head_m'];
            $staticHead=$destinationHead-$originHead;
            $unitHeadloss=max(0,(float)($options['maximum_unit_headloss_m_per_km']??0));
            $frictionAllowance=$unitHeadloss>0?$unitHeadloss*$downstream['path_length_m']/1000:0;
            $head=$manualHead>0?$manualHead:max(1,($staticHead+$frictionAllowance+$allowance)*$headFactor);
            $flow=round($flow,3);$head=round($head,3);
            $points=[
                ['flow_lps'=>0.0,'head_m'=>round($head*1.25,3)],
                ['flow_lps'=>$flow,'head_m'=>$head],
                ['flow_lps'=>round($flow*1.5,3),'head_m'=>round($head*.65,3)],
            ];
            $power=round(9.80665*($flow/1000)*$head/($efficiency/100),3);
            $controlNode=!empty($downstream['control_tank_key'])?($model['nodes'][$downstream['control_tank_key']]??null):null;$maximumLevel=$controlNode?max(0,(float)($controlNode['maximum_level_m']??0)):0;
            $designs[]=[
                'network_link_id'=>(int)$link['id'],
                'route_name'=>(string)$link['route_name'],
                'virtual_curve_id'=>900000000+(int)$link['id'],
                'flow_lps'=>$flow,
                'head_m'=>$head,
                'static_head_m'=>round($staticHead,3),
                'origin_head_m'=>round($originHead,3),
                'destination_required_head_m'=>round($destinationHead,3),
                'design_path_length_m'=>round($downstream['path_length_m'],3),
                'friction_head_allowance_m'=>round($frictionAllowance,3),
                'control_tank_key'=>$maximumLevel>0?$downstream['control_tank_key']:null,
                'pump_start_level_m'=>$maximumLevel>0?round($maximumLevel*.25,3):null,
                'pump_stop_level_m'=>$maximumLevel>0?round($maximumLevel*.90,3):null,
                'efficiency_percent'=>$efficiency,
                'operating_hours_day'=>$operatingHours,
                'estimated_power_kw'=>$power,
                'points'=>$points,
            ];
        }
        return $designs;
    }

    public function applyDesignFlows(array $model,array $designs,float $demandMultiplier=1): array
    {
        $demandMultiplier=max(.01,$demandMultiplier);$fillFlowByLink=[];$outgoing=[];
        foreach($model['links']??[] as $link){$from=(string)($link['origin_key']??'');if($from!=='')$outgoing[$from][]=$link;}
        foreach($designs as $design){$pumpLink=null;foreach($model['links']??[] as $link)if((int)$link['id']===(int)$design['network_link_id']){$pumpLink=$link;break;}if(!$pumpLink)continue;
            $queue=[(string)$pumpLink['destination_key']];$visited=[];while($queue){$key=array_shift($queue);if(isset($visited[$key]))continue;$visited[$key]=true;foreach($outgoing[$key]??[] as $link){if(strtoupper((string)($link['link_type']??'PIPE'))!=='PIPE')continue;$id=(int)$link['id'];$fillFlowByLink[$id]=($fillFlowByLink[$id]??0)+(float)$design['flow_lps'];$destination=(string)$link['destination_key'];if(($model['nodes'][$destination]['node_type']??'')!=='tank')$queue[]=$destination;}}
        }
        $memo=[];$downstreamDemand=function(string $key,array $stack=[])use(&$downstreamDemand,&$memo,$model,$outgoing,$demandMultiplier): float {if(isset($memo[$key]))return $memo[$key];if(isset($stack[$key]))return 0.0;$stack[$key]=true;$node=$model['nodes'][$key]??[];$sum=max(0,(float)($node['base_demand_lps']??0))*$demandMultiplier;foreach($outgoing[$key]??[] as $link)if(strtoupper((string)($link['link_type']??'PIPE'))==='PIPE')$sum+=$downstreamDemand((string)$link['destination_key'],$stack);return $memo[$key]=$sum;};
        foreach($model['links'] as &$link){if(strtoupper((string)($link['link_type']??'PIPE'))!=='PIPE')continue;$id=(int)$link['id'];if(isset($fillFlowByLink[$id]))$link['planned_flow_lps']=max(.001,$fillFlowByLink[$id]);else{$demand=$downstreamDemand((string)$link['destination_key']);if($demand>0)$link['planned_flow_lps']=$demand;}}unset($link);
        return $model;
    }

    public function applyToModel(array $model,array $designs): array
    {
        $byLink=[];
        foreach($designs as $design)$byLink[(int)$design['network_link_id']]=$design;
        foreach($model['links'] as &$link){
            $design=$byLink[(int)$link['id']]??null;
            if(!$design)continue;
            $curveId=(int)$design['virtual_curve_id'];
            $model['curves'][$curveId]=[
                'id'=>$curveId,
                'code'=>'AUTO-PUMP-'.$link['id'],
                'name'=>'Kurva desain '.$link['route_name'],
                'curve_type'=>'PUMP',
                'engine_id'=>'AUTO-PUMP-'.$link['id'],
                'points'=>$design['points'],
                'points_json'=>json_encode($design['points'],JSON_PRESERVE_ZERO_FRACTION),
                'status'=>'aktif',
            ];
            $link['pump_curve_id']=$curveId;
            $link['nominal_power_kw']=null;
            $link['planned_flow_lps']=$design['flow_lps'];
            if(!empty($design['control_tank_key'])){$link['control_mode']='TANK_LEVEL';$link['start_level_m']=$design['pump_start_level_m'];$link['stop_level_m']=$design['pump_stop_level_m'];}
        }
        unset($link);
        return $model;
    }

    private function availableHead(array $node,float $targetPressure): float
    {
        if(($node['entity_type']??'')==='source'||in_array((string)($node['node_type']??''),['source','reservoir'],true))return (float)($node['head_m']??$node['total_head_m']??$node['elevation_m']??0);
        if(($node['node_type']??'')==='tank')return (float)($node['elevation_m']??0)+(float)($node['initial_level_m']??0);
        return (float)($node['elevation_m']??0)+$targetPressure;
    }

    private function requiredHead(array $node,float $targetPressure): float
    {
        if(($node['entity_type']??'')==='source'||in_array((string)($node['node_type']??''),['source','reservoir'],true))return (float)($node['head_m']??$node['total_head_m']??$node['elevation_m']??0);
        if(($node['node_type']??'')==='tank')return (float)($node['elevation_m']??0)+(float)($node['initial_level_m']??0);
        return (float)($node['elevation_m']??0)+$targetPressure;
    }

    private function downstreamRequiredHead(array $model,string $startKey,float $targetPressure): float
    {
        return $this->downstreamRequirement($model,$startKey,$targetPressure)['head_m'];
    }

    private function downstreamRequirement(array $model,string $startKey,float $targetPressure): array
    {
        $adjacency=[];
        foreach($model['links']??[] as $link){
            if(strtoupper((string)($link['link_type']??'PIPE'))==='PUMP')continue;
            if(($link['status']??'aktif')!=='aktif'||($link['initial_status']??'OPEN')==='CLOSED')continue;
            $from=(string)($link['origin_key']??'');$to=(string)($link['destination_key']??'');if($from===''||$to==='')continue;
            $length=max(0,(float)($link['pipe_length_m']??0));$adjacency[$from][]=[$to,$length];$adjacency[$to][]=[$from,$length];
        }
        $maximum=$this->requiredHead($model['nodes'][$startKey]??[],$targetPressure);$selectedDistance=0.0;$selectedKey=$startKey;$controlTankKey=(($model['nodes'][$startKey]['node_type']??'')==='tank')?$startKey:null;$distances=[$startKey=>0.0];$queue=[[$startKey,0.0]];
        while($queue){
            usort($queue,fn($a,$b)=>$a[1]<=>$b[1]);[$key,$distance]=array_shift($queue);if($distance>($distances[$key]??INF)+1e-9)continue;$node=$model['nodes'][$key]??null;if(!$node)continue;
            if($controlTankKey===null&&($node['node_type']??'')==='tank')$controlTankKey=$key;$required=$this->requiredHead($node,$targetPressure);if($required>$maximum+1e-9||abs($required-$maximum)<=1e-9&&$distance>$selectedDistance){$maximum=$required;$selectedDistance=$distance;$selectedKey=$key;}
            foreach($adjacency[$key]??[] as [$next,$length]){$candidate=$distance+$length;if($candidate+1e-9<($distances[$next]??INF)){$distances[$next]=$candidate;$queue[]=[$next,$candidate];}}
        }
        return ['head_m'=>$maximum,'path_length_m'=>$selectedDistance,'node_key'=>$selectedKey,'control_tank_key'=>$controlTankKey];
    }
}

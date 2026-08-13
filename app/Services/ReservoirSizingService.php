<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class ReservoirSizingService
{
    public function rectangleVolume(float $length,float $width,float $height,int $compartments=1): float
    {
        $this->positive([$length,$width,$height]);return $length*$width*$height*max(1,$compartments);
    }
    public function squareVolume(float $side,float $height,int $compartments=1): float {return $this->rectangleVolume($side,$side,$height,$compartments);}
    public function cylinderVolume(float $diameter,float $height,int $compartments=1): float
    {
        $this->positive([$diameter,$height]);return M_PI*$diameter*$diameter/4*$height*max(1,$compartments);
    }
    public function requiredVolume(array $input): array
    {
        $method=(string)($input['method']??'DAILY_PERCENT');$maxDayM3=max(0,(float)($input['max_day_m3']??0));
        if($method==='MASS_CURVE')$operational=max(0,(float)($input['mass_curve_volume_m3']??0));
        elseif($method==='DETENTION_TIME')$operational=max(0,(float)($input['design_flow_lps']??0))*3.6*max(0,(float)($input['storage_hours']??0));
        else $operational=$maxDayM3*max(0,(float)($input['storage_percent']??0))/100;
        $fire=max(0,(float)($input['fire_volume_m3']??0));$emergency=max(0,(float)($input['emergency_volume_m3']??0));$dead=max(0,(float)($input['dead_volume_m3']??0));
        $base=$operational+$fire+$emergency;$reserve=$base*max(0,(float)($input['reserve_percent']??0))/100;
        $effective=$base+$reserve;$total=$effective+$dead;
        return ['operational_volume_m3'=>$operational,'fire_volume_m3'=>$fire,'emergency_volume_m3'=>$emergency,'reserve_volume_m3'=>$reserve,'dead_volume_m3'=>$dead,'effective_volume_m3'=>$effective,'total_required_m3'=>$total];
    }
    public function generateAlternatives(float $requiredM3,array $range,int $limit=10): array
    {
        if($requiredM3<=0)throw new InvalidArgumentException('Volume reservoir minimum harus lebih besar dari nol.');
        $shape=(string)($range['shape']??'RECTANGLE');$freeboard=max(0,(float)($range['freeboard_m']??0));$compartments=max(1,(int)($range['compartments']??1));
        $heightValues=$this->series((float)($range['height_min_m']??0),(float)($range['height_max_m']??0),(float)($range['height_step_m']??0));
        $alternatives=[];
        if($shape==='CYLINDER'){
            foreach($this->series((float)($range['diameter_min_m']??0),(float)($range['diameter_max_m']??0),(float)($range['diameter_step_m']??0)) as $diameter)foreach($heightValues as $height){
                $volume=$this->cylinderVolume($diameter,$height,$compartments);$alternatives[]=$this->alternative($shape,$diameter,null,$height,$freeboard,$compartments,$volume,$requiredM3,M_PI*$diameter*$diameter/4*$compartments);
            }
        }elseif($shape==='SQUARE'){
            foreach($this->series((float)($range['side_min_m']??0),(float)($range['side_max_m']??0),(float)($range['side_step_m']??0)) as $side)foreach($heightValues as $height){
                $volume=$this->squareVolume($side,$height,$compartments);$alternatives[]=$this->alternative($shape,$side,$side,$height,$freeboard,$compartments,$volume,$requiredM3,$side*$side*$compartments);
            }
        }else{
            $lengths=$this->series((float)($range['length_min_m']??0),(float)($range['length_max_m']??0),(float)($range['length_step_m']??0));
            $widths=$this->series((float)($range['width_min_m']??0),(float)($range['width_max_m']??0),(float)($range['width_step_m']??0));
            foreach($lengths as $length)foreach($widths as $width)foreach($heightValues as $height){
                $volume=$this->rectangleVolume($length,$width,$height,$compartments);$ratio=max($length,$width)/min($length,$width);if($ratio>3)continue;
                $alternatives[]=$this->alternative('RECTANGLE',$length,$width,$height,$freeboard,$compartments,$volume,$requiredM3,$length*$width*$compartments);
            }
        }
        $passed=array_values(array_filter($alternatives,fn($item)=>$item['status']==='PASS'));
        usort($passed,fn($a,$b)=>[$a['excess_volume_m3'],$a['footprint_m2']]<=>[$b['excess_volume_m3'],$b['footprint_m2']]);
        $passed=array_slice($passed,0,max(5,$limit));foreach($passed as $index=>&$item){$item['rank']=$index+1;$item['is_recommended']=$index===0;$item['reason']=$index===0?'Volume terdekat yang memenuhi kebutuhan dengan luas tapak terkecil pada selisih volume yang sama.':'Alternatif dimensi lain yang tetap memenuhi volume minimum.';}unset($item);
        return $passed;
    }
    private function alternative(string $shape,float $lengthOrDiameter,?float $width,float $height,float $freeboard,int $compartments,float $volume,float $required,float $footprint): array
    {
        return ['shape'=>$shape,'length_or_diameter_m'=>$lengthOrDiameter,'width_m'=>$width,'effective_height_m'=>$height,'freeboard_m'=>$freeboard,'construction_height_m'=>$height+$freeboard,'compartments'=>$compartments,'effective_volume_m3'=>$volume,'total_volume_m3'=>$volume,'excess_volume_m3'=>$volume-$required,'volume_per_compartment_m3'=>$volume/$compartments,'footprint_m2'=>$footprint,'status'=>$volume+1e-9>=$required?'PASS':'FAIL'];
    }
    private function series(float $minimum,float $maximum,float $step): array
    {
        if($minimum<=0||$maximum<$minimum||$step<=0)throw new InvalidArgumentException('Rentang atau interval dimensi reservoir tidak valid.');
        $values=[];for($value=$minimum,$guard=0;$value<=$maximum+1e-9&&$guard<500;$value+=$step,$guard++)$values[]=round($value,6);
        return $values;
    }
    private function positive(array $values): void {foreach($values as $value)if($value<=0)throw new InvalidArgumentException('Dimensi reservoir harus lebih besar dari nol.');}
}

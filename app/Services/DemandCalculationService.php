<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class DemandCalculationService
{
    public function projectPopulation(float $initialPopulation,int $baseYear,int $designYear,float $growthPercent,string $method,?float $manual=null): float
    {
        if($initialPopulation<0||$designYear<$baseYear)throw new InvalidArgumentException('Data proyeksi penduduk tidak valid.');
        $years=$designYear-$baseYear;$rate=$growthPercent/100;
        return match($method){
            'ARITHMETIC'=>$initialPopulation*(1+$rate*$years),
            'GEOMETRIC'=>$initialPopulation*((1+$rate)**$years),
            'EXPONENTIAL'=>$initialPopulation*exp($rate*$years),
            'MANUAL'=>$manual!==null&&$manual>=0?$manual:throw new InvalidArgumentException('Penduduk rencana manual wajib diisi.'),
            default=>throw new InvalidArgumentException('Metode proyeksi penduduk tidak dikenali.'),
        };
    }

    public function calculate(array $input): array
    {
        $population=(float)($input['projected_population']??0);
        if(($input['population_projection_method']??'MANUAL')!=='MANUAL'&&isset($input['initial_population'],$input['base_year'],$input['design_year'])){
            $population=$this->projectPopulation((float)$input['initial_population'],(int)$input['base_year'],(int)$input['design_year'],(float)($input['population_growth_percent']??0),(string)$input['population_projection_method'],$population);
        }
        $domesticLpd=max(0,(float)($input['domestic_lpd']??0));
        $domesticM3Day=$population*$domesticLpd/1000;
        $nonDomesticM3Day=$domesticM3Day*max(0,(float)($input['non_domestic_percent']??0))/100;
        $baseM3Day=$domesticM3Day+$nonDomesticM3Day;
        $lossM3Day=$baseM3Day*max(0,(float)($input['water_loss_percent']??0))/100;
        $averageM3Day=$baseM3Day+$lossM3Day;
        $averageLps=$averageM3Day*1000/86400;
        $maxDayLps=$averageLps*max(0,(float)($input['max_day_factor']??1));
        $peakHourLps=$maxDayLps*max(0,(float)($input['peak_hour_factor']??1));
        $fireFlow=max(0,(float)($input['fire_flow_lps']??0));
        return [
            'projected_population'=>(int)round($population),
            'domestic_m3_day'=>$domesticM3Day,'non_domestic_m3_day'=>$nonDomesticM3Day,'loss_m3_day'=>$lossM3Day,
            'average_m3_day'=>$averageM3Day,'average_m3_hour'=>$averageM3Day/24,'average_lps'=>$averageLps,
            'max_day_lps'=>$maxDayLps,'peak_hour_lps'=>$peakHourLps,'fire_flow_lps'=>$fireFlow,
            'final_design_flow_lps'=>$peakHourLps+$fireFlow,
        ];
    }

    public function massCurveVolume(float $dailyDemandM3,array $hourlyMultipliers,?array $hourlySupplyM3=null): array
    {
        if($dailyDemandM3<0||count($hourlyMultipliers)!==24)throw new InvalidArgumentException('Pola demand harus berisi tepat 24 nilai.');
        $sum=array_sum(array_map('floatval',$hourlyMultipliers));
        if($sum<=0)throw new InvalidArgumentException('Jumlah faktor pola demand harus lebih besar dari nol.');
        $normalized=array_map(fn($value)=>(float)$value/$sum,$hourlyMultipliers);
        $supply=$hourlySupplyM3??array_fill(0,24,$dailyDemandM3/24);
        if(count($supply)!==24)throw new InvalidArgumentException('Pola suplai harus berisi tepat 24 nilai.');
        $rows=[];$cumulativeSupply=0.0;$cumulativeDemand=0.0;$min=0.0;$max=0.0;
        for($hour=0;$hour<24;$hour++){
            $demand=$dailyDemandM3*$normalized[$hour];$hourSupply=(float)$supply[$hour];
            $cumulativeDemand+=$demand;$cumulativeSupply+=$hourSupply;$difference=$cumulativeSupply-$cumulativeDemand;
            $min=min($min,$difference);$max=max($max,$difference);
            $rows[]=['hour'=>$hour,'demand_m3'=>$demand,'supply_m3'=>$hourSupply,'cumulative_demand_m3'=>$cumulativeDemand,'cumulative_supply_m3'=>$cumulativeSupply,'difference_m3'=>$difference];
        }
        return ['operational_volume_m3'=>$max-$min,'minimum_difference_m3'=>$min,'maximum_difference_m3'=>$max,'normalized_pattern'=>$normalized,'rows'=>$rows];
    }
}

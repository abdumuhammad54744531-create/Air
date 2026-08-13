<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class PipeSizingService
{
    public const GRAVITY=9.80665;
    public const KPA_PER_METER=9.80665;
    public const BAR_PER_METER=0.0980665;

    public function litersPerSecondToCubicMetersPerSecond(float $flowLps): float {return $flowLps/1000;}
    public function area(float $insideDiameterMm): float
    {
        if($insideDiameterMm<=0)throw new InvalidArgumentException('Diameter dalam harus lebih besar dari nol.');
        $diameterM=$insideDiameterMm/1000;return M_PI*$diameterM*$diameterM/4;
    }
    public function velocity(float $flowLps,float $insideDiameterMm): float {return $this->litersPerSecondToCubicMetersPerSecond($flowLps)/$this->area($insideDiameterMm);}
    public function hazenWilliams(float $lengthM,float $flowLps,float $insideDiameterMm,float $coefficient): float
    {
        if($lengthM<0||$flowLps<0||$coefficient<=0)throw new InvalidArgumentException('Panjang, debit, atau koefisien Hazen-Williams tidak valid.');
        if($flowLps==0||$lengthM==0)return 0;
        $q=$this->litersPerSecondToCubicMetersPerSecond($flowLps);$d=$insideDiameterMm/1000;
        if($d<=0)throw new InvalidArgumentException('Diameter dalam harus lebih besar dari nol.');
        return 10.67*$lengthM*($q**1.852)/(($coefficient**1.852)*($d**4.87));
    }
    public function reynolds(float $flowLps,float $insideDiameterMm,float $kinematicViscosity=1.004e-6): float
    {
        if($kinematicViscosity<=0)throw new InvalidArgumentException('Viskositas kinematik harus lebih besar dari nol.');
        return abs($this->velocity($flowLps,$insideDiameterMm))*($insideDiameterMm/1000)/$kinematicViscosity;
    }
    public function frictionFactor(float $reynolds,float $insideDiameterMm,float $roughnessMm): array
    {
        if($reynolds<=0)return ['factor'=>0.0,'method'=>'Tidak ada aliran'];
        if($reynolds<2300)return ['factor'=>64/$reynolds,'method'=>'Laminar (64/Re)'];
        $d=$insideDiameterMm/1000;$epsilon=max(0,$roughnessMm)/1000;
        $factor=.25/(log10($epsilon/(3.7*$d)+5.74/($reynolds**.9))**2);
        return ['factor'=>$factor,'method'=>'Swamee-Jain'];
    }
    public function darcyWeisbach(float $lengthM,float $flowLps,float $insideDiameterMm,float $roughnessMm,float $kinematicViscosity=1.004e-6): array
    {
        if($lengthM<0||$flowLps<0||$roughnessMm<0)throw new InvalidArgumentException('Data Darcy-Weisbach tidak valid.');
        $velocity=$this->velocity($flowLps,$insideDiameterMm);$re=$this->reynolds($flowLps,$insideDiameterMm,$kinematicViscosity);
        $friction=$this->frictionFactor($re,$insideDiameterMm,$roughnessMm);
        $headloss=$friction['factor']*($lengthM/($insideDiameterMm/1000))*($velocity*$velocity/(2*self::GRAVITY));
        return ['headloss_m'=>$headloss,'reynolds'=>$re,'friction_factor'=>$friction['factor'],'friction_method'=>$friction['method']];
    }
    public function minorLoss(float $flowLps,float $insideDiameterMm,float $totalK): float
    {
        if($totalK<0)throw new InvalidArgumentException('Koefisien kehilangan minor tidak boleh negatif.');
        $velocity=$this->velocity($flowLps,$insideDiameterMm);return $totalK*$velocity*$velocity/(2*self::GRAVITY);
    }
    public function estimateDiameterMm(float $flowLps,float $targetVelocityMps): float
    {
        if($flowLps<0||$targetVelocityMps<=0)throw new InvalidArgumentException('Debit atau kecepatan target tidak valid.');
        return sqrt(4*$this->litersPerSecondToCubicMetersPerSecond($flowLps)/(M_PI*$targetVelocityMps))*1000;
    }
    public function pressureConversions(float $pressureM): array
    {
        return ['meter'=>$pressureM,'kpa'=>$pressureM*self::KPA_PER_METER,'bar'=>$pressureM*self::BAR_PER_METER];
    }
    public function validateDimensions(float $outsideDiameterMm,float $wallThicknessMm,float $insideDiameterMm): void
    {
        if($outsideDiameterMm<=0||$wallThicknessMm<0||$insideDiameterMm<=0)throw new InvalidArgumentException('Dimensi pipa harus lebih besar dari nol.');
        if($insideDiameterMm>=$outsideDiameterMm)throw new InvalidArgumentException('Diameter dalam harus lebih kecil dari diameter luar.');
        $calculated=$outsideDiameterMm-2*$wallThicknessMm;
        if($calculated<=0||abs($calculated-$insideDiameterMm)>max(1,$outsideDiameterMm*.03))throw new InvalidArgumentException('Diameter luar, tebal dinding, dan diameter dalam tidak konsisten.');
    }
    public function pressureClassCheck(float $staticPressureM,float $dynamicPressureM,float $allowableBar,float $safetyFactor=1,float $transientAllowanceBar=0): array
    {
        $maximumM=max($staticPressureM,$dynamicPressureM);$designBar=$maximumM*self::BAR_PER_METER*max(1,$safetyFactor)+max(0,$transientAllowanceBar);
        return ['maximum_pressure_m'=>$maximumM,'design_pressure_bar'=>$designBar,'allowable_pressure_bar'=>$allowableBar,'passed'=>$allowableBar>0&&$designBar<=$allowableBar];
    }
    public function waterHammer(?float $waveSpeedMps,?float $velocityChangeMps): array
    {
        if($waveSpeedMps===null||$velocityChangeMps===null||$waveSpeedMps<=0)return ['available'=>false,'head_m'=>null,'pressure_bar'=>null,'missing'=>['kecepatan gelombang','perubahan kecepatan']];
        $head=abs($waveSpeedMps*$velocityChangeMps/self::GRAVITY);
        return ['available'=>true,'head_m'=>$head,'pressure_bar'=>$head*self::BAR_PER_METER,'missing'=>[]];
    }
    public function calculate(array $pipe,string $method): array
    {
        $flow=abs((float)($pipe['flow_lps']??0));$diameter=(float)($pipe['inside_diameter_mm']??0);$length=(float)($pipe['length_m']??0);
        $velocity=$this->velocity($flow,$diameter);$minor=$this->minorLoss($flow,$diameter,(float)($pipe['minor_loss_coefficient']??0));
        if($method==='D-W'){
            $majorResult=$this->darcyWeisbach($length,$flow,$diameter,(float)($pipe['darcy_roughness_mm']??0));
            $major=$majorResult['headloss_m'];
        }else{
            $major=$this->hazenWilliams($length,$flow,$diameter,(float)($pipe['hazen_williams_c']??0));
            $majorResult=['reynolds'=>$this->reynolds($flow,$diameter),'friction_factor'=>null,'friction_method'=>'Hazen-Williams'];
        }
        $result=['area_m2'=>$this->area($diameter),'velocity_mps'=>$velocity,'major_headloss_m'=>$major,'minor_headloss_m'=>$minor,'total_headloss_m'=>$major+$minor,'unit_headloss_m_per_km'=>$length>0?($major+$minor)*1000/$length:0]+$majorResult;
        $result['reynolds_number']=$result['reynolds']??null;return $result;
    }
}

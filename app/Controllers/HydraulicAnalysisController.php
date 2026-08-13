<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\HydraulicNetworkService;
use Throwable;

final class HydraulicAnalysisController
{
    public function validate(): never
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        try {
            $project=$this->project();
            $service=new HydraulicNetworkService();
            $model=$service->loadModel((int)$project['id']);
            $validation=$service->validate($model);
            $payload=$service->buildPayload($model,$_POST);
            json_response([
                'success'=>true,
                'validation'=>$validation,
                'payload_summary'=>$this->payloadSummary($payload),
                'project'=>['id'=>(int)$project['id'],'code'=>$project['code'],'name'=>$project['name']],
            ]);
        } catch (Throwable $error) {
            json_response(['success'=>false,'message'=>'Validasi jaringan gagal: '.$error->getMessage()],500);
        }
    }

    public function run(): never
    {
        require_auth(['super_admin','administrator','operator']);
        verify_csrf();
        try {
            $project=$this->project();
            $service=new HydraulicNetworkService();
            $model=$service->loadModel((int)$project['id']);
            $validation=$service->validate($model);
            $payload=$service->buildPayload($model,$_POST);
            if (!$validation['valid']) {
                json_response([
                    'success'=>false,
                    'message'=>'Analisis belum dijalankan karena data jaringan belum valid.',
                    'validation'=>$validation,
                    'payload_summary'=>$this->payloadSummary($payload),
                    'project'=>['id'=>(int)$project['id'],'code'=>$project['code'],'name'=>$project['name']],
                ],422);
            }
            $engine=$service->run($payload);
            json_response([
                'success'=>$engine['success'],
                'message'=>$engine['success']
                    ? 'EPANET berhasil menyelesaikan analisis hidraulika.'
                    : 'EPANET menemukan kesalahan pada model jaringan.',
                'validation'=>$validation,
                'payload_summary'=>$this->payloadSummary($payload),
                'engine'=>$engine,
                'project'=>['id'=>(int)$project['id'],'code'=>$project['code'],'name'=>$project['name']],
            ],$engine['success']?200:422);
        } catch (Throwable $error) {
            json_response(['success'=>false,'message'=>'Analisis hidraulika gagal: '.$error->getMessage()],500);
        }
    }

    private function payloadSummary(array $payload): array
    {
        return [
            'junctions'=>count($payload['nodes']),
            'reservoirs'=>count($payload['reservoirs']),
            'tanks'=>count($payload['tanks']),
            'pipes'=>count($payload['pipes']),
            'pumps'=>count($payload['pumps']),
            'valves'=>count($payload['valves']),
            'patterns'=>count($payload['patterns']),
            'curves'=>count($payload['curves']),
        ];
    }

    private function project(): array
    {
        $id=(int)($_POST['project_id']??$_SESSION['network_project_id']??0);
        $project=$id?\App\Core\Database::query("SELECT * FROM network_projects WHERE id=? AND deleted_at IS NULL",[$id])->fetch():null;
        if (!$project) $project=\App\Core\Database::query("SELECT * FROM network_projects WHERE deleted_at IS NULL ORDER BY is_default DESC,id LIMIT 1")->fetch();
        if (!$project) throw new \RuntimeException('Proyek jaringan tidak ditemukan.');
        $_SESSION['network_project_id']=(int)$project['id'];
        return $project;
    }
}

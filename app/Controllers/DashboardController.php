<?php
namespace App\Controllers;

use App\Core\Database;

final class DashboardController
{
    public function index(): void
    {
        require_auth();
        view('dashboard/index', ['title'=>'Dashboard', 'stats'=>$this->stats(), 'charts'=>$this->chartData()]);
    }

    public function data(): void
    {
        require_auth();
        json_response(['success'=>true,'stats'=>$this->stats(),'charts'=>$this->chartData(),'server_time'=>date('Y-m-d H:i:s')]);
    }

    private function stats(): array
    {
        $sql = "SELECT
          (SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL) locations,
          (SELECT COUNT(*) FROM devices WHERE deleted_at IS NULL) devices,
          (SELECT COUNT(*) FROM devices WHERE status='aktif' AND deleted_at IS NULL) active_devices,
          (SELECT COUNT(*) FROM devices WHERE status IN ('offline','tidak_aktif') AND deleted_at IS NULL) offline_devices,
          (SELECT COUNT(*) FROM devices WHERE status='dalam_perawatan' AND deleted_at IS NULL) maintenance_devices,
          (SELECT COUNT(*) FROM sensors WHERE deleted_at IS NULL) sensors,
          (SELECT COUNT(*) FROM sensor_readings WHERE DATE(recorded_at)=CURDATE()) readings_today,
          (SELECT COUNT(*) FROM alerts WHERE status IN ('baru','dibaca','diproses')) active_alerts";
        return Database::query($sql)->fetch() ?: [];
    }

    private function chartData(): array
    {
        return Database::query("SELECT DATE_FORMAT(sr.recorded_at,'%d/%m') label, s.parameter, ROUND(AVG(sr.calibrated_value),2) value, s.unit
            FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id
            WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(sr.recorded_at), DATE_FORMAT(sr.recorded_at,'%d/%m'), s.parameter, s.unit ORDER BY DATE(sr.recorded_at)")->fetchAll();
    }

    public function map(): void
    {
        $locations = Database::query("SELECT l.*, d.name device_name, d.status device_status, d.last_data_at FROM locations l LEFT JOIN devices d ON d.location_id=l.id AND d.deleted_at IS NULL WHERE l.deleted_at IS NULL AND l.latitude IS NOT NULL")->fetchAll();
        view('dashboard/map', ['title'=>'Peta Monitoring','locations'=>$locations]);
    }

    public function reports(): void
    {
        $summary = Database::query("SELECT s.parameter,s.unit,MIN(sr.calibrated_value) minimum,MAX(sr.calibrated_value) maximum,AVG(sr.calibrated_value) average,COUNT(*) total FROM sensor_readings sr JOIN sensors s ON s.id=sr.sensor_id GROUP BY s.parameter,s.unit")->fetchAll();
        view('dashboard/reports', ['title'=>'Laporan Monitoring','summary'=>$summary]);
    }
}

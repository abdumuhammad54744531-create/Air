<?php
namespace App\Services;

use App\Core\App;
use App\Core\Database;
use RuntimeException;

final class GoogleSheetSensorService
{
    public function ensureSchema(): void
    {
        $columns = Database::query('SHOW COLUMNS FROM devices')->fetchAll();
        $names = array_column($columns, 'Field');
        $missing = [
            'google_sheet_url' => 'ADD COLUMN google_sheet_url VARCHAR(500) NULL AFTER notes',
            'google_sheet_gid' => 'ADD COLUMN google_sheet_gid VARCHAR(40) NULL AFTER google_sheet_url',
            'google_sheet_name' => 'ADD COLUMN google_sheet_name VARCHAR(150) NULL AFTER google_sheet_gid',
        ];
        foreach ($missing as $name => $sql) if (!in_array($name, $names, true)) Database::query("ALTER TABLE devices {$sql}");
    }

    public function locations(): array
    {
        return Database::query("SELECT DISTINCT l.id,l.code,l.name
            FROM locations l JOIN devices d ON d.location_id=l.id
            WHERE l.deleted_at IS NULL AND d.deleted_at IS NULL AND COALESCE(d.google_sheet_url,'')<>''
            ORDER BY l.name")->fetchAll();
    }

    public function readings(?int $locationId = null, int $limit = 300): array
    {
        $this->ensureSchema();
        $params = [];
        $where = "d.deleted_at IS NULL AND COALESCE(d.google_sheet_url,'')<>''";
        if ($locationId) { $where .= ' AND d.location_id=?'; $params[] = $locationId; }
        $devices = Database::query("SELECT d.id,d.code,d.name,d.google_sheet_url,d.google_sheet_gid,d.google_sheet_name,l.id location_id,l.code location_code,l.name location_name
            FROM devices d LEFT JOIN locations l ON l.id=d.location_id WHERE {$where} ORDER BY l.name,d.name", $params)->fetchAll();
        $rows = []; $errors = [];
        foreach ($devices as $device) {
            try {
                foreach ($this->readSheet($device) as $row) $rows[] = $row;
            } catch (RuntimeException $e) {
                $errors[] = ['device_id'=>(int)$device['id'], 'device_name'=>$device['name'], 'message'=>$e->getMessage()];
            }
        }
        usort($rows, fn(array $a, array $b) => strcmp((string)$b['sort_at'], (string)$a['sort_at']));
        return ['rows'=>array_slice($rows, 0, max(1, min(1000, $limit))), 'errors'=>$errors, 'updated_at'=>date('Y-m-d H:i:s'), 'device_count'=>count($devices)];
    }

    private function readSheet(array $device): array
    {
        [$spreadsheetId, $gid] = $this->sheetReference((string)$device['google_sheet_url'], (string)($device['google_sheet_gid'] ?? ''));
        $url = 'https://docs.google.com/spreadsheets/d/'.rawurlencode($spreadsheetId).'/export?format=csv&gid='.rawurlencode($gid);
        $csv = $this->download($url);
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?: '') ?: [];
        if (count($lines) < 2) throw new RuntimeException('Sheet belum memiliki baris data yang dapat dibaca.');
        $headerLine = null;
        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex > 40) break;
            $candidate = array_map(fn($v) => $this->headerKey((string)$v), str_getcsv($line, ',', '"', '\\') ?: []);
            $recognised = count(array_intersect($candidate, ['date','time','temperature','ph','tds','velocity','water_level']));
            if ($recognised >= 2) { $headers = $candidate; $headerLine = $lineIndex; break; }
        }
        if ($headerLine === null) throw new RuntimeException('Header sheet tidak dikenali. Gunakan kolom Suhu, pH, TDS, Kecepatan, atau Tinggi Air.');
        $lines = array_slice($lines, $headerLine + 1);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, ',', '"', '\\');
            $record = [];
            foreach ($headers as $index => $header) if ($header !== '') $record[$header] = trim((string)($cells[$index] ?? ''));
            $date = $record['date'] ?? ''; $time = $record['time'] ?? '';
            if ($date === '' && $time === '' && !$record) continue;
            $sort = $this->timestamp($date, $time);
            $rows[] = [
                'location_id'=>(int)($device['location_id'] ?? 0), 'location_code'=>$device['location_code'] ?? '', 'location_name'=>$device['location_name'] ?? 'Tanpa lokasi',
                'device_id'=>(int)$device['id'], 'device_code'=>$device['code'], 'device_name'=>$device['name'], 'sheet_name'=>$device['google_sheet_name'] ?: ('Sheet '.$gid),
                'date'=>$date, 'time'=>$time, 'temperature'=>$record['temperature'] ?? null, 'ph'=>$record['ph'] ?? null,
                'tds'=>$record['tds'] ?? null, 'velocity'=>$record['velocity'] ?? null, 'water_level'=>$record['water_level'] ?? null,
                'sort_at'=>$sort,
            ];
        }
        return $rows;
    }

    private function sheetReference(string $url, string $configuredGid): array
    {
        if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $match)) throw new RuntimeException('URL Google Sheet pada alat tidak valid.');
        $gid = $configuredGid;
        if ($gid === '' && preg_match('/[?&#]gid=(\d+)/', $url, $gidMatch)) $gid = $gidMatch[1];
        if ($gid === '') $gid = '0';
        return [$match[1], $gid];
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>['Accept: text/csv', 'Cache-Control: no-cache'], CURLOPT_USERAGENT=>'SIMMA-GoogleSheet-Sync/1.0']);
            $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl);
            if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException('Google Sheet tidak dapat diakses'.($error ? ': '.$error : '.'));
            return $body;
        }
        $context = stream_context_create(['http'=>['timeout'=>20,'header'=>"Accept: text/csv\r\nCache-Control: no-cache\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) throw new RuntimeException('Server tidak dapat mengambil data dari Google Sheet.');
        return $body;
    }

    private function headerKey(string $header): string
    {
        $value = strtolower(trim($header));
        $value = strtr($value, ['°'=>'', 'â°'=>'', 'á'=>'a']);
        return match (true) {
            str_contains($value, 'tanggal') || $value === 'date' => 'date',
            str_contains($value, 'waktu') || $value === 'time' => 'time',
            str_contains($value, 'suhu') || str_contains($value, 'temperature') => 'temperature',
            $value === 'ph' || str_contains($value, 'derajat keasaman') => 'ph',
            str_contains($value, 'tds') => 'tds',
            str_contains($value, 'kecepatan') || str_contains($value, 'velocity') || preg_match('/^v\s*\(/', $value) === 1 => 'velocity',
            str_contains($value, 'tinggi air') || str_contains($value, 'muka air') || str_contains($value, 'water level') || preg_match('/^h\s*\(/', $value) === 1 => 'water_level',
            default => preg_replace('/[^a-z0-9]+/', '_', $value) ?: '',
        };
    }

    private function timestamp(string $date, string $time): string
    {
        $date = trim($date); $time = trim($time);
        $date = preg_replace('#^(\d{2})/(\d{2})/(\d{4})$#', '$3-$2-$1', $date) ?: $date;
        $time = $time === '' ? '00:00:00' : $time;
        $value = strtotime($date.' '.$time);
        return $value ? date('Y-m-d H:i:s', $value) : '0000-00-00 00:00:00';
    }
}

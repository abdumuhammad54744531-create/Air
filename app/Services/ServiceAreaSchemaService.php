<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ServiceAreaSchemaService
{
    /** Menjaga instalasi lama tetap kompatibel saat elevasi wilayah ditambahkan. */
    public static function ensureElevationColumn(): void
    {
        $columns = Database::query('SHOW COLUMNS FROM service_areas')->fetchAll();
        if (!in_array('elevation_m', array_column($columns, 'Field'), true)) {
            Database::query('ALTER TABLE service_areas ADD COLUMN elevation_m DECIMAL(14,4) NULL AFTER name');
        }
    }
}

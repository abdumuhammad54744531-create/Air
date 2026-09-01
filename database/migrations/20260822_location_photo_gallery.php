<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

require_once dirname(__DIR__,2).'/app/Core/Env.php';
require_once dirname(__DIR__,2).'/app/Core/Database.php';
Env::load(dirname(__DIR__,2).'/.env');

Database::query("CREATE TABLE IF NOT EXISTS location_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id BIGINT UNSIGNED NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_location_photos_location (location_id,sort_order),
    FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

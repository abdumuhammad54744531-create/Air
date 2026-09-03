<?php
declare(strict_types=1);

use App\Services\ServiceAreaSchemaService;

require_once dirname(__DIR__, 2).'/app/Core/Env.php';
require_once dirname(__DIR__, 2).'/app/Core/Database.php';
require_once dirname(__DIR__, 2).'/app/Services/ServiceAreaSchemaService.php';
\App\Core\Env::load(dirname(__DIR__, 2).'/.env');

ServiceAreaSchemaService::ensureElevationColumn();

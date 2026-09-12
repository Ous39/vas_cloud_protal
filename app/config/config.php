<?php
declare(strict_types=1);
return [
    'db_host' => getenv('DB_HOST') ?: 'db',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_user' => getenv('DB_USER') ?: 'vas_user',
    'db_pass' => getenv('DB_PASSWORD') ?: 'vas_password',
    'portal_db' => getenv('PORTAL_DB') ?: 'vas_portal',
    'allowed_schemas' => ['HeraTesting','HeraProduction'],
    'default_schema' => getenv('DEFAULT_SCHEMA') ?: 'HeraTesting',
    'app_name' => 'VAS Cloud Control Center',
    'timezone' => getenv('APP_TZ') ?: 'UTC',
];

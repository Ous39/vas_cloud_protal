<?php
declare(strict_types=1);
return [
    'db_host' => getenv('DB_HOST') ?: 'db',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_user' => getenv('DB_USER') ?: 'vas_user',
    'db_pass' => getenv('DB_PASSWORD') ?: 'vas_password',
    // HeraProduction/HeraTesting connect separately from vas_portal (this app's own users/audit/
    // integrations/API-key data) so the portal's own metadata never has to live on the real
    // production database server. Falls back to the db_* values above (the local Docker MySQL)
    // until HERA_DB_* is actually set — see .env.example for where to fill those in.
    'hera_db_host' => getenv('HERA_DB_HOST') ?: getenv('DB_HOST') ?: 'db',
    'hera_db_port' => getenv('HERA_DB_PORT') ?: getenv('DB_PORT') ?: '3306',
    'hera_db_user' => getenv('HERA_DB_USER') ?: getenv('DB_USER') ?: 'vas_user',
    'hera_db_pass' => getenv('HERA_DB_PASSWORD') ?: getenv('DB_PASSWORD') ?: 'vas_password',
    'portal_db' => getenv('PORTAL_DB') ?: 'vas_portal',
    // Every database the portal may open. HeraProduction and Hera are treated as live (see protected_schemas()).
    'allowed_schemas' => array_values(array_filter(array_map('trim', explode(',', getenv('ALLOWED_SCHEMAS') ?: 'HeraTesting,HeraStaging,HeraProduction,Hera')))),
    'default_schema' => getenv('DEFAULT_SCHEMA') ?: 'HeraTesting',
    'app_name' => 'VAS Cloud Control Center',
    'timezone' => getenv('APP_TZ') ?: 'UTC',
];

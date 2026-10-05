<?php
declare(strict_types=1);
// Liveness / readiness check for Kubernetes and uptime monitors: 200 when the portal's database answers, 503 when it does not.
// Public on purpose and tells nothing about the system beyond ok/down and the build version (the detailed view is the
// System Status page, behind login).
header('Content-Type: application/json'); header('Cache-Control: no-store');
// ?live=1 is the liveness probe: it only proves PHP is answering, so a database outage makes the pod "not ready" (traffic
// is held back) without making Kubernetes restart healthy pods over and over.
if (isset($_GET['live'])) { echo json_encode(['status' => 'alive']); exit; }
try {
    $c = require __DIR__.'/../config/config.php';
    $pdo = new PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['portal_db'].';charset=utf8mb4', $c['db_user'], $c['db_pass'], [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query('SELECT 1');
    echo json_encode(['status' => 'ok', 'version' => substr((string)(getenv('APP_VERSION') ?: 'dev'), 0, 12)]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['status' => 'down']);
}

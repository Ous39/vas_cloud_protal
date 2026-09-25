<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';
$schema = in_array($_GET['schema'] ?? '', allowed_schemas(), true) ? $_GET['schema'] : app_config('default_schema');

$key = authenticate_api_key($action);

try {
    if ($action === 'offers') {
        if (!table_exists($schema, 'vas_offers')) api_error(404, 'vas_offers not available in '.$schema);
        $rows = pdo($schema)->query('SELECT * FROM vas_offers WHERE '.OFFER_ACTIVE_SQL.order_by_pk($schema, 'vas_offers').' LIMIT 200')->fetchAll();
        api_json(array_map('redact_row', $rows));
    }

    elseif ($action === 'subscriber') {
        if (!table_exists($schema, 'subscription')) api_error(404, 'subscription not available in '.$schema);
        $msisdn = trim((string)($_GET['msisdn'] ?? ''));
        if ($msisdn === '') api_error(400, 'msisdn is required');
        $f = ['msisdn' => $msisdn, 'transaction_id' => '', 'subscription_type' => '', 'channel' => '', 'date_from' => '', 'date_to' => ''];
        $rows = search_subscriptions($schema, $f, 1, 100)['rows'];
        $rows = array_map(fn($r) => $r + ['computed_status' => subscription_status($r)], $rows);
        api_json($rows);
    }

    elseif ($action === 'transactions') {
        if (!table_exists($schema, AUDIT_LOG_TABLE)) api_error(404, AUDIT_LOG_TABLE.' not available in '.$schema);
        $f = audit_log_filters_from_request($_GET);
        if ($f['msisdn'] === '' && $f['transaction_id'] === '') api_error(400, 'msisdn or transaction_id is required');
        $rows = search_audit_log($schema, $f, 1, 100)['rows'];
        api_json($rows);
    }

    elseif ($action === 'esim') {
        if (!table_exists($schema, 'esim_profile')) api_error(404, 'esim_profile not available in '.$schema);
        $msisdn = trim((string)($_GET['msisdn'] ?? '')); $iccid = trim((string)($_GET['iccid'] ?? ''));
        if ($msisdn === '' && $iccid === '') api_error(400, 'msisdn or iccid is required');
        $filters = [];
        if ($msisdn !== '') $filters[] = ['col' => 'msisdn', 'op' => 'equals', 'val' => $msisdn];
        if ($iccid !== '') $filters[] = ['col' => 'iccid', 'op' => 'equals', 'val' => $iccid];
        $rows = list_records($schema, 'esim_profile', $filters, 1, 50)['rows'];
        api_json(array_map('redact_row', $rows));
    }

    else {
        api_error(404, 'Unknown action. Available: offers, subscriber, transactions, esim');
    }
} catch (Throwable $e) {
    api_error(400, $e->getMessage());
}

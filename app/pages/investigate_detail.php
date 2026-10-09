<?php
declare(strict_types=1);
// Page: ?page=investigate_detail — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    header('Content-Type: application/json; charset=utf-8');
    try {
        require_perm('view_reports');
        $src = investigate_source($_GET, current_schema());
        $row = $src === 'subscription' ? subscription_log_detail(current_schema(), (int)($_GET['id'] ?? 0)) : audit_log_detail(current_schema(), (int)($_GET['id'] ?? 0), (string)($_GET['d'] ?? ''));
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Transaction not found.']); exit; }
        audit('investigate_view', current_schema(), $src === 'subscription' ? 'subscription' : AUDIT_LOG_TABLE, (string)$row['id'], 'viewed '.$src.' record of '.$row['transaction_id']);
        echo json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }
    exit;

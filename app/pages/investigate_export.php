<?php
declare(strict_types=1);
// Page: ?page=investigate_export — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports');
    $schema=current_schema();
    if (investigate_source($_GET,$schema)==='subscription') {
        $f=subscription_log_filters_from_request($_GET);
        audit('export',$schema,'subscription',null,'Complaint investigation (subscription) CSV export: '.json_encode($f));
        $rows=export_subscription_log($schema,$f);
        header('Content-Type:text/csv');
        header('Content-Disposition: attachment; filename="'.$schema.'_subscription_'.$f['date_from'].'_to_'.$f['date_to'].'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out, array_map('trim', explode(',', SUBSCRIPTION_LOG_COLS)));
        foreach($rows as $row) fputcsv($out,csv_safe_row($row));
        exit;
    }
    $f=audit_log_filters_from_request($_GET);
    audit('export',$schema,AUDIT_LOG_TABLE,null,'Complaint investigation CSV export: '.json_encode($f));
    $rows=export_audit_log($schema,$f);
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="'.$schema.'_audit_log_'.$f['date_from'].'_to_'.$f['date_to'].'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','transaction_id','create_date','msisdn','vendor_entity_name','channel','result_status','result_description','response_time','input_text','output_text']);
    foreach($rows as $row) fputcsv($out,csv_safe_row($row));
    exit;

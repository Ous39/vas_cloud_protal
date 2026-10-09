<?php
declare(strict_types=1);
// Page: ?page=subscriptions_export — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    $f=subscription_filters_from_request($_GET);
    audit('export',$schema,'subscription',null,'Subscriptions CSV export: '.json_encode($f));
    $rows=export_subscriptions($schema,$f);
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="'.$schema.'_subscriptions_export.csv"');
    $out=fopen('php://output','w'); $first=true;
    foreach($rows as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row)); }
    exit;

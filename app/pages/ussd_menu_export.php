<?php
declare(strict_types=1);
// Page: ?page=ussd_menu_export — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables');
    $shortCode = trim((string)($_GET['short_code'] ?? ''));
    if ($shortCode==='') throw new RuntimeException('short_code is required');
    $tree = menu_tree($shortCode);
    audit('export','vas_portal','ussd_menu_nodes',null,'Menu JSON export: '.$shortCode);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="ussd_menu_'.preg_replace('/[^A-Za-z0-9_-]/','_',$shortCode).'.json"');
    echo json_encode(['short_code'=>$shortCode,'generated_at'=>date('c'),'note'=>'Design export from VAS Cloud — not a Mobius or gateway-native config format.','menu'=>$tree], JSON_PRETTY_PRINT);
    exit;

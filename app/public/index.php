<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php'; // page frame and menu helpers (web only)

try { verify_csrf(); } catch(Throwable $e){ flash('danger',$e->getMessage()); redirect('?'); }
$page=$_GET['page'] ?? 'dashboard';
if ($page==='alert_cron') {
    if (!hash_equals(alert_cron_token(), (string)($_GET['token']??''))) { http_response_code(403); header('Content-Type: text/plain'); exit('forbidden'); }
    foreach (['HeraProduction','HeraTesting'] as $s) dispatch_pending_alert_notifications($s);
    portal_housekeeping();
    if (in_array('HeraProduction', allowed_schemas(), true)) { try { maybe_send_daily_summary('HeraProduction'); } catch (Throwable $e) { error_log('daily summary: '.$e->getMessage()); } }
    header('Content-Type: text/plain'); exit('OK');
}
if ($page==='logout') { logout(); redirect('?page=login'); }
if ($page==='login') {
    if ($_SERVER['REQUEST_METHOD']==='POST') { if(login_attempt($_POST['username']??'', $_POST['password']??'')) redirect('?page=dashboard'); flash('danger','Invalid username or password.'); }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet"><link href="style.css?v=<?=e(asset_version())?>" rel="stylesheet"></head><body class="login-page"><main><form method="post" class="login-card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="login-card-header"><img src="comium_logo.png" alt="Comium"><h2>VAS Cloud</h2><p class="mb-0">Sign in to manage VAS operations</p></div><div class="login-card-body"><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?>"><?=e($f['msg'])?></div><?php endforeach;?><label class="form-label">Username</label><input class="form-control mb-3" name="username" autofocus autocomplete="username"><label class="form-label">Password</label><input class="form-control mb-3" name="password" type="password" autocomplete="current-password"><button class="btn btn-custom w-100">Sign in</button></div></form></main></body></html><?php exit;
}
require_login();
if ($page==='switch_schema' && isset($_GET['schema'])) { // back to where the user was, but only if that is this site
    set_current_schema((string)$_GET['schema']); $ref=(string)($_SERVER['HTTP_REFERER'] ?? ''); $rh=(string)parse_url($ref, PHP_URL_HOST); $mine=explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
    redirect($rh!=='' && strcasecmp($rh,$mine)===0 ? $ref : '?');
}

try {
    // each page is its own file in app/pages/ (the name is checked, so only a file in that folder can be included)
    $pageFile = __DIR__.'/../pages/'.(is_string($page) ? $page : '').'.php';
    if (is_string($page) && preg_match('/^[a-z0-9_]+$/', $page) && is_file($pageFile)) require $pageFile;
    throw new RuntimeException('Page not found');
} catch(Throwable $e){ layout_start('Something went wrong'); ?><div class="cardx"><h3>Something went wrong</h3><div class="alert alert-danger"><?=e($e->getMessage())?></div><a class="btn btn-primary" href="?page=dashboard">Back to dashboard</a></div><?php layout_end(); }

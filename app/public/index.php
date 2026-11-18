<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php'; // page frame and menu helpers (web only)

try { verify_csrf(); } catch(Throwable $e){ flash('danger',$e->getMessage()); redirect('?'); }
$page=$_GET['page'] ?? 'dashboard';
// Scheduled menus that are due go live / switch off (Mobius menus and portal items). Run first by alert_cron (every 5 minutes)
// and by schedule_cron (every minute, deploy/k8s/06-schedule-cronjob.yaml), so a launch at 00:00 happens within a minute.
function run_due_schedules(): string {
    $out = [];
    try { $mc = ussd_proxy_config(); if ($mc['mobius_user'] !== '') { $r = mobius_sched_apply($mc); $out[] = 'mobius started='.$r['started'].' ended='.$r['ended'].' failed='.$r['failed']; } } catch (Throwable $e) { error_log('mobius schedule: '.$e->getMessage()); $out[] = 'mobius error'; }
    try { $r = menu_apply_schedules(); $out[] = 'portal on='.$r['on'].' off='.$r['off']; } catch (Throwable $e) { error_log('menu schedule: '.$e->getMessage()); $out[] = 'portal error'; }
    try { $r = offer_sched_apply(); $out[] = 'offers started='.$r['started'].' ended='.$r['ended'].' failed='.$r['failed']; } catch (Throwable $e) { error_log('offer schedule: '.$e->getMessage()); $out[] = 'offers error'; }
    return implode('; ', $out);
}
if ($page==='schedule_cron') {
    if (!hash_equals(alert_cron_token(), (string)($_GET['token']??''))) { http_response_code(403); header('Content-Type: text/plain'); exit('forbidden'); }
    $res = run_due_schedules();
    try { portal_pdo()->prepare("INSERT INTO metric_cache(cache_key,value,expires_at) VALUES('schedule_cron_last',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), expires_at=VALUES(expires_at)")->execute([(string)time(), time() + 30 * 86400]); } catch (Throwable $e) {}
    header('Content-Type: text/plain'); exit('OK '.$res);
}
if ($page==='alert_cron') {
    if (!hash_equals(alert_cron_token(), (string)($_GET['token']??''))) { http_response_code(403); header('Content-Type: text/plain'); exit('forbidden'); }
    run_due_schedules(); // first: a launch at 00:00 must not wait behind the checks below (the job is stopped after 30 s)
    try { builtin_health_run(); } catch (Throwable $e) { error_log('health checks: '.$e->getMessage()); } // Mobius nodes, Hera, database, USSD endpoint
    try { background_integration_checks(); portal_pdo()->exec("DELETE FROM metric_cache WHERE cache_key LIKE 'compute_alerts:%'"); } catch (Throwable $e) { error_log('integration checks: '.$e->getMessage()); } // before the alerts, so a DOWN one is sent now
    foreach (['HeraProduction','HeraTesting'] as $s) dispatch_pending_alert_notifications($s);
    portal_housekeeping();
    foreach (['HeraProduction'] as $s) if (in_array($s, allowed_schemas(), true)) warm_live_figures($s);
    // Offer Health's slow part (last purchase of every offer, kept 30 min): refreshed here every 25 minutes so nobody waits for it
    if (in_array('HeraProduction', allowed_schemas(), true) && intdiv(time(), 300) % 5 === 0) { try { $GLOBALS['CACHE_REFRESH'] = true; offer_health('HeraProduction', 30); } catch (Throwable $e) { error_log('offer health warm: '.$e->getMessage()); } finally { $GLOBALS['CACHE_REFRESH'] = false; } }
    if (in_array('HeraProduction', allowed_schemas(), true)) { try { maybe_send_daily_summary('HeraProduction'); } catch (Throwable $e) { error_log('daily summary: '.$e->getMessage()); } }
    if (in_array('HeraProduction', allowed_schemas(), true)) { try { maybe_send_failure_report('HeraProduction'); } catch (Throwable $e) { error_log('failure report: '.$e->getMessage()); } }
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

if ($page==='mobius_env') { // Production / Testbed for this user's USSD pages (POST, with the CSRF token checked above)
    require_perm('manage_api_keys'); $want=($_POST['env']??'')==='test'?'test':'prod';
    if ($want==='test' && !mobius_testbed_ready(ussd_proxy_config_raw())) { flash('warning','The testbed Mobius is not set up yet: USSD → Settings → Proxy → Mobius connection → Testbed.'); $want='prod'; }
    $_SESSION['mobius_env']=$want; audit('mobius_env',null,'mobius',null,'Switched the USSD pages to '.($want==='test'?'the testbed':'production'));
    $back=(string)($_POST['back']??''); redirect(preg_match('/^\?page=[a-z_]+[^\s]*$/',$back)?$back:'?page=ussd_menus');
}

try {
    // each page is its own file in app/pages/ (the name is checked, so only a file in that folder can be included)
    $pageFile = __DIR__.'/../pages/'.(is_string($page) ? $page : '').'.php';
    if (is_string($page) && preg_match('/^[a-z0-9_]+$/', $page) && is_file($pageFile)) require $pageFile;
    throw new RuntimeException('Page not found');
} catch(Throwable $e){ layout_start('Something went wrong'); ?><div class="cardx"><h3>Something went wrong</h3><div class="alert alert-danger"><?=e($e->getMessage())?></div><a class="btn btn-primary" href="?page=dashboard">Back to dashboard</a></div><?php layout_end(); }

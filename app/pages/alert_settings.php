<?php
declare(strict_types=1);
// Page: ?page=alert_settings — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_api_keys'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['add_recipient','toggle_recipient','save_smtp','save_rules','save_ignored','update_prefs','save_summary','rotate_token'],true)) {
        require_perm('manage_api_keys');
        if ($_POST['do']==='save_smtp') { save_smtp_settings($_POST); flash('success','Mail server saved. Use "Send test email" to check it.'); }
        elseif ($_POST['do']==='add_recipient') { save_alert_recipient((string)($_POST['email']??'')); flash('success','Recipient saved.'); }
        elseif ($_POST['do']==='save_rules') { save_alert_config($_POST); flash('success','Alert rules saved.'); }
        elseif ($_POST['do']==='rotate_token') { rotate_alert_cron_token(); flash('warning','Cron token rotated. The scheduled alert job is now rejected until you update its secret — run the kubectl command shown under "Scheduled alerts".'); }
        elseif ($_POST['do']==='save_summary') { save_summary_config($_POST); flash('success','Daily summary settings saved.'); }
        elseif ($_POST['do']==='save_ignored') { save_alert_ignored_reasons((array)($_POST['ignored']??[])); flash('success','Ignored failure reasons saved.'); }
        elseif ($_POST['do']==='update_prefs') { update_alert_recipient_prefs((int)($_POST['id']??0), !empty($_POST['notify_failure']), !empty($_POST['notify_vendor']), !empty($_POST['notify_slow']), !empty($_POST['notify_summary'])); flash('success','Recipient preferences saved.'); }
        else { toggle_alert_recipient((int)($_POST['id']??0)); flash('success','Recipient updated.'); }
        redirect('?page=alert_settings');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='send_summary') {
        require_perm('manage_api_keys');
        $err = send_daily_summary($schema, null, true);
        flash($err===null?'success':'danger', $err===null?'Summary for yesterday sent to everyone who receives it.':'Could not send: '.$err);
        redirect('?page=alert_settings');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='test_email') {
        require_perm('manage_api_keys');
        $err = send_email_alert('[VAS Cloud] Test email', 'This is a test email from the VAS Cloud portal Alert Settings page. If you can read this, alert emails are working.');
        audit('alert_test_email',$schema,null,null,$err ?? 'sent');
        flash($err===null?'success':'danger', $err===null?'Test email sent — check the inbox(es) below.':'Test email failed: '.$err);
        redirect('?page=alert_settings');
    }
    $cfg = alert_config(); $ignoredReasons = alert_ignored_reasons();
    $seenReasons = array_column(failure_reasons_breakdown($schema, 24, 30), 'c', 'reason');
    unset($seenReasons['(no reason given)']);
    $allReasons = array_values(array_unique(array_merge(array_keys($seenReasons), $ignoredReasons)));
    layout_start('Alert Settings');
    ?>
    <div class="cardx">
        <h3>What to be alerted about</h3>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_rules">
            <div class="col-lg-6"><div class="border rounded p-3 h-100">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="failure_rate_enabled" value="1" id="fre" <?=$cfg['failure_rate_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="fre">High failure rate</label></div>
                <p class="text-muted small">Alert when more than this share of the last hour's <b>customer</b> transactions failed. Hera's own background lookups (no channel) are not counted, so they can't hide a real problem.</p>
                <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Failure rate above (%)</label><input class="form-control" type="number" min="1" max="100" name="failure_rate_pct" value="<?=e($cfg['failure_rate_pct'])?>"></div>
                <div class="col-6"><label class="small text-muted mb-0">Only if at least this many transactions</label><input class="form-control" type="number" min="1" name="failure_min_sample" value="<?=e($cfg['failure_min_sample'])?>"></div></div>
            </div></div>
            <div class="col-lg-6"><div class="border rounded p-3 h-100">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vendor_silent_enabled" value="1" id="vse" <?=$cfg['vendor_silent_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="vse">Vendor gone silent</label></div>
                <p class="text-muted small">Alert when a vendor that was active this time yesterday sent nothing in the last hour.</p>
                <label class="small text-muted mb-0">Vendor must have sent at least this many yesterday</label><input class="form-control" type="number" min="1" name="vendor_silent_min_baseline" value="<?=e($cfg['vendor_silent_min_baseline'])?>">
            </div></div>
            <div class="col-12"><div class="border rounded p-3">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vendor_slow_enabled" value="1" id="vsl" <?=$cfg['vendor_slow_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="vsl">Vendor responding slowly</label></div>
                <p class="text-muted small">Alert when a vendor's average response time over the last hour is above the limit (a vendor can be "up" and still hurting customers). Response times are in milliseconds.</p>
                <div class="row g-2"><div class="col-md-4"><label class="small text-muted mb-0">Average slower than (ms)</label><input class="form-control" type="number" min="50" name="vendor_slow_ms" value="<?=e($cfg['vendor_slow_ms'])?>"></div>
                <div class="col-md-4"><label class="small text-muted mb-0">Only if at least this many transactions</label><input class="form-control" type="number" min="1" name="vendor_slow_min_sample" value="<?=e($cfg['vendor_slow_min_sample'])?>"></div></div>
            </div></div>
            <div class="col-12"><button class="btn btn-primary">Save alert rules</button> <small class="text-muted">Turning an alert off also removes it from the Dashboard and Alerts page.</small></div>
        </form>
    </div>
    <div class="cardx mt-3">
        <h3>Daily summary email</h3>
        <p class="text-muted">One email each morning with yesterday's totals, success rate, channels, vendors (with average response time), top failure reasons and any vendor that went silent. Sent for HeraProduction by the scheduled job, so the 5-minute alert CronJob must be running. Recipients choose below whether they get it.</p>
        <form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_summary">
            <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="summary_enabled" value="1" id="sme" <?=$cfg['summary_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="sme">Send every day</label></div></div>
            <div class="col-auto"><label class="small text-muted mb-0">After (hour, server time <?=e(date('T'))?>)</label><input class="form-control" type="number" min="0" max="23" name="summary_hour" value="<?=e($cfg['summary_hour'])?>"></div>
            <div class="col-auto"><button class="btn btn-primary">Save</button></div>
        </form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="send_summary"><button class="btn btn-sm btn-outline-primary">Send yesterday's summary now</button> <small class="text-muted">Goes to every enabled recipient who has "Daily summary" ticked — use it to check the layout.</small></form>
    </div>
    <div class="cardx mt-3">
        <h3>Failure reasons that count toward the failure-rate alert</h3>
        <p class="text-muted">Tick <b>Ignore</b> on reasons that aren't a system problem — for example a customer with no credit — so they can't trigger a failure-rate alert. They still show up in the Dashboard and reports. Reasons shown are the ones seen in the last 24 hours plus anything you've already ignored.</p>
        <?php if(!$allReasons):?><p class="text-muted mb-0">No failures in the last 24 hours to choose from.</p><?php else:?>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_ignored">
            <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th style="width:90px">Ignore</th><th>Failure reason</th><th>Last 24h</th></tr></thead><tbody>
            <?php foreach($allReasons as $i=>$reason):?><tr><td><input class="form-check-input" type="checkbox" name="ignored[]" value="<?=e($reason)?>" id="ig<?=$i?>" <?=in_array($reason,$ignoredReasons,true)?'checked':''?>></td><td><label for="ig<?=$i?>" class="mb-0"><?=e($reason)?></label></td><td><?=isset($seenReasons[$reason])?number_format((int)$seenReasons[$reason]):'—'?></td></tr><?php endforeach;?>
            </tbody></table></div>
            <button class="btn btn-primary">Save ignored reasons</button>
        </form>
        <?php endif;?>
    </div>
    <div class="cardx mt-3">
        <h3>Push Alerting Setup</h3>
        <p class="text-muted mb-2">1a. <b>Slack:</b> add a Slack Incoming Webhook as an Integration (Service type: <b>Monitoring</b>, Base URL: your webhook URL, Status: Active) — Integrations page. Alerts fire there whenever the Alerts page or the Dashboard is viewed while an alert is active.</p>
        <?php $smtpServer = smtp_server_settings(); $smtp = smtp_settings(); $recipients = alert_recipients(); $envTo = array_filter(array_map('trim', explode(',', (string)getenv('ALERT_EMAIL_TO')))); ?>
        <p class="text-muted mb-2">1b. <b>Email:</b>
            <?php if(!$smtpServer):?><span class="badge bg-secondary">mail server not configured</span> fill in the mail server below, then add recipients.
            <?php elseif(!$smtp):?><span class="badge bg-warning text-dark">no recipients</span> mail server <?=e($smtpServer['host'].':'.$smtpServer['port'])?> (<?=e($smtpServer['secure'])?>) is configured — add at least one recipient below.
            <?php else:?><span class="badge bg-success">configured</span> sends via <?=e($smtp['host'].':'.$smtp['port'])?> (<?=e($smtp['secure'])?>, settings from <?=e($smtp['source'])?>) to <?=count($smtp['to'])?> recipient(s).
            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="test_email"><button class="btn btn-sm btn-outline-primary ms-2">Send test email</button></form><?php endif;?></p>
        <details class="mb-3" <?=$smtpServer?'':'open'?>><summary class="fw-semibold">Mail server settings</summary>
        <form method="post" class="row g-2 mt-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_smtp">
            <div class="col-md-5"><label class="small text-muted mb-0">SMTP host</label><input class="form-control" name="host" value="<?=e($smtpServer['host']??'')?>" placeholder="mail.company.com" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Port</label><input class="form-control" type="number" name="port" value="<?=e($smtpServer['port']??587)?>" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Security</label><select class="form-select" name="secure"><?php foreach(['tls'=>'STARTTLS (587)','ssl'=>'SSL/TLS (465)','none'=>'None (25)'] as $v=>$lbl):?><option value="<?=$v?>" <?=($smtpServer['secure']??'tls')===$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select></div>
            <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="tls_verify" value="1" id="tlsv" <?=($smtpServer['verify']??true)?'checked':''?>><label class="form-check-label small" for="tlsv">Verify certificate</label></div></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Username <small>(blank if none)</small></label><input class="form-control" name="username" value="<?=e($smtpServer['user']??'')?>" autocomplete="off"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Password</label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?=!empty($smtpServer['has_password'])?'saved — leave blank to keep':'password'?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">From address</label><input class="form-control" type="email" name="from_email" value="<?=e(($smtpServer['from']??'')==='vas-cloud@localhost'?'':($smtpServer['from']??''))?>" placeholder="alerts@company.com"></div>
            <div class="col-12"><button class="btn btn-primary">Save mail server</button> <small class="text-muted">The password is stored encrypted and is never shown again.<?php if(($smtpServer['source']??'')==='environment'):?> Currently using the environment's settings; saving here overrides them.<?php endif;?></small></div>
        </form></details>
        <form method="post" class="row g-2 mb-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="add_recipient">
            <div class="col-md-6"><input class="form-control" type="email" name="email" placeholder="name@company.com" required></div>
            <div class="col-md-3"><button class="btn btn-primary">Add recipient</button></div>
        </form>
        <?php if($recipients || $envTo):?>
        <div class="table-scroll mb-3"><table class="table table-sm mb-0"><thead><tr><th>Email</th><th>Status</th><th>Receives</th><th></th></tr></thead><tbody>
        <?php foreach($recipients as $r):?><tr><td><?=e($r['email'])?></td><td><span class="badge <?=(int)$r['active']?'bg-success':'bg-secondary'?>"><?=(int)$r['active']?'Enabled':'Disabled'?></span></td>
            <td><form method="post" class="d-flex gap-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="update_prefs"><input type="hidden" name="id" value="<?=e($r['id'])?>">
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_failure" value="1" data-autosubmit <?=(int)$r['notify_failure']?'checked':''?>> Failure rate</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_vendor" value="1" data-autosubmit <?=(int)$r['notify_vendor']?'checked':''?>> Vendor silent</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_slow" value="1" data-autosubmit <?=(int)$r['notify_slow']?'checked':''?>> Vendor slow</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_summary" value="1" data-autosubmit <?=(int)$r['notify_summary']?'checked':''?>> Daily summary</label></form></td>
            <td><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="toggle_recipient"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark"><?=(int)$r['active']?'Disable':'Enable'?></button></form></td></tr><?php endforeach;?>
        <?php foreach($envTo as $addr):?><tr><td><?=e($addr)?></td><td><span class="badge bg-info text-dark">From environment</span></td><td><small class="text-muted">all alerts</small></td><td><small class="text-muted">set via ALERT_EMAIL_TO</small></td></tr><?php endforeach;?>
        </tbody></table></div>
        <?php endif;?>
        <p class="text-muted mb-0">2. For alerts even when nobody has the app open, point a scheduler (e.g. a Kubernetes CronJob — see deploy/k8s/05-alert-cronjob.yaml) at this URL every few minutes:</p>
        <p class="text-muted small mb-1">In-cluster URL (what the CronJob calls — no public hostname or /portal prefix involved):</p>
        <pre class="mb-2"><?='http://vas-cloud-app.vas-cloud.svc.cluster.local/?page=alert_cron&token='.e(alert_cron_token())?></pre>
        <form method="post" class="d-inline" data-confirm="Rotate the cron token? Scheduled alerts and the daily summary stop until you update the Kubernetes secret."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="rotate_token"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-rotate me-1"></i>Rotate token</button></form>
        <span class="text-muted small ms-2">After rotating, update the secret the CronJob reads:</span>
        <pre class="mt-2 mb-0">kubectl -n vas-cloud create secret generic vas-cloud-alert-cron-secret --from-literal=TOKEN=<?=e(alert_cron_token())?> --dry-run=client -o yaml | kubectl apply -f -</pre>
    </div>
    </div>
    <p class="mt-3"><a href="?page=alerts">&larr; Back to Alerts &amp; Monitoring</a></p>
    <?php layout_end(); exit;

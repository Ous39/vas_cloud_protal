<?php
declare(strict_types=1);
// Page: ?page=ussd_proxy — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_api_keys');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??'');
        if ($do==='save') { save_ussd_proxy_config($_POST); flash('success','USSD proxy settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_share') { save_share_config($_POST); flash('success','Shared Bundle settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_refund') { try { save_refund_config($_POST); flash('success','Refund settings saved.'); } catch (RuntimeException $e) { flash('danger',$e->getMessage()); } redirect('?page=ussd_proxy'); }
        if ($do==='save_purchase') { save_purchase_config($_POST); flash('success','Purchase settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_mobius') { save_mobius_config($_POST); flash('success','Mobius connection saved. Use "Test connection" to check the login.'); redirect('?page=ussd_proxy'); }
        if ($do==='rotate') { ussd_proxy_rotate_token(); flash('warning','New token created. Update the URL in the Mobius menu(s) — the old URL no longer works.'); redirect('?page=ussd_proxy'); }
        if ($do==='clear') { portal_pdo()->exec('DELETE FROM ussd_proxy_log'); audit('ussd_proxy_log_clear',null,'ussd_proxy_log',null,null); flash('success','Captured requests cleared.'); redirect('?page=ussd_proxy'); }
    }
    $cfg=ussd_proxy_config(); $token=ussd_proxy_token(); $mobiusTest=null;
    $mobiusProbe=null; $heraAsk=null; $shareAsk=null;
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='share_ask') { $shareAsk=share_ask($cfg,(string)($_POST['sa_op']??''),(string)($_POST['sa_msisdn']??'')); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='hera_ask') { $heraAsk=hera_ask($cfg,(string)($_POST['ask_op']??''),(string)($_POST['ask_msisdn']??''),trim((string)($_POST['ask_offer']??'')),trim((string)($_POST['ask_sub']??''))); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='mobius_probe') { $mobiusProbe=mobius_probe($cfg); audit('ussd_proxy_mobius_probe',null,'ussd_proxy_config',null,json_encode(array_map(fn($r)=>['l'=>$r['label'],'ok'=>$r['ok']],$mobiusProbe))); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='mobius_test') { $mobiusTest=mobius_test($cfg); audit('ussd_proxy_mobius_test',null,'ussd_proxy_config',null,json_encode(array_map(fn($r)=>['base'=>$r['base'],'ok'=>$r['ok']],$mobiusTest))); }
    $logs=portal_pdo()->query('SELECT * FROM ussd_proxy_log ORDER BY id DESC LIMIT 25')->fetchAll();
    ussd_purchase_table(); $purchases=portal_pdo()->query('SELECT * FROM ussd_purchases ORDER BY id DESC LIMIT 15')->fetchAll();
    // the tester: a sample request (or a captured one to replay), run through the live logic without storing any session
    $test=null; $testIn=''; $testMode='proxy'; $testCt='application/json';
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['test','replay'],true)) {
        $testMode=(($_POST['tmode']??'')==='ms_initiated')?'ms_initiated':'proxy';
        if ($_POST['do']==='replay') {
            $st=portal_pdo()->prepare('SELECT * FROM ussd_proxy_log WHERE id=?'); $st->execute([(int)($_POST['id']??0)]); $lg=$st->fetch();
            if (!$lg) throw new RuntimeException('That captured request is gone.');
            $testMode=$lg['mode']; $q=json_decode((string)$lg['query_text'],true)?:[]; $raw=(string)$lg['body_text'];
            $flat=ussd_proxy_flatten('',$raw,$q,[]); $testIn=$raw!==''?$raw:http_build_query($q);
        } else {
            $testIn=(string)($_POST['sample']??''); $raw=$testIn; $q=[];
            if (!ctype_space($testIn) && $testIn!=='' && $testIn[0]!=='{' && $testIn[0]!=='<' && !str_contains($testIn,"\n") ) { parse_str($testIn,$q); $raw=''; }
            $flat=ussd_proxy_flatten('',$raw,$q,[]);
        }
        $test=ussd_proxy_process($cfg,$testMode,$flat,true,true); $test['flat']=$flat;
    }
    $keys=[]; foreach($logs as $lg){ $q=json_decode((string)$lg['query_text'],true)?:[]; $keys=array_merge($keys,array_keys(ussd_proxy_flatten('',(string)$lg['body_text'],$q,[]))); if(count($keys)>0) break; }
    $keys=array_values(array_unique($keys));
    $host=($_SERVER['HTTP_HOST']??'your-host'); $proto=(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'))?'https':'http';
    $base=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/'); $urlFor=fn($m)=>$proto.'://'.$host.$base.'/ussd.php?t='.$token.'&m='.$m;
    layout_start('USSD Proxy'); ussd_subnav('ussd_proxy');
    ?>
    <?php $checklist=ussd_setup_checklist($cfg); $nOk=count(array_filter($checklist,fn($c)=>$c['state']==='ok')); $tabName=['connect'=>'Connection','buy'=>'Buying offers','share'=>'Shared Bundle'];?>
    <ul class="nav nav-tabs mb-3" id="proxyTabs" role="tablist">
        <?php foreach(['overview'=>['Overview','fa-list-check'],'connect'=>['Connection','fa-plug'],'buy'=>['Buying offers','fa-cart-shopping'],'share'=>['Shared Bundle','fa-share-nodes'],'tools'=>['Tools & requests','fa-screwdriver-wrench']] as $tk=>[$tl,$ti]):?>
        <li class="nav-item" role="presentation"><button class="nav-link <?=$tk==='overview'?'active':''?>" type="button" data-bs-toggle="tab" data-bs-target="#tab-<?=$tk?>"><i class="fa-solid <?=$ti?> me-1"></i><?=e($tl)?><?php if($tk==='overview'):?> <span class="badge <?=$nOk===count($checklist)?'bg-success':'bg-warning text-dark'?>"><?=$nOk?>/<?=count($checklist)?></span><?php endif;?></button></li>
        <?php endforeach;?>
    </ul>
    <div class="tab-content">
    <div class="tab-pane fade show active" id="tab-overview">
    <div class="cardx"><h3 class="mb-2"><i class="fa-solid fa-list-check me-2"></i>Is it ready for customers?</h3>
        <p class="text-muted small">Everything that has to be right for a customer to dial a code and be served. Press a line to go to its settings.</p>
        <ul class="list-unstyled mb-0"><?php foreach($checklist as $c):?><li class="py-1 d-flex gap-2 align-items-start"><span class="badge <?=['ok'=>'bg-success','warn'=>'bg-warning text-dark','off'=>'bg-secondary'][$c['state']]?> mt-1" style="min-width:3.2rem"><?=['ok'=>'ok','warn'=>'check','off'=>'off'][$c['state']]?></span><span><a href="#" data-goto="<?=e($c['tab'])?>"><b><?=e($c['title'])?></b></a> <span class="text-muted"><?=e($c['detail'])?></span></span></li><?php endforeach;?></ul>
        <div class="small text-muted mt-3">Menus: <a href="?page=ussd_menu">Menu Builder</a> · <a href="?page=shortcodes">Short Codes</a> · <a href="?page=status">System Status</a> · the address to give Mobius is under <a href="#" data-goto="connect">Connection</a>.</div>
    </div>
    </div>
    <div class="tab-pane fade" id="tab-connect">
    <div class="cardx">
        <h3><i class="fa-solid fa-plug me-2"></i>USSD proxy endpoint <span class="badge <?=$cfg['enabled']==='1'?($cfg['mode']==='live'?'bg-success':'bg-warning text-dark'):'bg-secondary'?> ms-2"><?=$cfg['enabled']==='1'?($cfg['mode']==='live'?'ON — serving menus':'ON — capture only'):'OFF'?></span></h3>
        <p class="text-muted mb-2">The address a Mobius <b>PROXY</b> (or <b>MS_INITIATED</b>) menu calls. While the endpoint is <b>off</b> Mobius gets "Not found" and nothing changes for customers. <b>Capture</b> mode only records what Mobius sends (useful when connecting a new menu); <b>Live</b> serves the menus built in the <a href="?page=ussd_menu">Menu Builder</a>. Only the Mobius menu you point at this address is affected — no other short code is touched.</p>
        <p class="mb-1 small text-muted">Put one of these in the Mobius menu's <b>URL</b> field (use the address Mobius can actually reach — the in-cluster one only works from inside the cluster):</p>
        <?php foreach(['proxy'=>'PROXY menu','ms_initiated'=>'MS_INITIATED menu'] as $m=>$lbl):?>
        <div class="small fw-semibold mt-2"><?=e($lbl)?> — public address</div><pre class="mb-1 txid" title="Click to select, then copy" data-public-url="ussd.php?t=<?=e($token)?>&amp;m=<?=e($m)?>"><?=e($urlFor($m))?></pre>
        <div class="small text-muted">in-cluster: <code><?=e('http://vas-cloud-app.vas-cloud.svc.cluster.local/ussd.php?t='.$token.'&m='.$m)?></code></div>
        <?php endforeach;?>
        <form method="post" class="mt-3 d-inline" data-confirm="Create a new token? The URL in every Mobius menu must be updated."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="rotate"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-rotate me-1"></i>New token</button></form>
        <span class="small text-muted ms-2">The token is the password to this endpoint — treat the URL like a secret.</span>
    </div>

    <form method="post" class="cardx mt-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save">
        <h3>Settings</h3>
        <div class="row g-3">
            <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="pe" <?=$cfg['enabled']==='1'?'checked':''?>><label class="form-check-label fw-semibold" for="pe">Endpoint enabled</label></div></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="mode"><option value="capture" <?=$cfg['mode']!=='live'?'selected':''?>>Capture only (learn the format)</option><option value="live" <?=$cfg['mode']==='live'?'selected':''?>>Live (serve the menus)</option></select></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Only accept calls from these IPs <small>(optional — blank = any; e.g. 192.168.162.20)</small></label><input class="form-control" name="allow_ips" value="<?=e($cfg['allow_ips'])?>"></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Short code served for the PROXY menu</label><input class="form-control" name="shortcode_proxy" value="<?=e($cfg['shortcode_proxy'])?>"></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Short code served for the MS_INITIATED menu</label><input class="form-control" name="shortcode_ms_initiated" value="<?=e($cfg['shortcode_ms_initiated'])?>"></div>
        </div>
        <h5 class="mt-4">What Mobius sends <small class="text-muted">(fill in after a capture — the names below suggest themselves from the latest captured request)</small></h5>
        <datalist id="keyList"><?php foreach($keys as $k):?><option value="<?=e($k)?>"><?php endforeach;?></datalist>
        <div class="row g-3">
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the customer's number</label><input class="form-control" list="keyList" name="f_msisdn" value="<?=e($cfg['f_msisdn'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the session id</label><input class="form-control" list="keyList" name="f_session" value="<?=e($cfg['f_session'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with what they typed</label><input class="form-control" list="keyList" name="f_input" value="<?=e($cfg['f_input'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the short code <small>(optional)</small></label><input class="form-control" list="keyList" name="f_shortcode" value="<?=e($cfg['f_shortcode'])?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">The typed text is…</label><select class="form-select" name="reply_mode"><option value="step" <?=$cfg['reply_mode']!=='cumulative'?'selected':''?>>just the latest reply (we remember the session)</option><option value="cumulative" <?=$cfg['reply_mode']==='cumulative'?'selected':''?>>everything so far, e.g. 1*2 or *9606*9090*1*2#</option></select></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Session timeout (s)</label><input class="form-control" type="number" name="session_ttl" value="<?=e($cfg['session_ttl'])?>"></div>
        </div>
        <h5 class="mt-4">What we send back</h5>
        <div class="row g-3">
            <div class="col-md-4"><label class="small text-muted mb-0">Content type</label><input class="form-control" name="resp_type" value="<?=e($cfg['resp_type'])?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Word for "session continues" / "ends" in {end}</label><div class="input-group"><input class="form-control" name="end_false" value="<?=e($cfg['end_false'])?>"><input class="form-control" name="end_true" value="<?=e($cfg['end_true'])?>"></div></div>
            <div class="col-12"><label class="small text-muted mb-0">Reply template <small>— placeholders: <code>{text}</code> <code>{text_json}</code> <code>{text_xml}</code> <code>{text_url}</code> <code>{end}</code> <code>{end_int}</code> <code>{con_end}</code> (CON/END) <code>{session}</code> <code>{msisdn}</code></small></label><textarea class="form-control code" rows="3" name="resp_body"><?=e($cfg['resp_body'])?></textarea></div>
            <div class="col-12"><label class="small text-muted mb-0">Reply used in capture mode</label><input class="form-control" name="capture_body" value="<?=e($cfg['capture_body'])?>"></div>
        </div>
        <button class="btn btn-primary mt-3">Save settings</button>
    </form>

    <div class="cardx mt-3"><h3>Mobius connection <small class="text-muted">(for PROXY menus)</small></h3>
        <p class="text-muted">A PROXY menu does not take its screen from our HTTP reply. Mobius sends us each reply, and we send the next screen back through <b>Mobius's REST API</b> (<code>auth/login</code>, then <code>ussdcalls/proxy</code>). That needs a Mobius API user — ideally a dedicated one created under <i>Admins</i> in Mobius, not your own. The password is turned into the MD5 hash Mobius expects and only that hash is kept, <b>encrypted</b>; it is never shown again.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_mobius">
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="push_enabled" value="1" id="pp" <?=$cfg['push_enabled']==='1'?'checked':''?>><label class="form-check-label fw-semibold" for="pp">Answer PROXY menus through the Mobius API <small class="text-muted">(applies in Live mode, to the PROXY menu only)</small></label></div></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Mobius REST address(es) <small>(comma-separated if there are several servers; tried in order)</small></label><input class="form-control" name="mobius_base" value="<?=e($cfg['mobius_base'])?>" placeholder="http://192.168.162.20:28080/rest/"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">API user name</label><input class="form-control" name="mobius_user" value="<?=e($cfg['mobius_user'])?>" autocomplete="off"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Password</label><input class="form-control" type="password" name="mobius_pass" autocomplete="new-password" placeholder="<?=$cfg['mobius_pass']!==''?'saved — leave blank to keep':'password'?>"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save connection</button></div>
        </form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="mobius_test"><button class="btn btn-sm btn-outline-primary">Test connection</button> <small class="text-muted">Logs in with the saved details and reports the result. Nothing else is sent.</small></form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="mobius_probe"><button class="btn btn-sm btn-outline-secondary">Run diagnostics</button> <small class="text-muted">For when screens aren't getting through: logs in, then makes a harmless read-only call (<code>ussdcalls/count</code>) in each way Mobius might want the login presented, and shows what Mobius says to each. Sends no screen and changes nothing.</small></form>
        <?php if($mobiusProbe):?><div class="mt-2"><?php foreach($mobiusProbe as $mp):?><div class="small"><span class="badge <?=$mp['ok']?'bg-success':'bg-danger'?> me-1"><?=$mp['ok']?'OK':'Refused'?></span><b><?=e($mp['label'])?></b> — <?=e($mp['msg'])?></div><?php endforeach;?></div><?php endif;?>
        <?php if($mobiusTest):?><div class="mt-2"><?php foreach($mobiusTest as $mt):?><div class="small"><span class="badge <?=$mt['ok']?'bg-success':'bg-danger'?> me-1"><?=$mt['ok']?'OK':'Failed'?></span><code><?=e($mt['base'])?></code> <?=e($mt['msg'])?></div><?php endforeach;?></div><?php endif;?>
    </div>
    </div>
    <div class="tab-pane fade" id="tab-buy">
    <div class="cardx"><h3>Buying from the menu <small class="text-muted">(offer items in the Menu Builder)</small></h3>
        <p class="text-muted">When a customer picks an <b>offer</b> item, the menu shows its name and price (read from <b><?=e(USSD_OFFER_SCHEMA)?></b> only) and asks <i>1. Confirm / 2. Cancel</i>. What happens on Confirm depends on the mode. Each call can buy each offer only once, even if Mobius repeats a request.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_purchase">
            <div class="col-md-4"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="purchase_mode">
                <option value="off" <?=$cfg['purchase_mode']==='off'?'selected':''?>>Off — nothing is bought</option>
                <option value="test" <?=$cfg['purchase_mode']==='test'?'selected':''?>>Test — record it, tell the customer it was a test</option>
                <option value="test_low" <?=$cfg['purchase_mode']==='test_low'?'selected':''?>>Test — pretend the balance is too low</option>
                <option value="live" <?=$cfg['purchase_mode']==='live'?'selected':''?>>Live — send the purchase request below</option></select></div>
            <div class="col-md-8"><label class="small text-muted mb-0">Purchase address <small>(POST, JSON — the Hera <b>test</b> environment; needed for Live only)</small></label><input class="form-control" name="purchase_url" value="<?=e($cfg['purchase_url'])?>" placeholder="https://…"></div>
            <div class="col-12"><label class="small text-muted mb-0">Request body <small>— placeholders: <code>{msisdn}</code> <code>{offer_code}</code> <code>{other_offer_code}</code> <code>{vendor}</code> <code>{price}</code> <code>{price_d}</code> (D9) <code>{txn}</code> (the call id) <code>{shortcode}</code> <code>{imsi}</code> <code>{local_address_json}</code> <code>{remote_address_json}</code> <code>{local_dialog_id}</code> <code>{remote_dialog_id}</code> (taken from Mobius's own request)</small></label><textarea class="form-control code" rows="3" name="purchase_body"><?=e($cfg['purchase_body'])?></textarea></div>
            <div class="col-12"><label class="small text-muted mb-0">Request body for buying <b>for another number</b> <small>— same placeholders plus <code>{other_msisdn}</code> (the digits as the customer typed them). Pre-filled with the request your Mobius menu sends. Blank = buying for another number is not switched on (customers are told so, nothing is sent)</small></label><textarea class="form-control code" rows="3" name="purchase_body_other"><?=e($cfg['purchase_body_other'])?></textarea></div>
            <div class="col-md-5"><label class="small text-muted mb-0">Headers to send <small>(one per line, e.g. <code>X-API-KEY: …</code> and <code>X-USERNAME: USSD</code> — kept encrypted, never shown again)</small></label><textarea class="form-control code" rows="3" name="purchase_auth" autocomplete="off" placeholder="<?=$cfg['purchase_auth']!==''?'saved — leave blank to keep':"X-API-KEY: …\nX-USERNAME: USSD"?>"></textarea><?php if($cfg['purchase_auth']!==''):?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="purchase_auth_clear" value="1" id="pac"><label class="form-check-label small" for="pac">Remove the saved header</label></div><?php endif;?></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Success means <small>(text the reply must contain. Blank = the customer is only told the request was <b>sent</b>; see the real reply in the table below, then fill this in)</small></label><input class="form-control" name="purchase_ok_match" value="<?=e($cfg['purchase_ok_match'])?>" placeholder="e.g. success"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Show Hera's own message <small>(name of the reply field that holds the text for the customer, e.g. <code>message</code>; blank = our wording)</small></label><input class="form-control" name="purchase_reply_field" value="<?=e($cfg['purchase_reply_field'])?>"></div>
            <div class="col-md-12"><label class="small text-muted mb-0">Low balance means <small>(if a failed reply contains any of these words, comma-separated, the customer is told the balance is too low)</small></label><input class="form-control" name="purchase_lowbal" value="<?=e($cfg['purchase_lowbal'])?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Timeout (s)</label><input class="form-control" type="number" min="2" max="15" name="purchase_timeout" value="<?=e($cfg['purchase_timeout'])?>"></div>
            <div class="col-12"><button class="btn btn-primary">Save purchase settings</button></div>
        </form>
        <form method="post" class="row g-2 mt-3 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="hera_ask">
            <div class="col-12"><b class="small">Ask Hera about an offer</b> <small class="text-muted">— the same questions the phone menu asks while browsing. Buys nothing, and shows Hera's raw reply.</small></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="ask_op"><option value="chooseOffer">Details of one offer (chooseOffer)</option><option value="listOffer">List a sub-category (listOffer)</option></select></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="ask_msisdn" placeholder="Phone number, e.g. 220…" value="<?=e((string)($_POST['ask_msisdn']??($purchases[0]['msisdn']??'')))?>"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="ask_offer" placeholder="Offer code" value="<?=e((string)($_POST['ask_offer']??($purchases[0]['offer_code']??'')))?>"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="ask_sub" placeholder="or sub-category" value="<?=e((string)($_POST['ask_sub']??''))?>"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Ask Hera</button></div>
        </form>
        <?php if($heraAsk):?><div class="mt-2 small"><div><b>Sent:</b> <code><?=e(json_encode($heraAsk['sent'],JSON_UNESCAPED_SLASHES))?></code></div><div><b>Hera answered</b> (HTTP <?=e($heraAsk['code'])?>)<?=$heraAsk['error']!==''?': '.e($heraAsk['error']):''?>:</div><pre class="mb-0"><?=e($heraAsk['raw'])?></pre></div><?php endif;?>
        <?php if($purchases):?><div class="table-scroll mt-3"><table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Number</th><th>Offer</th><th>Mode</th><th>Result</th><th>Shown to customer</th><th>Refund</th></tr></thead><tbody>
            <?php foreach($purchases as $pu):?><tr><td class="text-nowrap"><?=e($pu['created_at'])?></td><td><?=e($pu['msisdn'])?><?=!empty($pu['recipient'])?' <small class="text-muted">→ '.e($pu['recipient']).'</small>':''?></td><td title="<?=e((string)($pu['request_body']??''))?>"><?=e($pu['offer_code'])?> <small class="text-muted"><?=e($pu['offer_name'])?></small><?=!empty($pu['request_body'])?' <i class="fa-solid fa-code text-muted" title="Hover to see the request we sent"></i>':''?></td><td><?=e($pu['mode'])?></td><td><span class="badge <?=['ok'=>'bg-success','sent'=>'bg-primary','blocked'=>'bg-secondary','test'=>'bg-info text-dark','lowbal'=>'bg-warning text-dark','failed'=>'bg-danger','pending'=>'bg-warning text-dark'][$pu['status']]??'bg-secondary'?>"><?=e($pu['status'])?></span><?=$pu['http_code']?' <small class="text-muted">HTTP '.e($pu['http_code']).'</small>':''?></td><td title="<?=e((string)$pu['response'])?>"><?=e((string)$pu['reply_text'])?></td>
                <td class="text-nowrap"><?php $rs=(string)($pu['refund_status']??'');?><?php if($rs==='ok'):?><span class="badge bg-success" title="<?=e((string)($pu['refund_note']??''))?> — <?=e((string)($pu['refund_by']??''))?>, <?=e((string)($pu['refund_at']??''))?>">refunded</span>
                    <?php elseif($rs==='pending'):?><span class="badge bg-warning text-dark">in progress</span>
                    <?php elseif(ussd_refund_eligible($pu) && $cfg['refund_mode']!=='off'):?><a class="btn btn-sm btn-outline-danger py-0" href="?page=refunds&msisdn=<?=urlencode((string)(!empty($pu['recipient'])?$pu['recipient']:$pu['msisdn']))?>&offer=<?=urlencode((string)$pu['offer_code'])?>&date=<?=urlencode((string)$pu['created_at'])?>&purchase=<?=(int)$pu['id']?>" title="Look into it first, then refund">Investigate &amp; refund</a>
                    <?php else:?><span class="text-muted">—</span><?php endif;?>
                    <?php if(in_array($rs,['failed','test'],true)):?> <span class="badge <?=$rs==='failed'?'bg-danger':'bg-info text-dark'?>" title="<?=e((string)($pu['refund_note']??''))?>">last: <?=e($rs)?></span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <div class="cardx mt-3"><h3>Refunds <small class="text-muted">(Hera BundleSubscription, channel REF — for a purchase made for yourself or for another number)</small></h3>
        <p class="text-muted small">Refunds are made on the <a href="?page=refunds">Refunds page</a>: look into a number first, then send the request (offer code, vendor, number, channel, time) to Hera — once per number, offer and time. Each live purchase above has an <b>Investigate &amp; refund</b> link that opens it there with the details filled in. The headers are the ones saved for purchases. <b>Test</b> records the refund and sends nothing; <b>Off</b> blocks refunds.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_refund">
            <div class="row g-2"><div class="col-md-3"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="refund_mode"><?php foreach(['off'=>'Off — the button is hidden','test'=>'Test — record it, send nothing','live'=>'Live — ask Hera'] as $k=>$l):?><option value="<?=$k?>" <?=$cfg['refund_mode']===$k?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
                <div class="col-md-9"><label class="small text-muted mb-0">Refund address (POST, JSON)</label><input class="form-control" name="refund_url" value="<?=e($cfg['refund_url'])?>"></div>
                <div class="col-12"><label class="small text-muted mb-0">Headers for refunds <small>(one per line — <code>X-API-KEY: …</code>, <code>X-USERNAME: …</code>, <code>X-HASHED-PASSWORD: …</code>; kept encrypted, never shown again. Leave empty to use the purchase headers. The refund address and its API user must belong together: the test purchase user does not exist on the production Hera.)</small></label>
                    <textarea class="form-control code" rows="3" name="refund_auth" autocomplete="off" placeholder="<?=$cfg['refund_auth']!==''?'saved — leave blank to keep':"X-API-KEY: …\nX-USERNAME: …\nX-HASHED-PASSWORD: …"?>"></textarea>
                    <?php if($cfg['refund_auth']!==''):?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="refund_auth_clear" value="1" id="rac"><label class="form-check-label small" for="rac">Remove the saved refund headers (use the purchase headers again)</label></div><?php endif;?></div>
                </div>
            <button class="btn btn-primary mt-2">Save refund settings</button></form></div>
    </div>
    <div class="tab-pane fade" id="tab-share">
    <div class="cardx"><h3>Shared Bundle <small class="text-muted">(Seddo — the "Shared Bundle service" menu item)</small></h3>
        <p class="text-muted">Buy the bundle, add a sharing number, check balance and numbers — the flow from the Shared Bundle diagram, calling Hera's <code>…/prepaid/ShareBundle/</code> addresses. <b>Test</b> simulates every answer and sends nothing. In <b>Live</b>, the calls are real and use the headers saved for purchases. The bodies below were copied from Mobius's own requests (IMSI, addresses and dialog ids come from the live call). To change one, copy the request from a Mobius log line; a blank body blocks that call. The newest replies are in the <b>Buying offers</b> tab's table.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_share">
            <div class="col-md-3"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="share_mode"><option value="off" <?=$cfg['share_mode']==='off'?'selected':''?>>Off</option><option value="test" <?=$cfg['share_mode']==='test'?'selected':''?>>Test — simulated, nothing sent</option><option value="live" <?=$cfg['share_mode']==='live'?'selected':''?>>Live — real calls</option></select></div>
            <div class="col-md-7"><label class="small text-muted mb-0">ShareBundle address <small>(subscribe / addNumber / DataUsage / listNumber are added to it)</small></label><input class="form-control" name="share_base" value="<?=e($cfg['share_base'])?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Success code</label><input class="form-control" name="share_ok_code" value="<?=e($cfg['share_ok_code'])?>"></div>
            <div class="col-12"><details><summary class="small">Request bodies <small class="text-muted">— placeholders: <code>{msisdn}</code> <code>{other_msisdn}</code> <code>{offer_code}</code> <code>{vendor}</code> <code>{price}</code> <code>{price_d}</code> <code>{txn}</code></small></summary>
                <div class="row g-2 mt-1">
                <?php foreach(['subscribe'=>'Subscribe (blank = blocked)','validate'=>'Validate a number (blank = skipped)','add'=>'Add the number (blank = blocked)','balance'=>'Balance','numbers'=>'My numbers'] as $op=>$lab):?>
                    <div class="col-md-6"><label class="small text-muted mb-0"><?=e($lab)?></label><textarea class="form-control code" rows="2" name="share_body_<?=e($op)?>"><?=e($cfg['share_body_'.$op])?></textarea></div>
                <?php endforeach;?></div></details></div>
            <div class="col-12"><button class="btn btn-primary">Save Shared Bundle settings</button></div>
        </form>
        <form method="post" class="row g-2 mt-2 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="share_ask">
            <div class="col-12"><b class="small">Ask Hera about an account</b> <small class="text-muted">— read-only: shows Hera's raw reply for balance or numbers, so the screens can be matched to it.</small></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="sa_op"><option value="balance">Balance (DataUsage)</option><option value="numbers">My numbers (listNumber)</option></select></div>
            <div class="col-md-5"><input class="form-control form-control-sm" name="sa_msisdn" placeholder="Phone number, e.g. 220…" value="<?=e((string)($_POST['sa_msisdn']??''))?>"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Ask Hera</button></div></form>
        <?php if($shareAsk):?><div class="mt-2 small"><div><b>Hera answered</b> (HTTP <?=e($shareAsk['code'])?>)<?=$shareAsk['error']!==''?': '.e($shareAsk['error']):''?> — shown to a customer as:</div><pre class="mb-1"><?=e($shareAsk['text'])?></pre><div>Raw reply:</div><pre class="mb-0"><?=e($shareAsk['raw'])?></pre></div><?php endif;?>
    </div>
    </div>
    <div class="tab-pane fade" id="tab-tools">
    <div class="cardx"><h3>Try it without Mobius</h3>
        <p class="text-muted">Paste a sample request (JSON, XML or <code>name=value&amp;name=value</code>) — or press <b>Replay</b> on a captured one below. It runs the live logic with the settings above and shows what would be sent back. Nothing is stored and no session is kept.</p>
        <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="test">
            <div class="col-12"><textarea class="form-control code" rows="3" name="sample" placeholder='{"msisdn":"220xxxxxxx","sessionId":"abc123","text":"1"}'><?=e($testIn)?></textarea></div>
            <div class="col-md-3"><select class="form-select" name="tmode"><option value="proxy" <?=$testMode==='proxy'?'selected':''?>>as the PROXY menu</option><option value="ms_initiated" <?=$testMode==='ms_initiated'?'selected':''?>>as the MS_INITIATED menu</option></select></div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100">Run</button></div>
        </form>
        <?php if($test):?><div class="mt-3"><div class="small text-muted">Fields we read: <?php foreach($test['flat'] as $k=>$v):?><code><?=e($k)?></code>=<?=e(mb_strimwidth($v,0,40,'…'))?> · <?php endforeach; if(!$test['flat']):?><b class="text-danger">none — the sample couldn't be read</b><?php endif;?></div>
            <?php if(!empty($test['screen'])):?><div class="small text-muted mt-1">Short code <b><?=e($test['sc'])?></b>, replies so far: <b><?=e(implode(' → ',$test['replies'])?:'(none)')?></b>, <?=e($test['note'])?></div><?php endif;?>
            <pre class="mt-2 mb-0"><?=e($test['body'])?></pre><div class="small text-muted">Content type: <?=e($test['ctype'])?></div></div><?php endif;?>
    </div>

    <div class="cardx table-card mt-3"><div class="d-flex justify-content-between align-items-center px-3 pt-3"><h3 class="mb-0">Captured requests <small class="text-muted">(latest 25, kept 3 days)</small></h3>
        <form method="post" data-confirm="Delete all captured requests?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="clear"><button class="btn btn-sm btn-outline-secondary">Clear</button></form></div>
        <p class="text-muted px-3 mb-0 small">These contain customers' phone numbers — they are visible to admins only and deleted after 3 days.</p>
        <?php if(!$logs):?><p class="px-3 pb-3 pt-2 mb-0 text-muted">Nothing yet. Enable the endpoint, create the Mobius menu with the URL above, and dial the test short code.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Time</th><th>Menu</th><th>From</th><th>Request</th><th>We replied</th><th class="text-end">ms</th><th></th></tr></thead><tbody>
        <?php foreach($logs as $lg): $q=json_decode((string)$lg['query_text'],true)?:[]; $fl=ussd_proxy_flatten('',(string)$lg['body_text'],$q,[]);?>
        <tr><td class="text-nowrap"><?=e($lg['created_at'])?></td><td><?=e($lg['mode'])?></td><td class="text-nowrap"><?=e($lg['remote_ip'])?></td>
            <td class="cell-full"><details><summary><?=e($lg['method'])?> <?php foreach(array_slice($fl,0,5,true) as $k=>$v):?><code><?=e($k)?></code>=<?=e(mb_strimwidth($v,0,24,'…'))?> <?php endforeach; if(!$fl):?>(empty)<?php endif;?></summary>
                <div class="small mt-1"><b>Query</b> <code><?=e($lg['query_text'])?></code></div><div class="small"><b>Headers</b> <code><?=e($lg['headers_text'])?></code></div><div class="small"><b>Body</b></div><pre class="mb-0"><?=e($lg['body_text'])?></pre></details></td>
            <td class="cell-full"><?=e(mb_strimwidth((string)$lg['response_text'],0,70,'…'))?><div class="small text-muted"><?=e($lg['note'])?></div></td><td class="text-end"><?=e($lg['ms'])?></td>
            <td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="replay"><input type="hidden" name="id" value="<?=e($lg['id'])?>"><button class="btn btn-sm btn-outline-primary" title="Run this request through the live logic without sending anything">Replay</button></form></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    </div>
    </div>
    <script nonce="<?=e(csp_nonce())?>">
    // remember the open tab (saving a form reloads the page), and let a link elsewhere on the page open a tab
    window.addEventListener('load', function () {
        var key = 'vasProxyTab', btn = function (t) { return document.querySelector('[data-bs-target="#tab-' + t + '"]'); }, saved = null;
        try { saved = (location.hash || '').replace('#tab-', '') || sessionStorage.getItem(key); } catch (e) {}
        if (saved && btn(saved)) btn(saved).click();
        document.querySelectorAll('#proxyTabs [data-bs-toggle="tab"]').forEach(function (b) { b.addEventListener('shown.bs.tab', function (ev) { try { sessionStorage.setItem(key, ev.target.dataset.bsTarget.replace('#tab-', '')); } catch (e) {} }); });
        document.querySelectorAll('[data-goto]').forEach(function (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); var b = btn(a.dataset.goto); if (b) { b.click(); window.scrollTo(0, 0); } }); });
    });
    </script>
    <?php layout_end(); exit;

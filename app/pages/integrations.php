<?php
declare(strict_types=1);
// Page: ?page=integrations — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if (($_POST['action']??'')==='test') {
            require_perm('edit_records'); // a live probe of an external system — not for read-only users
            $result = test_integration((int)$_POST['id']);
            flash($result['ok']?'success':'danger', ($result['ok']?'Check succeeded: ':'Check failed: ').$result['message'].' ('.$result['latency_ms'].'ms)');
        } else {
            require_perm($id ? 'edit_records' : 'create_records');
            save_integration($_POST['data']??[], $id);
            flash('success','Integration saved.');
        }
        redirect('?page=integrations');
    }
    $edit=null; if(isset($_GET['id'])){ $edit=get_integration((int)$_GET['id']); }
    $typeFilter = in_array($_GET['type']??'', INTEGRATION_TYPES, true) ? $_GET['type'] : null;
    $connections = list_integrations($typeFilter);
    layout_start('Integrations');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-plug-circle-check me-2"></i>Integrations</h3>
        <p class="text-muted mb-0">One registry for every external system this platform talks to — SMSC, USSD gateway, IVR platform, monitoring endpoints, or anything you add later. "Test" performs a real live check right now: an HTTP request to the configured URL, or a raw TCP connect for socket-based systems (e.g. SMPP) — it does not simulate a result.</p>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-7 order-<?=$edit?0:2?>"><div class="cardx"><h3><?= $edit?'Edit Integration':'Add Integration' ?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <div class="row g-2">
                    <div class="col-6"><label>Service Type</label><select class="form-select mb-2" name="data[service_type]"><?php foreach(INTEGRATION_TYPES as $v):?><option value="<?=e($v)?>" <?=($edit['service_type']??'other')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label>Protocol</label><select class="form-select mb-2" name="data[protocol]"><?php foreach(['http'=>'HTTP (URL health check)','tcp'=>'TCP (raw socket connect)'] as $v=>$label):?><option value="<?=e($v)?>" <?=($edit['protocol']??'http')===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
                </div>
                <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>" placeholder="Primary SMSC / USSD Gateway / IVR Platform">
                <div class="row g-2"><div class="col-8"><label>Host</label><input class="form-control mb-2" name="data[host]" value="<?=e($edit['host']??'')?>" placeholder="smsc.example.com"></div><div class="col-4"><label>Port</label><input class="form-control mb-2" name="data[port]" value="<?=e($edit['port']??'')?>"></div></div>
                <label>Base URL <small class="text-muted">(HTTP only — overrides host if set)</small></label><input class="form-control mb-2" name="data[base_url]" value="<?=e($edit['base_url']??'')?>" placeholder="https://ussd-gateway.example.com">
                <label>Health Check Path <small class="text-muted">(HTTP only)</small></label><input class="form-control mb-2" name="data[health_check_path]" value="<?=e($edit['health_check_path']??'')?>" placeholder="/health">
                <div class="row g-2">
                    <div class="col-6"><label>Auth Type</label><select class="form-select mb-2" name="data[auth_type]"><?php foreach(['none','bearer','api_key','basic'] as $v):?><option value="<?=e($v)?>" <?=($edit['auth_type']??'none')===$v?'selected':''?>><?=e(ucfirst(str_replace('_',' ',$v)))?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['active','inactive','testing'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'testing')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select></div>
                </div>
                <label>Credential <small class="text-muted">(token/API key/user:pass — encrypted at rest<?=$edit?'; leave blank to keep the existing one':''?>)</small></label><input type="password" class="form-control mb-2" name="data[auth_credential]" autocomplete="new-password">
                <label>Notes</label><textarea class="form-control mb-2" name="data[notes]" rows="2"><?=e($edit['notes']??'')?></textarea>
                <button class="btn btn-primary w-100">Save Integration</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=integrations">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-12 order-1"><div class="cardx">
            <div class="d-flex justify-content-between align-items-center"><h3>Connections</h3><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary <?=!$typeFilter?'active':''?>" href="?page=integrations">All</a><?php foreach(INTEGRATION_TYPES as $t):?><a class="btn btn-outline-primary <?=$typeFilter===$t?'active':''?>" href="?page=integrations&type=<?=e($t)?>"><?=e(ucfirst($t))?></a><?php endforeach;?></div></div>
            <?php if(!$connections):?><p class="text-muted mb-0 mt-2">No integrations registered<?=$typeFilter?" for $typeFilter":''?> yet.</p><?php else:?>
            <div class="table-scroll mt-2"><table class="table table-hover table-sm"><thead><tr><th>Type</th><th>Name</th><th>Target</th><th>Status</th><th>Last Check</th><th>Last 10 / 24h Uptime</th><th></th></tr></thead><tbody>
            <?php foreach($connections as $c):
                $target = $c['protocol']==='tcp' ? e($c['host']).':'.e($c['port']) : e($c['base_url'] ?: $c['host']);
                $lastCheck = 'never';
                if ($c['last_check_at']) {
                    $badge = $c['last_check_ok'] ? '<span class="badge bg-success">UP</span>' : '<span class="badge bg-danger">DOWN</span>';
                    $lastCheck = $badge.' '.e($c['last_check_latency_ms']).'ms<br><small class="text-muted">'.e($c['last_check_at']).'</small>';
                }
                $history = integration_check_history((int)$c['id'], 10);
                $sparkline = implode('', array_map(fn($h)=>$h['ok']?'●':'○', array_reverse($history)));
                $uptime = integration_uptime_pct((int)$c['id'], 24);
            ?><tr>
                <td><span class="badge bg-dark"><?=e($c['service_type'])?></span></td>
                <td><strong><?=e($c['name'])?></strong></td>
                <td><small><?=$target?></small></td>
                <td><span class="badge <?=$c['status']==='active'?'bg-success':($c['status']==='testing'?'bg-warning text-dark':'bg-secondary')?>"><?=e($c['status'])?></span></td>
                <td><?=$lastCheck?></td>
                <td><span title="Oldest to newest, left to right" style="letter-spacing:2px;color:#22aa55;"><?=e($sparkline)?></span><br><small class="text-muted"><?=$uptime!==null?$uptime.'% up (24h)':'no data yet'?></small></td>
                <td><div class="d-flex gap-1">
                    <?php if(can('edit_records')):?><a class="btn btn-sm btn-warning" href="?page=integrations&id=<?=e($c['id'])?>">Edit</a><?php endif;?>
                    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=e($c['id'])?>"><?php if(can('edit_records')):?><button class="btn btn-sm btn-outline-dark">Test</button><?php endif;?></form>
                </div></td>
            </tr><?php endforeach;?>
            </tbody></table></div><?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;

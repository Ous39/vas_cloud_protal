<?php
declare(strict_types=1);
// Page: ?page=api_keys — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_api_keys');
    $newKey=null;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (($_POST['do']??'')==='create') { $newKey=create_api_key(trim($_POST['label']??'') ?: 'Unnamed'); flash('warning','New key created — copy it now, it will not be shown again.'); }
        elseif (($_POST['do']??'')==='revoke') { revoke_api_key((int)$_POST['id']); flash('info','Key revoked.'); }
    }
    $keys=list_api_keys();
    layout_start('Partner API Keys');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3>Create API Key</h3><p class="text-muted">Read-only access for partner integrations (e.g. the mobile app or USSD gateway) to <code>api.php</code>.</p>
            <form method="post" data-confirm="Create a new API key?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="create">
                <label>Label</label><input class="form-control mb-2" name="label" placeholder="e.g. GNM Mobile App">
                <button class="btn btn-primary w-100">Create Key</button>
            </form>
            <?php if($newKey):?><div class="alert alert-warning mt-3"><strong>Copy this key now</strong> — it will not be shown again:<br><code style="word-break:break-all;"><?=e($newKey)?></code></div><?php endif;?>
        </div></div>
        <div class="col-lg-8"><div class="cardx"><h3>Keys</h3><table class="table table-sm"><thead><tr><th>Label</th><th>Status</th><th>Created by</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>
        <?php foreach($keys as $k):?><tr>
            <td><?=e($k['label'])?></td><td><span class="badge <?=$k['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($k['status'])?></span></td>
            <td><?=e($k['created_by'])?></td><td><?=e($k['created_at'])?></td><td><?=e($k['last_used_at']??'never')?></td>
            <td><?php if($k['status']==='active'):?><form method="post" data-confirm="Revoke this API key? Integrations using it will stop working immediately."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?=e($k['id'])?>"><button class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif;?></td>
        </tr><?php endforeach;?>
        </tbody></table></div></div>
    </div>
    <?php layout_end(); exit;

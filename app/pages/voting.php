<?php
declare(strict_types=1);
// Page: ?page=voting — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'voting_contestant','keys'=>['id'=>$id],'data'=>$_POST['data']??[],'return_to'=>'?page=voting']);
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'voting_contestant',['id'=>(int)$_GET['id']]); }
    $tally = voting_tally($schema);
    $contestants = table_exists($schema,'voting_contestant') ? pdo($schema)->query('SELECT * FROM voting_contestant ORDER BY number')->fetchAll() : [];
    layout_start('Voting Service');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Contestant':'Add Contestant' ?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>">
                <label>Number (vote code)</label><input class="form-control mb-2" name="data[number]" value="<?=e($edit['number']??'')?>">
                <label>Status</label><input class="form-control mb-2" name="data[status]" value="<?=e($edit['status']??'active')?>">
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=voting">Cancel Edit</a><?php endif;?>
            </form>
        </div>
        <div class="cardx mt-3"><h3>Contestants</h3><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Name</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($contestants as $c):?><tr><td><?=e($c['number'])?></td><td><?=e($c['name'])?></td><td><?=e($c['status'])?></td><td><a class="btn btn-sm btn-warning" href="?page=voting&id=<?=e($c['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div>
        </div>
        <div class="col-lg-8"><div class="cardx"><h3>Live Tally</h3><?php if(!$tally):?><p class="text-muted mb-0">No votes recorded.</p><?php else:?><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Vote Code</th><th>Contestant</th><th>Votes</th></tr></thead><tbody><?php foreach($tally as $t):?><tr><td><?=e($t['content'])?></td><td><?=e($t['contestant']??'Unknown')?></td><td><strong><?=number_format($t['votes'])?></strong></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;

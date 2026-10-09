<?php
declare(strict_types=1);
// Page: ?page=audit — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_audit');
    $fu=trim((string)($_GET['user']??'')); $fa=trim((string)($_GET['action']??'')); $fq=trim((string)($_GET['q']??''));
    $fd=trim((string)($_GET['date_from']??'')); $ft=trim((string)($_GET['date_to']??''));
    $where=['1=1']; $params=[];
    if($fu!==''){ $where[]='username=?'; $params[]=$fu; }
    if($fa!==''){ $where[]='action=?'; $params[]=$fa; }
    if($fq!==''){ $where[]='(details LIKE ? OR target_table LIKE ? OR target_key LIKE ? OR ip_address LIKE ?)'; array_push($params,"%$fq%","%$fq%","%$fq%","%$fq%"); }
    if($fd!=='' && strtotime($fd)!==false){ $where[]='created_at >= ?'; $params[]=date('Y-m-d',strtotime($fd)).' 00:00:00'; }
    if($ft!=='' && strtotime($ft)!==false){ $where[]='created_at < ?'; $params[]=date('Y-m-d',strtotime($ft.' +1 day')).' 00:00:00'; }
    $w=implode(' AND ',$where); $pdb=portal_pdo();
    if(($_GET['format']??'')==='csv'){
        audit('audit_export',null,'portal_audit_trail',null,json_encode(['user'=>$fu,'action'=>$fa,'q'=>$fq,'from'=>$fd,'to'=>$ft]));
        $st=$pdb->prepare("SELECT id,created_at,username,action,schema_name,target_table,target_key,ip_address,details FROM portal_audit_trail WHERE $w ORDER BY id DESC LIMIT 50000"); $st->execute($params);
        header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="audit_trail_'.date('Ymd_His').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['id','time','user','action','schema','table','key','ip','details']);
        foreach($st->fetchAll() as $row) fputcsv($out,csv_safe_row($row)); exit;
    }
    $perPage=50; $pageNo=max(1,(int)($_GET['p']??1));
    $c=$pdb->prepare("SELECT COUNT(*) FROM portal_audit_trail WHERE $w"); $c->execute($params); $total=(int)$c->fetchColumn();
    $st=$pdb->prepare("SELECT * FROM portal_audit_trail WHERE $w ORDER BY id DESC LIMIT $perPage OFFSET ".(($pageNo-1)*$perPage)); $st->execute($params); $rows=$st->fetchAll();
    $users=$pdb->query('SELECT DISTINCT username FROM portal_audit_trail WHERE username IS NOT NULL ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
    $actions=$pdb->query('SELECT DISTINCT action FROM portal_audit_trail ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $qs=$_GET; unset($qs['p'],$qs['page']); $pages=max(1,(int)ceil($total/$perPage));
    layout_start('Audit Trail');
    ?>
    <div class="cardx"><h3>Audit Trail</h3>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="audit">
            <div class="col-md-2"><label>User</label><select class="form-select" name="user"><option value="">Anyone</option><?php foreach($users as $u):?><option <?=$fu===$u?'selected':''?>><?=e($u)?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label>Action</label><select class="form-select" name="action"><option value="">Any</option><?php foreach($actions as $ac):?><option <?=$fa===$ac?'selected':''?>><?=e($ac)?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label>From</label><input type="date" class="form-control" name="date_from" value="<?=e($fd)?>"></div>
            <div class="col-md-2"><label>To</label><input type="date" class="form-control" name="date_to" value="<?=e($ft)?>"></div>
            <div class="col-md-4"><label>Contains <small class="text-muted">(details, table, key, IP)</small></label><input class="form-control" name="q" value="<?=e($fq)?>"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="fa fa-search"></i> Filter</button><a class="btn btn-outline-secondary" href="?page=audit">Reset</a>
                <a class="btn btn-outline-primary ms-auto" href="?<?=e(http_build_query(array_merge($_GET,['page'=>'audit','format'=>'csv'])))?>"><i class="fa fa-file-csv"></i> Export CSV</a></div>
        </form>
    </div>
    <div class="cardx table-card mt-3"><p class="text-muted px-3 pt-3 mb-0"><?=number_format($total)?> entries</p>
        <div class="table-scroll"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Schema</th><th>Table</th><th>IP</th><th>Details</th></tr></thead><tbody>
        <?php foreach($rows as $r):?><tr><td class="text-nowrap"><?=e($r['created_at'])?></td><td><?=e($r['username'])?></td><td><span class="badge bg-secondary"><?=e($r['action'])?></span></td><td><?=e($r['schema_name'])?></td><td><?=e($r['target_table'])?></td><td class="text-nowrap"><?=e($r['ip_address'])?></td><td title="<?=e((string)$r['details'])?>"><?=e(mb_strimwidth((string)$r['details'],0,140,'...'))?></td></tr><?php endforeach;?>
        <?php if(!$rows):?><tr><td colspan="7" class="text-muted">Nothing matches these filters.</td></tr><?php endif;?></tbody></table></div>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div>
            <?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($qs,['page'=>'audit','p'=>$pageNo-1])))?>">Prev</a><?php endif;?>
            <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($qs,['page'=>'audit','p'=>$pageNo+1])))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;

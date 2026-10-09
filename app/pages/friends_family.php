<?php
declare(strict_types=1);
// Page: ?page=friends_family — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'unique_number_subscription')) throw new RuntimeException('unique_number_subscription does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle') {
        require_perm('edit_records');
        $id=(int)($_POST['id']??0);
        $row=fetch_record($schema,'unique_number_subscription',['id'=>$id]) ?: throw new RuntimeException('Record not found');
        $newVal = ($row['active']??'')==='1' ? '0' : '1';
        $token=make_confirmation('update',['schema'=>$schema,'table'=>'unique_number_subscription','keys'=>['id'=>$id],'data'=>['active'=>$newVal],'return_to'=>'?page=friends_family']);
        redirect('?page=confirm&token='.$token);
    }
    $filters=[];
    foreach(['msisdn'=>'msisdn','friend_number'=>'friend_number','transaction_id'=>'transaction_id'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=list_records($schema,'unique_number_subscription',$filters,$pageNo,25);
    layout_start('Friends & Family');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-user-group me-2"></i>Friends & Family Numbers</h3>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="friends_family">
            <div class="col-md-3"><input class="form-control" name="msisdn" placeholder="MSISDN" value="<?=e($_GET['msisdn']??'')?>"></div>
            <div class="col-md-3"><input class="form-control" name="friend_number" placeholder="Friend number" value="<?=e($_GET['friend_number']??'')?>"></div>
            <div class="col-md-3"><input class="form-control" name="transaction_id" placeholder="Transaction ID" value="<?=e($_GET['transaction_id']??'')?>"></div>
            <div class="col-md-3"><button class="btn btn-outline-primary w-100">Search</button></div>
        </form>
    </div>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching records</p>
        <div class="table-scroll"><table class="table table-hover table-sm"><thead><tr><th>Date</th><th>MSISDN</th><th>Friend Number</th><th>Offer Code</th><th>Expiry</th><th>Active</th><?php if(can('edit_records')):?><th></th><?php endif;?></tr></thead><tbody>
        <?php foreach($data['rows'] as $r):?><tr>
            <td><?=e($r['date'])?></td><td><?=e($r['msisdn'])?></td><td><?=e($r['friend_number'])?></td><td><?=e($r['offer_code'])?></td><td><?=e($r['expiry'])?></td>
            <td><span class="badge <?=$r['active']==='1'?'bg-success':'bg-secondary'?>"><?=$r['active']==='1'?'Active':'Inactive'?></span></td>
            <?php if(can('edit_records')):?><td><form method="post" data-confirm="Toggle this number's active status?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark">Toggle</button></form></td><?php endif;?>
        </tr><?php endforeach;?>
        </tbody></table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;

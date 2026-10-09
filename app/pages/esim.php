<?php
declare(strict_types=1);
// Page: ?page=esim — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'esim_profile')) throw new RuntimeException('esim_profile does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'esim_profile','keys'=>['id'=>$id],'data'=>$_POST['data']??[],'return_to'=>'?page=esim']);
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'esim_profile',['id'=>(int)$_GET['id']]); }
    $filters=[];
    foreach(['msisdn'=>'msisdn','iccid'=>'iccid','imsi'=>'imsi'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'equals','val'=>trim((string)$_GET[$qp])];
    if (trim((string)($_GET['status']??''))!=='') $filters[]=['col'=>'status','op'=>'contains','val'=>$_GET['status']];
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=list_records($schema,'esim_profile',$filters,$pageNo,25); $data['rows']=array_map('redact_row',$data['rows']);
    layout_start('eSIM Profiles');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Profile':'Add Profile' ?></h3><p class="text-muted">Saving requires confirmation.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Vendor</label><input class="form-control mb-2" name="data[vendor_name]" value="<?=e($edit['vendor_name']??'')?>">
                <label>ICCID</label><input class="form-control mb-2" name="data[iccid]" value="<?=e($edit['iccid']??'')?>">
                <label>IMSI</label><input class="form-control mb-2" name="data[imsi]" value="<?=e($edit['imsi']??'')?>">
                <label>MSISDN</label><input class="form-control mb-2" name="data[msisdn]" value="<?=e($edit['msisdn']??'')?>">
                <label>QR Code Value</label><input class="form-control mb-2" name="data[qr_code_value]" value="<?=e($edit['qr_code_value']??'')?>">
                <label>Profile Name</label><input class="form-control mb-2" name="data[profile_names]" value="<?=e($edit['profile_names']??'')?>">
                <label>SM-DP+ Address</label><input class="form-control mb-2" name="data[smdp_address]" value="<?=e($edit['smdp_address']??'')?>">
                <label>Matching ID</label><input class="form-control mb-2" name="data[matching_id]" value="<?=e($edit['matching_id']??'')?>">
                <div class="row g-2">
                    <div class="col-6"><label>Installation Status</label><input class="form-control mb-2" name="data[installation_status]" value="<?=e($edit['installation_status']??'')?>"></div>
                    <div class="col-6"><label>Status</label><input class="form-control mb-2" name="data[status]" value="<?=e($edit['status']??'')?>"></div>
                </div>
                <label>Availability</label><input class="form-control mb-2" name="data[Availability]" value="<?=e($edit['Availability']??'')?>">
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=esim">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-8"><div class="cardx">
            <div class="d-flex justify-content-between align-items-center"><h3>eSIM Profiles</h3><span class="badge bg-primary"><?=number_format($data['total'])?> profiles</span></div>
            <form method="get" class="row g-2 mt-1"><input type="hidden" name="page" value="esim">
                <div class="col-md-3"><input class="form-control" name="msisdn" placeholder="MSISDN" value="<?=e($_GET['msisdn']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="iccid" placeholder="ICCID" value="<?=e($_GET['iccid']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="imsi" placeholder="IMSI" value="<?=e($_GET['imsi']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="status" placeholder="Status contains" value="<?=e($_GET['status']??'')?>"></div>
                <div class="col-12"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-outline-secondary" href="?page=esim">Reset</a></div>
            </form>
            <div class="table-scroll mt-3"><table class="table table-hover table-sm"><thead><tr><th>MSISDN</th><th>ICCID</th><th>Vendor</th><th>Installation</th><th>Status</th><th>Last Connection</th><th></th></tr></thead><tbody>
            <?php foreach($data['rows'] as $r):?><tr>
                <td><?=e($r['msisdn'])?></td><td><?=e($r['iccid'])?></td><td><?=e($r['vendor_name'])?></td>
                <td><?=e($r['installation_status'])?></td><td><?=e($r['status'])?></td><td><?=e($r['last_connection'])?></td>
                <td><a class="btn btn-sm btn-warning" href="?page=esim&id=<?=e($r['id'])?>">Edit</a></td>
            </tr><?php endforeach;?>
            </tbody></table></div>
            <?php $pages=max(1,(int)ceil($data['total']/25));?>
            <div class="d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
        </div></div>
    </div>
    <?php layout_end(); exit;

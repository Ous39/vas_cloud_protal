<?php
declare(strict_types=1);
// Page: ?page=offers — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'vas_offers')) throw new RuntimeException('vas_offers does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        if (($_POST['action']??'')==='toggle') {
            require_perm('edit_records');
            $row=fetch_record($schema,'vas_offers',['id'=>$id]) ?: throw new RuntimeException('Offer not found');
            $newStatus = offer_is_active($row['status']??'') ? '0' : '1';
            $token=make_confirmation('update',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>['status'=>$newStatus],'return_to'=>'?page=offers']);
        } else {
            require_perm($id ? 'edit_records' : 'create_records');
            $data=$_POST['data']??[];
            if (!$id) {
                $code=trim((string)($data['offer_code']??''));
                if ($code==='') throw new RuntimeException('Offer Code is required for a new offer.');
                $st=pdo($schema)->prepare('SELECT COUNT(*) FROM vas_offers WHERE offer_code=?'); $st->execute([$code]);
                if ((int)$st->fetchColumn()>0) throw new RuntimeException('An offer with code '.$code.' already exists — a duplicate needs its own new Offer Code.');
            }
            if (isset($data['free_data'])) $data['free_data']=normalize_free_data_to_mb((string)$data['free_data']);
            $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>$data,'return_to'=>'?page=offers']);
        }
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; $dupOf=null;
    if(isset($_GET['id'])){ $edit=fetch_record($schema,'vas_offers',['id'=>(int)$_GET['id']]); }
    elseif(isset($_GET['duplicate'])){
        $dupOf=fetch_record($schema,'vas_offers',['id'=>(int)$_GET['duplicate']]);
        if($dupOf){ $edit=$dupOf; unset($edit['id']); foreach(['offer_code','offer_code_for_other','pcrf_offer_code'] as $k) $edit[$k]=''; $edit['name']=trim((string)$dupOf['name']).' (copy)'; $edit['status']='0'; }
    }
    $filters=[];
    foreach(['name'=>'name','offer_code'=>'offer_code','vendor'=>'vendor','category'=>'category','sub_category'=>'sub_category'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
    if (trim((string)($_GET['status']??''))!=='') $filters[]=['col'=>'status','op'=>'equals','val'=>$_GET['status']];
    $pageNo=max(1,(int)($_GET['p']??1));
    $perPage=resolve_page_size($_GET);
    $data=list_records($schema,'vas_offers',$filters,$pageNo,$perPage);
    $vendors=distinct_column_values($schema,'vas_offers','vendor');
    $categories=distinct_column_values($schema,'vas_offers','category');
    $subCategories=distinct_column_values($schema,'vas_offers','sub_category');
    $validities=distinct_column_values($schema,'vas_offers','validity_amount');
    $speedLimits=distinct_column_values($schema,'vas_offers','speed_limit');
    layout_start('Offer Management');
    ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="cardx">
                <h3><?= $dupOf?'Duplicate Offer':($edit?'Edit Offer':'Add Offer') ?></h3>
                <?php if($dupOf):?><div class="alert alert-info py-2 small">Copied from <b><?=e($dupOf['name'])?></b> (<?=e($dupOf['offer_code'])?>). Change what you need — the offer codes were left empty because each offer needs its own — then save. It starts as <b>Inactive</b>.</div><?php else:?><p class="text-muted">Saving requires confirmation. Toggling status also requires confirmation.</p><?php endif;?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                    <label>Vendor</label><input class="form-control mb-2" name="data[vendor]" list="dl_vendor" value="<?=e($edit['vendor']??'')?>"><datalist id="dl_vendor"><?php foreach($vendors as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist>
                    <label>Offer Code</label><input class="form-control mb-2" name="data[offer_code]" value="<?=e($edit['offer_code']??'')?>">
                    <label>Other Offer Code (Buy For Other Offer Code)</label><input class="form-control mb-2" name="data[offer_code_for_other]" value="<?=e($edit['offer_code_for_other']??'')?>">
                    <label>PCRF Offer Code</label><input class="form-control mb-2" name="data[pcrf_offer_code]" value="<?=e($edit['pcrf_offer_code']??'')?>">
                    <div class="row g-2">
                        <div class="col-6"><label>Category</label><input class="form-control mb-2" name="data[category]" list="dl_category" value="<?=e($edit['category']??'')?>"><datalist id="dl_category"><?php foreach($categories as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                        <div class="col-6"><label>Sub-category</label><input class="form-control mb-2" name="data[sub_category]" list="dl_sub_category" value="<?=e($edit['sub_category']??'')?>"><datalist id="dl_sub_category"><?php foreach($subCategories as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                    </div>
                    <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>">
                    <label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="2"><?=e($edit['description']??'')?></textarea>
                    <div class="row g-2">
                        <div class="col-4"><label>Price</label><input class="form-control mb-2" name="data[one_time_price]" value="<?=e($edit['one_time_price']??'')?>"></div>
                        <div class="col-4"><label>Rental</label><input class="form-control mb-2" name="data[rental_price]" value="<?=e($edit['rental_price']??'')?>"></div>
                        <div class="col-4"><label>Discount</label><input class="form-control mb-2" name="data[discount]" value="<?=e($edit['discount']??'')?>"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-4"><label>Free Data (MB)</label><input class="form-control mb-2" name="data[free_data]" placeholder="e.g. 500 or 10GB" value="<?=e($edit['free_data']??'')?>"></div>
                        <div class="col-4"><label>Validity (days)</label><input class="form-control mb-2" name="data[validity_amount]" list="dl_validity" value="<?=e($edit['validity_amount']??'')?>"><datalist id="dl_validity"><?php foreach($validities as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                        <div class="col-4"><label>Speed limit</label><input class="form-control mb-2" name="data[speed_limit]" list="dl_speed_limit" value="<?=e($edit['speed_limit']??'NA')?>"><datalist id="dl_speed_limit"><?php foreach($speedLimits as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                    </div>
                    <label>Status</label>
                    <select class="form-select mb-2" name="data[status]"><?php $editActive = offer_is_active($edit['status']??''); foreach(['1'=>'Active','0'=>'Inactive'] as $v=>$lbl):?><option value="<?=$v?>" <?=($editActive?'1':'0')===(string)$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select>
                    <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=offers"><?=$dupOf?'Cancel':'Cancel Edit'?></a><?php if(!$dupOf && !empty($edit['id'])):?><a class="btn btn-link w-100" href="?page=offer_history&id=<?=e($edit['id'])?>"><i class="fa-solid fa-clock-rotate-left me-1"></i>View history of this offer</a><?php endif; endif;?>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="cardx">
                <div class="d-flex justify-content-between align-items-center"><h3>Offer Catalog</h3><span class="badge bg-primary"><?=number_format($data['total'])?> offers</span></div>
                <form method="get" class="row g-2 mt-1">
                    <input type="hidden" name="page" value="offers">
                    <div class="col-md-3"><input class="form-control" name="name" placeholder="Name" value="<?=e($_GET['name']??'')?>"></div>
                    <div class="col-md-3"><input class="form-control" name="offer_code" placeholder="Offer code" value="<?=e($_GET['offer_code']??'')?>"></div>
                    <div class="col-md-2"><input class="form-control" name="vendor" placeholder="Vendor" value="<?=e($_GET['vendor']??'')?>"></div>
                    <div class="col-md-2"><input class="form-control" name="category" placeholder="Category" value="<?=e($_GET['category']??'')?>"></div>
                    <div class="col-md-2"><input class="form-control" name="sub_category" placeholder="Sub-category" value="<?=e($_GET['sub_category']??'')?>"></div>
                    <div class="col-md-2"><select class="form-select" name="status"><option value="">Any status</option><?php foreach(['1'=>'Active','0'=>'Inactive'] as $v=>$lbl):?><option value="<?=$v?>" <?=(string)($_GET['status']??'')===(string)$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select></div>
                    <div class="col-md-2"><label class="form-label small text-muted mb-0">Per page</label><select class="form-select" name="per_page" data-autosubmit><?php foreach(PAGE_SIZE_OPTIONS as $ps):?><option value="<?=$ps?>" <?=$perPage===$ps?'selected':''?>><?=$ps?></option><?php endforeach;?></select></div>
                    <div class="col-12"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-outline-secondary" href="?page=offers">Reset</a></div>
                </form>
                <div class="table-scroll mt-3"><table class="table table-hover table-sm"><thead><tr><th>Name</th><th>Offer Code</th><th>Category</th><th>Price</th><th>Validity</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach($data['rows'] as $r):?><tr>
                    <td><strong><?=e($r['name'])?></strong><div class="text-muted small"><?=e(mb_strimwidth((string)$r['description'],0,60,'...'))?></div></td>
                    <td><?=e($r['offer_code'])?></td>
                    <td><?=e($r['category'])?><div class="text-muted small"><?=e($r['vendor'])?></div></td>
                    <td><?=e($r['one_time_price'])?></td>
                    <td><?=e($r['validity_amount'])?> d</td>
                    <td><span class="badge <?=offer_is_active($r['status'])?'bg-success':'bg-secondary'?>"><?=offer_is_active($r['status'])?'Active':'Inactive'?></span></td>
                    <td><div class="d-flex gap-1">
                        <?php if(can('edit_records')):?><a class="btn btn-sm btn-warning" href="?page=offers&id=<?=e($r['id'])?>" title="Edit offer" aria-label="Edit offer"><i class="fa-solid fa-pen"></i></a><?php endif;?>
                        <a class="btn btn-sm btn-outline-secondary" title="History — who changed this offer" aria-label="Offer history" href="?page=offer_history&id=<?=e($r['id'])?>"><i class="fa-solid fa-clock-rotate-left"></i></a>
                        <?php if(can('create_records')):?><a class="btn btn-sm btn-outline-primary" title="Duplicate — copy this offer, change what you need, save as a new one" aria-label="Duplicate offer" href="?page=offers&duplicate=<?=e($r['id'])?>"><i class="fa-regular fa-copy"></i></a><?php endif;?>
                        <?php if(can('edit_records')):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark" title="Activate / deactivate" aria-label="Activate or deactivate"><i class="fa-solid fa-power-off"></i></button></form><?php endif;?>
                    </div></td>
                </tr><?php endforeach;?>
                </tbody></table></div>
                <?php $pages=max(1,(int)ceil($data['total']/$perPage));?>
                <div class="d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
            </div>
        </div>
    </div>
    <?php layout_end(); exit;

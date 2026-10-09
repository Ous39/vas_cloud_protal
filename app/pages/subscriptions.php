<?php
declare(strict_types=1);
// Page: ?page=subscriptions — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'subscription')) throw new RuntimeException('subscription does not exist in '.$schema);
    $hasSearch = trim((string)($_GET['msisdn']??''))!=='' || trim((string)($_GET['transaction_id']??''))!=='';
    $data = ['rows'=>[], 'total'=>0]; $f = null; $pageNo=max(1,(int)($_GET['p']??1));
    if ($hasSearch) { $f=subscription_filters_from_request($_GET); $data=search_subscriptions($schema,$f,$pageNo,25); }
    $exportQs=array_merge($_GET,['page'=>'subscriptions_export']);
    layout_start('Subscriptions');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-user-check me-2"></i>Subscriptions</h3>
        <div class="alert alert-warning">This table has ~<?=number_format(approx_table_count($schema,'subscription'))?> rows. Search by exact MSISDN or Transaction ID, and add a date range where you can — an unrestricted browse would scan the whole table.</div>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="subscriptions">
            <div class="col-md-2"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($_GET['msisdn']??'')?>"></div>
            <div class="col-md-2"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($_GET['transaction_id']??'')?>"></div>
            <div class="col-md-2"><label>Type</label><input class="form-control" name="subscription_type" value="<?=e($_GET['subscription_type']??'')?>"></div>
            <div class="col-md-2"><label>Channel</label><input class="form-control" name="channel" value="<?=e($_GET['channel']??'')?>"></div>
            <div class="col-md-2"><label>Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($_GET['date_from']??'')?>"></div>
            <div class="col-md-2"><label>Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($_GET['date_to']??'')?>"></div>
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-primary flex-fill">Search</button><?php if($hasSearch):?><a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query($exportQs)?>">Export CSV</a><?php endif;?></div>
        </form>
    </div>
    <?php if ($hasSearch): ?>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching subscriptions</p>
        <div class="table-scroll"><table class="table table-hover table-sm align-middle">
            <thead><tr><th>Date</th><th>MSISDN</th><th>Receiver</th><th>Transaction ID</th><th>Type</th><th>Channel</th><th>Result</th><th>Data</th><th>SMS</th><th>Minutes</th></tr></thead>
            <tbody><?php foreach($data['rows'] as $r): $s=subscription_status($r); $badge=fn($v)=>['active'=>'bg-success','expired'=>'bg-secondary'][$v]??'bg-light text-dark'; ?><tr>
                <td><?=e($r['date'])?></td>
                <td><?=e($r['subscriber_msisdn'])?></td>
                <td><?=e($r['receiver_msisdn'])?></td>
                <td><?=e($r['transaction_id'])?></td>
                <td><?=e($r['subscription_type'])?></td>
                <td><?=e($r['channel'])?></td>
                <td><?=e(mb_strimwidth((string)$r['result_desc'],0,60,'...'))?></td>
                <td><?=e($r['data_volume'])?> <span class="badge <?=$badge($s['data'])?>"><?=e($s['data'])?></span></td>
                <td><?=e($r['sms_volume'])?> <span class="badge <?=$badge($s['sms'])?>"><?=e($s['sms'])?></span></td>
                <td><?=e($r['minutes_volume'])?> <span class="badge <?=$badge($s['minutes'])?>"><?=e($s['minutes'])?></span></td>
            </tr><?php endforeach;?></tbody>
        </table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php endif; layout_end(); exit;

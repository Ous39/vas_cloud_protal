<?php
declare(strict_types=1);
// Page: ?page=investigate — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports');
    $schema=current_schema();
    $sources=investigate_sources($schema); $source=investigate_source($_GET,$schema);
    if ($source==='subscription') {
        $f=subscription_log_filters_from_request($_GET);
        $pageNo=max(1,(int)($_GET['p']??1));
        $data=search_subscription_log($schema,$f,$pageNo,25);
        layout_start('Complaint Investigation');
        $qs=$_GET; unset($qs['p']);
        ?>
        <?=investigate_source_tabs($sources,$source,$_GET)?>
        <div class="cardx">
            <h3><i class="fa-solid fa-headset me-2"></i>Complaint / Subscription Investigation</h3>
            <p class="text-muted">Searches <code><?=e($schema)?>.subscription</code> — one row per subscription attempt with its result. It has no request/response text (use <b>audit_log</b> for that), but it keeps the outcome for dates audit_log may no longer cover. A date range is required (max <?=SUBSCRIPTION_LOG_MAX_RANGE_DAYS?> days) — this table is very large.</p>
            <form method="get" class="row g-2">
                <input type="hidden" name="page" value="investigate"><input type="hidden" name="source" value="subscription">
                <div class="col-md-2"><label>Date from</label><input type="date" name="date_from" class="form-control" value="<?=e($f['date_from'])?>" required></div>
                <div class="col-md-2"><label>Date to</label><input type="date" name="date_to" class="form-control" value="<?=e($f['date_to'])?>" required></div>
                <div class="col-md-2"><label>MSISDN <small class="text-muted">(subscriber or receiver)</small></label><input class="form-control" name="msisdn" value="<?=e($f['msisdn'])?>"></div>
                <div class="col-md-3"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($f['transaction_id'])?>"></div>
                <div class="col-md-1"><label>Channel</label><input class="form-control" name="channel" value="<?=e($f['channel'])?>"></div>
                <div class="col-md-2"><label>Type</label><input class="form-control" name="subscription_type" value="<?=e($f['subscription_type'])?>"></div>
                <div class="col-md-6"><label>Result contains</label><input class="form-control" name="result_desc" value="<?=e($f['result_desc'])?>"></div>
                <div class="col-md-6 d-flex align-items-end gap-2">
                    <button class="btn btn-primary flex-fill"><i class="fa fa-search"></i> Search</button>
                    <a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate_export','source'=>'subscription']))?>"><i class="fa fa-file-csv"></i> Export CSV</a>
                </div>
            </form>
        </div>
        <div class="cardx table-card mt-3">
            <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching records</p>
            <div class="table-scroll"><table class="table table-hover table-sm align-middle">
                <thead><tr><th>Details</th><th>Date</th><th>Transaction ID</th><th>Subscriber</th><th>Receiver</th><th>Type</th><th>Channel</th><th>Result</th></tr></thead>
                <tbody><?php foreach($data['rows'] as $r): $ok=is_subscription_success($r['result_desc']);?><tr>
                    <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view data-source="subscription" data-id="<?=e($r['id'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
                    <td class="text-nowrap"><?=e($r['date'])?></td>
                    <td class="cell-full" style="min-width:300px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($r['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($r['transaction_id'])?></span></td>
                    <td class="text-nowrap"><?=e($r['subscriber_msisdn'])?> <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Copy MSISDN" data-copy="<?=e($r['subscriber_msisdn'])?>"><i class="fa-regular fa-copy"></i></button></td>
                    <td class="text-nowrap"><?=e($r['receiver_msisdn'])?></td>
                    <td><?=e($r['subscription_type'])?></td>
                    <td><?=e($r['channel'])?></td>
                    <td title="<?=e($r['result_desc'])?>"><span class="badge <?=$ok?'bg-success':'bg-danger'?> me-1"><?=$ok?'Success':'Failed'?></span><?=e(mb_strimwidth((string)$r['result_desc'],0,70,'...'))?></td>
                </tr><?php endforeach;?></tbody>
            </table></div>
            <?php $pages=max(1,(int)ceil($data['total']/25));?>
            <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
        </div>
        <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
        <script src="txviewer.js?v=<?=e((string)(@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/txviewer.js') ?: time()))?>"></script>
        <?php layout_end(); exit;
    }
    $f=audit_log_filters_from_request($_GET);
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=search_audit_log($schema,$f,$pageNo,25);
    layout_start('Complaint Investigation');
    $qs=$_GET; unset($qs['p']);
    ?>
    <?=investigate_source_tabs($sources,$source,$_GET)?>
    <div class="cardx">
        <h3><i class="fa-solid fa-headset me-2"></i>Complaint / Transaction Investigation</h3>
        <p class="text-muted">Searches <code><?=e($schema)?>.<?=e(AUDIT_LOG_TABLE)?></code>. A date range is required (max <?=AUDIT_LOG_MAX_RANGE_DAYS?> days) — this table is very large. It keeps roughly the last <?=retention_months()?> months; for an older complaint use the <b>subscription</b> source.</p>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="investigate">
            <div class="col-md-2"><label>Date from</label><input type="date" name="date_from" class="form-control" value="<?=e($f['date_from'])?>" required></div>
            <div class="col-md-2"><label>Date to</label><input type="date" name="date_to" class="form-control" value="<?=e($f['date_to'])?>" required></div>
            <div class="col-md-2"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($f['msisdn'])?>"></div>
            <div class="col-md-2"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($f['transaction_id'])?>"></div>
            <div class="col-md-2"><label>Result status</label><input class="form-control" name="result_status" value="<?=e($f['result_status'])?>" placeholder="0 = success, or a failure code"></div>
            <div class="col-md-2"><label>Vendor</label><input class="form-control" name="vendor" value="<?=e($f['vendor'])?>"></div>
            <div class="col-md-3"><label>Channel</label><input class="form-control" name="channel" value="<?=e($f['channel'])?>"></div>
            <div class="col-md-5"><label>Result description contains</label><input class="form-control" name="result_desc" value="<?=e($f['result_desc'])?>"></div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button class="btn btn-primary flex-fill"><i class="fa fa-search"></i> Search</button>
                <a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate_export']))?>"><i class="fa fa-file-csv"></i> Export CSV</a>
            </div>
        </form>
    </div>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching transactions</p>
        <div class="table-scroll"><table class="table table-hover table-sm align-middle">
            <thead><tr><th>Payload</th><th>Date</th><th>Transaction ID</th><th>MSISDN</th><th>Vendor</th><th>Channel</th><th>Status</th><th>Result</th><th>Response (ms)</th></tr></thead>
            <tbody><?php foreach($data['rows'] as $r):?><tr>
                <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view data-id="<?=e($r['id'])?>" data-date="<?=e($r['create_date'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
                <td class="text-nowrap"><?=e($r['create_date'])?></td>
                <td class="cell-full" style="min-width:300px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($r['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($r['transaction_id'])?></span></td>
                <td class="text-nowrap"><?=e($r['msisdn'])?> <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Copy MSISDN" data-copy="<?=e($r['msisdn'])?>"><i class="fa-regular fa-copy"></i></button></td>
                <td><?=e($r['vendor_entity_name'])?></td>
                <td><?=e($r['channel'])?></td>
                <td><span class="badge <?=is_success_status($r['result_status'])?'bg-success':'bg-danger'?>"><?=e($r['result_status'])?></span></td>
                <td title="<?=e($r['result_description'])?>"><?=e(mb_strimwidth((string)$r['result_description'],0,80,'...'))?></td>
                <td><?=e($r['response_time'])?></td>
            </tr><?php endforeach;?></tbody>
        </table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
    <script src="txviewer.js?v=<?=e((string)(@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/txviewer.js') ?: time()))?>"></script>
    <?php layout_end(); exit;

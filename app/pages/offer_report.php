<?php
declare(strict_types=1);
// Page: ?page=offer_report — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $schema=current_schema();
    $promotions=list_promotions($schema);
    $channels=distinct_recent_channels($schema);
    $offers = table_exists($schema,'vas_offers') ? pdo($schema)->query("SELECT offer_code, name FROM vas_offers ORDER BY name")->fetchAll() : [];
    $promotionId=(int)($_GET['promotion_id']??0);
    $selectedCodes = $_GET['offer_codes'] ?? null;
    if ($selectedCodes === null && $promotionId) { $p=get_promotion($promotionId); $selectedCodes = $p['offer_codes'] ?? []; }
    $selectedCodes = $selectedCodes ?? [];
    $channel=trim((string)($_GET['channel']??''));
    $dateFrom=trim((string)($_GET['date_from']??date('Y-m-d',strtotime('-6 days')))); $dateTo=trim((string)($_GET['date_to']??date('Y-m-d')));
    $result=null;
    if (isset($_GET['generate']) || ($_GET['format']??'')==='csv') {
        $result = offer_performance_report($schema, $selectedCodes, $channel, $dateFrom, $dateTo);
        if (($_GET['format']??'')==='csv') {
            audit('offer_report_export',$schema,'vas_offers',null,"offers=".implode(',',$selectedCodes)." range=$dateFrom..$dateTo channel=$channel");
            $codesForFilename = $selectedCodes ? implode('_', array_map(fn($c)=>preg_replace('/[^A-Za-z0-9]/','',$c), $selectedCodes)) : 'all';
            if (strlen($codesForFilename) > 80) $codesForFilename = substr($codesForFilename, 0, 80).'_etc';
            header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="offer_performance_'.$codesForFilename.'_report.csv"');
            $out=fopen('php://output','w'); $first=true;
            foreach($result as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row)); }
            if($first) fputcsv($out,['(no rows returned)']);
            exit;
        }
    }
    layout_start('Offer Performance');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-bullhorn me-2"></i>Offer Performance</h3>
        <p class="text-muted">Success/failure breakdown by offer, including "Buy for Other" purchases, over a bounded date range (capped at <?=PROMOTION_REPORT_MAX_RANGE_DAYS?> days; one or two days is fastest). Leave Offers empty to report on every offer.</p>
        <?php if($promotions):?>
        <form method="get" class="row g-2 mb-2"><input type="hidden" name="page" value="offer_report">
            <div class="col-md-4"><label class="small text-muted mb-0">Quick-load from a Promotion</label><select class="form-select" name="promotion_id" data-autosubmit><option value="">— none —</option><?php foreach($promotions as $p):?><option value="<?=e($p['id'])?>" <?=$promotionId===(int)$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach;?></select></div>
        </form>
        <?php endif;?>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="offer_report">
            <div class="col-md-4"><label class="small text-muted mb-0">Offers <small>(empty = all)</small></label><input class="form-control form-control-sm mb-1" type="text" placeholder="Search by code or name…" data-filter-select="offer_report_codes"><select class="form-select" id="offer_report_codes" name="offer_codes[]" multiple size="6"><?php foreach($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=in_array($o['offer_code'],$selectedCodes,true)?'selected':''?>><?=e($o['offer_code'])?> — <?=e($o['name'])?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Channel</label><input class="form-control" name="channel" list="dl_channel" value="<?=e($channel)?>" placeholder="Any"><datalist id="dl_channel"><?php foreach($channels as $c):?><option value="<?=e($c)?>"><?php endforeach;?></datalist></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($dateFrom)?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($dateTo)?>"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" name="generate" value="1">Generate</button></div>
        </form>
    </div>
    <?php if($result!==null):?>
    <div class="cardx mt-3">
        <div class="d-flex justify-content-between align-items-center"><h3>Results <small class="text-muted"><?=number_format(count($result))?> rows</small></h3><a class="btn btn-outline-primary btn-sm" href="?<?=http_build_query(array_merge($_GET,['format'=>'csv']))?>">Download CSV</a></div>
        <?php if(!$result):?><p class="text-muted mb-0">No matching transactions in this range.</p><?php else:?>
        <div class="table-scroll mt-2"><table class="table table-hover table-sm"><thead><tr><th>Date</th><th>Channel</th><th>Offer</th><th>Offer Code</th><th>Txn Offer Code</th><th>Purchase Type</th><th>Result</th><th>Reason</th><th>Total</th><th>Success</th><th>Failed</th><th>Distinct Users</th></tr></thead><tbody>
        <?php foreach($result as $r):?><tr>
            <td><?=e($r['ReportDate'])?></td><td><?=e($r['Channel'])?></td><td><?=e($r['OfferName'])?></td><td><?=e($r['OfferCode'])?></td><td><?=e($r['TransactionOfferCode'])?></td><td><?=e($r['PurchaseType'])?></td>
            <td><span class="badge <?=$r['ResultStatus']==='Successful'?'bg-success':'bg-danger'?>"><?=e($r['ResultStatus'])?></span></td><td><?=e($r['FailureReason'])?></td>
            <td><?=number_format((int)$r['TotalAttempts'])?></td><td><?=number_format((int)$r['SuccessfulAttempts'])?></td><td><?=number_format((int)$r['UnsuccessfulAttempts'])?></td><td><?=number_format((int)$r['TotalDistinctUsers'])?></td>
        </tr><?php endforeach;?>
        </tbody></table></div>
        <?php endif;?>
    </div>
    <?php endif;?>
    <?php layout_end(); exit;

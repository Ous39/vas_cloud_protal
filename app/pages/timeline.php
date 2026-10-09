<?php
declare(strict_types=1);
// Page: ?page=timeline — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $schema=current_schema();
    $msisdn=trim((string)($_GET['msisdn']??'')); $to=trim((string)($_GET['date_to']??date('Y-m-d'))); $from=trim((string)($_GET['date_from']??date('Y-m-d',strtotime('-6 days'))));
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='add_note') {
        require_perm('create_records');
        add_complaint_note($schema,(string)($_POST['msisdn']??''),(string)($_POST['note']??''),(string)($_POST['transaction_id']??''));
        flash('success','Note saved.'); redirect('?page=timeline&'.http_build_query(['msisdn'=>$_POST['msisdn']??'','date_from'=>$_POST['date_from']??'','date_to'=>$_POST['date_to']??'']));
    }
    $tl=null; $notes=[];
    if($msisdn!=='') {
        $tl=customer_timeline($schema,$msisdn,$from,$to); $notes=complaint_notes_for($schema,$msisdn);
        if(($_GET['format']??'')==='csv'){
            audit('timeline_export',$schema,null,$tl['msisdn'],"$from..$to");
            header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="timeline_'.$tl['msisdn'].'_'.$from.'_to_'.$to.'.csv"');
            $out=fopen('php://output','w'); fputcsv($out,['time','source','what','channel','result','success','transaction_id']);
            foreach($tl['events'] as $ev) fputcsv($out,csv_safe_row([$ev['when'],$ev['source'],$ev['what'],$ev['channel'],$ev['result'],$ev['ok']?'yes':'no',$ev['transaction_id']]));
            exit;
        }
        audit('timeline_view',$schema,null,$tl['msisdn'],"$from..$to");
    }
    layout_start('Customer Timeline');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-timeline me-2"></i>Customer Timeline</h3>
        <p class="text-muted">Everything we have for one subscriber in one list, newest first — transactions from <b>audit_log</b> and subscription attempts from <b>subscription</b> side by side, so you can see what happened in order.</p>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="timeline">
            <div class="col-md-4"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($msisdn)?>" required placeholder="e.g. 6201234"></div>
            <div class="col-md-2"><label>Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($from)?>"></div>
            <div class="col-md-2"><label>Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($to)?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="fa fa-search"></i> Show timeline</button></div>
        </form>
    </div>
    <?php if($tl):?>
    <?php foreach($tl['notes'] as $n):?><div class="alert alert-info py-2 mt-3 mb-0 small"><?=e($n)?></div><?php endforeach;?>
    <div class="cardx mt-3"><h3><i class="fa-regular fa-note-sticky me-2"></i>Case notes <small class="text-muted">for <?=e($tl['msisdn'])?></small></h3>
        <?php if(can('create_records')):?><form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="add_note"><input type="hidden" name="msisdn" value="<?=e($tl['msisdn'])?>"><input type="hidden" name="date_from" value="<?=e($from)?>"><input type="hidden" name="date_to" value="<?=e($to)?>">
            <div class="col-md-7"><textarea class="form-control" name="note" rows="2" maxlength="2000" required placeholder="What the customer reported, what you found, what was done…"></textarea></div>
            <div class="col-md-3"><input class="form-control" name="transaction_id" placeholder="Transaction ID (optional)"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Add note</button></div></form><?php endif;?>
        <?php if(!$notes):?><p class="text-muted mb-0">No notes yet for this number.</p><?php else: foreach($notes as $nt):?>
        <div class="border-start border-3 ps-3 mb-2"><div class="small text-muted"><?=e($nt['created_at'])?> · <?=e($nt['created_by'])?><?php if($nt['transaction_id']):?> · <span class="txid"><?=e($nt['transaction_id'])?></span><?php endif;?></div><div style="white-space:pre-wrap"><?=e($nt['note'])?></div></div><?php endforeach; endif;?>
    </div>
    <div class="cardx table-card mt-3">
        <div class="d-flex justify-content-between align-items-center px-3 pt-3"><span class="text-muted"><?=count($tl['events'])?> events for <?=e($tl['msisdn'])?></span><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(['page'=>'timeline','msisdn'=>$msisdn,'date_from'=>$from,'date_to'=>$to,'format'=>'csv']))?>"><i class="fa fa-file-csv"></i> Export CSV</a></div>
        <?php if(!$tl['events']):?><p class="px-3 pb-3 mb-0">Nothing found for that number in this range.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm table-hover align-middle"><thead><tr><th>Details</th><th>Time</th><th>Source</th><th>What</th><th>Channel</th><th>Result</th><th>Transaction ID</th></tr></thead><tbody>
        <?php foreach($tl['events'] as $ev):?><tr>
            <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view <?=$ev['source']==='subscription'?'data-source="subscription" ':''?>data-id="<?=e($ev['id'])?>" data-date="<?=e($ev['date'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
            <td class="text-nowrap"><?=e($ev['when'])?></td>
            <td><span class="badge <?=$ev['source']==='audit_log'?'bg-secondary':'bg-info text-dark'?>"><?=e($ev['source'])?></span></td>
            <td><?=e($ev['what'])?></td><td><?=e($ev['channel'])?></td>
            <td class="cell-full"><span class="badge <?=$ev['ok']?'bg-success':'bg-danger'?> me-1"><?=$ev['ok']?'Success':'Failed'?></span><?=e(mb_strimwidth($ev['result'],0,80,'...'))?></td>
            <td class="cell-full" style="min-width:230px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($ev['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($ev['transaction_id'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
    <script src="txviewer.js?v=<?=e((string)(@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/txviewer.js') ?: time()))?>"></script>
    <?php endif; layout_end(); exit;

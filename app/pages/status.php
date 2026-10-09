<?php
declare(strict_types=1);
// Page: ?page=status — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_api_keys');
    $sections=system_status();
    $bad=0; $warn=0; foreach($sections as $sec) foreach($sec['rows'] as $r0){ if($r0['state']==='bad') $bad++; elseif($r0['state']==='warn') $warn++; }
    layout_start('System Status');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0"><i class="fa-solid fa-server me-2"></i>System Status</h3>
            <span><?php if($bad):?><span class="badge bg-danger fs-6"><?=$bad?> problem<?=$bad===1?'':'s'?></span><?php elseif($warn):?><span class="badge bg-warning text-dark fs-6"><?=$warn?> to look at</span><?php else:?><span class="badge bg-success fs-6">everything is fine</span><?php endif;?>
            <a class="btn btn-sm btn-outline-primary ms-2" href="?page=status"><i class="fa-solid fa-rotate me-1"></i>Check again</a></span></div>
        <p class="text-muted small mb-0 mt-1">Read-only. Nothing here sends anything to a customer or changes data. For uptime monitors and Kubernetes the portal also answers <code>/health.php</code> (200 = database reachable).</p>
    </div>
    <?php foreach($sections as $sec):?>
    <div class="cardx mt-3"><h3><?=e($sec['title'])?></h3>
        <table class="table table-sm mb-0"><tbody><?php foreach($sec['rows'] as $r0):?><tr>
            <td style="width:1%" class="text-nowrap"><span class="badge <?=['ok'=>'bg-success','warn'=>'bg-warning text-dark','bad'=>'bg-danger','info'=>'bg-secondary'][$r0['state']]?>"><?=['ok'=>'OK','warn'=>'check','bad'=>'down','info'=>'info'][$r0['state']]?></span></td>
            <td class="fw-semibold text-nowrap"><?=e($r0['label'])?></td><td><?=e($r0['detail'])?></td>
            <td class="text-end"><?php if($r0['link']):?><a class="small" href="<?=e($r0['link'])?>">open</a><?php endif;?></td></tr><?php endforeach;?></tbody></table>
    </div>
    <?php endforeach; layout_end(); exit;

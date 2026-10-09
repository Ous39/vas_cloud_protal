<?php
declare(strict_types=1);
// Page: ?page=offer_history — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema(); $id=(int)($_GET['id']??0);
    $offer=fetch_record($schema,'vas_offers',['id'=>$id]) ?: throw new RuntimeException('Offer not found');
    $hist=offer_history($schema,$id);
    layout_start('Offer history');
    ?>
    <div class="cardx">
        <a href="?page=offers">&larr; Offer Management</a>
        <h3 class="mt-2"><?=e($offer['name'])?> <small class="text-muted"><?=e($offer['offer_code'])?></small></h3>
        <p class="text-muted mb-0">Who changed this offer, when, and what changed. Only changes made through this portal are recorded; entries made before change-tracking was added show the submitted values without the previous ones.</p>
    </div>
    <div class="cardx mt-3">
        <?php if(!$hist):?><p class="text-muted mb-0">No changes recorded for this offer in this portal yet.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Changes</th></tr></thead><tbody>
        <?php foreach($hist as $h):?><tr>
            <td class="text-nowrap"><?=e($h['at'])?></td><td><?=e($h['user'])?></td><td><span class="badge <?=$h['action']==='insert'?'bg-success':'bg-warning text-dark'?>"><?=$h['action']==='insert'?'Created':'Edited'?></span></td>
            <td class="cell-full"><?php if(!$h['changes']):?><span class="text-muted">no field changed</span><?php else: foreach($h['changes'] as $col=>$c):?><div><b><?=e($col)?></b>:
                <?php if($h['legacy'] || $h['action']==='insert'):?><?=e((string)$c['to'])?><?php else:?><span class="text-danger"><del><?=e((string)($c['from']??'(empty)'))?></del></span> → <span class="text-success"><?=e((string)($c['to']??'(empty)'))?></span><?php endif;?></div><?php endforeach; endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <?php layout_end(); exit;

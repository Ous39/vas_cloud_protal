<?php
declare(strict_types=1);
// Page: ?page=ussd_sim — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_ussd_menus');
    $known=menu_shortcodes(); $sc=trim((string)($_GET['sc']??'')); if($sc==='') $sc=$known[0]??'*9606*9090#';
    $withDraft=($_GET['draft']??'1')==='1';
    $prev=array_values(array_filter(explode(',',(string)($_GET['trail']??'')),fn($x)=>$x!==''));
    $reply=trim((string)($_GET['reply']??'')); $seed=trim((string)($_GET['seed']??'')); if($seed==='') $seed='sim'.mt_rand();
    $replies=$prev; if($reply!=='') $replies[]=$reply;
    $dres=ussd_resolve_dialled($sc,$known); if($dres && $dres[1]){ $sc=$dres[0]; $replies=array_merge($dres[1],$replies); }
    if(count($replies)>30) $replies=array_slice($replies,-30);
    $fsim=(string)($_GET['fsim']??'success'); if(!in_array($fsim,['success','lowbal,success','lowbal,fail','lowbal','fail'],true)) $fsim='success';
    $scr=ussd_screen($sc,$replies,$withDraft?['active','draft']:['active'],null,['seed'=>$seed,'share'=>'ussd_share_sim','msisdn'=>'2200000000','flow_sim'=>$fsim]);
    $trail=implode(',',$replies);
    layout_start('USSD Simulator'); ussd_subnav('ussd_sim');
    ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="cardx">
            <h3><i class="fa-solid fa-mobile-screen me-2"></i>Try a menu</h3>
            <p class="text-muted">Shows exactly what a phone would show for a short code, walking the menu you built in the <a href="?page=ussd_menu">Menu Builder</a>. Nothing is sent to Mobius or any customer.</p>
            <form method="get" class="mb-3"><input type="hidden" name="page" value="ussd_sim">
                <label class="small text-muted mb-0">Short code</label>
                <div class="input-group mb-2"><input class="form-control" name="sc" value="<?=e($sc)?>" list="scList"><datalist id="scList"><?php foreach($known as $k):?><option value="<?=e($k)?>"><?php endforeach;?></datalist><button class="btn btn-outline-primary">Dial</button></div>
                <label class="small text-muted mb-0 mt-1">If a service flow asks Hera, pretend it says</label><select class="form-select form-select-sm mb-2" name="fsim" data-autosubmit><?php foreach(['success'=>'success','lowbal,success'=>'low balance, then success on the next call (e.g. a loan)','lowbal,fail'=>'low balance, then failure','lowbal'=>'low balance','fail'=>'failure'] as $fv=>$fl):?><option value="<?=e($fv)?>" <?=$fsim===$fv?'selected':''?>><?=e($fl)?></option><?php endforeach;?></select>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="draft" value="1" id="dr" <?=$withDraft?'checked':''?> data-autosubmit><label class="form-check-label small" for="dr">Include <b>draft</b> items (the live service will only show <b>active</b> ones)</label></div>
            </form>
            <?php if(!in_array($sc,$known,true)):?><div class="alert alert-info py-2 small">No menu exists for <b><?=e($sc)?></b> yet. <a href="?page=ussd_menu&new_short_code=<?=urlencode($sc)?>">Start one in the Menu Builder</a>.</div><?php endif;?>
            <div class="small text-muted">Replies so far: <b><?=$trail!==''?e(str_replace(',',' → ',$trail)):'(none)'?></b></div>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <div class="ussd-phone">
                <div class="ussd-screen"><?=nl2br(e($scr['text']))?></div>
                <div class="ussd-meta <?=$scr['too_long']?'text-danger fw-semibold':'text-muted'?>"><?=$scr['chars']?> / <?=USSD_MAX_CHARS?> characters<?=$scr['too_long']?' — too long: some phones cut or reject it':''?></div>
                <?php if($scr['end']):?>
                    <?php if($scr['kind']==='purchase'):?><div class="alert alert-warning py-2 mt-2 mb-0 small">Simulator: nothing was bought. On the live short code this is where the purchase happens (purchase mode: <b><?=e(ussd_proxy_config()['purchase_mode'])?></b>).</div><?php endif;?>
                    <div class="alert alert-secondary py-2 mt-2 mb-2">Session ended (<?=e($scr['kind'])?>).</div>
                    <a class="btn btn-primary" href="?page=ussd_sim&sc=<?=urlencode($sc)?>&draft=<?=$withDraft?1:0?>&fsim=<?=urlencode($fsim)?>&seed=<?=mt_rand()?>"><i class="fa-solid fa-rotate-right me-1"></i>Dial again</a>
                <?php else:?>
                <form method="get" class="d-flex gap-2 mt-2"><input type="hidden" name="page" value="ussd_sim"><input type="hidden" name="sc" value="<?=e($sc)?>"><input type="hidden" name="draft" value="<?=$withDraft?1:0?>"><input type="hidden" name="trail" value="<?=e($trail)?>"><input type="hidden" name="seed" value="<?=e($seed)?>"><input type="hidden" name="fsim" value="<?=e($fsim)?>">
                    <input class="form-control" name="reply" inputmode="numeric" autocomplete="off" autofocus placeholder="Your reply, e.g. 1"><button class="btn btn-primary">Send</button>
                    <a class="btn btn-outline-secondary" href="?page=ussd_sim&sc=<?=urlencode($sc)?>&draft=<?=$withDraft?1:0?>&fsim=<?=urlencode($fsim)?>&seed=<?=mt_rand()?>">Restart</a></form>
                <?php endif;?>
            </div>
        </div></div>
    </div>
    <?php layout_end(); exit;

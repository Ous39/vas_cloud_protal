<?php
declare(strict_types=1);
// Page: ?page=ussd_menu — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); menu_versions_table();
    $canEdit = can('manage_ussd_menus');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_perm('manage_ussd_menus');
        $do=(string)($_POST['do']??''); $go=fn(string $sc)=>redirect('?page=ussd_menu&short_code='.urlencode($sc));
        if ($do==='import_menu') {
            if (empty($_POST['confirm_replace'])) throw new RuntimeException('Tick the box to confirm the current menu is replaced.');
            $res=import_menu_json((string)($_POST['import_short_code']??''),(string)($_POST['import_json']??''));
            flash('success','Menu imported: '.$res['nodes'].' items.'.($res['archived']?' The previous '.$res['archived'].' items were kept, inactive, as an archived copy.':''));
            $go(trim((string)($_POST['import_short_code']??'')));
        }
        if ($do==='move') $go(menu_move_node((int)($_POST['id']??0),(string)($_POST['dir']??'')));
        if ($do==='duplicate') { $sc=menu_duplicate_node((int)($_POST['id']??0)); flash('success','Copied as a Draft, just below the original. Edit it, then turn it on.'); $go($sc); }
        if ($do==='set_status') { $sc=menu_set_status((int)($_POST['id']??0),(string)($_POST['status']??''),!empty($_POST['branch'])); $go($sc); }
        if ($do==='activate_drafts') { $sc=trim((string)($_POST['short_code']??'')); $n=menu_activate_drafts($sc); flash('success',$n.' draft item'.($n===1?'':'s').' turned on — customers can see '.($n===1?'it':'them').' now.'); $go($sc); }
        if ($do==='quick_add') { $sc=trim((string)($_POST['short_code']??'')); $n=menu_quick_add($sc,($_POST['parent_id']??'')!==''?(int)$_POST['parent_id']:null,(string)($_POST['lines']??''),(string)($_POST['status']??'draft')); flash('success',$n.' item'.($n===1?'':'s').' added.'); $go($sc); }
        if ($do==='copy_menu') { $to=trim((string)($_POST['to']??'')); $n=menu_copy_to(trim((string)($_POST['short_code']??'')),$to,!empty($_POST['confirm_replace'])); flash('success','Copied '.$n.' items to '.$to.' as Drafts. Open it, check it, then "Activate all drafts".'); $go($to); }
        if ($do==='restore') { $sc=menu_restore_version((int)($_POST['id']??0)); flash('success','That saved version is back. The menu it replaced is kept (inactive) as an archive.'); $go($sc); }
        save_menu_node($_POST['data']??[], !empty($_POST['id'])?(int)$_POST['id']:null);
        flash('success','Saved.');
        $go((string)($_POST['data']['short_code']??''));
    }
    $shortCode = trim((string)($_GET['short_code'] ?? ''));
    if ($shortCode === '') $shortCode = trim((string)($_GET['new_short_code'] ?? ''));
    $known = menu_shortcodes();
    if ($shortCode==='' && $known) $shortCode = $known[0];
    $edit=null; if(isset($_GET['id'])){ $edit=menu_node((int)$_GET['id']); }
    $addParent = $edit ? ($edit['parent_id']??'') : ($_GET['parent']??'');
    $tree = $shortCode!=='' ? menu_tree($shortCode) : [];
    $flatNodes = $shortCode!=='' ? menu_nodes_flat($shortCode) : [];
    $labelOf=[]; foreach($flatNodes as $fn0) $labelOf[(int)$fn0['id']]=$fn0;
    $pathLabel=function(int $id) use (&$pathLabel,$labelOf){ $n=$labelOf[$id]??null; if(!$n) return ''; $pp=$n['parent_id']!==null?$pathLabel((int)$n['parent_id']):''; return ($pp!==''?$pp.' › ':'').$n['prompt_text']; };
    $offers = table_exists(current_schema(),'vas_offers') ? pdo(current_schema())->query("SELECT offer_code, name FROM vas_offers WHERE ".OFFER_ACTIVE_SQL." ORDER BY name")->fetchAll() : [];
    $subCats=[]; try { if(table_exists(USSD_OFFER_SCHEMA,'vas_offers')) $subCats=pdo(USSD_OFFER_SCHEMA)->query("SELECT sub_category, SUM(".OFFER_ACTIVE_SQL.") act FROM vas_offers WHERE sub_category IS NOT NULL AND sub_category<>'' AND (deleted_at IS NULL OR deleted_at='') GROUP BY sub_category HAVING act>0 ORDER BY sub_category")->fetchAll(); } catch(Throwable $e){}
    ussd_quiz_tables(); $quizOpts=portal_pdo()->query('SELECT quiz_key,title FROM ussd_quizzes ORDER BY title')->fetchAll(); $flowOpts=flow_list();
    $health = ($shortCode!=='' && $flatNodes) ? menu_health($shortCode) : [];
    $direct = $shortCode!=='' ? menu_direct_codes($shortCode) : [];
    $nErr=count(array_filter($health,fn($h)=>$h['level']==='error')); $nWarn=count(array_filter($health,fn($h)=>$h['level']==='warn'));
    $versions=[]; if($shortCode!==''){ $st=portal_pdo()->prepare('SELECT id,reason,nodes,created_by,created_at FROM ussd_menu_versions WHERE short_code=? ORDER BY id DESC LIMIT 12'); $st->execute([$shortCode]); $versions=$st->fetchAll(); }
    $drafts=count(array_filter($flatNodes,fn($n)=>$n['status']==='draft'));
    layout_start('USSD Menu Builder'); ussd_subnav('ussd_menu');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h3 class="mb-0"><i class="fa-solid fa-sitemap me-2"></i>USSD Menu Builder</h3>
            <?php if($shortCode!==''):?><div class="d-flex gap-2"><a class="btn btn-sm btn-primary" href="?page=ussd_sim&sc=<?=urlencode($shortCode)?>&draft=1"><i class="fa-solid fa-mobile-screen me-1"></i>Try it</a><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_menu_export&short_code=<?=urlencode($shortCode)?>">Export JSON</a></div><?php endif;?>
        </div>
        <form method="get" class="row g-2 mt-1"><input type="hidden" name="page" value="ussd_menu">
            <div class="col-md-4"><select class="form-select" name="short_code" data-autosubmit><?php foreach($known as $sc):?><option value="<?=e($sc)?>" <?=$shortCode===$sc?'selected':''?>><?=e($sc)?></option><?php endforeach;?></select></div>
            <div class="col-md-5"><input class="form-control" name="new_short_code" placeholder="Or start a new short code, e.g. *123#"></div>
            <div class="col-md-3"><button class="btn btn-outline-primary w-100">Switch / Start</button></div>
        </form>
        <details class="mt-2"><summary class="small">How building a menu works (30 seconds)</summary>
            <div class="small mt-2">
                <ol class="mb-2"><li><b>Add items</b> — use <b>Quick add</b> to type a whole list at once, or the form for one. New items start as <b>Draft</b>: only you see them in the Simulator.</li>
                <li><b>Check</b> — the <b>Menu check</b> below tells you what would break on a phone (too long, empty list, offer not available…). <b>Try it</b> opens the Simulator.</li>
                <li><b>Turn on</b> — <b>Activate all drafts</b> (or the power button on one item). Customers see Active items straight away, nothing else to restart.</li>
                <li><b>Changed your mind?</b> Every change is kept in <b>History</b> — press Restore on any earlier version.</li></ol>
                <b>Item types:</b> <b>Submenu</b> (a list of more items) · <b>Catalogue list</b> (offers from the catalogue, kept up to date by itself) · <b>Offer</b> (sells one offer) · <b>End</b> (just a message) · <b>Buy for another number</b> · <b>Shared Bundle</b> · <b>Quiz</b>. The <b>label</b> is the line in the parent's menu; the optional <b>screen text</b> is what shows when the customer opens it. The code must also exist as a PROXY menu in Mobius, pointing at the portal address on the USSD Proxy page.
            </div></details>
    </div>
    <?php if($shortCode!==''): $stt=shortcode_state($shortCode); $reg=$stt['registered'];?>
    <div class="cardx mt-3 py-2"><div class="d-flex flex-wrap gap-3 align-items-center small">
        <span><b>On phones:</b> <?php if($stt['live']):?><span class="badge bg-success">answering</span><?php else:?><span class="badge bg-secondary">not live</span> <span class="text-muted">because <?=e($stt['why'])?> — see <a href="?page=ussd_proxy">Proxy</a></span><?php endif;?></span>
        <span><b>Register:</b> <?php if($reg):?><a href="?page=shortcodes&id=<?=e($reg['id'])?>"><?=e($reg['service_name'])?></a> <span class="badge status-<?=e(strtolower($reg['status']))?>"><?=e($reg['status'])?></span><?php else:?><span class="text-muted">not registered</span> <a href="?page=shortcodes&new=<?=urlencode($shortCode)?>">register it</a><?php endif;?></span>
        <span><b>Direct codes:</b> <span class="text-muted">each item shows its own (⚡) — dial it to jump straight there</span></span></div></div>
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="cardx">
                <div class="d-flex justify-content-between align-items-center"><h3 class="mb-2">Menu check — <?=e($shortCode)?></h3>
                    <span><?php if(!$flatNodes):?><span class="badge bg-secondary">empty</span><?php elseif($nErr):?><span class="badge bg-danger"><?=$nErr?> to fix</span><?php elseif($nWarn):?><span class="badge bg-warning text-dark"><?=$nWarn?> to look at</span><?php else:?><span class="badge bg-success">all good</span><?php endif;?></span></div>
                <?php if(!$health):?><p class="text-muted mb-0 small">Nothing to check yet.</p><?php else:?>
                <ul class="list-unstyled mb-0 small"><?php foreach($health as $h):?><li class="py-1"><span class="badge <?=['error'=>'bg-danger','warn'=>'bg-warning text-dark','info'=>'bg-info text-dark'][$h['level']]?> me-1"><?=['error'=>'fix','warn'=>'check','info'=>'note'][$h['level']]?></span><?=e($h['msg'])?><?php if($h['node']):?> <a href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&id=<?=e($h['node'])?>">open</a><?php endif;?></li><?php endforeach;?></ul><?php endif;?>
                <?php if($canEdit && $drafts):?><form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="activate_drafts"><input type="hidden" name="short_code" value="<?=e($shortCode)?>"><button class="btn btn-sm btn-success"><i class="fa-solid fa-power-off me-1"></i>Activate all <?=$drafts?> draft<?=$drafts===1?'':'s'?></button> <small class="text-muted">turns them on for customers</small></form><?php endif;?>
            </div>
            <div class="cardx mt-3"><h3>Menu — <?=e($shortCode)?></h3>
                <?php if(!$tree):?><p class="text-muted mb-0">No items yet. Use <b>Quick add</b> (right) to type the first list.</p><?php else:?>
                <?php
                $badgeOf=['flow'=>'bg-primary','menu'=>'bg-primary','offer'=>'bg-success','catalog'=>'bg-warning text-dark','recipient'=>'bg-dark','quiz'=>'bg-danger','sharedbundle'=>'bg-success','action'=>'bg-info text-dark','end'=>'bg-secondary'];
                $nameOf=['flow'=>'service flow','menu'=>'submenu','offer'=>'offer','catalog'=>'catalogue list','recipient'=>'buy for other','quiz'=>'quiz','sharedbundle'=>'shared bundle','action'=>'action','end'=>'message'];
                $btn=function(string $do,int $id,string $icon,string $title,array $extra=[]) use ($canEdit){ if(!$canEdit) return ''; $h='<form method="post" class="d-inline"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="id" value="'.$id.'">'; foreach($extra as $k=>$v) $h.='<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; return $h.'<button class="btn btn-sm btn-light border py-0 px-1" title="'.e($title).'"><i class="fa-solid '.$icon.'"></i></button></form>'; };
                $renderTree = function($nodes, $depth=0) use (&$renderTree, $shortCode, $badgeOf, $nameOf, $btn, $canEdit, $direct) { $last=count($nodes)-1; foreach($nodes as $i=>$n): $id=(int)$n['id']; $on=$n['status']==='active'; ?>
                    <div style="margin-left:<?=$depth*22?>px" class="menu-row d-flex align-items-center gap-2 py-1 <?=$on?'':'text-muted'?>">
                        <span class="badge <?=$badgeOf[$n['node_type']]??'bg-secondary'?>" style="min-width:5.2rem"><?=e($nameOf[$n['node_type']]??$n['node_type'])?></span>
                        <span class="flex-grow-1"><?=e($n['prompt_text'])?>
                            <?php if($n['node_type']==='offer'):?><small class="text-muted">(<?=e($n['offer_code'])?>)</small><?php elseif($n['node_type']==='catalog'||$n['node_type']==='sharedbundle'):?><small class="text-muted">[<?=e(str_replace("\n",', ',(string)($n['catalog_filter']??'')))?>]</small><?php elseif($n['node_type']==='quiz' || $n['node_type']==='flow'):?><small class="text-muted">(<?=e($n['offer_code'])?>)</small><?php endif;?>
                            <?php if(!$on):?><span class="badge status-<?=e($n['status'])?>"><?=e($n['status'])?></span><?php elseif(isset($direct[$id])):?><small class="text-muted ms-1" title="Dial this to jump straight here"><i class="fa-solid fa-bolt"></i> <?=e($direct[$id])?></small><?php endif;?></span>
                        <span class="text-nowrap"><?php if($canEdit):?><?=$btn('move',$id,'fa-arrow-up','Move up',['dir'=>'up'])?><?=$btn('move',$id,'fa-arrow-down','Move down',['dir'=>'down'])?><?php endif;?>
                            <a class="btn btn-sm btn-light border py-0 px-1" title="Edit" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&id=<?=$id?>#nodeform"><i class="fa-solid fa-pen"></i></a>
                            <?php if($canEdit):?><?php if($n['node_type']==='menu'):?><a class="btn btn-sm btn-light border py-0 px-1" title="Add an item inside" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&parent=<?=$id?>#nodeform"><i class="fa-solid fa-plus"></i></a><?php endif;?><?=$btn('duplicate',$id,'fa-copy','Copy this item (and what is inside it) as a draft')?><?=$btn('set_status',$id,'fa-power-off',$on?'Turn off':'Turn on',['status'=>$on?'inactive':'active'])?><?php endif;?></span>
                    </div>
                    <?php if(!empty($n['children'])) $renderTree($n['children'],$depth+1); endforeach; }; $renderTree($tree); ?>
                <?php endif;?>
            </div>
            <div class="cardx mt-3"><div class="d-flex justify-content-between align-items-center"><h3>What a customer sees</h3></div>
                <?php if(!$tree):?><p class="text-muted mb-0">Nothing to preview yet.</p><?php else:?><pre class="mb-0"><?=render_menu_preview($tree)?></pre><?php endif;?>
            </div>
        </div>
        <div class="col-lg-5">
            <?php if($canEdit):?>
            <div class="cardx" id="nodeform"><h3><?= $edit?'Edit item':'Add one item' ?></h3>
                <form method="post" class="nodeform"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="data[short_code]" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Where it goes</label><select class="form-select mb-2" name="data[parent_id]"><option value="">The first menu (top level)</option><?php foreach($flatNodes as $n): if($edit && (int)$n['id']===(int)$edit['id']) continue; if($n['node_type']!=='menu') continue; ?><option value="<?=e($n['id'])?>" <?=(string)$addParent===(string)$n['id']?'selected':''?>>inside: <?=e($pathLabel((int)$n['id']))?></option><?php endforeach;?></select>
                    <label class="small text-muted mb-0">Label <small>(the line in the menu)</small></label><input class="form-control mb-2" name="data[prompt_text]" value="<?=e($edit['prompt_text']??'')?>" placeholder="e.g. Sakan Bundles" maxlength="120">
                    <label class="small text-muted mb-0">What is it?</label><select class="form-select mb-1" name="data[node_type]" id="node_type"><?php foreach(['menu'=>'A list of more items (submenu)','catalog'=>'Offers from the catalogue (kept up to date by itself)','offer'=>'One offer to buy','end'=>'Just a message (ends the session)','recipient'=>'Buy for another number','sharedbundle'=>'Shared Bundle service (Seddo)','flow'=>'Service flow (a special menu built on the Service Flows page)','quiz'=>'Quiz game','action'=>'Action (not connected yet)'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['node_type']??'menu')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select>
                    <div class="fld help t-menu small text-muted mb-2">Opens a screen listing the Active items you put inside it.</div>
                    <div class="fld help t-catalog small text-muted mb-2">Lists the <b>Active</b> offers of the sub-categories you choose, cheapest first, 5 per screen. Add or switch off an offer in the catalogue and the menu follows.</div>
                    <div class="fld help t-offer small text-muted mb-2">Shows the offer's name and price and asks <i>1. Confirm / 2. Cancel</i> before buying.</div>
                    <div class="fld help t-end small text-muted mb-2">Shows its text and ends the session — good for help or "coming soon" screens.</div>
                    <div class="fld help t-recipient small text-muted mb-2">Asks for the other person's number, then shows the main menu again so they can buy for them.</div>
                    <div class="fld help t-sharedbundle small text-muted mb-2">The whole Shared Bundle flow: buy, add a sharing number, balance, numbers. Set it up on the <a href="?page=ussd_proxy">USSD Proxy</a> page.</div>
                    <div class="fld help t-flow small text-muted mb-2">Opens a special menu you built on the <a href="?page=ussd_flows">Service Flows</a> page — ask, look something up, confirm, make a call, and branch on the result.</div>
                    <div class="fld help t-quiz small text-muted mb-2">Plays one of the quizzes from the <a href="?page=ussd_quiz">USSD Quiz</a> page.</div>
                    <div class="fld help t-action small text-muted mb-2">Reserved for things like "check balance". Today it shows a placeholder, so avoid it in a live menu.</div>
                    <div class="fld t-menu t-catalog t-end"><label class="small text-muted mb-0">Screen text <small>(optional — what shows when it opens; otherwise the label is used)</small></label><textarea class="form-control mb-2" rows="2" name="data[body_text]" maxlength="400" placeholder="e.g. Choose your Sakan bundle:"><?=e($edit['body_text']??'')?></textarea></div>
                    <div class="fld t-offer"><label class="small text-muted mb-0">Offer</label><select class="form-select mb-2" name="data[offer_code]"><option value="">—</option><?php foreach($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=($edit['offer_code']??'')===$o['offer_code']?'selected':''?>><?=e($o['name'])?> (<?=e($o['offer_code'])?>)</option><?php endforeach;?></select></div>
                    <div class="fld t-flow"><label class="small text-muted mb-0">Service flow <small>(<a href="?page=ussd_flows">manage flows</a>)</small></label><select class="form-select mb-2" name="data[flow_key]"><option value="">—</option><?php foreach($flowOpts as $fo):?><option value="<?=e($fo['flow_key'])?>" <?=(($edit['node_type']??'')==='flow' && ($edit['offer_code']??'')===$fo['flow_key'])?'selected':''?>><?=e($fo['title'])?> (<?=e($fo['flow_key'])?>)<?=$fo['status']==='inactive'?' — off':''?></option><?php endforeach;?></select></div>
                    <div class="fld t-quiz"><label class="small text-muted mb-0">Quiz <small>(<a href="?page=ussd_quiz">manage quizzes</a>)</small></label><select class="form-select mb-2" name="data[quiz_key]"><option value="">—</option><?php foreach($quizOpts as $qo):?><option value="<?=e($qo['quiz_key'])?>" <?=(($edit['node_type']??'')==='quiz' && ($edit['offer_code']??'')===$qo['quiz_key'])?'selected':''?>><?=e($qo['title'])?> (<?=e($qo['quiz_key'])?>)</option><?php endforeach;?></select></div>
                    <div class="fld t-catalog t-sharedbundle"><label class="small text-muted mb-0">Sub-categories <small>(one per line — Shared Bundle uses <code>Seddo</code>)</small></label><textarea class="form-control mb-1" rows="3" name="data[catalog_filter]" placeholder="Sakan 7 days&#10;Sakan 30 days"><?=e($edit['catalog_filter']??'')?></textarea>
                        <?php if($subCats):?><details class="mb-2"><summary class="small text-muted">Sub-categories available (with active offers)</summary><div class="small"><?php foreach($subCats as $sc2):?><span class="badge bg-light text-dark border me-1 mb-1"><?=e($sc2['sub_category'])?> · <?=(int)$sc2['act']?></span><?php endforeach;?></div></details><?php endif;?></div>
                    <div class="fld t-action"><label class="small text-muted mb-0">Action key</label><input class="form-control mb-2" name="data[action_key]" value="<?=e($edit['action_key']??'')?>" placeholder="e.g. check_balance"></div>
                    <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['draft'=>'Draft (only in Simulator)','active'=>'Active (customers see it)','inactive'=>'Off'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['status']??'draft')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label class="small text-muted mb-0">Position <small>(0 = last)</small></label><input type="number" min="0" class="form-control mb-2" name="data[display_order]" value="<?=e($edit['display_order']??0)?>"></div></div>
                    <button class="btn btn-primary w-100"><?=$edit?'Save changes':'Add item'?></button>
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>">Cancel</a><?php endif;?>
                </form>
            </div>
            <div class="cardx mt-3"><h3>Quick add — many at once</h3>
                <p class="small text-muted mb-2">One item per line. Just a name makes a submenu; add <code>| type | detail</code> for the rest:</p>
                <pre class="small mb-2" style="white-space:pre-wrap">KAA Bundle
Sakan 7 days | catalog | Sakan 7 days
Sakan 30 days | catalog | Sakan 30 days; Sakan Data 30 days
Daily 1GB | offer | 40154
How to play | end | Answer 5 questions, score 4+ to win
Comium Quiz | quiz | comium_trivia
Shared Bundle | shared | Seddo
Buy for other | other</pre>
                <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="quick_add"><input type="hidden" name="short_code" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Add them inside</label><select class="form-select mb-2" name="parent_id"><option value="">The first menu (top level)</option><?php foreach($flatNodes as $n): if($n['node_type']!=='menu') continue; ?><option value="<?=e($n['id'])?>" <?=(string)($_GET['parent']??'')===(string)$n['id']?'selected':''?>>inside: <?=e($pathLabel((int)$n['id']))?></option><?php endforeach;?></select>
                    <textarea class="form-control code mb-2" rows="5" name="lines" placeholder="One item per line"></textarea>
                    <div class="d-flex gap-2"><select class="form-select" name="status" style="max-width:12rem"><option value="draft">As Drafts (safe)</option><option value="active">Active straight away</option></select><button class="btn btn-outline-primary">Add them</button></div></form>
            </div>
            <?php endif;?>
            <div class="cardx mt-3"><h3>History</h3>
                <?php if(!$versions):?><p class="small text-muted mb-0">Changes from now on are kept here, so any earlier version can be brought back.</p><?php else:?>
                <div class="table-scroll" style="max-height:260px;overflow-y:auto"><table class="table table-sm mb-0 small"><tbody><?php foreach($versions as $v):?><tr><td class="text-nowrap"><?=e(date('M j H:i',strtotime($v['created_at'])))?></td><td><?=e($v['created_by']??'')?></td><td><?=e($v['reason'])?> <span class="text-muted">· <?=(int)$v['nodes']?> items</span></td>
                    <td class="text-end"><?php if($canEdit):?><form method="post" data-confirm="Bring this version back? The current menu is kept as an archive."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?=e($v['id'])?>"><button class="btn btn-sm btn-outline-secondary py-0">Restore</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
            </div>
            <?php if($canEdit):?>
            <div class="cardx mt-3"><h3>More tools</h3>
                <form method="post" class="mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="copy_menu"><input type="hidden" name="short_code" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Copy this whole menu to another short code (as Drafts)</label>
                    <div class="input-group mb-1"><input class="form-control" name="to" placeholder="e.g. *9606*90912#"><button class="btn btn-outline-primary">Copy</button></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_replace" value="1" id="cp"><label class="form-check-label small" for="cp">Replace it if that code already has a menu (the old one is kept as an archive)</label></div></form>
                <details><summary class="small">Import a menu from JSON</summary>
                    <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="import_menu"><input type="hidden" name="import_short_code" value="<?=e($shortCode)?>">
                        <p class="small text-muted mb-1">Same shape as <b>Export JSON</b>. It <b>replaces</b> this code's menu; the old items stay as an inactive archive.</p>
                        <textarea class="form-control code mb-2" rows="4" name="import_json" placeholder='[{"prompt_text":"Comium Menu","node_type":"menu","status":"active","children":[ … ]}]'></textarea>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_replace" value="1" id="cr"><label class="form-check-label small" for="cr">Replace the current menu of <b><?=e($shortCode)?></b></label></div>
                        <button class="btn btn-outline-primary btn-sm">Import menu</button></form></details>
            </div>
            <?php endif;?>
        </div>
    </div>
    <?php endif; layout_end(); exit;

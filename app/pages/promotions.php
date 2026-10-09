<?php
declare(strict_types=1);
// Page: ?page=promotions — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_promotions'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        save_promotion($schema, $_POST['name']??'', $_POST['offer_codes']??[], $id);
        flash('success','Promotion saved.');
        redirect('?page=promotions');
    }
    $edit=null; if(isset($_GET['id'])){ $edit=get_promotion((int)$_GET['id']); }
    $promotions=list_promotions($schema);
    $offers = table_exists($schema,'vas_offers') ? pdo($schema)->query("SELECT offer_code, name FROM vas_offers ORDER BY name")->fetchAll() : [];
    layout_start('Promotions');
    ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="cardx">
            <h3><?= $edit?'Edit Promotion':'Add Promotion' ?></h3>
            <p class="text-muted">Group offer codes under one name so the Promotion Performance report can run without hand-written SQL. "Buy for Other" transactions are picked up automatically via each offer's own Other Offer Code — no need to list those separately.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Name</label><input class="form-control mb-2" name="name" value="<?=e($edit['name']??'')?>" required>
                <label>Offer Codes</label>
                <input class="form-control mb-1" type="text" placeholder="Search by code or name…" data-filter-select="promotion_offer_codes">
                <select class="form-select" id="promotion_offer_codes" name="offer_codes[]" multiple size="10">
                    <?php foreach($offers as $o): $selected = $edit && in_array($o['offer_code'],$edit['offer_codes'],true); ?>
                    <option value="<?=e($o['offer_code'])?>" <?=$selected?'selected':''?>><?=e($o['offer_code'])?> — <?=e($o['name'])?></option>
                    <?php endforeach;?>
                </select>
                <p class="text-muted small mt-1">Ctrl/Cmd-click to select multiple.</p>
                <button class="btn btn-primary w-100 mt-2">Save Promotion</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=promotions">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <h3>Promotions in <?=e($schema)?></h3>
            <?php if(!$promotions):?><p class="text-muted mb-0">No promotions defined yet.</p><?php else:?>
            <div class="table-scroll"><table class="table table-hover"><thead><tr><th>Name</th><th></th></tr></thead><tbody>
            <?php foreach($promotions as $p):?><tr><td><?=e($p['name'])?></td><td><a class="btn btn-sm btn-warning" href="?page=promotions&id=<?=e($p['id'])?>">Edit</a></td></tr><?php endforeach;?>
            </tbody></table></div>
            <?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;

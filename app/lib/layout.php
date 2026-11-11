<?php
declare(strict_types=1);
// The page frame: navigation, permissions of the menu entries, header and footer, the record form. Loaded by index.php only (not by ussd.php).

function asset_version(): string { static $v=null; if($v===null) $v=(string)(@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/style.css') ?: time()); return $v; }

function nav_can_see(string $page): bool {
    if ($page==='sql') return can('run_sql');
    if ($page==='users') return can('manage_users');
    if ($page==='audit') return can('view_audit');
    if ($page==='ussd_sim' || $page==='ussd_quiz' || $page==='ussd_flows' || $page==='ussd_build' || $page==='ussd_new') return can('manage_ussd_menus');
    if ($page==='ussd_proxy' || $page==='mobius_menus') return can('manage_api_keys');
    if ($page==='api_keys') return can('manage_api_keys');
    if ($page==='promotions') return can('manage_promotions');
    if (in_array($page,['alert_settings','retention','status'],true)) return can('manage_api_keys');
    if (in_array($page,['investigate','reports','alerts','monitoring','offer_report','timeline','vendor'],true)) return can('view_reports');
    if ($page==='shortcodes') return can('manage_shortcodes');
    if ($page==='refunds') return can('manage_api_keys');
    if ($page==='projects') return can('manage_projects');
    if (in_array($page,['subscriptions','offers','offer_health','esim','sales','friends_family','voting','ussd_ivr','ussd_menu','ussd_menus','integrations','tables'],true)) return can('view_tables');
    return true;
}

// The tab bar every USSD page shows under its title: the pages used every day in one row, the rest under "More".
// (Refunds is not here any more: it is under Operations. The old builder "Advanced" is under the USSD tab bar's Settings; Easy Builder replaces it.)
// Tabs on the Monitoring pages (one entry in the main menu).
function monitoring_subnav(string $current): void {
    $tabs = [['monitoring','fa-heart-pulse','Monitoring'],['alerts','fa-triangle-exclamation','Alerts'],['status','fa-server','System Status'],['alert_settings','fa-bell','Alert Settings']];
    $tabs = array_values(array_filter($tabs, fn($t) => nav_can_see($t[0]))); if (count($tabs) < 2) return;
    echo '<ul class="nav nav-pills ussd-subnav mb-3">';
    foreach ($tabs as [$p,$ic,$lb]) echo '<li class="nav-item"><a class="nav-link'.($p===$current?' active':'').'" href="?page='.$p.'"><i class="fa-solid '.$ic.' me-1"></i>'.e($lb).'</a></li>';
    echo '</ul>';
}
// The USSD tab bar: few entries. Pages that do the same kind of job share one entry, and a small switch under the bar
// moves between them (Menus: in the portal / in Mobius; Special menus: Service Flows / Quiz). Set-once pages are under Settings.
function ussd_subnav(string $current): void {
    $groups = [
        ['fa-list', 'Menus', [['ussd_menus', 'All menus'], ['mobius_menus', 'Mobius tools']]],
        ['fa-wand-magic-sparkles', 'Builder', [['ussd_build', 'Easy Builder']]],
        ['fa-mobile-screen', 'Try it', [['ussd_sim', 'Simulator']]],
        ['fa-diagram-project', 'Special menus', [['ussd_flows', 'Service Flows'], ['ussd_quiz', 'Quiz']]],
    ];
    $settings = [['ussd_proxy', 'fa-plug', 'Proxy (connection to Mobius)'], ['shortcodes', 'fa-hashtag', 'Short Codes'], ['ussd_ivr', 'fa-route', 'Routing & IVR'], ['ussd_menu', 'fa-sitemap', 'Advanced builder (old)']];
    $settings = array_values(array_filter($settings, fn($t) => nav_can_see($t[0]))); $inSet = null; foreach ($settings as $t) if ($t[0] === $current) $inSet = $t;
    $switch = null;
    echo '<ul class="nav nav-pills ussd-subnav mb-2">';
    if (nav_can_see('ussd_new')) echo '<li class="nav-item"><a class="nav-link nav-create'.($current==='ussd_new'?' active':'').'" href="?page=ussd_new"><i class="fa-solid fa-plus me-1"></i>Create a menu</a></li>';
    foreach ($groups as [$ic, $lb, $pages]) {
        $pages = array_values(array_filter($pages, fn($p) => nav_can_see($p[0]))); if (!$pages) continue;
        $on = in_array($current, array_column($pages, 0), true); if ($on && count($pages) > 1) $switch = $pages;
        echo '<li class="nav-item"><a class="nav-link'.($on?' active':'').'" href="?page='.$pages[0][0].'"><i class="fa-solid '.$ic.' me-1"></i>'.e($lb).'</a></li>';
    }
    if ($settings) {
        echo '<li class="nav-item dropdown"><a class="nav-link dropdown-toggle'.($inSet?' active':'').'" href="#" role="button" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false"><i class="fa-solid '.($inSet?$inSet[1]:'fa-gear').' me-1"></i>'.($inSet?e($inSet[2]):'Settings').'</a><ul class="dropdown-menu dropdown-menu-end">';
        foreach ($settings as [$p,$ic,$lb]) echo '<li><a class="dropdown-item'.($p===$current?' active':'').'" href="?page='.$p.'"><i class="fa-solid '.$ic.' fa-fw me-2"></i>'.e($lb).'</a></li>';
        echo '</ul></li>';
    }
    echo '</ul>';
    if ($switch) {
        echo '<div class="btn-group btn-group-sm ussd-switch mb-3" role="group">';
        foreach ($switch as [$p, $lb]) echo '<a class="btn '.($p===$current?'btn-dark':'btn-outline-secondary').'" href="?page='.$p.'">'.e($lb).'</a>';
        echo '</div>';
    } else echo '<div class="mb-2"></div>';
}

function layout_start(string $title): void {
    $u=user(); $schema=current_schema(); $current=$_GET['page']??'dashboard';
    // The main menu: few entries. USSD and Monitoring are one entry each; their pages have their own tab bar.
    // A top-level entry is [page, icon, label, pages it covers]; the first page of that list the user may open is the link.
    $ussdPages = ['mobius_menus','ussd_menus','ussd_build','ussd_new','ussd_sim','ussd_flows','ussd_quiz','ussd_proxy','ussd_menu','ussd_ivr'];
    $nav = [
        ['dashboard','fa-gauge','Dashboard',['dashboard']],
        ['ussd','fa-mobile-screen-button','USSD',$ussdPages],
        ['monitoring','fa-heart-pulse','Monitoring',['monitoring','alerts','status','vendor']],
        ['group','fa-layer-group','Operations',[['refunds','fa-rotate-left','Refunds'],['subscriptions','fa-user-check','Subscriptions'],['offers','fa-tags','Offer Management'],['offer_health','fa-stethoscope','Offer Health'],['friends_family','fa-user-group','Friends & Family'],['voting','fa-square-poll-vertical','Voting Service']]],
        ['group','fa-chart-line','Reports',[['investigate','fa-headset','Complaint Investigation'],['timeline','fa-timeline','Customer Timeline'],['offer_report','fa-bullhorn','Offer Performance']]],
        ['group','fa-gears','Admin',[['users','fa-users-gear','Users'],['audit','fa-shield-halved','Audit Trail'],['shortcodes','fa-hashtag','Short Codes'],['promotions','fa-bullhorn','Promotions'],['integrations','fa-plug-circle-check','Integrations'],['alert_settings','fa-bell','Alert Settings'],['retention','fa-database','Data Retention'],['tables','fa-table','Database Tables'],['sql','fa-code','SQL Console']]],
    ];
    // Pages kept but no longer in the menu (little or no use): eSIM Profiles, Sales & Invoices, Projects, Partner API Keys, Reports (a list of links).
    // They still open from their address (?page=esim, sales, projects, api_keys, reports) for whoever has the permission.
    ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous"><link href="style.css?v=<?=e(asset_version())?>" rel="stylesheet"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous"></script></head><body>
<nav class="topnav"><div class="topnav-inner">
    <a class="topnav-brand" href="?page=dashboard"><img src="comium_logo.png" alt="Comium">VAS Cloud</a>
    <button type="button" id="topnav-toggle" class="topnav-toggle" aria-label="Menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
    <div class="topnav-collapse" id="topnav-collapse">
    <div class="topnav-links">
    <?php foreach($nav as $item):
        if ($item[0]==='group') {
            [, $icon, $label, $children] = $item;
            $children = array_values(array_filter($children, fn($c)=>nav_can_see($c[0])));
            if (!$children) continue;
            $groupActive = in_array($current, array_column($children,0), true);
    ?>
        <details class="topnav-group<?=$groupActive?' has-active':''?>"><summary><i class="fa-solid <?=$icon?>"></i><?=e($label)?> <i class="fa-solid fa-chevron-down" style="font-size:.7rem;"></i></summary>
            <div class="topnav-menu"><?php foreach($children as $c):?><a class="<?=$current===$c[0]?'active':''?>" href="?page=<?=$c[0]?>"><i class="fa-solid <?=$c[1]?>"></i><?=e($c[2])?></a><?php endforeach;?></div>
        </details>
    <?php } else {
            $to = null; foreach ($item[3] as $pg) if (nav_can_see($pg)) { $to = $pg; break; }
            if ($to === null) continue;
    ?>
        <a class="<?=in_array($current,$item[3],true)?'active':''?>" href="?page=<?=$to?>"><i class="fa-solid <?=$item[1]?>"></i><?=e($item[2])?></a>
    <?php } endforeach; ?>
    </div>
    <div class="topnav-side">
        <?php if(count(user_schemas())<2):?><span class="env-menu env-<?=schema_kind($schema)?>"><span class="env-static"><i class="fa-solid fa-database"></i><?=e(schema_label($schema))?></span></span><?php else:?><details class="env-menu env-<?=schema_kind($schema)?>"><summary title="Database in use"><i class="fa-solid fa-database"></i><?=e(schema_label($schema))?> <i class="fa-solid fa-chevron-down"></i></summary><div class="env-list"><?php foreach(user_schemas() as $s):?><a class="env-<?=schema_kind($s)?><?=$s===$schema?' on':''?>" href="?page=switch_schema&schema=<?=urlencode($s)?>"><span class="dot"></span><strong><?=e(schema_label($s))?></strong><small><?=e($s)?></small></a><?php endforeach;?></div></details><?php endif;?>
        <div class="user-chip"><strong><?=e($u['full_name'])?></strong><small><?=e($u['role'])?> • <?=e($u['username'])?></small><a href="?page=account" class="btn btn-sm btn-outline-light" title="Change my password"><i class="fa-solid fa-key"></i></a> <a href="?page=logout" class="btn btn-sm btn-light">Logout</a></div>
    </div>
    </div>
</div></nav>
<main><div class="topbar"><div><h1><?=e($title)?></h1><p><?=e($schema)?> • Request <?=e(request_id())?></p></div><span class="pill <?=schema_kind($schema)?>"><?=e($schema)?></span></div><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?> shadow-sm"><?=e($f['msg'])?></div><?php endforeach; ?>
<?php }

function layout_end(): void { ?></main><footer>&copy; <?=date('Y')?> Comium VAS Cloud. All rights reserved.</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script><script nonce="<?=e(csp_nonce())?>">
function addFilter(){const box=document.getElementById('filters'); const tpl=document.getElementById('filter-template').innerHTML; box.insertAdjacentHTML('beforeend',tpl);}
function confirmAction(msg){return confirm(msg||'Please confirm before saving this operation.');}
// CSP blocks inline onclick/onsubmit attributes even with a script nonce (nonces only cover <script>
// tags), so every interactive hook is wired here instead of inline in the markup.
document.getElementById('add-filter-btn')?.addEventListener('click', addFilter);
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirmAction(f.dataset.confirm)) e.preventDefault(); }));
document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));
document.querySelectorAll('[data-filter-select]').forEach(input => {
    const sel = document.getElementById(input.dataset.filterSelect);
    if (!sel) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        Array.from(sel.options).forEach(opt => { opt.hidden = q !== '' && !opt.textContent.toLowerCase().includes(q); });
    });
});
// Addresses shown on the USSD Proxy page are built from this browser's own address, so a path prefix the app can't see (/portal) is included.
document.querySelectorAll('[data-public-url]').forEach(el => { el.textContent = new URL(el.dataset.publicUrl, location.href).href; });
// Close an open nav dropdown when clicking outside it.
document.addEventListener('click', e => { document.querySelectorAll('.topnav-group[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
// Mobile nav toggle.
const topnavToggle = document.getElementById('topnav-toggle'), topnavCollapse = document.getElementById('topnav-collapse');
topnavToggle?.addEventListener('click', () => { const open = topnavCollapse.classList.toggle('open'); topnavToggle.setAttribute('aria-expanded', open ? 'true' : 'false'); });
</script></body></html><?php }

function render_form(string $schema,string $table,array $values=[],string $mode='add',array $keys=[]): void { $cols=editable_columns($schema,$table,$mode==='duplicate'); ?><div class="form-grid"><?php foreach($cols as $c): $name=$c['name']; if($mode==='duplicate' && is_auto_col($c)) continue; ?><div class="field"><label><?=e($name)?> <small><?=e($c['type'])?></small></label><?php $val=$values[$name]??''; if(str_contains(strtolower($c['type']),'text') || str_contains(strtolower($c['type']),'blob')): ?><textarea name="data[<?=e($name)?>]" class="form-control" rows="3"><?=e($val)?></textarea><?php else: ?><input name="data[<?=e($name)?>]" class="form-control" value="<?=e($val)?>"><?php endif;?></div><?php endforeach;?></div><?php foreach($keys as $k=>$v):?><input type="hidden" name="keys[<?=e($k)?>]" value="<?=e($v)?>"><?php endforeach; }

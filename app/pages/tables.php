<?php
declare(strict_types=1);
// Page: ?page=tables — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
 require_perm('view_tables'); layout_start('Database Tables'); $schema=current_schema(); ?><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Tables in <?=e($schema)?></h3><a class="btn btn-outline-primary" href="?page=reports">View Reports</a></div><div class="module-grid mt-3"><?php foreach(table_names($schema) as $t):?><a class="module-card" href="?page=table&table=<?=urlencode($t)?>"><i class="fa-solid fa-table"></i><strong><?=e($t)?></strong><span><?=number_format(approx_table_count($schema,$t))?> records (est.)</span></a><?php endforeach;?></div></div><?php layout_end(); exit;

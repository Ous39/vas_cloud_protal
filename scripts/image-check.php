<?php
// Run INSIDE the freshly built image by scripts/release.ps1, before anything is pushed: the code the image carries is complete and loads.
define('LEAN_BOOT', true);
$pages = glob('/var/www/pages/*.php') ?: [];
if (count($pages) < 40) { fwrite(STDERR, 'The image has only '.count($pages)." page files.\n"); exit(1); }
foreach (['/var/www/html/index.php', '/var/www/html/ussd.php', '/var/www/html/api.php', '/var/www/lib/layout.php', '/var/www/config/config.php'] as $f) if (!is_file($f)) { fwrite(STDERR, "Missing $f\n"); exit(1); }
require '/var/www/lib/bootstrap.php';
foreach (['refund_send', 'ussd_screen', 'mobius_create_menu', 'flow_screen', 'ussd_proxy_endpoint', 'menu_create', 'system_status'] as $fn) if (!function_exists($fn)) { fwrite(STDERR, "Missing function $fn\n"); exit(1); }
echo 'image ok: '.count($pages)." page files, code loads\n";

<?php
declare(strict_types=1);
// Loads the portal code. The modules are included in a fixed order (constants and the session setup in core.php come first); they define
// functions and constants only, so including them changes nothing about what runs. See docs/ARCHITECTURE.md ("Code layout").
foreach (['core.php', 'table_tools.php', 'investigation.php', 'subscriptions.php', 'promotions.php', 'dashboard.php', 'offer_health.php', 'maintenance.php', 'status.php', 'reports.php', 'partner.php', 'integrations.php', 'ussd_menus.php', 'ussd_engine.php', 'ussd_quiz.php', 'ussd_purchases.php', 'refunds.php', 'ussd_share.php', 'ussd_proxy.php', 'mobius.php', 'monitoring.php'] as $__m) require_once __DIR__.'/'.$__m;
unset($__m);
require_once __DIR__.'/flows.php';
if (!defined('LEAN_BOOT')) ensure_portal_runtime_schema();

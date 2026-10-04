<?php
declare(strict_types=1);
// Mobius PROXY / MS_INITIATED menu endpoint. Off until enabled on the portal's USSD Proxy page. No session, no login:
// access is by the secret token in the URL (and an optional IP allow-list). See ussd_proxy_endpoint() in lib/bootstrap.php.
define('LEAN_BOOT', true);
require __DIR__ . '/../lib/bootstrap.php';
ussd_proxy_endpoint();

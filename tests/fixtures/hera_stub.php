<?php
// A stand-in for Hera / Mobius, used only by tests/run.php (php -S router). It answers with the same reply shapes the real
// systems gave us, chosen by the first part of the path, and records the last request body per endpoint so tests can check it.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$dir = sys_get_temp_dir();
file_put_contents($dir.'/zt_stub_last_'.md5($path).'.json', json_encode(['path' => $path, 'body' => $body, 'headers' => ['x-api-key' => $_SERVER['HTTP_X_API_KEY'] ?? null, 'x-username' => $_SERVER['HTTP_X_USERNAME'] ?? null]]));
header('Content-Type: application/json');
$j = fn($v) => print(json_encode($v));
$parts = explode('/', trim($path, '/'));

// /purchase/<scenario>   (VasOffers purchaseOffer)
if ($parts[0] === 'purchase') {
    switch ($parts[1] ?? 'ok') {
        case 'ok': $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '0', 'resultDescription' => "\nYour subscription is successful"]]); break;
        case 'low': $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '20000005', 'resultDescription' => "\n Low balance"]]); break;
        case 'deduct': $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '-1', 'resultDescription' => "\nDeduction for Subscription failed"]]); break;
        case 'err500': http_response_code(500); $j(['timestamp' => 'x', 'status' => 500, 'error' => 'Internal Server Error']); break;
        default: $j(['resultCode' => '000', 'resultDescription' => 'Success']);
    }
    exit;
}
// /sb/<scenario>/<operation>   (ShareBundle: subscribe, addNumber, DataUsage, listNumber)
if ($parts[0] === 'sb') {
    $scn = $parts[1] ?? 'ok'; $op = $parts[2] ?? '';
    if ($scn === 'err500') { http_response_code(500); $j(['status' => 500, 'error' => 'Internal Server Error']); exit; }
    if ($op === 'DataUsage') { $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['NextOption' => '', 'package1' => 'Package Free Data remains 482.20MB expires on 19/10/26', 'package3' => '', 'package2' => 'Package Free Data remains 56.97MB expires on 11/10/26', 'package5' => '', 'package4' => '']]); exit; }
    if ($op === 'listNumber') {
        $row = fn($o, $m, $l) => ['usage' => '', 'limit' => $l, 'msisdn' => $m, 'separator' => '', 'order' => $o, 'separator1' => $m === '' ? '' : '-'];
        $rows = $scn === 'empty' ? ['msisdn1' => $row("You don't have Seddo number", '', ''), 'msisdn2' => $row('', '', '')] : ['msisdn1' => $row('1.', '220866111222', '3GB'), 'msisdn2' => $row('2.', '220866333444', '2GB'), 'msisdn3' => $row('', '', '')];
        $j(['resultCode' => '0', 'resultDescription' => 'Successful', 'result' => $rows]); exit;
    }
    if ($scn === 'nosub') { $j(['resultCode' => '9990', 'resultDescription' => "You don't have active Seddo Subscription"]); exit; }
    if ($scn === 'lowbal') { $j(['expiry' => '', 'resultCode' => '20000005', 'resultDescription' => 'Service information verification error: The account balance is insufficient.', 'transactionId' => 'x', 'purchaseSequence' => '0', 'result' => 'Subscription has failed']); exit; }
    $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '0', 'resultDescription' => "\nYour request is successful"]]); exit;
}
// /bal/<scenario>/Balance   (Hera prepaid/Balance: body {"msisdn":"<local number>"}; the reply is as Hera gave it: result is the balance)
if ($parts[0] === 'bal') { if (($parts[1] ?? 'ok') === 'err500') { http_response_code(500); $j(['status' => 500, 'error' => 'Internal Server Error']); exit; } $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => '1']); exit; }
// /mobius/rest/<call>   (Mobius REST: auth/login, ussdmenues/list|count|set). The menus live in a temp file the tests can read and seed.
if ($parts[0] === 'mobius') {
    $rest = implode('/', array_slice($parts, 2)); $in = json_decode($body, true) ?: []; $store = $dir.'/zt_stub_mobius_menus.json';
    $menus = is_file($store) ? (json_decode((string)file_get_contents($store), true) ?: []) : [];
    if ($rest === 'auth/login') { $j(['status' => 'SUCCESS', 'sessionID' => 'sid-test']); exit; }
    if (($in['sessionID'] ?? '') !== 'sid-test') { $j(['status' => 'ERROR', 'errorMessage' => 'invalid session']); exit; }
    $idOf = fn($m) => $m['menuID'] ?? ($m['_id']['$oid'] ?? null);
    switch ($rest) {
        case 'ussdmenues/count': $j(['status' => 'SUCCESS', 'data' => count($menus)]); break;
        case 'ussdmenues/list':
            // "nested" (file zt_stub_mobius_shape.txt): like an install whose list wants the paging fields inside data, answers {data: {list: […]}}
            // with "id" instead of "menuID" and gives at most 50 rows a page. Asked any other way it answers an empty list.
            $nested = trim((string)@file_get_contents($dir.'/zt_stub_mobius_shape.txt')) === 'nested'; $q = $nested ? ($in['data'] ?? null) : $in;
            if ($nested && !is_array($q)) { $j(['status' => 'SUCCESS', 'data' => ['list' => []]]); break; }
            $size = (int)($q['pageSize'] ?? 100); if ($nested) $size = min($size, 50); $from = $q['firstKey'] ?? null; $rows = $menus;
            // menus may be stored as Mobius really holds them: {"_id": {"$oid": "…"}, "applicationDestinationID": {"$oid": "…"}, …}
            if ($from !== null) { $at = array_search($from, array_map($idOf, $rows), true); $rows = $at === false ? [] : array_slice($rows, $at + 1); }
            $rows = array_slice($rows, 0, $size);
            if ($nested) { $rows = array_map(function ($m) { $m['id'] = $m['menuID']; unset($m['menuID']); return $m; }, $rows); $j(['status' => 'SUCCESS', 'data' => ['list' => $rows, 'total' => count($menus)]]); break; }
            $j(['status' => 'SUCCESS', 'data' => $rows]); break;
        case 'ussdmenues/set':
            $d = $in['data'] ?? []; if (empty($d['name'])) { $j(['status' => 'ERROR', 'errorMessage' => 'name required']); break; }
            // "strict" (file zt_stub_mobius_strict.txt): like a Mobius that refuses any field its menus do not have (HTTP 400 "Unrecognized field")
            if (is_file($dir.'/zt_stub_mobius_strict.txt') && $menus) { $known = array_keys($menus[0]); foreach (array_keys($d) as $f) if (!in_array($f, $known, true) && !in_array($f, ['id', 'menuID'], true)) { http_response_code(400); $j(['status' => 'ERROR', 'errorMessage' => 'Unrecognized field &quot;'.$f.'&quot; (class com.mobius.software.ims.ussd.gateway.c.B), not marked as ignorable']); break 2; } }
            if (isset($d['id'])) { $d['menuID'] = $d['id']; unset($d['id']); } if (array_key_exists('_raw', $d)) { $j(['status' => 'ERROR', 'errorMessage' => 'unknown field _raw']); break; }
            if (isset($d['_id']) && !is_array($d['_id'])) { http_response_code(400); $j(['status' => 'ERROR', 'errorMessage' => 'Cannot deserialize _id from a string']); break; }
            if ($idOf($d) !== null) { foreach ($menus as $k => $m) if ($idOf($m) === $idOf($d)) $menus[$k] = $d; }
            else { $new = 'm'.(count($menus) + 1).substr(md5(uniqid('', true)), 0, 8); if ($menus && isset($menus[0]['_id'])) $d['_id'] = ['$oid' => $new]; else $d['menuID'] = $new; $menus[] = $d; }
            file_put_contents($store, json_encode($menus)); $j(['status' => 'SUCCESS', 'data' => $d]); break;
        default: $j(['status' => 'ERROR', 'errorMessage' => 'unknown call']);
    }
    exit;
}
// /refund/<scenario>/BundleSubscription   (Hera BundleSubscription with channel REF; the reply shape here is a stand-in, the real one has not been seen)
if ($parts[0] === 'refund') {
    switch ($parts[1] ?? 'ok') {
        case 'nouser': $j(['success' => false, 'message' => 'Api user does not exist']); break;
        case 'yes': $j(['success' => true, 'message' => 'Refund accepted']); break;
        case 'weird': header('Content-Type: text/html'); echo '<html>maintenance</html>'; break;
        case 'deny': $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '-1', 'resultDescription' => 'No such transaction']]); break;
        case 'err500': http_response_code(500); $j(['status' => 500, 'error' => 'Internal Server Error']); break;
        default: $j(['resultCode' => '000', 'resultDescription' => 'Success', 'result' => ['resultCode' => '0', 'resultDescription' => 'Refund done']]);
    }
    exit;
}
http_response_code(404); $j(['error' => 'unknown']);

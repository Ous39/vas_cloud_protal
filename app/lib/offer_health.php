<?php
declare(strict_types=1);
// Offer catalogue health.

// ===================== Offer Catalog health =====================
// Most recent purchase attempt per offer code. Uses the suffix index backwards (newest id first) so each code
// costs one index seek instead of scanning a month of the 100M-row subscription table. Codes that are not
// exactly 5 characters can never appear: subscription only records the last 5 characters of the transaction.
function offer_last_purchase_dates(string $schema, array $codes, int $budgetSeconds = 20): array {
    $out = ['dates' => [], 'partial' => false];
    if (!table_exists($schema, 'subscription')) return $out;
    $col = column_exists($schema, 'subscription', 'txn_offer_suffix') ? 'txn_offer_suffix' : 'RIGHT(transaction_id, 5)';
    $st = pdo($schema)->prepare("SELECT date FROM subscription WHERE $col = ? ORDER BY id DESC LIMIT 1");
    $start = microtime(true);
    foreach (array_values(array_unique($codes)) as $code) {
        if (strlen((string)$code) !== 5) continue;
        if (microtime(true) - $start > $budgetSeconds) { $out['partial'] = true; break; }
        $st->execute([$code]); $d = $st->fetchColumn();
        $out['dates'][$code] = $d === false ? null : $d;
    }
    return $out;
}
function offer_health(string $schema, int $days): array {
    $offers = pdo($schema)->query('SELECT id, vendor, offer_code, offer_code_for_other, name, status, one_time_price, rental_price, validity_amount FROM vas_offers ORDER BY offer_code, id')->fetchAll();
    $empty = fn($v) => $v === null || trim((string)$v) === '' || (is_numeric($v) && (float)$v == 0.0);
    $byCode = []; $byOther = []; $directCodes = [];
    foreach ($offers as $o) {
        $c = strtolower(trim((string)$o['offer_code'])); $byCode[$c][] = $o; $directCodes[$c] = true;
        $x = strtolower(trim((string)$o['offer_code_for_other'])); if ($x !== '') $byOther[$x][] = $o;
    }
    $dups = [];
    foreach ($byCode as $c => $rows) {
        if (count($rows) < 2) continue;
        $active = count(array_filter($rows, fn($r) => offer_is_active($r['status'])));
        $dups[] = ['code' => $rows[0]['offer_code'], 'rows' => $rows, 'active' => $active, 'conflict' => $active > 1];
    }
    usort($dups, fn($a, $b) => [$b['conflict'], count($b['rows'])] <=> [$a['conflict'], count($a['rows'])]);
    $otherClash = [];
    foreach ($byOther as $x => $rows) {
        $bases = array_unique(array_map(fn($r) => strtolower(trim((string)$r['offer_code'])), $rows));
        if (count($bases) > 1) $otherClash[] = ['code' => $rows[0]['offer_code_for_other'], 'rows' => $rows, 'why' => 'shared by '.count($bases).' different offers'];
        elseif (isset($directCodes[$x]) && $x !== $bases[array_key_first($bases)]) $otherClash[] = ['code' => $rows[0]['offer_code_for_other'], 'rows' => $rows, 'why' => 'also used as another offer\'s own code'];
    }
    $active = array_values(array_filter($offers, fn($o) => offer_is_active($o['status'])));
    $incomplete = [];
    foreach ($active as $o) {
        $why = [];
        if (trim((string)$o['name']) === '') $why[] = 'no name';
        if ($empty($o['one_time_price']) && $empty($o['rental_price'])) $why[] = 'no price';
        if ($empty($o['validity_amount'])) $why[] = 'no validity';
        if ($why) $incomplete[] = ['offer' => $o, 'why' => $why];
    }
    $codes = []; foreach ($active as $o) { $codes[] = (string)$o['offer_code']; if (trim((string)$o['offer_code_for_other']) !== '') $codes[] = (string)$o['offer_code_for_other']; }
    $last = cached('offer_last_purchase:'.$schema, 1800, fn() => offer_last_purchase_dates($schema, $codes));
    $cut = date('Y-m-d H:i:s', strtotime("-$days days")); $quiet = []; $unchecked = [];
    foreach ($active as $o) {
        $mine = array_filter([$o['offer_code'], trim((string)$o['offer_code_for_other'])], fn($c) => $c !== '' && strlen((string)$c) === 5);
        if (!$mine) { $unchecked[] = $o; continue; }
        $latest = null; foreach ($mine as $c) { $d = $last['dates'][$c] ?? null; if ($d !== null && ($latest === null || $d > $latest)) $latest = $d; }
        $known = false; foreach ($mine as $c) if (array_key_exists($c, $last['dates'])) $known = true;
        if (!$known) continue; // not looked up (time budget) — don't guess
        if ($latest === null || $latest < $cut) $quiet[] = ['offer' => $o, 'last' => $latest];
    }
    usort($quiet, fn($a, $b) => strcmp((string)$a['last'], (string)$b['last']));
    // One entry per offer with all of its problems, so the page can filter/sort a single list.
    $items = [];
    foreach ($offers as $o) $items[(int)$o['id']] = $o + ['issues' => [], 'last' => null, 'score' => 0];
    $add = function (int $id, string $key, string $label, string $sev, int $score) use (&$items) { if (isset($items[$id])) { $items[$id]['issues'][] = ['key' => $key, 'label' => $label, 'sev' => $sev]; $items[$id]['score'] += $score; } };
    foreach ($dups as $d) foreach ($d['rows'] as $r) $d['conflict'] && offer_is_active($r['status'])
        ? $add((int)$r['id'], 'dup', 'Repeated code — '.$d['active'].' active rows', 'danger', 50)
        : $add((int)$r['id'], 'dup', 'Repeated code'.(offer_is_active($r['status']) ? ' (newest active)' : ' (old version)'), 'secondary', 5);
    foreach ($otherClash as $d) foreach ($d['rows'] as $r) $add((int)$r['id'], 'other', '"Buy for other" code: '.$d['why'], 'warning', 30);
    foreach ($incomplete as $x) foreach ($x['why'] as $w) $add((int)$x['offer']['id'], 'missing', ucfirst($w), 'warning', 20);
    foreach ($quiet as $q) $add((int)$q['offer']['id'], 'quiet', $q['last'] ? 'No purchases in '.$days.' days' : 'Never bought', 'warning', 10);
    foreach ($unchecked as $o) $add((int)$o['id'], 'invisible', 'Code not 5 characters — reports can\'t see it', 'info', 8);
    foreach ($active as $o) {
        $mine = array_filter([$o['offer_code'], trim((string)$o['offer_code_for_other'])], fn($c) => $c !== '' && strlen((string)$c) === 5);
        $latest = null; foreach ($mine as $c) { $d = $last['dates'][$c] ?? null; if ($d !== null && ($latest === null || $d > $latest)) $latest = $d; }
        $items[(int)$o['id']]['last'] = $latest;
    }
    return ['total' => count($offers), 'active' => count($active), 'dups' => $dups, 'other_clash' => $otherClash, 'incomplete' => $incomplete, 'quiet' => $quiet,
        'unchecked' => $unchecked, 'partial' => !empty($last['partial']), 'days' => $days, 'items' => array_values($items)];
}

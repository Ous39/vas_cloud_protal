<?php
declare(strict_types=1);
// The USSD screen engine (stateless: a screen is rebuilt from the replies typed so far) and direct codes.

// ===================== USSD screen engine =====================
// Turns the Menu Builder's tree into the screens a subscriber sees. It is deliberately independent of how the
// request arrives (the simulator now, the Mobius PROXY endpoint later): give it the short code and the replies
// typed so far, get back the next screen. Stateless — the "session" is just the list of replies — so any
// replica can serve any step.
const USSD_MAX_CHARS = 182;
// $statuses: which node states are served. Live traffic should use ['active']; the simulator also shows drafts.
const USSD_PAGE_SIZE = 5; // offers per screen in a catalogue list; "6. More" shows the next ones
// $hooks (for tests): 'offer' => fn(code): ?row, 'catalog' => fn(node): list of offer rows.
// Can this menu item lead to something that may be bought for ANOTHER number? Only offers that have an "other" code qualify
// (that is what Hera needs for it), so under "Buy for other" a menu or list that holds none of them is not shown at all.
// $kids: the Active items grouped by parent id (0 = top). $memo caches answers for one walk.
function ussd_allowed_for_other(array $n, array $kids, callable $offerLookup, callable $catalogLookup, array &$memo): bool {
    $key = (string)($n['id'] ?? ''); if ($key !== '' && isset($memo[$key])) return $memo[$key];
    $has = fn($o) => !array_key_exists('offer_code_for_other', $o) || trim((string)$o['offer_code_for_other']) !== '';
    $r = false;
    switch ($n['node_type'] ?? '') {
        case 'offer': $o = $offerLookup(trim((string)$n['offer_code'])); $r = $o && $has($o); break;
        case 'catalog': foreach ($catalogLookup($n) as $o) if ($has($o)) { $r = true; break; } break;
        case 'menu': foreach ($kids[(int)$n['id']] ?? [] as $c) if (ussd_allowed_for_other($c, $kids, $offerLookup, $catalogLookup, $memo)) { $r = true; break; } break;
    }
    if ($key !== '') $memo[$key] = $r;
    return $r;
}
// What a flow gets from the menu engine: the caller, how to find outcomes already stored for this session, how to look things up.
// $hooks['flow_sim'] = success|lowbal|fail makes every call and lookup simulated (the Simulator); $hooks['flow_hooks'] overrides (tests).
function ussd_flow_hooks(array $fl, array $hooks, string $shortCode): array {
    if (!empty($hooks['flow_hooks'])) return $hooks['flow_hooks'] + ['msisdn' => $hooks['msisdn'] ?? ''];
    $h = ['msisdn' => (string)($hooks['msisdn'] ?? '')];
    if (!empty($hooks['flow_sim'])) return $h + ['call' => flow_sim_call_hook((string)$hooks['flow_sim']), 'lookup' => 'flow_sim_lookup'];
    $seed = (string)($hooks['seed'] ?? ''); $ctx = (array)($hooks['ctx'] ?? []);
    return $h + ['call' => flow_stored_hook($fl['flow_key'], $seed), 'lookup' => flow_lookup_hook($fl, $ctx, $seed, $shortCode)];
}
// Direct codes: dialling "<short code>*2*1#" is the same as dialling the short code and then typing 2 and 1. Given the string that
// was dialled and the short codes that have a menu, returns [short code, [the digits to type for the customer]] — or null when
// the string is not one of our codes. An exact short code wins; otherwise the longest short code it starts with.
function ussd_resolve_dialled(string $dialled, array $known): ?array {
    $d = trim($dialled); if ($d === '') return null;
    if (in_array($d, $known, true)) return [$d, []];
    $bare = rtrim($d, '#'); $best = null;
    foreach ($known as $k) { $kb = rtrim((string)$k, '#'); if ($kb !== '' && str_starts_with($bare, $kb.'*') && ($best === null || strlen($kb) > strlen(rtrim($best, '#')))) $best = (string)$k; }
    if ($best === null) return null;
    $rest = substr($bare, strlen(rtrim($best, '#')) + 1); $extras = array_values(array_filter(explode('*', $rest), fn($x) => $x !== ''));
    foreach ($extras as $x) if (!ctype_digit($x)) return null; // only digits are shortcuts
    return [$best, array_slice($extras, 0, 8)];
}
// The direct code of every fixed item of a menu, e.g. [id => "*9606*9090*2*1#"], numbered the way customers see them (Active items only).
function menu_direct_codes(string $code): array {
    $kids = []; foreach (menu_nodes_flat($code) as $n) if ($n['status'] === 'active') $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $roots = $kids[0] ?? []; $top = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($kids[(int)$roots[0]['id']])) ? $kids[(int)$roots[0]['id']] : $roots;
    $out = []; $base = rtrim($code, '#');
    $walk = function (array $nodes, array $path) use (&$walk, &$out, $kids, $base) {
        foreach (array_values($nodes) as $i => $n) { $p = array_merge($path, [$i + 1]); $out[(int)$n['id']] = $base.'*'.implode('*', $p).'#'; if ($n['node_type'] === 'menu' && !empty($kids[(int)$n['id']]) && count($p) < 6) $walk($kids[(int)$n['id']], $p); }
    };
    $walk($top, []); return $out;
}
// A number as a customer reads it on a phone screen: without the 220 country code.
function ussd_local_number(string $n): string {
    $d = preg_replace('/\D+/', '', $n);
    return (str_starts_with($d, '220') && strlen($d) >= 10) ? substr($d, 3) : $d;
}
// A number typed for "Buy for another number": 7 or 9 digits get the country code, 220… is kept; anything else is refused.
function ussd_normalize_msisdn(string $s): ?string {
    $d = preg_replace('/\D+/', '', $s);
    if (str_starts_with($d, '220') && in_array(strlen($d), [10, 12], true)) return $d;
    if (in_array(strlen($d), [7, 9], true)) return '220'.$d;
    return null;
}
// $hooks (for tests): 'offer' => fn(code): ?row, 'catalog' => fn(node): list of offer rows.
function ussd_screen(string $shortCode, array $replies, array $statuses = ['active'], ?array $nodes = null, array $hooks = []): array {
    $nodes = $nodes ?? menu_nodes_flat($shortCode);
    $offerLookup = $hooks['offer'] ?? 'ussd_offer_lookup'; $catalogLookup = $hooks['catalog'] ?? 'ussd_catalog_offers';
    $nodes = array_values(array_filter($nodes, fn($n) => in_array($n['status'], $statuses, true)));
    $kids = [];
    foreach ($nodes as $n) $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    if (!$nodes) return ussd_result('No menu is set up for '.$shortCode.' yet.', true, [], 'empty');
    // A single root "menu" node acts as the welcome screen (its text is the header, its children the options);
    // otherwise the root nodes themselves are the options under a plain header.
    $roots = $kids[0] ?? [];
    $cur = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($kids[(int)$roots[0]['id']])) ? $roots[0] : null;
    // A short code that is nothing but one service (a single flow or action item, e.g. a code that only checks the balance) opens it straight away
    // instead of showing a list with one line — the same as the customer pressing 1.
    if (!$cur && count($roots) === 1 && in_array($roots[0]['node_type'], ['flow', 'action'], true)) array_unshift($replies, '1'); // every rebuild of the screen starts from it
    $trail = [$cur]; $path = []; $note = null; $page = 0;
    $recipient = null; $recipientRaw = ''; // the other number, once typed under a "Buy for another number" item (normalised, and as typed)
    // A quiz node plays a round entirely from the replies: the questions are picked deterministically from the call id
    // (so every replica rebuilds the same game) and the score is just the answers checked against them.
    $quiz = null; $seed = (string)($hooks['seed'] ?? '');
    $quizInfo = $hooks['quiz_info'] ?? 'ussd_quiz_info'; $quizQs = $hooks['quiz_questions'] ?? 'ussd_quiz_questions'; $quizTop = $hooks['quiz_top'] ?? 'ussd_quiz_top';
    // A Shared Bundle node is a small service of its own: buy the bundle, add a sharing number, look at the account.
    // Reading things from Hera (validating a number, balance, numbers) happens here through a hook; anything that
    // changes an account (subscribe, add number) is returned as a result kind and carried out by the endpoint, once.
    $sb = null; $shareCall = $hooks['share'] ?? 'ussd_share_read'; $msisdnIn = (string)($hooks['msisdn'] ?? ''); $ctxIn = (array)($hooks['ctx'] ?? []);
    $sbNew = fn() => ['phase' => 'main', 'offers' => [], 'page' => 0, 'offer' => null, 'number' => null, 'text' => ''];
    $quizNew = function (array $pick) use ($quizInfo): array {
        $key = trim((string)$pick['offer_code']); $info = $quizInfo($key);
        return ['key' => $key, 'info' => $info, 'qs' => [], 'i' => 0, 'score' => 0, 'round' => 0, 'phase' => $info ? 'intro' : 'unavailable', 'fb' => null];
    };
    $quizStart = function (array &$qz) use ($quizQs, $seed): void {
        $qs = $quizQs($qz['key'], $seed, $qz['round'], (int)$qz['info']['per_game']);
        if (!$qs) { $qz['phase'] = 'unavailable'; return; }
        $qz['qs'] = $qs; $qz['i'] = 0; $qz['score'] = 0; $qz['fb'] = null; $qz['phase'] = 'q';
    };
    $confirm = null;   // [node, offer row, name] while the customer is being asked to confirm a purchase
    $hasRecipientNode = fn() => (bool)array_filter($trail, fn($t) => $t && $t['node_type'] === 'recipient');
    // The options on the current screen. A catalogue list builds them from the offer catalogue, a page at a time;
    // under "Buy for another number" they are the main menu again (without that item), once the number is known.
    $otherMemo = [];
    $allowedOther = function (array $n) use ($kids, $offerLookup, $catalogLookup, &$otherMemo): bool { return ussd_allowed_for_other($n, $kids, $offerLookup, $catalogLookup, $otherMemo); };
    $current = function () use (&$cur, &$page, &$recipient, &$trail, $kids, $roots, $catalogLookup, $allowedOther): array {
        if ($cur && $cur['node_type'] === 'recipient') {
            if ($recipient === null) return [[], false];
            $top = $trail[0]; $rootOptions = $top ? ($kids[(int)$top['id']] ?? []) : $roots;
            return [array_values(array_filter($rootOptions, $allowedOther)), false]; // only what can be bought for someone else
        }
        if ($cur && $cur['node_type'] === 'catalog') {
            $all = []; $rows = $catalogLookup($cur); $seen = [];
            // buying for someone else only works for offers that have an "other" code
            if ($recipient !== null) $rows = array_values(array_filter($rows, fn($o) => !array_key_exists('offer_code_for_other', $o) || trim((string)$o['offer_code_for_other']) !== ''));
            foreach ($rows as $o) { $k = strtolower(trim((string)($o['name'] ?? ''))); $seen[$k] = ($seen[$k] ?? 0) + 1; }
            foreach ($rows as $o) {
                $name = trim((string)($o['name'] ?? '')) ?: (string)$o['offer_code'];
                // several offers can share a name (e.g. 175MB for Facebook, TikTok…): tell them apart by their sub-category
                if (($seen[strtolower($name)] ?? 0) > 1 && trim((string)($o['sub_category'] ?? '')) !== '') $name .= ' '.trim(preg_replace('/\s*bundles?$/i', '', (string)$o['sub_category']));
                $all[] = ['id' => 'c'.$cur['id'].'-'.$o['offer_code'], 'parent_id' => $cur['id'], 'node_type' => 'offer', 'status' => 'active', 'offer_code' => (string)$o['offer_code'], 'action_key' => '', 'full_label' => $name,
                    'prompt_text' => mb_strimwidth($name, 0, 18, '…').(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '')];
            }
            return [array_slice($all, $page * USSD_PAGE_SIZE, USSD_PAGE_SIZE), count($all) > ($page + 1) * USSD_PAGE_SIZE];
        }
        $opts = $cur ? ($kids[(int)$cur['id']] ?? []) : $roots;
        if ($recipient !== null) $opts = array_values(array_filter($opts, $allowedOther));
        return [$opts, false];
    };
    for ($ix = 0; $ix < count($replies); $ix++) {
        $r = trim((string)$replies[$ix], " \t\r\n*#"); // people sometimes type *2 or 2# — the star and hash are not part of the choice
        if ($confirm !== null) {
            [$pick, $o, $name] = $confirm;
            if ($r === '1') return ussd_result('Processing your purchase of '.$name.'...', true, $path, 'purchase', $pick)
                + ['purchase' => ['offer_code' => trim((string)$pick['offer_code']), 'name' => $name, 'price' => ($o['one_time_price'] !== null && $o['one_time_price'] !== '') ? (string)$o['one_time_price'] : '',
                    'vendor' => (string)($o['vendor'] ?? ''), 'other_offer_code' => (string)($o['offer_code_for_other'] ?? ''), 'recipient' => $recipient ?? '', 'recipient_raw' => $recipient !== null ? $recipientRaw : '']];
            if ($r === '2') return ussd_result('Cancelled. You were not charged.', true, $path, 'cancel', $pick);
            if ($r === '0') { $confirm = null; array_pop($path); $note = null; continue; }
            $note = 'Invalid choice.'; continue;
        }
        if ($r === '0' && $sb !== null && $cur && $cur['node_type'] === 'sharedbundle' && $sb['phase'] !== 'main') { // "0" inside the service goes back one step, not out of it
            $sb['phase'] = in_array($sb['phase'], ['balance', 'numbers'], true) ? 'account' : 'main'; $sb['offer'] = null; $note = null; continue;
        }
        if ($r === '0' && count($trail) > 1) { array_pop($trail); $cur = end($trail) ?: null; array_pop($path); $note = null; $page = 0; if (!$hasRecipientNode()) $recipient = null; if (!$cur || $cur['node_type'] !== 'quiz') $quiz = null; if (!$cur || $cur['node_type'] !== 'sharedbundle') $sb = null; continue; }
        if ($sb !== null && $cur && $cur['node_type'] === 'sharedbundle') {
            $ph = $sb['phase']; $note = null;
            if ($ph === 'main') {
                if ($r === '1') {
                    $sb['offers'] = array_values($catalogLookup($cur)); $sb['page'] = 0;
                    if (!$sb['offers']) $note = 'No Shared Bundle offer is available right now.';
                    elseif (count($sb['offers']) === 1) { $row = $offerLookup((string)$sb['offers'][0]['offer_code']); if ($row) { $sb['offer'] = ['code' => (string)$sb['offers'][0]['offer_code'], 'row' => $row]; $sb['phase'] = 'confirm'; } else $note = 'This offer is not available right now.'; }
                    else $sb['phase'] = 'list';
                } elseif ($r === '2') $sb['phase'] = 'num';
                elseif ($r === '3') $sb['phase'] = 'account';
                else $note = 'Invalid choice.';
                continue;
            }
            if ($ph === 'list') {
                $slice = array_slice($sb['offers'], $sb['page'] * USSD_PAGE_SIZE, USSD_PAGE_SIZE); $more = count($sb['offers']) > ($sb['page'] + 1) * USSD_PAGE_SIZE;
                if ($more && $r === (string)(USSD_PAGE_SIZE + 1)) { $sb['page']++; $note = null; continue; }
                if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($slice)) { $note = 'Invalid choice.'; continue; }
                $code = (string)$slice[(int)$r - 1]['offer_code']; $row = $offerLookup($code);
                if (!$row) { $note = 'This offer is not available right now.'; continue; }
                $sb['offer'] = ['code' => $code, 'row' => $row]; $sb['phase'] = 'confirm'; $note = null; continue;
            }
            if ($ph === 'confirm') {
                if ($r !== '1') { $note = 'Invalid choice.'; continue; }
                $row = $sb['offer']['row'];
                return ussd_result('Processing your subscription...', true, $path, 'share_subscribe', $cur)
                    + ['share' => ['op' => 'subscribe', 'offer_code' => $sb['offer']['code'], 'name' => trim((string)($row['name'] ?? '')) ?: $sb['offer']['code'], 'price' => (string)($row['one_time_price'] ?? ''),
                        'vendor' => (string)($row['vendor'] ?? ''), 'other_offer_code' => (string)($row['offer_code_for_other'] ?? '')]];
            }
            if ($ph === 'num') {
                $n = ussd_normalize_msisdn($r);
                if ($n === null) { $note = 'Invalid number.'; continue; }
                $rawNum = preg_replace('/\D+/', '', $r); $v = $shareCall('validate', ['msisdn' => $msisdnIn, 'other' => $n, 'other_raw' => $rawNum, 'ctx' => $ctxIn]);
                if (empty($v['ok'])) { $note = trim((string)($v['text'] ?? '')) ?: 'This number cannot be added.'; continue; }
                $sb['number'] = $n; $sb['number_raw'] = $rawNum; $sb['phase'] = 'numconfirm'; $note = null; continue;
            }
            if ($ph === 'numconfirm') {
                if ($r !== '1') { $note = 'Invalid choice.'; continue; }
                return ussd_result('Adding the number...', true, $path, 'share_add', $cur) + ['share' => ['op' => 'add', 'other' => $sb['number'], 'other_raw' => $sb['number_raw'] ?? $sb['number']]];
            }
            if ($ph === 'account') {
                if ($r === '1' || $r === '2') { $res = $shareCall($r === '1' ? 'balance' : 'numbers', ['msisdn' => $msisdnIn, 'ctx' => $ctxIn]); $sb['text'] = trim((string)($res['text'] ?? '')) ?: 'Not available right now.'; $sb['phase'] = $r === '1' ? 'balance' : 'numbers'; $note = null; }
                else $note = 'Invalid choice.';
                continue;
            }
            $note = 'Invalid choice.'; continue; // balance / numbers: only 0 (handled above)
        }
        if ($quiz !== null && $cur && $cur['node_type'] === 'quiz') { // playing a quiz: '0' (handled above) leaves it
            if ($quiz['phase'] === 'intro') { if ($r === '1') $quizStart($quiz); elseif ($r === '2') $quiz['phase'] = 'top'; else $note = 'Invalid choice.'; continue; }
            if ($quiz['phase'] === 'top') { if ($r === '1') $quiz['phase'] = 'intro'; else $note = 'Invalid choice.'; continue; }
            if ($quiz['phase'] === 'q') {
                $q = $quiz['qs'][$quiz['i']];
                if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($q['opts'])) { $note = 'Invalid choice.'; continue; }
                $good = (int)$r === (int)$q['correct']; if ($good) $quiz['score']++;
                $quiz['fb'] = $good ? 'Correct!' : 'Wrong. Answer was '.$q['correct'].'.';
                if (++$quiz['i'] >= count($quiz['qs'])) $quiz['phase'] = 'done';
                $note = null; continue;
            }
            if ($quiz['phase'] === 'done') { if ($r === '1') { $quiz['round']++; $quizStart($quiz); } else $note = 'Invalid choice.'; continue; }
            $note = 'Invalid choice.'; continue;
        }
        if ($cur && $cur['node_type'] === 'recipient' && $recipient === null) { // this reply is the other number
            $num = ussd_normalize_msisdn($r);
            if ($num === null) { $note = 'Invalid number.'; continue; }
            $recipient = $num; $recipientRaw = preg_replace('/\D+/', '', $r); $note = null; continue;
        }
        [$options, $more] = $current();
        if ($more && $r === (string)(USSD_PAGE_SIZE + 1)) { $page++; $note = null; continue; }
        if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($options)) { $note = 'Invalid choice.'; continue; }
        $pick = $options[(int)$r - 1]; $path[] = (int)$r; $note = null;
        if (($pick['node_type'] === 'menu' && !empty($kids[(int)$pick['id']])) || in_array($pick['node_type'], ['catalog', 'recipient', 'quiz', 'sharedbundle'], true)) { $cur = $pick; $trail[] = $cur; $page = 0; if ($pick['node_type'] === 'quiz') $quiz = $quizNew($pick); if ($pick['node_type'] === 'sharedbundle') $sb = $sbNew(); continue; }
        // an Action item whose flow is ready (Live for customers; in the Simulator whatever its mode) is opened as that flow
        if ($pick['node_type'] === 'action') { $ak = ussd_action_flow_key($pick); $afl = $ak !== '' ? flow_get($ak) : null; if ($afl && $afl['status'] === 'active' && ($afl['mode'] === 'live' || isset($hooks['flow_sim']))) { $pick['node_type'] = 'flow'; $pick['offer_code'] = $ak; } }
        // a service flow: its own screens from here on (until it leaves, when the menu carries on with the remaining replies)
        if ($pick['node_type'] === 'flow') {
            $fk = trim((string)$pick['offer_code']); $fl = flow_get($fk);
            if (!$fl || $fl['status'] !== 'active') return ussd_result('This service is not available right now.', true, $path, 'flow_unavailable', $pick);
            $fr = flow_screen($fl['def'], array_slice($replies, $ix + 1), ussd_flow_hooks($fl, $hooks, $shortCode));
            if (isset($fr['exit_at'])) { array_pop($path); $ix += $fr['exit_at']; $note = null; continue; }
            return ussd_result(isset($fr['pending']) ? 'Processing...' : (string)$fr['text'], isset($fr['pending']) ? false : (bool)$fr['end'], $path, isset($fr['pending']) ? 'flow_call' : 'flow', $pick)
                + ['flow_key' => $fk, 'flow_pending' => $fr['pending'] ?? null];
        }
        // an offer: show what it is and ask for a yes before anything is bought
        if ($pick['node_type'] === 'offer') {
            $o = $offerLookup(trim((string)$pick['offer_code']));
            if (!$o) return ussd_result('Sorry, this offer is not available right now.', true, $path, 'offer_unavailable', $pick);
            if ($recipient !== null && trim((string)($o['offer_code_for_other'] ?? '')) === '') return ussd_result('Sorry, this offer cannot be bought for another number.', true, $path, 'offer_unavailable', $pick);
            $confirm = [$pick, $o, trim((string)($pick['full_label'] ?? '')) ?: (trim((string)($o['name'] ?? '')) ?: trim((string)$pick['prompt_text']))];
            continue;
        }
        // a leaf: action / end / an empty submenu — the session ends here
        if ($pick['node_type'] === 'action') return ussd_result($pick['prompt_text']."\nThis service is not available right now.", true, $path, 'action', $pick);
        return ussd_result(trim((string)($pick['body_text'] ?? '')) !== '' ? $pick['body_text'] : $pick['prompt_text'], true, $path, 'end', $pick);
    }
    if ($confirm !== null) {
        [$pick, $o, $name] = $confirm;
        $line = 'Buy '.$name.(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '').(!empty($o['validity_amount']) ? ' / '.$o['validity_amount'].' days' : '').($recipient ? ' for '.ussd_local_number($recipient) : '').'?';
        return ussd_result(($note ? $note."\n" : '').$line."\n1. Confirm\n2. Cancel\n0. Back", false, $path, 'confirm', $pick);
    }
    if ($cur && $cur['node_type'] === 'recipient' && $recipient === null) return ussd_result(($note ? $note."\n" : '')."Enter the other phone number:\n0. Back", false, $path, 'recipient', $cur);
    if ($sb !== null && $cur && $cur['node_type'] === 'sharedbundle') {
        $L = []; if ($note) $L[] = $note; $ph = $sb['phase'];
        if ($ph === 'main') return ussd_result(implode("\n", array_merge($L, ['Shared Bundle', '1. Buy Shared Bundle', '2. Add Sharing Number', '3. My Account', '0. Exit'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'list') {
            $slice = array_slice($sb['offers'], $sb['page'] * USSD_PAGE_SIZE, USSD_PAGE_SIZE); $more = count($sb['offers']) > ($sb['page'] + 1) * USSD_PAGE_SIZE; $L[] = 'Shared Bundle offers:';
            foreach ($slice as $i => $o) $L[] = ($i + 1).'. '.mb_strimwidth(trim((string)$o['name']) ?: (string)$o['offer_code'], 0, 18, '…').(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '');
            if ($more) $L[] = (USSD_PAGE_SIZE + 1).'. More';
            $L[] = '0. Back'; return ussd_result(implode("\n", $L), false, $path, 'sharedbundle', $cur);
        }
        if ($ph === 'confirm') { $row = $sb['offer']['row']; $nm = trim((string)($row['name'] ?? '')) ?: $sb['offer']['code'];
            $L[] = 'Press 1 to subscribe to Seddo '.$nm.(($row['one_time_price'] ?? '') !== '' ? ' for D'.$row['one_time_price'] : '').(!empty($row['validity_amount']) ? ', valid for '.$row['validity_amount'].' days' : '').', or press 0 to return to the menu.';
            return ussd_result(implode("\n", $L), false, $path, 'sharedbundle', $cur); }
        if ($ph === 'num') return ussd_result(implode("\n", array_merge($L, ['Enter the beneficiary Seddo number:', '0. Back'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'numconfirm') return ussd_result(implode("\n", array_merge($L, ['Please confirm that your Seddo number '.ussd_local_number($sb['number']).' is correct. Press 1 to confirm or press 0 to return.'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'account') return ussd_result(implode("\n", array_merge($L, ['My Account', '1. Check Balance', '2. My Seddo Numbers', '0. Back'])), false, $path, 'sharedbundle', $cur);
        return ussd_result(implode("\n", array_merge($L, [$sb['text'], '0. Back'])), false, $path, 'sharedbundle', $cur);
    }
    if ($quiz !== null && $cur && $cur['node_type'] === 'quiz') {
        $L = []; if ($note) $L[] = $note; $info = $quiz['info'] ?? [];
        if ($quiz['phase'] === 'intro') return ussd_result(implode("\n", array_merge($L, [$info['title'], 'Answer '.(int)$info['per_game'].' questions, 1 point each.', '1. Start', '2. Top players', '0. Exit'])), false, $path, 'quiz', $cur);
        if ($quiz['phase'] === 'top') return ussd_result(implode("\n", array_merge($L, [$quizTop($quiz['key']), '1. Back', '0. Exit'])), false, $path, 'quiz', $cur);
        if ($quiz['phase'] === 'q') {
            $q = $quiz['qs'][$quiz['i']];
            if ($quiz['fb']) $L[] = $quiz['fb'];
            $L[] = 'Q'.($quiz['i'] + 1).'/'.count($quiz['qs']).': '.$q['q'];
            foreach ($q['opts'] as $k => $o) $L[] = ($k + 1).'. '.$o;
            $L[] = '0. Exit';
            return ussd_result(implode("\n", $L), false, $path, 'quiz', $cur);
        }
        if ($quiz['phase'] === 'done') {
            $total = count($quiz['qs']); $won = $quiz['score'] >= (int)$info['win_score'];
            if ($quiz['fb']) $L[] = $quiz['fb'];
            $L[] = 'Game over! Score '.$quiz['score'].'/'.$total;
            if ($won && trim((string)$info['win_text']) !== '') $L[] = $info['win_text'];
            array_push($L, '1. Play again', '0. Exit');
            return ussd_result(implode("\n", $L), false, $path, 'quiz_done', $cur) + ['quiz' => ['key' => $quiz['key'], 'score' => $quiz['score'], 'total' => $total, 'round' => $quiz['round'], 'won' => $won]];
        }
        return ussd_result(implode("\n", array_merge($L, ['This game is not available right now.', '0. Back'])), false, $path, 'quiz', $cur);
    }
    [$options, $more] = $current();
    $lines = [$cur ? (($cur['node_type'] === 'recipient') ? 'Buy for '.ussd_local_number($recipient).':' : (trim((string)($cur['body_text'] ?? '')) !== '' ? $cur['body_text'] : $cur['prompt_text'])) : 'Welcome'];
    if ($note) array_unshift($lines, $note);
    foreach ($options as $i => $o) $lines[] = ($i + 1).'. '.$o['prompt_text'];
    if ($cur && $cur['node_type'] === 'catalog' && !$options) $lines[] = 'No offers are available right now.';
    if ($cur && $recipient !== null && !$options && $cur['node_type'] !== 'catalog') $lines[] = 'Nothing here can be bought for another number.';
    if ($more) $lines[] = (USSD_PAGE_SIZE + 1).'. More';
    if (count($trail) > 1) $lines[] = '0. Back';
    return ussd_result(implode("\n", $lines), false, $path, 'menu', $cur);
}

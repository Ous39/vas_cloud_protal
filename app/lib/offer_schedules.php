<?php
declare(strict_types=1);
// Schedules for offers (vas_offers in Hera): change an offer at a time — price, name, validity, data, on/off, sub-category… —
// and, if an end time is given, put the old values back at the end. Typical uses: a live offer "upgraded" for a week
// (more data, lower price), a promotion offer switched on at 00:00 and off at the end of the month.
//  * At the start the current values are kept (before_vals), then the new ones are written with update_record(), so
//    the change is in the offer's History like any edit.
//  * At the end each field goes back to its old value — but only if it still has the scheduled value. A field someone
//    changed by hand in the meantime is left as it is (and the note says so).
// Run every minute (schedule_cron), by the 5-minute job and when the Schedules page is opened. Messages: ussd_schedule_notify.

const OFFER_SCHED_FIELDS = ['status' => 'On / off', 'name' => 'Name', 'description' => 'Description', 'one_time_price' => 'Price', 'rental_price' => 'Rental price',
    'discount' => 'Discount', 'validity_amount' => 'Validity (days)', 'free_data' => 'Free data (MB)', 'speed_limit' => 'Speed limit', 'category' => 'Category', 'sub_category' => 'Sub-category'];
const OFFER_SCHED_STATES = ['waiting' => ['Waiting', 'secondary'], 'live' => ['Live', 'success'], 'applied' => ['Applied', 'primary'], 'ended' => ['Ended', 'dark'], 'cancelled' => ['Cancelled', 'light'], 'failed' => ['Failed', 'danger']];

function offer_sched_ready(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS offer_schedules (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        schema_name VARCHAR(64) NOT NULL,
        offer_id INT NOT NULL,
        offer_code VARCHAR(80) NOT NULL DEFAULT '',
        offer_name VARCHAR(190) NOT NULL DEFAULT '',
        changes TEXT NOT NULL,
        before_vals TEXT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NULL,
        state VARCHAR(12) NOT NULL DEFAULT 'waiting',
        note VARCHAR(500) NULL,
        reminded_at DATETIME NULL,
        created_by VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY st (state, start_at), KEY offer (schema_name, offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
// The fields that can be scheduled in this schema's vas_offers (the ones above that the table really has).
function offer_sched_fields(string $schema): array {
    $have = array_column(columns($schema, 'vas_offers'), 'name');
    return array_filter(OFFER_SCHED_FIELDS, fn($k) => in_array($k, $have, true), ARRAY_FILTER_USE_KEY);
}
function offer_sched_get(int $id): ?array { offer_sched_ready(); $st = portal_pdo()->prepare('SELECT * FROM offer_schedules WHERE id=?'); $st->execute([$id]); return $st->fetch() ?: null; }
function offer_sched_list(bool $openOnly = false, ?string $schema = null, ?int $offerId = null): array {
    offer_sched_ready(); $w = []; $p = [];
    if ($openOnly) $w[] = "state IN ('waiting','live','failed')";
    if ($schema !== null) { $w[] = 'schema_name=?'; $p[] = $schema; }
    if ($offerId !== null) { $w[] = 'offer_id=?'; $p[] = $offerId; }
    $st = portal_pdo()->prepare('SELECT * FROM offer_schedules'.($w ? ' WHERE '.implode(' AND ', $w) : '').' ORDER BY (state IN (\'waiting\',\'live\',\'failed\')) DESC, start_at DESC, id DESC LIMIT 300');
    $st->execute($p); return $st->fetchAll();
}
function offer_sched_set(int $id, array $f): void {
    $k = array_keys($f); portal_pdo()->prepare('UPDATE offer_schedules SET '.implode(',', array_map(fn($x) => "$x=?", $k)).' WHERE id=?')->execute([...array_values($f), $id]);
}
// One value as people read it: status 1/0 as On/Off, empty as (empty).
function offer_sched_show(string $field, $v): string {
    if ($field === 'status') return offer_is_active($v) ? 'On' : 'Off';
    $v = trim((string)$v); return $v === '' ? '(empty)' : $v;
}
// "Price 100 → 75 · Free data (MB) 1024 → 2048" (with the values before when known)
function offer_sched_describe(array $s, ?array $current = null): string {
    $ch = json_decode((string)$s['changes'], true) ?: []; $before = json_decode((string)($s['before_vals'] ?? ''), true) ?: [];
    $out = [];
    foreach ($ch as $f => $v) {
        $from = array_key_exists($f, $before) ? $before[$f] : ($current[$f] ?? null);
        $out[] = (OFFER_SCHED_FIELDS[$f] ?? $f).' '.($from !== null || array_key_exists($f, $before) ? offer_sched_show($f, $from).' → ' : '→ ').offer_sched_show($f, $v);
    }
    return implode(' · ', $out);
}
// What the form sent, cleaned: only the schedulable fields that were filled in and differ from the offer now.
function offer_sched_clean_changes(string $schema, array $offer, array $in): array {
    $fields = offer_sched_fields($schema); $out = [];
    foreach ($fields as $f => $label) {
        if (!array_key_exists($f, $in)) continue; $v = trim((string)$in[$f]); if ($v === '') continue;
        if ($f === 'status') { if (!in_array($v, ['1', '0'], true)) continue; }
        elseif ($f === 'free_data') $v = normalize_free_data_to_mb($v);
        elseif (in_array($f, ['one_time_price', 'validity_amount'], true) && !is_numeric($v)) throw new RuntimeException($label.' must be a number.');
        $cur = $offer[$f] ?? null;
        $same = $f === 'status' ? offer_is_active($cur) === ($v === '1') : trim((string)$cur) === $v;
        if (!$same) $out[$f] = $v;
    }
    return $out;
}
function offer_sched_add(string $schema, int $offerId, array $changes, string $start, string $end = ''): int {
    offer_sched_ready();
    $offer = fetch_record($schema, 'vas_offers', ['id' => $offerId]) ?? throw new RuntimeException('That offer is no longer in '.$schema.'.');
    $changes = offer_sched_clean_changes($schema, $offer, $changes);
    if (!$changes) throw new RuntimeException('Nothing to change: fill in at least one new value that is different from the offer now.');
    $from = menu_parse_when($start) ?? date('Y-m-d H:i:00'); $until = menu_parse_when($end);
    if ($until !== null && $until <= $from) throw new RuntimeException('The end time must be after the start time.');
    if ($until !== null && $until <= date('Y-m-d H:i:s')) throw new RuntimeException('The end time is already past.');
    foreach (offer_sched_list(true, $schema, $offerId) as $o) {
        if ($o['state'] === 'failed') continue;
        $overlap = ($o['end_at'] === null || $o['end_at'] > $from) && ($until === null || $o['start_at'] < $until);
        $both = array_intersect(array_keys(json_decode((string)$o['changes'], true) ?: []), array_keys($changes));
        if ($overlap && $both) throw new RuntimeException('Another schedule already changes '.implode(', ', array_map(fn($f) => OFFER_SCHED_FIELDS[$f] ?? $f, $both)).' of this offer at that time (from '.substr($o['start_at'], 0, 16).'). Cancel it first.');
    }
    portal_pdo()->prepare('INSERT INTO offer_schedules(schema_name, offer_id, offer_code, offer_name, changes, start_at, end_at, created_by) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$schema, $offerId, (string)($offer['offer_code'] ?? ''), mb_substr((string)($offer['name'] ?? ''), 0, 190), json_encode($changes, JSON_UNESCAPED_UNICODE), $from, $until, user()['username'] ?? null]);
    $id = (int)portal_pdo()->lastInsertId();
    audit('offer_schedule_add', $schema, 'vas_offers', json_encode(['id' => $offerId]), json_encode(['schedule' => $id, 'from' => $from, 'until' => $until, 'changes' => $changes], JSON_UNESCAPED_UNICODE));
    return $id;
}
function offer_sched_start(array $s): void {
    $offer = fetch_record($s['schema_name'], 'vas_offers', ['id' => (int)$s['offer_id']]) ?? throw new RuntimeException('the offer is no longer in '.$s['schema_name']);
    $changes = json_decode((string)$s['changes'], true) ?: []; $before = [];
    foreach ($changes as $f => $v) $before[$f] = $offer[$f] ?? null;
    offer_sched_set((int)$s['id'], ['before_vals' => json_encode($before, JSON_UNESCAPED_UNICODE)]); // kept first, so the end can always put them back
    update_record($s['schema_name'], 'vas_offers', ['id' => (int)$s['offer_id']], $changes);
    $s['before_vals'] = json_encode($before);
    offer_sched_set((int)$s['id'], ['state' => $s['end_at'] ? 'live' : 'applied', 'note' => mb_substr('Changed '.date('Y-m-d H:i').': '.offer_sched_describe($s), 0, 500)]);
}
function offer_sched_end(array $s): void {
    $offer = fetch_record($s['schema_name'], 'vas_offers', ['id' => (int)$s['offer_id']]) ?? throw new RuntimeException('the offer is no longer in '.$s['schema_name']);
    $changes = json_decode((string)$s['changes'], true) ?: []; $before = json_decode((string)($s['before_vals'] ?? ''), true) ?: [];
    $back = []; $kept = [];
    foreach ($changes as $f => $v) {
        if (!array_key_exists($f, $before)) continue;
        $now = $offer[$f] ?? null; $still = $f === 'status' ? offer_is_active($now) === offer_is_active($v) : trim((string)$now) === trim((string)$v);
        if ($still) $back[$f] = $before[$f]; else $kept[] = OFFER_SCHED_FIELDS[$f] ?? $f;
    }
    if ($back) update_record($s['schema_name'], 'vas_offers', ['id' => (int)$s['offer_id']], array_map(fn($v) => $v ?? '', $back));
    $parts = []; foreach ($back as $f => $v) $parts[] = (OFFER_SCHED_FIELDS[$f] ?? $f).' back to '.offer_sched_show($f, $v);
    $note = 'Ended '.date('Y-m-d H:i').': '.($parts ? implode(' · ', $parts) : 'nothing to put back').($kept ? '. Left as they are (changed by hand meanwhile): '.implode(', ', $kept) : '');
    offer_sched_set((int)$s['id'], ['state' => 'ended', 'note' => mb_substr($note, 0, 500)]);
}
function offer_sched_notify(int $id, string $what): void {
    if (!function_exists('ussd_schedule_notify') || !($s = offer_sched_get($id))) return;
    $o = 'offer '.$s['offer_code'].' “'.$s['offer_name'].'” ('.$s['schema_name'].')'; $note = trim((string)$s['note']);
    $text = match ($what) {
        'live' => '✅ Changed '.$o.': '.offer_sched_describe($s).'. '.($s['end_at'] ? 'Goes back '.substr($s['end_at'], 0, 16).'.' : 'No end time: it stays.'),
        'ended' => '⏹ Schedule ended for '.$o.'. '.$note,
        'ended_now' => '⏹ Schedule for '.$o.' ended by hand ('.(user()['username'] ?? 'someone').'). '.$note,
        default => '❌ Schedule for '.$o.' FAILED: '.$note.' — check it in Schedules now.',
    };
    ussd_schedule_notify($text, 'Offer schedule');
}
// Start and end what is due (one run at a time). Returns ['started' => n, 'ended' => n, 'failed' => n].
function offer_sched_apply(?string $now = null): array {
    offer_sched_ready(); $now = $now ?? date('Y-m-d H:i:s'); $r = ['started' => 0, 'ended' => 0, 'failed' => 0];
    $db = portal_pdo(); try { if (!(int)$db->query("SELECT GET_LOCK('vas_offer_sched_apply', 0)")->fetchColumn()) return $r; } catch (Throwable $e) {}
    try {
        $due = $db->prepare("SELECT * FROM offer_schedules WHERE (state='waiting' AND start_at<=?) OR (state='live' AND end_at IS NOT NULL AND end_at<=?) ORDER BY start_at, id"); $due->execute([$now, $now]);
        foreach ($due->fetchAll() as $s) {
            try {
                if ($s['state'] === 'waiting') {
                    if ($s['end_at'] !== null && $s['end_at'] <= $now) { offer_sched_set((int)$s['id'], ['state' => 'ended', 'note' => 'Start and end both passed before it could run; nothing was changed.']); continue; }
                    offer_sched_start($s); $r['started']++; offer_sched_notify((int)$s['id'], 'live');
                } else { offer_sched_end($s); $r['ended']++; offer_sched_notify((int)$s['id'], 'ended'); }
            } catch (Throwable $e) {
                $r['failed']++; offer_sched_set((int)$s['id'], ['state' => 'failed', 'note' => mb_substr(($s['state'] === 'waiting' ? 'Could not change the offer: ' : 'Could not put it back: ').$e->getMessage(), 0, 500)]);
                error_log('offer schedule '.$s['id'].': '.$e->getMessage()); offer_sched_notify((int)$s['id'], 'failed');
            }
        }
        if ($r['started'] + $r['ended'] + $r['failed']) audit('offer_schedule_applied', null, 'vas_offers', null, json_encode($r));
        try { offer_sched_remind($now); } catch (Throwable $e) { error_log('offer schedule reminder: '.$e->getMessage()); }
        return $r;
    } finally { try { $db->query("SELECT RELEASE_LOCK('vas_offer_sched_apply')"); } catch (Throwable $e) {} }
}
// About an hour before the start: what will change, after checking that the offer is still there.
function offer_sched_remind(string $now): void {
    if (!function_exists('ussd_schedule_notify')) return;
    $st = portal_pdo()->prepare("SELECT * FROM offer_schedules WHERE state='waiting' AND reminded_at IS NULL AND start_at>? AND start_at<=? ORDER BY start_at");
    $st->execute([$now, date('Y-m-d H:i:s', strtotime($now) + 65 * 60)]);
    foreach ($st->fetchAll() as $s) {
        $offer = null; try { $offer = fetch_record($s['schema_name'], 'vas_offers', ['id' => (int)$s['offer_id']]); } catch (Throwable $e) {}
        offer_sched_set((int)$s['id'], ['reminded_at' => date('Y-m-d H:i:s')]);
        ussd_schedule_notify('⏰ In about '.max(1, (int)round((strtotime($s['start_at']) - strtotime($now)) / 60)).' min ('.substr($s['start_at'], 0, 16).'): offer '.$s['offer_code'].' “'.$s['offer_name'].'” ('.$s['schema_name'].') changes: '
            .offer_sched_describe($s, $offer).'.'.($s['end_at'] ? ' It goes back '.substr($s['end_at'], 0, 16).'.' : ' No end time.').($offer ? '' : ' ⚠ The offer is NO LONGER in '.$s['schema_name'].' — the schedule will fail.'), 'Offer schedule');
    }
}
function offer_sched_tick(): void { try { cached('offer_schedule_tick', 30, fn() => offer_sched_apply()); } catch (Throwable $e) { error_log('offer schedule: '.$e->getMessage()); } }
// Cancel a waiting schedule, or end a live one now (the old values go back).
function offer_sched_stop(int $id): string {
    $s = offer_sched_get($id) ?? throw new RuntimeException('That schedule no longer exists.');
    if ($s['state'] === 'waiting') { offer_sched_set($id, ['state' => 'cancelled', 'note' => 'Cancelled '.date('Y-m-d H:i').' by '.(user()['username'] ?? '?')]); audit('offer_schedule_cancel', $s['schema_name'], 'vas_offers', json_encode(['id' => (int)$s['offer_id']]), (string)$id); return 'cancelled'; }
    if ($s['state'] === 'live') { offer_sched_end($s); audit('offer_schedule_end_now', $s['schema_name'], 'vas_offers', json_encode(['id' => (int)$s['offer_id']]), (string)$id); offer_sched_notify($id, 'ended_now'); return 'ended'; }
    throw new RuntimeException('That schedule has already finished.');
}
// Try a failed one again: one that could not change the offer waits again (runs now if its time is past); one that could not put it back is live again (ends now if its end is past).
function offer_sched_retry(int $id): array {
    $s = offer_sched_get($id) ?? throw new RuntimeException('That schedule no longer exists.');
    if ($s['state'] !== 'failed') throw new RuntimeException('Only a failed schedule can be tried again.');
    offer_sched_set($id, ['state' => str_starts_with((string)$s['note'], 'Could not put it back') ? 'live' : 'waiting', 'note' => 'Tried again '.date('Y-m-d H:i').' by '.(user()['username'] ?? '?')]);
    audit('offer_schedule_retry', $s['schema_name'], 'vas_offers', json_encode(['id' => (int)$s['offer_id']]), (string)$id);
    return offer_sched_apply();
}

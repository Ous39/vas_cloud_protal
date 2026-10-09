<?php
declare(strict_types=1);
// Quiz games played from a menu item.

// ---- Quiz games: sets of questions managed on the USSD Quiz page, played from a "quiz" menu item ----
const USSD_QUIZ_BLOCK_MAX = 150; // longest question + options a screen can carry once the feedback and "0. Exit" lines are added
function ussd_quiz_tables(): void {
    static $done = false; if ($done) return;
    $db = portal_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quizzes (
        quiz_key VARCHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(80) NOT NULL, per_game TINYINT NOT NULL DEFAULT 5, win_score TINYINT NOT NULL DEFAULT 4,
        win_text VARCHAR(120) NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quiz_questions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, quiz_key VARCHAR(40) NOT NULL, question VARCHAR(160) NOT NULL,
        opt1 VARCHAR(40) NOT NULL, opt2 VARCHAR(40) NOT NULL, opt3 VARCHAR(40) NULL, opt4 VARCHAR(40) NULL, correct TINYINT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_quiz_status (quiz_key, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quiz_plays (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, call_id VARCHAR(120) NOT NULL, round INT NOT NULL, quiz_key VARCHAR(40) NOT NULL, msisdn VARCHAR(30) NOT NULL,
        score TINYINT NOT NULL, total TINYINT NOT NULL, won TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_call_round (call_id, round), INDEX idx_quiz_time (quiz_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
function ussd_quiz_info(string $key): ?array {
    try { ussd_quiz_tables(); $st = portal_pdo()->prepare("SELECT * FROM ussd_quizzes WHERE quiz_key=? AND status='active'"); $st->execute([$key]); return $st->fetch() ?: null; }
    catch (Throwable $e) { error_log('ussd quiz info: '.$e->getMessage()); return null; }
}
// $n questions for one round, in an order that depends only on the call id and the round, so the same replies always rebuild the same game.
function ussd_quiz_questions(string $key, string $seed, int $round, int $n): array {
    try {
        ussd_quiz_tables(); $st = portal_pdo()->prepare("SELECT * FROM ussd_quiz_questions WHERE quiz_key=? AND status='active'"); $st->execute([$key]); $rows = $st->fetchAll();
    } catch (Throwable $e) { error_log('ussd quiz questions: '.$e->getMessage()); return []; }
    usort($rows, fn($a, $b) => strcmp(md5($seed.'|'.$round.'|'.$a['id']), md5($seed.'|'.$round.'|'.$b['id'])));
    $out = [];
    foreach (array_slice($rows, 0, max(1, $n)) as $r) {
        $opts = array_values(array_filter([$r['opt1'], $r['opt2'], $r['opt3'], $r['opt4']], fn($o) => $o !== null && trim((string)$o) !== ''));
        $out[] = ['id' => (int)$r['id'], 'q' => $r['question'], 'opts' => $opts, 'correct' => (int)$r['correct']];
    }
    return $out;
}
function ussd_quiz_mask(string $msisdn): string { $d = ussd_local_number($msisdn); return strlen($d) > 6 ? substr($d, 0, 3).'***'.substr($d, -3) : '***'; }
function ussd_quiz_top(string $key): string {
    try {
        ussd_quiz_tables();
        $st = portal_pdo()->prepare('SELECT msisdn, SUM(score) AS pts FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY msisdn ORDER BY pts DESC, MIN(created_at) LIMIT 5');
        $st->execute([$key]); $rows = $st->fetchAll();
    } catch (Throwable $e) { return 'Top players: not available right now.'; }
    if (!$rows) return "Top players this week:\nNo scores yet. Be the first!";
    $lines = ['Top players this week:']; foreach ($rows as $i => $r) $lines[] = ($i + 1).'. '.ussd_quiz_mask((string)$r['msisdn']).' - '.(int)$r['pts'];
    return implode("\n", $lines);
}
// Records a finished round (once per call and round, however often Mobius repeats the request).
function ussd_quiz_record(array $screen, string $msisdn, string $callId): void {
    if (($screen['kind'] ?? '') !== 'quiz_done' || empty($screen['quiz']) || $callId === '') return;
    $q = $screen['quiz'];
    try {
        ussd_quiz_tables();
        portal_pdo()->prepare('INSERT IGNORE INTO ussd_quiz_plays(call_id,round,quiz_key,msisdn,score,total,won) VALUES(?,?,?,?,?,?,?)')
            ->execute([substr($callId, 0, 120), (int)$q['round'], $q['key'], preg_replace('/\D+/', '', $msisdn), (int)$q['score'], (int)$q['total'], $q['won'] ? 1 : 0]);
    } catch (Throwable $e) { error_log('ussd quiz record: '.$e->getMessage()); }
}
// Checks one question's fields and returns them ready to store. Throws a message an editor can act on.
function quiz_question_fields(string $question, array $opts, int $correct): array {
    $question = trim($question); $opts = array_values(array_filter(array_map('trim', $opts), fn($o) => $o !== ''));
    if (mb_strlen($question) < 5 || mb_strlen($question) > 150) throw new RuntimeException('The question must be 5 to 150 characters.');
    if (count($opts) < 2 || count($opts) > 4) throw new RuntimeException('Give 2 to 4 answers.');
    foreach ($opts as $o) if (mb_strlen($o) > 30) throw new RuntimeException('An answer can be at most 30 characters ("'.mb_substr($o, 0, 20).'…").');
    if ($correct < 1 || $correct > count($opts)) throw new RuntimeException('The right answer must be a number from 1 to '.count($opts).'.');
    $len = 6 + mb_strlen($question) + 1; foreach ($opts as $o) $len += 4 + mb_strlen($o);
    if ($len + 7 > USSD_QUIZ_BLOCK_MAX) throw new RuntimeException('Too long for one phone screen ('.($len + 7).' characters, at most '.USSD_QUIZ_BLOCK_MAX.'): shorten the question or the answers.');
    return [$question, $opts, $correct];
}
function save_quiz(array $d): string {
    ussd_quiz_tables();
    $key = trim((string)($d['quiz_key'] ?? '')); if (!preg_match('/^[a-z0-9_-]{2,40}$/', $key)) throw new RuntimeException('The quiz key is 2 to 40 characters: lowercase letters, digits, - and _.');
    $title = trim((string)($d['title'] ?? '')); if ($title === '' || mb_strlen($title) > 80) throw new RuntimeException('Give the quiz a title (up to 80 characters).');
    $per = filter_var($d['per_game'] ?? null, FILTER_VALIDATE_INT); if ($per === false || $per < 3 || $per > 10) throw new RuntimeException('Questions per game: 3 to 10.');
    $win = filter_var($d['win_score'] ?? null, FILTER_VALIDATE_INT); if ($win === false || $win < 1 || $win > $per) throw new RuntimeException('The winning score must be between 1 and the number of questions.');
    $text = trim((string)($d['win_text'] ?? '')); if (mb_strlen($text) > 120) throw new RuntimeException('The winner message is at most 120 characters.');
    $status = ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    portal_pdo()->prepare('INSERT INTO ussd_quizzes(quiz_key,title,per_game,win_score,win_text,status) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), per_game=VALUES(per_game), win_score=VALUES(win_score), win_text=VALUES(win_text), status=VALUES(status)')
        ->execute([$key, $title, $per, $win, $text !== '' ? $text : null, $status]);
    audit('ussd_quiz_save', null, 'ussd_quizzes', $key, json_encode(['title' => $title, 'per_game' => $per, 'win' => $win, 'status' => $status]));
    return $key;
}
function quiz_must_exist(string $key): void {
    $st = portal_pdo()->prepare('SELECT 1 FROM ussd_quizzes WHERE quiz_key=?'); $st->execute([$key]);
    if (!$st->fetchColumn()) throw new RuntimeException('That quiz does not exist.');
}
function save_quiz_question(array $d): string {
    ussd_quiz_tables(); $key = trim((string)($d['quiz_key'] ?? '')); quiz_must_exist($key);
    [$q, $opts, $correct] = quiz_question_fields((string)($d['question'] ?? ''), [$d['opt1'] ?? '', $d['opt2'] ?? '', $d['opt3'] ?? '', $d['opt4'] ?? ''], (int)($d['correct'] ?? 0));
    $vals = [$q, $opts[0], $opts[1], $opts[2] ?? null, $opts[3] ?? null, $correct]; $id = (int)($d['id'] ?? 0); $db = portal_pdo();
    if ($id > 0) { $db->prepare('UPDATE ussd_quiz_questions SET question=?,opt1=?,opt2=?,opt3=?,opt4=?,correct=? WHERE id=? AND quiz_key=?')->execute([...$vals, $id, $key]); }
    else { $db->prepare('INSERT INTO ussd_quiz_questions(question,opt1,opt2,opt3,opt4,correct,quiz_key,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([...$vals, $key, user()['username'] ?? null]); $id = (int)$db->lastInsertId(); }
    audit('ussd_quiz_question_save', null, 'ussd_quiz_questions', (string)$id, $key);
    return $key;
}
function toggle_quiz_question(int $id): string {
    ussd_quiz_tables(); $db = portal_pdo(); $st = $db->prepare('SELECT quiz_key, status FROM ussd_quiz_questions WHERE id=?'); $st->execute([$id]); $r = $st->fetch();
    if (!$r) throw new RuntimeException('That question is gone.');
    $db->prepare('UPDATE ussd_quiz_questions SET status=? WHERE id=?')->execute([$r['status'] === 'active' ? 'inactive' : 'active', $id]);
    audit('ussd_quiz_question_toggle', null, 'ussd_quiz_questions', (string)$id, $r['quiz_key']);
    return $r['quiz_key'];
}
// One question per line:  Question | answer 1 | answer 2 | answer 3 | answer 4 | number of the right answer
function import_quiz_questions(string $key, string $text): int {
    ussd_quiz_tables(); quiz_must_exist($key); $rows = [];
    foreach (preg_split('/\R/', $text) as $n => $line) {
        $line = trim($line); if ($line === '' || $line[0] === '#') continue;
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 4 || !ctype_digit(end($p))) throw new RuntimeException('Line '.($n + 1).': write  Question | answer | answer | … | number of the right answer');
        $correct = (int)array_pop($p); $q = array_shift($p);
        try { $rows[] = quiz_question_fields($q, $p, $correct); } catch (RuntimeException $e) { throw new RuntimeException('Line '.($n + 1).': '.$e->getMessage()); }
    }
    if (!$rows) throw new RuntimeException('Nothing to import.');
    if (count($rows) > 200) throw new RuntimeException('At most 200 questions per import.');
    $db = portal_pdo(); $db->beginTransaction();
    try {
        $ins = $db->prepare('INSERT INTO ussd_quiz_questions(question,opt1,opt2,opt3,opt4,correct,quiz_key,created_by) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($rows as [$q, $o, $c]) $ins->execute([$q, $o[0], $o[1], $o[2] ?? null, $o[3] ?? null, $c, $key, user()['username'] ?? null]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('ussd_quiz_import', null, 'ussd_quiz_questions', null, $key.' +'.count($rows));
    return count($rows);
}
// The offers a catalogue-list node shows: active ones in its sub-categories, cheapest first, each code once.
function ussd_catalog_offers(array $node): array {
    $subs = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($node['catalog_filter'] ?? '')))));
    if (!$subs) return [];
    try {
        $st = pdo(USSD_OFFER_SCHEMA)->prepare('SELECT offer_code, offer_code_for_other, name, sub_category, one_time_price, validity_amount FROM vas_offers WHERE sub_category IN ('.implode(',', array_fill(0, count($subs), '?')).') AND '.OFFER_ACTIVE_SQL." AND (deleted_at IS NULL OR deleted_at='') AND offer_code IS NOT NULL AND offer_code<>'' ORDER BY id DESC LIMIT 200");
        $st->execute($subs);
        $rows = []; foreach ($st->fetchAll() as $o) if (!isset($rows[$o['offer_code']])) $rows[$o['offer_code']] = $o;
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [(float)$a['one_time_price'], (string)$a['name']] <=> [(float)$b['one_time_price'], (string)$b['name']]);
        return array_slice($rows, 0, 60);
    } catch (Throwable $e) { error_log('ussd catalog: '.$e->getMessage()); return []; }
}
// The USSD menu sells from the TEST catalogue only for now (the live catalogue is deliberately not read).
const USSD_OFFER_SCHEMA = 'HeraTesting';
function ussd_offer_lookup(string $code): ?array {
    if ($code === '') return null;
    try {
        $st = pdo(USSD_OFFER_SCHEMA)->prepare("SELECT name, one_time_price, validity_amount, status, vendor, offer_code_for_other FROM vas_offers WHERE offer_code=? AND (deleted_at IS NULL OR deleted_at='') ORDER BY id DESC");
        $st->execute([$code]);
        foreach ($st->fetchAll() as $o) if (offer_is_active($o['status'])) return $o;
    } catch (Throwable $e) { error_log('ussd offer lookup: '.$e->getMessage()); }
    return null;
}

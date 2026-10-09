<?php
declare(strict_types=1);
// Database Tables browser: large-table guard, listing, export, SQL console, confirmations.

// ===================== Large-table scan guard (generic Database Tables browser) =====================
// Dedicated pages (Subscriptions, Complaint Investigation) already require a bounded/indexed lookup
// for the two tables known to be huge (subscription ~89M rows, audit_log ~227M rows). This guard
// applies the same protection generically to the plain table browser/export, so ANY table that turns
// out to be this large — including ones added after this was written — gets it automatically instead
// of relying on someone remembering to build a dedicated page first.
const LARGE_TABLE_ROW_THRESHOLD = 1000000;

function indexed_columns(string $schema, string $table): array {
    $st = pdo($schema)->prepare('SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $st->execute([$schema, $table]);
    return array_column($st->fetchAll(), 'COLUMN_NAME');
}

// Returns null if the browse/export is safe to run as-is, or ['level'=>'block'|'warn','message'=>...]
// if it's a large table being scanned without an index to lean on. 'block' means the caller should
// not run the query at all; 'warn' means it's safe to run but the UI should say why it may be slow.
function large_table_guard(string $schema, string $table, array $filters): ?array {
    if (approx_table_count($schema, $table) < LARGE_TABLE_ROW_THRESHOLD) return null;
    if (!$filters) {
        return ['level' => 'block', 'message' => 'This table has an estimated '.number_format(approx_table_count($schema,$table)).' rows. Browsing or exporting it without a filter would scan the whole table — add a filter (ideally on an indexed column) first.'];
    }
    $indexed = indexed_columns($schema, $table);
    $filteredCols = array_unique(array_filter(array_column($filters, 'col')));
    $anyIndexed = false;
    foreach ($filteredCols as $c) if (in_array($c, $indexed, true)) { $anyIndexed = true; break; }
    if (!$anyIndexed) {
        return ['level' => 'warn', 'message' => 'This table has an estimated '.number_format(approx_table_count($schema,$table)).' rows and none of your filter columns ('.implode(', ',$filteredCols).') has a database index — this query will scan the full table and may be slow.'];
    }
    return null;
}
function list_records(string $schema,string $table,array $filters,int $page,int $perPage): array {
    $params=[]; $where=table_filter_where($schema,$table,$filters,$params);
    $sql='FROM '.ident($table).$where;
    $st=pdo($schema)->prepare('SELECT COUNT(*) c '.$sql); $st->execute($params); $total=(int)$st->fetch()['c'];
    $offset=max(0,($page-1)*$perPage); $q='SELECT * '.$sql.order_by_pk($schema,$table).' LIMIT '.(int)$perPage.' OFFSET '.(int)$offset; $st=pdo($schema)->prepare($q); $st->execute($params); return ['rows'=>$st->fetchAll(),'total'=>$total];
}
const PAGE_SIZE_OPTIONS = [10, 20, 25];
function resolve_page_size(array $get, int $default = 25): int {
    $v = (int)($get['per_page'] ?? $default);
    return in_array($v, PAGE_SIZE_OPTIONS, true) ? $v : $default;
}
function export_records(string $schema,string $table,array $filters,int $limit=10000): array {
    $params=[]; $where=table_filter_where($schema,$table,$filters,$params);
    $q='SELECT * FROM '.ident($table).$where.order_by_pk($schema,$table).' LIMIT '.(int)$limit;
    $st=pdo($schema)->prepare($q); $st->execute($params); return $st->fetchAll();
}
function normalize_value($v){ return $v === '' ? null : $v; }
// What an update really changed (column => [from, to]), for the audit trail / Offer history. Sensitive
// columns are recorded as changed but never with their values.
function changed_fields(?array $before, array $data): array {
    $out = [];
    foreach ($data as $col => $new) {
        $old = $before[$col] ?? null; $newN = normalize_value($new);
        if ((string)$old === (string)$newN) continue;
        $out[$col] = is_sensitive_column((string)$col) ? ['from' => '••••', 'to' => '••••'] : ['from' => $old, 'to' => $newN];
    }
    return $out;
}
function insert_record(string $schema,string $table,array $data): void { $cols=editable_columns($schema,$table,false); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(array_key_exists($c['name'],$data)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=normalize_value($data[$c['name']]); }} if(!$names) throw new RuntimeException('Nothing to insert'); pdo($schema)->prepare('INSERT INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); $newId=(int)pdo($schema)->lastInsertId(); audit('insert',$schema,$table,$newId>0?json_encode(['id'=>$newId]):null,json_encode(redact_row($data))); }
function update_record(string $schema,string $table,array $keys,array $data): void { $set=[];$params=[]; foreach(editable_columns($schema,$table,false) as $c){ if(in_array($c['name'],primary_columns($schema,$table),true)) continue; if(array_key_exists($c['name'],$data)){ $set[]=ident($c['name']).'=?'; $params[]=normalize_value($data[$c['name']]); }} if(!$set) throw new RuntimeException('Nothing to update'); $before=fetch_record($schema,$table,$keys); $where=build_pk_where($schema,$table,$keys,$params); pdo($schema)->prepare('UPDATE '.ident($table).' SET '.implode(',',$set).' WHERE '.$where.' LIMIT 1')->execute($params); audit('update',$schema,$table,json_encode($keys),json_encode(['changed'=>changed_fields($before,$data)])); }
function copy_record(string $from,string $to,string $table,array $keys,array $overrides=[]): void { assert_copy_schemas($from,$to,false); if(!table_exists($to,$table)) throw new RuntimeException('Table does not exist in the destination schema.'); $row=fetch_record($from,$table,$keys); if(!$row) throw new RuntimeException('Source record not found'); foreach($overrides as $k=>$v) if(array_key_exists($k,$row)) $row[$k]=normalize_value($v); $cols=columns($to,$table); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(is_auto_col($c)) continue; if(array_key_exists($c['name'],$row)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=$row[$c['name']]; }} pdo($to)->prepare('REPLACE INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); audit('copy_record',$to,$table,json_encode($keys),"from=$from to=$to"); }
function assert_copy_schemas(string $from, string $to, bool $mustDiffer): void {
    if (!in_array($from, user_schemas(), true) || !in_array($to, user_schemas(), true)) throw new RuntimeException('Unknown source or destination schema, or you do not have access to it.');
    if ($mustDiffer && $from === $to) throw new RuntimeException('Source and destination must be different schemas.');
}
function sync_table(string $from,string $to,string $table): int {
    assert_copy_schemas($from, $to, true);
    if (!table_exists($from, $table) || !table_exists($to, $table)) throw new RuntimeException('Table must exist in both schemas.');
    // Destructive TRUNCATE+reload is only ever allowed when the destination is HeraTesting.
    // HeraProduction can only be updated via merge_table(), which never deletes existing rows.
    if (is_protected_schema($to)) throw new RuntimeException('Full-table sync cannot target '.$to.' (would truncate live data). Use Merge instead.');
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        pdo($to)->exec('TRUNCATE TABLE '.ident($table));
        $affected=pdo($to)->exec('INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table));
    } finally { pdo($to)->exec('SET FOREIGN_KEY_CHECKS=1'); } // this connection is reused: never leave the checks off
    audit('sync_table',$to,$table,null,"from=$from rows=$affected");
    return (int)$affected;
}

function merge_table(string $from,string $to,string $table): int {
    assert_copy_schemas($from, $to, true);
    if (!table_exists($from, $table) || !table_exists($to, $table)) throw new RuntimeException('Table must exist in both schemas.');
    // Non-destructive alternative: upserts rows by primary key, never deletes/truncates.
    $pk = primary_columns($from,$table);
    if (!$pk) throw new RuntimeException('Table has no primary key; merge requires one to avoid duplicating rows.');
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    $updateCols = array_diff($cols, $pk);
    $updateSql = implode(',', array_map(fn($c)=>ident($c).'=VALUES('.ident($c).')', $updateCols));
    $sql = 'INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table);
    $sql .= $updateSql !== '' ? ' ON DUPLICATE KEY UPDATE '.$updateSql : '';
    $affected=pdo($to)->exec($sql);
    audit('merge_table',$to,$table,null,"from=$from rows=$affected");
    return (int)$affected;
}

function all_channels(): array { return portal_pdo()->query('SELECT id, channel_type, short_code, service_name, provider, status FROM portal_short_codes ORDER BY short_code, channel_type, service_name')->fetchAll(); }
function project_channel_ids(int $projectId): array { $st=portal_pdo()->prepare('SELECT channel_id FROM portal_project_channels WHERE project_id=?'); $st->execute([$projectId]); return array_map('intval', array_column($st->fetchAll(),'channel_id')); }
function project_channels_label(int $projectId): string { $st=portal_pdo()->prepare('SELECT c.channel_type, c.short_code, c.service_name FROM portal_project_channels pc JOIN portal_short_codes c ON c.id=pc.channel_id WHERE pc.project_id=? ORDER BY c.short_code,c.channel_type'); $st->execute([$projectId]); $items=[]; foreach($st->fetchAll() as $r) $items[]=$r['channel_type'].' '.$r['short_code'].' - '.$r['service_name']; return implode(', ', $items); }
function save_shortcode(array $data, ?int $id=null): void { $payload=[normalize_value($data['channel_type']??'USSD'), normalize_value($data['short_code']??''), normalize_value($data['service_name']??''), normalize_value($data['provider']??''), normalize_value($data['status']??'Pending'), normalize_value($data['description']??'')]; if(!$payload[1] || !$payload[2]) throw new RuntimeException('Short Code and Service Name are required.'); if($id){ $payload[]=$id; portal_pdo()->prepare('UPDATE portal_short_codes SET channel_type=?, short_code=?, service_name=?, provider=?, status=?, description=?, updated_at=NOW() WHERE id=?')->execute($payload); audit('save_shortcode','vas_portal','portal_short_codes',(string)$id,json_encode($data)); } else { portal_pdo()->prepare('INSERT INTO portal_short_codes(channel_type,short_code,service_name,provider,status,description) VALUES(?,?,?,?,?,?)')->execute($payload); audit('save_shortcode','vas_portal','portal_short_codes',null,json_encode($data)); } }
function save_project(array $data, array $channelIds=[], ?int $id=null): void { $payload=[normalize_value($data['project_name']??''), normalize_value($data['short_code']??''), normalize_value($data['status']??'Planning'), normalize_value($data['start_date']??''), normalize_value($data['launch_date']??''), normalize_value($data['description']??'')]; if(!$payload[0]) throw new RuntimeException('Project Name is required.'); $db=portal_pdo(); if($id){ $payload[]=$id; $db->prepare('UPDATE portal_projects SET project_name=?, short_code=?, status=?, start_date=?, launch_date=?, description=?, updated_at=NOW() WHERE id=?')->execute($payload); } else { $db->prepare('INSERT INTO portal_projects(project_name,short_code,status,start_date,launch_date,description) VALUES(?,?,?,?,?,?)')->execute($payload); $id=(int)$db->lastInsertId(); } $db->prepare('DELETE FROM portal_project_channels WHERE project_id=?')->execute([$id]); foreach($channelIds as $cid){ $cid=(int)$cid; if($cid<=0) continue; $st=$db->prepare('SELECT short_code FROM portal_short_codes WHERE id=?'); $st->execute([$cid]); $row=$st->fetch(); if($row) $db->prepare('INSERT IGNORE INTO portal_project_channels(project_id,channel_id,short_code) VALUES(?,?,?)')->execute([$id,$cid,$row['short_code']]); } audit('save_project','vas_portal','portal_projects',(string)$id,json_encode(['data'=>$data,'channels'=>$channelIds])); }

function make_confirmation(string $action,array $payload): string { $token=bin2hex(random_bytes(24)); portal_pdo()->prepare('INSERT INTO operation_confirmations(token,username,action,payload) VALUES(?,?,?,?)')->execute([$token,user()['username']??'guest',$action,json_encode($payload)]); return $token; }
// The permission needed to carry out a confirmed action, checked again at the moment it runs (a role may have changed since the preview).
function confirmation_permission(string $action): string {
    return ['insert' => 'create_records', 'update' => 'edit_records', 'duplicate' => 'duplicate_records', 'copy_record' => 'copy_records', 'sync_table' => 'copy_records', 'merge_table' => 'copy_records',
        'sql' => 'run_sql', 'save_project' => 'manage_projects', 'save_shortcode' => 'manage_shortcodes'][$action] ?? throw new RuntimeException('Unknown action');
}
function get_confirmation(string $token): ?array { $st=portal_pdo()->prepare('SELECT * FROM operation_confirmations WHERE token=? AND status="pending" AND created_at > (NOW() - INTERVAL 30 MINUTE) LIMIT 1'); $st->execute([$token]); return $st->fetch() ?: null; }
function mark_confirmation(string $token,string $status): void { portal_pdo()->prepare('UPDATE operation_confirmations SET status=?, confirmed_at=NOW() WHERE token=?')->execute([$status,$token]); }

const SQL_READONLY_KINDS = ['SELECT','SHOW','DESCRIBE','EXPLAIN'];
// A "WITH ... AS (...) SELECT ..." (or INSERT/UPDATE) common table expression starts with WITH, not
// its real statement type, so safe_sql_kind() needs to see past the CTE header to classify it
// correctly — a paren-depth scan rather than assuming WITH always means SELECT, since MySQL also
// allows "WITH x AS (...) INSERT/UPDATE ...", which must still go through the write/confirmation path.
function sql_after_cte_header(string $sql): string {
    $s = preg_replace('/^WITH\s+(RECURSIVE\s+)?/i', '', ltrim($sql), 1);
    $len = strlen($s); $i = 0; $depth = 0;
    while ($i < $len) {
        if ($s[$i] === '(') {
            $depth = 1; $i++;
            while ($i < $len && $depth > 0) { if ($s[$i]==='(') $depth++; elseif ($s[$i]===')') $depth--; $i++; }
            while ($i < $len && ctype_space($s[$i])) $i++;
            if ($i < $len && $s[$i] === ',') { $i++; continue; }
            break;
        }
        $i++;
    }
    return ltrim(substr($s, $i));
}
// The console works in the database chosen at the top. It must not read the portal's own database (password hashes, the encryption key),
// the server's account tables, or write into a live database by naming it from another one; and it must not create accounts or stored code.
function sql_assert_in_scope(string $sql, bool $isRead): void {
    $bare = preg_replace(['~/\*.*?\*/~s', '~(--|#)[^\r\n]*~'], ' ', $sql);
    $portal = preg_quote((string)app_config('portal_db'), '/');
    if (preg_match('/`?\b(?:'.$portal.'|mysql)\b`?\s*\.\s*[`\w]/i', $bare)) throw new RuntimeException("The SQL Console cannot read the portal's own database or the server's account tables.");
    if (!$isRead && preg_match('/`?\b(?:'.implode('|', array_map(fn($s) => preg_quote($s, '/'), PROTECTED_SCHEMAS)).')\b`?\s*\.\s*[`\w]/i', $bare)) throw new RuntimeException('Writes cannot name a live database. Switch to that database and use the record forms.');
    if (!$isRead && preg_match('/\b(CREATE|ALTER)\s+(DEFINER\s*=\s*\S+\s+)?(USER|ROLE|SERVER|EVENT|TRIGGER|PROCEDURE|FUNCTION)\b/i', $bare)) throw new RuntimeException('Accounts, events, triggers and stored code cannot be created from the SQL Console.');
}
function safe_sql_kind(string $sql): string {
    $trim=ltrim($sql);
    if (substr_count(rtrim(trim($sql), ';'), ';') > 0) throw new RuntimeException('Only a single statement is allowed per run.');
    $effective = preg_match('/^WITH\s+/i', $trim) ? sql_after_cte_header($trim) : $trim;
    if(!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|INSERT|UPDATE|REPLACE|CREATE|ALTER)\b/i',$effective,$m)) throw new RuntimeException('Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, REPLACE, CREATE and ALTER (optionally preceded by a WITH common table expression) are allowed. DELETE, DROP and TRUNCATE are disabled.');
    if(preg_match('/\b(DELETE|DROP|TRUNCATE|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',$sql)) throw new RuntimeException('Dangerous SQL command blocked. Delete/drop/truncate are disabled in this portal.');
    sql_assert_in_scope($sql, in_array(strtoupper($m[1]), SQL_READONLY_KINDS, true));
    return strtoupper($m[1]);
}
const SQL_CONSOLE_MAX_ROWS = 1000;
const SQL_CONSOLE_CSV_MAX_ROWS = 100000;
const SQL_CONSOLE_TIMEOUT_MS = 30000;
function run_sql(string $schema,string $sql,int $maxRows=SQL_CONSOLE_MAX_ROWS): array {
    $kind=safe_sql_kind($sql);
    // HeraProduction is read-only from the free-form SQL console; production writes must go through the
    // audited, confirmation-gated record forms (insert_record/update_record/copy_record), which are scoped
    // to a single primary key and cannot run an unbounded UPDATE/CREATE/ALTER against live data.
    if (is_protected_schema($schema) && !in_array($kind, SQL_READONLY_KINDS, true)) {
        throw new RuntimeException($schema.' is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.');
    }
    $db=pdo($schema);
    if(in_array($kind,SQL_READONLY_KINDS,true)){
        // Read only what will be shown (unbuffered, so the rest is never held in memory) and cap the run time;
        // otherwise SELECT * on audit_log/subscription would exhaust PHP memory or pin the production server.
        try { $db->exec('SET SESSION max_execution_time='.SQL_CONSOLE_TIMEOUT_MS); } catch (Throwable $e) {}
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        try {
            $st=$db->query($sql); $rows=[]; $truncated=false;
            while (($r=$st->fetch())!==false) { if (count($rows)>=$maxRows) { $truncated=true; break; } $rows[]=$r; }
            $st->closeCursor();
        } finally { $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true); }
        return ['kind'=>$kind,'rows'=>$rows,'affected'=>null,'truncated'=>$truncated,'limit'=>$maxRows];
    }
    $affected=$db->exec($sql); audit('sql_'.$kind,$schema,null,null,$sql); return ['kind'=>$kind,'rows'=>[],'affected'=>$affected];
}

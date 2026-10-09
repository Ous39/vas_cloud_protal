<?php
declare(strict_types=1);
// Promotions and the Promotion Performance report.

// ===================== Promotions + Promotion Performance report =====================
// A promotion is just a named group of offer codes (portal governance data, vas_portal, never Hera).
// The report below joins that group against subscription — same shape as the "Buy for Other" report
// operators were hand-writing in the SQL Console, but generated, and offer_code_for_other is read
// live from vas_offers instead of being duplicated into the promotion.
const PROMOTION_REPORT_MAX_RANGE_DAYS = 31;
const REPORT_TIMEOUT_SECONDS = 240;

function list_promotions(string $schema): array {
    $st = portal_pdo()->prepare('SELECT id,name FROM promotions WHERE schema_name=? ORDER BY name');
    $st->execute([$schema]);
    return $st->fetchAll();
}
function get_promotion(int $id): ?array {
    $st = portal_pdo()->prepare('SELECT * FROM promotions WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $st2 = portal_pdo()->prepare('SELECT offer_code FROM promotion_offers WHERE promotion_id=? ORDER BY offer_code');
    $st2->execute([$id]);
    $row['offer_codes'] = array_column($st2->fetchAll(), 'offer_code');
    return $row;
}
function save_promotion(string $schema, string $name, array $offerCodes, ?int $id = null): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Promotion name is required.');
    $offerCodes = array_values(array_unique(array_filter(array_map('trim', $offerCodes), fn($c) => $c !== '')));
    if (!$offerCodes) throw new RuntimeException('Select at least one offer code.');
    $db = portal_pdo();
    $db->beginTransaction();
    try {
        if ($id) {
            $db->prepare('UPDATE promotions SET name=? WHERE id=?')->execute([$name, $id]);
            $db->prepare('DELETE FROM promotion_offers WHERE promotion_id=?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO promotions(name,schema_name,created_by) VALUES(?,?,?)')->execute([$name, $schema, user()['username'] ?? null]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO promotion_offers(promotion_id,offer_code) VALUES(?,?)');
        foreach ($offerCodes as $code) $ins->execute([$id, $code]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('save_promotion', $schema, 'promotions', (string)$id, $name.': '.implode(',', $offerCodes));
    return $id;
}

// $offerCodes empty means "every offer" — a saved Promotion is just one convenient way to fill this
// list, never required; the report works for a single offer, an ad-hoc selection, or everything.
function offer_performance_report(string $schema, array $offerCodes, string $channel, string $dateFrom, string $dateTo): array {
    if (strtotime($dateFrom) === false || strtotime($dateTo) === false) throw new RuntimeException('Invalid date.');
    if (strtotime($dateTo) < strtotime($dateFrom)) throw new RuntimeException('"Date to" must not be before "date from".');
    if ((strtotime($dateTo) - strtotime($dateFrom)) / 86400 > PROMOTION_REPORT_MAX_RANGE_DAYS) throw new RuntimeException('Date range cannot exceed '.PROMOTION_REPORT_MAX_RANGE_DAYS.' days — subscription has no supporting index, so a wider scan would be very slow.');
    if (!table_exists($schema, 'subscription') || !table_exists($schema, 'vas_offers')) return [];
    $offerCodes = array_values(array_unique(array_filter(array_map('trim', $offerCodes), fn($c) => $c !== '')));
    $db = pdo($schema);

    $directWhere = ['1=1']; $directParams = [];
    $otherWhere = ["offer_code_for_other IS NOT NULL", "offer_code_for_other != ''"]; $otherParams = [];
    if ($offerCodes) {
        $ph = implode(',', array_fill(0, count($offerCodes), '?'));
        $directWhere[] = "offer_code IN ($ph)"; $directParams = $offerCodes;
        $otherWhere[] = "offer_code IN ($ph)"; $otherParams = $offerCodes;
    }
    // txn_offer_suffix is a generated column + index added manually as a DBA operation (see
    // docs/ARCHITECTURE.md or the deploy history) — use it when present for an indexed join instead
    // of computing RIGHT(transaction_id,5) per row; fall back gracefully where it hasn't been added
    // yet (local dev, HeraTesting) rather than hard-requiring it everywhere.
    $txnSuffixExpr = column_exists($schema, 'subscription', 'txn_offer_suffix') ? 's.txn_offer_suffix' : 'RIGHT(s.transaction_id, 5)';

    // Offer codes are not unique in vas_offers (several rows can share one code), so offers are de-duplicated
    // above: joining raw rows multiplied every attempt by the number of rows sharing the code.
    // For a short range, drive the query from the date index (one or two days of rows) instead of letting MySQL
    // walk every row an offer has ever had through the suffix index and check the date afterwards.
    $indexHint = '';
    if (strtotime($dateTo) - strtotime($dateFrom) <= 3 * 86400 && !index_exists($schema, 'subscription', 'idx_subscription_suffix_date') && index_exists($schema, 'subscription', 'idx_subscription_date_channel')) {
        $indexHint = 'FORCE INDEX (idx_subscription_date_channel)';
    }
    $sql = "SELECT DATE(s.date) AS ReportDate, s.channel AS Channel, m.base_offer_code AS OfferCode, m.offer_code_used AS TransactionOfferCode, m.purchase_type AS PurchaseType, v.name AS OfferName,
        CASE WHEN s.result_desc = 'Operation successfully.' THEN 'Successful' ELSE 'Unsuccessful' END AS ResultStatus,
        CASE WHEN s.result_desc = 'Operation successfully.' THEN 'N/A' WHEN s.result_desc IS NULL OR TRIM(s.result_desc) = '' THEN 'Unknown failure reason' ELSE s.result_desc END AS FailureReason,
        COUNT(*) AS TotalAttempts,
        SUM(CASE WHEN s.result_desc = 'Operation successfully.' THEN 1 ELSE 0 END) AS SuccessfulAttempts,
        SUM(CASE WHEN s.result_desc = 'Operation successfully.' THEN 0 ELSE 1 END) AS UnsuccessfulAttempts,
        COUNT(DISTINCT s.subscriber_msisdn) AS TotalDistinctUsers
        FROM subscription s $indexHint
        INNER JOIN (
            SELECT DISTINCT offer_code AS offer_code_used, offer_code AS base_offer_code, 'Direct' AS purchase_type FROM vas_offers WHERE ".implode(' AND ',$directWhere)."
            UNION
            SELECT DISTINCT offer_code_for_other, offer_code, 'Buy for Other' FROM vas_offers WHERE ".implode(' AND ',$otherWhere)."
        ) m ON $txnSuffixExpr = m.offer_code_used
        INNER JOIN (
            SELECT offer_code, SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY (status IN ('1','active')) DESC, id DESC SEPARATOR '||'), '||', 1) AS name FROM vas_offers GROUP BY offer_code
        ) v ON m.base_offer_code = v.offer_code
        WHERE s.date >= ? AND s.date < ?";
    $params = array_merge($directParams, $otherParams, [$dateFrom.' 00:00:00', date('Y-m-d', strtotime($dateTo.' +1 day')).' 00:00:00']);
    if ($channel !== '') { $sql .= ' AND s.channel = ?'; $params[] = $channel; }
    $sql .= " GROUP BY ReportDate, Channel, OfferCode, TransactionOfferCode, PurchaseType, OfferName, s.result_desc ORDER BY ReportDate, OfferName, PurchaseType, ResultStatus, FailureReason";
    // This aggregates every subscription row in the range, so a busy day can outlast the ingress timeout.
    // Give it a bounded budget (matched by proxy-read-timeout in deploy/k8s/04-ingress.yaml) and turn a
    // MySQL "query execution was interrupted" into an actionable message instead of a bare 504.
    @set_time_limit(REPORT_TIMEOUT_SECONDS + 20);
    try { $db->exec('SET SESSION max_execution_time='.(REPORT_TIMEOUT_SECONDS * 1000)); } catch (Throwable $e) {}
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'max_execution_time') || str_contains($e->getMessage(), 'interrupted')) {
            throw new RuntimeException('The report took longer than '.REPORT_TIMEOUT_SECONDS.'s and was stopped. Narrow it: pick one channel or specific offers, or use a shorter time range.');
        }
        throw $e;
    }
}

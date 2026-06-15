<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_promo_stock_health.php';
require_once __DIR__ . '/stock_alerts.php';
require_once __DIR__ . '/warehouses.php';

/**
 * مرحلة 9: سجل الإيقاف + آخر فحص مخزون لكل قاعدة عرض.
 */
function orange_cart_promo_monitor_tables_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'promo_stock_check')
        && orange_table_exists($pdo, 'promo_pause_log');
}

/**
 * @return array<string, string>
 */
function orange_cart_promo_monitor_kind_labels(): array
{
    return [
        'cart_promotions' => 'خصم مجموع السلة',
        'cart_gift_promotions' => 'هدية مجموع السلة',
        'cart_bogo_promotions' => 'BOGO',
        'cart_combo_promotions' => 'كومبو',
        'offers' => 'عرض منتج',
    ];
}

/**
 * @return array<string, string>
 */
function orange_cart_promo_monitor_admin_pages(): array
{
    return [
        'cart_promotions' => 'cart_promotions',
        'cart_gift_promotions' => 'cart_gift_promotions',
        'cart_bogo_promotions' => 'cart_bogo_promotions',
        'cart_combo_promotions' => 'cart_combo_promotions',
        'offers' => 'offers',
    ];
}

/**
 * @param array<string,mixed> $rule
 *
 * @return list<int>
 */
function orange_cart_promo_rule_product_ids_for_stock_scan(PDO $pdo, string $table, array $rule): array
{
    $ids = [];
    if ($table === 'offers') {
        $pid = (int) ($rule['product_id'] ?? 0);
        if ($pid > 0) {
            $ids[$pid] = true;
        }

        return array_keys($ids);
    }

    foreach (orange_cart_promo_rule_offer_product_ids($pdo, $table, $rule) as $pid) {
        $ids[(int) $pid] = true;
    }

    if (in_array($table, orange_cart_promo_gift_stock_tables(), true)) {
        require_once __DIR__ . '/cart_gift_promotions.php';
        $gKind = strtolower(trim((string) ($rule['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
        if ($gKind === 'fixed') {
            $fv = (int) ($rule['fixed_variant_id'] ?? $rule['fixed_product_id'] ?? 0);
            $pid = $fv > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fv) : 0;
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        } else {
            $pool = $rule['pool_variant_ids'] ?? $rule['pool_product_ids'] ?? [];
            if (is_array($pool)) {
                foreach ($pool as $sid) {
                    $pid = orange_cart_promo_resolve_stored_product_id($pdo, (int) $sid);
                    if ($pid > 0) {
                        $ids[$pid] = true;
                    }
                }
            }
        }
    }

    return array_keys($ids);
}

function orange_cart_promo_rule_has_low_stock_warn(PDO $pdo, array $productIds, int $countryId): bool
{
    if ($productIds === []) {
        return false;
    }
    $th = orange_stock_low_alert_threshold($pdo, $countryId);
    $st = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $st->execute([$pid]);
        $max = 0;
        while ($vid = $st->fetchColumn()) {
            $vid = (int) $vid;
            if ($vid <= 0) {
                continue;
            }
            $qty = orange_warehouse_effective_variant_stock($pdo, $vid, $countryId);
            if ($qty > $max) {
                $max = $qty;
            }
        }
        if ($max > 0 && $max <= $th) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $row
 *
 * @return array{status:string,stock_reason:?string,detail:string}
 */
function orange_cart_promo_compute_stock_check_status(PDO $pdo, string $table, array $row, array $rule, int $countryId): array
{
    if ($table === 'cart_promotions') {
        return [
            'status' => 'na',
            'stock_reason' => null,
            'detail' => 'لا ربط مخزون منتجات — خصم على مجموع السلة فقط',
        ];
    }

    $pausedReason = trim((string) ($row['auto_paused_reason'] ?? ''));
    if ($pausedReason !== '') {
        return [
            'status' => 'paused',
            'stock_reason' => $pausedReason,
            'detail' => orange_cart_promo_admin_pause_reason_label_ar($pausedReason),
        ];
    }

    $would = orange_cart_promo_rule_stock_pause_reason($pdo, $table, $rule, $countryId);
    if ($would !== null) {
        return [
            'status' => 'would_pause',
            'stock_reason' => $would,
            'detail' => 'سيُوقف عند الفحص: ' . orange_cart_promo_admin_pause_reason_label_ar($would),
        ];
    }

    $pids = orange_cart_promo_rule_product_ids_for_stock_scan($pdo, $table, $rule);
    if (orange_cart_promo_rule_has_low_stock_warn($pdo, $pids, $countryId)) {
        return [
            'status' => 'warn',
            'stock_reason' => null,
            'detail' => 'قارب النفاذ (مخزون ≤ ' . orange_stock_low_alert_threshold($pdo, $countryId) . ' على منتج مرتبط)',
        ];
    }

    return [
        'status' => 'ok',
        'stock_reason' => null,
        'detail' => 'مخزون العرض متوفر',
    ];
}

function orange_cart_promo_log_stock_resume_event(
    PDO $pdo,
    string $table,
    int $ruleId,
    string $previousReason,
    int $countryId
): void {
    orange_cart_promo_log_pause_event($pdo, $table, $ruleId, 'stock_resumed', $countryId, [
        'action' => 'unpause',
        'previous_reason' => $previousReason,
        'source' => 'stock_auto_resume',
    ]);
}

function orange_cart_promo_log_pause_event(
    PDO $pdo,
    string $table,
    int $ruleId,
    string $reason,
    int $countryId,
    ?array $meta = null
): void {
    if (!orange_table_exists($pdo, 'promo_pause_log') || $ruleId <= 0) {
        return;
    }
    $metaJson = null;
    if ($meta !== null && $meta !== []) {
        $enc = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $metaJson = $enc !== false ? $enc : null;
    }
    $st = $pdo->prepare(
        'INSERT INTO promo_pause_log (rule_table, rule_id, reason, country_id, paused_at, meta_json)
         VALUES (?, ?, ?, ?, NOW(), ?)'
    );
    $st->execute([
        $table,
        $ruleId,
        $reason,
        $countryId > 0 ? $countryId : null,
        $metaJson,
    ]);
}

function orange_cart_promo_upsert_stock_check(
    PDO $pdo,
    string $table,
    int $ruleId,
    int $countryId,
    string $status,
    ?string $stockReason,
    string $detail
): void {
    if (!orange_table_exists($pdo, 'promo_stock_check') || $ruleId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO promo_stock_check (rule_table, rule_id, country_id, status, stock_reason, detail_ar, checked_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           stock_reason = VALUES(stock_reason),
           detail_ar = VALUES(detail_ar),
           checked_at = NOW()'
    );
    $st->execute([
        $table,
        $ruleId,
        $countryId > 0 ? $countryId : null,
        $status,
        $stockReason !== null && $stockReason !== '' ? $stockReason : null,
        $detail,
    ]);
}

/**
 * @return array{status:string,stock_reason:?string,detail:string}
 */
function orange_cart_promo_apply_pause_from_check(
    PDO $pdo,
    string $table,
    int $ruleId,
    array $check,
    int $countryId
): array {
    $reason = trim((string) ($check['stock_reason'] ?? ''));
    if ($reason === '' || !in_array($reason, orange_cart_promo_auto_pause_reasons(), true)) {
        return $check;
    }
    if (!orange_cart_promo_auto_pause_with_reason($pdo, $table, $ruleId, $reason)) {
        return $check;
    }
    orange_cart_promo_log_pause_event($pdo, $table, $ruleId, $reason, $countryId, [
        'source' => 'stock_health',
    ]);

    return [
        'status' => 'paused',
        'stock_reason' => $reason,
        'detail' => orange_cart_promo_admin_pause_reason_label_ar($reason),
    ];
}

/**
 * @param list<string>|null $onlyTables
 *
 * @return array{
 *   checked:int,
 *   paused_promo_stock:int,
 *   paused_gift_stock:int,
 *   paused:list<array{table:string,id:int,reason:string,country_id:int}>,
 *   resumed:int,
 *   resumed_rules:list<array{table:string,id:int,previous_reason:string,country_id:int}>,
 *   countries:list<int>,
 *   rows:list<array<string,mixed>>
 * }
 */
function orange_cart_promo_sync_stock_checks(
    PDO $pdo,
    ?int $countryId = null,
    ?array $onlyTables = null,
    bool $applyPause = false
): array {
    $result = [
        'checked' => 0,
        'paused_promo_stock' => 0,
        'paused_gift_stock' => 0,
        'paused' => [],
        'resumed' => 0,
        'resumed_rules' => [],
        'countries' => [],
        'rows' => [],
    ];

    $tables = $onlyTables !== null && $onlyTables !== []
        ? array_values(array_intersect(orange_cart_promo_stock_health_tables(), $onlyTables))
        : orange_cart_promo_stock_health_tables();

    $countryIds = orange_cart_promo_stock_health_country_ids($pdo, $countryId);
    $result['countries'] = $countryIds;
    $labels = orange_cart_promo_monitor_kind_labels();
    $pages = orange_cart_promo_monitor_admin_pages();

    foreach ($countryIds as $cid) {
        foreach ($tables as $table) {
            if (!orange_table_exists($pdo, $table)) {
                continue;
            }
            if ($table !== 'cart_promotions' && !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
                continue;
            }

            $inWindow = orange_table_has_column($pdo, $table, 'valid_from')
                ? 't.valid_from <= NOW() AND t.valid_to >= NOW()'
                : '';
            if ($inWindow !== '' && orange_table_has_column($pdo, $table, 'auto_paused_at')) {
                $monitorSql = ' AND t.is_active = 1 AND ((' . $inWindow . ') OR t.auto_paused_at IS NOT NULL)';
            } elseif ($inWindow !== '') {
                $monitorSql = ' AND t.is_active = 1 AND (' . $inWindow . ')';
            } else {
                $monitorSql = ' AND t.is_active = 1';
            }

            if ($table === 'offers') {
                $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $cid);
                $sql = 'SELECT t.*, t.id AS rule_id, p.name AS product_name
                        FROM offers t
                        INNER JOIN products p ON p.id = t.product_id
                        WHERE 1=1' . $monitorSql . $countrySql;
                $st = $pdo->query($sql);
            } elseif ($table === 'cart_promotions') {
                $bind = orange_cart_promotion_sql_bind($pdo, $table, 't', $cid);
                $st = $pdo->prepare(
                    'SELECT t.*, t.id AS rule_id FROM ' . $table . ' t WHERE 1=1' . $monitorSql . $bind['sql']
                );
                $st->execute($bind['params']);
            } else {
                $bind = orange_cart_promotion_sql_bind($pdo, $table, 't', $cid);
                $st = $pdo->prepare('SELECT t.*, t.id AS rule_id FROM ' . $table . ' t WHERE 1=1' . $monitorSql . $bind['sql']);
                $st->execute($bind['params']);
            }

            if ($st === false) {
                continue;
            }

            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $result['checked']++;
                $ruleId = (int) ($row['rule_id'] ?? $row['id'] ?? 0);
                $rule = $table === 'offers'
                    ? [
                        'id' => $ruleId,
                        'product_id' => (int) ($row['product_id'] ?? 0),
                        'is_active' => (int) ($row['is_active'] ?? 0),
                        'valid_from' => (string) ($row['valid_from'] ?? ''),
                        'valid_to' => (string) ($row['valid_to'] ?? ''),
                        'auto_paused_at' => $row['auto_paused_at'] ?? null,
                        'auto_paused_reason' => (string) ($row['auto_paused_reason'] ?? ''),
                    ]
                    : array_merge(orange_cart_promo_gift_rule_from_db_row($pdo, $row), [
                        'is_active' => (int) ($row['is_active'] ?? 0),
                        'valid_from' => (string) ($row['valid_from'] ?? ''),
                        'valid_to' => (string) ($row['valid_to'] ?? ''),
                        'auto_paused_at' => $row['auto_paused_at'] ?? null,
                        'auto_paused_reason' => (string) ($row['auto_paused_reason'] ?? ''),
                    ]);

                $prevReason = trim((string) ($row['auto_paused_reason'] ?? ''));
                if (orange_cart_promo_try_stock_auto_unpause($pdo, $table, $row, $rule, $cid)) {
                    $row['auto_paused_at'] = null;
                    $row['auto_paused_reason'] = null;
                    $rule['auto_paused_at'] = null;
                    $rule['auto_paused_reason'] = null;
                    $result['resumed']++;
                    $result['resumed_rules'][] = [
                        'table' => $table,
                        'id' => $ruleId,
                        'previous_reason' => $prevReason,
                        'country_id' => $cid,
                    ];
                }

                $check = orange_cart_promo_compute_stock_check_status($pdo, $table, $row, $rule, $cid);

                if ($applyPause && $check['status'] === 'would_pause') {
                    $check = orange_cart_promo_apply_pause_from_check($pdo, $table, $ruleId, $check, $cid);
                    if ($check['status'] === 'paused') {
                        $reason = (string) $check['stock_reason'];
                        if ($reason === 'gift_stock') {
                            $result['paused_gift_stock']++;
                        } else {
                            $result['paused_promo_stock']++;
                        }
                        $result['paused'][] = [
                            'table' => $table,
                            'id' => $ruleId,
                            'reason' => $reason,
                            'country_id' => $cid,
                        ];
                    }
                }

                orange_cart_promo_upsert_stock_check(
                    $pdo,
                    $table,
                    $ruleId,
                    $cid,
                    $check['status'],
                    $check['stock_reason'],
                    $check['detail']
                );

                $scheduleLabel = orange_table_has_column($pdo, $table, 'valid_from')
                    ? orange_cart_promo_admin_schedule_label($row)
                    : '—';

                $result['rows'][] = [
                    'table' => $table,
                    'kind' => $labels[$table] ?? $table,
                    'admin_page' => $pages[$table] ?? $table,
                    'id' => $ruleId,
                    'country_id' => $cid,
                    'status' => $check['status'],
                    'stock_reason' => $check['stock_reason'],
                    'detail' => $check['detail'],
                    'schedule' => $scheduleLabel,
                    'auto_paused_at' => (string) ($row['auto_paused_at'] ?? ''),
                ];
            }
        }
    }

    usort($result['rows'], static function (array $a, array $b): int {
        $ord = ['would_pause' => 0, 'paused' => 1, 'warn' => 2, 'ok' => 3, 'na' => 4];
        $sa = $ord[$a['status']] ?? 9;
        $sb = $ord[$b['status']] ?? 9;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        return strcmp($a['kind'], $b['kind']) ?: ((int) $b['id'] <=> (int) $a['id']);
    });

    return $result;
}

/**
 * @return list<array<string,mixed>>
 */
function orange_cart_promo_monitor_rows_for_admin(PDO $pdo, ?int $countryId = null, bool $resync = false): array
{
    if ($resync) {
        orange_cart_promo_sync_stock_checks($pdo, $countryId, null, false);
    }

    $cid = $countryId > 0 ? $countryId : orange_cart_promotion_admin_country_id($pdo);
    if (!orange_table_exists($pdo, 'promo_stock_check')) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT * FROM promo_stock_check
         WHERE country_id <=> ?
         ORDER BY FIELD(status, \'would_pause\', \'paused\', \'warn\', \'ok\', \'na\'), rule_table ASC, rule_id DESC'
    );
    $st->execute([$cid > 0 ? $cid : null]);
    $labels = orange_cart_promo_monitor_kind_labels();
    $pages = orange_cart_promo_monitor_admin_pages();
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $table = (string) ($row['rule_table'] ?? '');
        $out[] = [
            'table' => $table,
            'kind' => $labels[$table] ?? $table,
            'admin_page' => $pages[$table] ?? $table,
            'id' => (int) ($row['rule_id'] ?? 0),
            'country_id' => (int) ($row['country_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'stock_reason' => $row['stock_reason'] ?? null,
            'detail' => (string) ($row['detail_ar'] ?? ''),
            'checked_at' => (string) ($row['checked_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function orange_cart_promo_monitor_status_labels_ar(): array
{
    return [
        'would_pause' => 'سيُوقف عند الفحص',
        'paused' => 'موقوف (مخزون)',
        'warn' => 'تحذير — قارب النفاذ',
        'ok' => 'سليم',
        'na' => 'لا ينطبق',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function orange_cart_promo_recent_pause_log(PDO $pdo, ?int $countryId = null, int $limit = 30): array
{
    if (!orange_table_exists($pdo, 'promo_pause_log')) {
        return [];
    }
    $cid = $countryId > 0 ? $countryId : orange_cart_promotion_admin_country_id($pdo);
    $limit = max(1, min(100, $limit));
    $st = $pdo->prepare(
        'SELECT * FROM promo_pause_log
         WHERE country_id <=> ?
         ORDER BY paused_at DESC, id DESC
         LIMIT ' . $limit
    );
    $st->execute([$cid > 0 ? $cid : null]);
    $labels = orange_cart_promo_monitor_kind_labels();
    $pages = orange_cart_promo_monitor_admin_pages();
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $table = (string) ($row['rule_table'] ?? '');
        $reason = trim((string) ($row['reason'] ?? ''));
        $out[] = [
            'table' => $table,
            'kind' => $labels[$table] ?? $table,
            'admin_page' => $pages[$table] ?? $table,
            'id' => (int) ($row['rule_id'] ?? 0),
            'reason' => $reason,
            'reason_label' => orange_cart_promo_admin_pause_reason_label_ar($reason),
            'paused_at' => (string) ($row['paused_at'] ?? ''),
        ];
    }

    return $out;
}

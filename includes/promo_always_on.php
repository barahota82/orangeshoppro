<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @param mixed $value
 */
function orange_promo_always_on_to_int($value): int
{
    return !empty($value) ? 1 : 0;
}

function orange_promo_always_on_enabled(array $row): bool
{
    return orange_promo_always_on_to_int($row['is_always_on'] ?? 0) === 1;
}

function orange_promo_always_on_admin_id(): int
{
    if (function_exists('current_admin')) {
        $admin = current_admin();
        if (is_array($admin) && isset($admin['id'])) {
            $id = (int) $admin['id'];
            if ($id > 0) {
                return $id;
            }
        }
    }
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return 0;
    }

    return isset($_SESSION['admin_id']) ? max(0, (int) $_SESSION['admin_id']) : 0;
}

function orange_promo_always_on_history_table_exists(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'promotion_always_on_history');
}

/**
 * تحديث سجل "التفعيل الدائم" بدون تكرار:
 * - دائم -> دائم: لا يسجل صف جديد إذا يوجد صف مفتوح.
 * - غير دائم -> دائم: يفتح صف جديد.
 * - دائم -> غير دائم: يغلق الصف المفتوح.
 */
function orange_promo_always_on_sync_history(
    PDO $pdo,
    string $promoTable,
    int $promotionId,
    int $isAlwaysOn,
    ?int $countryId = null,
    ?int $adminId = null
): void {
    if ($promotionId <= 0 || !orange_promo_always_on_history_table_exists($pdo)) {
        return;
    }
    $promoTable = trim($promoTable);
    if ($promoTable === '') {
        return;
    }
    $isAlwaysOn = $isAlwaysOn === 1 ? 1 : 0;
    $adminId = $adminId ?? orange_promo_always_on_admin_id();
    $countryId = $countryId !== null ? max(0, $countryId) : null;

    $openSt = $pdo->prepare(
        'SELECT id
         FROM promotion_always_on_history
         WHERE promo_table = ? AND promotion_id = ? AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $openSt->execute([$promoTable, $promotionId]);
    $openId = (int) ($openSt->fetchColumn() ?: 0);

    if ($isAlwaysOn === 1) {
        if ($openId > 0) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO promotion_always_on_history
                (promo_table, promotion_id, country_id, started_at, ended_at, started_by_admin_id, ended_by_admin_id)
             VALUES (?, ?, ?, NOW(), NULL, ?, NULL)'
        );
        $ins->execute([
            $promoTable,
            $promotionId,
            $countryId !== null && $countryId > 0 ? $countryId : null,
            $adminId > 0 ? $adminId : null,
        ]);

        return;
    }

    if ($openId <= 0) {
        return;
    }
    $up = $pdo->prepare(
        'UPDATE promotion_always_on_history
         SET ended_at = NOW(), ended_by_admin_id = ?
         WHERE id = ? AND ended_at IS NULL'
    );
    $up->execute([
        $adminId > 0 ? $adminId : null,
        $openId,
    ]);
}

/**
 * @return list<array{
 *   id:int,
 *   promo_table:string,
 *   promotion_id:int,
 *   country_id:int,
 *   started_at:string,
 *   ended_at:?string,
 *   started_by_admin_id:int,
 *   ended_by_admin_id:int,
 *   started_by_name:string,
 *   ended_by_name:string
 * }>
 */
function orange_promo_always_on_history_list(
    PDO $pdo,
    string $promoTable,
    ?int $countryId = null,
    int $promotionId = 0,
    int $limit = 200
): array {
    if (!orange_promo_always_on_history_table_exists($pdo)) {
        return [];
    }
    $promoTable = trim($promoTable);
    if ($promoTable === '') {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $params = [$promoTable];
    $where = ' WHERE h.promo_table = ?';
    if ($promotionId > 0) {
        $where .= ' AND h.promotion_id = ?';
        $params[] = $promotionId;
    }
    if ($countryId !== null && $countryId > 0) {
        $where .= ' AND h.country_id = ?';
        $params[] = $countryId;
    }

    $sql = 'SELECT h.id, h.promo_table, h.promotion_id, h.country_id, h.started_at, h.ended_at,
                   h.started_by_admin_id, h.ended_by_admin_id,
                   COALESCE(NULLIF(TRIM(a1.display_name), \'\'), NULLIF(TRIM(a1.username), \'\'), \'\') AS started_by_name,
                   COALESCE(NULLIF(TRIM(a2.display_name), \'\'), NULLIF(TRIM(a2.username), \'\'), \'\') AS ended_by_name
            FROM promotion_always_on_history h
            LEFT JOIN admins a1 ON a1.id = h.started_by_admin_id
            LEFT JOIN admins a2 ON a2.id = h.ended_by_admin_id'
        . $where
        . ' ORDER BY h.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'promo_table' => (string) ($row['promo_table'] ?? ''),
            'promotion_id' => (int) ($row['promotion_id'] ?? 0),
            'country_id' => (int) ($row['country_id'] ?? 0),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'ended_at' => isset($row['ended_at']) && $row['ended_at'] !== '' ? (string) $row['ended_at'] : null,
            'started_by_admin_id' => (int) ($row['started_by_admin_id'] ?? 0),
            'ended_by_admin_id' => (int) ($row['ended_by_admin_id'] ?? 0),
            'started_by_name' => (string) ($row['started_by_name'] ?? ''),
            'ended_by_name' => (string) ($row['ended_by_name'] ?? ''),
        ];
    }

    return $out;
}

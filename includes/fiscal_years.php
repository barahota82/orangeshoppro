<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array<string, mixed>>
 */
function orange_fiscal_years_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return [];
    }

    return $pdo->query('SELECT * FROM fiscal_years ORDER BY start_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, mixed>|null
 */
function orange_fiscal_find_for_date(PDO $pdo, string $dateYmdOrDatetime): ?array
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return null;
    }
    $d = substr($dateYmdOrDatetime, 0, 10);
    $st = $pdo->prepare(
        'SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$d]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * يمنع القيد على سنة مغلقة أو خارج أي سنة معرفة.
 *
 * @throws RuntimeException
 */
function orange_fiscal_require_open_for_posting(PDO $pdo, string $datetime): int
{
    orange_catalog_ensure_schema($pdo);
    $row = orange_fiscal_find_for_date($pdo, $datetime);
    if (!$row) {
        throw new RuntimeException(
            'لا توجد سنة مالية تغطي تاريخ القيد. عرّف السنة من «السنوات المالية».'
        );
    }
    if ((int) $row['is_closed'] === 1) {
        $label = trim((string) ($row['label_ar'] ?? ''));
        if ($label === '') {
            $label = '#' . (int) $row['id'];
        }
        throw new RuntimeException('السنة المالية «' . $label . '» مغلقة — لا يمكن إضافة أو عكس قيود عليها.');
    }

    return (int) $row['id'];
}

function orange_fiscal_is_closed_for_entry(PDO $pdo, array $journalRow): bool
{
    orange_catalog_ensure_schema($pdo);
    $fyId = (int) ($journalRow['fiscal_year_id'] ?? 0);
    if ($fyId > 0 && orange_table_exists($pdo, 'fiscal_years')) {
        $st = $pdo->prepare('SELECT is_closed FROM fiscal_years WHERE id = ? LIMIT 1');
        $st->execute([$fyId]);
        $c = (int) $st->fetchColumn();

        return $c === 1;
    }
    $d = (string) ($journalRow['date'] ?? '');
    $fy = orange_fiscal_find_for_date($pdo, $d);

    return $fy ? ((int) $fy['is_closed'] === 1) : false;
}

/**
 * تداخل النطاقات: يوجد صف يقطع المدى [start,end]؟
 */
function orange_fiscal_range_overlaps_existing(PDO $pdo, string $start, string $end, ?int $exceptId = null): bool
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM fiscal_years WHERE NOT (end_date < ? OR start_date > ?)';
    $params = [$start, $end];
    if ($exceptId !== null && $exceptId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn() > 0;
}

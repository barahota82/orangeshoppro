<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * مفتاح commercial_kind_key المقترن بقسم رئيسي (departments.id).
 * يُستخدم في هرَم المقاسات المستوى 1 عند ربط القاموس بالأقسام الرئيسية.
 */
function orange_sizing_commercial_kind_key_for_department_id(int $departmentId): string
{
    if ($departmentId <= 0) {
        return '';
    }

    return 'd' . (string) $departmentId;
}

/**
 * @return int|null معرف القسم إن وافق المفتاح النمط d123، وإلا null
 */
function orange_sizing_department_id_from_commercial_kind_key(string $kindKey): ?int
{
    $t = trim($kindKey);
    if ($t === '' || ! preg_match('/^d(\d+)$/', $t, $m)) {
        return null;
    }
    $id = (int) ($m[1] ?? 0);

    return $id > 0 ? $id : null;
}

/**
 * يحدّث أو يُدرج صفاً في commercial_kind_dictionary يطابق قسمًا رئيسيًا.
 */
function orange_catalog_sync_commercial_kind_for_department(PDO $pdo, int $departmentId): void
{
    if ($departmentId <= 0) {
        return;
    }
    if (! orange_table_exists($pdo, 'departments') || ! orange_table_exists($pdo, 'commercial_kind_dictionary')) {
        return;
    }

    $kindKey = orange_sizing_commercial_kind_key_for_department_id($departmentId);
    if ($kindKey === '') {
        return;
    }

    $st = $pdo->prepare(
        'SELECT name_ar, name_en, sort_order, is_active FROM departments WHERE id = ? LIMIT 1'
    );
    $st->execute([$departmentId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        return;
    }

    $labelAr = trim((string) ($row['name_ar'] ?? ''));
    $labelEn = trim((string) ($row['name_en'] ?? ''));
    $sortOrder = (int) ($row['sort_order'] ?? 0);
    $isActive = (int) ($row['is_active'] ?? 1) === 0 ? 0 : 1;

    $ex = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
    $ex->execute([$kindKey]);
    if ($ex->fetchColumn()) {
        $pdo->prepare(
            'UPDATE commercial_kind_dictionary
             SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
             WHERE kind_key = ? LIMIT 1'
        )->execute([$labelAr, $labelEn, $sortOrder, $isActive, $kindKey]);
    } else {
        $pdo->prepare(
            'INSERT INTO commercial_kind_dictionary (kind_key, label_ar, label_en, sort_order, is_active)
             VALUES (?,?,?,?,?)'
        )->execute([$kindKey, $labelAr, $labelEn, $sortOrder, $isActive]);
    }
}

/**
 * يزامن كل الأقسام الرئيسية إلى قاموس المستوى 1.
 */
function orange_catalog_sync_commercial_kinds_from_departments(PDO $pdo): void
{
    if (! orange_table_exists($pdo, 'departments') || ! orange_table_exists($pdo, 'commercial_kind_dictionary')) {
        return;
    }

    try {
        $ids = $pdo->query('SELECT id FROM departments ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (! is_array($ids)) {
        return;
    }
    foreach ($ids as $id) {
        orange_catalog_sync_commercial_kind_for_department($pdo, (int) $id);
    }
}

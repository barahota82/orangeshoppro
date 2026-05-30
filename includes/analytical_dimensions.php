<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return bool
 */
function orange_analytical_dimensions_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'analytical_dimension')
        && orange_table_exists($pdo, 'analytical_dimension_value');
}

/**
 * v1 seed: branch + channel dimension headers (values via admin in phase 4).
 */
function orange_analytical_dimension_seed_v1(PDO $pdo): void
{
    if (!orange_analytical_dimensions_ready($pdo)) {
        return;
    }

    static $seeded = false;
    if ($seeded) {
        return;
    }
    $seeded = true;

    $countryId = null;
    if (function_exists('orange_admin_context_country_id')) {
        try {
            $cid = orange_admin_context_country_id($pdo);
            if ($cid > 0) {
                $countryId = $cid;
            }
        } catch (Throwable $e) {
            $countryId = null;
        }
    }

    if ($countryId === null && function_exists('orange_admin_settings_effective_country_id')) {
        require_once __DIR__ . '/admin_settings_country.php';
        try {
            $cid = orange_admin_settings_effective_country_id($pdo);
            if ($cid > 0) {
                $countryId = $cid;
            }
        } catch (Throwable $e) {
            $countryId = null;
        }
    }

    $defaults = [
        ['code' => 'branch', 'label_ar' => 'فرع', 'label_en' => 'Branch', 'sort' => 10],
        ['code' => 'channel', 'label_ar' => 'قناة', 'label_en' => 'Channel', 'sort' => 20],
    ];

    foreach ($defaults as $row) {
        $st = $pdo->prepare(
            'SELECT id FROM analytical_dimension WHERE code = ? AND '
            . ($countryId !== null ? 'country_id = ?' : 'country_id IS NULL')
            . ' LIMIT 1'
        );
        if ($countryId !== null) {
            $st->execute([$row['code'], $countryId]);
        } else {
            $st->execute([$row['code']]);
        }
        if ($st->fetchColumn()) {
            continue;
        }

        if ($countryId !== null) {
            $ins = $pdo->prepare(
                'INSERT INTO analytical_dimension (code, label_ar, label_en, is_active, sort_order, country_id)
                 VALUES (?, ?, ?, 1, ?, ?)'
            );
            $ins->execute([$row['code'], $row['label_ar'], $row['label_en'], $row['sort'], $countryId]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO analytical_dimension (code, label_ar, label_en, is_active, sort_order, country_id)
                 VALUES (?, ?, ?, 1, ?, NULL)'
            );
            $ins->execute([$row['code'], $row['label_ar'], $row['label_en'], $row['sort']]);
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_analytical_dimensions_list(PDO $pdo, ?int $countryId = null, bool $activeOnly = true): array
{
    if (!orange_analytical_dimensions_ready($pdo)) {
        return [];
    }

    $sql = 'SELECT id, code, label_ar, label_en, is_active, sort_order, country_id
            FROM analytical_dimension WHERE 1=1';
    $params = [];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($countryId !== null && $countryId > 0) {
        $sql .= ' AND (country_id IS NULL OR country_id = ?)';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array{id:int,label:string,dimension_id:int,dimension_code:string,dimension_label:string}>
 */
function orange_analytical_dimension_ui_options(PDO $pdo, ?int $countryId = null): array
{
    if (! orange_analytical_dimensions_ready($pdo)) {
        return [];
    }
    orange_analytical_dimension_seed_v1($pdo);
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/admin_settings_country.php';
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }

    $dims = orange_analytical_dimensions_list($pdo, $countryId, true);
    $out = [];
    foreach ($dims as $dim) {
        $dimId = (int) ($dim['id'] ?? 0);
        if ($dimId <= 0) {
            continue;
        }
        $dimLabel = trim((string) ($dim['label_ar'] ?? ''));
        if ($dimLabel === '') {
            $dimLabel = trim((string) ($dim['label_en'] ?? ''));
        }
        $dimCode = trim((string) ($dim['code'] ?? ''));
        $values = orange_analytical_dimension_values_list($pdo, $dimId, true);
        foreach ($values as $val) {
            $vid = (int) ($val['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $label = trim((string) ($val['label_ar'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($val['label_en'] ?? ''));
            }
            $code = trim((string) ($val['code'] ?? ''));
            if ($code !== '' && $label !== $code) {
                $label = $code . ' — ' . $label;
            } elseif ($label === '' && $code !== '') {
                $label = $code;
            }
            $out[] = [
                'id' => $vid,
                'label' => $label !== '' ? $label : ('#' . $vid),
                'dimension_id' => $dimId,
                'dimension_code' => $dimCode,
                'dimension_label' => $dimLabel !== '' ? $dimLabel : $dimCode,
            ];
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_analytical_dimension_values_list(PDO $pdo, int $dimensionId, bool $activeOnly = true): array
{
    if ($dimensionId <= 0 || ! orange_table_exists($pdo, 'analytical_dimension_value')) {
        return [];
    }
    $sql = 'SELECT id, dimension_id, code, label_ar, label_en, is_active, sort_order
            FROM analytical_dimension_value WHERE dimension_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, label_ar ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$dimensionId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function orange_analytical_dimension_value_row(PDO $pdo, int $valueId): ?array
{
    if ($valueId <= 0 || ! orange_table_exists($pdo, 'analytical_dimension_value')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT adv.*, ad.code AS dimension_code, ad.label_ar AS dimension_label_ar, ad.country_id AS dimension_country_id
         FROM analytical_dimension_value adv
         INNER JOIN analytical_dimension ad ON ad.id = adv.dimension_id
         WHERE adv.id = ? LIMIT 1'
    );
    $st->execute([$valueId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function orange_analytical_dimension_value_assert_valid(PDO $pdo, int $valueId, ?int $countryId = null): void
{
    if ($valueId <= 0) {
        return;
    }
    $row = orange_analytical_dimension_value_row($pdo, $valueId);
    if ($row === null || (int) ($row['is_active'] ?? 0) !== 1) {
        throw new InvalidArgumentException('قيمة البُعد التحليلي غير صالحة أو غير نشطة.');
    }
    if ($countryId !== null && $countryId > 0) {
        $dimCountry = (int) ($row['dimension_country_id'] ?? 0);
        if ($dimCountry > 0 && $dimCountry !== $countryId) {
            throw new InvalidArgumentException('قيمة البُعد لا تتبع دولة السند.');
        }
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_analytical_dimension_get(PDO $pdo, int $dimensionId, ?int $countryId = null): ?array
{
    if ($dimensionId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM analytical_dimension WHERE id = ? LIMIT 1');
    $st->execute([$dimensionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        return null;
    }
    if ($countryId !== null && $countryId > 0 && isset($row['country_id']) && (int) $row['country_id'] > 0) {
        if ((int) $row['country_id'] !== $countryId) {
            return null;
        }
    }

    return $row;
}

function orange_analytical_dimension_update_header(PDO $pdo, int $dimensionId, array $in, ?int $countryId = null): void
{
    $dim = orange_analytical_dimension_get($pdo, $dimensionId, $countryId);
    if ($dim === null) {
        throw new InvalidArgumentException('البُعد غير موجود.');
    }
    $labelAr = trim((string) ($in['label_ar'] ?? $dim['label_ar'] ?? ''));
    $labelEn = trim((string) ($in['label_en'] ?? $dim['label_en'] ?? ''));
    $sort = (int) ($in['sort_order'] ?? $dim['sort_order'] ?? 0);
    $active = (int) ($in['is_active'] ?? $dim['is_active'] ?? 1) === 1 ? 1 : 0;
    if ($labelAr === '') {
        throw new InvalidArgumentException('الاسم العربي للبُعد مطلوب.');
    }
    $pdo->prepare(
        'UPDATE analytical_dimension SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ? WHERE id = ?'
    )->execute([
        $labelAr,
        $labelEn !== '' ? $labelEn : null,
        $sort,
        $active,
        $dimensionId,
    ]);
}

function orange_analytical_dimension_save_value(PDO $pdo, array $in, ?int $countryId = null): int
{
    $id = (int) ($in['id'] ?? 0);
    $dimensionId = (int) ($in['dimension_id'] ?? 0);
    $code = strtolower(trim((string) ($in['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9_\-]/', '_', $code) ?? '';
    $labelAr = trim((string) ($in['label_ar'] ?? ''));
    $labelEn = trim((string) ($in['label_en'] ?? ''));
    $sort = (int) ($in['sort_order'] ?? 0);
    $active = (int) ($in['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($dimensionId <= 0) {
        throw new InvalidArgumentException('البُعد مطلوب.');
    }
    if (orange_analytical_dimension_get($pdo, $dimensionId, $countryId) === null) {
        throw new InvalidArgumentException('البُعد غير موجود.');
    }
    if ($code === '' || strlen($code) > 32) {
        throw new InvalidArgumentException('كود القيمة مطلوب (latin، حتى 32).');
    }
    if ($labelAr === '') {
        throw new InvalidArgumentException('الاسم العربي للقيمة مطلوب.');
    }

    if ($id > 0) {
        $st = $pdo->prepare('SELECT id, dimension_id FROM analytical_dimension_value WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (! is_array($existing) || (int) ($existing['dimension_id'] ?? 0) !== $dimensionId) {
            throw new InvalidArgumentException('قيمة البُعد غير موجودة.');
        }
        $dup = $pdo->prepare(
            'SELECT id FROM analytical_dimension_value WHERE dimension_id = ? AND code = ? AND id <> ? LIMIT 1'
        );
        $dup->execute([$dimensionId, $code, $id]);
        if ($dup->fetchColumn()) {
            throw new InvalidArgumentException('كود القيمة مستخدم مسبقاً لهذا البُعد.');
        }
        $pdo->prepare(
            'UPDATE analytical_dimension_value SET code = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ? WHERE id = ?'
        )->execute([
            $code,
            $labelAr,
            $labelEn !== '' ? $labelEn : null,
            $sort,
            $active,
            $id,
        ]);

        return $id;
    }

    $dup = $pdo->prepare(
        'SELECT id FROM analytical_dimension_value WHERE dimension_id = ? AND code = ? LIMIT 1'
    );
    $dup->execute([$dimensionId, $code]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('كود القيمة مستخدم مسبقاً لهذا البُعد.');
    }
    if ($sort <= 0) {
        $stMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM analytical_dimension_value WHERE dimension_id = ?');
        $stMax->execute([$dimensionId]);
        $sort = (int) ($stMax->fetchColumn() ?: 10);
    }
    $pdo->prepare(
        'INSERT INTO analytical_dimension_value (dimension_id, code, label_ar, label_en, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $dimensionId,
        $code,
        $labelAr,
        $labelEn !== '' ? $labelEn : null,
        $active,
        $sort,
    ]);

    return (int) $pdo->lastInsertId();
}

function orange_analytical_dimension_delete_value(PDO $pdo, int $valueId, ?int $countryId = null): bool
{
    $row = orange_analytical_dimension_value_row($pdo, $valueId);
    if ($row === null) {
        return false;
    }
    $dimId = (int) ($row['dimension_id'] ?? 0);
    if ($dimId <= 0 || orange_analytical_dimension_get($pdo, $dimId, $countryId) === null) {
        return false;
    }
    if (orange_table_exists($pdo, 'journal_lines') && orange_table_has_column($pdo, 'journal_lines', 'dimension_value_id')) {
        $used = $pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE dimension_value_id = ?');
        $used->execute([$valueId]);
        if ((int) ($used->fetchColumn() ?: 0) > 0) {
            throw new InvalidArgumentException('لا يمكن حذف القيمة — مستخدمة في أسطر قيود.');
        }
    }
    $st = $pdo->prepare('DELETE FROM analytical_dimension_value WHERE id = ?');
    $st->execute([$valueId]);

    return $st->rowCount() > 0;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_analytical_dimensions_list_with_value_counts(PDO $pdo, ?int $countryId = null, bool $activeOnly = false): array
{
    orange_analytical_dimension_seed_v1($pdo);
    $dims = orange_analytical_dimensions_list($pdo, $countryId, $activeOnly);
    foreach ($dims as &$dim) {
        $dimId = (int) ($dim['id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM analytical_dimension_value WHERE dimension_id = ?');
        $st->execute([$dimId]);
        $dim['value_count'] = (int) ($st->fetchColumn() ?: 0);
    }
    unset($dim);

    return $dims;
}

/**
 * @return int|null
 */
function orange_analytical_dimension_value_id_from_line(array $ln): ?int
{
    $raw = $ln['dimension_value_id'] ?? null;
    if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
        return null;
    }
    $id = (int) $raw;

    return $id > 0 ? $id : null;
}

/**
 * @return bool
 */
function orange_acc10_reconciliation_tables_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'bank_reconciliation')
        && orange_table_exists($pdo, 'bank_reconciliation_line')
        && orange_table_exists($pdo, 'inventory_reconciliation')
        && orange_table_exists($pdo, 'inventory_reconciliation_line');
}

/**
 * Phase 0 readiness gate for ACC-10 screens.
 */
function orange_acc10_phase0_ready(PDO $pdo): bool
{
    return orange_analytical_dimensions_ready($pdo)
        && orange_acc10_reconciliation_tables_ready($pdo)
        && orange_table_has_column($pdo, 'journal_lines', 'dimension_value_id');
}

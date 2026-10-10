<?php
declare(strict_types=1);

/**
 * Product content rows for name, description, SEO title, and SEO description.
 * Full copy and restore of orange_content_locale_text is the existing database dump:
 * orange_backup_pdo_export_database / orange_backup_run_full write every column,
 * including origin and source_hash. This helper is not a second full restore.
 * Country replace stays on copy_line and promo_message. Department extract stays department-only.
 * A caller that remaps product ids must pass that map. An empty snapshot deletes nothing.
 * Does not load config.php.
 */

require_once __DIR__ . '/../orange_product_content_locale.php';

/**
 * @param list<int> $productIds
 * @return list<array<string, mixed>>
 */
function orange_content_locale_product_text_export_rows(PDO $pdo, array $productIds): array
{
    if (!orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $allowed = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => (int) $id, $productIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($allowed === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $fields = array_keys(ORANGE_PRODUCT_CONTENT_FIELDS);
    $fieldPlaceholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'SELECT t.entity_kind, t.entity_id, t.field_key, t.locale_code, t.text_value, t.origin, t.source_locale, t.source_hash'
        . ' FROM orange_content_locale_text t'
        . ' INNER JOIN products p ON p.id = t.entity_id'
        . ' WHERE t.entity_kind = ? AND t.entity_id IN (' . $placeholders . ') AND t.field_key IN (' . $fieldPlaceholders . ')';
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([ORANGE_PRODUCT_CONTENT_KIND], $allowed, $fields));
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @param list<int> $productIds
 * @return array{table_ready:bool,target_text_ids:list<int>}
 */
function orange_content_locale_product_text_capture(PDO $pdo, array $productIds): array
{
    if (!orange_content_locale_table_ready($pdo)) {
        return ['table_ready' => false, 'target_text_ids' => []];
    }
    $allowed = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => (int) $id, $productIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($allowed === []) {
        return ['table_ready' => true, 'target_text_ids' => []];
    }
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $fields = array_keys(ORANGE_PRODUCT_CONTENT_FIELDS);
    $fieldPlaceholders = implode(',', array_fill(0, count($fields), '?'));
    $st = $pdo->prepare(
        'SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id IN (' . $placeholders . ') AND field_key IN (' . $fieldPlaceholders . ')'
    );
    $st->execute(array_merge([ORANGE_PRODUCT_CONTENT_KIND], $allowed, $fields));
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $textId = (int) $id;
        if ($textId > 0) {
            $ids[] = $textId;
        }
    }

    return ['table_ready' => true, 'target_text_ids' => $ids];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>}}
 */
function orange_content_locale_product_text_plan(PDO $pdo, array $rows): array
{
    $productIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $kind = (string) ($row['entity_kind'] ?? '');
        $field = (string) ($row['field_key'] ?? '');
        if ($kind !== ORANGE_PRODUCT_CONTENT_KIND || !isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field])) {
            throw new RuntimeException('content_locale_kind_not_allowed');
        }
        $sourceId = (int) ($row['entity_id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $productIds[$sourceId] = $sourceId;
    }
    $capture = orange_content_locale_product_text_capture($pdo, array_values($productIds));
    if (!$capture['table_ready']) {
        throw new RuntimeException('content_locale_table_missing');
    }

    return ['rows' => $rows, 'capture' => $capture];
}

/**
 * @param array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>}} $plan
 * @param array<int, int> $productIdMap old product id => new product id
 */
function orange_content_locale_product_text_commit(PDO $pdo, array $plan, array $productIdMap): void
{
    $capture = is_array($plan['capture'] ?? null) ? $plan['capture'] : [];
    if (empty($capture['table_ready'])) {
        throw new RuntimeException('content_locale_table_missing');
    }
    $packageRows = is_array($plan['rows'] ?? null) ? $plan['rows'] : [];
    $resolved = [];
    foreach ($packageRows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $kind = (string) ($row['entity_kind'] ?? '');
        $field = (string) ($row['field_key'] ?? '');
        if ($kind !== ORANGE_PRODUCT_CONTENT_KIND || !isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field])) {
            throw new RuntimeException('content_locale_kind_not_allowed');
        }
        $sourceId = (int) ($row['entity_id'] ?? 0);
        if (!isset($productIdMap[$sourceId])) {
            throw new RuntimeException('content_locale_parent_id_unmapped');
        }
        $parentId = (int) $productIdMap[$sourceId];
        $exists = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $exists->execute([$parentId]);
        if ($parentId <= 0 || !$exists->fetchColumn()) {
            throw new RuntimeException('content_locale_parent_id_unmapped');
        }
        $locale = trim((string) ($row['locale_code'] ?? ''));
        if ($locale === '') {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $resolved[] = [
            'field_key' => $field,
            'entity_id' => $parentId,
            'locale_code' => $locale,
            'text_value' => (string) ($row['text_value'] ?? ''),
            'origin' => (string) ($row['origin'] ?? 'unknown'),
            'source_locale' => $row['source_locale'] ?? null,
            'source_hash' => $row['source_hash'] ?? null,
        ];
    }
    $target = is_array($capture['target_text_ids'] ?? null) ? $capture['target_text_ids'] : [];
    if ($target !== []) {
        $placeholders = implode(',', array_fill(0, count($target), '?'));
        $fields = array_keys(ORANGE_PRODUCT_CONTENT_FIELDS);
        $fieldPlaceholders = implode(',', array_fill(0, count($fields), '?'));
        $del = $pdo->prepare(
            'DELETE FROM orange_content_locale_text WHERE id IN (' . $placeholders . ') AND entity_kind = ? AND field_key IN (' . $fieldPlaceholders . ')'
        );
        $del->execute(array_merge($target, [ORANGE_PRODUCT_CONTENT_KIND], $fields));
    }
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $insert = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $update = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    foreach ($resolved as $row) {
        $existing->execute([ORANGE_PRODUCT_CONTENT_KIND, $row['entity_id'], $row['field_key'], $row['locale_code']]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            $insert->execute([
                ORANGE_PRODUCT_CONTENT_KIND,
                $row['entity_id'],
                $row['field_key'],
                $row['locale_code'],
                $row['text_value'],
                $row['origin'],
                $row['source_locale'],
                $row['source_hash'],
            ]);
            continue;
        }
        $update->execute([$row['text_value'], $row['origin'], $row['source_locale'], $row['source_hash'], (int) $id]);
    }
}

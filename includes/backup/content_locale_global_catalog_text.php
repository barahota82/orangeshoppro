<?php
declare(strict_types=1);

/**
 * Global catalogue name rows. Parents are catalog_sections, catalog_categories,
 * catalog_subcategories, and product_types. None of those parents is country-owned.
 * This snapshot stays out of country replace. Department, banner, and promo rows stay untouched.
 * Does not load config.php.
 */

require_once __DIR__ . '/../orange_catalog_name_locale.php';

/**
 * @param array<string, list<int>> $exportedParentIds
 * @return list<array<string, mixed>>
 */
function orange_content_locale_global_catalog_text_export_rows(PDO $pdo, array $exportedParentIds): array
{
    if (!orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $rows = [];
    foreach (ORANGE_CATALOG_NAME_LOCALE_KINDS as $kind => $parent) {
        $allowed = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $exportedParentIds[$parent] ?? []),
            static fn (int $id): bool => $id > 0
        )));
        if ($allowed === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $sql = 'SELECT t.entity_kind, t.entity_id, t.field_key, t.locale_code, t.text_value, t.origin, t.source_locale, t.source_hash'
            . ' FROM orange_content_locale_text t'
            . ' INNER JOIN ' . $parent . ' p ON p.id = t.entity_id'
            . ' WHERE t.entity_kind = ? AND t.field_key = ? AND t.entity_id IN (' . $placeholders . ')';
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$kind, 'name'], $allowed));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * Delete scope is the parents present in the snapshot. An empty parent set
 * captures nothing, so a partial transfer cannot wipe other global rows.
 * Full copy and restore of this table is the existing database dump:
 * orange_backup_pdo_export_database / orange_backup_run_full write every column,
 * including origin and source_hash. This helper is not a second full restore.
 *
 * @param array<string, array<int, int>> $parentIdsByKind
 * @return array{table_ready:bool,target_text_ids:list<int>}
 */
function orange_content_locale_global_catalog_text_capture(PDO $pdo, array $parentIdsByKind): array
{
    if (!orange_content_locale_table_ready($pdo)) {
        return ['table_ready' => false, 'target_text_ids' => []];
    }
    $ids = [];
    foreach ($parentIdsByKind as $kind => $parentIds) {
        if (!isset(ORANGE_CATALOG_NAME_LOCALE_KINDS[$kind]) || !is_array($parentIds)) {
            continue;
        }
        $allowed = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $parentIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($allowed === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $st = $pdo->prepare(
            'SELECT id FROM orange_content_locale_text WHERE field_key = ? AND entity_kind = ? AND entity_id IN (' . $placeholders . ')'
        );
        $st->execute(array_merge(['name', (string) $kind], $allowed));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $textId = (int) $id;
            if ($textId > 0) {
                $ids[] = $textId;
            }
        }
    }

    return ['table_ready' => true, 'target_text_ids' => $ids];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>}}
 */
function orange_content_locale_global_catalog_text_plan(PDO $pdo, array $rows): array
{
    $parentIdsByKind = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $kind = (string) ($row['entity_kind'] ?? '');
        if (!isset(ORANGE_CATALOG_NAME_LOCALE_KINDS[$kind]) || (string) ($row['field_key'] ?? '') !== 'name') {
            throw new RuntimeException('content_locale_kind_not_allowed');
        }
        $sourceId = (int) ($row['entity_id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $parentIdsByKind[$kind][$sourceId] = $sourceId;
    }
    $capture = orange_content_locale_global_catalog_text_capture($pdo, $parentIdsByKind);
    if (!$capture['table_ready']) {
        throw new RuntimeException('content_locale_table_missing');
    }

    return ['rows' => $rows, 'capture' => $capture];
}

/**
 * @param array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>}} $plan
 * @param array<string, array<int, int>> $parentIdMaps
 */
function orange_content_locale_global_catalog_text_commit(PDO $pdo, array $plan, array $parentIdMaps): void
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
        if (!isset(ORANGE_CATALOG_NAME_LOCALE_KINDS[$kind]) || (string) ($row['field_key'] ?? '') !== 'name') {
            throw new RuntimeException('content_locale_kind_not_allowed');
        }
        $sourceId = (int) ($row['entity_id'] ?? 0);
        $map = $parentIdMaps[$kind] ?? null;
        if (!is_array($map) || !isset($map[$sourceId])) {
            throw new RuntimeException('content_locale_parent_id_unmapped');
        }
        $parentId = (int) $map[$sourceId];
        $parent = ORANGE_CATALOG_NAME_LOCALE_KINDS[$kind];
        $exists = $pdo->prepare('SELECT id FROM ' . $parent . ' WHERE id = ?');
        $exists->execute([$parentId]);
        if ($parentId <= 0 || !$exists->fetchColumn()) {
            throw new RuntimeException('content_locale_parent_id_unmapped');
        }
        $locale = trim((string) ($row['locale_code'] ?? ''));
        if ($locale === '') {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $resolved[] = [
            'entity_kind' => $kind,
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
        $kinds = array_keys(ORANGE_CATALOG_NAME_LOCALE_KINDS);
        $kindPlaceholders = implode(',', array_fill(0, count($kinds), '?'));
        $del = $pdo->prepare(
            'DELETE FROM orange_content_locale_text WHERE id IN (' . $placeholders . ') AND field_key = ? AND entity_kind IN (' . $kindPlaceholders . ')'
        );
        $del->execute(array_merge($target, ['name'], $kinds));
    }
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $insert = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $update = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    foreach ($resolved as $row) {
        $existing->execute([$row['entity_kind'], $row['entity_id'], 'name', $row['locale_code']]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            $insert->execute([
                $row['entity_kind'],
                $row['entity_id'],
                'name',
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

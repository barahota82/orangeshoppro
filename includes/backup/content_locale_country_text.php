<?php
declare(strict_types=1);

/**
 * Country-scoped copy_line and promo_message texts.
 * Department rows stay on the existing department extract and are not replaced here.
 * Does not load config.php.
 */

const ORANGE_CONTENT_LOCALE_COUNTRY_KINDS = [
    'copy_line' => 'storefront_copy_lines',
    'promo_message' => 'storefront_promo_messages',
];

/**
 * @param array<string, list<int>> $exportedParentIds
 * @return list<array<string, mixed>>
 */
function orange_content_locale_country_text_export_rows(PDO $pdo, int $countryId, array $exportedParentIds): array
{
    if ($countryId <= 0 || !orange_content_locale_country_text_table_ready($pdo)) {
        return [];
    }
    $rows = [];
    foreach (ORANGE_CONTENT_LOCALE_COUNTRY_KINDS as $kind => $parent) {
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
            . ' WHERE t.entity_kind = ? AND t.field_key = ? AND p.country_id = ? AND t.entity_id IN (' . $placeholders . ')';
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$kind, 'text', $countryId], $allowed));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * Old packages have no key. That skip is compatibility, not a restore of these texts.
 *
 * @param array<string, mixed> $snapshot
 */
function orange_content_locale_country_restore_decision(array $snapshot): string
{
    if (!array_key_exists('content_locale_country_text', $snapshot)) {
        return 'legacy_package_skip';
    }

    return 'restore';
}

/**
 * @return list<array<string, mixed>>
 */
function orange_content_locale_country_text_validate_snapshot(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException('content_locale_snapshot_invalid');
    }
    foreach ($value as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
    }

    return $value;
}

/**
 * Parent ownership captured before the first parent delete.
 *
 * @return array{table_ready:bool,target_text_ids:list<int>,witness:array<string,array<int,int>>}
 */
function orange_content_locale_country_text_capture(PDO $pdo, int $countryId): array
{
    $empty = ['table_ready' => false, 'target_text_ids' => [], 'witness' => []];
    if ($countryId <= 0 || !orange_content_locale_country_text_table_ready($pdo)) {
        return $empty;
    }
    $target = [];
    $witness = [];
    foreach (ORANGE_CONTENT_LOCALE_COUNTRY_KINDS as $kind => $parent) {
        $sql = 'SELECT t.id, t.entity_id, p.country_id'
            . ' FROM orange_content_locale_text t'
            . ' INNER JOIN ' . $parent . ' p ON p.id = t.entity_id'
            . ' WHERE t.entity_kind = ? AND t.field_key = ?';
        $st = $pdo->prepare($sql);
        $st->execute([$kind, 'text']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $textId = (int) ($row['id'] ?? 0);
            $entityId = (int) ($row['entity_id'] ?? 0);
            $owner = (int) ($row['country_id'] ?? 0);
            if ($textId <= 0 || $entityId <= 0 || $owner <= 0) {
                continue;
            }
            if ($owner === $countryId) {
                $target[] = $textId;
                continue;
            }
            $witness[$kind][$entityId] = $owner;
        }
    }

    return ['table_ready' => true, 'target_text_ids' => $target, 'witness' => $witness];
}

/**
 * Validate the snapshot and capture ownership. Call this before parent clear.
 *
 * @return array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>,witness:array<string,array<int,int>>},country_id:int}
 */
function orange_content_locale_country_text_plan(PDO $pdo, int $countryId, mixed $snapshotValue): array
{
    if ($countryId <= 0) {
        throw new InvalidArgumentException('country_required');
    }
    $rows = orange_content_locale_country_text_validate_snapshot($snapshotValue);
    $capture = orange_content_locale_country_text_capture($pdo, $countryId);
    if (!$capture['table_ready']) {
        throw new RuntimeException('content_locale_table_missing');
    }

    return ['rows' => $rows, 'capture' => $capture, 'country_id' => $countryId];
}

/**
 * After parent import. Refusal happens before any text delete.
 *
 * @param array{rows:list<array<string,mixed>>,capture:array{table_ready:bool,target_text_ids:list<int>,witness:array<string,array<int,int>>},country_id:int} $plan
 * @param array<string, array<int, int>> $parentIdMaps
 */
function orange_content_locale_country_text_commit(PDO $pdo, array $plan, array $parentIdMaps = []): void
{
    $countryId = (int) ($plan['country_id'] ?? 0);
    $capture = is_array($plan['capture'] ?? null) ? $plan['capture'] : [];
    if ($countryId <= 0 || empty($capture['table_ready'])) {
        throw new RuntimeException('content_locale_table_missing');
    }
    if (!orange_content_locale_country_text_table_ready($pdo)) {
        throw new RuntimeException('content_locale_table_missing');
    }
    $packageRows = is_array($plan['rows'] ?? null) ? $plan['rows'] : [];
    $witness = is_array($capture['witness'] ?? null) ? $capture['witness'] : [];
    $imported = [];
    foreach (ORANGE_CONTENT_LOCALE_COUNTRY_KINDS as $kind => $parent) {
        $st = $pdo->prepare('SELECT id FROM ' . $parent . ' WHERE country_id = ?');
        $st->execute([$countryId]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $parentId = (int) $id;
            if ($parentId <= 0) {
                continue;
            }
            if (isset($witness[$kind][$parentId])) {
                throw new RuntimeException('content_locale_parent_id_conflict');
            }
            $ids[$parentId] = true;
        }
        $imported[$kind] = $ids;
    }
    $resolved = [];
    foreach ($packageRows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $kind = (string) ($row['entity_kind'] ?? '');
        if (!isset(ORANGE_CONTENT_LOCALE_COUNTRY_KINDS[$kind]) || (string) ($row['field_key'] ?? '') !== 'text') {
            throw new RuntimeException('content_locale_kind_not_allowed');
        }
        $sourceId = (int) ($row['entity_id'] ?? 0);
        $map = $parentIdMaps[$kind] ?? [];
        if ($map !== []) {
            if (!isset($map[$sourceId])) {
                throw new RuntimeException('content_locale_parent_id_unmapped');
            }
            $parentId = (int) $map[$sourceId];
        } else {
            $parentId = $sourceId;
        }
        if (isset($witness[$kind][$parentId])) {
            throw new RuntimeException('content_locale_parent_id_conflict');
        }
        if ($parentId <= 0 || !isset($imported[$kind][$parentId])) {
            throw new RuntimeException('content_locale_parent_id_unmapped');
        }
        $locale = strtolower(trim((string) ($row['locale_code'] ?? '')));
        if (!preg_match('/^[a-z]{2,3}$/', $locale)) {
            throw new RuntimeException('content_locale_snapshot_invalid');
        }
        $resolved[] = [
            'entity_kind' => $kind,
            'entity_id' => $parentId,
            'locale_code' => $locale,
            'text_value' => (string) ($row['text_value'] ?? ''),
            'origin' => (string) ($row['origin'] ?? 'unknown'),
            'source_locale' => $row['source_locale'] !== null && $row['source_locale'] !== '' ? (string) $row['source_locale'] : null,
            'source_hash' => $row['source_hash'] !== null && $row['source_hash'] !== '' ? (string) $row['source_hash'] : null,
        ];
    }
    $targetIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $capture['target_text_ids'] ?? [])));
    if ($targetIds !== []) {
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $del = $pdo->prepare(
            'DELETE FROM orange_content_locale_text WHERE id IN (' . $placeholders . ') AND field_key = ? AND entity_kind IN (\'copy_line\', \'promo_message\')'
        );
        $del->execute(array_merge($targetIds, ['text']));
    }
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $insert = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $update = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    foreach ($resolved as $row) {
        $existing->execute([$row['entity_kind'], $row['entity_id'], 'text', $row['locale_code']]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            $insert->execute([
                $row['entity_kind'],
                $row['entity_id'],
                'text',
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

/**
 * Direct apply without a pre-delete capture is not a restore.
 *
 * @param list<array<string, mixed>> $packageRows
 * @param array<string, array<int, int>> $parentIdMaps
 */
function orange_content_locale_country_text_apply(PDO $pdo, int $countryId, array $packageRows, array $parentIdMaps = []): void
{
    unset($pdo, $countryId, $packageRows, $parentIdMaps);
    throw new RuntimeException('content_locale_capture_required');
}

function orange_content_locale_country_parent_exists(PDO $pdo, string $kind, int $parentId, int $countryId): bool
{
    $parent = ORANGE_CONTENT_LOCALE_COUNTRY_KINDS[$kind] ?? '';
    if ($parent === '' || $parentId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM ' . $parent . ' WHERE id = ? AND country_id = ?');
    $st->execute([$parentId, $countryId]);

    return (bool) $st->fetchColumn();
}

function orange_content_locale_country_text_table_ready(PDO $pdo): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute(['orange_content_locale_text']);
            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute(['orange_content_locale_text']);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

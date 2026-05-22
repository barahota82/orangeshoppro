<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/country_catalog_copy.php';
require_once __DIR__ . '/delivery_areas.php';

/**
 * @return array{
 *   warehouse:bool,
 *   channels_count:int,
 *   products_count:int,
 *   accounts_count:int,
 *   gl_settings_count:int,
 *   has_governorate:bool,
 *   team_users_count:int
 * }
 */
function orange_country_provision_status(PDO $pdo, int $countryId): array
{
    $out = [
        'warehouse' => false,
        'channels_count' => 0,
        'products_count' => 0,
        'accounts_count' => 0,
        'gl_settings_count' => 0,
        'has_governorate' => false,
        'team_users_count' => 0,
    ];
    if ($countryId <= 0) {
        return $out;
    }
    if (orange_table_exists($pdo, 'warehouses')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['warehouse'] = (int) $st->fetchColumn() > 0;
    }
    if (orange_table_exists($pdo, 'channels') && orange_channels_has_country_column($pdo)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM channels WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['channels_count'] = (int) $st->fetchColumn();
    }
    if (orange_table_exists($pdo, 'products') && orange_table_has_country_id($pdo, 'products')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['products_count'] = (int) $st->fetchColumn();
    }
    if (orange_table_exists($pdo, 'accounts') && orange_table_has_column($pdo, 'accounts', 'country_id')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['accounts_count'] = (int) $st->fetchColumn();
    }
    if (orange_table_exists($pdo, 'orange_gl_account_settings')
        && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM orange_gl_account_settings WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['gl_settings_count'] = (int) $st->fetchColumn();
    }
    if (orange_delivery_governorates_table_exists($pdo)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM delivery_governorates WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['has_governorate'] = (int) $st->fetchColumn() > 0;
    }
    if (orange_table_exists($pdo, 'admins') && orange_table_has_column($pdo, 'admins', 'country_id')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE country_id = ? AND is_active = 1');
        $st->execute([$countryId]);
        $out['team_users_count'] = (int) $st->fetchColumn();
    }

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, channels_copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_channels_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'channels_copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0 || !orange_table_exists($pdo, 'channels') || !orange_channels_has_country_column($pdo)) {
        $out['reason'] = 'no_channels_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source_country';

        return $out;
    }

    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM channels WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_channels';

        return $out;
    }

    $stSrc = $pdo->prepare(
        'SELECT * FROM channels WHERE country_id = ? ORDER BY id ASC'
    );
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }

    $hasKind = orange_table_has_column($pdo, 'channels', 'channel_kind');
    $defaultWhNum = 1;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        unset($row['id'], $row['updated_at']);
        $row['country_id'] = $targetCountryId;
        $row['whatsapp_number'] = '';
        $row['is_active'] = (int) ($row['is_active'] ?? 1);
        if (!isset($row['warehouse_number']) || (int) $row['warehouse_number'] <= 0) {
            $row['warehouse_number'] = $defaultWhNum;
        }
        if ($hasKind && !isset($row['channel_kind'])) {
            $row['channel_kind'] = 'web';
        }

        $cols = array_keys($row);
        $sql = 'INSERT INTO channels (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        try {
            $pdo->prepare($sql)->execute(array_values($row));
            $out['channels_copied']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] country channel copy: ' . $e->getMessage());
            }
        }
    }

    $out['skipped'] = $out['channels_copied'] <= 0;
    $out['reason'] = $out['channels_copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, accounts_copied:int, id_map:array<int,int>, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_accounts_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'accounts_copied' => 0,
        'id_map' => [],
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0 || !orange_table_exists($pdo, 'accounts')
        || !orange_table_has_column($pdo, 'accounts', 'country_id')) {
        $out['reason'] = 'no_accounts_country';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source_country';

        return $out;
    }

    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_accounts';

        return $out;
    }

    $stSrc = $pdo->prepare('SELECT * FROM accounts WHERE country_id = ? ORDER BY id ASC');
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }

    $rows = orange_country_accounts_sort_by_depth($rows);
    $idMap = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $oldId = (int) ($row['id'] ?? 0);
        unset($row['id'], $row['updated_at']);
        $row['country_id'] = $targetCountryId;
        $oldParent = (int) ($row['parent_id'] ?? 0);
        if ($oldParent > 0 && isset($idMap[$oldParent])) {
            $row['parent_id'] = $idMap[$oldParent];
        } elseif ($oldParent > 0) {
            $row['parent_id'] = null;
        }

        $cols = array_keys($row);
        $sql = 'INSERT INTO accounts (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        try {
            $pdo->prepare($sql)->execute(array_values($row));
            $newId = (int) $pdo->lastInsertId();
            if ($oldId > 0 && $newId > 0) {
                $idMap[$oldId] = $newId;
                $out['accounts_copied']++;
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] country accounts copy #' . $oldId . ': ' . $e->getMessage());
            }
        }
    }

    $out['id_map'] = $idMap;
    $out['skipped'] = $out['accounts_copied'] <= 0;
    $out['reason'] = $out['accounts_copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @param array<int,int> $accountIdMap
 * @return array{skipped:bool, reason:string, settings_copied:int, alloc_copied:int}
 */
function orange_country_copy_gl_settings_from_source(
    PDO $pdo,
    int $targetCountryId,
    ?int $sourceCountryId = null,
    array $accountIdMap = []
): array {
    $out = [
        'skipped' => true,
        'reason' => '',
        'settings_copied' => 0,
        'alloc_copied' => 0,
    ];
    if ($targetCountryId <= 0) {
        $out['reason'] = 'invalid_target';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source_country';

        return $out;
    }

    if (orange_table_exists($pdo, 'orange_gl_account_settings')
        && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM orange_gl_account_settings WHERE country_id = ?');
        $stCnt->execute([$targetCountryId]);
        if ((int) $stCnt->fetchColumn() === 0) {
            $hasJt = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id');
            if ($hasJt) {
                $stSrc = $pdo->prepare(
                    'SELECT setting_key, account_id, journal_type_id FROM orange_gl_account_settings WHERE country_id = ?'
                );
            } else {
                $stSrc = $pdo->prepare(
                    'SELECT setting_key, account_id FROM orange_gl_account_settings WHERE country_id = ?'
                );
            }
            $stSrc->execute([$sourceCountryId]);
            foreach ($stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = trim((string) ($r['setting_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $srcAcct = (int) ($r['account_id'] ?? 0);
                $tgtAcct = $srcAcct > 0 && isset($accountIdMap[$srcAcct]) ? (int) $accountIdMap[$srcAcct] : $srcAcct;
                try {
                    if ($hasJt) {
                        $ins = $pdo->prepare(
                            'INSERT INTO orange_gl_account_settings (setting_key, country_id, account_id, journal_type_id)
                             VALUES (?, ?, ?, ?)'
                        );
                        $ins->execute([$key, $targetCountryId, $tgtAcct > 0 ? $tgtAcct : null, $r['journal_type_id'] ?? null]);
                    } else {
                        $ins = $pdo->prepare(
                            'INSERT INTO orange_gl_account_settings (setting_key, country_id, account_id)
                             VALUES (?, ?, ?)'
                        );
                        $ins->execute([$key, $targetCountryId, $tgtAcct > 0 ? $tgtAcct : null]);
                    }
                    $out['settings_copied']++;
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('[orange] country gl setting copy ' . $key . ': ' . $e->getMessage());
                    }
                }
            }
        } else {
            $out['reason'] = 'target_has_gl_settings';
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_setting_alloc')
        && orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'country_id')) {
        $stCntA = $pdo->prepare('SELECT COUNT(*) FROM orange_gl_setting_alloc WHERE country_id = ?');
        $stCntA->execute([$targetCountryId]);
        if ((int) $stCntA->fetchColumn() === 0) {
            $stSrcA = $pdo->prepare(
                'SELECT setting_key, percent_value FROM orange_gl_setting_alloc WHERE country_id = ?'
            );
            $stSrcA->execute([$sourceCountryId]);
            foreach ($stSrcA->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = trim((string) ($r['setting_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                try {
                    $ins = $pdo->prepare(
                        'INSERT INTO orange_gl_setting_alloc (setting_key, country_id, percent_value) VALUES (?, ?, ?)'
                    );
                    $ins->execute([$key, $targetCountryId, $r['percent_value'] ?? 0]);
                    $out['alloc_copied']++;
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('[orange] country gl alloc copy ' . $key . ': ' . $e->getMessage());
                    }
                }
            }
        }
    }

    $out['skipped'] = $out['settings_copied'] <= 0 && $out['alloc_copied'] <= 0;
    if ($out['reason'] === '' && !$out['skipped']) {
        $out['reason'] = 'copied';
    } elseif ($out['reason'] === '' && $out['skipped']) {
        $out['reason'] = 'nothing_copied';
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function orange_country_accounts_sort_by_depth(array $rows): array
{
    $byId = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $r;
        }
    }
    $depthCache = [];
    $depthFn = static function (int $id) use (&$depthFn, &$byId, &$depthCache): int {
        if ($id <= 0) {
            return 0;
        }
        if (isset($depthCache[$id])) {
            return $depthCache[$id];
        }
        $pid = (int) ($byId[$id]['parent_id'] ?? 0);
        $depthCache[$id] = $pid <= 0 ? 0 : $depthFn($pid) + 1;

        return $depthCache[$id];
    };
    usort($rows, static function (array $a, array $b) use ($depthFn): int {
        return $depthFn((int) ($a['id'] ?? 0)) <=> $depthFn((int) ($b['id'] ?? 0));
    });

    return $rows;
}

/**
 * @return array{skipped:bool, reason:string, copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_journal_types_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0
        || !orange_table_exists($pdo, 'journal_types')
        || !orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM journal_types WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_rows';

        return $out;
    }
    $stSrc = $pdo->prepare('SELECT code, name_ar, name_en, sort_order FROM journal_types WHERE country_id = ? ORDER BY sort_order ASC, id ASC');
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    $ins = $pdo->prepare(
        'INSERT INTO journal_types (country_id, code, name_ar, name_en, sort_order) VALUES (?,?,?,?,?)'
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $ins->execute([
                $targetCountryId,
                (string) ($row['code'] ?? ''),
                (string) ($row['name_ar'] ?? ''),
                (string) ($row['name_en'] ?? ''),
                (int) ($row['sort_order'] ?? 0),
            ]);
            $out['copied']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] copy journal_types: ' . $e->getMessage());
            }
        }
    }
    $out['skipped'] = $out['copied'] <= 0;
    $out['reason'] = $out['copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_gl_journal_type_rules_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0
        || !orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        || !orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id')
        || !orange_table_exists($pdo, 'journal_types')
        || !orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM orange_gl_journal_type_rules WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_rows';

        return $out;
    }
    $stSrc = $pdo->prepare(
        'SELECT jt.code, r.payment_terms, r.debit_setting_key, r.credit_setting_key
         FROM orange_gl_journal_type_rules r
         INNER JOIN journal_types jt ON jt.id = r.journal_type_id
         WHERE r.country_id = ?
         ORDER BY r.id ASC'
    );
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    $stTgtJt = $pdo->prepare('SELECT id FROM journal_types WHERE country_id = ? AND code = ? LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO orange_gl_journal_type_rules (country_id, journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
         VALUES (?,?,?,?,?)'
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $stTgtJt->execute([$targetCountryId, $code]);
        $tgtJtId = (int) ($stTgtJt->fetchColumn() ?: 0);
        if ($tgtJtId <= 0) {
            continue;
        }
        try {
            $ins->execute([
                $targetCountryId,
                $tgtJtId,
                (string) ($row['payment_terms'] ?? ''),
                (string) ($row['debit_setting_key'] ?? ''),
                (string) ($row['credit_setting_key'] ?? ''),
            ]);
            $out['copied']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] copy gl_journal_type_rules ' . $code . ': ' . $e->getMessage());
            }
        }
    }
    $out['skipped'] = $out['copied'] <= 0;
    $out['reason'] = $out['copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_fiscal_years_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0
        || !orange_table_exists($pdo, 'fiscal_years')
        || !orange_table_has_column($pdo, 'fiscal_years', 'country_id')) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM fiscal_years WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_rows';

        return $out;
    }
    $stSrc = $pdo->prepare(
        'SELECT label_ar, start_date, end_date, is_closed, closed_at FROM fiscal_years WHERE country_id = ? ORDER BY start_date ASC, id ASC'
    );
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    $ins = $pdo->prepare(
        'INSERT INTO fiscal_years (country_id, label_ar, start_date, end_date, is_closed, closed_at) VALUES (?,?,?,?,?,?)'
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $ins->execute([
                $targetCountryId,
                (string) ($row['label_ar'] ?? ''),
                (string) ($row['start_date'] ?? ''),
                (string) ($row['end_date'] ?? ''),
                (int) ($row['is_closed'] ?? 0),
                $row['closed_at'] ?? null,
            ]);
            $out['copied']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] copy fiscal_years: ' . $e->getMessage());
            }
        }
    }
    $out['skipped'] = $out['copied'] <= 0;
    $out['reason'] = $out['copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_company_settings_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0
        || !orange_table_exists($pdo, 'company_settings')
        || !orange_table_has_column($pdo, 'company_settings', 'country_id')) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM company_settings WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_rows';

        return $out;
    }
    $stSrc = $pdo->prepare('SELECT * FROM company_settings WHERE country_id = ? LIMIT 1');
    $stSrc->execute([$sourceCountryId]);
    $row = $stSrc->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    $ins = $pdo->prepare(
        'INSERT INTO company_settings (country_id, company_name_ar, company_name_en, company_logo, commercial_register, phones, address, vat_number, invoice_footer)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    try {
        $ins->execute([
            $targetCountryId,
            (string) ($row['company_name_ar'] ?? ''),
            (string) ($row['company_name_en'] ?? ''),
            (string) ($row['company_logo'] ?? ''),
            (string) ($row['commercial_register'] ?? ''),
            (string) ($row['phones'] ?? ''),
            (string) ($row['address'] ?? ''),
            (string) ($row['vat_number'] ?? ''),
            $row['invoice_footer'] ?? null,
        ]);
        $out['copied'] = 1;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] copy company_settings: ' . $e->getMessage());
        }
    }
    $out['skipped'] = $out['copied'] <= 0;
    $out['reason'] = $out['copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * @return array{skipped:bool, reason:string, copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_storefront_copy_lines_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0
        || !orange_table_exists($pdo, 'storefront_copy_lines')
        || !orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id')) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM storefront_copy_lines WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_rows';

        return $out;
    }
    $stSrc = $pdo->prepare(
        'SELECT scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi
         FROM storefront_copy_lines WHERE country_id = ? ORDER BY scope ASC, sort_order ASC, id ASC'
    );
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    $ins = $pdo->prepare(
        'INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $ins->execute([
                $targetCountryId,
                (string) ($row['scope'] ?? ''),
                (int) ($row['sort_order'] ?? 0),
                (int) ($row['is_active'] ?? 1),
                (string) ($row['text_ar'] ?? ''),
                (string) ($row['text_en'] ?? ''),
                (string) ($row['text_fil'] ?? ''),
                (string) ($row['text_hi'] ?? ''),
            ]);
            $out['copied']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] copy storefront_copy_lines: ' . $e->getMessage());
            }
        }
    }
    $out['skipped'] = $out['copied'] <= 0;
    $out['reason'] = $out['copied'] > 0 ? 'copied' : 'copy_failed';

    return $out;
}

/**
 * تهيئة تشغيلية كاملة لدولة جديدة — idempotent (يتخطى ما وُجد مسبقاً).
 *
 * @return array<string,mixed>
 */
function orange_country_provision_full(PDO $pdo, int $countryId, ?int $sourceCountryId = null): array
{
    $out = [
        'warehouse_id' => 0,
        'channel_id' => 0,
        'created_warehouse' => false,
        'created_channel' => false,
        'catalog_copy' => [],
        'channels_copy' => [],
        'accounts_copy' => [],
        'gl_settings_copy' => [],
        'governorate_id' => 0,
        'created_governorate' => false,
        'journal_types_copy' => [],
        'fiscal_years_copy' => [],
        'company_settings_copy' => [],
        'storefront_copy_lines_copy' => [],
    ];
    if ($countryId <= 0) {
        return $out;
    }

    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);

    $whBefore = 0;
    if (orange_table_exists($pdo, 'warehouses')) {
        $stWh = $pdo->prepare('SELECT id FROM warehouses WHERE country_id = ? LIMIT 1');
        $stWh->execute([$countryId]);
        $whBefore = (int) ($stWh->fetchColumn() ?: 0);
    }
    $wid = orange_warehouse_ensure_default_for_country($pdo, $countryId);
    $out['warehouse_id'] = $wid;
    $out['created_warehouse'] = $whBefore <= 0 && $wid > 0;

    $out['channels_copy'] = orange_country_copy_channels_from_source($pdo, $countryId, $sourceCountryId);

    if ((int) ($out['channels_copy']['channels_copied'] ?? 0) <= 0
        && orange_table_exists($pdo, 'channels')
        && orange_channels_has_country_column($pdo)) {
        $stCh = $pdo->prepare('SELECT id FROM channels WHERE country_id = ? ORDER BY id ASC LIMIT 1');
        $stCh->execute([$countryId]);
        $existingCh = (int) ($stCh->fetchColumn() ?: 0);
        if ($existingCh > 0) {
            $out['channel_id'] = $existingCh;
        } else {
            $row = orange_country_row_by_id($pdo, $countryId, false);
            if ($row !== null) {
                $code = orange_countries_normalize_code((string) ($row['code'] ?? ''));
                $nameAr = trim((string) ($row['name_ar'] ?? ''));
                $nameEn = trim((string) ($row['name_en'] ?? ''));
                $chName = $nameAr !== '' ? ($nameAr . ' — ويب') : ($nameEn !== '' ? $nameEn . ' — web' : 'Web');
                $slug = $code !== '' ? $code . '-web' : ('c' . $countryId . '-web');
                $pathSegment = 'web';
                $defaultWhNum = 1;
                if (orange_table_has_column($pdo, 'channels', 'channel_kind')) {
                    $ins = $pdo->prepare(
                        'INSERT INTO channels (name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id, channel_kind)
                         VALUES (?, ?, ?, \'\', \'\', ?, 1, ?, \'web\')'
                    );
                    $ins->execute([$chName, $slug, $pathSegment, $defaultWhNum, $countryId]);
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO channels (name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id)
                         VALUES (?, ?, ?, \'\', \'\', ?, 1, ?)'
                    );
                    $ins->execute([$chName, $slug, $pathSegment, $defaultWhNum, $countryId]);
                }
                $cid = (int) $pdo->lastInsertId();
                $out['channel_id'] = $cid;
                $out['created_channel'] = $cid > 0;
            }
        }
    } else {
        $stCh = $pdo->prepare('SELECT id FROM channels WHERE country_id = ? ORDER BY id ASC LIMIT 1');
        $stCh->execute([$countryId]);
        $out['channel_id'] = (int) ($stCh->fetchColumn() ?: 0);
        $out['created_channel'] = (int) ($out['channels_copy']['channels_copied'] ?? 0) > 0;
    }

    $out['catalog_copy'] = orange_country_copy_catalog_from_source($pdo, $countryId, $sourceCountryId);
    $out['accounts_copy'] = orange_country_copy_accounts_from_source($pdo, $countryId, $sourceCountryId);
    $idMap = is_array($out['accounts_copy']['id_map'] ?? null) ? $out['accounts_copy']['id_map'] : [];
    $out['gl_settings_copy'] = orange_country_copy_gl_settings_from_source(
        $pdo,
        $countryId,
        $sourceCountryId,
        $idMap
    );

    require_once __DIR__ . '/department_countries.php';
    $out['departments_copy'] = orange_department_countries_copy_from_source($pdo, $countryId, $sourceCountryId);
    if ((int) ($out['departments_copy']['copied'] ?? 0) <= 0) {
        orange_department_countries_seed_for_new_country($pdo, $countryId, false);
        $out['departments_copy']['reason'] = 'seeded_inactive';
        $out['departments_copy']['skipped'] = false;
    }

    $out['journal_types_copy'] = orange_country_copy_journal_types_from_source($pdo, $countryId, $sourceCountryId);
    $out['gl_journal_type_rules_copy'] = orange_country_copy_gl_journal_type_rules_from_source($pdo, $countryId, $sourceCountryId);
    $out['fiscal_years_copy'] = orange_country_copy_fiscal_years_from_source($pdo, $countryId, $sourceCountryId);
    $out['company_settings_copy'] = orange_country_copy_company_settings_from_source($pdo, $countryId, $sourceCountryId);
    $out['storefront_copy_lines_copy'] = orange_country_copy_storefront_copy_lines_from_source($pdo, $countryId, $sourceCountryId);

    if (orange_delivery_governorates_table_exists($pdo)) {
        $govBefore = 0;
        $stG = $pdo->prepare('SELECT id FROM delivery_governorates WHERE country_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1');
        $stG->execute([$countryId]);
        $govBefore = (int) ($stG->fetchColumn() ?: 0);
        $gid = orange_delivery_governorate_ensure_default($pdo, $countryId);
        $out['governorate_id'] = $gid;
        $out['created_governorate'] = $govBefore <= 0 && $gid > 0;
    }

    $out['status'] = orange_country_provision_status($pdo, $countryId);

    return $out;
}

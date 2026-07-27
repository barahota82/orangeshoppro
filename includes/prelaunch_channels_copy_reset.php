<?php

declare(strict_types=1);

/**
 * One-time pre-launch reset: channels + storefront_copy_lines (full) + AUTO_INCREMENT=1.
 * Owner 2026-07-27 — Pre-Phase-4 Safe Setup Data Reset.
 *
 * Dry-run default. Apply only with explicit confirm token.
 * Does not touch storefront_home_hero, promo messages, products, orders, media, GL.
 *
 * @see scripts/reset_prelaunch_channels_and_storefront_copy_lines.php
 */

if (!function_exists('orange_prelaunch_reset_confirm_token')) {
    function orange_prelaunch_reset_confirm_token(): string
    {
        return 'RESET_PRELAUNCH_CHANNELS_AND_STOREFRONT_COPY_LINES';
    }
}

if (!function_exists('orange_prelaunch_reset_allowed_copy_scopes')) {
    /** @return list<string> */
    function orange_prelaunch_reset_allowed_copy_scopes(): array
    {
        return ['header_tagline', 'home_hero'];
    }
}

if (!function_exists('orange_prelaunch_reset_table_exists')) {
    function orange_prelaunch_reset_table_exists(PDO $pdo, string $table): bool
    {
        if (function_exists('orange_table_exists')) {
            return orange_table_exists($pdo, $table);
        }
        try {
            $pdo->query('SELECT 1 FROM `' . str_replace('`', '``', $table) . '` LIMIT 1');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('orange_prelaunch_reset_count')) {
    /** @param list<mixed> $params */
    function orange_prelaunch_reset_count(PDO $pdo, string $sql, array $params = []): int
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }
}

if (!function_exists('orange_prelaunch_reset_auto_increment')) {
    function orange_prelaunch_reset_auto_increment(PDO $pdo, string $table): ?int
    {
        if (!orange_prelaunch_reset_table_exists($pdo, $table)) {
            return null;
        }
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $pdo->query('SELECT seq FROM sqlite_sequence WHERE name = ' . $pdo->quote($table));
                if ($st === false) {
                    return 1;
                }
                $v = $st->fetchColumn();

                return $v === false ? 1 : ((int) $v + 1);
            }
            $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($db === '') {
                return null;
            }
            $st = $pdo->prepare(
                'SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
            );
            $st->execute([$db, $table]);
            $v = $st->fetchColumn();

            return $v === false || $v === null ? null : (int) $v;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('orange_prelaunch_reset_legacy_hero_visible_content')) {
    /**
     * Non-empty text in storefront_home_hero that storefront would show after copy_lines empty.
     *
     * @return array{row_count:int, non_empty_fields:list<string>, has_visible:bool}
     */
    function orange_prelaunch_reset_legacy_hero_visible_content(PDO $pdo): array
    {
        $out = [
            'row_count' => 0,
            'non_empty_fields' => [],
            'has_visible' => false,
        ];
        if (!orange_prelaunch_reset_table_exists($pdo, 'storefront_home_hero')) {
            return $out;
        }
        $out['row_count'] = orange_prelaunch_reset_count($pdo, 'SELECT COUNT(*) FROM storefront_home_hero');
        if ($out['row_count'] === 0) {
            return $out;
        }
        try {
            $st = $pdo->query('SELECT * FROM storefront_home_hero');
            while ($row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false) {
                if (!is_array($row)) {
                    break;
                }
                foreach ($row as $col => $val) {
                    if (!is_string($col) || $col === 'id' || $col === 'updated_at') {
                        continue;
                    }
                    if (trim((string) $val) !== '') {
                        $out['non_empty_fields'][] = $col;
                    }
                }
            }
        } catch (Throwable $e) {
            return $out;
        }
        $out['non_empty_fields'] = array_values(array_unique($out['non_empty_fields']));
        $out['has_visible'] = $out['non_empty_fields'] !== [];

        return $out;
    }
}

if (!function_exists('orange_prelaunch_reset_inspect')) {
    /**
     * @return array<string,mixed>
     */
    function orange_prelaunch_reset_inspect(PDO $pdo): array
    {
        $allowedScopes = orange_prelaunch_reset_allowed_copy_scopes();
        $out = [
            'targets' => [
                'channels' => true,
                'product_channels' => true,
                'storefront_copy_lines' => true,
            ],
            'untouched' => [
                'storefront_home_hero' => 'legacy fallback; never deleted by this tool',
                'storefront_promo_messages' => 'out of scope',
                'products' => 'out of scope',
                'orders' => 'business; blocker if channel_id set',
                'sales_returns' => 'business; blocker if channel_id set',
                'customers' => 'out of scope',
                'media_uploads' => 'never deleted',
            ],
            'channels' => [
                'row_count' => 0,
                'auto_increment' => null,
            ],
            'product_channels' => [
                'row_count' => 0,
                'class' => 'PURE_SETUP_CHILD',
            ],
            'business_refs' => [
                'orders_with_channel_id' => 0,
                'sales_returns_with_channel_id' => 0,
            ],
            'soft_refs' => [
                'storefront_accounts_registered_channel_slug' => 0,
                'merge_requests_proposed_channel_slug' => 0,
            ],
            'storefront_copy_lines' => [
                'row_count_all' => 0,
                'header_tagline' => 0,
                'home_hero' => 0,
                'scopes' => [],
                'other_scopes' => [],
                'auto_increment' => null,
                'only_allowed_scopes' => true,
            ],
            'legacy_hero' => [
                'row_count' => 0,
                'non_empty_fields' => [],
                'has_visible' => false,
                'is_storefront_fallback' => true,
            ],
            'blockers' => [],
            'apply_allowed' => false,
        ];

        if (orange_prelaunch_reset_table_exists($pdo, 'channels')) {
            $out['channels']['row_count'] = orange_prelaunch_reset_count($pdo, 'SELECT COUNT(*) FROM channels');
            $out['channels']['auto_increment'] = orange_prelaunch_reset_auto_increment($pdo, 'channels');
        }
        if (orange_prelaunch_reset_table_exists($pdo, 'product_channels')) {
            $out['product_channels']['row_count'] = orange_prelaunch_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM product_channels'
            );
        }

        if (orange_prelaunch_reset_table_exists($pdo, 'orders')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'orders', 'channel_id')) {
            $n = orange_prelaunch_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM orders WHERE channel_id IS NOT NULL AND channel_id > 0'
            );
            $out['business_refs']['orders_with_channel_id'] = $n;
            if ($n > 0) {
                $out['blockers'][] = [
                    'code' => 'BUSINESS_ORDERS_CHANNEL',
                    'class' => 'BUSINESS_REFERENCE',
                    'rows' => $n,
                    'detail' => 'orders.channel_id > 0',
                ];
            }
        }
        if (orange_prelaunch_reset_table_exists($pdo, 'sales_returns')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
            $n = orange_prelaunch_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM sales_returns WHERE channel_id IS NOT NULL AND channel_id > 0'
            );
            $out['business_refs']['sales_returns_with_channel_id'] = $n;
            if ($n > 0) {
                $out['blockers'][] = [
                    'code' => 'BUSINESS_SALES_RETURNS_CHANNEL',
                    'class' => 'BUSINESS_REFERENCE',
                    'rows' => $n,
                    'detail' => 'sales_returns.channel_id > 0',
                ];
            }
        }

        if (orange_prelaunch_reset_table_exists($pdo, 'storefront_accounts')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
            $n = orange_prelaunch_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_accounts
                 WHERE registered_channel_slug IS NOT NULL AND TRIM(registered_channel_slug) <> ''"
            );
            $out['soft_refs']['storefront_accounts_registered_channel_slug'] = $n;
            if ($n > 0) {
                $out['blockers'][] = [
                    'code' => 'SOFT_REF_STOREFRONT_ACCOUNTS',
                    'class' => 'SOFT_REFERENCE',
                    'rows' => $n,
                    'detail' => 'storefront_accounts.registered_channel_slug',
                ];
            }
        }
        if (orange_prelaunch_reset_table_exists($pdo, 'storefront_phone_merge_requests')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
            $n = orange_prelaunch_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_phone_merge_requests
                 WHERE proposed_channel_slug IS NOT NULL AND TRIM(proposed_channel_slug) <> ''"
            );
            $out['soft_refs']['merge_requests_proposed_channel_slug'] = $n;
            if ($n > 0) {
                $out['blockers'][] = [
                    'code' => 'SOFT_REF_MERGE_REQUESTS',
                    'class' => 'SOFT_REFERENCE',
                    'rows' => $n,
                    'detail' => 'storefront_phone_merge_requests.proposed_channel_slug',
                ];
            }
        }

        if (orange_prelaunch_reset_table_exists($pdo, 'storefront_copy_lines')) {
            $out['storefront_copy_lines']['row_count_all'] = orange_prelaunch_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM storefront_copy_lines'
            );
            $out['storefront_copy_lines']['header_tagline'] = orange_prelaunch_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'header_tagline'"
            );
            $out['storefront_copy_lines']['home_hero'] = orange_prelaunch_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'home_hero'"
            );
            $out['storefront_copy_lines']['auto_increment'] = orange_prelaunch_reset_auto_increment(
                $pdo,
                'storefront_copy_lines'
            );
            $scopes = [];
            try {
                $st = $pdo->query(
                    'SELECT scope, COUNT(*) AS c FROM storefront_copy_lines GROUP BY scope ORDER BY scope ASC'
                );
                while ($row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false) {
                    if (!is_array($row)) {
                        break;
                    }
                    $scope = (string) ($row['scope'] ?? '');
                    $scopes[$scope] = (int) ($row['c'] ?? 0);
                }
            } catch (Throwable $e) {
                $scopes = [];
            }
            $out['storefront_copy_lines']['scopes'] = $scopes;
            $other = [];
            foreach ($scopes as $scope => $c) {
                if (!in_array($scope, $allowedScopes, true)) {
                    $other[$scope] = $c;
                }
            }
            $out['storefront_copy_lines']['other_scopes'] = $other;
            $out['storefront_copy_lines']['only_allowed_scopes'] = $other === [];
            if ($other !== []) {
                $out['blockers'][] = [
                    'code' => 'COPY_LINES_OTHER_SCOPE',
                    'class' => 'SCOPE_GUARD',
                    'rows' => array_sum($other),
                    'detail' => json_encode($other, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $legacy = orange_prelaunch_reset_legacy_hero_visible_content($pdo);
        $out['legacy_hero'] = array_merge($out['legacy_hero'], $legacy);
        if (!empty($legacy['has_visible'])) {
            $out['blockers'][] = [
                'code' => 'LEGACY_HERO_FALLBACK_BLOCKER',
                'class' => 'LEGACY_FALLBACK',
                'rows' => (int) ($legacy['row_count'] ?? 0),
                'detail' => implode(',', $legacy['non_empty_fields'] ?? []),
            ];
        }

        $out['apply_allowed'] = $out['blockers'] === [];

        return $out;
    }
}

if (!function_exists('orange_prelaunch_reset_format_report')) {
    /**
     * ASCII KEY=VALUE dry-run / apply report (Plesk-safe).
     *
     * @param array<string,mixed> $inspect
     */
    function orange_prelaunch_reset_format_report(array $inspect, string $mode): string
    {
        $aiCh = $inspect['channels']['auto_increment'] ?? null;
        $aiCopy = $inspect['storefront_copy_lines']['auto_increment'] ?? null;
        $other = $inspect['storefront_copy_lines']['other_scopes'] ?? [];
        $otherJson = is_array($other) ? (string) json_encode($other, JSON_UNESCAPED_UNICODE) : '{}';
        $scopes = $inspect['storefront_copy_lines']['scopes'] ?? [];
        $scopesJson = is_array($scopes) ? (string) json_encode($scopes, JSON_UNESCAPED_UNICODE) : '{}';
        $legacyFields = $inspect['legacy_hero']['non_empty_fields'] ?? [];
        $legacyFieldsStr = is_array($legacyFields) ? implode(',', $legacyFields) : '';

        $lines = [
            'MODE=' . $mode,
            'CHANNELS_ROWS=' . (int) ($inspect['channels']['row_count'] ?? 0),
            'CHANNELS_AI=' . ($aiCh === null ? 'NA' : (string) (int) $aiCh),
            'PRODUCT_CHANNELS_ROWS=' . (int) ($inspect['product_channels']['row_count'] ?? 0),
            'BUSINESS_ORDERS_CHANNEL=' . (int) ($inspect['business_refs']['orders_with_channel_id'] ?? 0),
            'BUSINESS_SALES_RETURNS_CHANNEL=' . (int) ($inspect['business_refs']['sales_returns_with_channel_id'] ?? 0),
            'SOFT_REF_STOREFRONT_ACCOUNTS=' . (int) ($inspect['soft_refs']['storefront_accounts_registered_channel_slug'] ?? 0),
            'SOFT_REF_MERGE_REQUESTS=' . (int) ($inspect['soft_refs']['merge_requests_proposed_channel_slug'] ?? 0),
            'COPY_LINES_ALL=' . (int) ($inspect['storefront_copy_lines']['row_count_all'] ?? 0),
            'COPY_LINES_HEADER=' . (int) ($inspect['storefront_copy_lines']['header_tagline'] ?? 0),
            'COPY_LINES_HERO=' . (int) ($inspect['storefront_copy_lines']['home_hero'] ?? 0),
            'COPY_LINES_SCOPES=' . $scopesJson,
            'COPY_LINES_OTHER_SCOPES=' . $otherJson,
            'COPY_LINES_AI=' . ($aiCopy === null ? 'NA' : (string) (int) $aiCopy),
            'LEGACY_HERO_ROWS=' . (int) ($inspect['legacy_hero']['row_count'] ?? 0),
            'LEGACY_HERO_VISIBLE=' . (!empty($inspect['legacy_hero']['has_visible']) ? 'YES' : 'NO'),
            'LEGACY_HERO_FIELDS=' . $legacyFieldsStr,
            'BLOCKERS_TOTAL=' . count($inspect['blockers'] ?? []),
        ];
        foreach (($inspect['blockers'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $lines[] = 'BLOCKER=' . (string) ($b['code'] ?? 'UNKNOWN')
                . ';class=' . (string) ($b['class'] ?? '')
                . ';rows=' . (int) ($b['rows'] ?? 0)
                . ';detail=' . (string) ($b['detail'] ?? '');
        }
        $allowed = !empty($inspect['apply_allowed']);
        $lines[] = 'APPLY_ALLOWED=' . ($allowed ? 'YES' : 'NO');
        if (!$allowed) {
            $codes = [];
            foreach (($inspect['blockers'] ?? []) as $b) {
                if (is_array($b) && isset($b['code'])) {
                    $codes[] = (string) $b['code'];
                }
            }
            $lines[] = 'BLOCK_REASON=' . implode(';', $codes);
        }
        $lines[] = 'DELETION_ORDER=product_channels;channels;storefront_copy_lines;AI_channels;AI_copy_lines';
        $lines[] = 'UNTOUCHED=storefront_home_hero;storefront_promo_messages;products;orders;media';

        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('orange_prelaunch_reset_apply')) {
    /**
     * @return array{ok:bool, message:string, before:array<string,mixed>, after:?array<string,mixed>, steps:list<string>}
     */
    function orange_prelaunch_reset_apply(PDO $pdo): array
    {
        $before = orange_prelaunch_reset_inspect($pdo);
        if (empty($before['apply_allowed'])) {
            return [
                'ok' => false,
                'message' => 'Apply aborted - blockers present',
                'before' => $before,
                'after' => null,
                'steps' => [],
            ];
        }

        $steps = [];
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $useTxn = true;
        try {
            if ($useTxn) {
                $pdo->beginTransaction();
            }

            if (orange_prelaunch_reset_table_exists($pdo, 'product_channels')) {
                $n = $pdo->exec('DELETE FROM product_channels');
                $steps[] = 'deleted product_channels rows=' . (int) $n;
            }
            if (orange_prelaunch_reset_table_exists($pdo, 'channels')) {
                $n = $pdo->exec('DELETE FROM channels');
                $steps[] = 'deleted channels rows=' . (int) $n;
            }
            if (orange_prelaunch_reset_table_exists($pdo, 'storefront_copy_lines')) {
                $n = $pdo->exec('DELETE FROM storefront_copy_lines');
                $steps[] = 'deleted storefront_copy_lines rows=' . (int) $n;
            }

            if ($useTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($driver !== 'sqlite') {
                if (orange_prelaunch_reset_table_exists($pdo, 'channels')) {
                    $pdo->exec('ALTER TABLE channels AUTO_INCREMENT = 1');
                    $steps[] = 'channels AUTO_INCREMENT=1';
                }
                if (orange_prelaunch_reset_table_exists($pdo, 'storefront_copy_lines')) {
                    $pdo->exec('ALTER TABLE storefront_copy_lines AUTO_INCREMENT = 1');
                    $steps[] = 'storefront_copy_lines AUTO_INCREMENT=1';
                }
            } else {
                try {
                    $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('channels','storefront_copy_lines')");
                    $steps[] = 'sqlite_sequence cleared for channels + storefront_copy_lines';
                } catch (Throwable $e) {
                    $steps[] = 'sqlite_sequence skip: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            if ($useTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'message' => 'Apply failed: ' . $e->getMessage(),
                'before' => $before,
                'after' => null,
                'steps' => $steps,
            ];
        }

        $after = orange_prelaunch_reset_inspect($pdo);
        $chZero = (int) ($after['channels']['row_count'] ?? -1) === 0;
        $pcZero = (int) ($after['product_channels']['row_count'] ?? -1) === 0;
        $copyZero = (int) ($after['storefront_copy_lines']['row_count_all'] ?? -1) === 0;
        $aiCh = $after['channels']['auto_increment'] ?? null;
        $aiCopy = $after['storefront_copy_lines']['auto_increment'] ?? null;
        $aiOk = ($aiCh === null || (int) $aiCh === 1) && ($aiCopy === null || (int) $aiCopy === 1);
        $ok = $chZero && $pcZero && $copyZero && $aiOk;

        return [
            'ok' => $ok,
            'message' => $ok ? 'Apply completed' : 'Apply finished but post-check failed',
            'before' => $before,
            'after' => $after,
            'steps' => $steps,
        ];
    }
}

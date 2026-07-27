<?php

declare(strict_types=1);

/**
 * صيانة لمرة واحدة: تفريغ Setup Data (القنوات + جمل الهيدر/Hero) وإعادة AUTO_INCREMENT.
 * قرار المالك 2026-07-27 — Pre-Phase-4 Closure.
 *
 * DRY-RUN افتراضي؛ APPLY فقط بتأكيد صريح. لا يحذف ملفات وسائط. لا يمس Orders/Payments/Customers.
 *
 * @see scripts/maintenance_setup_data_reset.php
 */

if (!function_exists('orange_setup_data_reset_copy_scopes')) {
    /** @return list<string> */
    function orange_setup_data_reset_copy_scopes(): array
    {
        return ['header_tagline', 'home_hero'];
    }
}

if (!function_exists('orange_setup_data_reset_table_exists')) {
    function orange_setup_data_reset_table_exists(PDO $pdo, string $table): bool
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

if (!function_exists('orange_setup_data_reset_count')) {
    function orange_setup_data_reset_count(PDO $pdo, string $sql, array $params = []): int
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }
}

if (!function_exists('orange_setup_data_reset_auto_increment')) {
    function orange_setup_data_reset_auto_increment(PDO $pdo, string $table): ?int
    {
        if (!orange_setup_data_reset_table_exists($pdo, $table)) {
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

if (!function_exists('orange_setup_data_reset_inspect')) {
    /**
     * @return array<string,mixed>
     */
    function orange_setup_data_reset_inspect(PDO $pdo): array
    {
        $scopes = orange_setup_data_reset_copy_scopes();
        $out = [
            'targets' => [
                'channels' => orange_setup_data_reset_table_exists($pdo, 'channels'),
                'product_channels' => orange_setup_data_reset_table_exists($pdo, 'product_channels'),
                'storefront_copy_lines' => orange_setup_data_reset_table_exists($pdo, 'storefront_copy_lines'),
            ],
            'untouched' => [
                'storefront_home_hero' => 'legacy single-row / not the admin phrase lists',
                'storefront_promo_messages' => 'promotional messages — out of scope',
                'products' => 'products never deleted',
                'physical_media' => 'uploads/images never deleted',
            ],
            'channels' => [
                'row_count' => 0,
                'by_country' => [],
                'auto_increment' => null,
            ],
            'product_channels' => ['row_count' => 0],
            'storefront_copy_lines' => [
                'row_count_all' => 0,
                'header_tagline' => 0,
                'home_hero' => 0,
                'by_country' => [],
                'auto_increment' => null,
                'shared_table' => true,
                'scopes' => $scopes,
            ],
            'soft_refs' => [
                'storefront_accounts_registered_channel_slug' => 0,
                'merge_requests_proposed_channel_slug' => 0,
            ],
            'business_blockers' => [],
            'deletion_order' => [
                '1. DELETE product_channels (PURE_SETUP; FK CASCADE child)',
                '2. NULL soft slug refs on storefront_accounts / merge requests (EPHEMERAL)',
                '3. DELETE channels',
                '4. DELETE storefront_copy_lines WHERE scope IN (header_tagline, home_hero)',
                '5. ALTER AUTO_INCREMENT = 1 on channels + storefront_copy_lines (MySQL)',
            ],
            'can_apply' => true,
        ];

        if ($out['targets']['channels']) {
            $out['channels']['row_count'] = orange_setup_data_reset_count($pdo, 'SELECT COUNT(*) FROM channels');
            $out['channels']['auto_increment'] = orange_setup_data_reset_auto_increment($pdo, 'channels');
            try {
                $st = $pdo->query(
                    'SELECT country_id, COUNT(*) AS c FROM channels GROUP BY country_id ORDER BY country_id ASC'
                );
                if ($st) {
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $out['channels']['by_country'][(string) (int) ($row['country_id'] ?? 0)] = (int) ($row['c'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if ($out['targets']['product_channels']) {
            $out['product_channels']['row_count'] = orange_setup_data_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM product_channels'
            );
        }

        if ($out['targets']['storefront_copy_lines']) {
            $out['storefront_copy_lines']['row_count_all'] = orange_setup_data_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM storefront_copy_lines'
            );
            $out['storefront_copy_lines']['header_tagline'] = orange_setup_data_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'header_tagline'"
            );
            $out['storefront_copy_lines']['home_hero'] = orange_setup_data_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'home_hero'"
            );
            $out['storefront_copy_lines']['auto_increment'] = orange_setup_data_reset_auto_increment(
                $pdo,
                'storefront_copy_lines'
            );
            try {
                $st = $pdo->query(
                    "SELECT country_id, scope, COUNT(*) AS c FROM storefront_copy_lines
                     WHERE scope IN ('header_tagline','home_hero')
                     GROUP BY country_id, scope ORDER BY country_id ASC, scope ASC"
                );
                if ($st) {
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $cid = (string) (int) ($row['country_id'] ?? 0);
                        $scope = (string) ($row['scope'] ?? '');
                        if (!isset($out['storefront_copy_lines']['by_country'][$cid])) {
                            $out['storefront_copy_lines']['by_country'][$cid] = [];
                        }
                        $out['storefront_copy_lines']['by_country'][$cid][$scope] = (int) ($row['c'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (orange_setup_data_reset_table_exists($pdo, 'storefront_accounts')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
            $out['soft_refs']['storefront_accounts_registered_channel_slug'] = orange_setup_data_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_accounts
                 WHERE registered_channel_slug IS NOT NULL AND TRIM(registered_channel_slug) <> ''"
            );
        }
        if (orange_setup_data_reset_table_exists($pdo, 'storefront_phone_merge_requests')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
            $out['soft_refs']['merge_requests_proposed_channel_slug'] = orange_setup_data_reset_count(
                $pdo,
                "SELECT COUNT(*) FROM storefront_phone_merge_requests
                 WHERE proposed_channel_slug IS NOT NULL AND TRIM(proposed_channel_slug) <> ''"
            );
        }

        /* Business abort guards — TRANSACTIONAL */
        if (orange_setup_data_reset_table_exists($pdo, 'orders')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'orders', 'channel_id')) {
            $n = orange_setup_data_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM orders WHERE channel_id IS NOT NULL AND channel_id > 0'
            );
            if ($n > 0) {
                $out['business_blockers'][] = [
                    'table' => 'orders',
                    'column' => 'channel_id',
                    'rows' => $n,
                    'class' => 'TRANSACTIONAL_BUSINESS_DATA',
                    'action' => 'Owner Decision — do not delete/nullify order channel links via this reset',
                ];
            }
        }
        if (orange_setup_data_reset_table_exists($pdo, 'sales_returns')
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
            $n = orange_setup_data_reset_count(
                $pdo,
                'SELECT COUNT(*) FROM sales_returns WHERE channel_id IS NOT NULL AND channel_id > 0'
            );
            if ($n > 0) {
                $out['business_blockers'][] = [
                    'table' => 'sales_returns',
                    'column' => 'channel_id',
                    'rows' => $n,
                    'class' => 'TRANSACTIONAL_BUSINESS_DATA',
                    'action' => 'Owner Decision — do not delete/nullify sales_returns channel links via this reset',
                ];
            }
        }
        if (orange_setup_data_reset_table_exists($pdo, 'payment_transactions')) {
            $n = orange_setup_data_reset_count($pdo, 'SELECT COUNT(*) FROM payment_transactions');
            if ($n > 0) {
                /* Payments exist — only block if they somehow require channel rows; soft note if channels linked via orders already blocked. */
            }
        }

        $out['can_apply'] = $out['business_blockers'] === [];

        return $out;
    }
}

if (!function_exists('orange_setup_data_reset_format_report')) {
    /**
     * @param array<string,mixed> $inspect
     */
    function orange_setup_data_reset_format_report(array $inspect, string $mode): string
    {
        $lines = [];
        $lines[] = 'ORANGE setup-data reset — mode=' . $mode;
        $lines[] = 'channels.rows=' . (int) ($inspect['channels']['row_count'] ?? 0)
            . ' AI=' . var_export($inspect['channels']['auto_increment'] ?? null, true);
        $lines[] = 'product_channels.rows=' . (int) ($inspect['product_channels']['row_count'] ?? 0);
        $lines[] = 'storefront_copy_lines.all=' . (int) ($inspect['storefront_copy_lines']['row_count_all'] ?? 0)
            . ' header_tagline=' . (int) ($inspect['storefront_copy_lines']['header_tagline'] ?? 0)
            . ' home_hero=' . (int) ($inspect['storefront_copy_lines']['home_hero'] ?? 0)
            . ' AI=' . var_export($inspect['storefront_copy_lines']['auto_increment'] ?? null, true)
            . ' (shared table/sequence)';
        $lines[] = 'channels.by_country=' . json_encode($inspect['channels']['by_country'] ?? [], JSON_UNESCAPED_UNICODE);
        $lines[] = 'copy_lines.by_country=' . json_encode($inspect['storefront_copy_lines']['by_country'] ?? [], JSON_UNESCAPED_UNICODE);
        $lines[] = 'soft_refs=' . json_encode($inspect['soft_refs'] ?? [], JSON_UNESCAPED_UNICODE);
        $lines[] = 'can_apply=' . (!empty($inspect['can_apply']) ? 'yes' : 'no');
        foreach (($inspect['business_blockers'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $lines[] = 'BLOCKER ' . (string) ($b['table'] ?? '') . '.' . (string) ($b['column'] ?? '')
                . ' rows=' . (int) ($b['rows'] ?? 0) . ' — ' . (string) ($b['action'] ?? '');
        }
        foreach (($inspect['deletion_order'] ?? []) as $step) {
            $lines[] = 'ORDER: ' . (string) $step;
        }
        $lines[] = 'UNTOUCHED: ' . implode('; ', array_keys($inspect['untouched'] ?? []));

        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('orange_setup_data_reset_apply')) {
    /**
     * @return array{ok:bool, message:string, before:array<string,mixed>, after:?array<string,mixed>, steps:list<string>}
     */
    function orange_setup_data_reset_apply(PDO $pdo): array
    {
        $before = orange_setup_data_reset_inspect($pdo);
        if (empty($before['can_apply'])) {
            return [
                'ok' => false,
                'message' => 'Apply aborted — business-data blockers present',
                'before' => $before,
                'after' => null,
                'steps' => [],
            ];
        }

        $steps = [];
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $useTxn = true;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
        } catch (Throwable $e) {
            $useTxn = false;
        }

        try {
            if (orange_setup_data_reset_table_exists($pdo, 'product_channels')) {
                $n = $pdo->exec('DELETE FROM product_channels');
                $steps[] = 'deleted product_channels rows=' . (int) $n;
            }

            if (orange_setup_data_reset_table_exists($pdo, 'storefront_accounts')
                && function_exists('orange_table_has_column')
                && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
                $n = $pdo->exec(
                    "UPDATE storefront_accounts SET registered_channel_slug = NULL
                     WHERE registered_channel_slug IS NOT NULL AND TRIM(registered_channel_slug) <> ''"
                );
                $steps[] = 'cleared storefront_accounts.registered_channel_slug rows=' . (int) $n;
            }
            if (orange_setup_data_reset_table_exists($pdo, 'storefront_phone_merge_requests')
                && function_exists('orange_table_has_column')
                && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
                $n = $pdo->exec(
                    "UPDATE storefront_phone_merge_requests SET proposed_channel_slug = NULL
                     WHERE proposed_channel_slug IS NOT NULL AND TRIM(proposed_channel_slug) <> ''"
                );
                $steps[] = 'cleared merge proposed_channel_slug rows=' . (int) $n;
            }

            if (orange_setup_data_reset_table_exists($pdo, 'channels')) {
                $n = $pdo->exec('DELETE FROM channels');
                $steps[] = 'deleted channels rows=' . (int) $n;
            }

            if (orange_setup_data_reset_table_exists($pdo, 'storefront_copy_lines')) {
                $n = $pdo->exec(
                    "DELETE FROM storefront_copy_lines WHERE scope IN ('header_tagline','home_hero')"
                );
                $steps[] = 'deleted storefront_copy_lines header/hero rows=' . (int) $n;
                /* إن بقيت scopes أخرى لا نمسّها؛ حالياً القائمتان هما المحتوى الوحيد. */
            }

            if ($useTxn && $pdo->inTransaction()) {
                $pdo->commit();
            }

            /* AUTO_INCREMENT خارج المعاملة (DDL على MySQL). */
            if ($driver !== 'sqlite') {
                if (orange_setup_data_reset_table_exists($pdo, 'channels')) {
                    $pdo->exec('ALTER TABLE channels AUTO_INCREMENT = 1');
                    $steps[] = 'channels AUTO_INCREMENT=1';
                }
                if (orange_setup_data_reset_table_exists($pdo, 'storefront_copy_lines')) {
                    $pdo->exec('ALTER TABLE storefront_copy_lines AUTO_INCREMENT = 1');
                    $steps[] = 'storefront_copy_lines AUTO_INCREMENT=1 (shared Header+Hero)';
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

        $after = orange_setup_data_reset_inspect($pdo);
        $ok = ((int) ($after['channels']['row_count'] ?? -1) === 0)
            && ((int) ($after['product_channels']['row_count'] ?? -1) === 0)
            && ((int) ($after['storefront_copy_lines']['header_tagline'] ?? -1) === 0)
            && ((int) ($after['storefront_copy_lines']['home_hero'] ?? -1) === 0);

        return [
            'ok' => $ok,
            'message' => $ok ? 'Apply completed' : 'Apply finished but post-check failed',
            'before' => $before,
            'after' => $after,
            'steps' => $steps,
        ];
    }
}

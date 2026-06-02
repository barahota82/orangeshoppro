<?php

declare(strict_types=1);

/**
 * صيانة channels: دمج صفوف مكررة لنفس (country_id + path_segment أو slug).
 */
function orange_channels_repair_duplicates(PDO $pdo, bool $dryRun = true): array
{
    require_once __DIR__ . '/countries.php';

    $report = [
        'dry_run' => $dryRun,
        'path_segment_groups' => 0,
        'slug_groups' => 0,
        'merged' => 0,
        'deleted' => 0,
        'details' => [],
    ];

    if (!orange_table_exists($pdo, 'channels') || !orange_channels_has_country_column($pdo)) {
        $report['error'] = 'channels table or country_id missing';

        return $report;
    }

    $mergeGroup = static function (PDO $pdo, array $ids, bool $dryRun) use (&$report): void {
        if (count($ids) < 2) {
            return;
        }
        sort($ids, SORT_NUMERIC);
        $st = $pdo->prepare(
            'SELECT id, is_active, is_country_default FROM channels WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $keeper = (int) $ids[0];
        foreach ($rows as $r) {
            if ((int) ($r['is_country_default'] ?? 0) === 1) {
                $keeper = (int) ($r['id'] ?? $keeper);
                break;
            }
        }
        if ($keeper === (int) $ids[0]) {
            foreach ($rows as $r) {
                if ((int) ($r['is_active'] ?? 0) === 1) {
                    $keeper = (int) ($r['id'] ?? $keeper);
                    break;
                }
            }
        }

        $dupes = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keeper));
        if ($dupes === []) {
            return;
        }

        $report['details'][] = ['keep' => $keeper, 'remove' => $dupes];

        if ($dryRun) {
            return;
        }

        $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['product_channels', 'orders', 'sales_returns'] as $tbl) {
                if (!orange_table_exists($pdo, $tbl) || !orange_table_has_column($pdo, $tbl, 'channel_id')) {
                    continue;
                }
                foreach ($dupes as $dupId) {
                    $pdo->prepare("UPDATE `{$tbl}` SET channel_id = ? WHERE channel_id = ?")->execute([$keeper, $dupId]);
                }
            }
            if (orange_table_exists($pdo, 'storefront_accounts')
                && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
                $kSlug = $pdo->prepare('SELECT slug FROM channels WHERE id = ? LIMIT 1');
                $kSlug->execute([$keeper]);
                $slug = (string) ($kSlug->fetchColumn() ?: '');
                if ($slug !== '') {
                    foreach ($dupes as $dupId) {
                        $dSlug = $pdo->prepare('SELECT slug FROM channels WHERE id = ? LIMIT 1');
                        $dSlug->execute([$dupId]);
                        $old = (string) ($dSlug->fetchColumn() ?: '');
                        if ($old !== '') {
                            $pdo->prepare(
                                'UPDATE storefront_accounts SET registered_channel_slug = ? WHERE registered_channel_slug = ?'
                            )->execute([$slug, $old]);
                        }
                    }
                }
            }
            $del = $pdo->prepare('DELETE FROM channels WHERE id = ?');
            foreach ($dupes as $dupId) {
                $del->execute([$dupId]);
                ++$report['deleted'];
            }
            ++$report['merged'];
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
        }
    };

    $pathRows = $pdo->query(
        "SELECT country_id, path_segment, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS c
         FROM channels
         WHERE path_segment IS NOT NULL AND path_segment <> ''
         GROUP BY country_id, path_segment
         HAVING c > 1"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($pathRows as $g) {
        ++$report['path_segment_groups'];
        $ids = array_map('intval', explode(',', (string) ($g['ids'] ?? '')));
        $mergeGroup($pdo, $ids, $dryRun);
    }

    $slugRows = $pdo->query(
        "SELECT country_id, slug, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS c
         FROM channels
         WHERE slug IS NOT NULL AND slug <> ''
         GROUP BY country_id, slug
         HAVING c > 1"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($slugRows as $g) {
        ++$report['slug_groups'];
        $ids = array_map('intval', explode(',', (string) ($g['ids'] ?? '')));
        $mergeGroup($pdo, $ids, $dryRun);
    }

    return $report;
}

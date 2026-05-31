<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

/**
 * GAP-SALE-DOC-01 مرحلة 4 — قناة افتراضية لفاتورة الشركة:
 * 1) واتساب (channel_kind) برقم واتساب
 * 2) القناة الرئيسية للدولة (is_country_default)
 * 3) أول قناة نشطة
 */
function orange_admin_default_sales_channel_id(PDO $pdo, int $countryId): int
{
    if (!orange_table_exists($pdo, 'channels')) {
        return 0;
    }

    $countrySql = '';
    if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
        $countrySql = orange_sql_country_and_fragment($pdo, 'channels', 'channels', $countryId);
    }

    $hasKind = orange_table_has_column($pdo, 'channels', 'channel_kind');
    $hasDefault = orange_channels_has_country_default_column($pdo);
    $orderTail = ($hasDefault ? 'is_country_default DESC, ' : '') . 'id ASC';

    try {
        if ($hasKind) {
            $st = $pdo->query(
                "SELECT id FROM channels
                 WHERE is_active = 1 AND channel_kind = 'whatsapp'
                   AND TRIM(COALESCE(whatsapp_number, '')) <> ''"
                . $countrySql . '
                 ORDER BY ' . $orderTail . ' LIMIT 1'
            );
            $waId = (int) ($st ? $st->fetchColumn() : 0);
            if ($waId > 0) {
                return $waId;
            }
        }

        if ($hasDefault) {
            $st = $pdo->query(
                'SELECT id FROM channels WHERE is_active = 1 AND is_country_default = 1'
                . $countrySql . ' ORDER BY id ASC LIMIT 1'
            );
            $defId = (int) ($st ? $st->fetchColumn() : 0);
            if ($defId > 0) {
                return $defId;
            }
        }

        $st = $pdo->query(
            'SELECT id FROM channels WHERE is_active = 1' . $countrySql . ' ORDER BY ' . $orderTail . ' LIMIT 1'
        );

        return (int) ($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

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

/**
 * فاتورة شركة بدون قناة تسويق (مبيعات مباشرة) — channel_id = 0 / NULL في orders.
 */
function orange_sales_company_direct_channel_id(): int
{
    return 0;
}

function orange_sales_company_direct_channel_label(): string
{
    return 'الشركة';
}

function orange_sales_is_company_direct_channel(?int $channelId): bool
{
    return $channelId === null || (int) $channelId <= 0;
}

function orange_sales_order_channel_label(?int $channelId, ?string $channelNameFromDb = null): string
{
    if (orange_sales_is_company_direct_channel($channelId)) {
        return orange_sales_company_direct_channel_label();
    }
    $name = trim((string) ($channelNameFromDb ?? ''));

    return $name !== '' ? $name : '—';
}

/**
 * دولة الطلب عند الحفظ: من القناة إن وُجدت، وإلا سياق الأدمن (مبيعات شركة مباشرة).
 */
function orange_sales_order_country_id_for_channel(PDO $pdo, int $channelId): int
{
    if ($channelId > 0) {
        return orange_country_id_for_channel($pdo, $channelId);
    }
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx > 0) {
        return $ctx;
    }

    return orange_countries_default_id($pdo);
}

/**
 * SQL fragment: طلبات فاتورة شركة مباشرة (بدون channel_id).
 */
function orange_sales_company_direct_orders_sql(PDO $pdo, string $alias = 'o'): string
{
    $sql = '';
    if (orange_table_has_column($pdo, 'orders', 'order_source')) {
        $sql .= " AND {$alias}.order_source = 'company'";
    }
    $sql .= " AND ({$alias}.channel_id IS NULL OR {$alias}.channel_id = 0)";

    return $sql;
}

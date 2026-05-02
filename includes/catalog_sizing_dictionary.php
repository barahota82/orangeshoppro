<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

function orange_catalog_sizing_dictionary_kinds_enforced(PDO $pdo): bool
{
    if (!orange_table_exists($pdo, 'commercial_kind_dictionary')) {
        return false;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM commercial_kind_dictionary WHERE is_active = 1')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * عند وجود صفوف نشطة في قاموس الأنواع: يفرض وجود commercial_kind_key على العائلة إن وُجدت أنواع نشطة.
 * sizing_category_key يُحسب عند الحفظ ولا يُقارَن بصف في sizing_category_dictionary.
 *
 * @param string $sizingCategory يُمرَّر للتوافق مع المستدعي؛ غير مستخدم في المنطق الحالي
 * @return string|null رسالة خطأ عربية أو null
 */
function orange_catalog_validate_size_family_dictionary_consistency(PDO $pdo, string $commercialKind, string $sizingCategory): ?string
{
    if (!orange_table_exists($pdo, 'commercial_kind_dictionary')) {
        return null;
    }

    $ck = trim($commercialKind);

    $kindsEnforced = orange_catalog_sizing_dictionary_kinds_enforced($pdo);
    if ($kindsEnforced && $ck !== '') {
        try {
            $st = $pdo->prepare(
                'SELECT kind_key FROM commercial_kind_dictionary WHERE kind_key = ? AND is_active = 1 LIMIT 1'
            );
            $st->execute([$ck]);
            if (! $st->fetchColumn()) {
                return 'commercial_kind_key («' . htmlspecialchars($ck, ENT_QUOTES, 'UTF-8') . '») غير معرّف في قاموس هرَم المقاس؛ أضِفه من الأدمن أو عدّل المفتاح.';
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    // sizing_category_key على عائلة المقاسات يُولَّد تلقائياً من النوع التجاري + الاسم الإنجليزي؛
    // لا يُفرض تطابق صف في sizing_category_dictionary عند الحفظ.

    return null;
}

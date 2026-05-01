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
 * عند وجود صفوف نشطة في القواميس المرجعية: يفرض تطابق المفاتيح على عائلة المقاسات.
 *
 * @return string|null رسالة خطأ عربية أو null
 */
function orange_catalog_validate_size_family_dictionary_consistency(PDO $pdo, string $commercialKind, string $sizingCategory): ?string
{
    if (!orange_table_exists($pdo, 'commercial_kind_dictionary')) {
        return null;
    }

    $ck = trim($commercialKind);
    $sk = trim($sizingCategory);

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

    if (!orange_table_exists($pdo, 'sizing_category_dictionary')) {
        return null;
    }

    try {
        if ($ck === '' && $sk === '') {
            return null;
        }
        if ($sk !== '' && $ck === '') {
            return 'عند استخدام فئة قياس مرجعية يجب تعبئة النوع التجاري (commercial_kind_key) أولاً.';
        }
        if ($ck === '') {
            return null;
        }

        $cntSt = $pdo->prepare(
            'SELECT COUNT(*) FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND is_active = 1'
        );
        $cntSt->execute([$ck]);
        $cntForKind = (int) $cntSt->fetchColumn();
        if ($cntForKind > 0 && $sk === '') {
            return 'هذا النوع التجاري له فئات مقاس مسجَّلة في القاموس المرجعي؛ يجب تعبئة فئة القياس (sizing_category_key) وفق المرجعية.';
        }
        if ($sk !== '' && $cntForKind > 0) {
            $chk = $pdo->prepare(
                'SELECT 1 FROM sizing_category_dictionary
                 WHERE commercial_kind_key = ? AND category_key = ? AND is_active = 1 LIMIT 1'
            );
            $chk->execute([$ck, $sk]);
            if (! $chk->fetchColumn()) {
                return 'sizing_category_key («' . htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') . '») غير مرتبط بهذا النوع التجاري في قاموس هرَم المقاس.';
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

<?php

declare(strict_types=1);

/**
 * معاينة المنتج قبل النشر — مساعدات مشتركة.
 * المرجع: docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt
 *
 * النموذج: جلسة معاينة مقيّدة بأدمن (كوكي يحمل "<draftId>:<token>") + صفّ ظِلّ/مسودّة
 * في جدول products (is_preview_draft=1، is_active=0، preview_token عشوائي، preview_expires_at).
 * المسار الساخن للعميل لا يدفع أي تكلفة: لا استعلام إلا عند وجود الكوكي.
 */

if (! function_exists('orange_preview_cookie_name')) {
    /** اسم كوكي جلسة المعاينة (path=/ ليُلازم تصفّح الموقع كاملاً). */
    function orange_preview_cookie_name(): string
    {
        return 'orange_preview';
    }
}

if (! function_exists('orange_preview_generate_token')) {
    /** توكن عشوائي غير قابل للتخمين (مثل سابقة مستند الفاتورة س27). */
    function orange_preview_generate_token(): string
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            return bin2hex(hash('sha256', uniqid('orange_preview', true) . mt_rand(), true));
        }
    }
}

if (! function_exists('orange_preview_hide_sql')) {
    /**
     * جزء SQL لإخفاء صفوف الظِلّ/المسودّة عن كل استعلامات الواجهة للعميل.
     * يُعاد فارغاً إن لم يوجد العمود بعد (قبل أول ensure_schema) فلا يكسر الاستعلام.
     */
    function orange_preview_hide_sql(PDO $pdo, string $alias = 'p'): string
    {
        if (! function_exists('orange_table_has_column') || ! orange_table_has_column($pdo, 'products', 'is_preview_draft')) {
            return '';
        }
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        $prefix = ($a !== null && $a !== '') ? $a . '.' : '';

        return ' AND ' . $prefix . 'is_preview_draft = 0';
    }
}

if (! function_exists('orange_preview_active_context')) {
    /**
     * يقرأ كوكي المعاينة ويتحقّق منه مقابل صفّ ظِلّ نشِط غير منتهٍ.
     * يُعيد سياق المعاينة أو null. لا يلمس القاعدة إطلاقاً إن غاب الكوكي (حماية المسار الساخن).
     *
     * @return array{draft_id:int,product:array<string,mixed>,source_id:int,country_id:int}|null
     */
    function orange_preview_active_context(PDO $pdo): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        $cache = null;

        $raw = $_COOKIE[orange_preview_cookie_name()] ?? '';
        if (! is_string($raw) || $raw === '' || strpos($raw, ':') === false) {
            return null;
        }
        [$idPart, $tokenPart] = explode(':', $raw, 2);
        $draftId = (int) $idPart;
        $token = preg_replace('/[^a-f0-9]/', '', (string) $tokenPart);
        if ($draftId <= 0 || $token === '' || strlen($token) < 16) {
            return null;
        }
        if (! function_exists('orange_table_has_column') || ! orange_table_has_column($pdo, 'products', 'preview_token')) {
            return null;
        }

        try {
            $st = $pdo->prepare(
                'SELECT * FROM products
                 WHERE id = ? AND is_preview_draft = 1 AND preview_token = ?
                   AND (preview_expires_at IS NULL OR preview_expires_at > NOW())
                 LIMIT 1'
            );
            $st->execute([$draftId, $token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (! is_array($row) || empty($row)) {
                return null;
            }
            $cache = [
                'draft_id' => $draftId,
                'product' => $row,
                'source_id' => (int) ($row['preview_source_product_id'] ?? 0),
                'country_id' => (int) ($row['country_id'] ?? 0),
            ];

            return $cache;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_preview_active_context: ' . $e->getMessage());
            }

            return null;
        }
    }
}

if (! function_exists('orange_preview_is_active')) {
    /** هل نحن في وضع معاينة صالح الآن؟ */
    function orange_preview_is_active(PDO $pdo): bool
    {
        return orange_preview_active_context($pdo) !== null;
    }
}

if (! function_exists('orange_preview_purge_expired_for_admin')) {
    /**
     * تنظيف lazy: حذف صفوف الظِلّ المنتهية صلاحيتها لهذا الأدمن (+ صورها/متغيّراتها).
     * يُستدعى عند إنشاء معاينة جديدة. آمن إن غابت الأعمدة.
     */
    function orange_preview_purge_expired_for_admin(PDO $pdo, int $adminId): void
    {
        if ($adminId <= 0 || ! function_exists('orange_table_has_column')
            || ! orange_table_has_column($pdo, 'products', 'is_preview_draft')
            || ! orange_table_has_column($pdo, 'products', 'preview_admin_id')) {
            return;
        }
        try {
            $st = $pdo->prepare(
                'SELECT id FROM products
                 WHERE is_preview_draft = 1 AND preview_admin_id = ?
                   AND preview_expires_at IS NOT NULL AND preview_expires_at <= NOW()'
            );
            $st->execute([$adminId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (! is_array($ids) || count($ids) === 0) {
                return;
            }
            foreach ($ids as $pid) {
                orange_preview_delete_draft_row($pdo, (int) $pid);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_preview_purge_expired_for_admin: ' . $e->getMessage());
            }
        }
    }
}

if (! function_exists('orange_preview_delete_draft_row')) {
    /** حذف صفّ ظِلّ واحد وكل تبعاته (صور/متغيّرات). محروس بعلم المسودّة لمنع حذف منتج حيّ. */
    function orange_preview_delete_draft_row(PDO $pdo, int $draftId): void
    {
        if ($draftId <= 0 || ! function_exists('orange_table_has_column')
            || ! orange_table_has_column($pdo, 'products', 'is_preview_draft')) {
            return;
        }
        try {
            $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_preview_draft = 1 LIMIT 1');
            $chk->execute([$draftId]);
            if ($chk->fetchColumn() === false) {
                return; // ليس صفّ ظِلّ — لا تحذف
            }
            if (orange_table_exists($pdo, 'product_variants')) {
                $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$draftId]);
            }
            if (orange_table_exists($pdo, 'product_images')) {
                $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$draftId]);
            }
            if (orange_table_exists($pdo, 'product_channels')) {
                $pdo->prepare('DELETE FROM product_channels WHERE product_id = ?')->execute([$draftId]);
            }
            $pdo->prepare('DELETE FROM products WHERE id = ? AND is_preview_draft = 1')->execute([$draftId]);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_preview_delete_draft_row: ' . $e->getMessage());
            }
        }
    }
}

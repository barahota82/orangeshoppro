<?php

declare(strict_types=1);

/**
 * معاينة المنتج قبل النشر — مساعدات مشتركة.
 * المرجع: docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt
 *
 * النموذج (محدّث): جلسة معاينة موقعية عبر $_SESSION['orange_product_preview']
 * (متّسقة مع سابقة $_SESSION['orange_sf_preview'] في config.php) — مفصولة عن المسودّة:
 *   - تُضبط فقط من admin/api/products/save-preview-draft.php المحمي بـ require_admin_api().
 *   - تحمل: admin_id، country_id، draft_id (0 = تصفّح بلا منتج)، exp.
 *   - الجلسة تبدأ عالمياً في config.php ⇒ فحص مفتاح المصفوفة دون أي استعلام على المسار الساخن للعميل.
 *   - صفّ الظِلّ/المسودّة في جدول products (is_preview_draft=1، is_active=0، preview_admin_id، preview_expires_at)
 *     يُحمَّل فقط عند draft_id>0 لإظهار كارت/صفحة المنتج للأدمن صاحب الجلسة وحده.
 */

if (! function_exists('orange_preview_cookie_name')) {
    /** اسم كوكي قديم (لم يَعُد يُقرأ؛ يُمسح عند الخروج احتياطاً للجلسات القديمة). */
    function orange_preview_cookie_name(): string
    {
        return 'orange_preview';
    }
}

if (! function_exists('orange_preview_session_key')) {
    /** مفتاح جلسة معاينة المنتج. */
    function orange_preview_session_key(): string
    {
        return 'orange_product_preview';
    }
}

if (! function_exists('orange_preview_generate_token')) {
    /** توكن عشوائي غير قابل للتخمين (يُختم به صفّ المسودّة؛ غير مستخدم لتحقّق الجلسة بعد الآن). */
    function orange_preview_generate_token(): string
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            return bin2hex(hash('sha256', uniqid('orange_preview', true) . mt_rand(), true));
        }
    }
}

if (! function_exists('orange_preview_set_session')) {
    /** يفتح جلسة معاينة (يُستدعى من API الأدمن فقط). draftId=0 يعني تصفّح بلا منتج. */
    function orange_preview_set_session(int $adminId, int $countryId, int $draftId, int $ttlSeconds = 86400): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $adminId <= 0) {
            return;
        }
        $_SESSION[orange_preview_session_key()] = [
            'admin_id' => $adminId,
            'country_id' => max(0, $countryId),
            'draft_id' => max(0, $draftId),
            'exp' => time() + max(60, $ttlSeconds),
        ];
    }
}

if (! function_exists('orange_preview_clear_session')) {
    /** ينهي جلسة المعاينة. */
    function orange_preview_clear_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[orange_preview_session_key()]);
        }
    }
}

if (! function_exists('orange_preview_hide_sql')) {
    /**
     * جزء SQL لإخفاء صفوف الظِلّ/المسودّة عن كل استعلامات الواجهة للعميل.
     * يُعاد فارغاً إن لم يوجد العمود بعد (قبل أول ensure_schema) فلا يكسر الاستعلام.
     * يبقى ثابتاً حتى داخل المعاينة: كارت المسودّة يُحقَن منفصلاً (لا تعديل WHERE ⇒ لا تسريب).
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
     * يقرأ جلسة المعاينة ويتحقّق من صلاحيتها. يحمّل صفّ المسودّة فقط عند draft_id>0.
     * يُعيد سياق المعاينة أو null. لا يلمس القاعدة إطلاقاً إن لم تكن الجلسة فعّالة (حماية المسار الساخن).
     *
     * @return array{admin_id:int,country_id:int,draft_id:int,product:?array<string,mixed>,source_id:int}|null
     */
    function orange_preview_active_context(PDO $pdo): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        $cache = null;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $pv = $_SESSION[orange_preview_session_key()] ?? null;
        if (! is_array($pv)) {
            return null;
        }
        if ((int) ($pv['exp'] ?? 0) < time()) {
            unset($_SESSION[orange_preview_session_key()]);

            return null;
        }
        $adminId = (int) ($pv['admin_id'] ?? 0);
        if ($adminId <= 0) {
            return null;
        }
        $countryId = (int) ($pv['country_id'] ?? 0);
        $draftId = (int) ($pv['draft_id'] ?? 0);
        $product = null;

        if ($draftId > 0
            && function_exists('orange_table_has_column')
            && orange_table_has_column($pdo, 'products', 'is_preview_draft')) {
            try {
                $st = $pdo->prepare(
                    'SELECT * FROM products
                     WHERE id = ? AND is_preview_draft = 1 AND preview_admin_id = ?
                       AND (preview_expires_at IS NULL OR preview_expires_at > NOW())
                     LIMIT 1'
                );
                $st->execute([$draftId, $adminId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && ! empty($row)) {
                    $product = $row;
                } else {
                    $draftId = 0; // المسودّة لم تَعُد موجودة — تبقى جلسة تصفّح فقط
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] orange_preview_active_context: ' . $e->getMessage());
                }
                $draftId = 0;
            }
        }

        $cache = [
            'admin_id' => $adminId,
            'country_id' => $countryId,
            'draft_id' => $draftId,
            'product' => $product,
            'source_id' => $product !== null ? (int) ($product['preview_source_product_id'] ?? 0) : 0,
        ];

        return $cache;
    }
}

if (! function_exists('orange_preview_is_active')) {
    /** هل نحن في وضع معاينة صالح الآن؟ */
    function orange_preview_is_active(PDO $pdo): bool
    {
        return orange_preview_active_context($pdo) !== null;
    }
}

if (! function_exists('orange_preview_draft_card_for_country')) {
    /**
     * يُعيد صفّ المسودّة الجاهز لعرضه ككارت في قوائم الواجهة، أو null.
     * مشروط بمطابقة دولة المسودّة لدولة المتجر المعروضة (لا تسرّب لدول أخرى).
     *
     * @param array<string,mixed>|null $ctx سياق orange_preview_active_context()
     * @return array<string,mixed>|null
     */
    function orange_preview_draft_card_for_country(?array $ctx, int $storefrontCountryId): ?array
    {
        if (! is_array($ctx) || (int) ($ctx['draft_id'] ?? 0) <= 0 || ! is_array($ctx['product'] ?? null)) {
            return null;
        }
        $p = $ctx['product'];
        if ((int) ($p['country_id'] ?? 0) !== $storefrontCountryId) {
            return null;
        }

        return $p;
    }
}

if (! function_exists('orange_preview_demo_cards')) {
    /**
     * كروت تجريبية (برواز أصفر) لملء فراغ القوائم أثناء المعاينة فقط. بيانات ثابتة لا تمسّ القاعدة.
     *
     * @return list<array{name:string,price:float}>
     */
    function orange_preview_demo_cards(int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $count = min($count, 8);
        $samples = [
            ['name' => 'نموذج تجريبي ١', 'price' => 9.90],
            ['name' => 'نموذج تجريبي ٢', 'price' => 14.50],
            ['name' => 'نموذج تجريبي ٣', 'price' => 19.00],
            ['name' => 'نموذج تجريبي ٤', 'price' => 24.75],
            ['name' => 'نموذج تجريبي ٥', 'price' => 7.25],
            ['name' => 'نموذج تجريبي ٦', 'price' => 32.00],
            ['name' => 'نموذج تجريبي ٧', 'price' => 12.30],
            ['name' => 'نموذج تجريبي ٨', 'price' => 49.99],
        ];

        return array_slice($samples, 0, $count);
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

if (! function_exists('orange_preview_clear_draft_children')) {
    /**
     * تفريغ أبناء صفّ الظِلّ (دون حذف صفّ المنتج) لإعادة استخدام نفس الـid عند تحديث المعاينة —
     * فلا يتصاعد عدّاد AUTO_INCREMENT مع كل فتح معاينة. القنوات/الصفات/صور الألوان تنظّف نفسها لاحقاً،
     * لكن المتغيّرات والصور والـcolorways إدراجٌ صِرف فتُمسح هنا.
     */
    function orange_preview_clear_draft_children(PDO $pdo, int $draftId): void
    {
        if ($draftId <= 0 || ! function_exists('orange_table_exists')) {
            return;
        }
        try {
            if (orange_table_exists($pdo, 'product_colorway_images') && orange_table_exists($pdo, 'product_colorways')) {
                $pdo->prepare(
                    'DELETE pci FROM product_colorway_images pci
                     INNER JOIN product_colorways cw ON cw.id = pci.product_colorway_id
                     WHERE cw.product_id = ?'
                )->execute([$draftId]);
            }
            if (orange_table_exists($pdo, 'product_colorways')) {
                $pdo->prepare('DELETE FROM product_colorways WHERE product_id = ?')->execute([$draftId]);
            }
            if (orange_table_exists($pdo, 'product_variants')) {
                $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$draftId]);
            }
            if (orange_table_exists($pdo, 'product_images')) {
                $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$draftId]);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_preview_clear_draft_children: ' . $e->getMessage());
            }
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Absolute filesystem path to project root (folder containing config.php, uploads/, …).
 */
function orange_project_root_path(): string
{
    $root = dirname(__DIR__);
    $real = @realpath($root);

    return ($real !== false) ? $real : $root;
}

/**
 * Product image uploads live under project root /uploads/products (web: /uploads/products/).
 */
function orange_products_upload_dir(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
}

/**
 * Ensure directory exists and is writable. Returns absolute path or null.
 *
 * On Windows/Plesk: إن فشل إنشاء المجلد، يدوياً أنشئ `uploads` و `uploads/products` من مدير الملفات
 * وامنح حساب الموقع (IIS_IUSRS / app pool) صلاحية الكتابة.
 */
/**
 * شعارات القنوات: /uploads/channels (ويب: /uploads/channels/).
 */
function orange_channels_upload_dir(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'channels';
}

function orange_ensure_channels_upload_dir(): ?string
{
    $dir = orange_channels_upload_dir();
    $uploadsDir = dirname($dir);

    if (is_file($uploadsDir)) {
        return null;
    }
    if (is_file($dir)) {
        return null;
    }

    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, false) && !is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0775, false);
        }
    }
    if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
        return null;
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, false) && !is_dir($dir)) {
            @mkdir($dir, 0775, false);
        }
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return null;
    }

    return $dir;
}

function orange_ensure_products_upload_dir(): ?string
{
    $dir = orange_products_upload_dir();
    $uploadsDir = dirname($dir);

    if (is_file($uploadsDir)) {
        return null;
    }
    if (is_file($dir)) {
        return null;
    }

    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, false) && !is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0775, false);
        }
    }
    if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
        return null;
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, false) && !is_dir($dir)) {
            @mkdir($dir, 0775, false);
        }
    }
    if (!is_dir($dir)) {
        return null;
    }
    if (!is_writable($dir)) {
        return null;
    }

    return $dir;
}

/**
 * مسار URL تحت ‎/uploads/products/‎ لصورة منتج كما في قاعدة البيانات (اسم ملف في الجذر).
 * إن وُجد ملف ‎{نفس_الاسم_بدون_امتداد}.webp‎ بجانب الملف الأصلي على القرص يُفضَّل WebP؛ وإلا يُعاد المسار للملف الأصلي.
 *
 * @return string مثل ‎/uploads/products/x.webp‎ أو ‎/uploads/products/x.jpg‎، أو ‎''‎ إن المدخل فارغ/غير صالح
 */
function storefront_product_image_web_path(?string $mainImageFromDb): string
{
    $raw = trim(str_replace('\\', '/', (string) $mainImageFromDb));
    if ($raw === '') {
        return '';
    }
    $base = basename($raw);
    if ($base === '' || $base === '.' || $base === '..') {
        return '';
    }
    $dir = orange_products_upload_dir();
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if ($stem !== '' && $stem !== '.' && $stem !== '..') {
        $webp = $stem . '.webp';
        if (is_file($dir . DIRECTORY_SEPARATOR . $webp)) {
            return '/uploads/products/' . rawurlencode($webp);
        }
    }

    return '/uploads/products/' . rawurlencode($base);
}

/**
 * أرشيف مستندات الشركة (غير عام — التنزيل عبر سكربت يتحقق من الجلسة).
 * مسار الويب المباشر لا يُفضَّل؛ استخدم API التنزيل.
 */
function orange_company_docs_upload_root(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'company_docs';
}

/**
 * @return string|null المسار المطلق لمجلد الشهر أو null عند الفشل
 */
function orange_ensure_company_docs_month_dir(): ?string
{
    $base = orange_company_docs_upload_root();
    $ym = date('Y') . DIRECTORY_SEPARATOR . date('m');
    $dir = $base . DIRECTORY_SEPARATOR . $ym;
    $uploadsDir = dirname($base);

    if (is_file($uploadsDir)) {
        return null;
    }
    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, false) && !is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0775, false);
        }
    }
    if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
        return null;
    }
    if (!is_dir($base)) {
        if (!@mkdir($base, 0755, false) && !is_dir($base)) {
            @mkdir($base, 0775, false);
        }
    }
    if (!is_dir($base) || !is_writable($base)) {
        return null;
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return null;
    }

    return $dir;
}

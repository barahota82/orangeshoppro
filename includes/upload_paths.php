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
 * يحاول إنشاء ‎{نفس_الاسم}.webp‎ بجانب ملف الصورة المرفوع (jpg/png/gif). لا يفعل شيئاً لـ webp أو لأنواع غير مدعومة؛ يتجاهل الفشل بصمت.
 */
function orange_image_write_webp_beside(string $absolutePath): void
{
    if (!is_file($absolutePath) || !is_readable($absolutePath) || !function_exists('imagewebp')) {
        return;
    }
    $ext = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
    if ($ext === 'webp') {
        return;
    }
    $loader = match ($ext) {
        'jpg', 'jpeg' => 'imagecreatefromjpeg',
        'png' => 'imagecreatefrompng',
        'gif' => 'imagecreatefromgif',
        default => null,
    };
    if ($loader === null || !function_exists($loader)) {
        return;
    }
    $im = @$loader($absolutePath);
    if ($im === false) {
        return;
    }
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($im);
    }
    if (function_exists('imagealphablending')) {
        @imagealphablending($im, true);
    }
    if (function_exists('imagesavealpha')) {
        @imagesavealpha($im, true);
    }
    $stem = pathinfo($absolutePath, PATHINFO_FILENAME);
    if ($stem === '' || $stem === '.' || $stem === '..') {
        imagedestroy($im);

        return;
    }
    $webpPath = dirname($absolutePath) . DIRECTORY_SEPARATOR . $stem . '.webp';
    @imagewebp($im, $webpPath, 82);
    imagedestroy($im);
}

/**
 * بادئة ‎PUBLIC_BASE_PATH‎ لمسار ويب يبدأ بـ ‎/‎ (رفع، أصول) عند نشر التطبيق داخل مجلد فرعي.
 */
function storefront_public_path(string $relativeWebPath): string
{
    $p = str_replace('\\', '/', trim($relativeWebPath));
    if ($p !== '' && $p[0] !== '/') {
        $p = '/' . $p;
    }
    $base = PUBLIC_BASE_PATH === '' ? '' : rtrim(PUBLIC_BASE_PATH, '/');
    if ($base === '' || $p === '') {
        return $p;
    }
    if ($p === $base || str_starts_with($p, $base . '/')) {
        return $p;
    }

    return $base . $p;
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
 * مسار صورة منتج لـ ‎src‎ في HTML (يشمل ‎PUBLIC_BASE_PATH‎ إن وُجد).
 */
function storefront_product_image_href(?string $mainImageFromDb): string
{
    $rel = storefront_product_image_web_path($mainImageFromDb);
    if ($rel === '') {
        return '';
    }

    return storefront_public_path($rel);
}

/**
 * هل يوجد ملف شعار قناة مطابق لما في قاعدة البيانات أو نسخة ‎.webp‎ بنفس الاسم الأساسي تحت uploads/channels.
 */
function orange_channels_logo_file_resolved_exists(?string $filenameFromDb): bool
{
    $raw = trim(str_replace('\\', '/', (string) $filenameFromDb));
    if ($raw === '') {
        return false;
    }
    $base = basename($raw);
    if ($base === '' || $base === '.' || $base === '..') {
        return false;
    }
    $dir = orange_channels_upload_dir();
    if (is_file($dir . DIRECTORY_SEPARATOR . $base)) {
        return true;
    }
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if ($stem !== '' && $stem !== '.' && $stem !== '..'
        && is_file($dir . DIRECTORY_SEPARATOR . $stem . '.webp')) {
        return true;
    }

    return false;
}

/**
 * تحت ‎/assets/images‎: يفضّل ‎stem.webp‎ إن وُجد على القرص، وإلا الملف المسمّى في المسار إن وُجد، وإلا يعيد المسار كما مرّ (سلوك سابق؛ قد ينتج 404).
 *
 * @param string $relativePath مثل ‎/assets/images/logo-orange.png‎
 */
function storefront_asset_image_preferred_path(string $relativePath): string
{
    $rel = str_replace('\\', '/', $relativePath);
    $prefix = '/assets/images/';
    if (!str_starts_with($rel, $prefix)) {
        return $relativePath;
    }
    $base = basename(substr($rel, strlen($prefix)));
    if ($base === '' || $base === '.' || $base === '..') {
        return $relativePath;
    }
    $dirFs = orange_project_root_path() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images';
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if ($stem !== '' && $stem !== '.' && $stem !== '..') {
        $webp = $stem . '.webp';
        if (is_file($dirFs . DIRECTORY_SEPARATOR . $webp)) {
            return $prefix . $webp;
        }
    }
    if (is_file($dirFs . DIRECTORY_SEPARATOR . $base)) {
        return $prefix . $base;
    }

    return $relativePath;
}

/**
 * مسار URL تحت ‎/uploads/channels/‎ لشعار مرفوع (اسم ملف في الجذر). يفضّل ‎.webp‎ المرافق إن وُجد.
 *
 * @return string مثل ‎/uploads/channels/x.webp‎ أو المسار للأصل، أو ‎''‎ إن المدخل غير صالح
 */
function storefront_channel_logo_web_path(?string $filenameFromDb): string
{
    $raw = trim(str_replace('\\', '/', (string) $filenameFromDb));
    if ($raw === '') {
        return '';
    }
    $base = basename($raw);
    if ($base === '' || $base === '.' || $base === '..') {
        return '';
    }
    $dir = orange_channels_upload_dir();
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if ($stem !== '' && $stem !== '.' && $stem !== '..') {
        $webp = $stem . '.webp';
        if (is_file($dir . DIRECTORY_SEPARATOR . $webp)) {
            return '/uploads/channels/' . rawurlencode($webp);
        }
    }

    return '/uploads/channels/' . rawurlencode($base);
}

/**
 * مسار شعار قناة لـ ‎src‎ في HTML (يشمل ‎PUBLIC_BASE_PATH‎ إن وُجد).
 */
function storefront_channel_logo_href(?string $filenameFromDb): string
{
    $rel = storefront_channel_logo_web_path($filenameFromDb);
    if ($rel === '') {
        return '';
    }

    return storefront_public_path($rel);
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

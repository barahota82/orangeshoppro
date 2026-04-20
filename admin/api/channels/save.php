<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

function channels_ensure_warehouse_column(PDO $pdo): void
{
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM channels LIKE 'warehouse_number'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN warehouse_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER whatsapp_number');
        }
    } catch (Throwable $e) {
    }
}

/**
 * Slug فريد لجدول channels (للـ ?channel= والكوكي).
 */
function channels_next_unique_slug(PDO $pdo, string $base): string
{
    $b = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', $base));
    if ($b === '') {
        $b = 'channel';
    }
    $c = $pdo->prepare('SELECT 1 FROM channels WHERE slug = ? LIMIT 1');
    for ($i = 0; $i < 500; $i++) {
        $try = $i === 0 ? $b : $b . '-' . $i;
        $c->execute([$try]);
        if (!$c->fetch()) {
            return $try;
        }
    }

    return $b . '-' . bin2hex(random_bytes(3));
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    channels_ensure_warehouse_column($pdo);

    $data = get_json_input();

    if (empty($data['name']) || empty($data['path_segment']) || empty($data['whatsapp_number'])) {
        json_response(['success' => false, 'message' => 'الاسم واختصار الرابط ورقم الواتساب مطلوبة'], 422);
    }

    $pathSeg = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $data['path_segment']));
    if ($pathSeg === '' || strlen($pathSeg) > 64) {
        json_response(['success' => false, 'message' => 'اختصار الرابط غير صالح (حروف إنجليزية وأرقام وشرطة فقط)'], 422);
    }
    if (in_array($pathSeg, orange_storefront_reserved_path_segments(), true)) {
        json_response(['success' => false, 'message' => 'هذا الاختصار محجوز لمسارات النظام — اختر اسماً آخر'], 422);
    }

    $dupPath = $pdo->prepare('SELECT 1 FROM channels WHERE path_segment = ? LIMIT 1');
    $dupPath->execute([$pathSeg]);
    if ($dupPath->fetch()) {
        json_response(['success' => false, 'message' => 'اختصار الرابط مستخدم بالفعل'], 409);
    }

    $slug = channels_next_unique_slug($pdo, $pathSeg);

    $wh = 1;

    $stmt = $pdo->prepare(
        'INSERT INTO channels (name, slug, path_segment, logo, primary_color, whatsapp_number, warehouse_number, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        trim((string) $data['name']),
        $slug,
        $pathSeg,
        trim((string) ($data['logo'] ?? '')),
        trim((string) ($data['primary_color'] ?? '')),
        trim((string) $data['whatsapp_number']),
        $wh,
    ]);

    json_response(['success' => true, 'message' => 'تم حفظ الواجهة', 'slug' => $slug, 'path_segment' => $pathSeg]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ القناة');
}

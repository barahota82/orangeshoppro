<?php

declare(strict_types=1);

/**
 * هدام — يفرغ كل أدلة المقاسات الاسترشادية والربط بالمكتبة.
 * قرار المالك 2026-06-21: تشغيل مرة واحدة قبل التسجيل النظيف بعد تنفيذ layout_kind/panel_kind.
 *
 * **الطريق المعتمد على السيرفر:** ترحيل PHP `php_advisory_sizing_clean_wipe_v99` (أوتوماتيك بعد git pull).
 * هذا السكربت للطوارئ/CLI فقط.
 *
 * الاستخدام (من جذر المشروع):
 *   php scripts/maintenance_wipe_advisory_sizing_guides.php --confirm=WIPE_ADVISORY_GUIDES
 *
 * @see docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md
 */

require_once dirname(__DIR__) . '/config.php';

$confirm = '';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    }
}

if ($confirm !== 'WIPE_ADVISORY_GUIDES') {
    fwrite(STDERR, "Refusing to run without --confirm=WIPE_ADVISORY_GUIDES\n");
    exit(1);
}

$pdo = db();
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/advisory_sizing_wipe.php';

try {
    $steps = orange_advisory_sizing_wipe_all($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Wipe failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Advisory sizing wipe completed.\n");
foreach ($steps as $line) {
    fwrite(STDOUT, '  - ' . $line . "\n");
}

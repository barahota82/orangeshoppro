<?php

declare(strict_types=1);

/**
 * صيانة لمرة واحدة — تفريغ Setup Data: القنوات + جمل الهيدر/Hero + إعادة AUTO_INCREMENT.
 * قرار المالك 2026-07-27.
 *
 * الاستخدام (من جذر المشروع):
 *   php scripts/maintenance_setup_data_reset.php
 *       → DRY RUN (لا تعديل)
 *
 *   php scripts/maintenance_setup_data_reset.php --apply --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES
 *       → APPLY صريح فقط
 *
 * ممنوع تشغيل Apply على Production من Cursor — يشغّله المالك على السيرفر بعد مراجعة Dry Run.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/setup_data_reset.php';

$apply = false;
$confirm = '';
foreach ($argv ?? [] as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    }
    if (str_starts_with((string) $arg, '--confirm=')) {
        $confirm = substr((string) $arg, strlen('--confirm='));
    }
}

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!$apply) {
    $inspect = orange_setup_data_reset_inspect($pdo);
    fwrite(STDOUT, orange_setup_data_reset_format_report($inspect, 'DRY_RUN'));
    fwrite(STDOUT, "Dry Run only — zero rows modified.\n");
    fwrite(STDOUT, "To apply: php scripts/maintenance_setup_data_reset.php --apply --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES\n");
    exit(empty($inspect['can_apply']) ? 2 : 0);
}

if ($confirm !== 'RESET_SETUP_CHANNELS_AND_COPY_LINES') {
    fwrite(STDERR, "Refusing APPLY without --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES\n");
    exit(1);
}

$result = orange_setup_data_reset_apply($pdo);
fwrite(STDOUT, orange_setup_data_reset_format_report($result['before'], 'APPLY_BEFORE'));
foreach ($result['steps'] as $step) {
    fwrite(STDOUT, 'STEP: ' . $step . "\n");
}
if (is_array($result['after'])) {
    fwrite(STDOUT, orange_setup_data_reset_format_report($result['after'], 'APPLY_AFTER'));
}
fwrite(STDOUT, ($result['ok'] ? 'OK: ' : 'FAIL: ') . $result['message'] . "\n");
exit($result['ok'] ? 0 : 1);

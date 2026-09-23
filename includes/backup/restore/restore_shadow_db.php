<?php

declare(strict_types=1);

/**
 * Phase 3B.3B4 — Shadow Database Restore Engine.
 *
 * Imports Full package SQL into an isolated shadow/staging database only.
 * Never modifies production DB, never cutover, never file restore, never maintenance.
 *
 * Reuses:
 *   - orange_restore_staging_* credentials / safety fences
 *   - orange_restore_sql_runner_import_gzip()
 *   - approved Full package dump format (manifest.dump_file gzip SQL)
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_package_compat.php';
require_once __DIR__ . '/restore_private_sql_import_policy.php';
require_once __DIR__ . '/restore_private_shadow_engine.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/../backup_validate.php';

const ORANGE_RESTORE_SHADOW_RECORD_VERSION = '3B.3B4-v2-shadow-target';
const ORANGE_RESTORE_SHADOW_REPORT_FILE = 'shadow_restore_report.json';
const ORANGE_RESTORE_SHADOW_META_FILE = 'shadow_restore.json';
const ORANGE_RESTORE_SHADOW_LOCK_FILE = '.shadow_restore.lock';
const ORANGE_RESTORE_SHADOW_BOOTSTRAP_ACK_FILE = 'shadow_worker_bootstrap_ack.json';
const ORANGE_RESTORE_SHADOW_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_ENV_SHADOW_DB = 'ORANGE_RESTORE_SHADOW_DB';
const ORANGE_RESTORE_SHADOW_CHARSET = 'utf8mb4';
const ORANGE_RESTORE_SHADOW_COLLATION = 'utf8mb4_unicode_ci';
const ORANGE_RESTORE_SHADOW_AUTO_PREFIX = 'orange_restore_shadow_';
/** Expected Schema for Step-7 source import (Owner 2026-08-11). */
const ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION = 124;
/** Owner-safe Step-7 shadow DB target unavailable (no env/DB name leakage). */
if (!defined('ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE')) {
    define('ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE', 'STEP7_SHADOW_DB_TARGET_UNAVAILABLE');
}
/** Owner-safe Step-7: resolved target exists but CREATE/USE privilege not proven. */
if (!defined('ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE')) {
    define('ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE', 'STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE');
}

/**
 * Safe Owner Arabic messages for Step-7 shadow restore (no paths/SQL/DB names).
 */
function orange_restore_shadow_operator_message_ar(string $code): string
{
    $code = trim($code);
    $map = [
        'shadow_restore_lock_active' => 'استعادة قاعدة الظل تعمل بالفعل. انتظر ثم حدّث الحالة.',
        'pre_restore_backup_not_ready' => 'تعذر بدء استعادة قاعدة الظل. أكمل النسخة الاحتياطية الإلزامية أولاً.',
        'invalid_status' => 'تعذر بدء استعادة قاعدة الظل في الحالة الحالية للمهمة.',
        'package_type_mismatch' => 'تعذر بدء استعادة قاعدة الظل. نوع الحزمة غير صالح.',
        'country_production_restore_not_enabled' => 'استعادة حزمة الدولة غير مفعّلة لهذا المسار.',
        'contract_missing' => 'تعذر بدء استعادة قاعدة الظل. عقد التنفيذ غير متاح.',
        'version_mismatch' => 'تعذر بدء استعادة قاعدة الظل. بصمة الحزمة لا تطابق الخطة المعتمدة.',
        'package_changed' => 'تعذر بدء استعادة قاعدة الظل. تغيّرت الحزمة بعد الاعتماد.',
        'schema_mismatch' => 'تعذر بدء استعادة قاعدة الظل. إصدار المخطط غير متوافق.',
        'source_package_missing' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. الحزمة المصدر غير موجودة.',
        'source_package_unreadable' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. الحزمة غير قابلة للقراءة.',
        'source_rollback_package_swap' => 'تعذر بدء استعادة قاعدة الظل. حزمة الرجوع ليست مصدر الاستيراد.',
        'source_package_schema_mismatch' => 'تعذر التحقق من قاعدة الظل بعد الاستيراد. إصدار المخطط غير متوافق.',
        'source_package_health_failed' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. صحة الحزمة غير مقبولة.',
        'source_package_checksum_failed' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. فشل التحقق من المجاميع.',
        'source_package_verify_failed' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. فشل التحقق الكامل.',
        'source_package_drv_failed' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. فشل تقرير قابلية الاسترداد.',
        'source_package_fingerprint_mismatch' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. بصمة الحزمة لا تطابق الاعتماد.',
        'dump_file_missing' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. ملف قاعدة البيانات مفقود.',
        'shadow_db_create_failed' => 'تعذر إنشاء بيئة قاعدة الظل.',
        'sql_import_failed' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل.',
        'shadow_verify_failed' => 'تعذر التحقق من قاعدة الظل بعد الاستيراد.',
        'shadow_db_equals_production' => 'تعذر إنشاء بيئة قاعدة الظل. الهدف غير مسموح.',
        'shadow_db_rejected_as_production' => 'تعذر إنشاء بيئة قاعدة الظل. الهدف غير مسموح.',
        'shadow_db_name_invalid' => 'تعذر إنشاء بيئة قاعدة الظل.',
        'shadow_db_ownership_mismatch' => 'تعذر إنشاء بيئة قاعدة الظل. الهدف لا يخص هذه المهمة.',
        'shadow_db_target_unavailable' => 'تعذر تجهيز هدف قاعدة الظل لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE => 'تعذر تجهيز هدف قاعدة الظل لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED => 'تعذر قبول مقدمة ملف SQL للحزمة على مسار الاستيراد الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES => 'ملف SQL يحتوي أكثر من تبديل قاعدة أو تبديلاً متأخراً. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH => 'هوية قاعدة البيانات في مقدمة SQL لا تطابق مصدر الحزمة الموثوق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE => 'ملف SQL يحتوي مراجع عبر قواعد بيانات غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN => 'ملف SQL يحتوي أوامر مستوى قاعدة غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED => 'تعذر تجهيز تيار الاستيراد المطبّع لملف SQL. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED => 'تعذر تجهيز هدف قاعدة الظل داخل المحرك الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED => 'محرك قاعدة الظل الخاص جاهز لكن الاستيراد لم يبدأ.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED => 'تعذر بدء استيراد SQL إلى قاعدة الظل الخاصة.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED => 'فشل استيراد SQL إلى قاعدة الظل الخاصة. لم تُمس قاعدة الإنتاج.',
        'shadow_db_capability_unavailable' => 'هدف قاعدة الظل معروف، لكن صلاحية إنشاء/استخدام قاعدة الظل غير متاحة لحساب التطبيق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE => 'هدف قاعدة الظل معروف، لكن صلاحية إنشاء/استخدام قاعدة الظل غير متاحة لحساب التطبيق. لم يبدأ التنفيذ.',
        'cli_only' => 'تعذر تنفيذ العملية.',
        'package_incompatible' => 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل. الحزمة غير متوافقة.',
    ];

    if (str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')
        || $code === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING) {
        return orange_restore_private_engine_operator_reason_ar($code);
    }

    // Never echo raw env keys / paths / SQL fragments to Owner UI.
    if (str_contains($code, 'ORANGE_RESTORE_') || str_contains($code, '.env') || str_contains($code, 'DB_')) {
        return $map[ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE];
    }

    return $map[$code] ?? ($code !== '' && !str_contains($code, '/') && !str_contains($code, '\\') && strlen($code) < 80
        ? 'تعذر بدء استعادة قاعدة الظل.'
        : 'تعذر بدء استعادة قاعدة الظل.');
}

/**
 * Map internal/exception messages to Owner-safe Step-7 codes (no env/path leakage).
 */
function orange_restore_shadow_normalize_failure_code(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return 'shadow_restore_failed';
    }
    if (!function_exists('orange_restore_private_sql_map_import_error')) {
        require_once __DIR__ . '/restore_private_sql_import_policy.php';
    }
    if (str_starts_with($code, 'STEP7_SQL_')
        || str_starts_with($code, 'STEP7_PRIVATE_IMPORT_')
        || str_starts_with($code, 'STEP7_PRIVATE_TARGET_')
        || $code === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED) {
        return $code;
    }
    if (str_contains($code, 'forbidden pattern')
        || str_contains($code, 'USE database switch')
        || str_contains($code, 'rejected USE database switch')
        || str_contains($code, 'CREATE DATABASE')
        || str_contains($code, 'cross-database')) {
        return orange_restore_private_sql_map_import_error($code);
    }
    if (str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
        return $code;
    }
    if ($code === ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE
        || $code === 'shadow_db_capability_unavailable') {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE;
    }
    if ($code === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
        || $code === 'shadow_db_target_unavailable'
        || $code === 'shadow_db_create_failed'
        || $code === 'shadow_db_equals_production'
        || $code === 'shadow_db_rejected_as_production'
        || $code === 'shadow_db_name_invalid'
        || $code === 'shadow_db_ownership_mismatch') {
        return $code === 'shadow_db_create_failed'
            ? 'shadow_db_create_failed'
            : ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE;
    }
    if (str_contains($code, 'ORANGE_RESTORE_STAGING_DB')
        || str_contains($code, 'ORANGE_RESTORE_SHADOW_DB')
        || str_contains($code, 'ORANGE_RESTORE_STAGING_DB_USER')
        || str_contains($code, 'is not configured')
        || str_contains($code, '.env.php')
        || preg_match('/\bDB_(?:NAME|USER|PASS|HOST)\b/', $code) === 1) {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE;
    }
    // SQL privilege / access-denied shapes → capability (not env/target naming).
    if (preg_match('/\b(1044|1045|1142|1227)\b/', $code) === 1
        || preg_match('/access denied/i', $code) === 1
        || preg_match('/CREATE\s+DATABASE/i', $code) === 1
        || str_contains($code, 'shadow_db_capability')) {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE;
    }

    return $code;
}

function orange_restore_shadow_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_REPORT_FILE;
}

function orange_restore_shadow_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_META_FILE;
}

function orange_restore_shadow_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_LOCK_FILE;
}

/**
 * Production DB name for fences/compare. Overrideable for isolated self-tests.
 */
function orange_restore_shadow_production_db_name(string $projectRoot): string
{
    if (isset($GLOBALS['orange_shadow_production_db_override'])
        && is_string($GLOBALS['orange_shadow_production_db_override'])
        && trim($GLOBALS['orange_shadow_production_db_override']) !== '') {
        return trim($GLOBALS['orange_shadow_production_db_override']);
    }

    return orange_restore_production_db_name($projectRoot);
}

/**
 * Automatic per-job shadow DB name (never production). Stable for a given job_id.
 */
function orange_restore_shadow_automatic_db_name(string $jobId): string
{
    $jobId = trim($jobId);
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
    }
    $hash = substr(hash('sha256', 'orange-step7-shadow|' . strtolower($jobId)), 0, 16);
    $name = ORANGE_RESTORE_SHADOW_AUTO_PREFIX . $hash;
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
    }

    return $name;
}

/**
 * Authoritative Restore-only Step-7 shadow DB target resolver (single authority).
 * Order: job-bound meta → automatic per-job → optional trusted override → fail closed.
 * Staging/shadow env is never mandatory. Never equals production.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed>|null $meta
 * @return array{
 *   ok:bool,
 *   shadow_db:string,
 *   source:string,
 *   code:string,
 *   production_db:string,
 *   identity_hash:string
 * }
 */
function orange_restore_shadow_resolve_target(
    array $env,
    string $projectRoot,
    string $jobId = '',
    ?array $meta = null
): array {
    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    $candidates = [];
    $productionCollision = false;

    // 1) Job-bound (parent-persisted or prior successful bind).
    $bound = trim((string) ($meta['shadow_db'] ?? ''));
    if ($bound !== '') {
        $candidates[] = ['name' => $bound, 'source' => 'job_bound'];
    }

    // 2) Automatic per-job (authoritative default — no env required).
    if (trim($jobId) !== '') {
        try {
            $candidates[] = [
                'name' => orange_restore_shadow_automatic_db_name($jobId),
                'source' => 'automatic_per_job',
            ];
        } catch (Throwable) {
            // continue to fail-closed below
        }
    }

    // 3) Optional trusted override only (never mandatory).
    $overrideShadow = trim((string) ($env[ORANGE_RESTORE_ENV_SHADOW_DB] ?? ''));
    if ($overrideShadow !== '') {
        $candidates[] = ['name' => $overrideShadow, 'source' => 'trusted_override_shadow'];
    }
    $overrideStaging = trim((string) ($env[ORANGE_RESTORE_ENV_STAGING_DB] ?? ''));
    if ($overrideStaging !== '') {
        $candidates[] = ['name' => $overrideStaging, 'source' => 'trusted_override_staging'];
    }

    foreach ($candidates as $candidate) {
        $name = trim((string) ($candidate['name'] ?? ''));
        $source = (string) ($candidate['source'] ?? '');
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            continue;
        }
        if (strcasecmp($name, $productionDb) === 0) {
            $productionCollision = true;
            continue;
        }

        return [
            'ok' => true,
            'shadow_db' => $name,
            'source' => $source,
            'code' => 'ok',
            'production_db' => $productionDb,
            'identity_hash' => orange_restore_shadow_target_identity_hash($name, $jobId),
        ];
    }

    return [
        'ok' => false,
        'shadow_db' => '',
        'source' => $productionCollision ? 'production_collision' : 'unavailable',
        'code' => $productionCollision
            ? 'shadow_db_equals_production'
            : ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
        'production_db' => $productionDb,
        'identity_hash' => '',
    ];
}

/**
 * Stable non-secret identity for parent/worker target match (never exposes DB name).
 */
function orange_restore_shadow_target_identity_hash(string $shadowDb, string $jobId): string
{
    $shadowDb = trim($shadowDb);
    if ($shadowDb === '') {
        return '';
    }

    return hash(
        'sha256',
        strtolower($shadowDb) . '|' . trim($jobId) . '|' . ORANGE_RESTORE_SHADOW_RECORD_VERSION
    );
}

/**
 * Shadow DB name resolver (backward compatible).
 * Prefer passing $jobId so automatic per-job targeting works without mandatory staging env.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed>|null $meta
 */
function orange_restore_shadow_db_name(
    array $env,
    string $projectRoot,
    string $jobId = '',
    ?array $meta = null
): string {
    $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
    if (!($resolved['ok'] ?? false) || trim((string) ($resolved['shadow_db'] ?? '')) === '') {
        $code = (string) ($resolved['code'] ?? ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
        if ($code === 'shadow_db_equals_production') {
            throw new RuntimeException(
                'Shadow database must not equal production database ('
                . (string) ($resolved['production_db'] ?? 'production')
                . ').'
            );
        }
        throw new RuntimeException(
            $code !== '' ? $code : ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
        );
    }

    return (string) $resolved['shadow_db'];
}

/**
 * Optional job context for private shadow engine (set by Step-7 parent/worker).
 *
 * @return array{work_root:string,job_id:string}
 */
function orange_restore_shadow_private_engine_context(): array
{
    $ctx = $GLOBALS['orange_restore_private_engine_context'] ?? null;
    if (!is_array($ctx)) {
        return ['work_root' => '', 'job_id' => ''];
    }

    return [
        'work_root' => trim((string) ($ctx['work_root'] ?? '')),
        'job_id' => trim((string) ($ctx['job_id'] ?? '')),
    ];
}

/**
 * Connection credentials for shadow ensure/connect.
 * Authoritative path: application-owned private shadow engine (NO Production CREATE DATABASE).
 * Legacy staging/trusted_app retained only for disposable self-test overrides.
 *
 * @param array<string, mixed> $env
 * @return array{host:string,user:string,pass:string,mode:string,port?:int}
 */
function orange_restore_shadow_connection_credentials(array $env, string $projectRoot): array
{
    $ctx = orange_restore_shadow_private_engine_context();
    if ($ctx['work_root'] !== '' && $ctx['job_id'] !== '') {
        $priv = orange_restore_private_engine_connection_credentials($ctx['work_root'], $ctx['job_id']);
        if (!empty($priv['ok'])) {
            return [
                'host' => (string) $priv['host'],
                'user' => (string) $priv['user'],
                'pass' => (string) $priv['pass'],
                'mode' => 'private_shadow_engine',
                'port' => (int) $priv['port'],
            ];
        }
        // Private-engine architecture is mandatory for live Step-7 — do not fall back to Production.
        if (empty($GLOBALS['orange_shadow_allow_legacy_production_credentials'])) {
            throw new RuntimeException(
                (string) ($priv['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY)
            );
        }
    }

    // Disposable self-test / legacy override only.
    if (!empty($GLOBALS['orange_shadow_allow_legacy_production_credentials'])) {
        $settings = orange_backup_load_db_settings($projectRoot);
        $host = (string) $settings['host'];
        $prodUser = (string) $settings['user'];
        $prodPass = (string) $settings['pass'];

        $stagingUser = trim((string) ($env[ORANGE_RESTORE_ENV_STAGING_DB_USER] ?? ''));
        $stagingPass = (string) ($env[ORANGE_RESTORE_ENV_STAGING_DB_PASS] ?? '');
        if ($stagingUser !== ''
            && preg_match('/^[A-Za-z0-9_]+$/', $stagingUser) === 1
            && strcasecmp($stagingUser, $prodUser) !== 0
        ) {
            return [
                'host' => $host,
                'user' => $stagingUser,
                'pass' => $stagingPass,
                'mode' => 'staging_optional',
            ];
        }

        if ($prodUser === '') {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
        }

        return [
            'host' => $host,
            'user' => $prodUser,
            'pass' => $prodPass,
            'mode' => 'trusted_app',
        ];
    }

    throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY);
}

function orange_restore_shadow_bootstrap_ack_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_SHADOW_BOOTSTRAP_ACK_FILE;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_shadow_write_bootstrap_ack(string $workRoot, string $jobId, array $payload): void
{
    $path = orange_restore_shadow_bootstrap_ack_path($workRoot, $jobId);
    unset($payload['shadow_db'], $payload['production_db'], $payload['absolute_paths'], $payload['password']);
    $payload['job_id'] = $jobId;
    $payload['acked_at'] = (string) ($payload['acked_at'] ?? gmdate('c'));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('shadow_bootstrap_ack_write_failed');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_load_bootstrap_ack(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_bootstrap_ack_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Persist authoritative shadow target onto job meta (parent pre-spawn / worker bind).
 *
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $resolved from orange_restore_shadow_resolve_target()
 * @return array<string, mixed>
 */
function orange_restore_shadow_bind_resolved_target(array $meta, array $resolved, string $jobId): array
{
    if (!($resolved['ok'] ?? false) || trim((string) ($resolved['shadow_db'] ?? '')) === '') {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
    }
    $meta['shadow_db'] = (string) $resolved['shadow_db'];
    $meta['production_db'] = (string) ($resolved['production_db'] ?? '');
    $meta['shadow_db_source'] = (string) ($resolved['source'] ?? '');
    $meta['shadow_db_identity_hash'] = (string) ($resolved['identity_hash']
        ?? orange_restore_shadow_target_identity_hash((string) $resolved['shadow_db'], $jobId));
    $meta['owner_job_id'] = $jobId;
    $meta['framework_job_id'] = (string) ($meta['framework_job_id'] ?? $jobId);

    return $meta;
}

/**
 * Evaluate SHOW GRANTS lines for CREATE / schema-use capability (redacted classes only).
 *
 * @param list<string> $grantLines
 * @return array{can_create:bool,can_use_existing:bool,privilege_classes:list<string>}
 */
function orange_restore_shadow_evaluate_grant_capability(array $grantLines, string $shadowDb): array
{
    $canCreate = false;
    $canUseExisting = false;
    $classes = [];
    $shadowNeedle = '`' . str_replace('`', '``', $shadowDb) . '`';

    foreach ($grantLines as $grant) {
        $grant = trim((string) $grant);
        if ($grant === '' || preg_match('/^GRANT\b/i', $grant) !== 1) {
            continue;
        }
        if (preg_match('/\bALL PRIVILEGES\b/i', $grant) === 1 && preg_match('/\bON\s+\*\.\*/i', $grant) === 1) {
            $canCreate = true;
            $canUseExisting = true;
            $classes[] = 'ALL_ON_GLOBAL';
            continue;
        }
        if (preg_match('/\bCREATE\b/i', $grant) === 1 && preg_match('/\bON\s+\*\.\*/i', $grant) === 1) {
            $canCreate = true;
            $classes[] = 'CREATE_ON_GLOBAL';
        }
        if (preg_match('/\bON\s+\*\.\*/i', $grant) === 1
            && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|INDEX)\b/i', $grant) === 1) {
            $classes[] = 'GLOBAL_DML_DDL';
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/i', $grant) === 1) {
                $canUseExisting = true;
            }
        }
        if (stripos($grant, ' ON ' . $shadowNeedle . '.') !== false
            || stripos($grant, ' ON ' . $shadowNeedle . '.*') !== false
            || preg_match(
                '/\sON\s+(?:`' . preg_quote(str_replace('`', '``', $shadowDb), '/') . '`|'
                . preg_quote($shadowDb, '/') . ')\s*\./i',
                $grant
            ) === 1) {
            $canUseExisting = true;
            $classes[] = 'SCHEMA_SCOPED';
            if (preg_match('/\b(ALL PRIVILEGES|CREATE|DROP|ALTER|INSERT|UPDATE|DELETE|SELECT)\b/i', $grant) === 1) {
                $classes[] = 'SCHEMA_DML_DDL';
            }
        }
    }

    return [
        'can_create' => $canCreate,
        'can_use_existing' => $canUseExisting,
        'privilege_classes' => array_values(array_unique($classes)),
    ];
}

/**
 * Honest read-only capability probe (SHOW GRANTS + information_schema). Never CREATE/DROP/USE-mutate.
 * Distinct codes: target unavailable vs capability unavailable.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed>|null $meta
 * @return array{
 *   ok:bool,
 *   code:string,
 *   source:string,
 *   credential_mode:string,
 *   can_create:bool,
 *   can_use:bool,
 *   schema_exists:bool,
 *   database_capability:string,
 *   privilege_classes:list<string>,
 *   shadow_db_identity_hash:string
 * }
 */
function orange_restore_shadow_probe_target_readiness(
    string $projectRoot,
    array $env,
    string $jobId,
    ?array $meta = null
): array {
    $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
    if (!($resolved['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
            'source' => (string) ($resolved['source'] ?? 'unavailable'),
            'credential_mode' => '',
            'can_create' => false,
            'can_use' => false,
            'schema_exists' => false,
            'database_capability' => 'unavailable',
            'privilege_classes' => [],
            'shadow_db_identity_hash' => '',
        ];
    }
    $shadowDb = (string) $resolved['shadow_db'];
    $productionDb = (string) $resolved['production_db'];
    $identity = (string) ($resolved['identity_hash']
        ?? orange_restore_shadow_target_identity_hash($shadowDb, $jobId));

    if (isset($GLOBALS['orange_shadow_readiness_override']) && is_callable($GLOBALS['orange_shadow_readiness_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_readiness_override'];
        $over = $fn($projectRoot, $env, $jobId, $resolved);
        if (is_array($over)) {
            $over['shadow_db_identity_hash'] = $identity;
            $over['source'] = (string) ($over['source'] ?? $resolved['source']);
            $over['database_capability'] = (string) ($over['database_capability']
                ?? (!empty($over['ok']) ? 'available' : 'unavailable'));
            $over['privilege_classes'] = is_array($over['privilege_classes'] ?? null)
                ? $over['privilege_classes']
                : [];
            $over['schema_exists'] = !empty($over['schema_exists']);

            return $over;
        }
    }

    // Private-engine path (authoritative): capability proven on loopback private instance only.
    $ctx = orange_restore_shadow_private_engine_context();
    if ($ctx['work_root'] !== '' && $ctx['job_id'] !== '') {
        try {
            if (orange_restore_private_engine_runtime_healthy($ctx['work_root'], $ctx['job_id'])) {
                $creds = orange_restore_shadow_connection_credentials($env, $projectRoot);
                $port = (int) ($creds['port'] ?? 0);
                $dsn = 'mysql:host=' . $creds['host']
                    . ($port > 0 ? ';port=' . (string) $port : '')
                    . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                $st = $pdo->prepare(
                    'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
                );
                $st->execute([$shadowDb]);
                $exists = (string) ($st->fetchColumn() ?: '') !== '';
                if (!$exists) {
                    return [
                        'ok' => false,
                        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
                        'source' => (string) $resolved['source'],
                        'credential_mode' => 'private_shadow_engine',
                        'can_create' => false,
                        'can_use' => false,
                        'schema_exists' => false,
                        'database_capability' => 'unavailable',
                        'privilege_classes' => ['PRIVATE_ENGINE_SCHEMA_MISSING'],
                        'shadow_db_identity_hash' => $identity,
                    ];
                }

                return [
                    'ok' => true,
                    'code' => 'ok',
                    'source' => (string) $resolved['source'],
                    'credential_mode' => 'private_shadow_engine',
                    'can_create' => true,
                    'can_use' => true,
                    'schema_exists' => true,
                    'database_capability' => 'available',
                    'privilege_classes' => ['PRIVATE_ENGINE_RUNTIME'],
                    'shadow_db_identity_hash' => $identity,
                ];
            }

            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
                'source' => (string) $resolved['source'],
                'credential_mode' => 'private_shadow_engine',
                'can_create' => false,
                'can_use' => false,
                'schema_exists' => false,
                'database_capability' => 'unavailable',
                'privilege_classes' => [],
                'shadow_db_identity_hash' => $identity,
            ];
        } catch (Throwable $e) {
            $safe = orange_restore_shadow_normalize_failure_code(trim($e->getMessage()));

            return [
                'ok' => false,
                'code' => str_starts_with($safe, 'STEP7_PRIVATE_ENGINE_')
                    ? $safe
                    : ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
                'source' => (string) $resolved['source'],
                'credential_mode' => 'private_shadow_engine',
                'can_create' => false,
                'can_use' => false,
                'schema_exists' => false,
                'database_capability' => 'unavailable',
                'privilege_classes' => [],
                'shadow_db_identity_hash' => $identity,
            ];
        }
    }

    // Legacy Production GRANT probe — disposable self-tests only.
    if (empty($GLOBALS['orange_shadow_allow_legacy_production_credentials'])) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
            'source' => (string) $resolved['source'],
            'credential_mode' => '',
            'can_create' => false,
            'can_use' => false,
            'schema_exists' => false,
            'database_capability' => 'unavailable',
            'privilege_classes' => [],
            'shadow_db_identity_hash' => $identity,
        ];
    }

    try {
        $creds = orange_restore_shadow_connection_credentials($env, $projectRoot);
        $dsn = 'mysql:host=' . $creds['host'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $st->execute([$shadowDb]);
        $exists = (string) ($st->fetchColumn() ?: '') !== '';
        if ($exists && strcasecmp($shadowDb, $productionDb) === 0) {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
                'source' => (string) $resolved['source'],
                'credential_mode' => (string) $creds['mode'],
                'can_create' => false,
                'can_use' => false,
                'schema_exists' => true,
                'database_capability' => 'unavailable',
                'privilege_classes' => [],
                'shadow_db_identity_hash' => $identity,
            ];
        }

        $grantLines = [];
        try {
            $grantSt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
            if ($grantSt !== false) {
                while ($row = $grantSt->fetch(PDO::FETCH_NUM)) {
                    if (is_array($row) && isset($row[0])) {
                        $line = trim((string) $row[0]);
                        if ($line !== '') {
                            $grantLines[] = $line;
                        }
                    }
                }
            }
        } catch (Throwable) {
            $grantLines = [];
        }

        $eval = orange_restore_shadow_evaluate_grant_capability($grantLines, $shadowDb);
        $canCreate = !empty($eval['can_create']);
        $canUse = $exists ? !empty($eval['can_use_existing']) : $canCreate;
        if ($exists && !$canUse) {
            $canUse = false;
        }
        $ok = ($exists && $canUse) || (!$exists && $canCreate);
        if (!$ok) {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE,
                'source' => (string) $resolved['source'],
                'credential_mode' => (string) $creds['mode'],
                'can_create' => $canCreate,
                'can_use' => $canUse,
                'schema_exists' => $exists,
                'database_capability' => 'unavailable',
                'privilege_classes' => $eval['privilege_classes'],
                'shadow_db_identity_hash' => $identity,
            ];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'source' => (string) $resolved['source'],
            'credential_mode' => (string) $creds['mode'],
            'can_create' => $canCreate || $exists,
            'can_use' => true,
            'schema_exists' => $exists,
            'database_capability' => 'available',
            'privilege_classes' => $eval['privilege_classes'],
            'shadow_db_identity_hash' => $identity,
        ];
    } catch (Throwable $e) {
        $safe = orange_restore_shadow_normalize_failure_code(trim($e->getMessage()));
        $code = $safe === ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE
            ? ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE
            : ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE;

        return [
            'ok' => false,
            'code' => $code,
            'source' => (string) $resolved['source'],
            'credential_mode' => '',
            'can_create' => false,
            'can_use' => false,
            'schema_exists' => false,
            'database_capability' => 'unavailable',
            'privilege_classes' => [],
            'shadow_db_identity_hash' => $identity,
        ];
    }
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool}
 */
function orange_restore_shadow_lock_status(string $workRoot): array
{
    $path = orange_restore_shadow_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'stale' => false];
    }
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        return ['held' => true, 'payload' => null, 'stale' => true];
    }
    $acquiredAt = strtotime((string) ($payload['acquired_at'] ?? ''));
    $age = $acquiredAt !== false ? (time() - $acquiredAt) : PHP_INT_MAX;
    $pid = (int) ($payload['pid'] ?? 0);
    $pidAlive = null;
    if ($pid > 0 && function_exists('posix_kill')) {
        $pidAlive = @posix_kill($pid, 0);
        if ($pidAlive === false && function_exists('posix_get_last_error')) {
            $err = posix_get_last_error();
            $eperm = defined('POSIX_EPERM') ? (int) constant('POSIX_EPERM') : 1;
            if ($err === $eperm) {
                $pidAlive = true;
            }
        }
    }
    $stale = $age > ORANGE_RESTORE_SHADOW_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/**
 * @return array{ok:bool,message:string}
 */
function orange_restore_shadow_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_shadow_lock_path($workRoot);
    $status = orange_restore_shadow_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_shadow_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        return ['ok' => false, 'message' => 'shadow_restore_lock_active'];
    }
    $payload = json_encode([
        'job_id' => $jobId,
        'owner' => $owner,
        'pid' => getmypid(),
        'acquired_at' => gmdate('c'),
        'heartbeat_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false || $payload === false) {
        return ['ok' => false, 'message' => 'shadow_restore_lock_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok'];
}

function orange_restore_shadow_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_shadow_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $held = is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
        if ($held !== '' && $held !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

/**
 * @param array<string, mixed> $record
 */
function orange_restore_shadow_write_json(string $path, array $record): void
{
    unset($record['absolute_paths'], $record['package_path'], $record['dump_path'], $record['password'], $record['secrets']);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write shadow restore metadata.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_load_meta(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_meta_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_load_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_report_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function orange_restore_shadow_public_meta(array $meta): array
{
    unset($meta['absolute_paths'], $meta['package_path'], $meta['dump_path'], $meta['password'], $meta['secrets']);
    $shadowRaw = (string) ($meta['shadow_db'] ?? '');
    $jobId = (string) ($meta['framework_job_id'] ?? '');
    $identity = $shadowRaw !== ''
        ? hash('sha256', strtolower($shadowRaw) . '|' . $jobId . '|' . ORANGE_RESTORE_SHADOW_RECORD_VERSION)
        : '';

    return [
        'record_version' => (string) ($meta['record_version'] ?? ''),
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($meta['source_package_id'] ?? ''),
        'rollback_package_id' => (string) ($meta['rollback_package_id'] ?? ''),
        // Owner UI: never expose raw DB names (RESTORE_CENTER_STEP7_SHADOW_DB_ISOLATION_01).
        'shadow_db' => '',
        'production_db' => '',
        'shadow_db_identity_hash' => $identity,
        'shadow_db_owned_by_job' => $jobId !== '' && (string) ($meta['owner_job_id'] ?? $jobId) === $jobId,
        'status' => (string) ($meta['status'] ?? ''),
        'created_at' => (string) ($meta['created_at'] ?? ''),
        'created_by' => (string) ($meta['created_by'] ?? ''),
        'schema_revision' => (int) ($meta['schema_revision'] ?? 0),
        'backend' => (string) ($meta['backend'] ?? ''),
        'statements_executed' => (int) ($meta['statements_executed'] ?? 0),
        'verify_result' => (string) ($meta['verify_result'] ?? ''),
        'ready' => (bool) ($meta['ready'] ?? false),
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'cli_needed' => false,
        'failure_code' => (($fc = trim((string) ($meta['failure_code'] ?? ''))) !== '')
            ? orange_restore_shadow_normalize_failure_code($fc)
            : '',
        'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
        'bootstrap_acked' => !empty($meta['bootstrap_acked']),
        'shadow_db_source' => (string) ($meta['shadow_db_source'] ?? ''),
        'warning' => 'استعادة قاعدة الظل فقط — قاعدة الإنتاج لم تُعدَّل.',
    ];
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_shadow_public_report(array $report): array
{
    unset(
        $report['absolute_paths'],
        $report['package_path'],
        $report['dump_path'],
        $report['password'],
        $report['shadow_db'],
        $report['production_db']
    );
    if (isset($report['production_compare']) && is_array($report['production_compare'])) {
        unset($report['production_compare']['production_database']);
    }

    return $report + [
        'production_touched' => false,
        'cutover_performed' => false,
        'execution_started' => false,
    ];
}

/**
 * Resolve and fence the Step-7 import source package (never the Step-6 rollback anchor).
 * RESTORE_CENTER_STEP7_SOURCE_VS_ROLLBACK_PACKAGE_FENCE_01
 *
 * @return array{
 *   ok:bool,
 *   code:string,
 *   source_package_id:string,
 *   rollback_package_id:string,
 *   package_path:string,
 *   manifest:array<string,mixed>,
 *   fingerprint:string
 * }
 */
function orange_restore_shadow_resolve_source_package(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $job
): array {
    $sourceId = trim((string) ($job['package_id'] ?? ''));
    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    $rollbackId = is_array($anchor) ? trim((string) ($anchor['rollback_package_id'] ?? '')) : '';

    if ($sourceId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $sourceId)) {
        return [
            'ok' => false,
            'code' => 'source_package_missing',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => '',
            'manifest' => [],
            'fingerprint' => '',
        ];
    }
    if ($rollbackId !== '' && hash_equals($sourceId, $rollbackId)) {
        return [
            'ok' => false,
            'code' => 'source_rollback_package_swap',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => '',
            'manifest' => [],
            'fingerprint' => '',
        ];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return [
            'ok' => false,
            'code' => 'package_type_mismatch',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => '',
            'manifest' => [],
            'fingerprint' => '',
        ];
    }

    try {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $sourceId);
    } catch (Throwable) {
        return [
            'ok' => false,
            'code' => 'source_package_missing',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => '',
            'manifest' => [],
            'fingerprint' => '',
        ];
    }
    if ($packagePath === '' || !is_dir($packagePath)) {
        return [
            'ok' => false,
            'code' => 'source_package_missing',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => '',
            'manifest' => [],
            'fingerprint' => '',
        ];
    }

    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestRaw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest) || ($manifest['package_type'] ?? '') !== 'full_disaster') {
        return [
            'ok' => false,
            'code' => 'package_type_mismatch',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => [],
            'fingerprint' => '',
        ];
    }
    $schema = (int) ($manifest['schema_revision'] ?? 0);
    if ($schema !== ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION) {
        return [
            'ok' => false,
            'code' => 'source_package_schema_mismatch',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => '',
        ];
    }
    $dumpFile = (string) ($manifest['dump_file'] ?? '');
    if ($dumpFile === '' || !is_file($packagePath . DIRECTORY_SEPARATOR . $dumpFile)) {
        return [
            'ok' => false,
            'code' => 'dump_file_missing',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => '',
        ];
    }

    $healthPath = $packagePath . DIRECTORY_SEPARATOR . 'health.json';
    $health = is_file($healthPath) ? json_decode((string) file_get_contents($healthPath), true) : null;
    $healthStatus = is_array($health) ? strtolower((string) ($health['package_status'] ?? $health['status'] ?? '')) : '';
    if ($healthStatus !== '' && !in_array($healthStatus, ['healthy', 'success', 'pass', 'ok'], true)) {
        return [
            'ok' => false,
            'code' => 'source_package_health_failed',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => '',
        ];
    }

    if (function_exists('orange_backup_verify_checksums')) {
        $checksums = orange_backup_verify_checksums($packagePath);
        if (is_array($checksums) && isset($checksums['ok']) && !$checksums['ok']) {
            return [
                'ok' => false,
                'code' => 'source_package_checksum_failed',
                'source_package_id' => $sourceId,
                'rollback_package_id' => $rollbackId,
                'package_path' => $packagePath,
                'manifest' => $manifest,
                'fingerprint' => '',
            ];
        }
    }

    $verify = orange_backup_verify_full_package($packagePath);
    if (!($verify['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'source_package_verify_failed',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => '',
        ];
    }

    $drvPath = orange_backup_admin_recovery_report_sibling_path($packagePath, $sourceId);
    $drv = is_file($drvPath) ? json_decode((string) file_get_contents($drvPath), true) : null;
    $drvResult = is_array($drv) ? strtolower((string) ($drv['overall_result'] ?? '')) : '';
    $drvScore = is_array($drv) ? (int) ($drv['recovery_score'] ?? 0) : 0;
    if ($drvResult !== 'pass' && $drvScore < 70) {
        return [
            'ok' => false,
            'code' => 'source_package_drv_failed',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => '',
        ];
    }

    $fingerprint = '';
    try {
        if (!function_exists('orange_restore_exec_build_package_fingerprint')) {
            require_once __DIR__ . '/restore_execution_orchestrator.php';
        }
        $fpRow = orange_restore_exec_build_package_fingerprint(
            $backupRoot,
            'full_disaster',
            $sourceId,
            null
        );
        $fingerprint = trim((string) ($fpRow['fingerprint'] ?? ''));
    } catch (Throwable) {
        $fingerprint = '';
    }
    $jobFp = trim((string) ($job['package_fingerprint'] ?? ''));
    $anchorFp = is_array($anchor) ? trim((string) ($anchor['package_fingerprint'] ?? '')) : '';
    $expectedFp = $jobFp !== '' ? $jobFp : $anchorFp;
    if ($expectedFp !== '' && $fingerprint !== '' && !hash_equals($expectedFp, $fingerprint)) {
        return [
            'ok' => false,
            'code' => 'source_package_fingerprint_mismatch',
            'source_package_id' => $sourceId,
            'rollback_package_id' => $rollbackId,
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'fingerprint' => $fingerprint,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'source_package_id' => $sourceId,
        'rollback_package_id' => $rollbackId,
        'package_path' => $packagePath,
        'manifest' => $manifest,
        'fingerprint' => $fingerprint,
    ];
}

/**
 * @return array{ok:bool,code:string,job:array<string,mixed>}
 */
function orange_restore_shadow_revalidate(string $workRoot, string $jobId, string $backupRoot): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'job' => $job];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'job' => $job];
    }

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($anchor === null || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
        return ['ok' => false, 'code' => 'pre_restore_backup_not_ready', 'job' => $job];
    }

    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
        $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
        if (!($validation['ok'] ?? false)) {
            return ['ok' => false, 'code' => (string) ($validation['code'] ?? 'version_mismatch'), 'job' => $job];
        }
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'contract_missing', 'job' => $job];
    }

    // Source package fence (never rollback anchor) — RESTORE_CENTER_STEP7_SOURCE_VS_ROLLBACK_PACKAGE_FENCE_01
    $source = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
    if (!($source['ok'] ?? false)) {
        return ['ok' => false, 'code' => (string) ($source['code'] ?? 'source_package_missing'), 'job' => $job];
    }

    return ['ok' => true, 'code' => 'ok', 'job' => $job];
}

/**
 * HTTP: request shadow restore (metadata only).
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $check = orange_restore_shadow_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
        && is_array($meta)
        && !empty($meta['ready'])) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report(orange_restore_shadow_load_report($workRoot, $jobId) ?? []),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Shadow restore already ready.',
        ];
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_public_meta($meta ?? [
                'framework_job_id' => $jobId,
                'status' => $status,
                'cli_needed' => false,
                'execution_started' => false,
            ]),
            // Keep true so attach_verified_schedule can ensure a worker consumer exists.
            'cli_needed' => true,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'استعادة قاعدة الظل قيد التنفيذ.',
        ];
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED) {
        $lock = orange_restore_shadow_lock_status($workRoot);
        if ($lock['held'] && !$lock['stale']) {
            throw new RuntimeException('shadow_restore_lock_active');
        }
    } elseif ($status !== ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) {
        throw new RuntimeException('invalid_status');
    }

    $source = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
    if (!($source['ok'] ?? false)) {
        throw new RuntimeException((string) ($source['code'] ?? 'source_package_missing'));
    }

    // Attempt identity sticky across request → claim → bootstrap → result → public state.
    $attemptId = 's7_' . bin2hex(random_bytes(8));
    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'owner_job_id' => $jobId,
        'attempt_id' => $attemptId,
        'source_package_id' => (string) ($source['source_package_id'] ?? ''),
        'rollback_package_id' => (string) ($source['rollback_package_id'] ?? ''),
        'source_package_fingerprint' => (string) ($source['fingerprint'] ?? ''),
        'shadow_db' => '',
        'production_db' => '',
        'shadow_db_source' => '',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'created_at' => gmdate('c'),
        'created_by' => $operator,
        'schema_revision' => 0,
        'backend' => 'php_pdo',
        'statements_executed' => 0,
        'verify_result' => '',
        'ready' => false,
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'bootstrap_acked' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'warning' => 'استعادة قاعدة الظل فقط — قاعدة الإنتاج لن تُعدَّل.',
    ];
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
        10,
        'Shadow DB restore pending — internal worker required',
        'shadow_restore_requested'
    );
    $job['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => orange_restore_shadow_public_meta($meta),
        'cli_needed' => true, // signals attach_verified_schedule to spawn internal worker
        'idempotent' => false,
        'execution_started' => false,
        'message' => 'تم قبول طلب استعادة قاعدة الظل.',
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array{ok:bool,created:bool,shadow_db:string,message:string}
 */
function orange_restore_shadow_ensure_database(string $projectRoot, array $env, string $shadowDb): array
{
    if (isset($GLOBALS['orange_shadow_ensure_override']) && is_callable($GLOBALS['orange_shadow_ensure_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_ensure_override'];
        $result = $fn($projectRoot, $env, $shadowDb);

        return is_array($result) ? $result : ['ok' => false, 'created' => false, 'shadow_db' => $shadowDb, 'message' => 'ensure_override_invalid'];
    }

    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('shadow_db_equals_production');
    }
    if ($shadowDb === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $shadowDb)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
    }

    try {
        $creds = orange_restore_shadow_connection_credentials($env, $projectRoot);
        // NO_PRODUCTION_MYSQL_PROVISIONING_01 — private engine only unless legacy test flag.
        if (($creds['mode'] ?? '') !== 'private_shadow_engine'
            && empty($GLOBALS['orange_shadow_allow_legacy_production_credentials'])) {
            return [
                'ok' => false,
                'created' => false,
                'shadow_db' => $shadowDb,
                'message' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
            ];
        }
        $port = (int) ($creds['port'] ?? 0);
        $dsn = 'mysql:host=' . $creds['host']
            . ($port > 0 ? ';port=' . (string) $port : '')
            . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        $quoted = '`' . str_replace('`', '``', $shadowDb) . '`';
        $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $st->execute([$shadowDb]);
        $exists = (string) ($st->fetchColumn() ?: '') !== '';

        $created = false;
        if (!$exists) {
            // On private engine, schema is created during provision; runtime user may lack CREATE.
            if (($creds['mode'] ?? '') === 'private_shadow_engine') {
                return [
                    'ok' => false,
                    'created' => false,
                    'shadow_db' => $shadowDb,
                    'message' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
                ];
            }
            $pdo->exec(
                'CREATE DATABASE ' . $quoted
                . ' CHARACTER SET ' . ORANGE_RESTORE_SHADOW_CHARSET
                . ' COLLATE ' . ORANGE_RESTORE_SHADOW_COLLATION
            );
            $created = true;
        }

        return [
            'ok' => true,
            'created' => $created,
            'shadow_db' => $shadowDb,
            'message' => $created ? 'created' : 'already_exists',
            'credential_mode' => (string) $creds['mode'],
        ];
    } catch (Throwable $e) {
        $safe = orange_restore_shadow_normalize_failure_code(trim($e->getMessage()));
        if ($safe === trim($e->getMessage()) && $safe !== ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
            && !str_starts_with($safe, 'STEP7_PRIVATE_ENGINE_')) {
            $safe = 'shadow_db_create_failed';
        }

        return [
            'ok' => false,
            'created' => false,
            'shadow_db' => $shadowDb,
            'message' => $safe,
        ];
    }
}

/**
 * Connect to shadow DB using staging credentials with shadow db name override.
 *
 * @param array<string, mixed> $env
 */
function orange_restore_shadow_connect_pdo(string $projectRoot, array $env, string $shadowDb): PDO
{
    if (isset($GLOBALS['orange_shadow_connect_override']) && is_callable($GLOBALS['orange_shadow_connect_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_connect_override'];
        $pdo = $fn($projectRoot, $env, $shadowDb);
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('shadow_connect_override_invalid');
        }

        return $pdo;
    }

    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('shadow_db_equals_production');
    }
    if ($shadowDb === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $shadowDb)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
    }
    $creds = orange_restore_shadow_connection_credentials($env, $projectRoot);
    $port = (int) ($creds['port'] ?? 0);
    $dsn = 'mysql:host=' . $creds['host']
        . ($port > 0 ? ';port=' . (string) $port : '')
        . ';dbname=' . $shadowDb . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException(
            orange_restore_shadow_normalize_failure_code(trim($e->getMessage())),
            0,
            $e
        );
    }
    $pdo->exec('SET NAMES utf8mb4');
    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
    $sessionDb = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($sessionDb === '' || strcasecmp($sessionDb, $shadowDb) !== 0 || strcasecmp($sessionDb, $productionDb) === 0) {
        throw new RuntimeException('shadow_db_rejected_as_production');
    }
    // Dedicated staging user keeps the historic privilege fence.
    if (($creds['mode'] ?? '') === 'staging_optional') {
        orange_restore_staging_assert_no_production_privileges($pdo, $shadowDb, $productionDb);
    }
    // Private engine: host must remain loopback.
    if (($creds['mode'] ?? '') === 'private_shadow_engine') {
        $host = (string) ($creds['host'] ?? '');
        if ($host !== '127.0.0.1' && $host !== '::1') {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED);
        }
    }

    return $pdo;
}

/**
 * Wipe shadow schema objects (tables/views/routines/triggers/events). Never touches production.
 */
function orange_restore_shadow_wipe(PDO $pdo, string $shadowDb): void
{
    if (isset($GLOBALS['orange_shadow_wipe_override']) && is_callable($GLOBALS['orange_shadow_wipe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_wipe_override'];
        $fn($pdo, $shadowDb);

        return;
    }

    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Events
    $st = $pdo->prepare('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP EVENT IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Triggers
    $st = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Routines
    $st = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'
    );
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = strtoupper((string) ($row['ROUTINE_TYPE'] ?? 'PROCEDURE'));
        if ($type !== 'FUNCTION' && $type !== 'PROCEDURE') {
            $type = 'PROCEDURE';
        }
        $pdo->exec('DROP ' . $type . ' IF EXISTS `' . str_replace('`', '``', (string) ($row['ROUTINE_NAME'] ?? '')) . '`');
    }

    // Views
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'"
    );
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Base tables
    orange_restore_staging_wipe($pdo, $shadowDb);
    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_shadow_inventory(PDO $pdo, string $schema): array
{
    if (isset($GLOBALS['orange_shadow_inventory_override']) && is_callable($GLOBALS['orange_shadow_inventory_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_inventory_override'];
        $result = $fn($pdo, $schema);

        return is_array($result) ? $result : [];
    }

    orange_restore_staging_assert_safe_target($pdo, $schema);

    $charset = '';
    $collation = '';
    $st = $pdo->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $st->execute([$schema]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $charset = (string) ($row['DEFAULT_CHARACTER_SET_NAME'] ?? '');
        $collation = (string) ($row['DEFAULT_COLLATION_NAME'] ?? '');
    }

    $tables = [];
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $tables[] = (string) $name;
    }

    $views = [];
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $views[] = (string) $name;
    }

    $routines = [];
    $st = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $routines[] = [
            'name' => (string) ($r['ROUTINE_NAME'] ?? ''),
            'type' => (string) ($r['ROUTINE_TYPE'] ?? ''),
        ];
    }

    $triggers = [];
    $st = $pdo->prepare(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $triggers[] = (string) $name;
    }

    $events = [];
    $st = $pdo->prepare(
        'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $events[] = (string) $name;
    }

    $rowCounts = [];
    $totalRows = 0;
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        } catch (Throwable) {
            $count = -1;
        }
        $rowCounts[$table] = $count;
        if ($count > 0) {
            $totalRows += $count;
        }
    }

    $schemaRevision = 0;
    if (function_exists('orange_backup_schema_revision_live')) {
        $schemaRevision = orange_backup_schema_revision_live($pdo);
    }

    return [
        'database' => $schema,
        'charset' => $charset,
        'collation' => $collation,
        'tables' => $tables,
        'table_count' => count($tables),
        'views' => $views,
        'view_count' => count($views),
        'routines' => $routines,
        'routine_count' => count($routines),
        'triggers' => $triggers,
        'trigger_count' => count($triggers),
        'events' => $events,
        'event_count' => count($events),
        'row_counts' => $rowCounts,
        'total_rows' => $totalRows,
        'schema_revision' => $schemaRevision,
    ];
}

/**
 * Read-only production inventory (SELECT/information_schema only).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_shadow_production_inventory_readonly(string $projectRoot, array $env): array
{
    unset($env);
    if (isset($GLOBALS['orange_shadow_production_inventory_override'])
        && is_callable($GLOBALS['orange_shadow_production_inventory_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_production_inventory_override'];
        $result = $fn($projectRoot);

        return is_array($result) ? $result : [];
    }

    $settings = orange_backup_load_db_settings($projectRoot);
    $prodDb = (string) $settings['name'];
    $dsn = 'mysql:host=' . $settings['host'] . ';dbname=' . $prodDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    // Inventory only — no writes.
    $inv = [
        'database' => $prodDb,
        'charset' => '',
        'collation' => '',
        'tables' => [],
        'table_count' => 0,
        'views' => [],
        'view_count' => 0,
        'routines' => [],
        'routine_count' => 0,
        'triggers' => [],
        'trigger_count' => 0,
        'events' => [],
        'event_count' => 0,
        'schema_revision' => function_exists('orange_backup_schema_revision_live')
            ? orange_backup_schema_revision_live($pdo)
            : 0,
        'read_only' => true,
    ];

    $st = $pdo->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $st->execute([$prodDb]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $inv['charset'] = (string) ($row['DEFAULT_CHARACTER_SET_NAME'] ?? '');
        $inv['collation'] = (string) ($row['DEFAULT_COLLATION_NAME'] ?? '');
    }

    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $inv['tables'][] = (string) $name;
    }
    $inv['table_count'] = count($inv['tables']);

    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $inv['views'][] = (string) $name;
    }
    $inv['view_count'] = count($inv['views']);

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'
    );
    $st->execute([$prodDb]);
    $inv['routine_count'] = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
    $st->execute([$prodDb]);
    $inv['trigger_count'] = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
    $st->execute([$prodDb]);
    $inv['event_count'] = (int) $st->fetchColumn();

    return $inv;
}

/**
 * @param array<string, mixed> $shadowInv
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $prodInv
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,package_compare:array<string,mixed>,production_compare:array<string,mixed>}
 */
function orange_restore_shadow_verify(
    array $shadowInv,
    array $manifest,
    array $prodInv
): array {
    $errors = [];
    $warnings = [];

    if ((int) ($shadowInv['table_count'] ?? 0) <= 0) {
        $errors[] = 'Shadow database has no base tables after restore.';
    }

    $charset = strtolower((string) ($shadowInv['charset'] ?? ''));
    $collation = strtolower((string) ($shadowInv['collation'] ?? ''));
    if ($charset !== '' && $charset !== ORANGE_RESTORE_SHADOW_CHARSET) {
        $errors[] = 'Shadow charset mismatch (expected utf8mb4, got ' . $charset . ').';
    }
    if ($collation !== '' && !str_starts_with($collation, 'utf8mb4_')) {
        $errors[] = 'Shadow collation is not utf8mb4_* (got ' . $collation . ').';
    }

    $expectedTables = (int) ($manifest['table_count'] ?? 0);
    $actualTables = (int) ($shadowInv['table_count'] ?? 0);
    $packageCompare = [
        'expected_table_count' => $expectedTables,
        'actual_table_count' => $actualTables,
        'expected_schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'actual_schema_revision' => (int) ($shadowInv['schema_revision'] ?? 0),
        'tables_exist' => $actualTables > 0,
        'views_count' => (int) ($shadowInv['view_count'] ?? 0),
        'routines_count' => (int) ($shadowInv['routine_count'] ?? 0),
        'triggers_count' => (int) ($shadowInv['trigger_count'] ?? 0),
        'events_count' => (int) ($shadowInv['event_count'] ?? 0),
        'charset' => (string) ($shadowInv['charset'] ?? ''),
        'collation' => (string) ($shadowInv['collation'] ?? ''),
        'total_rows' => (int) ($shadowInv['total_rows'] ?? 0),
    ];
    if ($expectedTables > 0 && $actualTables < max(1, (int) floor($expectedTables * 0.5))) {
        $errors[] = 'Shadow table count far below package table_count.';
    }
    $expRev = (int) ($manifest['schema_revision'] ?? 0);
    $actRev = (int) ($shadowInv['schema_revision'] ?? 0);
    if ($expRev !== ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION) {
        $errors[] = 'Package schema_revision must be '
            . ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION
            . ' (got ' . $expRev . ').';
    }
    if ($actRev > 0 && $actRev !== ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION) {
        $errors[] = 'Shadow schema_revision must be '
            . ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION
            . ' (got ' . $actRev . ').';
    } elseif ($expRev > 0 && $actRev > 0 && $expRev !== $actRev) {
        $errors[] = 'Shadow schema_revision differs from package.';
    }

    $shadowTables = array_values(array_map('strval', $shadowInv['tables'] ?? []));
    $prodTables = array_values(array_map('strval', $prodInv['tables'] ?? []));
    $onlyShadow = array_values(array_diff($shadowTables, $prodTables));
    $onlyProd = array_values(array_diff($prodTables, $shadowTables));
    $productionCompare = [
        'production_database' => (string) ($prodInv['database'] ?? ''),
        'shadow_table_count' => count($shadowTables),
        'production_table_count' => count($prodTables),
        'tables_only_in_shadow' => array_slice($onlyShadow, 0, 50),
        'tables_only_in_production' => array_slice($onlyProd, 0, 50),
        'charset_shadow' => (string) ($shadowInv['charset'] ?? ''),
        'charset_production' => (string) ($prodInv['charset'] ?? ''),
        'collation_shadow' => (string) ($shadowInv['collation'] ?? ''),
        'collation_production' => (string) ($prodInv['collation'] ?? ''),
        'schema_revision_shadow' => (int) ($shadowInv['schema_revision'] ?? 0),
        'schema_revision_production' => (int) ($prodInv['schema_revision'] ?? 0),
        'read_only_production_scan' => true,
    ];
    if ($onlyShadow !== [] || $onlyProd !== []) {
        $warnings[] = 'Shadow/production table sets differ (reported; not a cutover).';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'package_compare' => $packageCompare,
        'production_compare' => $productionCompare,
    ];
}

/**
 * CLI worker — shadow DB only. Stops at shadow_restore_ready.
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_run_cli(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'cli'
): array {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $check = orange_restore_shadow_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
        && is_array($meta)
        && !empty($meta['ready'])) {
        return [
            'ok' => true,
            'idempotent' => true,
            'result' => 'PASS',
            'job_id' => $jobId,
            'shadow_db' => (string) ($meta['shadow_db'] ?? ''),
            'verify' => (string) ($meta['verify_result'] ?? 'PASS'),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report(orange_restore_shadow_load_report($workRoot, $jobId) ?? []),
        ];
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) {
        orange_restore_shadow_request($workRoot, $jobId, $backupRoot, ['username' => $owner, 'id' => 0]);
        $job = orange_restore_fw_read($workRoot, $jobId);
        $status = (string) ($job['status'] ?? '');
    }
    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    $lock = orange_restore_shadow_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'execution_started' => false,
    ];

    try {
        $env = orange_backup_load_env_array($projectRoot);
        if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
            $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
        }

        $attemptId = trim((string) ($meta['attempt_id'] ?? ''));
        if ($attemptId === '') {
            $attemptId = 's7_' . bin2hex(random_bytes(8));
            $meta['attempt_id'] = $attemptId;
        }

        // Bind private-engine context for worker import (loopback DSN only).
        $GLOBALS['orange_restore_private_engine_context'] = [
            'work_root' => $workRoot,
            'job_id' => $jobId,
        ];

        // Persist attempt runtime / engine-service identity before target preparation.
        $engineState = function_exists('orange_restore_private_engine_load_state')
            ? orange_restore_private_engine_load_state($workRoot, $jobId)
            : null;
        $attemptCtxEarly = function_exists('orange_restore_private_engine_attempt_context')
            ? orange_restore_private_engine_attempt_context($workRoot, $jobId)
            : [];
        $meta['attempt_runtime'] = [
            'runtime_source' => is_array($engineState)
                ? (string) ($engineState['runtime_source'] ?? 'unavailable')
                : 'unavailable',
            'runtime_version' => is_array($engineState)
                ? (string) ($engineState['runtime_version'] ?? ($engineState['family'] ?? ''))
                : '',
            'engine_service_state' => (string) ($attemptCtxEarly['engine_service_state'] ?? ORANGE_RESTORE_ENGINE_ABSENT),
            'import_policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
            'source_package_id' => (string) ($job['source_package_id'] ?? $job['package_id'] ?? ''),
            'attempt_id' => $attemptId,
            'engine_boundary' => (string) ($attemptCtxEarly['engine_service_state'] ?? '') === ORANGE_RESTORE_ENGINE_READY_IDLE
                ? 'ENGINE_READY_IDLE'
                : 'TARGET_PREPARE_STARTED',
        ];
        $meta['execution_started'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        // Authoritative resolver only — never orange_restore_staging_db_name / mandatory env.
        // Prefer job-bound meta persisted by parent pre-spawn; else auto/override resolve.
        $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
        if (!($resolved['ok'] ?? false)) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE);
        }
        $meta = orange_restore_shadow_bind_resolved_target($meta, $resolved, $jobId);
        $shadowDb = (string) $meta['shadow_db'];
        $productionDb = (string) $meta['production_db'];
        if ($shadowDb === '' || strcasecmp($shadowDb, $productionDb) === 0) {
            throw new RuntimeException('shadow_db_equals_production');
        }

        // Bound bootstrap readiness ack BEFORE public "started" (Owner F).
        orange_restore_shadow_write_bootstrap_ack($workRoot, $jobId, [
            'attempt_id' => $attemptId,
            'pid' => getmypid(),
            'owner' => $owner,
            'target_source' => (string) ($meta['shadow_db_source'] ?? $resolved['source']),
            'shadow_db_identity_hash' => (string) ($meta['shadow_db_identity_hash'] ?? ''),
            'ready' => true,
        ]);
        $meta['bootstrap_acked'] = true;
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING;
        $meta['cli_needed'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_restore_started',
            'result' => 'ok',
            'owner' => $owner,
            'attempt_id' => $attemptId,
            'bootstrap_acked' => true,
            'target_source' => (string) $resolved['source'],
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_RUNNING,
            35,
            'Importing package SQL into shadow database',
            'shadow_restore_started'
        );

        // Source package only — never Step-6 rollback anchor.
        $source = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
        if (!($source['ok'] ?? false)) {
            throw new RuntimeException((string) ($source['code'] ?? 'source_package_missing'));
        }
        $packageId = (string) ($source['source_package_id'] ?? '');
        $rollbackId = (string) ($source['rollback_package_id'] ?? '');
        if ($rollbackId !== '' && hash_equals($packageId, $rollbackId)) {
            throw new RuntimeException('source_rollback_package_swap');
        }
        $meta['source_package_id'] = $packageId;
        $meta['rollback_package_id'] = $rollbackId;
        $meta['source_package_fingerprint'] = (string) ($source['fingerprint'] ?? '');
        $packagePath = (string) ($source['package_path'] ?? '');
        $manifest = is_array($source['manifest'] ?? null) ? $source['manifest'] : [];
        if ($packagePath === '' || $manifest === []) {
            throw new RuntimeException('source_package_unreadable');
        }
        $dumpFile = (string) ($manifest['dump_file'] ?? '');
        if ($dumpFile === '') {
            throw new RuntimeException('dump_file_missing');
        }
        $dumpPath = $packagePath . DIRECTORY_SEPARATOR . $dumpFile;
        if (!is_file($dumpPath)) {
            throw new RuntimeException('dump_file_missing');
        }

        if (!function_exists('orange_restore_package_private_engine_import_compat')) {
            require_once __DIR__ . '/restore_private_sql_import_policy.php';
        }
        // Private-engine adapter: authoritative SQL compat engine (not Phase-2B.1 USE ban).
        $trustedSource = orange_restore_sql_compat_trusted_source_from_manifest($manifest);
        if ($trustedSource === '') {
            $trustedSource = orange_restore_private_sql_normalize_ident($productionDb);
        }
        $compat = orange_restore_package_private_engine_import_compat(
            $packagePath,
            $manifest,
            $shadowDb,
            $productionDb,
            $trustedSource
        );
        if (!($compat['ok'] ?? false)) {
            throw new RuntimeException((string) ($compat['error'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED));
        }
        $classification = (string) ($compat['classification'] ?? ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE);
        $packageClassification = (string) ($compat['package_classification'] ?? ORANGE_RESTORE_SQL_PKG_SCAN_FAILED);
        $meta['import_policy_version'] = ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION;
        $meta['sql_compat_engine_version'] = ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION;
        $meta['sql_structure_classification'] = $classification;
        $meta['sql_package_classification'] = $packageClassification;
        $meta['source_dump_sha256'] = (string) ($compat['source_dump_sha256'] ?? '');
        $meta['trusted_source_identity_hash'] = (string) ($compat['trusted_source_identity_hash'] ?? '');
        $meta['engine_boundary'] = 'IMPORT_STREAM_VALIDATE_STARTED';
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        $importGzipPath = $dumpPath;
        $normalizedMeta = null;
        $needsNormalize = in_array($packageClassification, [
            ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
            ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
        ], true) || in_array($classification, [
            ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
            ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE,
        ], true);
        if ($needsNormalize) {
            $prepared = orange_restore_private_sql_prepare_normalized_import(
                $workRoot,
                $jobId,
                $dumpPath,
                $trustedSource,
                $classification,
                $packageClassification
            );
            if (!($prepared['ok'] ?? false)) {
                throw new RuntimeException((string) ($prepared['code'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED));
            }
            $importGzipPath = (string) $prepared['normalized_path'];
            $normalizedMeta = $prepared;
            $meta['normalized_stream_sha256'] = (string) ($prepared['normalized_stream_sha256'] ?? '');
            $meta['canonical_use_removed_count'] = (int) ($prepared['removed_count'] ?? 0);
            $meta['same_source_remapped_count'] = (int) ($prepared['remapped_count'] ?? 0);
            $meta['engine_boundary'] = 'IMPORT_STREAM_NORMALIZED';
            orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
        } else {
            $meta['canonical_use_removed_count'] = 0;
            $meta['same_source_remapped_count'] = 0;
            $meta['normalized_stream_sha256'] = '';
            $meta['engine_boundary'] = 'IMPORT_STREAM_NORMALIZED';
            orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
        }

        $ensured = orange_restore_shadow_ensure_database($projectRoot, $env, $shadowDb);
        if (!($ensured['ok'] ?? false)) {
            $ensureMsg = trim((string) ($ensured['message'] ?? 'shadow_db_create_failed'));
            $ensureCode = orange_restore_shadow_normalize_failure_code($ensureMsg);
            if ($ensureCode === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                || $ensureCode === ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE
                || $ensureCode === 'shadow_db_create_failed') {
                throw new RuntimeException(
                    $ensureCode === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                        ? ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED
                        : ($ensureCode !== '' ? $ensureCode : ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED)
                );
            }
            throw new RuntimeException(
                $ensureCode !== '' ? $ensureCode : ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED
            );
        }
        $meta['engine_boundary'] = 'TARGET_READY';
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        orange_restore_shadow_wipe($pdo, $shadowDb);

        $meta['engine_boundary'] = 'IMPORT_START_REQUESTED';
        $meta['execution_started'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        if (isset($GLOBALS['orange_shadow_import_override']) && is_callable($GLOBALS['orange_shadow_import_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_import_override'];
            $sqlResult = $fn($pdo, $importGzipPath, $shadowDb, $productionDb);
            if (!is_array($sqlResult)) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED);
            }
        } else {
            // execution_started flips true only when the runner begins submitting statements.
            $meta['engine_boundary'] = 'IMPORT_STARTED';
            $meta['execution_started'] = true;
            $job['execution_started'] = true;
            orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
            orange_restore_fw_write($workRoot, $job);

            $sqlResult = orange_restore_private_sql_import_gzip(
                $pdo,
                $importGzipPath,
                $shadowDb,
                $productionDb,
                null,
                $trustedSource
            );
            // Safety: session must still be shadow (skip when import is mocked for self-tests).
            orange_restore_staging_assert_safe_target($pdo, $shadowDb);
        }
        if (!($sqlResult['ok'] ?? false)) {
            $importCode = (string) ($sqlResult['code'] ?? $sqlResult['error'] ?? ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED);
            throw new RuntimeException(orange_restore_shadow_normalize_failure_code($importCode));
        }
        $meta['engine_boundary'] = 'IMPORT_FINISHED';
        if (is_array($normalizedMeta)) {
            $meta['normalization_provenance'] = [
                'policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
                'classification' => $classification,
                'removed_count' => (int) ($normalizedMeta['removed_count'] ?? 0),
                'source_dump_sha256' => (string) ($normalizedMeta['source_dump_sha256'] ?? ''),
                'normalized_stream_sha256' => (string) ($normalizedMeta['normalized_stream_sha256'] ?? ''),
            ];
        }
        orange_restore_private_sql_cleanup_normalized_import($workRoot, $jobId);

        $meta['statements_executed'] = (int) ($sqlResult['statements_executed'] ?? 0);
        $meta['backend'] = (string) ($manifest['export_backend'] ?? 'php_pdo');
        $meta['schema_revision'] = (int) ($manifest['schema_revision'] ?? 0);
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_VERIFYING,
            70,
            'Verifying shadow database restore',
            'shadow_restore_verification_started'
        );

        $shadowInv = orange_restore_shadow_inventory($pdo, $shadowDb);
        $prodInv = orange_restore_shadow_production_inventory_readonly($projectRoot, $env);
        $verify = orange_restore_shadow_verify($shadowInv, $manifest, $prodInv);
        if (!($verify['ok'] ?? false)) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_restore_verification_failed',
                'result' => 'fail',
                'errors' => array_slice($verify['errors'] ?? [], 0, 10),
            ]);
            throw new RuntimeException('shadow_verify_failed');
        }

        $report = [
            'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
            'generated_at' => gmdate('c'),
            'framework_job_id' => $jobId,
            'source_package_id' => $packageId,
            'rollback_package_id' => $rollbackId,
            'shadow_db' => $shadowDb,
            'production_db' => $productionDb,
            'overall_result' => 'PASS',
            'sql_import' => [
                'ok' => true,
                'statements_executed' => (int) ($sqlResult['statements_executed'] ?? 0),
                'bytes_read' => (int) ($sqlResult['bytes_read'] ?? 0),
            ],
            'shadow_inventory' => [
                'table_count' => (int) ($shadowInv['table_count'] ?? 0),
                'view_count' => (int) ($shadowInv['view_count'] ?? 0),
                'routine_count' => (int) ($shadowInv['routine_count'] ?? 0),
                'trigger_count' => (int) ($shadowInv['trigger_count'] ?? 0),
                'event_count' => (int) ($shadowInv['event_count'] ?? 0),
                'charset' => (string) ($shadowInv['charset'] ?? ''),
                'collation' => (string) ($shadowInv['collation'] ?? ''),
                'schema_revision' => (int) ($shadowInv['schema_revision'] ?? 0),
                'total_rows' => (int) ($shadowInv['total_rows'] ?? 0),
                'tables' => array_slice($shadowInv['tables'] ?? [], 0, 200),
                'row_counts_sample' => array_slice($shadowInv['row_counts'] ?? [], 0, 50, true),
            ],
            'package_compare' => $verify['package_compare'],
            'production_compare' => $verify['production_compare'],
            'warnings' => $verify['warnings'],
            'errors' => [],
            'production_touched' => false,
            'cutover_performed' => false,
            'files_restored' => false,
            'maintenance_enabled' => false,
            'execution_started' => false,
            'application_switched_to_shadow' => false,
            'warning' => 'Shadow restore only — production database was not modified.',
        ];
        orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), $report);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_restore_verification_passed',
            'result' => 'ok',
            'shadow_db' => $shadowDb,
            'table_count' => (int) ($shadowInv['table_count'] ?? 0),
        ]);

        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
        $meta['verify_result'] = 'PASS';
        $meta['ready'] = true;
        $meta['failure_code'] = '';
        $meta['execution_started'] = false;
        $meta['production_touched'] = false;
        $meta['cutover_performed'] = false;
        $meta['files_restored'] = false;
        $meta['maintenance_enabled'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_READY,
            100,
            'Shadow database restore ready (production untouched)',
            'shadow_restore_ready'
        );
        $job['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
        $job['shadow_restore_report_file'] = ORANGE_RESTORE_SHADOW_REPORT_FILE;
        $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_shadow_release_lock($workRoot, $jobId);

        return [
            'ok' => true,
            'idempotent' => false,
            'result' => 'PASS',
            'job_id' => $jobId,
            'shadow_db' => $shadowDb,
            'verify' => 'PASS',
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report($report),
        ];
    } catch (Throwable $e) {
        $code = orange_restore_shadow_normalize_failure_code(trim($e->getMessage()) ?: 'shadow_restore_failed');
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
        $meta['ready'] = false;
        $meta['failure_code'] = $code;
        $meta['verify_result'] = 'FAIL';
        $meta['execution_started'] = false;
        $meta['cli_needed'] = false;
        $meta['production_touched'] = false;
        try {
            orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
            $failReport = [
                'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
                'generated_at' => gmdate('c'),
                'framework_job_id' => $jobId,
                'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
                'overall_result' => 'FAIL',
                'failure_code' => $code,
                'production_touched' => false,
                'cutover_performed' => false,
                'execution_started' => false,
            ];
            orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), $failReport);
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
                ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
                100,
                'Shadow restore failed',
                'shadow_restore_failed'
            );
            $failed = orange_restore_fw_read($workRoot, $jobId);
            $failed['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
            $failed['execution_started'] = false;
            orange_restore_fw_write($workRoot, $failed);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_restore_failed',
                'result' => 'fail',
                'code' => $code,
                'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
                'safe_failure_code' => $code,
            ]);
        } catch (Throwable) {
            // best-effort forensic preserve
        }
        orange_restore_shadow_release_lock($workRoot, $jobId);

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
            'shadow_db' => (string) ($meta['shadow_db'] ?? ''),
            'verify' => 'FAIL',
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
        ];
    }
}

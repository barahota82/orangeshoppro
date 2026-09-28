<?php

declare(strict_types=1);

/**
 * Brand Identity runtime consumer.
 * Normal authority is application MySQL after the seven tables exist.
 * Fail-closed to static/CSS fallbacks when tables are absent or empty.
 * Does not create tables on ordinary reads and does not stamp Control Rev6.
 */

require_once __DIR__ . '/brand_identity.php';

/**
 * Isolated identity PDO bind (tests / admin snapshot helpers).
 * Normal hosted runtime authority is application MySQL via db() after the
 * seven Brand Identity tables exist.
 */
function orange_brand_identity_runtime_bind_control(?PDO $pdo): void
{
    $GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO'] = $pdo;
}

function orange_brand_identity_runtime_forget_control_pdo(): void
{
    unset($GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO']);
}

function orange_brand_identity_runtime_bind_preview_release(?int $releaseId): void
{
    $GLOBALS['ORANGE_BRAND_IDENTITY_PREVIEW_RELEASE_ID'] = $releaseId !== null && $releaseId > 0
        ? $releaseId
        : null;
}

function orange_brand_identity_runtime_preview_release_id(): ?int
{
    if (array_key_exists('ORANGE_BRAND_IDENTITY_PREVIEW_RELEASE_ID', $GLOBALS)) {
        $bound = $GLOBALS['ORANGE_BRAND_IDENTITY_PREVIEW_RELEASE_ID'];
        if ($bound === null) {
            return null;
        }
        $id = (int) $bound;

        return $id > 0 ? $id : null;
    }
    $q = isset($_GET['orange_brand_preview']) ? (int) $_GET['orange_brand_preview'] : 0;

    return $q > 0 ? $q : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_runtime_slot_from_release(PDO $pdo, int $releaseId, string $slotCode): ?array
{
    foreach (orange_brand_identity_release_slots($pdo, $releaseId) as $row) {
        if ((string) ($row['slot_code'] ?? '') === $slotCode) {
            return orange_brand_identity_slot_version($pdo, (int) $row['slot_version_id']);
        }
    }

    return null;
}

function orange_brand_identity_runtime_app_mysql_pdo(): ?PDO
{
    if (!function_exists('db')) {
        return null;
    }
    try {
        $pdo = db();
        if (!($pdo instanceof PDO)) {
            return null;
        }
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
            return null;
        }
        if (!orange_brand_identity_mysql_tables_ready($pdo)) {
            return null;
        }

        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Normal runtime authority: injected test PDO, else application MySQL.
 * Does not open ORANGE_BRAND_IDENTITY_CONTROL_SQLITE and has no SQLite fallback.
 */
function orange_brand_identity_runtime_control_pdo(): ?PDO
{
    if (isset($GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO'])
        && $GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO'] instanceof PDO) {
        return $GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO'];
    }
    $mysql = orange_brand_identity_runtime_app_mysql_pdo();
    if ($mysql instanceof PDO) {
        $GLOBALS['ORANGE_BRAND_IDENTITY_RUNTIME_PDO'] = $mysql;

        return $mysql;
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_runtime_current_slot(string $slotCode): ?array
{
    try {
        orange_brand_identity_assert_slot_code($slotCode);
        $pdo = orange_brand_identity_runtime_control_pdo();
        if ($pdo === null) {
            return null;
        }
        $previewId = orange_brand_identity_runtime_preview_release_id();
        if ($previewId !== null) {
            return orange_brand_identity_runtime_slot_from_release($pdo, $previewId, $slotCode);
        }

        return orange_brand_identity_current_slot_version($pdo, $slotCode);
    } catch (Throwable $e) {
        return null;
    }
}

function orange_brand_identity_runtime_object_public_url(string $sha256): string
{
    try {
        $pdo = orange_brand_identity_runtime_control_pdo();
        if ($pdo === null) {
            return '';
        }
        $obj = orange_brand_identity_object($pdo, $sha256);
        if ($obj === null) {
            return '';
        }
        $rel = trim((string) ($obj['relpath'] ?? ''));
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        if ($rel[0] !== '/') {
            $rel = '/' . $rel;
        }
        if (function_exists('storefront_public_path')) {
            try {
                $pub = trim((string) storefront_public_path($rel));
                if ($pub !== '') {
                    return $pub;
                }
            } catch (Throwable $e) {
                /* keep relative path */
            }
        }

        return $rel;
    } catch (Throwable $e) {
        return '';
    }
}

function orange_brand_identity_runtime_consume_slot_url(string $slotCode, string $fallbackUrl): string
{
    $slot = orange_brand_identity_runtime_current_slot($slotCode);
    if ($slot === null) {
        return $fallbackUrl;
    }
    $sha = trim((string) ($slot['derivative_object_id'] ?? ''));
    if ($sha === '') {
        $sha = trim((string) ($slot['original_object_id'] ?? ''));
    }
    if ($sha === '') {
        return $fallbackUrl;
    }
    $url = orange_brand_identity_runtime_object_public_url($sha);

    return $url !== '' ? $url : $fallbackUrl;
}

function orange_brand_identity_runtime_normalize_ui_locale(string $raw): string
{
    $n = orange_brand_identity_normalize_locale_code($raw);
    if ($n === null) {
        return 'en';
    }

    return $n;
}

/**
 * Identity slogan: selected locale → English → empty.
 * Returns null when no active identity slogan exists (caller may use copy_lines).
 */
function orange_brand_identity_runtime_slogan_text(string $locale): ?string
{
    try {
        $pdo = orange_brand_identity_runtime_control_pdo();
        if ($pdo === null) {
            return null;
        }
        $previewId = orange_brand_identity_runtime_preview_release_id();
        if ($previewId !== null) {
            $rel = orange_brand_identity_release($pdo, $previewId);
            $ver = orange_brand_identity_identity_version($pdo, (int) $rel['identity_version_id']);
        } else {
            $ver = orange_brand_identity_current_identity_version($pdo);
        }
        if ($ver === null) {
            return null;
        }
        $rows = orange_brand_identity_identity_translations($pdo, (int) $ver['id']);
        $byLocale = [];
        foreach ($rows as $row) {
            if ((string) ($row['text_key'] ?? '') !== 'STOREFRONT_SLOGAN') {
                continue;
            }
            $loc = orange_brand_identity_runtime_normalize_ui_locale((string) ($row['locale'] ?? ''));
            $byLocale[$loc] = trim((string) ($row['text_value'] ?? ''));
        }
        if ($byLocale === []) {
            return null;
        }
        $want = orange_brand_identity_runtime_normalize_ui_locale($locale);
        $text = trim((string) ($byLocale[$want] ?? ''));
        if ($text !== '') {
            return $text;
        }
        $en = trim((string) ($byLocale['en'] ?? ''));

        return $en;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, mixed> $row
 */
function orange_storefront_copy_slogan_from_row(array $row, string $locale): string
{
    $loc = orange_brand_identity_runtime_normalize_ui_locale($locale);
    $map = [
        'ar' => ['text_ar', 'header_tagline_ar'],
        'en' => ['text_en', 'header_tagline_en'],
        'fil' => ['text_fil', 'header_tagline_fil'],
        'hi' => ['text_hi', 'header_tagline_hi'],
    ];
    $cols = $map[$loc] ?? $map['en'];
    foreach ($cols as $col) {
        if (array_key_exists($col, $row)) {
            $t = trim((string) $row[$col]);
            if ($t !== '') {
                return $t;
            }
        }
    }
    foreach ($map['en'] as $col) {
        if (array_key_exists($col, $row)) {
            $t = trim((string) $row[$col]);
            if ($t !== '') {
                return $t;
            }
        }
    }

    return '';
}

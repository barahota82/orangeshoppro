<?php

declare(strict_types=1);

/**
 * Storefront-by-country helpers (S32 / C1–C8). Country is public authority; channels are not required.
 */

require_once __DIR__ . '/countries.php';

/**
 * @return list<array{id:int, code:string, name_ar:string, name_en:string, is_active:int}>
 */
function orange_storefront_active_country_rows(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'countries')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT ' . orange_countries_row_select_sql($pdo) . '
         FROM countries WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    );

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Resolve country for bare/public entry without Geo/channel.
 *
 * @return array{country_code:string,country_id:int,needs_picker:bool}|null null = no active market
 */
function orange_storefront_resolve_public_entry_country(PDO $pdo): ?array
{
    $rows = orange_storefront_active_country_rows($pdo);
    $n = count($rows);
    if ($n === 0) {
        return null;
    }
    if ($n === 1) {
        $row = $rows[0];

        return [
            'country_code' => orange_countries_normalize_code((string) ($row['code'] ?? '')),
            'country_id' => (int) ($row['id'] ?? 0),
            'needs_picker' => false,
        ];
    }
    $cookie = orange_storefront_read_saved_country_code();
    if ($cookie !== null) {
        $row = orange_country_row_by_code($pdo, $cookie, true);
        if ($row !== null) {
            return [
                'country_code' => orange_countries_normalize_code((string) ($row['code'] ?? '')),
                'country_id' => (int) ($row['id'] ?? 0),
                'needs_picker' => false,
            ];
        }
    }

    return [
        'country_code' => '',
        'country_id' => 0,
        'needs_picker' => true,
    ];
}

function orange_storefront_request_country_code_from_path_or_query(PDO $pdo): ?string
{
    if (isset($_GET['country']) && (string) $_GET['country'] !== '') {
        $code = orange_countries_normalize_code((string) $_GET['country']);
        if ($code !== '' && orange_country_row_by_code($pdo, $code, true) !== null) {
            return $code;
        }
    }
    if (!function_exists('orange_storefront_request_primary_path_segment')) {
        return null;
    }
    $primary = orange_storefront_request_primary_path_segment();
    if ($primary === null || $primary === '') {
        return null;
    }
    $seg = strtolower($primary);
    if (function_exists('orange_storefront_reserved_path_segments')) {
        foreach (orange_storefront_reserved_path_segments() as $rs) {
            if ($seg === strtolower((string) $rs)) {
                return null;
            }
        }
    }
    // Strip lang suffix if present (kw-ar → kw)
    if (preg_match('/^(.+)-(ar|hi|ph)$/', $seg, $m) === 1) {
        $seg = $m[1];
    }
    $code = orange_countries_normalize_code($seg);
    if ($code !== '' && orange_country_row_by_code($pdo, $code, true) !== null) {
        return $code;
    }

    return null;
}

/**
 * تطبيع/تحقق رقم واتساب دولي للدولة (س32/C6).
 * فارغ = مقبول. غير صالح = null (رفض عند الحفظ؛ إخفاء عند الاستخدام).
 *
 * @return array{ok:bool, digits:string, empty:bool, message:string}
 */
function orange_storefront_whatsapp_normalize_for_country(string $raw): array
{
    $trim = trim($raw);
    if ($trim === '') {
        return ['ok' => true, 'digits' => '', 'empty' => true, 'message' => ''];
    }
    $digits = preg_replace('/\D+/', '', $trim);
    if (!is_string($digits) || $digits === '') {
        return [
            'ok' => false,
            'digits' => '',
            'empty' => false,
            'message' => 'رقم واتساب غير صالح — أدخل رقماً دولياً بأرقام فقط أو اتركه فارغاً',
        ];
    }
    // دولي: 8–15 رقماً (E.164 بلا +) ولا يبدأ بصفر.
    if (preg_match('/^[1-9][0-9]{7,14}$/', $digits) !== 1) {
        return [
            'ok' => false,
            'digits' => '',
            'empty' => false,
            'message' => 'رقم واتساب غير صالح — الصيغة الدولية 8–15 رقماً دون صفر بادئ (أو اترك الحقل فارغاً)',
        ];
    }

    return ['ok' => true, 'digits' => $digits, 'empty' => false, 'message' => ''];
}

/**
 * Digits-only WhatsApp for a country, or empty if unset/invalid (لا رابط معطوب).
 */
function orange_storefront_country_whatsapp_digits(PDO $pdo, int $countryId): string
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'countries')) {
        return '';
    }
    if (!orange_table_has_column($pdo, 'countries', 'whatsapp_number')) {
        return '';
    }
    $st = $pdo->prepare('SELECT whatsapp_number FROM countries WHERE id = ? LIMIT 1');
    $st->execute([$countryId]);
    $raw = trim((string) ($st->fetchColumn() ?: ''));
    $norm = orange_storefront_whatsapp_normalize_for_country($raw);

    return ($norm['ok'] && !$norm['empty']) ? $norm['digits'] : '';
}

/**
 * Synthetic storefront context row (replaces channel row for WA/UI).
 *
 * @return array{id:int,name:string,slug:string,logo:string,whatsapp_number:string,country_id:int}
 */
function orange_storefront_country_context_row(PDO $pdo, int $countryId, string $countryCode): array
{
    $wa = orange_storefront_country_whatsapp_digits($pdo, $countryId);
    $name = 'Orange';
    $row = orange_country_row_by_id($pdo, $countryId, false);
    if ($row !== null) {
        $ar = trim((string) ($row['name_ar'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $name = $ar !== '' ? $ar : ($en !== '' ? $en : $name);
    }

    return [
        'id' => 0,
        'name' => $name,
        'slug' => $countryCode,
        'logo' => 'logo-orange.png',
        'whatsapp_number' => $wa,
        'country_id' => $countryId,
    ];
}

function orange_storefront_country_path_segment(string $countryCode, string $lang): ?string
{
    $code = orange_countries_normalize_code($countryCode);
    if ($code === '') {
        return null;
    }
    $suffix = match ($lang) {
        'en' => '',
        'ar' => '-ar',
        'hi' => '-hi',
        'fil' => '-ph',
        default => null,
    };
    if ($suffix === null) {
        return null;
    }

    return $code . $suffix;
}

/**
 * Resolve active country for storefront checkout (payload → cookie/path). Fail closed = 0.
 *
 * @param array<string, mixed> $data
 */
function orange_storefront_resolve_checkout_country_id(PDO $pdo, array $data): int
{
    $fromPayload = (int) ($data['country_id'] ?? 0);
    if ($fromPayload > 0) {
        $row = orange_country_row_by_id($pdo, $fromPayload, true);
        if ($row !== null) {
            return $fromPayload;
        }
    }
    $code = orange_countries_normalize_code((string) ($data['country_code'] ?? ''));
    if ($code !== '') {
        $row = orange_country_row_by_code($pdo, $code, true);
        if ($row !== null) {
            return (int) ($row['id'] ?? 0);
        }
    }
    $cid = orange_storefront_current_country_id($pdo);

    return $cid > 0 ? $cid : 0;
}

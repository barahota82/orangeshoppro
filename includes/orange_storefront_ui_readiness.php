<?php
declare(strict_types=1);

/**
 * Storefront interface readiness comes from get_translations() after pack merge.
 * Required keys are the non-empty English pack keys, not a short sample.
 * A locale is ready only when every required key is a non-empty string.
 * Names and direction are not decided here.
 * includes/i18n/catalogs/v1/fr.php stays unwired.
 */

/**
 * @return list<string>
 */
function orange_storefront_ui_required_keys(): array
{
    if (!function_exists('get_translations')) {
        return [];
    }
    $packs = get_translations();
    $keys = [];
    foreach ($packs['en'] ?? [] as $key => $value) {
        if (!is_string($key) || !is_string($value) || trim($value) === '') {
            continue;
        }
        $keys[] = $key;
    }

    return $keys;
}

/**
 * @return list<string>
 */
function orange_storefront_ui_live_ready_locales(): array
{
    if (!function_exists('get_translations')) {
        return [];
    }
    $packs = get_translations();
    $required = orange_storefront_ui_required_keys();
    if ($required === []) {
        return [];
    }
    $ready = [];
    foreach ($packs as $code => $pack) {
        if (!is_string($code) || !preg_match('/^[a-z]{2,3}$/', $code) || !is_array($pack)) {
            continue;
        }
        if (orange_storefront_ui_pack_covers($pack, $required)) {
            $ready[] = $code;
        }
    }

    return $ready;
}

/**
 * @return list<string>
 */
function orange_storefront_ui_dormant_incomplete_locales(): array
{
    return ['fr'];
}

/**
 * @param list<string>|null $required
 */
function orange_storefront_ui_pack_covers(array $pack, ?array $required = null): bool
{
    $keys = $required ?? orange_storefront_ui_required_keys();
    if ($keys === []) {
        return false;
    }
    foreach ($keys as $key) {
        $value = $pack[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
    }

    return true;
}

function orange_storefront_ui_pack_complete(array $pack): bool
{
    return orange_storefront_ui_pack_covers($pack, null);
}

function orange_storefront_ui_lookup(array $packs, string $locale, string $key): ?string
{
    $code = strtolower(trim($locale));
    $row = $packs[$code] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $value = $row[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return $value;
}

function orange_storefront_ui_direction(string $locale, ?string $catalogDir = null): string
{
    if ($catalogDir === 'rtl' || $catalogDir === 'ltr') {
        return $catalogDir;
    }

    return strtolower(trim($locale)) === 'ar' ? 'rtl' : 'ltr';
}

/**
 * @param list<string> $catalogCodes
 * @return array{
 *   live_ready:list<string>,
 *   ready_in_catalog:list<string>,
 *   remaining_in_catalog:list<string>,
 *   dormant_incomplete:list<string>,
 *   dormant_note:string,
 *   live_note:string
 * }
 */
function orange_storefront_ui_inventory(array $catalogCodes): array
{
    $live = orange_storefront_ui_live_ready_locales();
    $ready = [];
    $remaining = [];
    foreach ($catalogCodes as $code) {
        $c = strtolower(trim((string) $code));
        if ($c === '') {
            continue;
        }
        if (in_array($c, $live, true)) {
            $ready[] = $c;
        } else {
            $remaining[] = $c;
        }
    }

    return [
        'live_ready' => $live,
        'ready_in_catalog' => array_values(array_unique($ready)),
        'remaining_in_catalog' => array_values(array_unique($remaining)),
        'dormant_incomplete' => orange_storefront_ui_dormant_incomplete_locales(),
        'dormant_note' => 'includes/i18n/catalogs/v1/fr.php is an empty foundation and is not wired to t(). Listing it is not an implementation.',
        'live_note' => 'Ready locales are discovered from get_translations() after the pack files are merged. One complete pack does not translate the rest of the catalog.',
    ];
}

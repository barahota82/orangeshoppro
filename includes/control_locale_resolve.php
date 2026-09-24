<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.2 — dormant Control locale resolution library.
 *
 * Control PDO only. COUNTRY_DB_OPEN_COUNT must remain 0.
 * No Admin/Storefront/runtime callers in this phase.
 */

/** @throws RuntimeException locale_digit_machine_reject */
function orange_ctrl_digits_normalize_human(string $input): string
{
    $map = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];
    $out = '';
    $len = mb_strlen($input, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($input, $i, 1, 'UTF-8');
        $out .= $map[$ch] ?? $ch;
    }

    return $out;
}

/**
 * ASCII 0-9 only. Any Eastern / other Nd digit → locale_digit_machine_reject.
 *
 * @throws RuntimeException
 */
function orange_ctrl_digits_reject_machine(string $input): void
{
    $len = mb_strlen($input, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($input, $i, 1, 'UTF-8');
        $ord = null;
        if (function_exists('mb_ord')) {
            $ord = mb_ord($ch, 'UTF-8');
        } else {
            $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'));
            $ord = is_array($u) ? (int) $u[1] : null;
        }
        if ($ord === null) {
            continue;
        }
        // ASCII digits ok
        if ($ord >= 0x30 && $ord <= 0x39) {
            continue;
        }
        // Arabic-Indic / Extended Arabic-Indic → reject for machine
        if (($ord >= 0x0660 && $ord <= 0x0669) || ($ord >= 0x06F0 && $ord <= 0x06F9)) {
            throw new RuntimeException('locale_digit_machine_reject');
        }
        // Other Nd (decimal number) — reject
        if (preg_match('/\p{Nd}/u', $ch) === 1) {
            throw new RuntimeException('locale_digit_machine_reject');
        }
    }
}

function orange_ctrl_locale_normalize_code(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $c = strtolower(trim($raw));
    if ($c === '') {
        return null;
    }
    if ($c === 'tl') {
        $c = 'fil';
    }
    if (!preg_match('/^[a-z]{2,3}$/', $c)) {
        return null;
    }

    return $c;
}

function orange_ctrl_locale_dir(string $localeCode): string
{
    $c = orange_ctrl_locale_normalize_code($localeCode);
    if ($c === null) {
        throw new RuntimeException('locale_code_invalid');
    }

    return $c === 'ar' ? 'rtl' : 'ltr';
}

/**
 * @return array{eligible:bool,reason:?string,lifecycle:?string}
 */
function orange_ctrl_locale_lifecycle_eligible(PDO $controlPdo, int $countryId, string $surface): array
{
    $st = $controlPdo->prepare(
        'SELECT lifecycle_status FROM ctrl_countries WHERE id = ? LIMIT 1'
    );
    $st->execute([$countryId]);
    $life = $st->fetchColumn();
    if ($life === false || $life === null) {
        return ['eligible' => false, 'reason' => 'locale_country_lifecycle_ineligible', 'lifecycle' => null];
    }
    $life = strtolower(trim((string) $life));
    $ok = match ($surface) {
        'admin', 'report' => in_array($life, ['ready', 'active'], true),
        'storefront', 'legal' => $life === 'active',
        default => false,
    };

    return [
        'eligible' => $ok,
        'reason' => $ok ? null : 'locale_country_lifecycle_ineligible',
        'lifecycle' => $life,
    ];
}

/**
 * @return array{ok:bool,reason:?string,dir:?string,code:?string}
 */
function orange_ctrl_locale_evaluate_candidate(
    PDO $controlPdo,
    int $countryId,
    ?string $rawLocale,
    string $surface /* admin|storefront|document */,
    string $lifecycleSurface /* admin|storefront|report|legal */
): array {
    $code = orange_ctrl_locale_normalize_code($rawLocale);
    if ($code === null) {
        return ['ok' => false, 'reason' => 'locale_code_invalid', 'dir' => null, 'code' => null];
    }
    $life = orange_ctrl_locale_lifecycle_eligible($controlPdo, $countryId, $lifecycleSurface);
    if (!$life['eligible']) {
        return [
            'ok' => false,
            'reason' => 'locale_country_lifecycle_ineligible',
            'dir' => null,
            'code' => $code,
        ];
    }
    $st = $controlPdo->prepare(
        'SELECT dir, is_active_global FROM ctrl_locales WHERE locale_code = ? LIMIT 1'
    );
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int) $row['is_active_global'] !== 1) {
        return ['ok' => false, 'reason' => 'locale_global_inactive', 'dir' => null, 'code' => $code];
    }
    $col = match ($surface) {
        'admin' => 'is_enabled_admin',
        'storefront' => 'is_enabled_storefront',
        'document' => 'is_enabled_document',
        default => 'is_enabled_admin',
    };
    $st2 = $controlPdo->prepare(
        "SELECT `{$col}` AS en FROM ctrl_country_locales
         WHERE country_id = ? AND locale_code = ? LIMIT 1"
    );
    $st2->execute([$countryId, $code]);
    $en = $st2->fetchColumn();
    if ($en === false || (int) $en !== 1) {
        $reason = $surface === 'document' ? 'locale_document_not_enabled' : 'locale_surface_not_enabled';

        return ['ok' => false, 'reason' => $reason, 'dir' => null, 'code' => $code];
    }
    $dir = strtolower((string) $row['dir']);
    if ($dir !== 'rtl' && $dir !== 'ltr') {
        $dir = $code === 'ar' ? 'rtl' : 'ltr';
    }

    return ['ok' => true, 'reason' => null, 'dir' => $dir, 'code' => $code];
}

/**
 * @param list<array{code:string,reason_code:string}> $skipped
 * @return array<string,mixed>
 */
function orange_ctrl_locale_context_base(
    string $surface,
    int $countryId,
    array $skipped,
    ?string $resolved,
    ?string $dir,
    string $source,
    ?string $failure,
    ?int $adminId = null,
    ?string $documentClass = null
): array {
    $out = [
        'surface' => $surface,
        'resolved_locale' => $resolved,
        'dir' => $dir,
        'source' => $source,
        'skipped' => $skipped,
        'country_id' => $countryId,
        'failure_code' => $failure,
        'country_db_open_count' => 0,
        'numbering_system' => 'latn',
    ];
    if ($adminId !== null) {
        $out['admin_id'] = $adminId;
    }
    if ($documentClass !== null) {
        $out['document_class'] = $documentClass;
    }

    return $out;
}

/**
 * Admin UI locale context — FINAL chain: session → preferred → country_default_admin → en.
 *
 * @return array<string,mixed>
 */
function orange_admin_resolve_ui_locale_context(
    PDO $controlPdo,
    int $adminId,
    ?string $sessionOverride,
    int $countryId
): array {
    $skipped = [];
    $try = static function (?string $raw, string $sourceTag) use ($controlPdo, $countryId, &$skipped): ?array {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $ev = orange_ctrl_locale_evaluate_candidate($controlPdo, $countryId, $raw, 'admin', 'admin');
        if (!$ev['ok']) {
            $skipped[] = ['code' => (string) ($ev['code'] ?? $raw), 'reason_code' => (string) $ev['reason']];

            return null;
        }

        return ['locale' => $ev['code'], 'dir' => $ev['dir'], 'source' => $sourceTag];
    };

    $hit = $try($sessionOverride, 'session');
    if ($hit === null) {
        $st = $controlPdo->prepare(
            'SELECT preferred_ui_language FROM ctrl_admins WHERE id = ? LIMIT 1'
        );
        $st->execute([$adminId]);
        $pref = $st->fetchColumn();
        $hit = $try($pref === false ? null : (string) $pref, 'preferred');
    }
    if ($hit === null) {
        $st = $controlPdo->prepare(
            'SELECT locale_code FROM ctrl_country_locales
             WHERE country_id = ? AND is_default_admin = 1 LIMIT 1'
        );
        $st->execute([$countryId]);
        $def = $st->fetchColumn();
        $hit = $try($def === false ? null : (string) $def, 'country_default_admin');
    }
    if ($hit === null) {
        $hit = $try('en', 'fallback_en');
        if ($hit === null) {
            return orange_ctrl_locale_context_base(
                'admin',
                $countryId,
                $skipped,
                null,
                null,
                'none',
                'locale_country_lifecycle_ineligible',
                $adminId
            );
        }
    }

    return orange_ctrl_locale_context_base(
        'admin',
        $countryId,
        $skipped,
        $hit['locale'],
        $hit['dir'],
        $hit['source'],
        null,
        $adminId
    );
}

function orange_admin_resolve_ui_locale(
    PDO $controlPdo,
    int $adminId,
    ?string $sessionOverride,
    int $countryId
): string {
    $ctx = orange_admin_resolve_ui_locale_context($controlPdo, $adminId, $sessionOverride, $countryId);
    if (!is_string($ctx['resolved_locale'] ?? null) || $ctx['resolved_locale'] === '') {
        throw new RuntimeException((string) ($ctx['failure_code'] ?? 'locale_country_lifecycle_ineligible'));
    }

    return (string) $ctx['resolved_locale'];
}

/**
 * Storefront dormant resolver — get→path→cookie→accept→country_default_storefront→en.
 *
 * @param array{get_lang?:?string,path_lang?:?string,cookie_lang?:?string,accept_lang?:?string} $hints
 * @return array<string,mixed>
 */
function orange_storefront_resolve_ui_locale_context(
    PDO $controlPdo,
    int $countryId,
    array $hints
): array {
    $skipped = [];
    $order = [
        ['key' => 'get_lang', 'source' => 'explicit_get'],
        ['key' => 'path_lang', 'source' => 'explicit_path'],
        ['key' => 'cookie_lang', 'source' => 'cookie'],
        ['key' => 'accept_lang', 'source' => 'accept'],
    ];
    foreach ($order as $step) {
        $raw = isset($hints[$step['key']]) ? (string) $hints[$step['key']] : null;
        if ($raw === null || trim($raw) === '') {
            continue;
        }
        $ev = orange_ctrl_locale_evaluate_candidate($controlPdo, $countryId, $raw, 'storefront', 'storefront');
        if ($ev['ok']) {
            return orange_ctrl_locale_context_base(
                'storefront',
                $countryId,
                $skipped,
                $ev['code'],
                $ev['dir'],
                $step['source'],
                null
            );
        }
        $skipped[] = ['code' => (string) ($ev['code'] ?? $raw), 'reason_code' => (string) $ev['reason']];
    }
    $st = $controlPdo->prepare(
        'SELECT locale_code FROM ctrl_country_locales
         WHERE country_id = ? AND is_default_storefront = 1 LIMIT 1'
    );
    $st->execute([$countryId]);
    $def = $st->fetchColumn();
    if ($def !== false) {
        $ev = orange_ctrl_locale_evaluate_candidate(
            $controlPdo,
            $countryId,
            (string) $def,
            'storefront',
            'storefront'
        );
        if ($ev['ok']) {
            return orange_ctrl_locale_context_base(
                'storefront',
                $countryId,
                $skipped,
                $ev['code'],
                $ev['dir'],
                'country_default_storefront',
                null
            );
        }
        $skipped[] = ['code' => (string) ($ev['code'] ?? $def), 'reason_code' => (string) $ev['reason']];
    }
    $ev = orange_ctrl_locale_evaluate_candidate($controlPdo, $countryId, 'en', 'storefront', 'storefront');
    if ($ev['ok']) {
        return orange_ctrl_locale_context_base(
            'storefront',
            $countryId,
            $skipped,
            $ev['code'],
            $ev['dir'],
            'fallback_en',
            null
        );
    }
    $skipped[] = ['code' => 'en', 'reason_code' => (string) $ev['reason']];

    return orange_ctrl_locale_context_base(
        'storefront',
        $countryId,
        $skipped,
        null,
        null,
        'none',
        (string) ($ev['reason'] ?? 'locale_country_lifecycle_ineligible')
    );
}

/**
 * Document chains A/B/C per S1.
 *
 * @return array<string,mixed>
 */
function orange_document_resolve_ui_locale_context(
    PDO $controlPdo,
    int $countryId,
    string $documentClass,
    ?string $explicitLocale
): array {
    $class = strtolower(trim($documentClass));
    if ($class === 'future_taqfeet_boundary') {
        return orange_ctrl_locale_context_base(
            'document',
            $countryId,
            [],
            null,
            null,
            'future_boundary_only',
            'locale_taqfeet_future_boundary_only',
            null,
            'future_taqfeet_boundary'
        );
    }
    if ($class !== 'report' && $class !== 'legal') {
        return orange_ctrl_locale_context_base(
            'document',
            $countryId,
            [],
            null,
            null,
            'none',
            'locale_code_invalid',
            null,
            $class
        );
    }
    $lifeSurface = $class === 'report' ? 'report' : 'legal';
    $skipped = [];
    $try = static function (?string $raw, string $sourceTag) use (
        $controlPdo,
        $countryId,
        $lifeSurface,
        &$skipped
    ): ?array {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $ev = orange_ctrl_locale_evaluate_candidate(
            $controlPdo,
            $countryId,
            $raw,
            'document',
            $lifeSurface
        );
        if (!$ev['ok']) {
            $skipped[] = ['code' => (string) ($ev['code'] ?? $raw), 'reason_code' => (string) $ev['reason']];

            return null;
        }

        return ['locale' => $ev['code'], 'dir' => $ev['dir'], 'source' => $sourceTag];
    };

    $hit = $try($explicitLocale, 'explicit');
    if ($hit === null) {
        $st = $controlPdo->prepare(
            'SELECT locale_code FROM ctrl_country_locales
             WHERE country_id = ? AND is_default_document = 1 LIMIT 1'
        );
        $st->execute([$countryId]);
        $def = $st->fetchColumn();
        if ($def === false) {
            $skipped[] = ['code' => '', 'reason_code' => 'locale_document_default_missing'];
        } else {
            $hit = $try((string) $def, 'country_default_document');
        }
    }
    if ($hit === null) {
        $hit = $try('en', 'fallback_en');
        if ($hit === null) {
            return orange_ctrl_locale_context_base(
                'document',
                $countryId,
                $skipped,
                null,
                null,
                'none',
                'locale_document_fallback_en_ineligible',
                null,
                $class
            );
        }
    }

    return orange_ctrl_locale_context_base(
        'document',
        $countryId,
        $skipped,
        $hit['locale'],
        $hit['dir'],
        $hit['source'],
        null,
        null,
        $class
    );
}

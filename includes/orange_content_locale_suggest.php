<?php
declare(strict_types=1);

/**
 * Suggestion for one shared text field. Reuses orange_department_suggest().
 * Country and roles come from the caller. Posted country_id, actor, and full_access are ignored.
 * Does not require a global admin and does not load config.php.
 */

require_once __DIR__ . '/orange_department_locale_save.php';
require_once __DIR__ . '/orange_content_locale_field.php';
require_once __DIR__ . '/orange_translate_contract.php';

/**
 * @param callable(string,string,string):string|null $fetch
 * @return array{status:int,body:array<string,mixed>}
 */
function orange_content_locale_suggest_handle(PDO $pdo, int $countryId, array $data, ?callable $fetch = null): array
{
    unset($data['country_id'], $data['full_access'], $data['actor']);
    if ($countryId <= 0) {
        return [
            'status' => 422,
            'body' => ['success' => false, 'code' => 'country_context_required', 'message' => 'يلزم سياق الدولة', 'suggestions' => []],
        ];
    }
    if (!orange_content_locale_screen_ready($pdo, $countryId)) {
        return [
            'status' => 422,
            'body' => ['success' => false, 'code' => 'not_configured', 'message' => 'إعداد لغات المحتوى غير مهيأ', 'suggestions' => []],
        ];
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $active = [];
    try {
        $active = orange_language_reference_active_codes($pdo);
    } catch (Throwable $e) {
        $active = [];
    }
    $panel = orange_content_panel_locales($roles, $active);
    $corrected = array_key_exists('corrected_english', $data) ? (string) $data['corrected_english'] : null;
    $previous = array_key_exists('previous_english', $data) ? (string) $data['previous_english'] : null;
    try {
        $result = orange_department_suggest(
            (string) ($roles['base'] ?? ''),
            (string) ($data['base_text'] ?? ''),
            $panel,
            is_array($data['known'] ?? null) ? $data['known'] : [],
            is_array($data['explicit'] ?? null) ? $data['explicit'] : [],
            $fetch,
            1000,
            $corrected,
            $previous
        );
    } catch (Throwable $e) {
        return [
            'status' => 200,
            'body' => ['success' => false, 'code' => 'transport_failed', 'message' => 'تعذر طلب الترجمة', 'suggestions' => []],
        ];
    }
    $result['success'] = true;
    $result['message'] = 'ok';
    $result['customer_wired'] = false;

    return ['status' => 200, 'body' => $result];
}

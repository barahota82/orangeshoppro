<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/orange_content_locale_field.php';
require_once __DIR__ . '/../../../includes/orange_content_locale_suggest.php';
require_once __DIR__ . '/../../../includes/orange_department_suggest_service.php';

require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    if (!is_array($data)) {
        $data = [];
    }
    $countryId = orange_admin_settings_effective_country_id($pdo);
    $handled = orange_content_locale_suggest_handle(
        $pdo,
        $countryId,
        $data,
        static fn (string $text, string $to, string $from): string => orange_department_suggest_provider_fetch($text, $to, $from)
    );
    json_response($handled['body'], (int) $handled['status']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر طلب الترجمة');
}

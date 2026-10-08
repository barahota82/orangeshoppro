<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/department_countries.php';
require_once __DIR__ . '/../../../includes/orange_department_integration.php';
require_admin_api();

try {
    orange_department_countries_require_global_admin();
    $pdo = db();
    $data = get_json_input();
    unset($data['actor'], $data['full_access']);
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$adminId]);
    $admin = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($admin)) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    $result = orange_department_integration_handle($pdo, $admin, 'read', $data, $countryId, null);
    json_response($result['body'], (int) $result['status']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر قراءة القسم');
}

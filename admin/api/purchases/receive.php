<?php

declare(strict_types=1);

/**
 * مُبقى للتوافق مع روابط قديمة فقط.
 * سياسة المخزون: حفظ فاتورة الشراء يزيد المتغيرات ويُرحّل المخزون فوراً — لا استلام لاحق.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    json_response([
        'success' => false,
        'message' => 'حفظ فاتورة الشراء يُسجّل استلام المخزن بالكامل تلقائياً. لا يوجد استلام جزئي أو منفصل.',
    ], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'طلب غير مدعوم');
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_section_index.php';

/** @var array<string,mixed> $admin — من admin/index.php */
$pdo = db();

orange_admin_render_mega_section_index(
    $admin,
    $pdo,
    'accounting',
    'accounting_reports_index',
    'فهرس الحسابات والتقارير',
    '',
    [] // الجمل التوضيحية أُزيلت بقرار المالك — تُعرض بطاقات بعناوينها فقط
);

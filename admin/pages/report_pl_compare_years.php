<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">مقارنة أرباح وخسائر بين السنوات المالية</h1>
    <p class="admin-fy-shell__lead">هذه الشاشة قيد التطوير — ستعرض مقارنة صافي الدخل بين سنوات محددة.</p>
</div>

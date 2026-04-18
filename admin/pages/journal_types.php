<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
?>
<div class="page-title">
    <h1>أنواع اليوميات</h1>
</div>
<div class="card">
    <p class="card-hint" style="margin:0;">
        هذه الشاشة مخصّصة <strong>لتنظيم القائمة</strong> ضمن خطة العمل. سيتم ربطها لاحقاً بإعداد أنواع اليوميات / تجميع القيود حسب النوع.
        القيود اليدوية الحالية من <a href="/admin/index.php?page=journal_entries">القيود المحاسبية</a>.
    </p>
</div>

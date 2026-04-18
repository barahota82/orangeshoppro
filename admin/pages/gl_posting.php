<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
?>
<div class="page-title">
    <h1>الترحيل إلى الحسابات</h1>
</div>
<div class="card">
    <p class="card-hint" style="margin:0;">
        هذه الشاشة مخصّصة <strong>لتنظيم القائمة</strong> ضمن خطة العمل. الترحيل الفعلي للقيود يتم عبر تسجيل السندات في النظام (مثلاً
        <a href="/admin/index.php?page=journal_entries">القيود المحاسبية</a> وسندات الذمم والمشتريات).
        سيتم تعريف واجهة «ترحيل» مستقلة عند الحاجة.
    </p>
</div>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

/** @var array<string, mixed> $admin */
$pdo = orange_admin_page_pdo();

if (!orange_admin_may_restore_center_view($admin, $pdo)) {
    echo '<div class="card"><div class="alert-error">لا تملك صلاحية عرض إدارة الاسترداد.</div></div>';

    return;
}

orange_admin_render_page_title_with_country('إدارة الاسترداد', $pdo, [
    'subtitle' => 'Phase 3B — شاشة مستقلة عن إدارة النسخ الاحتياطي. سيتم تنفيذ الوظائف بعد اعتماد خطة Restore Center.',
]);
?>
<div class="card">
    <p class="card-hint" style="margin:0 0 1rem;line-height:1.65;">
        <strong>Restore Center</strong> — نطاق تشغيلي منفصل عن Backup Center.
        إدارة النسخ الاحتياطي تبقى مسؤولة عن النسخ والتحقق وDRV والسجلات والتخزين فقط.
    </p>
    <ul class="card-hint" style="margin:0;padding-inline-start:1.25rem;line-height:1.75;">
        <li>Restore Jobs</li>
        <li>Package selection</li>
        <li>Staging Restore</li>
        <li>Approval</li>
        <li>Merge</li>
        <li>Rollback</li>
    </ul>
    <p class="muted" style="margin:1rem 0 0;">لا توجد إجراءات استرداد في هذه المرحلة — انتظر خطة التنفيذ.</p>
</div>

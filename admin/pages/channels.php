<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$channels = $pdo->query('SELECT * FROM channels ORDER BY id ASC')->fetchAll();
$sfPreviewConfigured = ORANGE_STOREFRONT_PREVIEW_TOKEN !== '';
?>
<div class="page-title">
    <h1>الواجهات (قنوات العملاء)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">كل قناة تمثّل واجهة أو مصدراً لتجميع <strong>العملاء وطلباتهم</strong>. المخزون والمبيعات المحاسبية <strong>للشركة موحّدة</strong> — الطلب من أي قناة يسحب من نفس المخزن الرئيسي.</p>
    <p style="margin:0.6rem 0 0;"><a class="btn btn-secondary" href="/admin/index.php?page=channel_analytics">تحليل أداء القنوات (مبيعات، أكثر منتج، ترتيب النشاط)</a></p>
    <?php if (!$sfPreviewConfigured): ?>
        <p class="card-hint" style="margin:0.75rem 0 0;color:#b45309;">لتفعيل روابط «معاينة الواجهة» من هذا الجدول، عيّن <code dir="ltr">ORANGE_STOREFRONT_PREVIEW_TOKEN</code> في ملف <code dir="ltr">.env.php</code> (سلسلة عشوائية طويلة)، ثم أعد تحميل الصفحة.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>إضافة واجهة</h3>
    <p class="card-hint" style="margin:0 0 0.75rem;">اختصار الرابط يظهر في عنوان الموقع مثل <code>/tiktok</code> أو <code>/instagram</code> — يُربط تلقائياً بالمخزن الموحّد. يُنشأ معرّف داخلي (slug) تلقائياً للنظام.</p>
    <div class="form-grid">
        <div>
            <label>اسم الواجهة</label>
            <input type="text" id="channel_name" placeholder="مثال: متجر إنستغرام">
        </div>
        <div>
            <label>اختصار الرابط (بالإنجليزية)</label>
            <input type="text" id="channel_path_segment" placeholder="مثل: instagram أو sale" dir="ltr" lang="en" autocomplete="off">
        </div>
        <div>
            <label>الشعار (اسم الملف)</label>
            <input type="text" id="channel_logo">
        </div>
        <div>
            <label>اللون الأساسي</label>
            <input type="text" id="channel_color" placeholder="#ff6600">
        </div>
        <div>
            <label>رقم الواتساب</label>
            <input type="text" id="channel_whatsapp">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" onclick="saveChannel()">حفظ الواجهة</button>
    </div>
</div>

<div class="card">
    <h3>قائمة الواجهات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>اختصار URL</th>
                    <th>Slug داخلي</th>
                    <th>اللون</th>
                    <th>الواتساب</th>
                    <th>معاينة الواجهة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($channels as $ch): ?>
                <tr>
                    <td><?php echo (int)$ch['id']; ?></td>
                    <td><?php echo htmlspecialchars($ch['name']); ?></td>
                    <td><code dir="ltr"><?php echo htmlspecialchars((string)($ch['path_segment'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><code dir="ltr"><?php echo htmlspecialchars($ch['slug']); ?></code></td>
                    <td><?php echo htmlspecialchars($ch['primary_color']); ?></td>
                    <td><?php echo htmlspecialchars($ch['whatsapp_number']); ?></td>
                    <td dir="ltr"><?php
                    $ps = trim((string) ($ch['path_segment'] ?? ''));
                    if ($ps === '') {
                        echo '—';
                    } elseif (!$sfPreviewConfigured) {
                        echo '<span class="card-hint">أضف المفتاح في .env.php</span>';
                    } else {
                        $prevUrl = orange_storefront_admin_preview_home_url($ps);
                        echo $prevUrl !== ''
                            ? '<a href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">فتح الرئيسية</a>'
                            : '—';
                    }
                    ?></td>
                    <td><?php echo (int)$ch['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function saveChannel() {
    const payload = {
        name: document.getElementById('channel_name').value.trim(),
        path_segment: document.getElementById('channel_path_segment').value.trim(),
        logo: document.getElementById('channel_logo').value.trim(),
        primary_color: document.getElementById('channel_color').value.trim(),
        whatsapp_number: document.getElementById('channel_whatsapp').value.trim()
    };
    const res = await postJSON('/admin/api/channels/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ الواجهة' : 'فشل الحفظ'));
    if (res.success) location.reload();
}
</script>

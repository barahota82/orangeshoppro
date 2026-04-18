<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$accounts = $pdo->query(
    'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\') ASC, name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$labels = orange_gl_setting_key_labels();
$keys = array_keys($labels);

$current = [];
if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
    $rows = $pdo->query('SELECT setting_key, account_id FROM orange_gl_account_settings')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $current[(string)$r['setting_key']] = (int)$r['account_id'];
    }
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>الحسابات الأساسية للقيود التلقائية</h1>
        <p class="page-subtitle">
            <strong>هيكل الجذور والشجرة</strong> تُنشئه من
            <a href="/admin/index.php?page=chart_of_accounts">الدليل المحاسبي</a>
            (مستوى أول = «بدون أب»). هذه الصفحة للربط فقط: بعد إعداد الشجرة (مع <strong>code</strong> إن رغبت)، اربط كل بند أدناه بالحساب المناسب.
            القيود الناتجة عن <strong>تسليم الطلبات</strong> و<strong>فواتير الشراء</strong> تستخدم هذه الربطات بدل الأرقام الثابتة القديمة.
        </p>
    </div>
</div>

<div class="card">
    <p class="card-hint">
        إن تركت حقلًا فارغًا ولم يوجد ربط، يُستخدم الاحتياط القديم (حسابات Cash / Sales / Inventory …) إن وُجدت بالاسم.
        لبيع <strong>آجل</strong> يجب ربط <strong>عملاء آجل</strong> و<strong>إيراد مبيعات آجل</strong> و<strong>تكلفة مبيعات آجل</strong> بشكل صحيح.
        لإغلاق السنة محاسبياً: اربط <strong>income_summary</strong> و<strong>retained_earnings</strong> بحسابات حقيقية في الدليل (وصنّفها في «الدليل المحاسبي»).
    </p>
    <div class="form-grid" id="gl-settings-grid">
        <?php foreach ($keys as $key): ?>
        <div style="grid-column:1/-1; display:grid; grid-template-columns: 1fr 2fr; gap:12px; align-items:end; margin-bottom:12px;">
            <div>
                <label><code><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code></label>
                <p class="page-subtitle" style="margin:4px 0 0;"><?php echo htmlspecialchars($labels[$key], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div>
                <label>الحساب من الدليل</label>
                <select class="gl-sel" data-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                    <option value="">— بدون ربط (احتياط بالاسم القديم إن وُجد) —</option>
                    <?php foreach ($accounts as $a): ?>
                        <?php
                        $aid = (int)$a['id'];
                        $code = trim((string)($a['code'] ?? ''));
                        $lab = $code !== '' ? ($code . ' — ' . $a['name']) : $a['name'];
                        ?>
                        <option value="<?php echo $aid; ?>" <?php echo (($current[$key] ?? 0) === $aid) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="actions" style="margin-top:18px;">
        <button type="button" onclick="glSave()">حفظ الربط</button>
        <a class="btn btn-secondary" href="/admin/index.php?page=chart_of_accounts">الدليل المحاسبي</a>
    </div>
</div>

<script>
function glSave() {
    var settings = {};
    document.querySelectorAll('.gl-sel').forEach(function (sel) {
        var k = sel.getAttribute('data-key');
        if (!k) return;
        settings[k] = parseInt(sel.value, 10) || 0;
    });
    postJSON('/admin/api/settings/gl-accounts.php', { action: 'save', settings: settings }).then(function (res) {
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success) location.reload();
    });
}
</script>

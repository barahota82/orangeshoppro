<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasTable = orange_table_exists($pdo, 'catalog_attributes');
$attrs = [];

if ($hasTable) {
    try {
        $attrs = $pdo->query(
            'SELECT attribute_key, label_ar, label_en, label_fil, label_hi,
                    input_kind, is_filterable, sort_order, is_active, created_at
             FROM catalog_attributes
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $attrs = [];
    }
}
?>
<div class="page-title">
    <h1>سمات الكتالوج</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;">عرض قراءة للتعريفات؛ <strong>قيم كل منتج</strong> تُحفظ من صفحة <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=products'), ENT_QUOTES, 'UTF-8'); ?>">المنتجات</a> ضمن قسم «صفات الكتالوج» وفق المرحلة D في سياسة التصنيف الموحَّد.</p>
</div>

<div class="card">
    <?php if (!$hasTable): ?>
        <div class="alert-error">جدول <code>catalog_attributes</code> غير موجود بعد تهيئة المخطط.</div>
    <?php elseif ($attrs === []): ?>
        <p style="margin:0;color:#555;">لا توجد سجلات بعد (المرحلة الاستكشافية).</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;font-size:0.93rem;">
                <thead>
                <tr>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">المفتاح</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">النوع</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">عربي</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">EN</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">فلتر؟</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">ترتيب</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">نشط</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($attrs as $row): ?>
                    <tr style="vertical-align:top;">
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['attribute_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['input_kind'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($row['label_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo ((int) ($row['is_filterable'] ?? 0) === 1) ? '√' : '—'; ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

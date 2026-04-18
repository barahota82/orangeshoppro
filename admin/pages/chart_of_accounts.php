<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$flat = orange_accounts_flat($pdo);
$tree = orange_accounts_build_tree($flat);
$depths = orange_accounts_depth_by_id($flat);
$hasClass = orange_table_has_column($pdo, 'accounts', 'account_class');

$classLabels = [
    'unclassified' => 'غير مصنف',
    'asset' => 'أصل',
    'liability' => 'خصم',
    'equity' => 'حقوق ملكية',
    'revenue' => 'إيراد',
    'expense' => 'مصروف',
    'cogs' => 'تكلفة بضاعة مباعة',
];

/**
 * @param list<array<string, mixed>> $nodes
 */
function orange_render_coa_tree(array $nodes, int $activeId): void
{
    echo '<ul class="coa-tree-list">';
    foreach ($nodes as $n) {
        $id = (int) $n['id'];
        $code = htmlspecialchars((string) ($n['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8');
        $isG = !empty($n['is_group']);
        $cls = $activeId === $id ? 'coa-tree-node is-active' : 'coa-tree-node';
        $ac = htmlspecialchars((string) ($n['account_class'] ?? 'unclassified'), ENT_QUOTES, 'UTF-8');
        echo '<li class="' . $cls . '" role="treeitem" data-id="' . $id . '" data-code="' . $code . '" data-name="' . $name . '" data-is-group="' . ($isG ? '1' : '0') . '" data-class="' . $ac . '" data-parent="' . (int) ($n['parent_id'] ?? 0) . '">';
        echo '<span class="coa-tree-label">' . $code . ' — ' . $name . ($isG ? ' <small>(مجموعة)</small>' : '') . '</span>';
        if (!empty($n['children'])) {
            orange_render_coa_tree($n['children'], $activeId);
        }
        echo '</li>';
    }
    echo '</ul>';
}

$firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>الدليل المحاسبي</h1>
        <p class="page-subtitle">
            شجرة حسابات مع <strong>اقتراح كود تلقائي</strong> تحت كل أب (قفل قصير على الخادم لتقليل التصادم عند عدة مستخدمين).
            صنّف الحسابات للتقارير و<a href="/admin/index.php?page=gl_account_settings">القيود التلقائية</a>.
        </p>
    </div>
</div>

<div class="coa-layout">
    <div class="card coa-tree-card">
        <h3 class="card-title">شجرة الحسابات</h3>
        <div class="coa-tree-scroll" id="coa_tree_root" role="tree">
            <?php if ($tree === []): ?>
                <p class="muted">لا توجد حسابات — أنشئ حساباً جذراً من النموذج.</p>
            <?php else: ?>
                <?php orange_render_coa_tree($tree, $firstId); ?>
            <?php endif; ?>
        </div>
        <div class="actions" style="margin-top:10px;">
            <button type="button" class="btn-secondary" id="coa_btn_new">حساب جديد</button>
        </div>
    </div>
    <div class="card coa-form-card">
        <h3 class="card-title">بيانات الحساب</h3>
        <input type="hidden" id="coa_id" value="0">
        <div class="form-grid">
            <div>
                <label for="coa_code">كود الشجرة</label>
                <input type="text" id="coa_code" maxlength="64">
            </div>
            <div>
                <label for="coa_name">اسم الحساب</label>
                <input type="text" id="coa_name">
            </div>
            <div style="grid-column:1/-1;">
                <label for="coa_parent">تحت حساب (أب)</label>
                <select id="coa_parent">
                    <option value="">— جذر (بدون أب) —</option>
                    <?php
                    usort($flat, static function ($a, $b) use ($depths): int {
                        $da = $depths[(int) $a['id']] ?? 0;
                        $db = $depths[(int) $b['id']] ?? 0;
                        if ($da !== $db) {
                            return $da <=> $db;
                        }

                        return ((int) $a['id']) <=> ((int) $b['id']);
                    });
                    foreach ($flat as $r) {
                        $d = $depths[(int) $r['id']] ?? 0;
                        $pad = str_repeat('— ', $d);
                        $cid = (int) $r['id'];
                        $cc = htmlspecialchars((string) ($r['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $nm = htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8');
                        echo '<option value="' . $cid . '">' . $pad . $cc . ' — ' . $nm . '</option>';
                    }
                    ?>
                </select>
            </div>
            <?php if ($hasClass): ?>
            <div style="grid-column:1/-1;">
                <label for="coa_class">التصنيف المحاسبي</label>
                <select id="coa_class">
                    <?php foreach ($classLabels as $k => $lab): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-check" style="grid-column:1/-1;">
                <label><input type="checkbox" id="coa_is_group"> حساب رئيسي (مجموعة)</label>
            </div>
        </div>
        <div class="actions" style="margin-top:12px; flex-wrap:wrap; gap:8px;">
            <button type="button" id="coa_btn_suggest" class="btn-secondary">اقتراح كود</button>
            <button type="button" id="coa_btn_save">حفظ</button>
        </div>
        <p class="card-hint">عند الحفظ بدون كود، يُولَّد كود تلقائياً تحت الأب المختار.</p>
    </div>
</div>

<script>
(function () {
    var treeEl = document.getElementById('coa_tree_root');
    function fillForm(li) {
        if (!li || !li.dataset) return;
        document.getElementById('coa_id').value = li.dataset.id || '0';
        document.getElementById('coa_code').value = li.dataset.code || '';
        document.getElementById('coa_name').value = li.dataset.name || '';
        document.getElementById('coa_is_group').checked = li.dataset.isGroup === '1';
        var p = parseInt(li.dataset.parent, 10) || 0;
        var sel = document.getElementById('coa_parent');
        sel.value = p > 0 ? String(p) : '';
        var cl = document.getElementById('coa_class');
        if (cl && li.dataset.class) cl.value = li.dataset.class;
    }
    function bindTreeClicks(root) {
        root.querySelectorAll('.coa-tree-node').forEach(function (li) {
            var label = li.querySelector('.coa-tree-label');
            if (!label) return;
            label.addEventListener('click', function (e) {
                e.stopPropagation();
                root.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
                li.classList.add('is-active');
                fillForm(li);
            });
        });
    }
    bindTreeClicks(treeEl);
    document.getElementById('coa_btn_new').addEventListener('click', function () {
        document.getElementById('coa_id').value = '0';
        document.getElementById('coa_code').value = '';
        document.getElementById('coa_name').value = '';
        document.getElementById('coa_parent').value = '';
        document.getElementById('coa_is_group').checked = false;
        var cl = document.getElementById('coa_class');
        if (cl) cl.value = 'unclassified';
        treeEl.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
    });
    document.getElementById('coa_btn_suggest').addEventListener('click', function () {
        var p = document.getElementById('coa_parent').value.trim();
        var parentId = p === '' ? null : parseInt(p, 10);
        postJSON('/admin/api/accounts/suggest-code.php', { parent_id: parentId }).then(function (r) {
            if (!r.success) { alert(r.message || 'فشل'); return; }
            document.getElementById('coa_code').value = r.suggested_code || '';
        }).catch(function (e) { alert(e.message || String(e)); });
    });
    document.getElementById('coa_btn_save').addEventListener('click', function () {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var p = document.getElementById('coa_parent').value.trim();
        var payload = {
            id: id,
            name: document.getElementById('coa_name').value.trim(),
            code: document.getElementById('coa_code').value.trim(),
            parent_id: p === '' ? null : parseInt(p, 10),
            is_group: document.getElementById('coa_is_group').checked,
            account_class: document.getElementById('coa_class') ? document.getElementById('coa_class').value : 'unclassified'
        };
        if (!payload.name) { alert('اسم الحساب مطلوب'); return; }
        postJSON('/admin/api/accounts/save-node.php', payload).then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        }).catch(function (e) { alert(e.message || String(e)); });
    });
    var first = treeEl.querySelector('.coa-tree-node');
    if (first) {
        first.classList.add('is-active');
        fillForm(first);
    }
})();
</script>

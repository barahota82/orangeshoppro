<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/account_tree.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$expenseList = [];
$hasExpenseAccCol = false;
$hasNotesCol = false;
$expensePickAccounts = [];
if (orange_table_exists($pdo, 'accounts')) {
    $lw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $accRows = $pdo->query(
        'SELECT a.id, a.code, a.name FROM accounts a WHERE ' . $lw . ' ORDER BY COALESCE(a.code, \'\'), a.name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($accRows as $a) {
        $aid = (int) $a['id'];
        if (orange_accounts_account_pl_role($pdo, $aid) === 'expense') {
            $expensePickAccounts[] = $a;
        }
    }
}
$expenseAccountLabel = static function (int $id) use ($expensePickAccounts): string {
    foreach ($expensePickAccounts as $a) {
        if ((int) $a['id'] === $id) {
            return (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . ($a['name'] ?? '');
        }
    }

    return '#' . $id;
};
if (orange_table_exists($pdo, 'expenses')) {
    $hasExpenseAccCol = orange_table_has_column($pdo, 'expenses', 'expense_account_id');
    $hasNotesCol = orange_table_has_column($pdo, 'expenses', 'notes');
    $expenseList = $pdo->query('SELECT * FROM expenses ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
}
$glHint = storefront_public_path('/admin/index.php?page=gl_account_settings');
?>
<div class="page-title page-title--stacked">
    <h1>المصروفات</h1>
    <p class="page-subtitle">القيد: مدين <strong>حساب مصروف من الدليل</strong> (يُفضَّل اختيار بند من جذر المصروفات) أو ترك الافتراضي <strong>مصروف عام</strong> من
        <a href="<?php echo htmlspecialchars($glHint, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>
        — دائن <strong>الخزينة</strong>. يُرحَّل عبر الطابور أو مباشرة حسب إعداد النظام.</p>
</div>

<div class="card">
    <h3 class="card-title">آخر المصروفات</h3>
    <?php if ($expenseList === []): ?>
        <p class="card-hint">لا توجد مصروفات مسجّلة بعد.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>البيان</th>
                    <th>المبلغ (KD)</th>
                    <?php if ($hasNotesCol): ?><th>ملاحظات</th><?php endif; ?>
                    <?php if ($hasExpenseAccCol): ?><th>حساب مصروف</th><?php endif; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenseList as $ex): ?>
                <tr>
                    <td><?php echo (int)$ex['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)($ex['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float)($ex['amount'] ?? 0), 3); ?></td>
                    <?php if ($hasNotesCol): ?>
                    <td><?php echo htmlspecialchars((string)($ex['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endif; ?>
                    <?php if ($hasExpenseAccCol): ?>
                    <td><?php
                        $ea = (int) ($ex['expense_account_id'] ?? 0);
                        echo $ea > 0 ? htmlspecialchars($expenseAccountLabel($ea), ENT_QUOTES, 'UTF-8') : '<span class="muted">مصروف عام</span>';
                    ?></td>
                    <?php endif; ?>
                    <td class="actions">
                        <button type="button" class="btn-secondary" onclick="expStartEdit(<?php echo (int)$ex['id']; ?>)">تعديل</button>
                        <button type="button" class="btn-danger" onclick="expDelete(<?php echo (int)$ex['id']; ?>)">حذف</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title" id="exp_form_title">تسجيل مصروف جديد</h3>
    <div class="form-grid" style="max-width:640px;">
        <div>
            <label for="exp_name">البيان</label>
            <input id="exp_name" type="text" placeholder="مثال: إيجار، كهرباء، قرطاسية">
        </div>
        <div>
            <label for="exp_amount">المبلغ</label>
            <input id="exp_amount" type="number" class="admin-inp-money" step="any" min="0" placeholder="0" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <div>
            <label for="exp_account_id">حساب المصروف في الدليل</label>
            <select id="exp_account_id">
                <option value="">— افتراضي: مصروف عام من الإعدادات —</option>
                <?php foreach ($expensePickAccounts as $a): ?>
                    <?php
                    $eid = (int) $a['id'];
                    $elab = (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . ($a['name'] ?? '');
                    ?>
                    <option value="<?php echo $eid; ?>"><?php echo htmlspecialchars($elab, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1/-1;">
            <label for="exp_notes">ملاحظات</label>
            <input id="exp_notes" type="text" placeholder="اختياري">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" class="btn" id="exp_btn_save" onclick="expSave()">حفظ</button>
        <button type="button" class="btn-secondary" id="exp_btn_cancel" onclick="expCancelEdit()" style="display:none;">إلغاء التعديل</button>
    </div>
</div>

<script>
(function () {
    var editId = 0;
    var rows = <?php echo json_encode($expenseList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function rowById(id) {
        for (var i = 0; i < rows.length; i++) {
            if (parseInt(rows[i].id, 10) === id) {
                return rows[i];
            }
        }
        return null;
    }

    window.expStartEdit = function (id) {
        var r = rowById(id);
        if (!r) {
            alert('السجل غير موجود في القائمة المعروضة — حدّث الصفحة');
            return;
        }
        editId = id;
        document.getElementById('exp_form_title').textContent = 'تعديل مصروف #' + id;
        document.getElementById('exp_name').value = r.name || '';
        document.getElementById('exp_amount').value = String(r.amount != null ? r.amount : '');
        document.getElementById('exp_notes').value = r.notes || '';
        var accEl = document.getElementById('exp_account_id');
        if (accEl) {
            accEl.value = r.expense_account_id ? String(r.expense_account_id) : '';
            accEl.disabled = true;
        }
        document.getElementById('exp_btn_cancel').style.display = '';
        document.getElementById('exp_btn_save').textContent = 'تحديث';
        window.scrollTo(0, document.getElementById('exp_form_title').offsetTop - 20);
    };

    window.expCancelEdit = function () {
        editId = 0;
        document.getElementById('exp_form_title').textContent = 'تسجيل مصروف جديد';
        document.getElementById('exp_name').value = '';
        document.getElementById('exp_amount').value = '';
        document.getElementById('exp_notes').value = '';
        var accEl2 = document.getElementById('exp_account_id');
        if (accEl2) {
            accEl2.value = '';
            accEl2.disabled = false;
        }
        document.getElementById('exp_btn_cancel').style.display = 'none';
        document.getElementById('exp_btn_save').textContent = 'حفظ';
    };

    window.expDelete = function (id) {
        if (!confirm('حذف هذا المصروف وعكس أثره المحاسبي (معلّق أو حسب النظام)؟')) {
            return;
        }
        postJSON('/admin/api/expenses/update.php', { id: id, action: 'delete' })
            .then(function (r) {
                if (r.success) {
                    location.reload();
                    return;
                }
                if (!orangeAdminOfferSuggestOnFailure(r, 'فشل الحذف')) {
                    alert(r.message || 'فشل');
                }
            })
            .catch(function (e) { alert(e.message || String(e)); });
    };

    window.expSave = function () {
        var name = document.getElementById('exp_name').value.trim();
        var amount = parseFloat(String(document.getElementById('exp_amount').value || '0').replace(',', '.'));
        var notes = document.getElementById('exp_notes').value.trim();
        var accEl3 = document.getElementById('exp_account_id');
        var accRaw = accEl3 ? parseInt(String(accEl3.value || '0'), 10) : 0;
        if (!name || amount <= 0) {
            alert('أدخل البيان والمبلغ بشكل صحيح');
            return;
        }
        var payload = { name: name, amount: amount, notes: notes };
        if (accEl3 && !accEl3.disabled && accRaw > 0) {
            payload.expense_account_id = accRaw;
        }
        var url = editId ? '/admin/api/expenses/update.php' : '/admin/api/expenses/create.php';
        if (editId) {
            payload.id = editId;
        }
        postJSON(url, payload)
            .then(function (r) {
                if (r.success) {
                    location.reload();
                    return;
                }
                if (!orangeAdminOfferSuggestOnFailure(r, 'فشل الحفظ')) {
                    alert(r.message || 'فشل');
                }
            })
            .catch(function (e) { alert(e.message || String(e)); });
    };
})();
</script>

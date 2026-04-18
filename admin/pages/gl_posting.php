<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$linkedJournalTypes = orange_gl_posting_linked_journal_types($pdo);
$todayEnd = date('Y-m-d\T23:59');
$monthStartDt = date('Y-m-01\T00:00');
?>
<div class="gl-posting-page" dir="rtl">
    <header class="gl-posting-appbar">
        <span class="gl-posting-appbar__title">ترحيل الحركات</span>
    </header>

    <p class="gl-posting-scope-note">
        <strong>نوع الحركة:</strong> القائمة تعرض فقط أنواع اليومية المربوطة (حساب + نوع يومية) من
        <a href="/admin/index.php?page=gl_account_settings">حسابات القيود التلقائية</a>.
        أعمدة القيود مطابقة لسندات النظام (مدين / دائن / بيان) وليس نموذج عملات متعددة.
    </p>

    <div class="gl-posting-workbench">
        <!-- في RTL العمود الأول يظهر يمين الشاشة: مصدر الحركات -->
        <section class="gl-posting-pane gl-posting-pane--source" aria-labelledby="gl_post_movements_table_title">
            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--filters">
                <div class="gl-posting-field">
                    <span class="gl-posting-field__label" id="gl_post_movement_type_label">نوع الحركة :</span>
                    <div class="gl-posting-field__row">
                        <select id="gl_post_movement_type" class="gl-posting-select" aria-labelledby="gl_post_movement_type_label"<?php echo $linkedJournalTypes === [] ? ' disabled' : ''; ?>>
                            <option value="">— اختر نوع اليومية —</option>
                            <?php foreach ($linkedJournalTypes as $jt):
                                $jid = (int) ($jt['id'] ?? 0);
                                $jname = trim((string) ($jt['name_ar'] ?? ''));
                                ?>
                            <option value="<?php echo $jid; ?>"><?php echo htmlspecialchars($jname, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="gl-posting-check-label">
                            <input type="checkbox" id="gl_post_all_movements" class="gl-posting-chk">
                            جميع الحركات
                        </label>
                    </div>
                </div>
                <div class="gl-posting-dates">
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="gl_post_date_from">تاريخ الحركة من</label>
                        <input type="datetime-local" id="gl_post_date_from" class="gl-posting-inp-datetime" value="<?php echo htmlspecialchars($monthStartDt, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="gl_post_date_to">إلى تاريخ</label>
                        <input type="datetime-local" id="gl_post_date_to" class="gl-posting-inp-datetime" value="<?php echo htmlspecialchars($todayEnd, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            </div>

            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--actions">
                <button type="button" class="btn-secondary" id="gl_post_btn_unposted">إسترجاع الحركات الغير مرحلة</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_posted">إسترجاع الحركات المرحلة</button>
            </div>

            <h2 id="gl_post_movements_table_title" class="gl-posting-subcap">حركات غير مرحلة</h2>
            <div class="gl-posting-table-frame gl-posting-table-frame--scroll">
                <table class="gl-posting-gridtable">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-chk" aria-label="اختيار"></th>
                            <th>رقم الحركة</th>
                            <th>الفرع / العميل / المورد</th>
                            <th>القيمة</th>
                            <th>تاريخ الحركة</th>
                        </tr>
                    </thead>
                    <tbody id="gl_post_movements_tbody">
                        <tr>
                            <td colspan="5" class="gl-posting-empty-cell">لا توجد حركات — اضغط «إسترجاع…» عند تفعيل الربط الخلفي.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="gl-posting-pane__footer">
                <button type="button" class="btn" id="gl_post_btn_do_post">ترحيل</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_undo">إلغاء الترحيل</button>
            </div>
        </section>

        <section class="gl-posting-pane gl-posting-pane--ledger" aria-labelledby="gl_post_entries_cap">
            <h2 id="gl_post_entries_cap" class="gl-posting-subcap gl-posting-subcap--ledger">قيود الترحيل</h2>
            <div class="gl-posting-table-frame gl-posting-table-frame--grow">
                <table class="gl-posting-gridtable gl-posting-gridtable--ledger">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-num">م</th>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين</th>
                            <th>دائن</th>
                            <th>البيان</th>
                        </tr>
                    </thead>
                    <tbody id="gl_post_entries_tbody">
                        <tr class="gl-posting-placeholder-row">
                            <td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">هنا تظهر القيود بعد ان تم ترحيلها</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('gl_post_movement_type');
    var chkAll = document.getElementById('gl_post_all_movements');
    if (chkAll && sel) {
        function syncAllMovements() {
            sel.disabled = chkAll.checked;
            if (chkAll.checked) {
                sel.value = '';
            }
        }
        chkAll.addEventListener('change', syncAllMovements);
        syncAllMovements();
    }
    function stub(msg) {
        window.alert(msg);
    }
    var stubMsg = 'هذه الخطوة تتطلب ربط مصدر الحركات (مخزن حركات غير مرحّلة) — قيد التطوير.';
    var btnUn = document.getElementById('gl_post_btn_unposted');
    var btnPo = document.getElementById('gl_post_btn_posted');
    var btnDo = document.getElementById('gl_post_btn_do_post');
    var btnUndo = document.getElementById('gl_post_btn_undo');
    if (btnUn) {
        btnUn.addEventListener('click', function () { stub(stubMsg); });
    }
    if (btnPo) {
        btnPo.addEventListener('click', function () { stub(stubMsg); });
    }
    if (btnDo) {
        btnDo.addEventListener('click', function () { stub(stubMsg); });
    }
    if (btnUndo) {
        btnUndo.addEventListener('click', function () { stub(stubMsg); });
    }
})();
</script>

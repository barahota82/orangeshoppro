<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$linkedJournalTypes = orange_gl_posting_linked_journal_types($pdo);
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
?>
<div class="gl-posting-page" dir="rtl">
    <h1 class="gl-posting-page__title">ترحيل الحركات</h1>
    <p class="muted gl-posting-page__hint">
        <strong>نوع الحركة:</strong> تظهر هنا فقط أنواع اليومية التي تم ربطها (حساب + نوع يومية) من
        <a href="/admin/index.php?page=gl_account_settings">حسابات القيود التلقائية</a>.
        استرجاع الحركات والترحيل الفعلي يُربط لاحقًا بمصدر الحركات في النظام.
    </p>

    <div class="gl-posting-grid">
        <section class="card gl-posting-panel gl-posting-panel--movements" aria-labelledby="gl_post_movements_title">
            <h2 id="gl_post_movements_title" class="gl-posting-panel__title">الحركات</h2>

            <div class="gl-posting-filters">
                <div class="gl-posting-filter-row">
                    <label class="gl-posting-label" for="gl_post_movement_type">نوع الحركة</label>
                    <div class="gl-posting-filter-inline">
                        <select id="gl_post_movement_type" class="gl-posting-select"<?php echo $linkedJournalTypes === [] ? ' disabled' : ''; ?>>
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
                <div class="gl-posting-filter-row gl-posting-filter-row--dates">
                    <div>
                        <label class="gl-posting-label" for="gl_post_date_from">تاريخ الحركة من</label>
                        <input type="date" id="gl_post_date_from" class="gl-posting-inp-date" value="<?php echo htmlspecialchars($monthStart, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="gl-posting-label" for="gl_post_date_to">إلى تاريخ</label>
                        <input type="date" id="gl_post_date_to" class="gl-posting-inp-date" value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            </div>

            <div class="gl-posting-toolbar">
                <button type="button" class="btn-secondary" id="gl_post_btn_unposted">استرجاع الحركات غير المرحّلة</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_posted">استرجاع الحركات المرحّلة</button>
            </div>

            <div class="table-wrap gl-posting-table-wrap">
                <table class="gl-posting-table">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-chk" aria-label="اختيار"></th>
                            <th>رقم الحركة</th>
                            <th>الفرع / العميل / المورد</th>
                            <th>القيمة</th>
                            <th>رقم المستند</th>
                            <th>تاريخ الحركة</th>
                        </tr>
                    </thead>
                    <tbody id="gl_post_movements_tbody">
                        <tr>
                            <td colspan="6" class="gl-posting-empty-cell">لا توجد حركات — اضغط «استرجاع…» عند تفعيل الربط الخلفي.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="gl-posting-toolbar gl-posting-toolbar--bottom">
                <button type="button" id="gl_post_btn_do_post">ترحيل</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_undo">إلغاء الترحيل</button>
            </div>
        </section>

        <section class="card gl-posting-panel gl-posting-panel--entries" aria-labelledby="gl_post_entries_title">
            <h2 id="gl_post_entries_title" class="gl-posting-panel__title">قيود الترحيل</h2>
            <p class="muted gl-posting-entries-hint">هنا تظهر بنود القيود بعد تنفيذ الترحيل (معاينة).</p>
            <div class="table-wrap gl-posting-table-wrap">
                <table class="gl-posting-table gl-posting-table--entries">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-num">م</th>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين أجنبي</th>
                            <th>دائن أجنبي</th>
                            <th>العملة</th>
                            <th>معامل التحويل</th>
                            <th>مدين محلي</th>
                            <th>دائن محلي</th>
                        </tr>
                    </thead>
                    <tbody id="gl_post_entries_tbody">
                        <tr>
                            <td colspan="9" class="gl-posting-empty-cell">لم يُرحَّل بعد — ستظهر الأسطر هنا بعد الترحيل.</td>
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/report_line_master.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$coaCaps = orange_admin_caps($admin, $pdo, 'accounting');

$ctxCountryId = orange_admin_context_country_id($pdo);
$ctxCountryLabel = '';
if ($ctxCountryId > 0) {
    $ctxRow = orange_country_row_by_id($pdo, $ctxCountryId, false);
    $ctxCountryLabel = trim((string) ($ctxRow['name_ar'] ?? ''));
    if ($ctxCountryLabel === '' && $ctxRow !== null) {
        $ctxCountryLabel = trim((string) ($ctxRow['name_en'] ?? ''));
    }
}

$flat = orange_accounts_flat($pdo);
$tree = orange_accounts_build_tree($flat);
$depths = orange_accounts_depth_by_id($flat);
$hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
$hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
$hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');
$hasMap = orange_table_has_column($pdo, 'accounts', 'account_type');
$hasRli = orange_table_has_column($pdo, 'accounts', 'report_line_id');
$reportLineOpts = ($hasMap && $hasRli) ? orange_report_line_master_list_active($pdo) : [];

$fyList = orange_fiscal_years_list($pdo);
$fyDefault = $fyList !== [] ? (int) $fyList[0]['id'] : 0;

$firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;
?>
<div class="coa-shell" dir="rtl" data-fy-default="<?php echo (int) $fyDefault; ?>" data-admin-country-id="<?php echo (int) $ctxCountryId; ?>">
    <?php if ($ctxCountryId > 0 && $ctxCountryLabel !== ''): ?>
    <p class="admin-fy-shell__lead" style="margin:0 0 12px;">
        <strong>سياق الدولة الحالي:</strong> <?php echo htmlspecialchars($ctxCountryLabel, ENT_QUOTES, 'UTF-8'); ?>
        (معرّف <?php echo (int) $ctxCountryId; ?>)
        — يُعرض دليل هذه الدولة فقط (حسابات <?php echo (int) count($flat); ?>).
        <?php if ($flat !== []): ?>
        <span class="muted" style="font-size:0.85rem;"> — أول حساب #<?php echo (int) ($flat[0]['id'] ?? 0); ?></span>
        <?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="coa-shell__body" dir="ltr">
        <aside class="coa-shell__tree card coa-tree-card">
            <h3 class="card-title coa-tree-card__title">شجرة الحسابات</h3>
            <div class="coa-tree-search">
                <input type="search" id="coa_tree_search" class="coa-tree-search__input" placeholder="بحث في الشجرة…" autocomplete="off" dir="rtl">
            </div>
            <button type="button" class="btn-coa-guide" id="coa_btn_open_guide">اضافة الدليل المحاسبي</button>
            <div class="coa-tree-scroll" id="coa_tree_root" role="tree">
                <?php if ($tree === []): ?>
                    <p class="muted">
                        لا توجد حسابات في القاعدة. عند أول تشغيل على قاعدة فارغة يُنشأ النظام تلقائياً سبعة جذور (أصول، خصوم، حقوق ملكية، إيرادات، تكلفة مبيعات، مصروفات، حسابات نظامية خارج الميزانية) بترميز UTF-8.
                        إن بقيت الشجرة فارغة فتحقق من الاتصال بقاعدة البيانات أو افتح «اضافة الدليل المحاسبي» لإضافة الجذور يدوياً.
                    </p>
                <?php else: ?>
                    <?php orange_render_coa_tree($tree, $firstId, $flat, 0); ?>
                <?php endif; ?>
            </div>
        </aside>

        <div class="coa-shell__main" dir="rtl">
            <div class="coa-shell__panel">

            <div class="card coa-form-card coa-form-card--classic">
                <h3 class="card-title coa-form-card__title">بيانات الحساب</h3>
                <input type="hidden" id="coa_id" value="0">
                <input type="hidden" id="coa_parent_id" value="">

                <div class="coa-form-grid">
                    <div class="coa-field coa-field--code">
                        <label for="coa_code">كود الحساب</label>
                        <input type="text" id="coa_code" maxlength="64" class="coa-input-wide coa-input-readonly" readonly placeholder="يُولَّد تلقائياً عند الحفظ">
                    </div>
                    <div class="coa-field">
                        <label for="coa_parent_code">كود الحساب الأب</label>
                        <input type="text" id="coa_parent_code" readonly class="coa-input-readonly" tabindex="-1" placeholder="—">
                    </div>

                    <div class="coa-field coa-field--span2">
                        <label for="coa_name">اسم الحساب بالعربية</label>
                        <input type="text" id="coa_name" class="coa-input-wide" autocomplete="off">
                    </div>
                    <?php if ($hasNameEn): ?>
                    <div class="coa-field coa-field--span2">
                        <label for="coa_name_en">اسم الحساب بالإنجليزية</label>
                        <input type="text" id="coa_name_en" class="coa-input-wide" lang="en" dir="ltr" autocomplete="off">
                    </div>
                    <?php endif; ?>

                    <div class="coa-field">
                        <span class="coa-field__label">نوع الحساب</span>
                        <p class="coa-level-display" id="coa_type_display">—</p>
                    </div>
                    <div class="coa-field">
                        <span class="coa-field__label">فئة الحساب</span>
                        <p class="coa-level-display" id="coa_category_display">—</p>
                    </div>
                    <div class="coa-field coa-field--span2">
                        <span class="coa-field__label">مستوى الحساب</span>
                        <p class="coa-level-display" id="coa_level">—</p>
                    </div>

                    <?php if ($hasNb): ?>
                    <div class="coa-field coa-field--span2">
                        <label for="coa_normal_balance">طبيعة الحساب</label>
                        <select id="coa_normal_balance" title="للحسابات الفرعية فقط — الحساب الرئيسي (مجموعة) لا طبيعة محددة له">
                            <option value="">—</option>
                            <option value="debit">مدين</option>
                            <option value="credit">دائن</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasMap): ?>
                    <details class="coa-field coa-field--span2 coa-mapping-details" open>
                        <summary class="coa-mapping-details__sum">التصنيف المحاسبي (افتراضي من الأب — راجع قبل الحفظ)</summary>
                        <div class="coa-form-grid coa-form-grid--mapping">
                            <div class="coa-field">
                                <label for="coa_account_type">نوع الحساب (التقارير)</label>
                                <select id="coa_account_type" title="يُشتقّ من الحساب الأب إن لم تُختر قيمة">
                                    <option value="">— افتراضي من السيرفر / الأب</option>
                                    <option value="asset">أصول</option>
                                    <option value="liability">خصوم</option>
                                    <option value="equity">حقوق ملكية</option>
                                    <option value="revenue">إيرادات</option>
                                    <option value="cogs">تكلفة مبيعات</option>
                                    <option value="expense">مصروفات</option>
                                    <option value="other">أخرى</option>
                                </select>
                            </div>
                            <div class="coa-field">
                                <label for="coa_report_section">قسم التقرير</label>
                                <select id="coa_report_section">
                                    <option value="">— افتراضي من السيرفر / الأب</option>
                                    <option value="balance_sheet">الميزانية</option>
                                    <option value="trading">أرباح وخسائر — تداول</option>
                                    <option value="pnl">أرباح وخسائر — عام</option>
                                    <option value="cashflow">التدفقات النقدية</option>
                                    <option value="none">لا يُصنّف</option>
                                </select>
                            </div>
                            <?php if ($hasRli): ?>
                            <div class="coa-field coa-field--span2">
                                <label for="coa_report_line_id">سطر التقرير (مرجع معتمد)</label>
                                <select id="coa_report_line_id" title="يُشتقّ من الأب؛ التعديل فقط ضمن المرجع المعتمد">
                                    <option value="">— افتراضي من السيرفر / الأب</option>
                                    <?php foreach ($reportLineOpts as $rlrow): ?>
                                    <option value="<?php echo (int) ($rlrow['id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string) ($rlrow['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        (<?php echo htmlspecialchars((string) ($rlrow['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="coa-field">
                                <label for="coa_cashflow_section">التدفقات النقدية</label>
                                <select id="coa_cashflow_section">
                                    <option value="">— افتراضي من السيرفر / الأب</option>
                                    <option value="none">غير مؤثر</option>
                                    <option value="operating">تشغيلية</option>
                                    <option value="investing">استثمارية</option>
                                    <option value="financing">تمويلية</option>
                                </select>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>

                    <div class="coa-field coa-field--kind coa-field--span2">
                        <span class="coa-field__label">الحساب في القيود</span>
                        <div class="coa-radio-row">
                            <?php if ($hasSuspended): ?>
                            <label class="coa-radio"><input type="radio" name="coa_state" value="suspended"> موقوف</label>
                            <?php endif; ?>
                            <label class="coa-radio"><input type="radio" name="coa_state" value="group"> رئيسي</label>
                            <label class="coa-radio"><input type="radio" name="coa_state" value="leaf" checked> فرعي</label>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="coa-shell__footer">
                <button type="button" class="btn-secondary" id="coa_btn_new" data-orange-perm="edit" data-orange-resource="accounting">إضافة</button>
                <button type="button" class="btn-danger" id="coa_btn_delete" data-orange-perm="delete" data-orange-resource="accounting">حذف</button>
                <button type="button" id="coa_btn_save" data-orange-perm="edit" data-orange-resource="accounting">حفظ</button>
                <a class="btn-secondary coa-footer-link coa-footer-link--disabled" id="coa_btn_statement" href="#">كشف حساب</a>
                <button type="button" class="btn-secondary" id="coa_btn_print">طباعة</button>
            </footer>
            </div>
        </div>
    </div>
</div>

<div class="coa-setup-modal" id="coa_setup_modal" hidden aria-hidden="true">
    <div class="coa-setup-modal__backdrop" id="coa_setup_backdrop" role="presentation"></div>
    <div class="coa-setup-modal__dialog coa-setup-print-area" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="coa_setup_title">
        <div class="coa-setup-modal__head">
            <h2 class="coa-setup-modal__title" id="coa_setup_title">إعداد الدليل</h2>
        </div>
        <div class="coa-setup-modal__body">
            <div class="coa-setup-table-wrap">
                <table class="coa-setup-table">
                    <thead>
                        <tr>
                            <th class="coa-setup-table__del" aria-label="حذف"></th>
                            <th>الكود</th>
                            <th>الاسم — عربي</th>
                            <th>الاسم — إنجليزي</th>
                        </tr>
                    </thead>
                    <tbody id="coa_setup_tbody"></tbody>
                </table>
            </div>
            <div class="coa-setup-form coa-setup-form--compact">
                <input type="hidden" id="coa_setup_row_id" value="0">
                <div class="coa-setup-form__row coa-setup-form__row--inline coa-setup-form__row--code">
                    <label class="coa-setup-form__label-inline" for="coa_setup_code">الكود</label>
                    <input type="text" id="coa_setup_code" class="coa-setup-code-display" readonly tabindex="-1" dir="ltr" autocomplete="off" title="يُحدَّد تلقائياً عند الحفظ">
                    <span class="muted coa-setup-code-hint">تلقائي: جذر 1–2…؛ تحت الجذر 11–12…؛ ثم +رقمان (01) ثم +5 أرقام للمستوى الأخير</span>
                </div>
                <div class="coa-setup-form__row coa-setup-form__row--inline">
                    <label class="coa-setup-form__label-inline" for="coa_setup_name"><span class="coa-required">*</span> الاسم — عربي</label>
                    <input type="text" id="coa_setup_name" class="coa-setup-input-flex" autocomplete="off">
                </div>
                <div class="coa-setup-form__row coa-setup-form__row--inline">
                    <label class="coa-setup-form__label-inline" for="coa_setup_name_en">الاسم — إنجليزي</label>
                    <input type="text" id="coa_setup_name_en" class="coa-setup-input-flex" lang="en" dir="ltr" autocomplete="off">
                </div>
            </div>
        </div>
        <footer class="coa-setup-modal__footer">
            <button type="button" class="btn-secondary" id="coa_setup_btn_new">إضافة</button>
            <button type="button" id="coa_setup_btn_save" data-orange-perm="edit" data-orange-resource="accounting">حفظ</button>
            <button type="button" class="btn-secondary" id="coa_setup_btn_print">طباعة</button>
            <button type="button" class="btn-secondary" id="coa_setup_btn_close">إغلاق</button>
        </footer>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var treeEl = document.getElementById('coa_tree_root');

    function coaAdminCountryQuery() {
        var m = document.querySelector('meta[name="orange-admin-country"]');
        var c = m ? String(m.getAttribute('content') || '').trim() : '';
        return c ? ('?admin_country=' + encodeURIComponent(c)) : '';
    }
    var hasNameEn = <?php echo $hasNameEn ? 'true' : 'false'; ?>;
    var hasSuspended = <?php echo $hasSuspended ? 'true' : 'false'; ?>;
    var hasNb = <?php echo $hasNb ? 'true' : 'false'; ?>;
    var hasMap = <?php echo $hasMap ? 'true' : 'false'; ?>;
    var hasRli = <?php echo $hasRli ? 'true' : 'false'; ?>;
    var __orangeAdminPub = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '') : '';
    var coaMainSaveInFlight = false;
    var coaSetupSaveInFlight = false;
    var COA_CAPS = <?php echo json_encode($coaCaps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

    var levelOrds = ['', 'الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر'];

    /** ملء حقول التصنيف من عُقدة الشجرة (الحساب نفسه أو الأب عند «إضافة»). */
    function coaApplyDatasetToMappingFields(li) {
        if (!hasMap) {
            return;
        }
        var atSel = document.getElementById('coa_account_type');
        var rsSel = document.getElementById('coa_report_section');
        var rlSel = hasRli ? document.getElementById('coa_report_line_id') : null;
        var cfSel = document.getElementById('coa_cashflow_section');
        if (!atSel || !rsSel || !cfSel) {
            return;
        }
        if (hasRli && !rlSel) {
            return;
        }
        var at = '';
        var rs = '';
        var rli = '';
        var cf = '';
        if (li && li.dataset) {
            at = String(li.dataset.accountType || '').trim().toLowerCase();
            rs = String(li.dataset.reportSection || '').trim().toLowerCase();
            rli = String(li.dataset.reportLineId || '').trim();
            cf = String(li.dataset.cashflowSection || '').trim().toLowerCase();
        }
        function optOk(sel, v) {
            if (!v) {
                sel.value = '';

                return;
            }
            var ok = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === v) {
                    ok = true;
                    break;
                }
            }
            sel.value = ok ? v : '';
        }
        optOk(atSel, at);
        optOk(rsSel, rs);
        if (hasRli && rlSel) {
            optOk(rlSel, rli);
        }
        optOk(cfSel, cf);
    }

    function coaHumanLevel(depthStr) {
        var d = parseInt(depthStr, 10);
        if (isNaN(d) || d < 0) {
            return '—';
        }
        var h = d + 1;
        if (h > 0 && h < levelOrds.length) {
            return 'المستوى ' + levelOrds[h];
        }
        return 'المستوى ' + h;
    }

    function setStateRadios(suspended, isGroup) {
        var v = 'leaf';
        if (hasSuspended && suspended) {
            v = 'suspended';
        } else if (isGroup) {
            v = 'group';
        }
        var r = document.querySelector('input[name="coa_state"][value="' + v + '"]');
        if (r) {
            r.checked = true;
        } else {
            var leaf = document.querySelector('input[name="coa_state"][value="leaf"]');
            if (leaf) {
                leaf.checked = true;
            }
        }
    }

    /** طبيعة الحساب تُحدَّد للفرعي فقط؛ الرئيسي (مجموعة) الحقل فارغ وغير مفعّل. */
    function coaSyncNormalBalanceUi() {
        if (!hasNb) {
            return;
        }
        var sel = document.getElementById('coa_normal_balance');
        if (!sel) {
            return;
        }
        var stEl = document.querySelector('input[name="coa_state"]:checked');
        var st = stEl ? stEl.value : 'leaf';
        var isGroup = st === 'group';
        if (isGroup) {
            sel.value = '';
            sel.disabled = true;
        } else {
            sel.disabled = false;
            if (!sel.value) {
                sel.value = 'debit';
            }
        }
    }

    function updateParentFieldsFromContext() {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var pidEl = document.getElementById('coa_parent_id');
        var pc = document.getElementById('coa_parent_code');
        if (!pc || !pidEl) {
            return;
        }
        if (id > 0) {
            var li = treeEl.querySelector('.coa-tree-node.is-active');
            if (li) {
                pidEl.value = li.dataset.parent && parseInt(li.dataset.parent, 10) > 0 ? li.dataset.parent : '';
                pc.value = li.dataset.parentCode || '';
                if (!pc.value) {
                    pc.placeholder = '—';
                }
            }
            return;
        }
        var p = pidEl.value.trim();
        if (!p) {
            pc.value = '';
            pc.placeholder = '—';
            return;
        }
        var anchor = treeEl.querySelector('.coa-tree-node[data-id="' + p + '"]');
        pc.value = anchor ? (anchor.dataset.code || '') : '';
    }

    function updateStatementLink() {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var a = document.getElementById('coa_btn_statement');
        var shell = document.querySelector('.coa-shell');
        var fy = shell ? (shell.getAttribute('data-fy-default') || '0') : '0';
        if (!a) {
            return;
        }
        if (id <= 0 || parseInt(fy, 10) <= 0) {
            a.href = '#';
            a.classList.add('coa-footer-link--disabled');
            return;
        }
        a.href = __orangeAdminPub + '/admin/index.php?page=financial_report&fy=' + encodeURIComponent(fy) + '&account=' + id;
        a.classList.remove('coa-footer-link--disabled');
    }

    function getAnchorForNewPreview() {
        var p = document.getElementById('coa_parent_id').value.trim();
        if (!p) {
            return null;
        }
        return treeEl.querySelector('.coa-tree-node[data-id="' + p + '"]');
    }

    function updatePreviewFromParent() {
        var fid = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var rootEl = document.getElementById('coa_type_display');
        var catEl = document.getElementById('coa_category_display');
        var nameInp = document.getElementById('coa_name');
        if (fid > 0) {
            return;
        }
        var anchor = getAnchorForNewPreview();
        if (!anchor) {
            rootEl.textContent = '—';
            catEl.textContent = '—';
            return;
        }
        var depth = parseInt(anchor.dataset.depth, 10);
        if (isNaN(depth)) {
            depth = 0;
        }
        rootEl.textContent = anchor.dataset.rootName || '—';
        if (depth === 0) {
            catEl.textContent = (nameInp.value || '').trim() || '—';
        } else {
            catEl.textContent = anchor.dataset.categoryName || '—';
        }
    }

    function fillForm(li) {
        if (!li || !li.dataset) {
            return;
        }
        document.getElementById('coa_id').value = li.dataset.id || '0';
        document.getElementById('coa_code').value = li.dataset.code || '';
        document.getElementById('coa_name').value = li.dataset.name || '';
        if (hasNameEn) {
            document.getElementById('coa_name_en').value = li.dataset.nameEn || '';
        }
        setStateRadios(li.dataset.suspended === '1', li.dataset.isGroup === '1');
        var p = parseInt(li.dataset.parent, 10) || 0;
        document.getElementById('coa_parent_id').value = p > 0 ? String(p) : '';
        var lev = document.getElementById('coa_level');
        if (lev) {
            lev.textContent = coaHumanLevel(li.dataset.depth);
        }
        var tDisp = document.getElementById('coa_type_display');
        var cDisp = document.getElementById('coa_category_display');
        if (tDisp) {
            tDisp.textContent = li.dataset.rootName || '—';
        }
        if (cDisp) {
            cDisp.textContent = li.dataset.categoryName || '—';
        }
        if (hasNb) {
            var nbSel = document.getElementById('coa_normal_balance');
            if (li.dataset.isGroup === '1') {
                nbSel.value = '';
            } else {
                var nb = li.dataset.normalBalance || 'debit';
                nbSel.value = nb === 'credit' ? 'credit' : 'debit';
            }
        }
        coaApplyDatasetToMappingFields(li);
        coaSyncNormalBalanceUi();
        updateParentFieldsFromContext();
        updateStatementLink();
    }

    function bindTreeClicks(root) {
        root.querySelectorAll('.coa-tree-node').forEach(function (li) {
            var label = li.querySelector('.coa-tree-row .coa-tree-label');
            if (!label) {
                return;
            }
            label.addEventListener('click', function (e) {
                e.stopPropagation();
                root.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
                li.classList.add('is-active');
                fillForm(li);
            });
        });
    }

    function bindTreeToggles(root) {
        root.querySelectorAll('.coa-tree-toggle:not(.coa-tree-toggle--leaf)').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var li = btn.closest('.coa-tree-node');
                if (!li || !li.classList.contains('coa-tree-node--has-children')) {
                    return;
                }
                var collapsed = li.classList.toggle('coa-tree-node--collapsed');
                btn.textContent = collapsed ? '+' : '\u2212';
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                li.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        });
    }

    function coaExpandAncestorsForVisible() {
        treeEl.querySelectorAll('.coa-tree-node').forEach(function (li) {
            if (li.style.display === 'none') {
                return;
            }
            var el = li.parentElement;
            while (el && el !== treeEl) {
                if (el.classList && el.classList.contains('coa-tree-list')) {
                    var parentLi = el.parentElement;
                    if (parentLi && parentLi.classList.contains('coa-tree-node')) {
                        parentLi.classList.remove('coa-tree-node--collapsed');
                        parentLi.setAttribute('aria-expanded', 'true');
                        var tb = parentLi.querySelector(':scope > .coa-tree-row > .coa-tree-toggle:not(.coa-tree-toggle--leaf)');
                        if (tb) {
                            tb.textContent = '\u2212';
                            tb.setAttribute('aria-expanded', 'true');
                        }
                    }
                    el = parentLi ? parentLi.parentElement : null;
                } else {
                    el = el.parentElement;
                }
            }
        });
    }

    /**
     * تمرير لوحة الشجرة دون طي/فتح: إن كان الحساب داخل فرع مطوي يُمرَّر إلى صف أقرب أب مطوي (يبقى الفرع مغلقاً).
     */
    function coaScrollTreeWithoutExpanding(targetLi) {
        if (!targetLi) {
            return;
        }
        var scrollTarget = targetLi;
        var node = targetLi;
        while (node && node !== treeEl) {
            var par = node.parentElement;
            if (par && par.classList.contains('coa-tree-list')) {
                var parentLi = par.parentElement;
                if (parentLi && parentLi.classList.contains('coa-tree-node') && parentLi.classList.contains('coa-tree-node--collapsed')) {
                    scrollTarget = parentLi;
                }
                node = parentLi;
            } else {
                node = node.parentElement;
            }
        }
        try {
            scrollTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) {
            scrollTarget.scrollIntoView(false);
        }
    }

    function getActiveCoaTreeId() {
        var a = treeEl.querySelector('.coa-tree-node.is-active');
        return a ? parseInt(a.dataset.id, 10) || 0 : 0;
    }

    /**
     * @param {number} [optFocusId] إن وُجد يُحدَّد هذا الحساب بعد التحديث (مثلاً id من استجابة الحفظ).
     */
    function refreshCoaMainTree(optFocusId) {
        var keepId = typeof optFocusId === 'number' && optFocusId > 0 ? optFocusId : getActiveCoaTreeId();
        return fetch('/admin/api/accounts/tree-html.php' + coaAdminCountryQuery(), {
            credentials: 'same-origin',
            headers: typeof orangeAdminCountryHeaders === 'function' ? orangeAdminCountryHeaders() : {}
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    return Promise.reject(new Error(data.message || 'تعذر تحديث الشجرة'));
                }
                treeEl.innerHTML = data.html || '';
                bindTreeClicks(treeEl);
                bindTreeToggles(treeEl);
                var q = document.getElementById('coa_tree_search').value;
                applyCoaTreeFilter(q);
                var pick = keepId > 0 ? treeEl.querySelector('.coa-tree-node[data-id="' + keepId + '"]') : null;
                if (!pick && keepId > 0) {
                    var searchInp = document.getElementById('coa_tree_search');
                    if (searchInp && String(searchInp.value || '').trim() !== '') {
                        searchInp.value = '';
                        applyCoaTreeFilter('');
                        pick = treeEl.querySelector('.coa-tree-node[data-id="' + keepId + '"]');
                    }
                }
                if (!pick) {
                    pick = treeEl.querySelector('.coa-tree-node');
                }
                if (pick) {
                    treeEl.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
                    pick.classList.add('is-active');
                    coaScrollTreeWithoutExpanding(pick);
                    fillForm(pick);
                } else {
                    document.getElementById('coa_id').value = '0';
                    document.getElementById('coa_code').value = '';
                    document.getElementById('coa_name').value = '';
                    if (hasNameEn) {
                        document.getElementById('coa_name_en').value = '';
                    }
                    setStateRadios(false, false);
                    document.getElementById('coa_parent_id').value = '';
                    document.getElementById('coa_parent_code').value = '';
                    var lev = document.getElementById('coa_level');
                    if (lev) {
                        lev.textContent = '—';
                    }
                    document.getElementById('coa_type_display').textContent = '—';
                    document.getElementById('coa_category_display').textContent = '—';
                    if (hasNb) {
                        document.getElementById('coa_normal_balance').value = 'debit';
                    }
                    coaApplyDatasetToMappingFields(null);
                    coaSyncNormalBalanceUi();
                    updateParentFieldsFromContext();
                    updatePreviewFromParent();
                    updateStatementLink();
                }
            });
    }

    function liHasMatchingDescendant(li, q) {
        var lab = li.querySelector(':scope > .coa-tree-row .coa-tree-label');
        if (lab && lab.textContent.toLowerCase().indexOf(q) >= 0) {
            return true;
        }
        var ul = li.querySelector(':scope > .coa-tree-list');
        if (!ul) {
            return false;
        }
        var children = ul.querySelectorAll(':scope > .coa-tree-node');
        for (var i = 0; i < children.length; i++) {
            if (liHasMatchingDescendant(children[i], q)) {
                return true;
            }
        }
        return false;
    }

    function applyCoaTreeFilter(raw) {
        var q = (raw || '').trim().toLowerCase();
        var nodes = treeEl.querySelectorAll('.coa-tree-node');
        if (!q) {
            nodes.forEach(function (li) { li.style.display = ''; });
            return;
        }
        nodes.forEach(function (li) {
            li.style.display = liHasMatchingDescendant(li, q) ? '' : 'none';
        });
        coaExpandAncestorsForVisible();
    }

    document.getElementById('coa_name').addEventListener('input', function () {
        if (parseInt(document.getElementById('coa_id').value, 10) <= 0) {
            updatePreviewFromParent();
        }
    });

    bindTreeClicks(treeEl);
    bindTreeToggles(treeEl);

    document.getElementById('coa_tree_search').addEventListener('input', function () {
        applyCoaTreeFilter(this.value);
    });

    document.getElementById('coa_btn_new').addEventListener('click', function () {
        var pick = treeEl.querySelector('.coa-tree-node.is-active');
        document.getElementById('coa_id').value = '0';
        document.getElementById('coa_code').value = '';
        document.getElementById('coa_name').value = '';
        if (hasNameEn) {
            document.getElementById('coa_name_en').value = '';
        }
        setStateRadios(false, false);
        if (pick) {
            document.getElementById('coa_parent_id').value = pick.dataset.id || '';
        } else {
            document.getElementById('coa_parent_id').value = '';
        }
        var lev = document.getElementById('coa_level');
        if (lev) {
            if (pick) {
                var d = parseInt(pick.dataset.depth, 10);
                lev.textContent = isNaN(d) ? '—' : coaHumanLevel(String(d + 1));
            } else {
                lev.textContent = coaHumanLevel('0');
            }
        }
        document.getElementById('coa_type_display').textContent = '—';
        document.getElementById('coa_category_display').textContent = '—';
        if (hasNb) {
            var nbSel = document.getElementById('coa_normal_balance');
            if (pick && pick.dataset && pick.dataset.isGroup !== '1' && pick.dataset.normalBalance) {
                nbSel.value = pick.dataset.normalBalance === 'credit' ? 'credit' : 'debit';
            } else {
                nbSel.value = 'debit';
            }
        }
        updateParentFieldsFromContext();
        updatePreviewFromParent();
        updateStatementLink();
        coaApplyDatasetToMappingFields(pick || null);
        coaSyncNormalBalanceUi();
    });

    document.querySelectorAll('input[name="coa_state"]').forEach(function (radio) {
        radio.addEventListener('change', coaSyncNormalBalanceUi);
    });

    document.getElementById('coa_btn_save').addEventListener('click', function () {
        if (!COA_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل الدليل المحاسبي');
            return;
        }
        var coaSaveBtn = document.getElementById('coa_btn_save');
        if (coaMainSaveInFlight || (coaSaveBtn && coaSaveBtn.getAttribute('data-orange-postjson-busy') === '1')) {
            return;
        }
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var p = document.getElementById('coa_parent_id').value.trim();
        var stEl = document.querySelector('input[name="coa_state"]:checked');
        var st = stEl ? stEl.value : 'leaf';
        var payload = {
            id: id,
            name: document.getElementById('coa_name').value.trim(),
            parent_id: p === '' ? null : parseInt(p, 10),
            is_group: st === 'group',
            is_suspended: hasSuspended && st === 'suspended'
        };
        if (hasNameEn) {
            payload.name_en = document.getElementById('coa_name_en').value.trim();
        }
        if (hasNb && st !== 'group') {
            payload.normal_balance = document.getElementById('coa_normal_balance').value || 'debit';
        }
        if (hasMap) {
            var at = document.getElementById('coa_account_type');
            var rs = document.getElementById('coa_report_section');
            var cf = document.getElementById('coa_cashflow_section');
            if (at) {
                payload.account_type = String(at.value || '').trim();
            }
            if (rs) {
                payload.report_section = String(rs.value || '').trim();
            }
            if (hasRli) {
                var rl = document.getElementById('coa_report_line_id');
                if (rl) {
                    payload.report_line_id = String(rl.value || '').trim();
                }
            }
            if (cf) {
                payload.cashflow_section = String(cf.value || '').trim();
            }
        }
        if (!payload.name) {
            alert('اسم الحساب بالعربية مطلوب');
            return;
        }
        if (id <= 0 && (payload.parent_id === null || payload.parent_id <= 0)) {
            alert('لا يُنشأ حساب جذر من هذه الشاشة. استخدم زر «اضافة الدليل المحاسبي» لإضافة الجذور، أو اختر حساباً أباً في الشجرة ثم «إضافة».');
            return;
        }
        if (id <= 0 && payload.parent_id !== null && payload.parent_id > 0) {
            var parLi = treeEl.querySelector('.coa-tree-node[data-id="' + payload.parent_id + '"]');
            if (parLi) {
                var pd = parseInt(parLi.dataset.depth, 10);
                if (!isNaN(pd) && pd >= 4) {
                    alert('لا يمكن إضافة حساب تحت المستوى الخامس — أقصى عمق خمسة مستويات');
                    return;
                }
            }
        }
        coaMainSaveInFlight = true;
        postJSON('/admin/api/accounts/save-node.php', payload, coaSaveBtn).then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) {
                var savedId = parseInt(String(r.id || '0'), 10) || 0;
                return refreshCoaMainTree(savedId > 0 ? savedId : undefined).catch(function (err) {
                    alert((err && err.message) ? err.message : String(err));
                    location.reload();
                });
            }
        }).catch(function (e) {
            alert(e.message || String(e));
        }).finally(function () {
            coaMainSaveInFlight = false;
        });
    });

    document.getElementById('coa_btn_delete').addEventListener('click', function () {
        if (!COA_CAPS.can_delete) {
            alert('لا تملك صلاحية حذف حسابات من الدليل');
            return;
        }
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        if (id <= 0) {
            alert('اختر حساباً من الشجرة أولاً (أو أنشئ واحداً ثم احفظه قبل الحذف).');
            return;
        }
        if (!confirm('حذف هذا الحساب نهائياً؟ لا يمكن التراجع إن نجح الحذف.')) {
            return;
        }
        postJSON('/admin/api/accounts/delete-node.php', { id: id }).then(function (r) {
            alert(r.message || (r.success ? 'تم الحذف' : 'فشل'));
            if (r.success) {
                location.reload();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    });

    document.getElementById('coa_btn_print').addEventListener('click', function () {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var shell = document.querySelector('.coa-shell');
        var fy = shell ? (shell.getAttribute('data-fy-default') || '0') : '0';
        if (id <= 0) {
            alert('اختر حساباً من الشجرة أولاً');
            return;
        }
        if (parseInt(fy, 10) <= 0) {
            alert('عرّف سنة مالية أولاً من «السنوات المالية»');
            return;
        }
        window.open(__orangeAdminPub + '/admin/index.php?page=financial_report&fy=' + encodeURIComponent(fy) + '&account=' + id + '&print=1', '_blank');
    });

    document.getElementById('coa_btn_statement').addEventListener('click', function (e) {
        if (this.classList.contains('coa-footer-link--disabled')) {
            e.preventDefault();
            alert('اختر حساباً محفوظاً من الشجرة (بعد الحفظ يظهر الكود).');
        }
    });

    /* ——— إعداد الدليل (نافذة) ——— */
    var modal = document.getElementById('coa_setup_modal');
    var tbody = document.getElementById('coa_setup_tbody');

    function openGuideModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('coa-modal-open');
        loadSetupRoots().catch(function (e) {
            alert(e.message || String(e));
        });
    }

    function resetSetupModalUi() {
        document.getElementById('coa_setup_row_id').value = '0';
        document.getElementById('coa_setup_code').value = '';
        document.getElementById('coa_setup_name').value = '';
        document.getElementById('coa_setup_name_en').value = '';
        tbody.querySelectorAll('tr.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
    }

    function closeGuideModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('coa-modal-open');
        resetSetupModalUi();
    }

    function loadSetupRoots() {
        return fetch('/admin/api/accounts/list-roots.php' + coaAdminCountryQuery(), {
            credentials: 'same-origin',
            headers: typeof orangeAdminCountryHeaders === 'function' ? orangeAdminCountryHeaders() : {}
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    return Promise.reject(new Error(data.message || 'تعذر تحميل الجذور'));
                }
                renderSetupTable(data.roots || []);
                clearSetupForm();
            });
    }

    function setupNextNumericCodePreview() {
        var roots = tbody.querySelectorAll('tr');
        var maxNum = 0;
        roots.forEach(function (tr) {
            var c = String(tr.dataset.code || '').trim();
            if (/^[0-9]+$/.test(c)) {
                maxNum = Math.max(maxNum, parseInt(c, 10));
            }
        });
        return roots.length ? String(maxNum + 1) : '1';
    }

    function renderSetupTable(roots) {
        tbody.innerHTML = '';
        roots.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.dataset.id = String(row.id);
            tr.dataset.code = row.code || '';
            tr.dataset.name = row.name || '';
            tr.dataset.nameEn = row.name_en || '';
            tr.dataset.canDelete = row.can_delete ? '1' : '0';
            var delTd = document.createElement('td');
            delTd.className = 'coa-setup-table__del';
            var delSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/></svg>';
            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'coa-setup-del' + (row.can_delete ? '' : ' coa-setup-del--disabled');
            delBtn.setAttribute('aria-label', row.can_delete ? 'حذف هذا الجذر' : 'حذف غير متاح');
            delBtn.innerHTML = delSvg;
            var hint = (row.delete_block_hint || '').trim();
            if (!row.can_delete && hint) {
                delBtn.title = hint;
                delBtn.setAttribute('aria-description', hint);
            } else if (!row.can_delete) {
                delBtn.title = 'لا يمكن حذف هذا الحساب حالياً';
            }
            if (!row.can_delete) {
                delBtn.disabled = true;
            } else {
                delBtn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    if (!confirm('حذف هذا الحساب الجذر؟')) {
                        return;
                    }
                    postJSON('/admin/api/accounts/delete-node.php', { id: row.id }).then(function (r) {
                        if (!r.success) {
                            alert(r.message || 'فشل الحذف');
                            return;
                        }
                        var msg = r.message || 'تم الحذف';
                        loadSetupRoots().then(function () {
                            return refreshCoaMainTree();
                        }).then(function () {
                            alert(msg);
                        }).catch(function (e) {
                            alert(msg + '\n— تعذر تحديث العرض: ' + (e.message || e));
                        });
                    }).catch(function (e) { alert(e.message || String(e)); });
                });
            }
            delTd.appendChild(delBtn);
            var c1 = document.createElement('td');
            c1.textContent = row.code || '';
            var c2 = document.createElement('td');
            c2.textContent = row.name || '';
            var c3 = document.createElement('td');
            c3.textContent = row.name_en || '';
            tr.appendChild(delTd);
            tr.appendChild(c1);
            tr.appendChild(c2);
            tr.appendChild(c3);
            tr.addEventListener('click', function () {
                tbody.querySelectorAll('tr.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
                tr.classList.add('is-selected');
                document.getElementById('coa_setup_row_id').value = String(row.id);
                document.getElementById('coa_setup_code').value = row.code || '';
                document.getElementById('coa_setup_name').value = row.name || '';
                document.getElementById('coa_setup_name_en').value = row.name_en || '';
            });
            tbody.appendChild(tr);
        });
    }

    function clearSetupForm() {
        document.getElementById('coa_setup_row_id').value = '0';
        tbody.querySelectorAll('tr.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
        document.getElementById('coa_setup_code').value = setupNextNumericCodePreview();
        document.getElementById('coa_setup_name').value = '';
        document.getElementById('coa_setup_name_en').value = '';
    }

    document.getElementById('coa_btn_open_guide').addEventListener('click', openGuideModal);
    document.getElementById('coa_setup_backdrop').addEventListener('click', closeGuideModal);
    document.getElementById('coa_setup_btn_close').addEventListener('click', closeGuideModal);
    document.getElementById('coa_setup_btn_new').addEventListener('click', clearSetupForm);
    document.getElementById('coa_setup_btn_save').addEventListener('click', function () {
        if (!COA_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل الدليل المحاسبي');
            return;
        }
        var coaSetupSaveBtn = document.getElementById('coa_setup_btn_save');
        if (coaSetupSaveInFlight || (coaSetupSaveBtn && coaSetupSaveBtn.getAttribute('data-orange-postjson-busy') === '1')) {
            return;
        }
        var name = document.getElementById('coa_setup_name').value.trim();
        if (!name) {
            alert('الاسم بالعربية مطلوب');
            return;
        }
        var sid = parseInt(document.getElementById('coa_setup_row_id').value, 10) || 0;
        var payload = {
            id: sid,
            name: name,
            name_en: document.getElementById('coa_setup_name_en').value.trim()
        };
        coaSetupSaveInFlight = true;
        postJSON('/admin/api/accounts/save-root-setup.php', payload, coaSetupSaveBtn).then(function (r) {
            if (!r.success) {
                alert(r.message || 'فشل الحفظ');
                return;
            }
            var msg = r.message || 'تم الحفظ';
            loadSetupRoots().then(function () {
                return refreshCoaMainTree();
            }).then(function () {
                alert(msg);
            }).catch(function (e) {
                alert(msg + '\n— تعذر تحديث العرض: ' + (e.message || e));
            });
        }).catch(function (e) { alert(e.message || String(e)); }).finally(function () {
            coaSetupSaveInFlight = false;
        });
    });
    document.getElementById('coa_setup_btn_print').addEventListener('click', function () {
        window.print();
    });

    var preSelectId = <?php echo (int) $firstId; ?>;
    var first = null;
    if (preSelectId > 0) {
        first = treeEl.querySelector('.coa-tree-node[data-id="' + preSelectId + '"]');
    }
    if (!first) {
        first = treeEl.querySelector('.coa-tree-node');
    }
    if (first) {
        first.classList.add('is-active');
        fillForm(first);
    } else {
        document.getElementById('coa_parent_id').value = '';
        document.getElementById('coa_parent_code').value = '';
        updatePreviewFromParent();
        updateStatementLink();
    }
});
</script>

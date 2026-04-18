<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$flat = orange_accounts_flat($pdo);
$tree = orange_accounts_build_tree($flat);
$depths = orange_accounts_depth_by_id($flat);
$hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
$hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
$hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');

$fyList = orange_fiscal_years_list($pdo);
$fyDefault = $fyList !== [] ? (int) $fyList[0]['id'] : 0;

$firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;
?>
<div class="coa-shell" dir="rtl" data-fy-default="<?php echo (int) $fyDefault; ?>">
    <div class="coa-shell__body" dir="ltr">
        <aside class="coa-shell__tree card coa-tree-card">
            <h3 class="card-title coa-tree-card__title">شجرة الحسابات</h3>
            <div class="coa-tree-search">
                <input type="search" id="coa_tree_search" class="coa-tree-search__input" placeholder="بحث في الشجرة…" autocomplete="off" dir="rtl">
            </div>
            <button type="button" class="btn-coa-guide" id="coa_btn_open_guide">اضافة الدليل المحاسبي</button>
            <div class="coa-tree-scroll" id="coa_tree_root" role="tree">
                <?php if ($tree === []): ?>
                    <p class="muted">لا توجد حسابات بعد. افتح «إعداد الدليل» أو اضغط «إضافة» ثم احفظ.</p>
                <?php else: ?>
                    <?php orange_render_coa_tree($tree, $firstId, $flat, 0); ?>
                <?php endif; ?>
            </div>
        </aside>

        <div class="coa-shell__main" dir="rtl">
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
                        <select id="coa_normal_balance">
                            <option value="debit">مدين</option>
                            <option value="credit">دائن</option>
                        </select>
                    </div>
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
                <button type="button" class="btn-secondary" id="coa_btn_new">إضافة</button>
                <button type="button" class="btn-danger" id="coa_btn_delete">حذف</button>
                <button type="button" id="coa_btn_save">حفظ</button>
                <a class="btn-secondary coa-footer-link coa-footer-link--disabled" id="coa_btn_statement" href="#">كشف حساب</a>
                <button type="button" class="btn-secondary" id="coa_btn_print">طباعة</button>
                <a class="btn-secondary" href="/admin/index.php">خروج</a>
            </footer>
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
                    <span class="muted coa-setup-code-hint">تلقائي (أكبر رقم + 1)</span>
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
            <button type="button" id="coa_setup_btn_save">حفظ</button>
            <button type="button" class="btn-secondary" id="coa_setup_btn_print">طباعة</button>
            <button type="button" class="btn-secondary" id="coa_setup_btn_close">خروج</button>
        </footer>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var treeEl = document.getElementById('coa_tree_root');
    var hasNameEn = <?php echo $hasNameEn ? 'true' : 'false'; ?>;
    var hasSuspended = <?php echo $hasSuspended ? 'true' : 'false'; ?>;
    var hasNb = <?php echo $hasNb ? 'true' : 'false'; ?>;

    var levelOrds = ['', 'الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر'];

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
        a.href = '/admin/index.php?page=financial_report&fy=' + encodeURIComponent(fy) + '&account=' + id;
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
            var nb = li.dataset.normalBalance || 'debit';
            document.getElementById('coa_normal_balance').value = nb === 'credit' ? 'credit' : 'debit';
        }
        updateParentFieldsFromContext();
        updateStatementLink();
    }

    function bindTreeClicks(root) {
        root.querySelectorAll('.coa-tree-node').forEach(function (li) {
            var label = li.querySelector('.coa-tree-label');
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

    function liHasMatchingDescendant(li, q) {
        var lab = li.querySelector(':scope > .coa-tree-label');
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
    }

    document.getElementById('coa_name').addEventListener('input', function () {
        if (parseInt(document.getElementById('coa_id').value, 10) <= 0) {
            updatePreviewFromParent();
        }
    });

    bindTreeClicks(treeEl);

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
        if (hasNb) {
            document.getElementById('coa_normal_balance').value = 'debit';
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
        updateParentFieldsFromContext();
        updatePreviewFromParent();
        updateStatementLink();
    });

    document.getElementById('coa_btn_save').addEventListener('click', function () {
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
        if (hasNb) {
            payload.normal_balance = document.getElementById('coa_normal_balance').value;
        }
        if (!payload.name) {
            alert('اسم الحساب بالعربية مطلوب');
            return;
        }
        postJSON('/admin/api/accounts/save-node.php', payload).then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) {
                location.reload();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    });

    document.getElementById('coa_btn_delete').addEventListener('click', function () {
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
        window.open('/admin/index.php?page=financial_report&fy=' + encodeURIComponent(fy) + '&account=' + id + '&print=1', '_blank');
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
        return fetch('/admin/api/accounts/list-roots.php', { credentials: 'same-origin' })
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
            if (row.can_delete) {
                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'coa-setup-del';
                delBtn.setAttribute('aria-label', 'حذف الصف');
                delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/></svg>';
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
                        loadSetupRoots().then(function () {
                            alert(r.message || 'تم الحذف');
                        }).catch(function (e) {
                            alert((r.message || 'تم الحذف') + '\n— تعذر تحديث الجدول: ' + (e.message || e));
                        });
                    }).catch(function (e) { alert(e.message || String(e)); });
                });
                delTd.appendChild(delBtn);
            }
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
        postJSON('/admin/api/accounts/save-root-setup.php', payload).then(function (r) {
            if (!r.success) {
                alert(r.message || 'فشل الحفظ');
                return;
            }
            loadSetupRoots().then(function () {
                alert(r.message || 'تم الحفظ');
            }).catch(function (e) {
                alert((r.message || 'تم الحفظ') + '\n— تعذر تحديث الجدول: ' + (e.message || e));
            });
        }).catch(function (e) { alert(e.message || String(e)); });
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

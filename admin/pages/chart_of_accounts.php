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
    <div class="coa-shell__title">
        <h1 class="coa-shell__heading">الدليل المحاسبي</h1>
        <p class="coa-shell__subtitle muted">
            الشجرة تبدأ فارغة وتبنيها أنت. كود الحساب يُولَّد تلقائياً عند الحفظ ولا يُعدَّل يدوياً.
            <strong>الحساب الرئيسي</strong> لا يظهر في بحث القيود؛ <strong>الفرعي</strong> للتسجيل في السندات.
        </p>
    </div>

    <div class="coa-shell__body" dir="ltr">
        <aside class="coa-shell__tree card coa-tree-card">
            <h3 class="card-title coa-tree-card__title">شجرة الحسابات</h3>
            <div class="coa-tree-search">
                <input type="search" id="coa_tree_search" class="coa-tree-search__input" placeholder="بحث في الشجرة…" autocomplete="off" dir="rtl">
                <button type="button" class="btn-secondary coa-tree-search__btn" id="coa_tree_search_clear">مسح</button>
            </div>
            <div class="coa-tree-scroll" id="coa_tree_root" role="tree">
                <?php if ($tree === []): ?>
                    <p class="muted">لا توجد حسابات بعد. اضغط «إضافة» ثم املأ البيانات واحفظ.</p>
                <?php else: ?>
                    <?php orange_render_coa_tree($tree, $firstId, $flat, 0); ?>
                <?php endif; ?>
            </div>
        </aside>

        <div class="coa-shell__main" dir="rtl">
            <div class="card coa-form-card coa-form-card--classic">
                <h3 class="card-title coa-form-card__title">بيانات الحساب</h3>
                <input type="hidden" id="coa_id" value="0">

                <div class="coa-form-grid">
                    <div class="coa-field coa-field--code">
                        <label for="coa_code">كود الحساب</label>
                        <input type="text" id="coa_code" maxlength="64" class="coa-input-wide coa-input-readonly" readonly placeholder="يُولَّد تلقائياً عند الحفظ">
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
                        <span class="coa-field__label">مستوى الحساب</span>
                        <p class="coa-level-display" id="coa_level">—</p>
                    </div>
                    <div class="coa-field">
                        <span class="coa-field__label">نوع الحساب</span>
                        <p class="coa-level-display" id="coa_type_display">—</p>
                        <p class="coa-field-hint muted">يُحدَّد من <strong>جذر الشجرة</strong> للحساب المحدد.</p>
                    </div>
                    <div class="coa-field coa-field--span2">
                        <span class="coa-field__label">فئة الحساب</span>
                        <p class="coa-level-display" id="coa_category_display">—</p>
                        <p class="coa-field-hint muted">يُحدَّد من <strong>المستوى الثاني</strong> في مسار الحساب.</p>
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

                    <div class="coa-field">
                        <label for="coa_parent_code">كود الحساب الأب</label>
                        <input type="text" id="coa_parent_code" readonly class="coa-input-readonly" tabindex="-1" placeholder="—">
                    </div>
                    <div class="coa-field coa-field--span2">
                        <label for="coa_parent">الحساب الأب</label>
                        <select id="coa_parent">
                            <option value="" data-code="" data-root="" data-category="" data-depth="" data-pname="">— جذر (بدون أب) —</option>
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
                                $rcOpt = orange_coa_root_category_names($flat, $cid);
                                $dr = htmlspecialchars($rcOpt['root'], ENT_QUOTES, 'UTF-8');
                                $dcat = htmlspecialchars($rcOpt['category'], ENT_QUOTES, 'UTF-8');
                                echo '<option value="' . $cid . '" data-code="' . $cc . '" data-root="' . $dr . '" data-category="' . $dcat . '" data-depth="' . (int) $d . '" data-pname="' . $nm . '">' . $pad . $cc . ' — ' . $nm . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <?php if ($hasSuspended): ?>
                    <div class="coa-field coa-field--suspend">
                        <label class="coa-check"><input type="checkbox" id="coa_suspended"> حساب موقوف</label>
                    </div>
                    <?php endif; ?>

                    <div class="coa-field coa-field--kind coa-field--span2">
                        <span class="coa-field__label">الحساب في القيود</span>
                        <div class="coa-radio-row">
                            <label class="coa-radio"><input type="radio" name="coa_kind" value="1"> حساب رئيسي <span class="muted">(لا يظهر في بحث القيود)</span></label>
                            <label class="coa-radio"><input type="radio" name="coa_kind" value="0" checked> حساب فرعي <span class="muted">(للتسجيل في السندات)</span></label>
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

<script>
(function () {
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

    function updateParentCodeDisplay() {
        var sel = document.getElementById('coa_parent');
        var opt = sel && sel.selectedOptions && sel.selectedOptions[0];
        var pc = document.getElementById('coa_parent_code');
        if (!pc) {
            return;
        }
        if (!opt || !opt.value) {
            pc.value = '';
            pc.placeholder = '—';
            return;
        }
        pc.value = opt.getAttribute('data-code') || '';
    }

    function setKindRadios(isGroup) {
        var v = isGroup ? '1' : '0';
        var r = document.querySelector('input[name="coa_kind"][value="' + v + '"]');
        if (r) {
            r.checked = true;
        }
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

    function updatePreviewFromParent() {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        if (id > 0) {
            return;
        }
        var opt = document.getElementById('coa_parent').selectedOptions[0];
        var rootEl = document.getElementById('coa_type_display');
        var catEl = document.getElementById('coa_category_display');
        var nameInp = document.getElementById('coa_name');
        if (!opt || !opt.value) {
            rootEl.textContent = '—';
            catEl.textContent = '—';
            return;
        }
        var root = opt.getAttribute('data-root') || '';
        var depth = parseInt(opt.getAttribute('data-depth'), 10);
        if (isNaN(depth)) {
            depth = 0;
        }
        rootEl.textContent = root || '—';
        if (depth === 0) {
            catEl.textContent = (nameInp.value || '').trim() || '—';
        } else {
            catEl.textContent = opt.getAttribute('data-category') || '—';
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
        if (hasSuspended) {
            document.getElementById('coa_suspended').checked = li.dataset.suspended === '1';
        }
        if (hasNb) {
            var nb = li.dataset.normalBalance || 'debit';
            document.getElementById('coa_normal_balance').value = nb === 'credit' ? 'credit' : 'debit';
        }
        setKindRadios(li.dataset.isGroup === '1');
        var p = parseInt(li.dataset.parent, 10) || 0;
        var sel = document.getElementById('coa_parent');
        sel.value = p > 0 ? String(p) : '';
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
        updateParentCodeDisplay();
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

    document.getElementById('coa_parent').addEventListener('change', function () {
        updateParentCodeDisplay();
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var lev = document.getElementById('coa_level');
        if (lev && id <= 0) {
            var opt = this.selectedOptions && this.selectedOptions[0];
            if (!opt || !opt.value) {
                lev.textContent = '—';
            } else {
                var pd = parseInt(opt.getAttribute('data-depth'), 10);
                lev.textContent = isNaN(pd) ? '—' : coaHumanLevel(String(pd + 1));
            }
        }
        updatePreviewFromParent();
    });

    document.getElementById('coa_name').addEventListener('input', function () {
        if (parseInt(document.getElementById('coa_id').value, 10) <= 0) {
            updatePreviewFromParent();
        }
    });

    bindTreeClicks(treeEl);

    document.getElementById('coa_tree_search').addEventListener('input', function () {
        applyCoaTreeFilter(this.value);
    });
    document.getElementById('coa_tree_search_clear').addEventListener('click', function () {
        var inp = document.getElementById('coa_tree_search');
        inp.value = '';
        applyCoaTreeFilter('');
        inp.focus();
    });

    document.getElementById('coa_btn_new').addEventListener('click', function () {
        document.getElementById('coa_id').value = '0';
        document.getElementById('coa_code').value = '';
        document.getElementById('coa_name').value = '';
        if (hasNameEn) {
            document.getElementById('coa_name_en').value = '';
        }
        if (hasSuspended) {
            document.getElementById('coa_suspended').checked = false;
        }
        if (hasNb) {
            document.getElementById('coa_normal_balance').value = 'debit';
        }
        document.getElementById('coa_parent').value = '';
        setKindRadios(false);
        var lev = document.getElementById('coa_level');
        if (lev) {
            lev.textContent = '—';
        }
        document.getElementById('coa_type_display').textContent = '—';
        document.getElementById('coa_category_display').textContent = '—';
        treeEl.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
        updateParentCodeDisplay();
        updatePreviewFromParent();
        updateStatementLink();
    });

    document.getElementById('coa_btn_save').addEventListener('click', function () {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var p = document.getElementById('coa_parent').value.trim();
        var kindEl = document.querySelector('input[name="coa_kind"]:checked');
        var payload = {
            id: id,
            name: document.getElementById('coa_name').value.trim(),
            parent_id: p === '' ? null : parseInt(p, 10),
            is_group: kindEl && kindEl.value === '1'
        };
        if (hasNameEn) {
            payload.name_en = document.getElementById('coa_name_en').value.trim();
        }
        if (hasSuspended) {
            payload.is_suspended = document.getElementById('coa_suspended').checked;
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
        updateParentCodeDisplay();
        updatePreviewFromParent();
        updateStatementLink();
    }
})();
</script>

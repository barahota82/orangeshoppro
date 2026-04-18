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
$hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
$hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');

$classLabels = [
    'unclassified' => 'غير مصنف',
    'asset' => 'أصول',
    'liability' => 'خصوم',
    'equity' => 'حقوق الملكية',
    'revenue' => 'إيرادات',
    'expense' => 'مصروفات',
    'cogs' => 'تكلفة مبيعات',
];

/**
 * @param list<array<string, mixed>> $nodes
 */
function orange_render_coa_tree(array $nodes, int $activeId, int $depth = 0): void
{
    echo '<ul class="coa-tree-list">';
    foreach ($nodes as $n) {
        $id = (int) $n['id'];
        $code = htmlspecialchars((string) ($n['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8');
        $nameEn = htmlspecialchars((string) ($n['name_en'] ?? ''), ENT_QUOTES, 'UTF-8');
        $isG = !empty($n['is_group']);
        $susp = !empty($n['is_suspended']);
        $cls = $activeId === $id ? 'coa-tree-node is-active' : 'coa-tree-node';
        if ($susp) {
            $cls .= ' coa-tree-node--suspended';
        }
        $ac = htmlspecialchars((string) ($n['account_class'] ?? 'unclassified'), ENT_QUOTES, 'UTF-8');
        echo '<li class="' . $cls . '" role="treeitem" data-id="' . $id . '" data-code="' . $code . '" data-name="' . $name . '" data-name-en="' . $nameEn . '" data-is-group="' . ($isG ? '1' : '0') . '" data-class="' . $ac . '" data-parent="' . (int) ($n['parent_id'] ?? 0) . '" data-suspended="' . ($susp ? '1' : '0') . '" data-depth="' . $depth . '">';
        echo '<span class="coa-tree-label">' . $code . ' — ' . $name . ($isG ? ' <small>(رئيسي)</small>' : '') . ($susp ? ' <small class="coa-tree-suspended-tag">موقوف</small>' : '') . '</span>';
        if (!empty($n['children'])) {
            orange_render_coa_tree($n['children'], $activeId, $depth + 1);
        }
        echo '</li>';
    }
    echo '</ul>';
}

$firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;
?>
<div class="coa-shell" dir="rtl">
    <div class="coa-shell__title">
        <h1 class="coa-shell__heading">الدليل المحاسبي</h1>
        <p class="coa-shell__subtitle muted">
            شجرة الحسابات مع اقتراح كود تلقائي. التصنيف يُستخدم في التقارير و
            <a href="/admin/index.php?page=gl_account_settings">حسابات القيود التلقائية</a>.
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
                    <p class="muted">لا توجد حسابات — استخدم «إضافة» ثم احفظ حساباً جذرياً.</p>
                <?php else: ?>
                    <?php orange_render_coa_tree($tree, $firstId, 0); ?>
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
                        <div class="coa-field__row">
                            <input type="text" id="coa_code" maxlength="64" class="coa-input-wide">
                            <button type="button" id="coa_btn_suggest" class="btn-secondary">اقتراح كود</button>
                        </div>
                    </div>
                    <div class="coa-field coa-field--kind">
                        <span class="coa-field__label">نوع الحساب في الشجرة</span>
                        <div class="coa-radio-row">
                            <label class="coa-radio"><input type="radio" name="coa_kind" value="1"> رئيسي</label>
                            <label class="coa-radio"><input type="radio" name="coa_kind" value="0" checked> فرعي</label>
                        </div>
                    </div>
                    <?php if ($hasSuspended): ?>
                    <div class="coa-field coa-field--suspend">
                        <label class="coa-check"><input type="checkbox" id="coa_suspended"> موقوف</label>
                    </div>
                    <?php endif; ?>

                    <div class="coa-field">
                        <label for="coa_parent_code">كود الحساب الأب</label>
                        <input type="text" id="coa_parent_code" readonly class="coa-input-readonly" tabindex="-1" placeholder="—">
                    </div>
                    <div class="coa-field coa-field--span2">
                        <label for="coa_parent">الحساب الأب (اختيار)</label>
                        <select id="coa_parent">
                            <option value="" data-code="">— جذر (بدون أب) —</option>
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
                                echo '<option value="' . $cid . '" data-code="' . $cc . '">' . $pad . $cc . ' — ' . $nm . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="coa-field coa-field--span2">
                        <label for="coa_name">اسم الحساب عربي</label>
                        <input type="text" id="coa_name" class="coa-input-wide">
                    </div>
                    <?php if ($hasNameEn): ?>
                    <div class="coa-field coa-field--span2">
                        <label for="coa_name_en">اسم الحساب لاتيني / English</label>
                        <input type="text" id="coa_name_en" class="coa-input-wide" lang="en" dir="ltr">
                    </div>
                    <?php endif; ?>

                    <div class="coa-field">
                        <span class="coa-field__label">مستوى الحساب</span>
                        <p class="coa-level-display" id="coa_level">—</p>
                    </div>
                    <?php if ($hasClass): ?>
                    <div class="coa-field">
                        <label for="coa_class">نوع الحساب (تصنيف)</label>
                        <select id="coa_class">
                            <?php foreach ($classLabels as $k => $lab): ?>
                                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="coa-tabs-placeholder" aria-hidden="true">
                    <div class="coa-tabs-placeholder__tabs">
                        <span class="coa-tabs-placeholder__tab is-disabled">حسابات التوزيع المتغير</span>
                        <span class="coa-tabs-placeholder__tab is-disabled">حسابات التوزيع الثابت</span>
                    </div>
                    <div class="coa-tabs-placeholder__body muted">غير مستخدم حالياً — يمكن إضافته لاحقاً عند الحاجة.</div>
                </div>
            </div>

            <footer class="coa-shell__footer">
                <button type="button" class="btn-secondary" id="coa_btn_print">طباعة</button>
                <button type="button" class="btn-secondary" id="coa_btn_cost_stub" disabled title="قريباً">تحليل مراكز تكلفة</button>
                <a class="btn-secondary" id="coa_btn_report" href="/admin/index.php?page=financial_report">كشف حساب / تقارير</a>
                <button type="button" class="btn-secondary" id="coa_btn_new">إضافة</button>
                <button type="button" class="btn-danger" id="coa_btn_delete">حذف</button>
                <button type="button" id="coa_btn_save">حفظ</button>
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
    var coaArTimer = null;
    var coaEnTimer = null;

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
        setKindRadios(li.dataset.isGroup === '1');
        var p = parseInt(li.dataset.parent, 10) || 0;
        var sel = document.getElementById('coa_parent');
        sel.value = p > 0 ? String(p) : '';
        var cl = document.getElementById('coa_class');
        if (cl && li.dataset.class) {
            cl.value = li.dataset.class;
        }
        var lev = document.getElementById('coa_level');
        if (lev) {
            lev.textContent = coaHumanLevel(li.dataset.depth);
        }
        updateParentCodeDisplay();
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
        if (!lev || id > 0) {
            return;
        }
        var sel = document.getElementById('coa_parent');
        var opt = sel && sel.selectedOptions && sel.selectedOptions[0];
        if (!opt || !opt.value) {
            lev.textContent = '—';
            return;
        }
        var pd = parseInt(opt.getAttribute('data-depth'), 10);
        lev.textContent = isNaN(pd) ? '—' : coaHumanLevel(String(pd + 1));
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

    function coaTranslate(silent, forceFromArabic) {
        if (!hasNameEn) {
            return;
        }
        var nameAr = document.getElementById('coa_name').value.trim();
        var payload = {
            name_ar: nameAr,
            name_en: forceFromArabic ? '' : document.getElementById('coa_name_en').value.trim()
        };
        postJSON('/admin/api/translate/names.php', payload).then(function (res) {
            if (!res || !res.success) {
                if (!silent) {
                    alert((res && res.message) ? res.message : 'فشل الترجمة');
                }
                return;
            }
            var t = res.translations || {};
            if (t.name_en) {
                document.getElementById('coa_name_en').value = t.name_en;
            }
        }).catch(function () {
            if (!silent) {
                alert('فشل طلب الترجمة');
            }
        });
    }

    if (hasNameEn) {
        document.getElementById('coa_name').addEventListener('input', function () {
            var v = this.value.trim();
            if (!v) {
                document.getElementById('coa_name_en').value = '';
                return;
            }
            clearTimeout(coaArTimer);
            coaArTimer = setTimeout(function () { coaTranslate(true, true); }, 700);
        });
        document.getElementById('coa_name_en').addEventListener('input', function () {
            if (!this.value.trim()) {
                return;
            }
            clearTimeout(coaEnTimer);
            coaEnTimer = setTimeout(function () { coaTranslate(true, false); }, 600);
        });
    }

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
        document.getElementById('coa_parent').value = '';
        setKindRadios(false);
        var cl = document.getElementById('coa_class');
        if (cl) {
            cl.value = 'unclassified';
        }
        var lev = document.getElementById('coa_level');
        if (lev) {
            lev.textContent = '—';
        }
        treeEl.querySelectorAll('.coa-tree-node.is-active').forEach(function (x) { x.classList.remove('is-active'); });
        updateParentCodeDisplay();
    });

    document.getElementById('coa_btn_suggest').addEventListener('click', function () {
        var p = document.getElementById('coa_parent').value.trim();
        var parentId = p === '' ? null : parseInt(p, 10);
        postJSON('/admin/api/accounts/suggest-code.php', { parent_id: parentId }).then(function (r) {
            if (!r.success) {
                alert(r.message || 'فشل');
                return;
            }
            document.getElementById('coa_code').value = r.suggested_code || '';
        }).catch(function (e) { alert(e.message || String(e)); });
    });

    document.getElementById('coa_btn_save').addEventListener('click', function () {
        var id = parseInt(document.getElementById('coa_id').value, 10) || 0;
        var p = document.getElementById('coa_parent').value.trim();
        var kindEl = document.querySelector('input[name="coa_kind"]:checked');
        var payload = {
            id: id,
            name: document.getElementById('coa_name').value.trim(),
            code: document.getElementById('coa_code').value.trim(),
            parent_id: p === '' ? null : parseInt(p, 10),
            is_group: kindEl && kindEl.value === '1',
            account_class: document.getElementById('coa_class') ? document.getElementById('coa_class').value : 'unclassified'
        };
        if (hasNameEn) {
            payload.name_en = document.getElementById('coa_name_en').value.trim();
        }
        if (hasSuspended) {
            payload.is_suspended = document.getElementById('coa_suspended').checked;
        }
        if (!payload.name) {
            alert('اسم الحساب مطلوب');
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
        window.print();
    });

    var first = treeEl.querySelector('.coa-tree-node');
    if (first) {
        first.classList.add('is-active');
        fillForm(first);
    } else {
        updateParentCodeDisplay();
    }

    (function addDepthToParentOptions() {
        var depthMap = <?php
            $jsonMap = [];
            foreach ($flat as $r) {
                $jsonMap[(string) ((int) $r['id'])] = (int) ($depths[(int) $r['id']] ?? 0);
            }
            echo json_encode($jsonMap, JSON_UNESCAPED_UNICODE);
        ?>;
        var sel = document.getElementById('coa_parent');
        if (!sel) {
            return;
        }
        Array.prototype.forEach.call(sel.options, function (opt) {
            if (!opt.value) {
                return;
            }
            var d = depthMap[opt.value];
            if (typeof d === 'number') {
                opt.setAttribute('data-depth', String(d));
            }
        });
    })();
})();
</script>

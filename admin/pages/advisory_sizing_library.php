<?php

declare(strict_types=1);

$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/advisory_sizing_library.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_advisory_sizing_library_tables_ready($pdo);
$families = [];
if (orange_table_exists($pdo, 'size_families')) {
    try {
        $families = $pdo->query(
            'SELECT id, name_ar, name_en, commercial_kind_key FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $families = [];
    }
}
$familiesJson = json_encode($families, JSON_UNESCAPED_UNICODE);
if ($familiesJson === false) {
    $familiesJson = '[]';
}
?>
<div class="page-title">
    <h1>مكتبة أدلة المقاسات الاسترشادية</h1>
</div>

<p class="card-hint" style="margin:0 0 12px;">
    <strong>الفكرة:</strong> تُنشأ <strong>حزمة</strong> تشير إلى <strong>عائلة مصدر</strong> (تحرير الأدلة من «دليل المقاس الاسترشادي» على تلك العائلة).
    ثم تُربَط <strong>عائلة مستهلك</strong> بالحزمة وتُضغط <strong>مزامنة</strong> لنسخ أدلة علوي/سفلي/مفرد إلى عائلة المستهلك مع مطابقة المقاسات حسب <strong>الترتيب</strong> (نفس عدد المقاسات النشطة في المصدر والمستهلك).
    المنتجات التي تستخدم عائلة المستهلك <strong>ترث</strong> الأدلة تلقائياً (لا حقل إضافي على المنتج في هذه المرحلة).
    المرجع: <code>docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md</code>
</p>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول المكتبة غير جاهزة. تأكد من تطبيق الترحيل <code>scripts/migrations/031_advisory_sizing_library.sql</code> ثم حدّث الصفحة.</div>
</div>
<?php else: ?>

<div class="card">
    <h3>حزم المكتبة</h3>
    <p class="card-hint">مفتاح النوع التجاري (مستوى 1) اختياري: عند تعبئته يُشترط تطابقه مع <code>commercial_kind_key</code> لعائلة المستهلك عند المزامنة.</p>
    <div class="form-grid" style="max-width:920px;">
        <div><label for="aslb_id">معرّف (0 = جديد)</label><input type="number" id="aslb_id" value="0" min="0" class="input-narrow"></div>
        <div><label for="aslb_sort">ترتيب</label><input type="number" id="aslb_sort" value="0"></div>
        <div><label for="aslb_active">نشط</label>
            <select id="aslb_active"><option value="1">نعم</option><option value="0">لا</option></select>
        </div>
        <div style="grid-column:1/-1;"><label for="aslb_name_ar">اسم الحزمة (عربي)</label><input type="text" id="aslb_name_ar" maxlength="191"></div>
        <div style="grid-column:1/-1;"><label for="aslb_name_en">اسم الحزمة (EN)</label><input type="text" id="aslb_name_en" maxlength="191"></div>
        <div style="grid-column:1/-1;"><label for="aslb_ck">مفتاح النوع التجاري (اختياري، حتى 32 حرفاً)</label><input type="text" id="aslb_ck" maxlength="32" placeholder="مثال: clothing"></div>
        <div style="grid-column:1/-1;"><label for="aslb_src">عائلة المصدر (الأدلة معدّة عليها)</label>
            <select id="aslb_src"><option value="0">— اختر —</option>
                <?php foreach ($families as $f): ?>
                <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars((string) ($f['name_ar'] ?: $f['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="aslb_save_bundle">حفظ الحزمة</button>
        <button type="button" class="btn-secondary" id="aslb_clear_bundle">تفريغ النموذج</button>
    </div>
    <div style="margin-top:16px;overflow:auto;">
        <table class="data-table"><thead><tr>
            <th>ترتيب</th><th>عربي</th><th>EN</th><th>تجاري</th><th>عائلة مصدر</th><th>نشط</th><th>إجراءات</th>
        </tr></thead><tbody id="aslb_bundle_rows"></tbody></table>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>ربط عائلة مستهلك → حزمة</h3>
    <div class="form-grid" style="max-width:720px;">
        <div style="grid-column:1/-1;"><label for="aslb_map_consumer">عائلة المستهلك</label>
            <select id="aslb_map_consumer"><option value="0">— اختر —</option>
                <?php foreach ($families as $f): ?>
                <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars((string) ($f['name_ar'] ?: $f['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1/-1;"><label for="aslb_map_bundle">حزمة المكتبة</label>
            <select id="aslb_map_bundle"><option value="0">— اختر —</option></select>
        </div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="aslb_save_map">حفظ الربط</button>
        <button type="button" class="btn" id="aslb_sync">مزامنة الأدلة إلى عائلة المستهلك</button>
        <button type="button" class="btn-secondary" id="aslb_delete_map">إزالة الربط</button>
    </div>
    <div style="margin-top:16px;overflow:auto;">
        <table class="data-table"><thead><tr>
            <th>عائلة مستهلك</th><th>حزمة</th><th>آخر تحديث</th><th>إجراءات</th>
        </tr></thead><tbody id="aslb_map_rows"></tbody></table>
    </div>
</div>

<script>
(function () {
    var API = '/admin/api/advisory_sizing_library/manage.php';
    var FAMILIES = <?php echo $familiesJson; ?>;

    function famLabel(id) {
        id = parseInt(id, 10) || 0;
        for (var i = 0; i < FAMILIES.length; i++) {
            if (parseInt(FAMILIES[i].id, 10) === id) {
                return (FAMILIES[i].name_ar || FAMILIES[i].name_en || ('#' + id));
            }
        }
        return '#' + id;
    }

    function esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }

    async function api(payload) {
        if (typeof postJSON !== 'function') {
            alert('postJSON غير متاح');
            return null;
        }
        return await postJSON(API, payload);
    }

    function fillBundleSelect(sel, bundles, selectedId) {
        sel.innerHTML = '<option value="0">— اختر —</option>';
        for (var i = 0; i < bundles.length; i++) {
            var b = bundles[i];
            var id = parseInt(b.id, 10) || 0;
            var lab = (b.name_ar || b.name_en || ('#' + id));
            var o = document.createElement('option');
            o.value = String(id);
            o.textContent = lab;
            if (id === selectedId) o.selected = true;
            sel.appendChild(o);
        }
    }

    async function loadBundles() {
        var res = await api({ action: 'list_bundles' });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'خطأ تحميل الحزم');
            return [];
        }
        var bundles = res.bundles || [];
        var tb = document.getElementById('aslb_bundle_rows');
        tb.innerHTML = '';
        for (var j = 0; j < bundles.length; j++) {
            var b = bundles[j];
            var tr = document.createElement('tr');
            var srcLab = famLabel(b.source_size_family_id);
            tr.innerHTML =
                '<td>' + esc(String(b.sort_order != null ? b.sort_order : '')) + '</td>' +
                '<td>' + esc(b.name_ar) + '</td>' +
                '<td>' + esc(b.name_en) + '</td>' +
                '<td><code>' + esc(b.commercial_kind_key || '') + '</code></td>' +
                '<td>' + esc(srcLab) + '</td>' +
                '<td>' + (parseInt(b.is_active, 10) ? 'نعم' : 'لا') + '</td>' +
                '<td><button type="button" class="btn-secondary aslb-ed" data-id="' + parseInt(b.id, 10) + '">تعديل</button> ' +
                '<button type="button" class="btn-secondary aslb-del" data-id="' + parseInt(b.id, 10) + '">حذف</button></td>';
            tb.appendChild(tr);
        }
        fillBundleSelect(document.getElementById('aslb_map_bundle'), bundles, parseInt(document.getElementById('aslb_map_bundle').value, 10) || 0);
        tb.querySelectorAll('.aslb-ed').forEach(function (btn) {
            btn.onclick = function () {
                var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
                var row = bundles.filter(function (x) { return parseInt(x.id, 10) === id; })[0];
                if (!row) return;
                document.getElementById('aslb_id').value = String(id);
                document.getElementById('aslb_sort').value = String(row.sort_order != null ? row.sort_order : 0);
                document.getElementById('aslb_active').value = parseInt(row.is_active, 10) ? '1' : '0';
                document.getElementById('aslb_name_ar').value = row.name_ar || '';
                document.getElementById('aslb_name_en').value = row.name_en || '';
                document.getElementById('aslb_ck').value = row.commercial_kind_key || '';
                document.getElementById('aslb_src').value = String(parseInt(row.source_size_family_id, 10) || 0);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };
        });
        tb.querySelectorAll('.aslb-del').forEach(function (btn) {
            btn.onclick = async function () {
                var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
                if (!confirm('حذف الحزمة #' + id + '؟')) return;
                var r2 = await api({ action: 'delete_bundle', id: id });
                if (!r2 || !r2.success) {
                    alert((r2 && r2.message) ? r2.message : 'فشل الحذف');
                    return;
                }
                loadBundles();
                loadMaps();
            };
        });
        return bundles;
    }

    async function loadMaps() {
        var res = await api({ action: 'list_maps' });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'خطأ تحميل الربط');
            return;
        }
        var maps = res.maps || [];
        var tb = document.getElementById('aslb_map_rows');
        tb.innerHTML = '';
        for (var i = 0; i < maps.length; i++) {
            var m = maps[i];
            var tr = document.createElement('tr');
            var cons = (m.consumer_ar || m.consumer_en || '');
            var bun = (m.bundle_ar || m.bundle_en || '');
            var cid = parseInt(m.consumer_size_family_id, 10) || 0;
            tr.innerHTML =
                '<td>' + esc(cons) + '</td>' +
                '<td>' + esc(bun) + '</td>' +
                '<td>' + esc(m.updated_at || '') + '</td>' +
                '<td><button type="button" class="btn-secondary aslb-sync-one" data-consumer="' + cid + '">مزامنة</button> ' +
                '<button type="button" class="btn-secondary aslb-unmap" data-consumer="' + cid + '">إزالة ربط</button></td>';
            tb.appendChild(tr);
        }
        tb.querySelectorAll('.aslb-sync-one').forEach(function (btn) {
            btn.onclick = async function () {
                var cid = parseInt(btn.getAttribute('data-consumer'), 10) || 0;
                if (!confirm('نسخ الأدلة من عائلة مصدر الحزمة إلى «' + famLabel(cid) + '»؟ سيُستبدل دليل علوي/سفلي/مفرد الموجود على العائلة المستهدفة إن وُجد.')) return;
                var r2 = await api({ action: 'sync_consumer', consumer_size_family_id: cid });
                if (!r2 || !r2.success) {
                    alert((r2 && r2.message) ? r2.message : 'فشل');
                    return;
                }
                alert(r2.message || 'تم');
            };
        });
        tb.querySelectorAll('.aslb-unmap').forEach(function (btn) {
            btn.onclick = async function () {
                var cid = parseInt(btn.getAttribute('data-consumer'), 10) || 0;
                if (!confirm('إزالة ربط العائلة؟')) return;
                var r2 = await api({ action: 'delete_map', consumer_size_family_id: cid });
                if (!r2 || !r2.success) {
                    alert((r2 && r2.message) ? r2.message : 'فشل');
                    return;
                }
                loadMaps();
            };
        });
    }

    document.getElementById('aslb_save_bundle').onclick = async function () {
        var payload = {
            action: 'save_bundle',
            id: parseInt(document.getElementById('aslb_id').value, 10) || 0,
            name_ar: document.getElementById('aslb_name_ar').value.trim(),
            name_en: document.getElementById('aslb_name_en').value.trim(),
            commercial_kind_key: document.getElementById('aslb_ck').value.trim(),
            source_size_family_id: parseInt(document.getElementById('aslb_src').value, 10) || 0,
            sort_order: parseInt(document.getElementById('aslb_sort').value, 10) || 0,
            is_active: parseInt(document.getElementById('aslb_active').value, 10) ? 1 : 0
        };
        var res = await api(payload);
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل الحفظ');
            return;
        }
        alert('تم الحفظ');
        document.getElementById('aslb_id').value = String(res.id || 0);
        loadBundles();
    };

    document.getElementById('aslb_clear_bundle').onclick = function () {
        document.getElementById('aslb_id').value = '0';
        document.getElementById('aslb_sort').value = '0';
        document.getElementById('aslb_active').value = '1';
        document.getElementById('aslb_name_ar').value = '';
        document.getElementById('aslb_name_en').value = '';
        document.getElementById('aslb_ck').value = '';
        document.getElementById('aslb_src').value = '0';
    };

    document.getElementById('aslb_save_map').onclick = async function () {
        var c = parseInt(document.getElementById('aslb_map_consumer').value, 10) || 0;
        var b = parseInt(document.getElementById('aslb_map_bundle').value, 10) || 0;
        var res = await api({ action: 'save_map', consumer_size_family_id: c, library_bundle_id: b });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل');
            return;
        }
        alert('تم حفظ الربط');
        loadMaps();
    };

    document.getElementById('aslb_sync').onclick = async function () {
        var c = parseInt(document.getElementById('aslb_map_consumer').value, 10) || 0;
        if (c <= 0) {
            alert('اختر عائلة مستهلك');
            return;
        }
        if (!confirm('مزامنة الأدلة إلى هذه العائلة؟')) return;
        var res = await api({ action: 'sync_consumer', consumer_size_family_id: c });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل');
            return;
        }
        alert(res.message || 'تم');
    };

    loadBundles();
    loadMaps();
})();
</script>

<?php endif; ?>

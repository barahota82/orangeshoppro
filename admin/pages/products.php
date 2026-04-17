<?php
$pdo = db();
$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query(
    'SELECT p.*, c.name_ar AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.id DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$colors = $pdo->query(
    'SELECT id, name_ar, name_en FROM color_dictionary WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$families = $pdo->query('SELECT * FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$famSizes = $pdo->query(
    'SELECT * FROM size_family_sizes WHERE is_active = 1 ORDER BY size_family_id ASC, sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$familiesOut = [];
foreach ($families as $f) {
    $fid = (int)$f['id'];
    $f['sizes'] = [];
    foreach ($famSizes as $sz) {
        if ((int)$sz['size_family_id'] === $fid) {
            $f['sizes'][] = $sz;
        }
    }
    $familiesOut[] = $f;
}
?>
<div class="page-title">
    <h1>المنتجات</h1>
</div>

<div class="card" style="margin-bottom:12px;">
    <p style="margin:0;">قبل إضافة منتج بمقاسات: عرّف <a href="/admin/index.php?page=size_families">عائلات المقاسات</a>.
        قبل الألوان: <a href="/admin/index.php?page=color_dictionary">قاموس الألوان</a>.</p>
</div>

<div class="card">
    <h3>إضافة منتج</h3>
    <form id="productForm">
        <div class="form-grid">
            <div>
                <label>اسم المنتج</label>
                <input type="text" id="name" required>
            </div>
            <div>
                <label>الفئة</label>
                <select id="category_id" required>
                    <option value="">اختر الفئة</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name_ar'] ?: $cat['name_en']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>السعر</label>
                <input type="number" id="price" step="0.01" required>
            </div>
            <div>
                <label>التكلفة</label>
                <input type="number" id="cost" step="0.01" required>
            </div>
            <div style="grid-column:1/-1;">
                <label>الوصف</label>
                <textarea id="description"></textarea>
            </div>
            <div>
                <label>الصورة الرئيسية (اسم الملف)</label>
                <input type="text" id="main_image" placeholder="example.jpg">
            </div>
        </div>

        <div class="form-grid">
            <div>
                <label>له مقاسات؟</label>
                <select id="has_sizes" onchange="onHasFlagsChange()">
                    <option value="0">لا</option>
                    <option value="1">نعم</option>
                </select>
            </div>
            <div>
                <label>له ألوان؟</label>
                <select id="has_colors" onchange="onHasFlagsChange()">
                    <option value="0">لا</option>
                    <option value="1">نعم</option>
                </select>
            </div>
            <div>
                <label>عائلة المقاسات</label>
                <select id="size_family_id" disabled>
                    <option value="">—</option>
                    <?php foreach ($familiesOut as $f): ?>
                        <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>دليل المقاس الاسترشادي (عرض)</label>
                <select id="sizing_guide_scope">
                    <option value="none">بدون</option>
                    <option value="upper">علوي</option>
                    <option value="lower">سفلي</option>
                    <option value="both">علوي وسفلي</option>
                </select>
            </div>
        </div>

        <div id="colorwaysSection" class="card" style="display:none;margin:14px 0;padding:12px;">
            <h4 style="margin-top:0;">Colorways (لون أساسي / ثانوي اختياري)</h4>
            <div id="colorwaysBox"></div>
            <button type="button" class="btn-secondary" onclick="addColorwayRow()">+ صف لون</button>
        </div>

        <div class="actions" style="margin:14px 0;">
            <button type="button" onclick="generateVariants()">توليد المتغيرات</button>
            <button type="button" class="btn-secondary" onclick="saveProduct()">حفظ المنتج</button>
        </div>

        <div id="variantsBox"></div>
    </form>
</div>

<div class="card">
    <h3>قائمة المنتجات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الفئة</th>
                    <th>دليل مقاس</th>
                    <th>السعر</th>
                    <th>التكلفة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?php echo (int)$p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($p['category_name'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars((string)($p['sizing_guide_scope'] ?? 'none')); ?></td>
                    <td><?php echo number_format((float)$p['price'], 2); ?></td>
                    <td><?php echo number_format((float)$p['cost'], 2); ?></td>
                    <td><?php echo (int)$p['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.ORANGE_COLORS = <?php echo json_encode($colors, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_FAMILIES = <?php echo json_encode($familiesOut, JSON_UNESCAPED_UNICODE); ?>;

function onHasFlagsChange() {
    const hs = document.getElementById('has_sizes').value === '1';
    const hc = document.getElementById('has_colors').value === '1';
    document.getElementById('size_family_id').disabled = !hs;
    document.getElementById('colorwaysSection').style.display = hc ? 'block' : 'none';
    if (hc && !document.querySelector('#colorwaysBox .cw-row')) {
        addColorwayRow();
    }
}

function colorOptionsHtml() {
    let h = '<option value="0">—</option>';
    (window.ORANGE_COLORS || []).forEach(c => {
        const t = (c.name_ar || c.name_en || '').replace(/</g,'');
        h += `<option value="${c.id}">${t}</option>`;
    });
    return h;
}

function addColorwayRow() {
    const box = document.getElementById('colorwaysBox');
    const div = document.createElement('div');
    div.className = 'cw-row form-grid';
    div.style.marginBottom = '8px';
    div.innerHTML = `
        <div><label>أساسي</label><select class="cw-p">${colorOptionsHtml()}</select></div>
        <div><label>ثانوي (اختياري)</label><select class="cw-s">${colorOptionsHtml()}</select></div>
    `;
    box.appendChild(div);
}

function sizesForFamily(fid) {
    const fam = (window.ORANGE_FAMILIES || []).find(f => String(f.id) === String(fid));
    return fam && fam.sizes ? fam.sizes : [];
}

function generateVariants() {
    const hasC = document.getElementById('has_colors').value === '1';
    const hasS = document.getElementById('has_sizes').value === '1';
    const famId = parseInt(document.getElementById('size_family_id').value, 10) || 0;
    const box = document.getElementById('variantsBox');

    if (hasS && !famId) {
        alert('اختر عائلة مقاسات');
        return;
    }
    if (hasC) {
        const rows = document.querySelectorAll('#colorwaysBox .cw-row');
        if (!rows.length) {
            alert('أضف صف لون واحد على الأقل');
            return;
        }
    }

    let sizes = [{ id: 0, label_ar: '', label_en: '' }];
    if (hasS) {
        sizes = sizesForFamily(famId);
        if (!sizes.length) {
            alert('لا توجد مقاسات في العائلة المختارة');
            return;
        }
    }

    let combos = [];
    if (!hasC && !hasS) {
        combos.push({ primary_color_id: 0, secondary_color_id: 0, size_family_size_id: 0, stock: 0 });
    } else if (hasC && hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            if (!p) {
                return;
            }
            sizes.forEach(sz => {
                combos.push({ primary_color_id: p, secondary_color_id: s, size_family_size_id: sz.id, stock: 0 });
            });
        });
    } else if (hasC && !hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            if (!p) return;
            combos.push({ primary_color_id: p, secondary_color_id: s, size_family_size_id: 0, stock: 0 });
        });
    } else if (!hasC && hasS) {
        sizes.forEach(sz => {
            combos.push({ primary_color_id: 0, secondary_color_id: 0, size_family_size_id: sz.id, stock: 0 });
        });
    }

    if (!combos.length) {
        alert('لا توجد تركيبات');
        return;
    }

    let html = '<h4>المتغيرات والمخزون</h4><div class="table-wrap"><table><thead><tr><th>لون (عرض)</th><th>مقاس</th><th>المخزون</th></tr></thead><tbody>';
    combos.forEach((c, idx) => {
        const sz = sizes.find(x => String(x.id) === String(c.size_family_size_id));
        const szLabel = sz ? (sz.label_ar || sz.label_en || ('#' + sz.id)) : '-';
        const p = (window.ORANGE_COLORS || []).find(x => String(x.id) === String(c.primary_color_id));
        const s = (window.ORANGE_COLORS || []).find(x => String(x.id) === String(c.secondary_color_id));
        let colorLabel = '';
        if (p) colorLabel += (p.name_ar || p.name_en);
        if (s) colorLabel += (colorLabel ? ' / ' : '') + (s.name_ar || s.name_en);
        if (!colorLabel) colorLabel = '-';
        html += `<tr>
            <td>${colorLabel}<input type="hidden" class="v-p" value="${c.primary_color_id}"><input type="hidden" class="v-s" value="${c.secondary_color_id}"></td>
            <td>${szLabel}<input type="hidden" class="v-zid" value="${c.size_family_size_id}"></td>
            <td><input type="number" class="v-stock" min="0" value="0" data-idx="${idx}"></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
}

async function saveProduct() {
    const rows = Array.from(document.querySelectorAll('#variantsBox tbody tr'));
    if (!rows.length) {
        alert('ولّد المتغيرات أولاً');
        return;
    }

    const variants = rows.map(tr => ({
        primary_color_id: parseInt(tr.querySelector('.v-p').value, 10) || 0,
        secondary_color_id: parseInt(tr.querySelector('.v-s').value, 10) || 0,
        size_family_size_id: parseInt(tr.querySelector('.v-zid').value, 10) || 0,
        stock_quantity: parseInt(tr.querySelector('.v-stock').value || '0', 10)
    }));

    const payload = {
        name: document.getElementById('name').value.trim(),
        description: document.getElementById('description').value.trim(),
        category_id: parseInt(document.getElementById('category_id').value, 10),
        price: parseFloat(document.getElementById('price').value || '0'),
        cost: parseFloat(document.getElementById('cost').value || '0'),
        main_image: document.getElementById('main_image').value.trim(),
        has_sizes: parseInt(document.getElementById('has_sizes').value, 10),
        has_colors: parseInt(document.getElementById('has_colors').value, 10),
        size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
        sizing_guide_scope: document.getElementById('sizing_guide_scope').value,
        variants
    };

    const res = await postJSON('/admin/api/products/create.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) location.reload();
}

onHasFlagsChange();
</script>

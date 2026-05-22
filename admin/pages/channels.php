<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$countriesList = orange_countries_admin_list($pdo);
$defaultCountryId = orange_countries_default_id($pdo);
$channelsCountryId = orange_admin_context_country_id($pdo);
$channelsCountrySql = orange_sql_country_and_fragment($pdo, 'channels', 'c', $channelsCountryId);
if (orange_channels_has_country_column($pdo) && orange_table_exists($pdo, 'countries')) {
    $channels = $pdo->query(
        'SELECT c.*, co.code AS country_code, co.name_ar AS country_name_ar
         FROM channels c
         LEFT JOIN countries co ON co.id = c.country_id
         WHERE 1=1' . $channelsCountrySql . '
         ORDER BY c.country_id ASC, c.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $channels = $pdo->query('SELECT * FROM channels ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$channelKinds = orange_channel_kinds_allowed();

$channelFormDefaultCountryId = $channelsCountryId > 0 ? $channelsCountryId : $defaultCountryId;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
foreach ($channels as $c) {
    if ($editId > 0 && (int) $c['id'] === $editId) {
        $editRow = $c;
        break;
    }
}
$pubBase = PUBLIC_BASE_PATH === '' ? '' : PUBLIC_BASE_PATH;
$initialLogo = $editRow ? trim((string) ($editRow['logo'] ?? '')) : '';
$editIsActive = $editRow ? (int) ($editRow['is_active'] ?? 1) : 1;
$editIsCountryDefault = $editRow ? (int) ($editRow['is_country_default'] ?? 0) : 0;
$hasCountryDefaultCol = orange_channels_has_country_default_column($pdo);
?>
<div class="page-title">
    <h1>الواجهات (قنوات العملاء)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">كل قناة تمثّل واجهة أو مصدراً لتجميع <strong>العملاء وطلباتهم</strong>. المخزون والمبيعات المحاسبية <strong>للشركة موحّدة</strong> — الطلب من أي قناة يسحب من نفس المخزن الرئيسي.</p>
    <p style="margin:0.6rem 0 0;"><a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=channel_analytics'), ENT_QUOTES, 'UTF-8'); ?>">تحليل أداء القنوات (مبيعات، أكثر منتج، ترتيب النشاط)</a></p>
    <p class="card-hint" style="margin:0.75rem 0 0;">روابط <strong>معاينة الواجهة</strong> تمرّ بصفحة أدمن ثم تفتح المتجر <strong>بدون إظهار سر في عنوان المتجر</strong> (جلسة معاينة ~15 دقيقة). اختياري: <code dir="ltr">ORANGE_STOREFRONT_PREVIEW_TOKEN</code> في <code dir="ltr">.env.php</code> لروابط قديمة بـ <code dir="ltr">?sf_preview=</code>.</p>
</div>

<div class="card">
    <h3><?php echo $editRow ? 'تعديل واجهة' : 'إضافة واجهة'; ?></h3>
    <p class="card-hint" style="margin:0 0 0.75rem;">اختصار الرابط يظهر في عنوان الموقع مثل <code>/tiktok</code> — عند <strong>تغييره</strong> يُحدَّث تلقائياً الـ <strong>slug الداخلي</strong> (لـ <code>?channel=</code> والكوكي). اسم الواجهة فقط لا يغيّر الـ slug.</p>
    <p class="card-hint" style="margin:0 0 0.75rem;"><strong>حالة الظهور:</strong> الواجهة <strong>غير النشطة</strong> لا يعمل لها مسار الاختصار العام ولا تُقبل في كوكي/خرائط المتجر للزوار. رابط <strong>معاينة الواجهة</strong> من الجدول يفتحها للمراجعة (بعد تسجيل دخول الأدمن) حتى وهي متوقفة.</p>
    <?php if ($hasCountryDefaultCol): ?>
    <p class="card-hint" style="margin:0 0 0.75rem;"><strong>القناة الرئيسية للدولة:</strong> يُحوَّل الزائر الذي يفتح جذر الموقع (<code dir="ltr">/</code>) من دولة نشطة تلقائياً إلى هذه القناة (حسب موقعه الجغرافي). قناة رئيسية واحدة لكل دولة.</p>
    <?php endif; ?>
    <input type="hidden" id="channel_id" value="<?php echo $editRow ? (int) $editRow['id'] : ''; ?>">
    <input type="hidden" id="channel_logo" value="<?php echo htmlspecialchars($initialLogo, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="form-grid">
        <div>
            <label for="channel_country_id">الدولة <span style="color:#b45309;">*</span></label>
            <select id="channel_country_id" required>
                <?php foreach ($countriesList as $co): ?>
                    <?php
                    $coId = (int) ($co['id'] ?? 0);
                    $sel = $editRow
                        ? ((int) ($editRow['country_id'] ?? 0) === $coId)
                        : ($coId === $channelFormDefaultCountryId);
                    ?>
                <option value="<?php echo $coId; ?>"<?php echo $sel ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) ($co['name_ar'] ?? '') . ' (' . (string) ($co['code'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="channel_kind">نوع القناة</label>
            <select id="channel_kind">
                <?php
                $editKind = $editRow ? orange_channel_kind_normalize((string) ($editRow['channel_kind'] ?? 'other')) : 'other';
                foreach ($channelKinds as $k):
                    $kLabel = $k === 'web' ? 'ويب' : ($k === 'whatsapp' ? 'واتساب' : 'أخرى');
                    ?>
                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $editKind === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($kLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>اسم الواجهة</label>
            <input type="text" id="channel_name" placeholder="مثال: متجر إنستغرام" value="<?php echo $editRow ? htmlspecialchars((string) $editRow['name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label>اختصار الرابط (بالإنجليزية)</label>
            <input type="text" id="channel_path_segment" placeholder="مثل: instagram أو sale" dir="ltr" lang="en" autocomplete="off" value="<?php echo $editRow ? htmlspecialchars((string) ($editRow['path_segment'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label>الشعار (رفع من الجهاز)</label>
            <input type="file" id="channel_logo_file" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="card-hint" style="margin:0.35rem 0 0;">jpeg أو png أو webp أو gif — حتى 4 ميجا. بعد الرفع يُحفظ اسم الملف مع الواجهة عند الضغط على «حفظ».</p>
            <div id="channel_logo_preview_wrap" style="margin-top:0.5rem;display:none;">
                <img id="channel_logo_preview" alt="" style="max-height:72px;max-width:200px;object-fit:contain;border-radius:6px;border:1px solid #e5e7eb;">
            </div>
        </div>
        <div>
            <label>رقم الواتساب</label>
            <input type="text" id="channel_whatsapp" value="<?php echo $editRow ? htmlspecialchars((string) ($editRow['whatsapp_number'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label>ظهور الواجهة على الإنترنت</label>
            <select id="channel_is_active">
                <option value="1" <?php echo $editIsActive === 1 ? ' selected' : ''; ?>>نشط — مسار الاختصار يعمل للجميع</option>
                <option value="0" <?php echo $editIsActive === 0 ? ' selected' : ''; ?>>متوقف — مخفي عن الزوار</option>
            </select>
        </div>
        <?php if ($hasCountryDefaultCol): ?>
        <div>
            <label for="channel_is_country_default">قناة رئيسية للدولة</label>
            <select id="channel_is_country_default">
                <option value="0" <?php echo $editIsCountryDefault === 0 ? ' selected' : ''; ?>>لا</option>
                <option value="1" <?php echo $editIsCountryDefault === 1 ? ' selected' : ''; ?>>نعم — تحويل الزائر من / لهذه القناة</option>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <button type="button" onclick="saveChannel()"><?php echo $editRow ? 'حفظ التعديلات' : 'حفظ الواجهة'; ?></button>
        <?php if ($editRow): ?>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=channels'), ENT_QUOTES, 'UTF-8'); ?>">إلغاء التعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>قائمة الواجهات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الدولة</th>
                    <th>النوع</th>
                    <th>الاسم</th>
                    <th>اختصار URL</th>
                    <th>Slug داخلي</th>
                    <?php if ($hasCountryDefaultCol): ?><th>رئيسية</th><?php endif; ?>
                    <th>الواتساب</th>
                    <th>معاينة الواجهة</th>
                    <th>الحالة</th>
                    <th>إخفاء / تفعيل</th>
                    <th>تعديل</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($channels as $ch): ?>
                <tr>
                    <td><?php echo (int) $ch['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) ($ch['country_name_ar'] ?? $ch['country_code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php
                    $k = orange_channel_kind_normalize((string) ($ch['channel_kind'] ?? 'other'));
                    echo $k === 'web' ? 'ويب' : ($k === 'whatsapp' ? 'واتساب' : 'أخرى');
                    ?></td>
                    <td><?php echo htmlspecialchars($ch['name']); ?></td>
                    <td><code dir="ltr"><?php echo htmlspecialchars((string) ($ch['path_segment'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><code dir="ltr"><?php echo htmlspecialchars($ch['slug']); ?></code></td>
                    <?php if ($hasCountryDefaultCol): ?>
                    <td><?php echo (int) ($ch['is_country_default'] ?? 0) === 1 ? 'نعم' : '—'; ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($ch['whatsapp_number']); ?></td>
                    <td dir="ltr"><?php
                    $ps = trim((string) ($ch['path_segment'] ?? ''));
                    if ($ps === '') {
                        echo '—';
                    } else {
                        $prevUrl = orange_storefront_admin_preview_home_url($ps);
                        echo $prevUrl !== ''
                            ? '<a href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">فتح الرئيسية</a>'
                            : '—';
                    }
                    ?></td>
                    <td><?php echo (int) $ch['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                    <td>
                        <button type="button" class="btn btn-secondary" style="font-size:0.85rem;padding:0.25rem 0.5rem;" onclick="toggleChannelActive(<?php echo (int) $ch['id']; ?>, <?php echo (int) $ch['is_active']; ?>)"><?php echo (int) $ch['is_active'] === 1 ? 'إخفاء' : 'تفعيل'; ?></button>
                    </td>
                    <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=channels&edit=' . (int) $ch['id']), ENT_QUOTES, 'UTF-8'); ?>">تعديل</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var PUB = <?php echo json_encode($pubBase, JSON_UNESCAPED_UNICODE); ?>;

    function setLogoPreview(filename) {
        var wrap = document.getElementById('channel_logo_preview_wrap');
        var img = document.getElementById('channel_logo_preview');
        if (!wrap || !img) return;
        if (!filename) {
            wrap.style.display = 'none';
            img.removeAttribute('src');
            return;
        }
        wrap.style.display = '';
        img.src = (PUB || '') + '/uploads/channels/' + encodeURIComponent(filename);
    }

    window.setLogoPreview = setLogoPreview;

    var logoFile = document.getElementById('channel_logo_file');
    if (logoFile) {
        logoFile.addEventListener('change', async function () {
            var f = logoFile.files && logoFile.files[0];
            if (!f) return;
            var fd = new FormData();
            fd.append('image', f);
            var r;
            try {
                r = await fetch('/admin/api/channels/upload-logo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            } catch (e) {
                alert('تعذر الاتصال بالسيرفر');
                logoFile.value = '';
                return;
            }
            var text = await r.text();
            var data;
            try {
                data = JSON.parse(text);
            } catch (e2) {
                alert('رد السيرفر غير صالح');
                logoFile.value = '';
                return;
            }
            if (!data.success) {
                alert(data.message || 'فشل الرفع');
                logoFile.value = '';
                return;
            }
            var fn = data.filename || '';
            document.getElementById('channel_logo').value = fn;
            setLogoPreview(fn);
            logoFile.value = '';
        });
    }

    var initLogo = <?php echo json_encode($initialLogo, JSON_UNESCAPED_UNICODE); ?>;
    if (initLogo) {
        setLogoPreview(initLogo);
    }
})();

async function toggleChannelActive(id, currentlyActive) {
    var next = currentlyActive ? 0 : 1;
    var res = await postJSON('/admin/api/channels/toggle-active.php', { id: id, is_active: next });
    alert(res.message || (res.success ? 'تم' : 'فشل التحديث'));
    if (res.success) location.reload();
}

async function saveChannel() {
    var idEl = document.getElementById('channel_id');
    var actSel = document.getElementById('channel_is_active');
    var payload = {
        name: document.getElementById('channel_name').value.trim(),
        path_segment: document.getElementById('channel_path_segment').value.trim(),
        logo: document.getElementById('channel_logo').value.trim(),
        whatsapp_number: document.getElementById('channel_whatsapp').value.trim(),
        is_active: actSel && actSel.value === '0' ? 0 : 1,
        country_id: parseInt((document.getElementById('channel_country_id') || {}).value, 10) || 0,
        channel_kind: (document.getElementById('channel_kind') || {}).value || 'other'
    };
    var defEl = document.getElementById('channel_is_country_default');
    if (defEl) {
        payload.is_country_default = defEl.value === '1' ? 1 : 0;
    }
    if (idEl && idEl.value) {
        var n = parseInt(idEl.value, 10);
        if (n > 0) payload.id = n;
    }
    var res = await postJSON('/admin/api/channels/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ الواجهة' : 'فشل الحفظ'));
    if (res.success) location.reload();
}
</script>

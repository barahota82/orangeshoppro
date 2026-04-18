<?php
$pdo = db();
$channels = $pdo->query("SELECT * FROM channels ORDER BY id ASC")->fetchAll();
?>
<div class="page-title">
    <h1>الواجهات</h1>
</div>

<div class="card">
    <h3>إضافة واجهة</h3>
    <div class="form-grid">
        <div>
            <label>الاسم</label>
            <input type="text" id="channel_name">
        </div>
        <div>
            <label>Slug</label>
            <input type="text" id="channel_slug">
        </div>
        <div>
            <label>الشعار (اسم الملف)</label>
            <input type="text" id="channel_logo">
        </div>
        <div>
            <label>اللون الأساسي</label>
            <input type="text" id="channel_color" placeholder="#ff6600">
        </div>
        <div>
            <label>رقم الواتساب</label>
            <input type="text" id="channel_whatsapp">
        </div>
        <div>
            <label>رقم المخزن (1–3)</label>
            <input type="number" id="channel_warehouse" class="admin-inp-qty" min="1" max="3" step="1" value="1" inputmode="numeric" lang="en" dir="ltr">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" onclick="saveChannel()">حفظ الواجهة</button>
    </div>
</div>

<div class="card">
    <h3>قائمة الواجهات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>Slug</th>
                    <th>اللون</th>
                    <th>الواتساب</th>
                    <th>المخزن</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($channels as $ch): ?>
                <tr>
                    <td><?php echo (int)$ch['id']; ?></td>
                    <td><?php echo htmlspecialchars($ch['name']); ?></td>
                    <td><?php echo htmlspecialchars($ch['slug']); ?></td>
                    <td><?php echo htmlspecialchars($ch['primary_color']); ?></td>
                    <td><?php echo htmlspecialchars($ch['whatsapp_number']); ?></td>
                    <td><?php echo (int)($ch['warehouse_number'] ?? 1); ?></td>
                    <td><?php echo (int)$ch['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function saveChannel() {
    let wh = parseInt(document.getElementById('channel_warehouse').value || '1', 10);
    if (wh < 1) wh = 1;
    if (wh > 3) wh = 3;
    const payload = {
        name: document.getElementById('channel_name').value.trim(),
        slug: document.getElementById('channel_slug').value.trim(),
        logo: document.getElementById('channel_logo').value.trim(),
        primary_color: document.getElementById('channel_color').value.trim(),
        whatsapp_number: document.getElementById('channel_whatsapp').value.trim(),
        warehouse_number: wh
    };
    const res = await postJSON('/admin/api/channels/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ الواجهة' : 'فشل الحفظ'));
    if (res.success) location.reload();
}
</script>

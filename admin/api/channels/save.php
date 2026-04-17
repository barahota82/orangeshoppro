<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

function channels_ensure_warehouse_column(PDO $pdo): void {
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM channels LIKE 'warehouse_number'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec("ALTER TABLE channels ADD COLUMN warehouse_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER whatsapp_number");
        }
    } catch (Throwable $e) {
        // ignore if concurrent migration
    }
}

try {
    $pdo = db();
    channels_ensure_warehouse_column($pdo);

    $data = get_json_input();

    if (empty($data['name']) || empty($data['slug']) || empty($data['whatsapp_number'])) {
        json_response(['success' => false, 'message' => 'بيانات الواجهة مطلوبة'], 422);
    }

    $wh = (int)($data['warehouse_number'] ?? 1);
    if ($wh < 1) {
        $wh = 1;
    }
    if ($wh > 3) {
        $wh = 3;
    }

    $stmt = $pdo->prepare("
        INSERT INTO channels (name, slug, logo, primary_color, whatsapp_number, warehouse_number, is_active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        trim((string)$data['name']),
        trim((string)$data['slug']),
        trim((string)($data['logo'] ?? '')),
        trim((string)($data['primary_color'] ?? '')),
        trim((string)$data['whatsapp_number']),
        $wh
    ]);

    json_response(['success' => true, 'message' => 'تم حفظ الواجهة']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}

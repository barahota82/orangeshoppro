<?php

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

function req_data(): array
{
    $data = get_json_input();
    if (is_array($data) && count($data) > 0) {
        return $data;
    }

    return $_POST;
}

/** @param mixed $v */
function hero_line_str($v): string
{
    $s = trim((string) $v);

    return mb_substr($s, 0, 500, 'UTF-8');
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = req_data();
    $action = trim((string) ($data['action'] ?? 'get'));

    if ($action === 'get') {
        if (!orange_table_exists($pdo, 'storefront_home_hero')) {
            json_response(['success' => false, 'message' => 'جدول storefront_home_hero غير جاهز'], 422);
        }
        $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->exec('INSERT INTO storefront_home_hero (id) VALUES (1)');
            $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        }
        json_response(['success' => true, 'data' => $row ?: []]);
    }

    if ($action === 'save') {
        $fields = [
            'line_1_ar', 'line_1_en', 'line_1_fil', 'line_1_hi',
            'line_2_ar', 'line_2_en', 'line_2_fil', 'line_2_hi',
            'line_3_ar', 'line_3_en', 'line_3_fil', 'line_3_hi',
        ];
        $vals = [];
        foreach ($fields as $f) {
            $vals[$f] = hero_line_str($data[$f] ?? '');
        }

        if (!orange_table_exists($pdo, 'storefront_home_hero')) {
            json_response(['success' => false, 'message' => 'جدول storefront_home_hero غير جاهز'], 422);
        }

        $exists = $pdo->query('SELECT id FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetchColumn();
        if ($exists) {
            $sql = 'UPDATE storefront_home_hero SET '
                . implode(', ', array_map(static fn (string $f): string => $f . ' = ?', $fields))
                . ' WHERE id = 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($vals));
        } else {
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $cols = implode(', ', $fields);
            $stmt = $pdo->prepare("INSERT INTO storefront_home_hero (id, {$cols}) VALUES (1, {$placeholders})");
            $stmt->execute(array_values($vals));
        }

        json_response(['success' => true, 'message' => 'تم حفظ نصوص البانر']);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ نصوص البانر');
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'size_scheme_templates')
        || !orange_table_exists($pdo, 'size_scheme_template_sizes')
    ) {
        json_response(['success' => false, 'message' => 'جداول قوالب المقاسات غير جاهزة؛ حدّث المخطّط بزيارة الأدمن.'], 503);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT t.id, t.name_ar, t.name_en, t.sort_order, t.is_active,
                    (SELECT COUNT(*) FROM size_scheme_template_sizes s WHERE s.template_id = t.id) AS sizes_count
                 FROM size_scheme_templates t
                 ORDER BY t.sort_order ASC, t.id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'templates' => is_array($rows) ? $rows : []]);

        case 'get':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف القالب غير صالح'], 422);
            }
            $st = $pdo->prepare('SELECT * FROM size_scheme_templates WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) {
                json_response(['success' => false, 'message' => 'القالب غير موجود'], 404);
            }
            $st2 = $pdo->prepare(
                'SELECT id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm
                 FROM size_scheme_template_sizes
                 WHERE template_id = ?
                 ORDER BY sort_order ASC, id ASC'
            );
            $st2->execute([$id]);
            $sizes = $st2->fetchAll(PDO::FETCH_ASSOC);
            json_response([
                'success' => true,
                'template' => $tpl,
                'sizes' => is_array($sizes) ? $sizes : [],
            ]);

        case 'save':
            $id = (int) ($data['id'] ?? 0);
            $nameAr = trim((string) ($data['name_ar'] ?? ''));
            $nameEn = trim((string) ($data['name_en'] ?? ''));
            $nameFil = trim((string) ($data['name_fil'] ?? ''));
            $nameHi = trim((string) ($data['name_hi'] ?? ''));
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
            $sizesIn = $data['sizes'] ?? [];
            if (!is_array($sizesIn)) {
                $sizesIn = [];
            }
            if ($nameAr === '' || $nameEn === '') {
                json_response(['success' => false, 'message' => 'عبّئ اسم القالب عربي وEnglish'], 422);
            }

            $normalized = [];
            foreach ($sizesIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $la = trim((string) ($row['label_ar'] ?? ''));
                $le = trim((string) ($row['label_en'] ?? ''));
                if ($la === '' && $le === '') {
                    continue;
                }
                if ($la === '') {
                    json_response(['success' => false, 'message' => 'كل صف في القالب يجب أن يحتوي على تسمية عربية للمقاس'], 422);
                }
                $lf = trim((string) ($row['label_fil'] ?? ''));
                $lh = trim((string) ($row['label_hi'] ?? ''));
                $so = (int) ($row['sort_order'] ?? 0);
                $flRaw = $row['foot_length_cm'] ?? null;
                $fl = null;
                if ($flRaw !== null && $flRaw !== '') {
                    $fl = is_numeric($flRaw) ? (float) $flRaw : null;
                }
                $normalized[] = [
                    'label_ar' => $la,
                    'label_en' => $le,
                    'label_fil' => $lf,
                    'label_hi' => $lh,
                    'sort_order' => $so,
                    'foot_length_cm' => $fl,
                ];
            }

            if ($normalized === []) {
                json_response(['success' => false, 'message' => 'أضف صف مقاس واحد على الأقل داخل القالب'], 422);
            }

            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    $tplId = $id;
                    $chk = $pdo->prepare('SELECT id FROM size_scheme_templates WHERE id = ? LIMIT 1');
                    $chk->execute([$tplId]);
                    if (!$chk->fetch()) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'القالب غير موجود'], 404);
                    }
                    $pdo->prepare(
                        'UPDATE size_scheme_templates SET name_ar=?, name_en=?, name_fil=?, name_hi=?, is_active=? WHERE id=? LIMIT 1'
                    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $active, $tplId]);
                } else {
                    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_scheme_templates')->fetchColumn();
                    if ($sort <= 0) {
                        $sort = 1;
                    }
                    $pdo->prepare(
                        'INSERT INTO size_scheme_templates (name_ar, name_en, name_fil, name_hi, sort_order, is_active) VALUES (?,?,?,?,?,?)'
                    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $sort, $active]);
                    $tplId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM size_scheme_template_sizes WHERE template_id = ?')->execute([$tplId]);
                $ins = $pdo->prepare(
                    'INSERT INTO size_scheme_template_sizes
                        (template_id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm, is_active)
                     VALUES (?,?,?,?,?,?,?,1)'
                );
                foreach ($normalized as $i => $r) {
                    $so = $r['sort_order'] > 0 ? $r['sort_order'] : ($i + 1);
                    $ins->execute([
                        $tplId,
                        $r['label_ar'],
                        $r['label_en'],
                        $r['label_fil'],
                        $r['label_hi'],
                        $so,
                        $r['foot_length_cm'],
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_response(['success' => true, 'id' => $tplId]);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف القالب غير صالح'], 422);
            }
            $pdo->prepare('DELETE FROM size_scheme_template_sizes WHERE template_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM size_scheme_templates WHERE id = ? LIMIT 1')->execute([$id]);
            json_response(['success' => true]);

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ طلب قوالب المقاسات');
}

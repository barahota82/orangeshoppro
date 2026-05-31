<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/year_end_close.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'get') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف السند مطلوب'], 422);
        }
        if (! orange_journal_vouchers_ready($pdo)) {
            json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 422);
        }
        $st = $pdo->prepare(
            "SELECT * FROM journal_vouchers WHERE id = ? AND entry_type = 'year_end_close' LIMIT 1"
        );
        $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (! $v) {
            json_response(['success' => false, 'message' => 'سند YEC غير موجود'], 404);
        }
        orange_admin_assert_entity_country($pdo, 'journal_vouchers', $id);

        $phaseSel = orange_table_has_column($pdo, 'journal_lines', 'yec_phase')
            ? ', jl.yec_phase'
            : ', NULL AS yec_phase';
        $lst = $pdo->prepare(
            'SELECT jl.account_id, jl.debit, jl.credit, jl.memo' . $phaseSel . ', a.code, a.name
             FROM journal_lines jl
             INNER JOIN accounts a ON a.id = jl.account_id
             WHERE jl.voucher_id = ?
             ORDER BY jl.line_no ASC'
        );
        $lst->execute([$id]);
        $lines = [];
        while ($row = $lst->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = [
                'account_id' => (int) $row['account_id'],
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'debit' => (float) $row['debit'],
                'credit' => (float) $row['credit'],
                'memo' => (string) ($row['memo'] ?? ''),
                'yec_phase' => (string) ($row['yec_phase'] ?? ''),
            ];
        }

        $vd = (string) ($v['voucher_date'] ?? '');
        $locked = orange_year_end_close_yec_columns_ready($pdo)
            && (int) ($v['yec_locked'] ?? 0) === 1
            && (int) ($v['is_void'] ?? 0) === 0;
        $editable = ! $locked;

        json_response([
            'success' => true,
            'voucher' => [
                'id' => (int) $v['id'],
                'voucher_serial' => (int) ($v['voucher_serial'] ?? 0),
                'display_voucher_no' => orange_journal_voucher_display_number($v),
                'voucher_date' => strlen($vd) >= 10 ? orange_format_date_dmY(substr($vd, 0, 10)) : '',
                'date' => strlen($vd) >= 10 ? substr($vd, 0, 10) : '',
                'reference' => (string) ($v['reference'] ?? ''),
                'description' => (string) ($v['description'] ?? ''),
                'document_entered_display' => orange_format_datetime_dmY_hi(
                    (string) (($v['document_entered_at'] ?? '') !== '' ? $v['document_entered_at'] : $vd)
                ),
                'entry_type' => 'year_end_close',
                'editable' => $editable,
                'yec_locked' => $locked,
                'fiscal_year_id' => (int) ($v['fiscal_year_id'] ?? 0),
                'is_void' => (int) ($v['is_void'] ?? 0) === 1,
            ],
            'phase_labels' => orange_year_end_close_phase_labels(),
            'lines' => $lines,
        ]);
    }

    if ($action === 'finalize') {
        $id = (int) ($data['id'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $dateRaw = trim((string) ($data['date'] ?? ''));
        $dateUp = orange_normalize_admin_posted_datetime($dateRaw);
        if ($dateUp === null) {
            json_response(['success' => false, 'message' => 'تاريخ السند غير صالح'], 422);
        }
        if ($description === '') {
            json_response(['success' => false, 'message' => 'بيان السند مطلوب'], 422);
        }
        $linesIn = $data['lines'] ?? null;
        if (! is_array($linesIn) || count($linesIn) < 2) {
            json_response(['success' => false, 'message' => 'يُشترط سطران صالحان على الأقل'], 422);
        }
        $lines = [];
        foreach ($linesIn as $ln) {
            if (! is_array($ln)) {
                continue;
            }
            $aid = (int) ($ln['account_id'] ?? 0);
            $deb = (float) ($ln['debit'] ?? 0);
            $cre = (float) ($ln['credit'] ?? 0);
            $memo = trim((string) ($ln['memo'] ?? ''));
            if ($aid <= 0 || ($deb <= 0 && $cre <= 0) || $memo === '') {
                continue;
            }
            $lines[] = [
                'account_id' => $aid,
                'debit' => $deb,
                'credit' => $cre,
                'memo' => $memo,
                'yec_phase' => trim((string) ($ln['yec_phase'] ?? '')),
            ];
        }
        if (count($lines) < 2) {
            json_response(['success' => false, 'message' => 'أسطر السند غير صالحة'], 422);
        }

        try {
            $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
            orange_admin_assert_entity_country($pdo, 'journal_vouchers', $id);
            $info = orange_year_end_close_finalize($pdo, $id, $dateUp, $description, $lines, $ctxCountryId);
        } catch (InvalidArgumentException|RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
        audit_log('year_end_close_finalize', 'إقفال YEC #' . $id, 'journal_vouchers', $id);
        json_response([
            'success' => true,
            'message' => 'تم حفظ سند الإقفال وإغلاق السنة المالية',
            'id' => (int) ($info['voucher_id'] ?? $id),
            'fiscal_year_id' => (int) ($info['fiscal_year_id'] ?? 0),
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر معالجة سند الإقفال');
}

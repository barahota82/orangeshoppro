<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/payments/payment_core.php';
require_once __DIR__ . '/../../../includes/upload_paths.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_payments_ensure_schema($pdo);
    $cid = (int) orange_admin_context_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'orders', 'o', $cid);

    /* رفع إثبات (multipart) — يربطه بحركة دفع ويضع الطلب pending_review. */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            json_response(['success' => false, 'message' => 'الطلب مطلوب'], 422);
        }
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);
        if (!isset($_FILES['proof']) || (int) ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'لم يُرسل إثبات صالح'], 422);
        }
        $tmp = (string) ($_FILES['proof']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
        }
        if ((int) ($_FILES['proof']['size'] ?? 0) > 8 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'الملف كبير جداً'], 422);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
        if (!isset($allowed[$mime])) {
            json_response(['success' => false, 'message' => 'النوع غير مدعوم (صورة أو PDF)'], 422);
        }
        $dir = orange_payment_ensure_proof_dir();
        if ($dir === null) {
            json_response(['success' => false, 'message' => 'تعذر إنشاء مجلد الإثباتات (uploads/payment_proofs)'], 500);
        }
        $name = 'pay_' . $orderId . '_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
            json_response(['success' => false, 'message' => 'تعذر حفظ الإثبات'], 500);
        }
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $txnUuid = 'bank_' . $orderId . '_' . substr(md5($reference . '|' . $name), 0, 16);
        orange_payment_record_transaction($pdo, [
            'order_id' => $orderId,
            'country_id' => $cid,
            'method' => 'bank',
            'provider' => 'manual',
            'amount' => $amount,
            'currency' => orange_country_functional_currency_code($pdo, $cid),
            'status' => 'pending_review',
            'provider_ref' => $reference,
            'proof_file' => $name,
            'txn_uuid' => $txnUuid,
        ]);
        if (orange_table_has_column($pdo, 'payment_transactions', 'proof_file')) {
            $pdo->prepare('UPDATE payment_transactions SET proof_file = ? WHERE txn_uuid = ?')->execute([$name, $txnUuid]);
        }
        orange_payment_set_order_status($pdo, $orderId, 'pending_review', 'bank', $amount > 0 ? $amount : null);
        audit_log('payment_proof_upload', 'إثبات تحويل بنكي للطلب #' . $orderId, 'orders', $orderId);
        json_response(['success' => true, 'message' => 'تم رفع الإثبات — بانتظار التأكيد', 'proof_file' => $name]);
    }

    $data = get_json_input();
    $action = (string) ($data['action'] ?? $_GET['action'] ?? 'search');

    if ($action === 'search') {
        $status = trim((string) ($data['status'] ?? $_GET['status'] ?? 'pending_review'));
        $q = trim((string) ($data['q'] ?? $_GET['q'] ?? ''));
        $hasPayCol = orange_table_has_column($pdo, 'orders', 'payment_status');
        if (!$hasPayCol) {
            json_response(['success' => true, 'results' => []]);
        }
        $hasPaidCol = orange_table_has_column($pdo, 'orders', 'amount_paid');
        $hasPaidAt = orange_table_has_column($pdo, 'orders', 'paid_at');
        $hasCreatedAt = orange_table_has_column($pdo, 'orders', 'created_at');
        $sql = 'SELECT o.id, o.order_number, o.customer_name, o.phone, o.total,'
            . ($hasPaidCol ? ' o.amount_paid,' : ' 0 AS amount_paid,')
            . ($hasPaidAt ? ' o.paid_at,' : ' NULL AS paid_at,')
            . ($hasCreatedAt ? ' o.created_at,' : ' NULL AS created_at,')
            . (orange_table_has_column($pdo, 'orders', 'country_id') ? ' o.country_id,' : ' NULL AS country_id,')
            . ' o.payment_status, o.payment_method
                FROM orders o WHERE 1=1' . $countrySql;
        $params = [];
        if (in_array($status, orange_payment_statuses(), true)) {
            $sql .= ' AND o.payment_status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $sql .= ' AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY o.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['payment_status_label'] = orange_payment_status_label((string) ($r['payment_status'] ?? ''));
            $rowCid = (int) ($r['country_id'] ?? 0);
            if ($rowCid <= 0) {
                $rowCid = $cid;
            }
            // orders.created_at is DATETIME — Phase 2 stores/displays as UTC wall → country IANA.
            $r['created_at_display'] = orange_admin_time_display_mysql_utc_or_dash(
                $pdo,
                (string) ($r['created_at'] ?? ''),
                $rowCid
            );
            // orders.paid_at is TIMESTAMP (session-mediated) — not yet UTC-written via Phase 1;
            // expose raw only until Owner approves TIMESTAMP/session strategy (see ORANGE_ADMIN_TIME_POLICY).
            $r['paid_at_raw'] = (string) ($r['paid_at'] ?? '');
            $r['paid_at_display'] = $r['paid_at_raw'] !== '' ? $r['paid_at_raw'] : '—';
            $txn = $pdo->prepare(
                'SELECT id, provider_ref, proof_file, amount, created_at, status
                 FROM payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1'
            );
            $txn->execute([(int) $r['id']]);
            $t = $txn->fetch(PDO::FETCH_ASSOC) ?: [];
            $r['last_reference'] = (string) ($t['provider_ref'] ?? '');
            $r['last_txn_status'] = (string) ($t['status'] ?? '');
            // payment_transactions.created_at is TIMESTAMP — blocked for UTC rewrite this phase.
            $r['last_txn_created_at_raw'] = (string) ($t['created_at'] ?? '');
            $pf = trim((string) ($t['proof_file'] ?? ''));
            $txnId = (int) ($t['id'] ?? 0);
            if ($pf !== '' && $txnId > 0) {
                $proofParams = 'order_id=' . (int) $r['id'] . '&txn_id=' . $txnId;
                $r['proof_url'] = storefront_public_path('/admin/api/payments/proof-download.php?' . $proofParams);
            } else {
                $r['proof_url'] = '';
            }
        }
        unset($r);
        json_response(['success' => true, 'results' => $rows]);
    }

    if ($action === 'set_status') {
        $orderId = (int) ($data['order_id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        if ($orderId <= 0 || !in_array($status, ['paid', 'failed', 'pending_review', 'unpaid'], true)) {
            json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
        }
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        orange_payment_set_order_status($pdo, $orderId, $status, 'bank', $amount);
        $txnUuid = 'bankset_' . $orderId . '_' . $status . '_' . substr(md5((string) ($data['reference'] ?? '') . microtime()), 0, 12);
        orange_payment_record_transaction($pdo, [
            'order_id' => $orderId,
            'country_id' => $cid,
            'method' => 'bank',
            'provider' => 'manual',
            'amount' => $amount ?? 0,
            'currency' => orange_country_functional_currency_code($pdo, $cid),
            'status' => $status,
            'provider_ref' => trim((string) ($data['reference'] ?? '')),
            'txn_uuid' => $txnUuid,
        ]);
        /* م1: لا ترحيل GL تلقائي — قيد التحصيل عند تفعيل سياسة الحساب (راجع المخطط). */
        audit_log('payment_set_status', 'الطلب #' . $orderId . ' → ' . $status, 'orders', $orderId);
        json_response(['success' => true, 'message' => 'تم تحديث حالة الدفع: ' . orange_payment_status_label($status)]);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ العملية');
}

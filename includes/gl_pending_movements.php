<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/party_allocations.php';
require_once __DIR__ . '/countries.php';

/**
 * طابور الحركات المعلّقة — تعطيله مؤقتاً عبر ORANGE_GL_IMMEDIATE_POSTING=1 في .env.php (على السيرفر).
 */
function orange_gl_use_pending_queue(PDO $pdo): bool
{
    global $env;
    if (!is_array($env)) {
        $env = [];
    }
    $im = $env['ORANGE_GL_IMMEDIATE_POSTING'] ?? false;
    if ($im === true || $im === 1 || $im === '1' || strtolower((string) $im) === 'true') {
        return false;
    }

    return orange_table_exists($pdo, 'orange_gl_pending_movements');
}

/**
 * حذف صف طابور مرتبط بمرجع (عند إعادة كتابة أو حذف المستند).
 */
function orange_gl_pending_remove_by_reference(PDO $pdo, string $reference): void
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return;
    }
    $ref = trim($reference);
    if ($ref === '') {
        return;
    }
    $pdo->prepare('DELETE FROM orange_gl_pending_movements WHERE reference = ?')->execute([$ref]);
}

/**
 * حذف قيود تسليم الطلب المعلّقة فقط (مرجع -S- / -C-) عند إلغاء تسليم قبل الترحيل.
 */
function orange_gl_pending_remove_forward_fulfillment(PDO $pdo, string $orderNumber): void
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return;
    }
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return;
    }
    $base = 'ORDER-' . $orderNumber . '-';
    $pdo->prepare(
        "DELETE FROM orange_gl_pending_movements
         WHERE status = 'pending'
           AND (reference LIKE ? OR reference LIKE ?)"
    )->execute([$base . 'S-%', $base . 'C-%']);
}

/**
 * قيد بسيط (مدين/دائن) في الطابور. عند التكرار (INSERT IGNORE) يُعاد 0.
 *
 * @param array{
 *   reference:string,
 *   source_label?:string,
 *   movement_at?:string,
 *   voucher_date?:string,
 *   account_debit:int,
 *   account_credit:int,
 *   amount:float,
 *   description:string,
 *   entry_type?:string,
 *   after_post_json?:string|null
 * } $row
 */
function orange_gl_pending_apply_country_from_hook(array &$vh, ?string $hookJson): void
{
    if ($hookJson === null || trim($hookJson) === '') {
        return;
    }
    $decoded = json_decode($hookJson, true);
    if (!is_array($decoded)) {
        return;
    }
    if (isset($decoded['_country_id']) && (int) $decoded['_country_id'] > 0) {
        $vh['country_id'] = (int) $decoded['_country_id'];
    }
}

function orange_gl_pending_hook_country_id(?string $hookJson): int
{
    if ($hookJson === null || trim($hookJson) === '') {
        return 0;
    }
    $decoded = json_decode($hookJson, true);
    if (!is_array($decoded)) {
        return 0;
    }

    return isset($decoded['_country_id']) ? (int) $decoded['_country_id'] : 0;
}

function orange_gl_pending_row_visible_for_country(array $row, int $ctxCountryId): bool
{
    if ($ctxCountryId <= 0) {
        return true;
    }
    $hookCid = orange_gl_pending_hook_country_id(trim((string) ($row['after_post_json'] ?? '')));
    if ($hookCid <= 0) {
        return true;
    }

    return $hookCid === $ctxCountryId;
}

/**
 * @throws RuntimeException
 */
function orange_gl_pending_assert_admin_country(PDO $pdo, array $row): void
{
    require_once __DIR__ . '/countries.php';
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        return;
    }
    $hookCid = orange_gl_pending_hook_country_id(trim((string) ($row['after_post_json'] ?? '')));
    if ($hookCid > 0 && $hookCid !== $ctx) {
        throw new RuntimeException('الحركة لا تتبع الدولة المختارة في لوحة التحكم.');
    }
}

function orange_gl_after_post_json_with_country(?string $afterJson, int $countryId): ?string
{
    if ($countryId <= 0) {
        return $afterJson !== null && trim($afterJson) !== '' ? $afterJson : null;
    }
    $base = [];
    if ($afterJson !== null && trim($afterJson) !== '') {
        $decoded = json_decode($afterJson, true);
        if (is_array($decoded)) {
            $base = $decoded;
        }
    }
    $base['_country_id'] = $countryId;

    return json_encode($base, JSON_UNESCAPED_UNICODE);
}

function orange_gl_pending_enqueue_simple(PDO $pdo, array $row): int
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return 0;
    }
    $reference = trim((string) ($row['reference'] ?? ''));
    if ($reference === '') {
        throw new InvalidArgumentException('مرجع الحركة المعلّقة مطلوب.');
    }
    $description = trim((string) ($row['description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('بيان الحركة المعلّقة مطلوب.');
    }
    $debit = (int) ($row['account_debit'] ?? 0);
    $credit = (int) ($row['account_credit'] ?? 0);
    $amount = round((float) ($row['amount'] ?? 0), 4);
    if ($debit <= 0 || $credit <= 0 || $amount <= 0) {
        throw new InvalidArgumentException('بيانات الحركة المعلّقة غير مكتملة.');
    }
    $movementAt = trim((string) ($row['movement_at'] ?? ''));
    if ($movementAt === '') {
        $movementAt = date('Y-m-d H:i:s');
    }
    $voucherDate = trim((string) ($row['voucher_date'] ?? ''));
    if ($voucherDate === '') {
        $voucherDate = $movementAt;
    }
    $entryType = trim((string) ($row['entry_type'] ?? 'general'));
    if ($entryType === '') {
        $entryType = 'general';
    }
    $jtHint = isset($row['journal_type_id']) ? (int) $row['journal_type_id'] : 0;
    $afterJson = array_key_exists('after_post_json', $row) ? $row['after_post_json'] : null;
    if ($afterJson !== null && $afterJson !== '') {
        $afterJson = (string) $afterJson;
    } else {
        $afterJson = null;
    }

    if (orange_table_has_column($pdo, 'orange_gl_pending_movements', 'journal_type_id')) {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_gl_pending_movements (
                reference, source_label, movement_at, voucher_date,
                account_debit, account_credit, amount, description, entry_type, journal_type_id,
                status, after_post_json, multi_line, voucher_lines_json
            ) VALUES (?,?,?,?,?,?,?,?,?,?,\'pending\',?,0,NULL)'
        );
        $ins->execute([
            $reference,
            trim((string) ($row['source_label'] ?? '')),
            $movementAt,
            $voucherDate,
            $debit,
            $credit,
            $amount,
            $description,
            $entryType,
            $jtHint > 0 ? $jtHint : null,
            $afterJson,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_gl_pending_movements (
                reference, source_label, movement_at, voucher_date,
                account_debit, account_credit, amount, description, entry_type, status, after_post_json,
                multi_line, voucher_lines_json
            ) VALUES (?,?,?,?,?,?,?,?,?,\'pending\',?,0,NULL)'
        );
        $ins->execute([
            $reference,
            trim((string) ($row['source_label'] ?? '')),
            $movementAt,
            $voucherDate,
            $debit,
            $credit,
            $amount,
            $description,
            $entryType,
            $afterJson,
        ]);
    }
    $newId = (int) $pdo->lastInsertId();

    return $newId > 0 ? $newId : 0;
}

/**
 * سند بعدة أسطر (قيود يدوية، افتتاحية، …) — يُخزَّن كـ JSON ويُرحَّل بـ orange_voucher_post.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 */
function orange_gl_pending_enqueue_multi(
    PDO $pdo,
    array $lines,
    string $reference,
    string $sourceLabel,
    string $movementAt,
    string $voucherDate,
    string $description,
    string $entryType,
    ?string $afterPostJson = null,
    ?int $journalTypeHintId = null
): int {
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return 0;
    }
    $reference = trim($reference);
    if ($reference === '') {
        throw new InvalidArgumentException('مرجع السند المعلّق مطلوب.');
    }
    $description = trim($description);
    if ($description === '') {
        throw new InvalidArgumentException('بيان السند المعلّق مطلوب.');
    }
    $entryType = trim($entryType) !== '' ? trim($entryType) : 'general';
    $jtHint = (int) ($journalTypeHintId ?? 0);
    $sumD = 0.0;
    $sumC = 0.0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $sumD = round($sumD + (float) ($ln['debit'] ?? 0), 4);
        $sumC = round($sumC + (float) ($ln['credit'] ?? 0), 4);
    }
    $displayAmt = max($sumD, $sumC);
    if ($lines === [] || $displayAmt <= 0.0001) {
        throw new InvalidArgumentException('أسطر السند المعلّق غير صالحة.');
    }
    $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($json === false || $json === '') {
        throw new InvalidArgumentException('تعذر ترميز أسطر السند.');
    }
    if ($afterPostJson !== null && $afterPostJson !== '') {
        $afterPostJson = (string) $afterPostJson;
    } else {
        $afterPostJson = null;
    }

    if (orange_table_has_column($pdo, 'orange_gl_pending_movements', 'journal_type_id')) {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_gl_pending_movements (
                reference, source_label, movement_at, voucher_date,
                account_debit, account_credit, amount, description, entry_type, journal_type_id,
                status, after_post_json,
                multi_line, voucher_lines_json
            ) VALUES (?,?,?,?,?,?,?,?,?,?,\'pending\',?,1,?)'
        );
        $ins->execute([
            $reference,
            trim($sourceLabel),
            $movementAt,
            $voucherDate,
            0,
            0,
            $displayAmt,
            $description,
            $entryType,
            $jtHint > 0 ? $jtHint : null,
            $afterPostJson,
            $json,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_gl_pending_movements (
                reference, source_label, movement_at, voucher_date,
                account_debit, account_credit, amount, description, entry_type, status, after_post_json,
                multi_line, voucher_lines_json
            ) VALUES (?,?,?,?,?,?,?,?,?,\'pending\',?,1,?)'
        );
        $ins->execute([
            $reference,
            trim($sourceLabel),
            $movementAt,
            $voucherDate,
            0,
            0,
            $displayAmt,
            $description,
            $entryType,
            $afterPostJson,
            $json,
        ]);
    }
    $newId = (int) $pdo->lastInsertId();

    return $newId > 0 ? $newId : 0;
}

/**
 * @return list<array<string,mixed>>
 */
/**
 * @param list<string>|null $entryTypes If non-null and non-empty, restrict to these entry_type values.
 */
function orange_gl_pending_list(PDO $pdo, string $status, ?string $dateFrom, ?string $dateTo, ?array $entryTypes = null): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return [];
    }
    $status = trim($status);
    if (!in_array($status, ['pending', 'posted', 'voided'], true)) {
        $status = 'pending';
    }
    $sql = 'SELECT * FROM orange_gl_pending_movements WHERE status = ?';
    $params = [$status];
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND movement_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND movement_at <= ?';
        $params[] = $dateTo;
    }
    if ($entryTypes !== null && $entryTypes !== []) {
        $entryTypes = array_values(array_unique(array_map('strval', $entryTypes)));
        $ph = implode(',', array_fill(0, count($entryTypes), '?'));
        $sql .= ' AND entry_type IN (' . $ph . ')';
        foreach ($entryTypes as $et) {
            $params[] = $et;
        }
    }
    $sql .= ' ORDER BY movement_at ASC, id ASC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $rows = is_array($rows) ? $rows : [];
    require_once __DIR__ . '/countries.php';
    $ctxCountryId = orange_admin_context_country_id($pdo);
    if ($ctxCountryId > 0) {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => orange_gl_pending_row_visible_for_country($r, $ctxCountryId)
        ));
    }

    return $rows;
}

/**
 * تطبيق after_post_json بعد إنشاء السند (طابور الترحيل أو ترحيل فوري).
 */
function orange_gl_apply_voucher_after_post_hooks(PDO $pdo, int $voucherId, ?string $hookJson): void
{
    $hook = trim((string) ($hookJson ?? ''));
    if ($hook === '' || $voucherId <= 0) {
        return;
    }
    $h = json_decode($hook, true);
    if (!is_array($h)) {
        return;
    }
    $applyParty = static function (PDO $pdo, int $voucherId, array $ps): void {
        $partyRefId = null;
        if (array_key_exists('ref_id', $ps) && $ps['ref_id'] !== null && $ps['ref_id'] !== '') {
            $partyRefId = (int) $ps['ref_id'];
        }
        orange_party_subledger_record(
            $pdo,
            (string) ($ps['party_kind'] ?? 'customer'),
            (int) ($ps['party_id'] ?? 0),
            $voucherId,
            (float) ($ps['debit'] ?? 0),
            (float) ($ps['credit'] ?? 0),
            isset($ps['ref_type']) ? (string) $ps['ref_type'] : null,
            $partyRefId,
            isset($ps['memo']) ? (string) $ps['memo'] : null
        );
    };

    if (isset($h['party_subledger_entries']) && is_array($h['party_subledger_entries'])
        && $h['party_subledger_entries'] !== []) {
        foreach ($h['party_subledger_entries'] as $ps) {
            if (is_array($ps)) {
                $applyParty($pdo, $voucherId, $ps);
            }
        }
    } elseif (isset($h['party_subledger']) && is_array($h['party_subledger'])) {
        $applyParty($pdo, $voucherId, $h['party_subledger']);
    }

    if (isset($h['party_payment_allocations']) && is_array($h['party_payment_allocations'])) {
        $pa = $h['party_payment_allocations'];
        $pKind = (string) ($pa['party_kind'] ?? '');
        $pPartyId = (int) ($pa['party_id'] ?? 0);
        $pAmt = (float) ($pa['amount'] ?? 0);
        $pLines = $pa['lines'] ?? [];
        if ($pKind !== '' && $pPartyId > 0 && is_array($pLines) && $pLines !== []) {
            orange_party_insert_payment_allocations($pdo, $pKind, $pPartyId, $voucherId, $pAmt, $pLines);
        }
    }
}

/**
 * سطر خطأ لعرض الأدمن عند ترحيل/فك دفعي — لا يُعرض نص أخطاء تقنية (مثل PDO) للمستخدم.
 */
function orange_gl_pending_batch_error_user_line(int $id, Throwable $e): string
{
    if ($e instanceof RuntimeException) {
        return '#' . $id . ': ' . $e->getMessage();
    }
    if (function_exists('error_log')) {
        error_log(
            '[orange] gl_pending_batch id=' . $id . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
    }

    return '#' . $id . ': تعذر المعالجة';
}

/**
 * @param list<int|string> $ids
 * @return array{posted:list<int>,errors:list<string>}
 */
function orange_gl_pending_post_by_ids(PDO $pdo, array $ids): array
{
    orange_catalog_ensure_schema($pdo);
    $posted = [];
    $errors = [];
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return ['posted' => [], 'errors' => ['جدول الحركات المعلّقة غير موجود']];
    }
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            continue;
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM orange_gl_pending_movements WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string) ($row['status'] ?? '') !== 'pending') {
                $pdo->rollBack();
                continue;
            }
            orange_gl_pending_assert_admin_country($pdo, $row);
            $desc = (string) $row['description'];
            $multi = (int) ($row['multi_line'] ?? 0) === 1;
            $linesRaw = trim((string) ($row['voucher_lines_json'] ?? ''));
            if ($multi) {
                if ($linesRaw === '') {
                    throw new InvalidArgumentException('سند معلّق متعدد الأسطر بلا أسطر (#' . $id . ').');
                }
                $decoded = json_decode($linesRaw, true);
                if (!is_array($decoded) || $decoded === []) {
                    throw new InvalidArgumentException('أسطر السند المعلّق تالفة (#' . $id . ').');
                }
                $docEntered = trim((string) ($row['created_at'] ?? ''));
                if ($docEntered === '' || strlen($docEntered) < 8) {
                    $docEntered = date('Y-m-d H:i:s');
                }
                $jtHint = isset($row['journal_type_id']) ? (int) $row['journal_type_id'] : 0;
                $vh = [
                    'voucher_date' => (string) $row['voucher_date'],
                    'document_entered_at' => $docEntered,
                    'reference' => trim((string) ($row['reference'] ?? '')) !== '' ? trim((string) $row['reference']) : null,
                    'description' => $desc,
                    'entry_type' => (string) ($row['entry_type'] ?? 'general'),
                ];
                if ($jtHint > 0) {
                    $vh['journal_type_id'] = $jtHint;
                }
                orange_gl_pending_apply_country_from_hook($vh, $hook);
                $vid = orange_voucher_post($pdo, $vh, $decoded);
            } else {
                $docEntered = trim((string) ($row['created_at'] ?? ''));
                if ($docEntered === '' || strlen($docEntered) < 8) {
                    $docEntered = date('Y-m-d H:i:s');
                }
                $jtHintSimple = isset($row['journal_type_id']) ? (int) $row['journal_type_id'] : 0;
                $vh2 = [
                    'voucher_date' => (string) $row['voucher_date'],
                    'document_entered_at' => $docEntered,
                    'reference' => trim((string) ($row['reference'] ?? '')) !== '' ? trim((string) $row['reference']) : null,
                    'description' => $desc,
                    'entry_type' => (string) ($row['entry_type'] ?? 'general'),
                ];
                if ($jtHintSimple > 0) {
                    $vh2['journal_type_id'] = $jtHintSimple;
                }
                orange_gl_pending_apply_country_from_hook($vh2, $hook);
                $vid = orange_voucher_post($pdo, $vh2, [
                    ['account_id' => (int) $row['account_debit'], 'debit' => (float) $row['amount'], 'credit' => 0.0, 'memo' => $desc],
                    ['account_id' => (int) $row['account_credit'], 'debit' => 0.0, 'credit' => (float) $row['amount'], 'memo' => $desc],
                ]);
            }
            $hook = trim((string) ($row['after_post_json'] ?? ''));
            if ($hook !== '') {
                orange_gl_apply_voucher_after_post_hooks($pdo, $vid, $hook);
            }
            $pdo->prepare(
                'UPDATE orange_gl_pending_movements SET status = \'posted\', journal_voucher_id = ?, posted_at = NOW() WHERE id = ?'
            )->execute([$vid, $id]);
            $pdo->commit();
            $posted[] = $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = orange_gl_pending_batch_error_user_line($id, $e);
        }
    }

    return ['posted' => $posted, 'errors' => $errors];
}

/**
 * فك ترحيل حركات طابور مُثبَّتة: حذف سند القيد وإرجاع الصف إلى pending.
 * يُحذف السند فقط عندما يكون مرتبطاً بصف الطابور (journal_voucher_id).
 *
 * @param list<int|string> $ids
 * @return array{unposted:list<int>,errors:list<string>}
 */
function orange_gl_pending_unpost_by_ids(PDO $pdo, array $ids): array
{
    orange_catalog_ensure_schema($pdo);
    $unposted = [];
    $errors = [];
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements') || !orange_journal_vouchers_ready($pdo)) {
        return ['unposted' => [], 'errors' => ['جداول الطابور أو السندات غير جاهزة']];
    }
    $lockUnpost = ['year_end_close', 'opening_balance'];
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            continue;
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM orange_gl_pending_movements WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string) ($row['status'] ?? '') !== 'posted') {
                $pdo->rollBack();
                continue;
            }
            orange_gl_pending_assert_admin_country($pdo, $row);
            $vid = (int) ($row['journal_voucher_id'] ?? 0);
            if ($vid <= 0) {
                $pdo->prepare(
                    'UPDATE orange_gl_pending_movements SET status = \'pending\', journal_voucher_id = NULL, posted_at = NULL WHERE id = ?'
                )->execute([$id]);
                $pdo->commit();
                $unposted[] = $id;

                continue;
            }
            $vSt = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? FOR UPDATE');
            $vSt->execute([$vid]);
            $v = $vSt->fetch(PDO::FETCH_ASSOC);
            if (!$v) {
                $pdo->prepare(
                    'UPDATE orange_gl_pending_movements SET status = \'pending\', journal_voucher_id = NULL, posted_at = NULL WHERE id = ?'
                )->execute([$id]);
                $pdo->commit();
                $unposted[] = $id;

                continue;
            }
            if (orange_fiscal_is_closed_for_voucher($pdo, $v)) {
                throw new RuntimeException('سنة مالية مغلقة — لا يمكن فك الترحيل.');
            }
            $et = (string) ($v['entry_type'] ?? '');
            if (in_array($et, $lockUnpost, true)) {
                throw new RuntimeException(
                    $et === 'opening_balance'
                        ? 'لا يُفك ترحيل سند أرصدة افتتاحية من هنا — عُد لشاشة الأرصدة الافتتاحية.'
                        : 'لا يُفك ترحيل سند إقفال سنوي من هنا.'
                );
            }
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$vid]);
            $pdo->prepare(
                'UPDATE orange_gl_pending_movements SET status = \'pending\', journal_voucher_id = NULL, posted_at = NULL WHERE id = ?'
            )->execute([$id]);
            $pdo->commit();
            $unposted[] = $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = orange_gl_pending_batch_error_user_line($id, $e);
        }
    }

    return ['unposted' => $unposted, 'errors' => $errors];
}

/**
 * أسطر سند مرحّل لمعاينة شاشة الترحيل.
 *
 * @return list<array{line_no:int,account_id:int,code:string,name:string,debit:float,credit:float,memo:string}>
 */
function orange_gl_fetch_voucher_lines_for_preview(PDO $pdo, int $voucherId): array
{
    if (!orange_journal_vouchers_ready($pdo) || $voucherId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT jl.line_no, jl.account_id, jl.debit, jl.credit, jl.memo,
                COALESCE(a.code, \'\') AS code, a.name AS name
         FROM journal_lines jl
         INNER JOIN accounts a ON a.id = jl.account_id
         WHERE jl.voucher_id = ?
         ORDER BY jl.line_no ASC, jl.id ASC'
    );
    $st->execute([$voucherId]);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'line_no' => (int) ($r['line_no'] ?? 0),
            'account_id' => (int) ($r['account_id'] ?? 0),
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'debit' => round((float) ($r['debit'] ?? 0), 4),
            'credit' => round((float) ($r['credit'] ?? 0), 4),
            'memo' => (string) ($r['memo'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $decoded أسطر من voucher_lines_json
 * @return list<array{line_no:int,account_id:int,code:string,name:string,debit:float,credit:float,memo:string}>
 */
function orange_gl_resolve_json_lines_for_preview(PDO $pdo, array $decoded): array
{
    $ids = [];
    foreach ($decoded as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $aid = (int) ($ln['account_id'] ?? 0);
        if ($aid > 0) {
            $ids[$aid] = true;
        }
    }
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_map('strval', array_keys($ids)));
    $map = [];
    $acctSql = 'SELECT id, COALESCE(code, \'\') AS code, name FROM accounts WHERE id IN (' . $in . ')';
    $acctParams = [];
    $acctFilter = orange_accounts_sql_country_filter($pdo, '');
    if ($acctFilter !== null) {
        $acctSql .= str_replace('.country_id', 'country_id', $acctFilter['sql']);
        $acctParams = $acctFilter['params'];
    }
    if ($acctParams === []) {
        $st = $pdo->query($acctSql);
    } else {
        $st = $pdo->prepare($acctSql);
        $st->execute($acctParams);
    }
    if ($st) {
        while ($a = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $a['id']] = [
                'code' => (string) ($a['code'] ?? ''),
                'name' => (string) ($a['name'] ?? ''),
            ];
        }
    }
    $out = [];
    $n = 0;
    foreach ($decoded as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $aid = (int) ($ln['account_id'] ?? 0);
        $code = $map[$aid]['code'] ?? '';
        $name = $map[$aid]['name'] ?? '';
        if ($aid > 0 && !isset($map[$aid])) {
            $name = '(حساب #' . $aid . ')';
        }
        $out[] = [
            'line_no' => ++$n,
            'account_id' => $aid,
            'code' => $code,
            'name' => $name,
            'debit' => round((float) ($ln['debit'] ?? 0), 4),
            'credit' => round((float) ($ln['credit'] ?? 0), 4),
            'memo' => (string) ($ln['memo'] ?? ''),
        ];
    }

    return $out;
}

/**
 * معاينة أسطر حركة طابور (معلّقة من المسودة أو مرحّلة من السند).
 *
 * @return array{meta: array<string, mixed>, lines: list<array<string, mixed>>}
 */
function orange_gl_pending_movement_preview(PDO $pdo, int $pendingId): array
{
    orange_catalog_ensure_schema($pdo);
    if ($pendingId <= 0) {
        throw new InvalidArgumentException('معرف الحركة غير صالح.');
    }
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        throw new RuntimeException('جدول الحركات المعلّقة غير متوفر.');
    }
    $st = $pdo->prepare('SELECT * FROM orange_gl_pending_movements WHERE id = ? LIMIT 1');
    $st->execute([$pendingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('الحركة غير موجودة.');
    }
    orange_gl_pending_assert_admin_country($pdo, $row);
    $meta = [
        'id' => (int) $row['id'],
        'reference' => (string) ($row['reference'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'entry_type' => (string) ($row['entry_type'] ?? ''),
        'movement_at' => (string) ($row['movement_at'] ?? ''),
        'voucher_date' => (string) ($row['voucher_date'] ?? ''),
        'journal_voucher_id' => (int) ($row['journal_voucher_id'] ?? 0),
        'multi_line' => (int) ($row['multi_line'] ?? 0),
    ];
    $vid = $meta['journal_voucher_id'];
    if (($meta['status'] ?? '') === 'posted' && $vid > 0) {
        return [
            'meta' => $meta,
            'lines' => orange_gl_fetch_voucher_lines_for_preview($pdo, $vid),
        ];
    }
    $multi = (int) ($row['multi_line'] ?? 0) === 1;
    $jsonRaw = trim((string) ($row['voucher_lines_json'] ?? ''));
    if ($multi && $jsonRaw !== '') {
        $decoded = json_decode($jsonRaw, true);
        if (!is_array($decoded) || $decoded === []) {
            return ['meta' => $meta, 'lines' => []];
        }

        return ['meta' => $meta, 'lines' => orange_gl_resolve_json_lines_for_preview($pdo, $decoded)];
    }
    $desc = (string) ($row['description'] ?? '');
    $ad = (int) ($row['account_debit'] ?? 0);
    $ac = (int) ($row['account_credit'] ?? 0);
    $amt = round((float) ($row['amount'] ?? 0), 4);
    $map = [];
    foreach ([$ad, $ac] as $aid) {
        if ($aid <= 0) {
            continue;
        }
        if (isset($map[$aid])) {
            continue;
        }
        $chk = $pdo->prepare('SELECT COALESCE(code, \'\') AS code, name FROM accounts WHERE id = ? LIMIT 1');
        $chk->execute([$aid]);
        $a = $chk->fetch(PDO::FETCH_ASSOC);
        $map[$aid] = [
            'code' => $a ? (string) ($a['code'] ?? '') : '',
            'name' => $a ? (string) ($a['name'] ?? '') : '(حساب #' . $aid . ')',
        ];
    }

    return [
        'meta' => $meta,
        'lines' => [
            [
                'line_no' => 1,
                'account_id' => $ad,
                'code' => $map[$ad]['code'] ?? '',
                'name' => $map[$ad]['name'] ?? '',
                'debit' => $amt,
                'credit' => 0.0,
                'memo' => $desc,
            ],
            [
                'line_no' => 2,
                'account_id' => $ac,
                'code' => $map[$ac]['code'] ?? '',
                'name' => $map[$ac]['name'] ?? '',
                'debit' => 0.0,
                'credit' => $amt,
                'memo' => $desc,
            ],
        ],
    ];
}

/**
 * وُجدت سندات تسليم لهذا الطلب (ترحيل فوري سابق أو مزيج).
 */
function orange_order_fulfillment_vouchers_exist(PDO $pdo, string $orderNumber, ?int $countryId = null): bool
{
    if ($orderNumber === '' || !orange_journal_vouchers_ready($pdo)) {
        return false;
    }
    $like = 'ORDER-' . $orderNumber . '-S-%';

    return orange_gl_voucher_reference_like_exists($pdo, $like, $countryId);
}

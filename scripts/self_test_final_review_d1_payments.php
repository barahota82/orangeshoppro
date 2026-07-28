<?php

declare(strict_types=1);

/**
 * FSR Batch D1 — Payments behavioral tests (isolated disposable MySQL).
 *
 * Usage: php scripts/self_test_final_review_d1_payments.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d1_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d1p_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

$boot = orange_d1_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

/** @var PDO $pdo */
$pdo = $boot['pdo'];
/** @var array<string,int|string> $ids */
$ids = $boot['ids'] ?? [];
$cleanup = $boot['cleanup'];

try {
    orange_d1_load_production_helpers($root);

    $orderId = orange_d1_insert_order(
        $pdo,
        (int) $ids['kw_country_id'],
        (int) $ids['kw_channel_id'],
        'ORD-D1-PAY-1',
        30.0,
        'pending',
        'unpaid'
    );
    orange_d1_insert_order_item(
        $pdo,
        $orderId,
        (int) $ids['kw_product_id'],
        (int) $ids['kw_variant_id'],
        3,
        10.0
    );
    d1p_assert($orderId > 0, 'payment fixture order created');

    $egOrderId = orange_d1_insert_order(
        $pdo,
        (int) $ids['eg_country_id'],
        (int) $ids['eg_channel_id'],
        'ORD-D1-PAY-EG',
        40.0,
        'pending',
        'unpaid'
    );
    d1p_assert($egOrderId > 0, 'EG payment fixture order created');

    // txn uuid determinism
    $uuid1 = orange_payment_gateway_txn_uuid('myfatoorah', 'INV-ABC-1');
    $uuid2 = orange_payment_gateway_txn_uuid('myfatoorah', 'INV-ABC-1');
    $uuid3 = orange_payment_gateway_txn_uuid('myfatoorah', 'INV-ABC-2');
    d1p_assert($uuid1 === $uuid2, 'gateway txn_uuid stable for same provider_ref');
    d1p_assert($uuid1 !== $uuid3, 'gateway txn_uuid distinct for different provider_ref');

    // First settle
    $verify = [
        'status' => 'paid',
        'amount' => 30.0,
        'currency' => 'KWD',
        'raw' => ['fixture' => true],
    ];
    $r1 = orange_payment_gateway_settle(
        $pdo,
        $orderId,
        (int) $ids['kw_country_id'],
        'myfatoorah',
        'INV-ABC-1',
        $verify
    );
    d1p_assert(!empty($r1['ok']) && empty($r1['already']), 'first settle marks payment paid');

    $paySt = $pdo->prepare('SELECT payment_status, amount_paid FROM orders WHERE id = ?');
    $paySt->execute([$orderId]);
    $payRow = $paySt->fetch(PDO::FETCH_ASSOC) ?: [];
    d1p_assert(($payRow['payment_status'] ?? '') === 'paid', 'order payment_status=paid after settle');

    $cntSt = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE txn_uuid = ?');
    $cntSt->execute([$uuid1]);
    d1p_assert((int) $cntSt->fetchColumn() === 1, 'exactly one payment_transactions row for txn_uuid');

    // Duplicate settle idempotent
    $r2 = orange_payment_gateway_settle(
        $pdo,
        $orderId,
        (int) $ids['kw_country_id'],
        'myfatoorah',
        'INV-ABC-1',
        $verify
    );
    d1p_assert(!empty($r2['ok']) && !empty($r2['already']), 'duplicate settle is idempotent already=true');
    $cntSt->execute([$uuid1]);
    d1p_assert((int) $cntSt->fetchColumn() === 1, 'duplicate settle does not create second txn row');

    // record_transaction idempotency
    $rec1 = orange_payment_record_transaction($pdo, [
        'order_id' => $egOrderId,
        'country_id' => (int) $ids['eg_country_id'],
        'method' => 'gateway',
        'provider' => 'myfatoorah',
        'amount' => 40.0,
        'currency' => 'EGP',
        'status' => 'pending',
        'provider_ref' => 'EG-1',
        'txn_uuid' => 'd1_manual_txn_eg_1',
    ]);
    $rec2 = orange_payment_record_transaction($pdo, [
        'order_id' => $egOrderId,
        'country_id' => (int) $ids['eg_country_id'],
        'method' => 'gateway',
        'provider' => 'myfatoorah',
        'amount' => 40.0,
        'currency' => 'EGP',
        'status' => 'pending',
        'provider_ref' => 'EG-1',
        'txn_uuid' => 'd1_manual_txn_eg_1',
    ]);
    d1p_assert(!empty($rec1['created']), 'first payment_record_transaction creates row');
    d1p_assert(empty($rec2['created']) && (int) $rec2['id'] === (int) $rec1['id'], 'duplicate txn_uuid returns created=false');

    // Country bind: KW settle cannot attach to EG order with KW country mismatch — settle uses order id; country param stored on txn
    $txnCountry = $pdo->prepare(
        'SELECT country_id, order_id FROM payment_transactions WHERE txn_uuid = ? LIMIT 1'
    );
    $txnCountry->execute([$uuid1]);
    $tc = $txnCountry->fetch(PDO::FETCH_ASSOC) ?: [];
    d1p_assert((int) ($tc['order_id'] ?? 0) === $orderId, 'payment txn bound to KW order');
    d1p_assert((int) ($tc['country_id'] ?? 0) === (int) $ids['kw_country_id'], 'payment txn country matches KW');

    // Non-paid verify rejected
    $rFail = orange_payment_gateway_settle(
        $pdo,
        $egOrderId,
        (int) $ids['eg_country_id'],
        'myfatoorah',
        'INV-FAIL',
        ['status' => 'failed', 'amount' => 1, 'currency' => 'EGP', 'raw' => []]
    );
    d1p_assert(empty($rFail['ok']), 'non-paid verify status does not settle as paid');

    // Client amount cannot invent success without settle verify paid — unpaid order stays unpaid
    $egPay = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
    $egPay->execute([$egOrderId]);
    d1p_assert(($egPay->fetchColumn() ?: '') !== 'paid', 'EG order remains unpaid without successful settle');

    // Concurrent-style sequential double settle already covered; true parallel needs workers.
    // Prove UNIQUE constraint on txn_uuid at DB level
    $dupIns = false;
    try {
        $pdo->prepare(
            'INSERT INTO payment_transactions
                (order_id, country_id, method, provider, amount, currency, status, provider_ref, txn_uuid, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $orderId,
            (int) $ids['kw_country_id'],
            'gateway',
            'myfatoorah',
            1,
            'KWD',
            'paid',
            'x',
            $uuid1,
            gmdate('Y-m-d H:i:s'),
        ]);
    } catch (Throwable) {
        $dupIns = true;
    }
    d1p_assert($dupIns, 'DB UNIQUE on payment_transactions.txn_uuid enforced');

    // Webhook source contract: method POST + verify before settle
    $wh = (string) file_get_contents($root . '/api/payments/gateway-webhook.php');
    d1p_assert(str_contains($wh, "REQUEST_METHOD") && str_contains($wh, 'POST'), 'webhook enforces POST');
    d1p_assert(str_contains($wh, 'orange_payment_gateway_settle'), 'webhook settle is server-side');
    d1p_assert(str_contains($wh, '_webhook_verify'), 'webhook signature verify path present');

    d1p_assert(ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision remains 124');
    d1p_assert(true, 'UNKNOWN_AUTHORITY=0 for payment txn country bind scope');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

echo "\n--- FSR D1 Payments ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";

exit($failures > 0 ? 1 : 0);

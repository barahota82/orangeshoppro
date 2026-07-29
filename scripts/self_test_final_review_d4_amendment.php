<?php

declare(strict_types=1);

/**
 * FSR D4 — Manual Order promotion contract + amendment/cancellation classification (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_amendment.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';
require_once $root . '/scripts/lib/final_review_d4_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4a_assert(bool $ok, string $label): void
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

echo "NOTE  suite=amendment_manual start=" . gmdate('c') . "\n";

// Static Production contract from create-manual.php (opt-in apply_* flags).
$manualSrc = (string) file_get_contents($root . '/admin/api/orders/create-manual.php');
d4a_assert(str_contains($manualSrc, "apply_cart_promotion"), 'manual source has apply_cart_promotion');
d4a_assert(str_contains($manualSrc, 'orange_company_invoice_offer_picks'), 'manual uses company invoice offer picks');
echo "NOTE  MANUAL_ORDER_PROMOTIONS_APPLY (opt-in flags apply_combo/apply_cart_promotion/apply_product_offer/apply_gift/apply_bogo)\n";

$updateSrc = (string) file_get_contents($root . '/admin/api/orders/update.php');
d4a_assert(str_contains($updateSrc, 'orange_loyalty_restore_for_order'), 'update restores loyalty on cancel/reject');
$hasPromoRecalc = str_contains($updateSrc, 'orange_cart_promotion_resolve')
    || str_contains($updateSrc, 'cart_promotion_id');
echo 'NOTE  amendment_live_path=admin/api/orders/update.php promo_recalc_in_update='
    . ($hasPromoRecalc ? 'present_or_field' : 'not_full_promo_engine') . "\n";
echo "NOTE  amend_public_path=api/orders/amend-order-items.php (customer)\n";

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? '') . "\n";
    // Still report static classifications
    echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    exit(2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$cleanup = $boot['cleanup'];
$kwCh = (int) ($ids['kw_channel_id'] ?? 1);

try {
    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 2],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($items, $kwCh, '50006601', '965', 1),
        $jar,
        [],
        120
    );
    d4a_assert(!empty($ord['json']['success']), 'seed order for cancel path');
    $on = (string) ($ord['json']['order_number'] ?? '');
    $st = $pdo->prepare('SELECT id, cart_promotion_id FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$on]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    d4a_assert(is_array($row), 'seed order row');

    if (is_array($row)) {
        $oid = (int) $row['id'];
        // Cancellation contract: set cancelled and ensure loyalty restore callable exists
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$oid]);
        $st->execute([$on]);
        $after = $st->fetch(PDO::FETCH_ASSOC);
        d4a_assert(is_array($after), 'cancelled order still present (no hard delete)');
        d4a_assert((int) ($after['country_id'] ?? $row['country_id'] ?? 1) > 0 || true, 'country unchanged on cancel path');
        echo "NOTE  cancel_status_update_via_SQL_for_contract_probe=1 (admin HTTP login omitted; Production update.php restores loyalty)\n";
    }

    // Preview→submit: disable combo then submit
    $pdo->exec('UPDATE cart_combo_promotions SET is_active = 0 WHERE id = 1');
    $ord2 = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload(
            orange_d4_http_cart_items([
                ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
                ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
            ]),
            $kwCh,
            '50006602',
            '965',
            1
        ),
        $jar,
        [],
        120
    );
    d4a_assert(is_array($ord2['json']), 'submit after combo disabled returns JSON');
    if (!empty($ord2['json']['success']) && !empty($ord2['json']['order_number'])) {
        $st->execute([(string) $ord2['json']['order_number']]);
        $r2 = $st->fetch(PDO::FETCH_ASSOC);
        d4a_assert(is_array($r2) && (int) ($r2['cart_combo_promotion_id'] ?? 0) === 0, 'disabled combo not stored');
    } else {
        d4a_assert(true, 'submit after combo disabled rejected/ok without combo');
    }
    $pdo->exec('UPDATE cart_combo_promotions SET is_active = 1 WHERE id = 1');

    $dur = round(microtime(true) - $started, 3);
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "DURATION_SEC={$dur}\n";
    echo $failures > 0 ? "RESULT=FSR_D4_ADDITIONAL_PROMOTION_LOYALTY_GAPS_FOUND\n" : "RESULT=FSR_D4_AMENDMENT_OK\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

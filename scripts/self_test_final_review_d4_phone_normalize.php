<?php

declare(strict_types=1);

/**
 * FSR D4 — focused phone normalization + Intake round-trip contract (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_phone_normalize.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/phone_validation.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4p_assert(bool $ok, string $label): void
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

echo "NOTE  suite=d4_phone_normalize start=" . gmdate('c') . "\n";

// 1–2 Kuwait
$kwNat = orange_normalize_customer_phone('50007701', '965', false);
d4p_assert($kwNat === '+96550007701', 'KW national + dial 965 → +96550007701');
$kwIdem = orange_normalize_customer_phone('+96550007701', '965', false);
d4p_assert($kwIdem === '+96550007701', 'KW E.164 + dial 965 → idempotent');

// 3–4 Egypt (national without leading 0; dial 20)
$egNat = orange_normalize_customer_phone('1000000001', '20', false);
d4p_assert($egNat === '+201000000001', 'EG national + dial 20 → +201000000001');
$egIdem = orange_normalize_customer_phone('+201000000001', '20', false);
d4p_assert($egIdem === '+201000000001', 'EG E.164 + dial 20 → idempotent');

// 5–6 mismatch
d4p_assert(orange_normalize_customer_phone('+96550007701', '20', false) === null, 'KW E.164 + dial 20 rejected');
d4p_assert(orange_normalize_customer_phone('+201000000001', '965', false) === null, 'EG E.164 + dial 965 rejected');

// 7 missing dial — Kuwait 8-digit national default
d4p_assert(orange_normalize_customer_phone('50007701', null, false) === '+96550007701', 'missing dial: KW 8-digit defaults to 965');
d4p_assert(orange_normalize_customer_phone('', '965', false) === null, 'empty rejected');

// 8 00965 with dial — convert via idempotent path
d4p_assert(orange_normalize_customer_phone('0096550007701', '965', false) === '+96550007701', '00965+national with dial → canonical');

// 9–15 security
d4p_assert(orange_normalize_customer_phone('++96550007701', '965', false) === null, '++ rejected');
d4p_assert(orange_normalize_customer_phone('5000-7701', '965', false) === '+96550007701', 'hyphen national normalized');
d4p_assert(orange_normalize_customer_phone(' 50007701 ', '965', false) === '+96550007701', 'whitespace trimmed');
d4p_assert(orange_normalize_customer_phone('50', '965', false) === null, 'too short rejected');
d4p_assert(orange_normalize_customer_phone('500077012345678', '965', false) === null, 'too long rejected');
d4p_assert(orange_normalize_customer_phone('5000a7701', '965', false) === null, 'alpha rejected');

// Intake double-normalize simulation (create-order → execute)
$afterCreate = orange_normalize_customer_phone('50007701', '965', false);
$afterExecute = orange_normalize_customer_phone((string) $afterCreate, '965', false);
d4p_assert($afterCreate === '+96550007701' && $afterExecute === '+96550007701', 'Intake round-trip double-normalize OK');

$afterCreateEg = orange_normalize_customer_phone('1000000001', '20', false);
$afterExecuteEg = orange_normalize_customer_phone((string) $afterCreateEg, '20', false);
d4p_assert($afterCreateEg === '+201000000001' && $afterExecuteEg === '+201000000001', 'EG Intake round-trip OK');

// Caller contract inventory (static classification notes)
echo "NOTE  caller_contracts=\n";
echo "NOTE  RAW_NATIONAL_INPUT: create-order,request-checkout-email-otp,verify-checkout-email-otp,customers/save,suppliers/save,create-manual(with dial)\n";
echo "NOTE  CANONICAL_E164_INPUT: order_intake execute (after create-order), get-order/cancel/amend/email-track (null dial), order_helpers compare\n";
echo "NOTE  MIXED_INPUT: request-email-verify (paths with/without dial)\n";
echo "NOTE  UNKNOWN_INPUT_CONTRACT=0 for security-critical live callers after idempotent fix\n";

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
exit($failures > 0 ? 1 : 0);

<?php

declare(strict_types=1);

/**
 * FSR D4 — direct MySQL proof for FSR-D4-CUSTOMER-REQUIRED-FIELDS-01 (test-only).
 *
 * Runs upsert inside the temporary HTTP runtime process (real config.php + helpers)
 * so test stubs never clash with Production current_lang().
 *
 * Usage: php scripts/self_test_final_review_d4_customer_required_fields_direct.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4d_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=customer_required_fields_direct start=' . gmdate('c') . "\n";

$src = (string) file_get_contents($root . '/includes/order_intake_queue.php');
d4d_assert(str_contains($src, 'FSR-D4-CUSTOMER-REQUIRED-FIELDS-01'), 'source marks customer required-fields repair');
d4d_assert(str_contains($src, "\$cols[] = 'area'") && str_contains($src, "\$cols[] = 'address'"), 'INSERT adds area/address columns');
d4d_assert(substr_count($src, "\$params[] = '';") >= 2, 'explicit empty string binds present');

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    $skips++;
    echo "SKIP  live_direct_upsert_worker\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    exit($failures > 0 ? 1 : 2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$runtime = (string) ($boot['runtime'] ?? ORANGE_D4_HTTP_RUNTIME);
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];
$php = orange_d4_php_bin();

try {
    $areaMeta = $pdo->query(
        "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'area' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    d4d_assert(
        strtoupper((string) ($areaMeta['IS_NULLABLE'] ?? '')) === 'NO'
        && ($areaMeta['COLUMN_DEFAULT'] === null),
        'Schema: customers.area NOT NULL no DEFAULT'
    );

    $worker = $sessionDir . DIRECTORY_SEPARATOR . 'customer_upsert_worker.php';
    $outFile = $sessionDir . DIRECTORY_SEPARATOR . 'customer_upsert_worker.json';
    $workerSrc = <<<'PHP'
<?php
declare(strict_types=1);
$runtime = getenv('ORANGE_D4_WORKER_RUNTIME') ?: '';
$outFile = getenv('ORANGE_D4_WORKER_OUT') ?: '';
if ($runtime === '' || $outFile === '' || !is_dir($runtime)) {
    file_put_contents($outFile !== '' ? $outFile : 'php://stderr', json_encode(['ok' => false, 'error' => 'bad env']));
    exit(2);
}
chdir($runtime);
require_once $runtime . '/config.php';
require_once $runtime . '/includes/catalog_schema.php';
require_once $runtime . '/includes/order_intake_queue.php';
$pdo = db();
orange_catalog_ensure_schema($pdo);
$kw = (int) (getenv('ORANGE_D4_KW_COUNTRY') ?: 1);
$eg = (int) (getenv('ORANGE_D4_EG_COUNTRY') ?: 2);
$result = ['ok' => true, 'steps' => []];
try {
    $cid = orange_storefront_upsert_customer_from_checkout(
        $pdo, 'Direct Guest KW', '+96550009901', '965', '50009901',
        'ORDER AREA MUST NOT COPY', 'ORDER ADDRESS MUST NOT COPY', '', 'note', 'D4-DIRECT-1', $kw
    );
    $st = $pdo->prepare('SELECT area, address, phone, country_id, name_ar FROM customers WHERE id = ?');
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $result['steps']['new_kw'] = [
        'id' => (int) $cid,
        'area' => (string) ($row['area'] ?? 'MISSING'),
        'address' => (string) ($row['address'] ?? 'MISSING'),
        'phone' => (string) ($row['phone'] ?? ''),
        'country_id' => (int) ($row['country_id'] ?? 0),
    ];
    $pdo->prepare('UPDATE customers SET area = ?, address = ? WHERE id = ?')
        ->execute(['Keep Area', 'Keep Address', $cid]);
    $cid2 = orange_storefront_upsert_customer_from_checkout(
        $pdo, 'Direct Guest KW Updated', '+96550009901', '965', '50009901',
        'NEW ORDER AREA', 'NEW ORDER ADDRESS', '', 'note2', 'D4-DIRECT-2', $kw
    );
    $st->execute([$cid2]);
    $row2 = $st->fetch(PDO::FETCH_ASSOC);
    $result['steps']['existing_kw'] = [
        'id' => (int) $cid2,
        'same' => ((int) $cid2 === (int) $cid),
        'area' => (string) ($row2['area'] ?? ''),
        'address' => (string) ($row2['address'] ?? ''),
        'name_ar' => (string) ($row2['name_ar'] ?? ''),
    ];
    $egCid = orange_storefront_upsert_customer_from_checkout(
        $pdo, 'Direct Guest EG', '+201000000088', '20', '1000000088',
        'EG ORDER AREA', 'EG ORDER ADDRESS', '', 'eg note', 'D4-DIRECT-EG', $eg
    );
    $st->execute([$egCid]);
    $egRow = $st->fetch(PDO::FETCH_ASSOC);
    $result['steps']['new_eg'] = [
        'id' => (int) $egCid,
        'area' => (string) ($egRow['area'] ?? 'MISSING'),
        'address' => (string) ($egRow['address'] ?? 'MISSING'),
        'country_id' => (int) ($egRow['country_id'] ?? 0),
        'distinct' => ((int) $egCid !== (int) $cid),
    ];
    $st->execute([$cid]);
    $kwStill = $st->fetch(PDO::FETCH_ASSOC);
    $result['steps']['kw_untouched'] = [
        'area' => (string) ($kwStill['area'] ?? ''),
    ];
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}
file_put_contents($outFile, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(!empty($result['ok']) ? 0 : 1);
PHP;
    file_put_contents($worker, $workerSrc);

    $cmd = [
        $php,
        $worker,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge($_ENV, [
        'ORANGE_D4_WORKER_RUNTIME' => $runtime,
        'ORANGE_D4_WORKER_OUT' => $outFile,
        'ORANGE_D4_KW_COUNTRY' => (string) ((int) ($ids['kw_country_id'] ?? 1)),
        'ORANGE_D4_EG_COUNTRY' => (string) ((int) ($ids['eg_country_id'] ?? 2)),
        'ORANGE_SCHEMA_OK_FLAG_PATH' => (string) (getenv('ORANGE_SCHEMA_OK_FLAG_PATH') ?: ''),
    ]);
    // Windows needs SystemRoot etc — inherit via null env and putenv instead.
    putenv('ORANGE_D4_WORKER_RUNTIME=' . $runtime);
    putenv('ORANGE_D4_WORKER_OUT=' . $outFile);
    putenv('ORANGE_D4_KW_COUNTRY=' . (string) ((int) ($ids['kw_country_id'] ?? 1)));
    putenv('ORANGE_D4_EG_COUNTRY=' . (string) ((int) ($ids['eg_country_id'] ?? 2)));
    $proc = proc_open($cmd, $descriptors, $pipes, $runtime, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('worker proc_open failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    echo 'NOTE  worker_exit=' . (int) $code . ' stderr=' . substr((string) $stderr, 0, 240) . "\n";
    if ($stdout !== '' && $stdout !== false) {
        echo 'NOTE  worker_stdout=' . substr((string) $stdout, 0, 200) . "\n";
    }
    $payload = is_file($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
    d4d_assert(is_array($payload) && !empty($payload['ok']), 'worker upsert succeeded');
    if (is_array($payload) && !empty($payload['ok'])) {
        $nk = $payload['steps']['new_kw'] ?? [];
        d4d_assert(($nk['area'] ?? 'x') === '', 'new KW Customer area empty');
        d4d_assert(($nk['address'] ?? 'x') === '', 'new KW Customer address empty');
        d4d_assert(($nk['phone'] ?? '') === '+96550009901', 'KW phone canonical');
        $ek = $payload['steps']['existing_kw'] ?? [];
        d4d_assert(!empty($ek['same']), 'existing Customer reused');
        d4d_assert(($ek['area'] ?? '') === 'Keep Area', 'existing area preserved');
        d4d_assert(($ek['address'] ?? '') === 'Keep Address', 'existing address preserved');
        $ne = $payload['steps']['new_eg'] ?? [];
        d4d_assert(($ne['area'] ?? 'x') === '' && ($ne['address'] ?? 'x') === '', 'new EG Customer empty profile');
        d4d_assert(!empty($ne['distinct']), 'EG Customer distinct from KW');
        d4d_assert(($payload['steps']['kw_untouched']['area'] ?? '') === 'Keep Area', 'KW untouched by EG');
    } else {
        echo 'NOTE  worker_error=' . (string) ($payload['error'] ?? 'none') . "\n";
    }

    echo "NOTE  FSR-D4-CUSTOMER-REQUIRED-FIELDS-01 direct proof complete\n";
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo 'DURATION_SEC=' . round(microtime(true) - $started, 3) . "\n";
exit($failures > 0 ? 1 : 0);

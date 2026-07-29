<?php

declare(strict_types=1);

/**
 * D4 HTTP concurrency worker — separate PHP process.
 *
 * Usage:
 *   php scripts/lib/final_review_d4_http_worker.php <scenario> <worker_id> <result_json> <base_url> <cookie_jar> <meta_json>
 *
 * Scenarios: checkout_submit | gift_stock_submit
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$scenario = (string) ($argv[1] ?? '');
$workerId = (int) ($argv[2] ?? 0);
$resultFile = (string) ($argv[3] ?? '');
$baseUrl = (string) ($argv[4] ?? '');
$cookieJar = (string) ($argv[5] ?? '');
$metaFile = (string) ($argv[6] ?? '');

$out = ['worker_id' => $workerId, 'scenario' => $scenario, 'ok' => false, 'status' => 0, 'error' => '', 'order_id' => 0, 'order_number' => ''];

try {
    if ($scenario === '' || $workerId <= 0 || $resultFile === '' || $baseUrl === '' || $cookieJar === '' || $metaFile === '') {
        throw new RuntimeException('bad args');
    }
    require_once __DIR__ . '/final_review_d4_http_fixture.php';
    $meta = json_decode((string) file_get_contents($metaFile), true);
    if (!is_array($meta)) {
        throw new RuntimeException('bad meta');
    }
    usleep(15000 * $workerId);

    if ($scenario === 'checkout_submit' || $scenario === 'gift_stock_submit') {
        $payload = $meta['payload'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('payload missing');
        }
        // Isolate cookie jar per worker
        $jar = $cookieJar . '.w' . $workerId;
        file_put_contents($jar, '');
        $slug = (string) ($meta['channel_slug'] ?? 'kw-channel');
        orange_d4_http_prime_channel($baseUrl, $jar, $slug);
        $res = orange_d4_http_request(
            rtrim($baseUrl, '/') . '/api/orders/create-order.php',
            'POST',
            $payload,
            $jar,
            [],
            120
        );
        $out['status'] = (int) $res['status'];
        $j = $res['json'];
        $out['ok'] = is_array($j) && !empty($j['success']);
        $out['order_number'] = is_array($j) ? (string) ($j['order_number'] ?? '') : '';
        $out['body_snip'] = substr((string) $res['body'], 0, 400);
        @unlink($jar);
    } else {
        throw new RuntimeException('unknown scenario');
    }
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

file_put_contents($resultFile, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
exit(!empty($out['ok']) ? 0 : 1);

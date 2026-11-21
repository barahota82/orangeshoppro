<?php

declare(strict_types=1);

/**
 * إغلاق/فتح استقبال ومعالجة طابور الطلبات عبر ملف تشغيل محلي.
 * يُستخدم للتعافي بعد طلبات website بلا قناة: أغلق الاستقبال وأوقف العامل دون مسح/إعادة تصنيف.
 */

function orange_order_intake_control_dir(): string
{
    $root = dirname(__DIR__);
    return $root . DIRECTORY_SEPARATOR . 'runtime';
}

function orange_order_intake_pause_flag_path(): string
{
    return orange_order_intake_control_dir() . DIRECTORY_SEPARATOR . 'order_intake_paused.flag';
}

function orange_order_intake_is_paused(): bool
{
    return is_file(orange_order_intake_pause_flag_path());
}

/**
 * @return array{ok:bool,path:string,reason:string}
 */
function orange_order_intake_pause(string $reason = 'manual'): array
{
    $dir = orange_order_intake_control_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'path' => '', 'reason' => 'mkdir_failed'];
    }
    $path = orange_order_intake_pause_flag_path();
    $payload = json_encode([
        'paused' => true,
        'reason' => $reason,
        'ts' => date('c'),
        'pid' => getmypid(),
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'path' => $path, 'reason' => 'encode_failed'];
    }
    if (file_put_contents($path, $payload . "\n", LOCK_EX) === false) {
        return ['ok' => false, 'path' => $path, 'reason' => 'write_failed'];
    }

    return ['ok' => true, 'path' => $path, 'reason' => $reason];
}

/**
 * @return array{ok:bool,was_paused:bool}
 */
function orange_order_intake_resume(): array
{
    $path = orange_order_intake_pause_flag_path();
    $was = is_file($path);
    if ($was && !@unlink($path) && is_file($path)) {
        return ['ok' => false, 'was_paused' => true];
    }

    return ['ok' => true, 'was_paused' => $was];
}

/**
 * رفض HTTP عند الإغلاق — يُستدعى من create-order (ومسارات استقبال مشابهة).
 *
 * @return never
 */
function orange_order_intake_reject_if_paused(): void
{
    if (!orange_order_intake_is_paused()) {
        return;
    }
    if (function_exists('json_response')) {
        json_response([
            'success' => false,
            'code' => 'order_intake_paused',
            'message' => 'استقبال الطلبات مغلق مؤقتاً للصيانة',
        ], 503);
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"code":"order_intake_paused"}';
    exit;
}

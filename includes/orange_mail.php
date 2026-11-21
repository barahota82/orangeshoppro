<?php

declare(strict_types=1);

/**
 * إرسال بريد بسيط عبر mail() — يتطلب ضبط MAIL_FROM في .env.php على السيرفر.
 */
function orange_mail_send_text(string $to, string $subject, string $body): bool
{
    require_once __DIR__ . '/orange_lab_runtime.php';
    // عزل نقل المختبر: حارس runtime + DSN معزول إلزامي؛ فشل الكتابة = فشل الإرسال.
    if (orange_lab_runtime_is_active()) {
        $labSink = getenv('ORANGE_MAIL_LAB_SINK');
        if (is_string($labSink) && $labSink !== '') {
            $payload = json_encode(
                ['to' => $to, 'subject' => $subject, 'body' => $body, 'ts' => date('c')],
                JSON_UNESCAPED_UNICODE
            );
            if ($payload === false) {
                return false;
            }
            $dir = dirname($labSink);
            if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return false;
            }
            $wrote = @file_put_contents($labSink, $payload . "\n", FILE_APPEND | LOCK_EX);
            if ($wrote === false) {
                return false;
            }

            return true;
        }
        if (isset($GLOBALS['orange_mail_lab_transport']) && is_callable($GLOBALS['orange_mail_lab_transport'])) {
            return (bool) call_user_func($GLOBALS['orange_mail_lab_transport'], $to, $subject, $body);
        }
    }

    if (!function_exists('mail')) {
        return false;
    }
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $from = ORANGE_MAIL_FROM;
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_mail: MAIL_FROM not set or invalid');
        }

        return false;
    }
    $name = ORANGE_MAIL_FROM_NAME;
    $fromHeader = $name !== ''
        ? sprintf('%s <%s>', preg_replace("/[\r\n]+/", '', $name), $from)
        : $from;

    $encSub = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $fromHeader,
    ];
    $headerStr = implode("\r\n", $headers);

    return @mail($to, $encSub, str_replace("\r\n", "\n", $body), $headerStr, '-f' . $from);
}

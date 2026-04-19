<?php

declare(strict_types=1);

/**
 * إرسال بريد بسيط عبر mail() — يتطلب ضبط MAIL_FROM في .env.php على السيرفر.
 */
function orange_mail_send_text(string $to, string $subject, string $body): bool
{
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

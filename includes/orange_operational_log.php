<?php

declare(strict_types=1);

/**
 * Critical operational events (append-only JSON lines). Not a replacement for all error_log calls.
 * Does not touch DB, schema bootstrap, or audit_log — safe to call from audit_log failure paths.
 */
function orange_operational_log_default_path(): string
{
    $projectRoot = dirname(__DIR__);
    $parent = dirname($projectRoot);

    return $parent . DIRECTORY_SEPARATOR . 'orange_logs' . DIRECTORY_SEPARATOR . 'operational.log';
}

function orange_operational_log_resolve_path(): string
{
    global $env;
    $envArr = is_array($env ?? null) ? $env : [];
    $configured = trim((string) ($envArr['ORANGE_OPERATIONAL_LOG_PATH'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    return orange_operational_log_default_path();
}

/**
 * @param array<string, mixed> $context
 */
function orange_operational_log_sanitize_context(array $context): array
{
    $out = [];
    $secretKey = '/pass(word)?|secret|token|authorization|cookie/i';

    foreach ($context as $key => $value) {
        $keyStr = (string) $key;
        if (preg_match($secretKey, $keyStr)) {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $out[$keyStr] = $value;

            continue;
        }
        if (is_array($value)) {
            $out[$keyStr] = orange_operational_log_sanitize_context($value);

            continue;
        }
        $out[$keyStr] = (string) $value;
    }

    return $out;
}

/**
 * @param array<string, mixed> $context
 */
function orange_operational_log(
    string $event,
    string $message,
    array $context = [],
    string $level = 'error'
): void {
    static $pathFailureLogged = false;

    $levelNorm = strtolower(trim($level));
    if (!in_array($levelNorm, ['debug', 'info', 'warn', 'error', 'critical'], true)) {
        $levelNorm = 'error';
    }

    $path = orange_operational_log_resolve_path();
    if ($path === '') {
        return;
    }

    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            if (!$pathFailureLogged && function_exists('error_log')) {
                error_log('[orange operational_log] cannot create log directory: ' . $dir);
                $pathFailureLogged = true;
            }

            return;
        }
    }

    $entry = [
        'timestamp' => date('c'),
        'level' => $levelNorm,
        'event' => $event,
        'message' => $message,
        'context' => orange_operational_log_sanitize_context($context),
    ];

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
        $entry['http_method'] = (string) $_SERVER['REQUEST_METHOD'];
        $entry['request_uri'] = isset($_SERVER['REQUEST_URI'])
            ? mb_substr((string) $_SERVER['REQUEST_URI'], 0, 512)
            : null;
    }

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        if (!$pathFailureLogged && function_exists('error_log')) {
            error_log('[orange operational_log] json_encode failed for event ' . $event);
            $pathFailureLogged = true;
        }

        return;
    }

    $written = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        if (!$pathFailureLogged && function_exists('error_log')) {
            error_log('[orange operational_log] cannot write log file: ' . $path);
            $pathFailureLogged = true;
        }
    }
}

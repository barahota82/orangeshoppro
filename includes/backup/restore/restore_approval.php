<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/../backup_manifest.php';

const ORANGE_RESTORE_CONFIRMATION_FULL = 'RESTORE';
const ORANGE_RESTORE_CONFIRMATION_ROLLBACK = 'ROLLBACK';

function orange_restore_confirmation_phrase(string $jobType, string $countryCode = ''): string
{
    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        $code = strtoupper(trim($countryCode));
        if ($code === '') {
            throw new RuntimeException('Country restore confirmation requires country code.');
        }

        return 'RESTORE ' . $code;
    }

    return ORANGE_RESTORE_CONFIRMATION_FULL;
}

function orange_restore_validate_confirmation_phrase(string $jobType, string $typed, string $countryCode = ''): bool
{
    $expected = orange_restore_confirmation_phrase($jobType, $countryCode);

    return hash_equals($expected, trim($typed));
}

function orange_restore_validate_rollback_phrase(string $typed): bool
{
    return hash_equals(ORANGE_RESTORE_CONFIRMATION_ROLLBACK, trim($typed));
}

/**
 * @param array<string, mixed> $binding
 * @return array{plaintext:string,hash:string,binding:array<string,mixed>,issued_at:string,expires_at:string}
 */
function orange_restore_approval_issue_token(array $binding): array
{
    $jobId = trim((string) ($binding['job_id'] ?? ''));
    if ($jobId === '') {
        throw new RuntimeException('Approval token binding requires job_id.');
    }

    $plaintext = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plaintext);
    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + ORANGE_RESTORE_APPROVAL_TOKEN_TTL_SECONDS);

    $fullBinding = array_merge($binding, [
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ]);

    return [
        'plaintext' => $plaintext,
        'hash' => $hash,
        'binding' => $fullBinding,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ];
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $binding
 */
function orange_restore_approval_store_token_on_job(array $job, string $tokenHash, array $binding): array
{
    $job['approval_token_hash'] = $tokenHash;
    $job['approval_token_binding'] = $binding;
    $job['approval_token_issued_at'] = (string) ($binding['issued_at'] ?? '');
    $job['approval_token_expires_at'] = (string) ($binding['expires_at'] ?? '');
    $job['approval_token_consumed_at'] = '';
    $job['approval_token_invalidated_at'] = '';

    return $job;
}

function orange_restore_approval_invalidate_token(array $job, string $reason = ''): array
{
    if (($job['approval_token_hash'] ?? '') !== '') {
        $job['approval_token_invalidated_at'] = gmdate('c');
        if ($reason !== '') {
            $job['approval_token_invalidation_reason'] = $reason;
        }
        $job['approval_token_hash'] = '';
        $job['approval_token_binding'] = [];
    }

    return $job;
}

/**
 * @param array<string, mixed> $job
 * @return array{ok:bool,error:?string,binding:array<string,mixed>}
 */
function orange_restore_approval_verify_token(array $job, string $plaintextToken, bool $consume = false): array
{
    $hash = (string) ($job['approval_token_hash'] ?? '');
    if ($hash === '') {
        return ['ok' => false, 'error' => 'Approval token missing or invalidated.', 'binding' => []];
    }

    if ((string) ($job['approval_token_consumed_at'] ?? '') !== '') {
        return ['ok' => false, 'error' => 'Approval token already consumed.', 'binding' => []];
    }

    if ((string) ($job['approval_token_invalidated_at'] ?? '') !== '') {
        return ['ok' => false, 'error' => 'Approval token invalidated.', 'binding' => []];
    }

    $expiresAt = (string) ($job['approval_token_expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) !== false && time() > strtotime($expiresAt)) {
        return ['ok' => false, 'error' => 'Approval token expired.', 'binding' => []];
    }

    if (!hash_equals($hash, hash('sha256', $plaintextToken))) {
        return ['ok' => false, 'error' => 'Approval token mismatch.', 'binding' => []];
    }

    /** @var array<string, mixed> $binding */
    $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
    if (($binding['job_id'] ?? '') !== ($job['job_id'] ?? '')) {
        return ['ok' => false, 'error' => 'Approval token binding job_id mismatch.', 'binding' => []];
    }

    if ($consume) {
        $job['approval_token_consumed_at'] = gmdate('c');
    }

    return ['ok' => true, 'error' => null, 'binding' => $binding];
}

/** @deprecated Use orange_restore_approval_issue_token — kept for legacy self-test compatibility */
function orange_restore_generate_approval_token(string $jobId, string $packageChecksum): string
{
    unset($packageChecksum);
    $issued = orange_restore_approval_issue_token(['job_id' => $jobId]);

    return $issued['plaintext'];
}

/** @deprecated Use orange_restore_approval_verify_token */
function orange_restore_validate_approval_token(string $providedToken, string $expectedToken): bool
{
    if ($providedToken === '' || $expectedToken === '') {
        return false;
    }

    return hash_equals($expectedToken, $providedToken);
}

function orange_restore_approval_token_sidecar_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_APPROVAL_TOKEN_FILENAME;
}

/**
 * Persist hashed metadata only (never plaintext) for audit/reference.
 *
 * @param array<string, mixed> $metadata
 */
function orange_restore_approval_write_token_sidecar(string $workRoot, string $jobId, array $metadata): void
{
    $path = orange_restore_approval_token_sidecar_path($workRoot, $jobId);
    orange_backup_write_json($path, $metadata);
}

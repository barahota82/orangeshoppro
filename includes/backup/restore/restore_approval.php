<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';

const ORANGE_RESTORE_CONFIRMATION_FULL = 'RESTORE';

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

function orange_restore_generate_approval_token(string $jobId, string $packageChecksum): string
{
    if ($jobId === '' || $packageChecksum === '') {
        throw new RuntimeException('Approval token requires job id and package checksum.');
    }

    return hash('sha256', $jobId . '|' . $packageChecksum . '|' . bin2hex(random_bytes(16)));
}

function orange_restore_validate_approval_token(string $providedToken, string $expectedToken): bool
{
    if ($providedToken === '' || $expectedToken === '') {
        return false;
    }

    return hash_equals($expectedToken, $providedToken);
}

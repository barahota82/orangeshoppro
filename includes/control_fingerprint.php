<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A — Control Trust-Core fingerprints and reference validators.
 *
 * Local / contained proof only. Not included by Production runtime.
 * PHP is the sole Production sealing authority for hashes.
 */

const ORANGE_CONTROL_FP_SEP = "\x1F";
const ORANGE_CONTROL_SNAPSHOT_NULL_TOKEN = "\x00";

final class OrangeControlTrustException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message = '')
    {
        $this->errorCode = $errorCode;
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * Reject control bytes, NUL..0x1F, DEL, and non-ASCII for reference strings.
 */
function orange_control_ref_has_forbidden_bytes(string $raw): bool
{
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $b = ord($raw[$i]);
        if ($b <= 0x1F || $b === 0x7F || $b >= 0x80) {
            return true;
        }
    }

    return false;
}

/**
 * Distinct secret_ref validator (Owner clarification — not a union validator).
 * Normalize: strtolower(trim). Store lowercase ASCII only.
 */
function orange_control_validate_secret_ref(string $value): string
{
    $s = strtolower(trim($value));
    if ($s === '') {
        throw new OrangeControlTrustException('empty_secret_ref');
    }
    if (strlen($s) > 191) {
        throw new OrangeControlTrustException('secret_ref_too_long');
    }
    if (orange_control_ref_has_forbidden_bytes($s) || str_contains($s, ORANGE_CONTROL_FP_SEP)) {
        throw new OrangeControlTrustException('secret_ref_forbidden_byte');
    }
    if (!preg_match('#^orange://secrets/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#', $s)) {
        throw new OrangeControlTrustException('secret_ref_invalid');
    }
    // Explicit segment rejects (defense in depth vs grammar).
    $path = substr($s, strlen('orange://secrets/'));
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            throw new OrangeControlTrustException('secret_ref_bad_segment');
        }
    }

    return $s;
}

/**
 * Distinct runtime_principal_ref validator (Owner clarification).
 */
function orange_control_validate_runtime_principal_ref(string $value): string
{
    $s = strtolower(trim($value));
    if ($s === '') {
        throw new OrangeControlTrustException('empty_runtime_principal_ref');
    }
    if (strlen($s) > 191) {
        throw new OrangeControlTrustException('runtime_principal_ref_too_long');
    }
    if (orange_control_ref_has_forbidden_bytes($s) || str_contains($s, ORANGE_CONTROL_FP_SEP)) {
        throw new OrangeControlTrustException('runtime_principal_ref_forbidden_byte');
    }
    if (!preg_match('#^orange://principals/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#', $s)) {
        throw new OrangeControlTrustException('runtime_principal_ref_invalid');
    }
    $path = substr($s, strlen('orange://principals/'));
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            throw new OrangeControlTrustException('runtime_principal_ref_bad_segment');
        }
    }

    return $s;
}

function orange_control_assert_uuid_lowercase(string $uuid, string $code): string
{
    $u = strtolower(trim($uuid));
    if ($u === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u)) {
        throw new OrangeControlTrustException($code);
    }

    return $u;
}

function orange_control_assert_hex64(string $hex, string $code): string
{
    $h = strtolower(trim($hex));
    if (!preg_match('/^[0-9a-f]{64}$/', $h)) {
        throw new OrangeControlTrustException($code);
    }

    return $h;
}

function orange_control_assert_lower_ascii_ident(string $name, string $code): string
{
    $n = strtolower(trim($name));
    if ($n === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $n) || str_contains($n, '#')) {
        throw new OrangeControlTrustException($code);
    }

    return $n;
}

/**
 * @param array<string, mixed> $fields
 */
function orange_control_fingerprint_v1_canonical(array $fields): string
{
    $version = isset($fields['fingerprint_version']) ? (int) $fields['fingerprint_version'] : 0;
    if ($version !== 1) {
        throw new OrangeControlTrustException('bad_fingerprint_version');
    }
    $countryUuid = orange_control_assert_uuid_lowercase((string) ($fields['country_uuid'] ?? ''), 'bad_country_uuid');
    $databaseUuid = orange_control_assert_uuid_lowercase((string) ($fields['database_uuid'] ?? ''), 'bad_database_uuid');
    $hostKey = orange_control_assert_lower_ascii_ident((string) ($fields['host_profile_key'] ?? ''), 'bad_host_profile_key');
    $dbName = orange_control_assert_lower_ascii_ident((string) ($fields['db_name'] ?? ''), 'bad_db_name');
    if (!array_key_exists('installed_schema_revision', $fields) || $fields['installed_schema_revision'] === null || $fields['installed_schema_revision'] === '') {
        throw new OrangeControlTrustException('bad_installed_schema_revision');
    }
    $rev = (int) $fields['installed_schema_revision'];
    $template = orange_control_assert_hex64((string) ($fields['schema_template_hash'] ?? ''), 'bad_schema_template_hash');

    return implode(ORANGE_CONTROL_FP_SEP, [
        (string) $version,
        $countryUuid,
        $databaseUuid,
        $hostKey,
        $dbName,
        (string) $rev,
        $template,
    ]);
}

/**
 * @param array<string, mixed> $fields
 */
function orange_control_fingerprint_v1_hash(array $fields): string
{
    $canonical = orange_control_fingerprint_v1_canonical($fields);
    $hash = hash('sha256', $canonical, false);
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        throw new OrangeControlTrustException('fingerprint_hash_corrupt');
    }

    return $hash;
}

/**
 * @param array<string, mixed> $fields
 */
function orange_control_fingerprint_v1_assert_match(array $fields, string $expectedHex): void
{
    $got = orange_control_fingerprint_v1_hash($fields);
    $exp = orange_control_assert_hex64($expectedHex, 'bad_expected_fingerprint');
    if (!hash_equals($exp, $got)) {
        throw new OrangeControlTrustException('fingerprint_mismatch');
    }
}

/**
 * Snapshot hash V1 — field order includes secret_version (supersedes obsolete S1–S3).
 *
 * @param array<string, mixed> $fields
 */
function orange_control_snapshot_hash_v1_canonical(array $fields): string
{
    $order = [
        'snapshot_hash_version',
        'registry_id',
        'country_id',
        'country_uuid',
        'database_uuid',
        'db_key',
        'db_name',
        'host_profile_id',
        'host_profile_key',
        'secret_ref',
        'secret_version',
        'runtime_principal_ref',
        'required_schema_revision',
        'installed_schema_revision',
        'schema_template_hash',
        'identity_fingerprint',
        'registry_status',
        'row_version',
    ];
    $parts = [];
    foreach ($order as $k) {
        if (!array_key_exists($k, $fields)) {
            throw new OrangeControlTrustException('snapshot_missing_' . $k);
        }
        $v = $fields[$k];
        if ($v === null) {
            if ($k !== 'installed_schema_revision') {
                throw new OrangeControlTrustException('snapshot_null_not_allowed_' . $k);
            }
            $parts[] = ORANGE_CONTROL_SNAPSHOT_NULL_TOKEN;
            continue;
        }
        if ($k === 'secret_ref') {
            $parts[] = orange_control_validate_secret_ref((string) $v);
            continue;
        }
        if ($k === 'runtime_principal_ref') {
            $parts[] = orange_control_validate_runtime_principal_ref((string) $v);
            continue;
        }
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) && in_array($k, [
            'snapshot_hash_version', 'registry_id', 'country_id', 'host_profile_id',
            'secret_version', 'required_schema_revision', 'installed_schema_revision', 'row_version',
        ], true))) {
            $parts[] = (string) (int) $v;
            continue;
        }
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            throw new OrangeControlTrustException('snapshot_empty_' . $k);
        }
        if ($k === 'country_uuid' || $k === 'database_uuid') {
            $s = orange_control_assert_uuid_lowercase($s, 'snapshot_bad_' . $k);
        } elseif ($k === 'schema_template_hash' || $k === 'identity_fingerprint') {
            $s = orange_control_assert_hex64($s, 'snapshot_bad_' . $k);
        } elseif ($k === 'db_key' || $k === 'db_name' || $k === 'host_profile_key') {
            $s = orange_control_assert_lower_ascii_ident($s, 'snapshot_bad_' . $k);
        } elseif ($k === 'registry_status') {
            $s = strtolower(trim($s));
            if ($s === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $s)) {
                throw new OrangeControlTrustException('snapshot_bad_registry_status');
            }
        }
        $parts[] = $s;
    }

    return implode(ORANGE_CONTROL_FP_SEP, $parts);
}

/**
 * @param array<string, mixed> $fields
 */
function orange_control_snapshot_hash_v1(array $fields): string
{
    $canonical = orange_control_snapshot_hash_v1_canonical($fields);
    $hash = hash('sha256', $canonical, false);
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        throw new OrangeControlTrustException('snapshot_hash_corrupt');
    }

    return $hash;
}

/**
 * Approved golden identity fingerprint vectors V1–V5 (Phase 2.0C).
 *
 * @return array<string, mixed>
 */
function orange_control_fingerprint_v1_fixture(string $name): array
{
    $template = str_repeat('a', 64);
    $kw = [
        'fingerprint_version' => 1,
        'country_uuid' => '11111111-1111-4111-8111-111111111111',
        'database_uuid' => '22222222-2222-4222-8222-222222222222',
        'host_profile_key' => 'local_loopback',
        'db_name' => 'orange_country_kw_001',
        'installed_schema_revision' => 124,
        'schema_template_hash' => $template,
    ];
    switch ($name) {
        case 'V1':
            return $kw;
        case 'V2':
            $eg = $kw;
            $eg['country_uuid'] = '33333333-3333-4333-8333-333333333333';
            $eg['database_uuid'] = '44444444-4444-4444-8444-444444444444';
            $eg['db_name'] = 'orange_country_eg_001';

            return $eg;
        case 'V3':
            $mixed = $kw;
            $mixed['host_profile_key'] = 'Local_Loopback';
            $mixed['db_name'] = 'Orange_Country_KW_001';
            $mixed['schema_template_hash'] = str_repeat('A', 64);

            return $mixed;
        case 'V5':
            $diff = $kw;
            $diff['db_name'] = 'orange_country_kw_002';

            return $diff;
        default:
            throw new OrangeControlTrustException('unknown_fingerprint_fixture');
    }
}

/** @return array<string, string> */
function orange_control_fingerprint_v1_expected(): array
{
    return [
        'V1' => 'd56e93a305e16acf666d5dae2ba090732fdf6728890d01703c13c56617bd1f13',
        'V2' => 'de10dc6ab170fcbbe74adcc36a2b5610b9527f2f089e4c5843c946dab222dee3',
        'V5' => 'e52192ad5e848b80fbcf2c94e508a5cb7fb02af07d644c205815419bb60142db',
    ];
}

/**
 * Approved revised snapshot vectors R1–R5 (Phase 2.0E). S1–S3 are obsolete.
 *
 * @return array<string, mixed>
 */
function orange_control_snapshot_v1_fixture(string $name): array
{
    $base = [
        'snapshot_hash_version' => 1,
        'registry_id' => 10,
        'country_id' => 1,
        'country_uuid' => '11111111-1111-4111-8111-111111111111',
        'database_uuid' => '22222222-2222-4222-8222-222222222222',
        'db_key' => 'rt_kw_001',
        'db_name' => 'orange_country_kw_001',
        'host_profile_id' => 1,
        'host_profile_key' => 'local_loopback',
        'secret_ref' => 'orange://secrets/country_runtime/kw',
        'secret_version' => 1,
        'runtime_principal_ref' => 'orange://principals/country_runtime/kw',
        'required_schema_revision' => 124,
        'installed_schema_revision' => 124,
        'schema_template_hash' => str_repeat('a', 64),
        'identity_fingerprint' => 'd56e93a305e16acf666d5dae2ba090732fdf6728890d01703c13c56617bd1f13',
        'registry_status' => 'active',
        'row_version' => 1,
    ];
    switch ($name) {
        case 'R1':
            return $base;
        case 'R2':
            $r = $base;
            $r['row_version'] = 2;

            return $r;
        case 'R3':
            $r = $base;
            $r['installed_schema_revision'] = null;

            return $r;
        case 'R4':
            $r = $base;
            $r['secret_version'] = 2;

            return $r;
        case 'R5':
            $r = $base;
            $r['secret_ref'] = 'Orange://Secrets/Country_Runtime/KW';
            $r['runtime_principal_ref'] = 'Orange://Principals/Country_Runtime/KW';

            return $r;
        default:
            throw new OrangeControlTrustException('unknown_snapshot_fixture');
    }
}

/** @return array<string, string> */
function orange_control_snapshot_v1_expected(): array
{
    return [
        'R1' => '84b746caeefc83b000e2b95aefb9f8f4c410df6e86484f68e315210d766b9bd3',
        'R2' => 'adfe3c42f6566d1df20063927ea501a149c6ca52310bcbb51218d5674304a861',
        'R3' => 'b7a0850aebd5eec4cb9ed6db38acfa1429e57fcf8c313ef28e370a4dd68b9b5e',
        'R4' => 'ee7fd05a4cffc498386b7ee5201c5baa09550690530cb8d5d58a690dd3a74a4e',
    ];
}

/** Obsolete S1–S3 hashes — must never be accepted as current expected fixtures. */
function orange_control_obsolete_snapshot_hashes(): array
{
    return [
        'S1' => '88da3f3fdd8cc3d23682103ecf0a63d9ddb284f8b6fbabbe419f2f908a635f12',
        'S2' => 'd7bb49f5b4478682a9c4ebb7b944bbc9c23d6c45af6249c33b5fce0e77c6988c',
        'S3' => '19273e7bd7d933cb2008d4bc9461305e7d5504cf099e64d15114116b7558e25f',
    ];
}

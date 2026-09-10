<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$renumberPath = $root . '/includes/db_id_renumber.php';
$schemaPath = $root . '/includes/catalog_schema.php';

require_once $schemaPath;
require_once $renumberPath;

$passes = 0;
$failures = [];

$check = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) {
        ++$passes;

        return;
    }
    $failures[] = $label;
};

$functionBody = static function (string $source, string $functionName): string {
    $needle = 'function ' . $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $open = strpos($source, '{', $start);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; ++$i) {
        if ($source[$i] === '{') {
            ++$depth;
        } elseif ($source[$i] === '}') {
            --$depth;
            if ($depth === 0) {
                return substr($source, $open + 1, $i - $open - 1);
            }
        }
    }

    return '';
};

$renumberSource = file_get_contents($renumberPath);
$schemaSource = file_get_contents($schemaPath);
if (!is_string($renumberSource) || !is_string($schemaSource)) {
    fwrite(STDERR, "FAIL: unable to read production sources\n");
    exit(1);
}

// Pure classification controls: no PDO/MySQL is touched.
$check(
    orange_db_id_renumber_table_kind(true, false) === 'non_id_non_auto_increment',
    'no-id table is classified non-id/non-auto-increment'
);
$check(
    orange_db_id_renumber_table_kind(false, false) === 'missing',
    'missing table classification'
);
$check(
    orange_db_id_renumber_table_kind(true, true) === 'id_auto_increment_candidate',
    'positive control: id table remains eligible'
);

foreach (
    [
        'orange_db_ids_dense_from_one' => 'SELECT MIN(id)',
        'orange_db_build_dense_id_map' => 'SELECT id FROM',
        'orange_db_renumber_table_to_dense_ids' => 'SELECT id FROM',
        'orange_db_align_table_auto_increment' => 'MAX(id)',
    ] as $functionName => $idSql
) {
    $body = $functionBody($renumberSource, $functionName);
    $guardPos = strpos($body, 'orange_db_table_has_id_column');
    $sqlPos = strpos($body, $idSql);
    $check(
        $body !== '' && $guardPos !== false && $sqlPos !== false && $guardPos < $sqlPos,
        $functionName . ' checks id before id-dependent SQL'
    );
}

$phase3 = $functionBody($renumberSource, 'orange_db_id_renumber_run_phase3');
$check(
    str_contains($phase3, "orange_db_id_renumber_table_kind(\$allocExists, \$allocHasId)")
    && str_contains($phase3, "'id_auto_increment_candidate'"),
    'orange_gl_setting_alloc uses explicit no-id classification gate'
);

// Pure positioning seam: no AFTER for no-id, positive control preserves AFTER id.
$check(
    orange_catalog_country_id_position_clause('id', false) === '',
    'no-id table receives no AFTER id clause'
);
$check(
    orange_catalog_country_id_position_clause('id', true) === ' AFTER `id`',
    'positive control: proven id anchor is retained'
);
$check(
    orange_catalog_country_id_followup_allowed(false) === false
    && orange_catalog_country_id_followup_allowed(true) === true,
    'dependent DDL gate mirrors post-add column recheck'
);

$countryAdd = $functionBody($schemaSource, 'orange_catalog_ensure_country_id_column');
$invalidatePos = strpos($countryAdd, "orange_schema_invalidate_column_check(\$table, 'country_id')");
$recheckPos = strpos($countryAdd, "\$existsAfterAdd = orange_table_has_column(\$pdo, \$table, 'country_id')");
$dependentPos = strpos($countryAdd, 'orange_catalog_ensure_country_id_index');
$check(
    $countryAdd !== ''
    && $invalidatePos !== false
    && $recheckPos !== false
    && $dependentPos !== false
    && $invalidatePos < $recheckPos
    && $recheckPos < $dependentPos,
    'country_id add invalidates cache and rechecks before dependent index'
);

$check(
    preg_match('/ADD\s+COLUMN\s+country_id\b[^;\r\n]*\bAFTER\s+id\b/i', $schemaSource) !== 1,
    'catalog schema has no unconditional country_id AFTER id'
);
$check(
    str_contains(
        $schemaSource,
        "orange_catalog_ensure_country_id_column(\$pdo, 'orange_gl_setting_alloc', 'setting_key')"
    ),
    'orange_gl_setting_alloc uses its proven setting_key anchor'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    fwrite(STDERR, 'RESULT: FAIL (' . count($failures) . ' failed, ' . $passes . " passed)\n");
    exit(1);
}

fwrite(STDOUT, 'RESULT: PASS (' . $passes . " checks)\n");

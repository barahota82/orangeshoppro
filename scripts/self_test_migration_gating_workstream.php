<?php

declare(strict_types=1);

/**
 * Pure/static migration-gating certification. No DB or network is opened.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/catalog_schema.php';
require_once $root . '/includes/schema_migrations.php';

$passes = 0;
$failures = [];
$check = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $label;
};

final class OrangeMigrationGateFakePdo extends PDO
{
    public int $interactions = 0;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->interactions++;
        throw new RuntimeException('Unexpected query');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->interactions++;
        throw new RuntimeException('Unexpected prepare');
    }

    public function exec(string $statement): int|false
    {
        $this->interactions++;
        throw new RuntimeException('Unexpected exec');
    }
}

// Per-PDO gate matrix: order and state on one connection never leak to another.
$older = new OrangeMigrationGateFakePdo();
$steady = new OrangeMigrationGateFakePdo();
$check(orange_schema_gate_route(123) === 'php_catchup', 'older revision preserves PHP catch-up route');
$check(orange_schema_gate_route(124) === 'steady', 'revision 124 uses steady route');
$check(
    orange_schema_steady_state_gate_cached($older) === null
    && orange_schema_steady_state_gate_cached($steady) === null,
    'two PDO identities start uncached'
);
orange_schema_steady_state_gate_mark($older, 'ok');
$check(
    orange_schema_steady_state_gate_cached($older) === 'ok'
    && orange_schema_steady_state_gate_cached($steady) === null,
    'older then steady connection cache is independent'
);
orange_schema_steady_state_gate_mark($steady, 'degraded');
$check(
    orange_schema_steady_state_gate_cached($steady) === 'degraded'
    && orange_schema_steady_state_gate_cached($older) === 'ok',
    'steady then older connection cache is independent'
);

// Normal web/backup-style calls cannot reach migration metadata or increment attempts.
$check(!orange_schema_numbered_sql_apply_allowed(), 'numbered SQL denied without explicit maintenance opt-in');
for ($i = 0; $i < 5; $i++) {
    orange_schema_run_pending_migrations($older);
    orange_schema_run_pending_migrations($steady);
}
$check(
    $older->interactions === 0 && $steady->interactions === 0,
    'repeated simulated web/backup calls perform zero DB interactions'
);

// Parser matrix: BOM, all newline forms, Arabic comments, and quoted semicolons.
$fixtures = [
    'LF' => "\xEF\xBB\xBF-- تعليق عربي\nINSERT INTO demo (value) VALUES ('أ;ب');\nUPDATE demo SET value=\"x;y\";",
    'CRLF' => "-- تعليق عربي\r\nINSERT INTO demo (value) VALUES ('أ;ب');\r\nUPDATE demo SET value=\"x;y\";",
    'CR' => "-- تعليق عربي\rINSERT INTO demo (value) VALUES ('أ;ب');\rUPDATE demo SET value=\"x;y\";",
];
foreach ($fixtures as $name => $fixture) {
    $parts = orange_schema_migration_split_statements($fixture);
    $check(
        count($parts) === 2
        && str_contains($parts[0], "'أ;ب'")
        && str_contains($parts[1], '"x;y"')
        && !str_contains(implode("\n", $parts), 'تعليق عربي'),
        'parser handles ' . $name . ' fixture safely'
    );
}
$blockParts = orange_schema_migration_split_statements(
    "/* تعليق؛ عربي */\nINSERT INTO `demo;name` (`value`) VALUES ('it''s;safe'); # نهاية\nDELETE FROM demo WHERE value='z;1';"
);
$check(
    count($blockParts) === 2 && str_contains($blockParts[0], "'it''s;safe'"),
    'parser handles block/hash comments and doubled quotes'
);

// Diagnostic contains only structural evidence.
$exception = new PDOException(
    "SQLSTATE[42000]: Syntax error 1064 near password='hunter2'; token=secret-token; SELECT * FROM users"
);
$exception->errorInfo = ['42000', 1064, 'raw secret'];
$statement = "INSERT INTO users (password, token) VALUES ('hunter2', 'secret-token')";
$diagnostic = orange_schema_migration_statement_diagnostic('013_sensitive.sql', 7, $statement, $exception);
$encodedDiagnostic = json_encode($diagnostic, JSON_UNESCAPED_SLASHES);
$check(
    ($diagnostic['filename'] ?? '') === '013_sensitive.sql'
    && ($diagnostic['statement_ordinal'] ?? 0) === 7
    && ($diagnostic['sha256'] ?? '') === hash('sha256', $statement)
    && ($diagnostic['operation'] ?? '') === 'INSERT'
    && ($diagnostic['table'] ?? '') === 'users'
    && ($diagnostic['sqlstate'] ?? '') === '42000'
    && ($diagnostic['errno'] ?? 0) === 1064,
    'diagnostic retains filename ordinal hash table SQLSTATE errno'
);
$check(
    is_string($encodedDiagnostic)
    && !str_contains($encodedDiagnostic, 'hunter2')
    && !str_contains($encodedDiagnostic, 'secret-token')
    && !str_contains($encodedDiagnostic, 'VALUES')
    && !str_contains($encodedDiagnostic, 'SELECT *'),
    'diagnostic redacts literal values credentials and full SQL'
);

$schemaMigrationsSource = (string) file_get_contents($root . '/includes/schema_migrations.php');
$catalogSource = (string) file_get_contents($root . '/includes/catalog_schema.php');
$healthSource = (string) file_get_contents($root . '/health.php');
$webCatchupStart = strpos($catalogSource, 'function orange_catalog_schema_web_version_catchup');
$webCatchupEnd = strpos($catalogSource, 'function orange_catalog_schema_integrity_migrations', $webCatchupStart ?: 0);
$webCatchupBody = $webCatchupStart !== false && $webCatchupEnd !== false
    ? substr($catalogSource, $webCatchupStart, $webCatchupEnd - $webCatchupStart)
    : '';
$check(
    str_contains($webCatchupBody, 'orange_catalog_ensure_schema_fast_path_slice')
    && !str_contains($webCatchupBody, 'orange_schema_run_numbered_sql_chain')
    && !str_contains($webCatchupBody, 'orange_schema_run_pending_migrations'),
    'older first-request catch-up remains PHP-only'
);
$check(
    !str_contains($schemaMigrationsSource, 'DELETE FROM orange_schema_migration_failures')
    && str_contains($schemaMigrationsSource, 'orange_schema_migration_recent_failures($pdo)'),
    'failure rows remain and cooldown stays active'
);
$check(
    str_contains($schemaMigrationsSource, 'Numbered SQL migration is in failure cooldown:')
    && str_contains($schemaMigrationsSource, 'Numbered SQL migration failed:')
    && str_contains($schemaMigrationsSource, '$cache[$pdo] = true;'),
    'explicit apply fails closed on cooldown or execution failure'
);
$cacheMarkPos = strrpos($schemaMigrationsSource, '$cache[$pdo] = true;');
$failureThrowPos = strpos($schemaMigrationsSource, 'Numbered SQL migration failed:');
$check(
    $cacheMarkPos !== false && $failureThrowPos !== false && $failureThrowPos < $cacheMarkPos,
    'numbered SQL connection cache is marked only after failure paths'
);
$check(
    !str_contains($healthSource, 'orange_run_migrations($pdoRollout)')
    && str_contains($healthSource, 'orange_catalog_ensure_schema($pdoRollout)'),
    'db-id-renumber health probe does not call numbered SQL apply path'
);

// Script contract via callback seam: refusal is side-effect free; --apply reaches runner once.
define('ORANGE_RUN_MIGRATIONS_TEST_LIBRARY', true);
require_once $root . '/scripts/run_migrations.php';
$runnerCalls = 0;
ob_start();
$refused = orange_run_migrations_cli_main(['run_migrations.php'], static function () use (&$runnerCalls): void {
    $runnerCalls++;
});
ob_end_clean();
$check($refused === 2 && $runnerCalls === 0, 'maintenance without --apply refuses before runner');
ob_start();
$applied = orange_run_migrations_cli_main(['run_migrations.php', '--apply'], static function () use (&$runnerCalls): void {
    $runnerCalls++;
});
ob_end_clean();
$check($applied === 0 && $runnerCalls === 1, 'maintenance with --apply reaches mocked runner once');
$check(orange_schema_numbered_sql_apply_allowed(), 'apply path defines narrowly scoped maintenance context');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    fwrite(STDERR, 'RESULT: FAIL (' . count($failures) . ' failed, ' . $passes . " passed)\n");
    exit(1);
}

fwrite(STDOUT, 'RESULT: PASS (' . $passes . " checks)\n");
exit(0);

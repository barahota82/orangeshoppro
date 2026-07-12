<?php

declare(strict_types=1);

const ORANGE_BACKUP_PDO_EXPORTER_VERSION = '1.0.0';
const ORANGE_BACKUP_PDO_INSERT_CHUNK_ROWS = 100;
const ORANGE_BACKUP_PDO_MIN_DUMP_BYTES = 64;

/**
 * Maintenance/audit routines outside Orange runtime — ignored by PDO preflight/export.
 *
 * @return list<string>
 */
function orange_backup_pdo_maintenance_routine_names(): array
{
    return [
        'check_empty_tables_id_starts_at_one',
    ];
}

/**
 * @return list<array{name:string,type:string}>
 */
function orange_backup_pdo_list_routines(PDO $pdo, string $databaseName): array
{
    $st = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = ?
         ORDER BY ROUTINE_NAME ASC'
    );
    $st->execute([$databaseName]);
    $routines = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $name = (string) ($row['ROUTINE_NAME'] ?? '');
        if ($name === '') {
            continue;
        }
        $routines[] = [
            'name' => $name,
            'type' => (string) ($row['ROUTINE_TYPE'] ?? ''),
        ];
    }

    return $routines;
}

/**
 * @return array{ready:bool,error:?string,warnings:list<string>}
 */
function orange_backup_pdo_export_preflight(PDO $pdo, string $databaseName): array
{
    $warnings = [];
    $maintenanceAllowlist = array_fill_keys(
        array_map(static fn (string $name): string => strtolower($name), orange_backup_pdo_maintenance_routine_names()),
        true
    );

    $ignoredMaintenance = [];
    $runtimeRoutines = [];
    foreach (orange_backup_pdo_list_routines($pdo, $databaseName) as $routine) {
        $key = strtolower($routine['name']);
        if (isset($maintenanceAllowlist[$key])) {
            $ignoredMaintenance[] = $routine['name'];
            continue;
        }
        $runtimeRoutines[] = $routine;
    }

    if ($runtimeRoutines !== []) {
        $labels = array_map(
            static fn (array $routine): string => $routine['name'] . ' (' . $routine['type'] . ')',
            $runtimeRoutines
        );

        return [
            'ready' => false,
            'error' => 'PDO export cannot safely include non-maintenance routines (found ' . count($runtimeRoutines) . '): '
                . implode(', ', $labels) . '. Use mysqldump/PowerShell or add maintenance-only allowlist entries.',
            'warnings' => $warnings,
        ];
    }

    if ($ignoredMaintenance !== []) {
        $warnings[] = 'PDO export ignores maintenance-only routines (not Orange runtime): '
            . implode(', ', $ignoredMaintenance);
    }

    foreach ([
        'TRIGGER' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
        'EVENT' => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
    ] as $label => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$databaseName]);
        $count = (int) ($st->fetchColumn() ?: 0);
        if ($count > 0) {
            return [
                'ready' => false,
                'error' => 'PDO export cannot safely include ' . strtolower($label) . 's (found ' . $count . '). Use mysqldump/PowerShell or remove unsupported objects.',
                'warnings' => $warnings,
            ];
        }
    }

    $warnings[] = 'PDO export does not include routines, triggers, or events.';

    return ['ready' => true, 'error' => null, 'warnings' => $warnings];
}

/**
 * @return list<string>
 */
function orange_backup_pdo_list_tables(PDO $pdo, string $databaseName): array
{
    $st = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'
         ORDER BY TABLE_NAME ASC'
    );
    $st->execute([$databaseName]);
    $tables = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $name = (string) ($row['TABLE_NAME'] ?? '');
        if ($name !== '') {
            $tables[] = $name;
        }
    }

    return $tables;
}

/**
 * @return list<array{name:string,data_type:string,extra:string,generation_expression:?string}>
 */
function orange_backup_pdo_table_columns(PDO $pdo, string $databaseName, string $tableName): array
{
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, EXTRA, GENERATION_EXPRESSION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION ASC'
    );
    $st->execute([$databaseName, $tableName]);
    $columns = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = [
            'name' => (string) ($row['COLUMN_NAME'] ?? ''),
            'data_type' => strtolower((string) ($row['DATA_TYPE'] ?? '')),
            'extra' => strtolower((string) ($row['EXTRA'] ?? '')),
            'generation_expression' => $row['GENERATION_EXPRESSION'] ?? null,
        ];
    }

    return $columns;
}

/**
 * @param list<array{name:string,data_type:string,extra:string,generation_expression:?string}> $columns
 * @return list<string>
 */
function orange_backup_pdo_insertable_column_names(array $columns): array
{
    $names = [];
    foreach ($columns as $column) {
        $generation = $column['generation_expression'];
        if ($generation !== null && trim((string) $generation) !== '') {
            continue;
        }
        if (str_contains($column['extra'], 'virtual generated') || str_contains($column['extra'], 'stored generated')) {
            continue;
        }
        if ($column['name'] !== '') {
            $names[] = $column['name'];
        }
    }

    return $names;
}

function orange_backup_pdo_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function orange_backup_pdo_sql_literal(PDO $pdo, mixed $value, string $dataType): string
{
    if ($value === null) {
        return 'NULL';
    }

    $binaryTypes = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit'];
    if (in_array($dataType, $binaryTypes, true)) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        return '0x' . bin2hex($value);
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (!is_string($value)) {
        $value = (string) $value;
    }

    return $pdo->quote($value);
}

/**
 * @param list<string> $insertColumns
 * @param list<array{name:string,data_type:string,extra:string,generation_expression:?string}> $columnMeta
 * @param list<array<string,mixed>> $rows
 */
function orange_backup_pdo_write_insert_chunk(
    PDO $pdo,
    $handle,
    string $tableName,
    array $insertColumns,
    array $columnMeta,
    array $rows
): void {
    if ($rows === [] || $insertColumns === []) {
        return;
    }

    $metaByName = [];
    foreach ($columnMeta as $column) {
        $metaByName[$column['name']] = $column;
    }

    $columnSql = implode(', ', array_map(orange_backup_pdo_quote_identifier(...), $insertColumns));
    $tableSql = orange_backup_pdo_quote_identifier($tableName);
    $valueGroups = [];

    foreach ($rows as $row) {
        $values = [];
        foreach ($insertColumns as $columnName) {
            $dataType = $metaByName[$columnName]['data_type'] ?? 'varchar';
            $values[] = orange_backup_pdo_sql_literal($pdo, $row[$columnName] ?? null, $dataType);
        }
        $valueGroups[] = '(' . implode(', ', $values) . ')';
    }

    fwrite($handle, 'INSERT INTO ' . $tableSql . ' (' . $columnSql . ") VALUES\n");
    fwrite($handle, implode(",\n", $valueGroups));
    fwrite($handle, ";\n");
}

function orange_backup_pdo_write_preamble($handle): void
{
    fwrite($handle, "-- Orange Phase 1A PDO SQL export\n");
    fwrite($handle, '-- backup_engine_version=' . ORANGE_BACKUP_PDO_EXPORTER_VERSION . "\n");
    fwrite($handle, "SET NAMES utf8mb4;\n");
    fwrite($handle, "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n");
    fwrite($handle, "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($handle, "SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';\n\n");
}

function orange_backup_pdo_write_postamble($handle): void
{
    fwrite($handle, "\nSET TIME_ZONE=@OLD_TIME_ZONE;\n");
    fwrite($handle, "SET SQL_MODE=@OLD_SQL_MODE;\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n");
}

function orange_backup_pdo_begin_snapshot(PDO $pdo): void
{
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('PDO export could not start snapshot transaction.');
    }
}

function orange_backup_pdo_end_snapshot(PDO $pdo, bool $commit): void
{
    if (!$pdo->inTransaction()) {
        return;
    }
    if ($commit) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
}

/**
 * @return array{warnings:list<string>,table_count:int,row_count:int}
 */
function orange_backup_pdo_export_database(PDO $pdo, string $databaseName, string $outputSqlFile): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('PDO export is CLI-only.');
    }

    $preflight = orange_backup_pdo_export_preflight($pdo, $databaseName);
    if (!$preflight['ready']) {
        throw new RuntimeException((string) $preflight['error']);
    }

    $handle = fopen($outputSqlFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open PDO export output file.');
    }

    $committed = false;
    orange_backup_pdo_begin_snapshot($pdo);

    try {
        orange_backup_pdo_write_preamble($handle);

        $tables = orange_backup_pdo_list_tables($pdo, $databaseName);
        $rowCount = 0;

        foreach ($tables as $tableName) {
            $createSt = $pdo->query('SHOW CREATE TABLE ' . orange_backup_pdo_quote_identifier($tableName));
            $createRow = $createSt ? $createSt->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($createRow)) {
                throw new RuntimeException('SHOW CREATE TABLE failed for ' . $tableName);
            }
            $createSql = (string) ($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('Missing CREATE TABLE output for ' . $tableName);
            }

            fwrite($handle, "\nDROP TABLE IF EXISTS " . orange_backup_pdo_quote_identifier($tableName) . ";\n");
            fwrite($handle, $createSql . ";\n\n");

            $columnMeta = orange_backup_pdo_table_columns($pdo, $databaseName, $tableName);
            $insertColumns = orange_backup_pdo_insertable_column_names($columnMeta);
            if ($insertColumns === []) {
                continue;
            }

            $selectSql = 'SELECT ' . implode(', ', array_map(orange_backup_pdo_quote_identifier(...), $insertColumns))
                . ' FROM ' . orange_backup_pdo_quote_identifier($tableName);
            $dataSt = $pdo->query($selectSql);
            if ($dataSt === false) {
                throw new RuntimeException('Data read failed for table ' . $tableName);
            }

            $chunk = [];
            while ($row = $dataSt->fetch(PDO::FETCH_ASSOC)) {
                $rowCount++;
                $chunk[] = $row;
                if (count($chunk) >= ORANGE_BACKUP_PDO_INSERT_CHUNK_ROWS) {
                    orange_backup_pdo_write_insert_chunk($pdo, $handle, $tableName, $insertColumns, $columnMeta, $chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                orange_backup_pdo_write_insert_chunk($pdo, $handle, $tableName, $insertColumns, $columnMeta, $chunk);
            }
        }

        orange_backup_pdo_write_postamble($handle);
        fflush($handle);
        fclose($handle);
        $handle = null;

        orange_backup_pdo_validate_export_format($outputSqlFile, count($tables));
        orange_backup_pdo_end_snapshot($pdo, true);
        $committed = true;

        if (!is_file($outputSqlFile) || filesize($outputSqlFile) < ORANGE_BACKUP_PDO_MIN_DUMP_BYTES) {
            throw new RuntimeException('PDO export output is missing or too small.');
        }

        return [
            'warnings' => $preflight['warnings'],
            'table_count' => count($tables),
            'row_count' => $rowCount,
        ];
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($outputSqlFile)) {
            @unlink($outputSqlFile);
        }
        if (!$committed) {
            orange_backup_pdo_end_snapshot($pdo, false);
        }
        throw $e;
    }
}

function orange_backup_pdo_validate_export_format(string $sqlFile, int $tableCount): void
{
    if (!is_file($sqlFile)) {
        throw new RuntimeException('PDO export validation failed: file missing.');
    }

    $size = filesize($sqlFile);
    if ($size === false || $size < ORANGE_BACKUP_PDO_MIN_DUMP_BYTES) {
        throw new RuntimeException('PDO export validation failed: file too small.');
    }

    $head = (string) file_get_contents($sqlFile, false, null, 0, 4096);
    $required = [
        'SET NAMES utf8mb4',
        'FOREIGN_KEY_CHECKS=0',
        'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS',
    ];
    foreach ($required as $needle) {
        if (!str_contains($head, $needle) && !str_contains((string) file_get_contents($sqlFile), $needle)) {
            throw new RuntimeException('PDO export validation failed: missing ' . $needle);
        }
    }

    if ($tableCount > 0 && !preg_match('/CREATE TABLE `/', (string) file_get_contents($sqlFile))) {
        throw new RuntimeException('PDO export validation failed: CREATE TABLE missing.');
    }
}

/**
 * @return array{passed:int,failed:int}
 */
function orange_backup_pdo_export_self_test(PDO $pdo, string $databaseName): array
{
    $passed = 0;
    $failed = 0;
    $assert = static function (bool $ok) use (&$passed, &$failed): void {
        if ($ok) {
            $passed++;
        } else {
            $failed++;
        }
    };

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_pdo_export_selftest_' . bin2hex(random_bytes(4)) . '.sql';
    try {
        $result = orange_backup_pdo_export_database($pdo, $databaseName, $tmp);
        $assert(is_file($tmp));
        $assert(($result['table_count'] ?? 0) >= 0);
        orange_backup_pdo_validate_export_format($tmp, (int) ($result['table_count'] ?? 0));
        $assert(true);
    } catch (Throwable) {
        $assert(false);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    $literalPdo = new PDO('sqlite::memory:');
    $assert(orange_backup_pdo_sql_literal($literalPdo, null, 'varchar') === 'NULL');
    $assert(str_contains(orange_backup_pdo_sql_literal($literalPdo, "O'Reilly\n", 'varchar'), "'"));
    $assert(orange_backup_pdo_sql_literal($literalPdo, "\x00\xFF", 'blob') === '0x00ff');

    return ['passed' => $passed, 'failed' => $failed];
}

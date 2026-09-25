<?php
declare(strict_types=1);

/**
 * PREPARE ONLY — do not execute in the V2 preparation task.
 *
 * Later Owner-authorized isolated start:
 *   php ISOLATED_BRAND_CONTROL_BOOTSTRAP.php --sqlite=ABS_PATH_OUTSIDE_HTTPDOCS
 *
 * Creates a NEW SQLite file with:
 *   - ctrl_locales: exactly ar,en,fil,hi (is_active_global=1)
 *   - seven Brand Identity tables, all empty
 * Zero releases, zero current/active, zero objects, zero slot versions,
 * zero identity versions, zero audit events, zero visual-review files.
 *
 * Never opens MySQL. Never calls orange_control_ensure_schema / Rev6.
 * Never seeds the accepted ORANGE four or any synthetic identity.
 *
 * @see BRAND_CONTROL_CLEAN_STATE_CONTRACT.md
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$sqlite = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--sqlite=')) {
        $sqlite = (string) substr($arg, 9);
    }
}
$sqlite = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($sqlite));
if ($sqlite === '' || str_contains($sqlite, '..')) {
    fwrite(STDERR, "REFUSE: --sqlite=absolute_path required; no ..\n");
    exit(3);
}
if (!str_contains($sqlite, ':') && !str_starts_with($sqlite, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "REFUSE: sqlite path must be absolute\n");
    exit(3);
}
$norm = strtolower(str_replace('\\', '/', $sqlite));
foreach (['/httpdocs/', '/public_html/', '/wwwroot/'] as $pub) {
    if (str_contains($norm, $pub)) {
        fwrite(STDERR, "REFUSE: sqlite must be outside httpdocs/public_html/wwwroot\n");
        exit(3);
    }
}
if (is_file($sqlite)) {
    fwrite(STDERR, "REFUSE: target already exists (NEW file only): $sqlite\n");
    exit(4);
}
$dir = dirname($sqlite);
if (!is_dir($dir)) {
    fwrite(STDERR, "REFUSE: parent directory does not exist: $dir\n");
    exit(5);
}

$pdo = new PDO('sqlite:' . $sqlite, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('CREATE TABLE ctrl_locales (
  locale_code TEXT PRIMARY KEY,
  is_active_global INTEGER NOT NULL DEFAULT 1
)');
$ins = $pdo->prepare('INSERT INTO ctrl_locales (locale_code, is_active_global) VALUES (?, 1)');
foreach (['ar', 'en', 'fil', 'hi'] as $code) {
    $ins->execute([$code]);
}

$pdo->exec('CREATE TABLE orange_brand_identity_versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, state TEXT NOT NULL, default_locale TEXT NOT NULL,
  change_note TEXT NULL, created_by INTEGER NOT NULL, approved_by INTEGER NULL,
  created_at TEXT NOT NULL, previewed_at TEXT NULL, activated_at TEXT NULL, archived_at TEXT NULL)');
$pdo->exec('CREATE TABLE orange_brand_identity_translations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, identity_version_id INTEGER NOT NULL, locale TEXT NOT NULL,
  text_key TEXT NOT NULL, text_value TEXT NOT NULL,
  UNIQUE (identity_version_id, locale, text_key))');
$pdo->exec('CREATE TABLE orange_brand_asset_objects (
  sha256 TEXT PRIMARY KEY, mime TEXT NOT NULL, byte_size INTEGER NOT NULL, width INTEGER NOT NULL,
  height INTEGER NOT NULL, aspect_ratio REAL NOT NULL, alpha_flag INTEGER NOT NULL DEFAULT 0,
  alpha_note TEXT NOT NULL DEFAULT \'\', relpath TEXT NOT NULL, original_filename TEXT NOT NULL DEFAULT \'\',
  created_by INTEGER NOT NULL, created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE orange_brand_slot_versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, slot_code TEXT NOT NULL, original_object_id TEXT NOT NULL,
  derivative_object_id TEXT NULL, state TEXT NOT NULL, change_note TEXT NULL, uploaded_by INTEGER NOT NULL,
  approved_by INTEGER NULL, created_at TEXT NOT NULL, previewed_at TEXT NULL, activated_at TEXT NULL, archived_at TEXT NULL)');
$pdo->exec('CREATE TABLE orange_brand_releases (
  id INTEGER PRIMARY KEY AUTOINCREMENT, identity_version_id INTEGER NOT NULL, state TEXT NOT NULL,
  note TEXT NULL, current_flag INTEGER NULL, created_by INTEGER NOT NULL, approved_by INTEGER NULL,
  created_at TEXT NOT NULL, activated_at TEXT NULL, archived_at TEXT NULL, UNIQUE (current_flag))');
$pdo->exec('CREATE TABLE orange_brand_release_slots (
  id INTEGER PRIMARY KEY AUTOINCREMENT, release_id INTEGER NOT NULL, slot_code TEXT NOT NULL,
  slot_version_id INTEGER NOT NULL, UNIQUE (release_id, slot_code))');
$pdo->exec('CREATE TABLE orange_brand_identity_audit_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, actor_id INTEGER NOT NULL,
  created_at TEXT NOT NULL, entity_table TEXT NOT NULL DEFAULT \'\', entity_id TEXT NOT NULL DEFAULT \'\',
  release_id INTEGER NULL, slot_code TEXT NULL, identity_version_id INTEGER NULL, slot_version_id INTEGER NULL,
  before_id TEXT NULL, after_id TEXT NULL, change_note TEXT NULL)');

$report = [
    'ok' => true,
    'sqlite' => $sqlite,
    'bytes' => filesize($sqlite),
    'locales_seeded' => ['ar', 'en', 'fil', 'hi'],
    'identity_tables_empty' => true,
    'releases' => 0,
    'current_flag_rows' => 0,
    'objects' => 0,
    'slot_versions' => 0,
    'identity_versions' => 0,
    'audit_events' => 0,
    'rev6' => 'NOT_RUN',
    'mysql' => 'NOT_USED',
    'accepted_orange_four_seeded' => false,
    'visual_reviews_seeded' => false,
];
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit(0);

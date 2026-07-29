<?php

declare(strict_types=1);

/** Test-only: force-drop disposable D4 HTTP orange_db + app user. Never touch shadow/CRP. */

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

foreach ($pdo->query("SELECT id FROM information_schema.processlist WHERE db = 'orange_db'") as $r) {
    try {
        $pdo->exec('KILL ' . (int) $r['id']);
    } catch (Throwable) {
    }
}

$pdo->exec('DROP DATABASE IF EXISTS `orange_db`');
foreach (['localhost', '127.0.0.1'] as $host) {
    try {
        $pdo->exec("DROP USER IF EXISTS 'orange_d4_http_app'@'{$host}'");
    } catch (Throwable) {
    }
}
$pdo->exec('FLUSH PRIVILEGES');

$left = $pdo->query("SHOW DATABASES LIKE 'orange_db'")->fetchColumn();
echo $left === false || $left === '' ? "CLEAN_OK\n" : "STILL_EXISTS\n";

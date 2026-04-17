<?php
require_once __DIR__ . '/config.php';

echo "PHP OK\n";

try {
  $pdo = db();
  echo "DB OK\n";
  $r = $pdo->query("SELECT COUNT(*) c FROM admins")->fetch();
  echo "admins table OK, count=" . (int)($r['c'] ?? 0) . "\n";
} catch (Throwable $e) {
  echo "DB/admins ERROR: " . $e->getMessage() . "\n";
}

try {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $_SESSION['__t'] = '1';
  echo "SESSION OK\n";
} catch (Throwable $e) {
  echo "SESSION ERROR: " . $e->getMessage() . "\n";
}

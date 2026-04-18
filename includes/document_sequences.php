<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * تسلسل آمن مع عدة مستخدمين: صف واحد لكل scope، زيادة ذرّية عبر ON DUPLICATE KEY UPDATE.
 */
function orange_sequence_next(PDO $pdo, string $scope): int
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'document_sequences')) {
        throw new RuntimeException('جدول التسلسلات غير جاهز.');
    }
    $scope = preg_replace('/[^a-zA-Z0-9_\-]/', '', $scope);
    if ($scope === '') {
        throw new InvalidArgumentException('scope فارغ');
    }
    $pdo->prepare(
        'INSERT INTO document_sequences (scope, last_value) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE last_value = last_value + 1'
    )->execute([$scope]);
    $st = $pdo->prepare('SELECT last_value FROM document_sequences WHERE scope = ? LIMIT 1');
    $st->execute([$scope]);

    return (int) $st->fetchColumn();
}

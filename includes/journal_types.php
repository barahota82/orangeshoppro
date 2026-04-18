<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array<string, mixed>>
 */
function orange_journal_types_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return [];
    }

    return $pdo->query('SELECT * FROM journal_types ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ترميز اللاتيني للبادئة (أحرف وأرقام فقط، يُحوَّل لكبير).
 */
function orange_journal_type_normalize_code(string $raw): string
{
    $s = strtoupper(preg_replace('/\s+/', '', trim($raw)));
    $s = preg_replace('/[^A-Z0-9]/', '', $s);

    return $s ?? '';
}

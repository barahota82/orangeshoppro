<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/** @return array<string, string> */
function orange_delivery_agent_status_labels_ar(): array
{
    return [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
        'leave' => 'إجازة',
        'terminated' => 'إنهاء خدمات',
    ];
}

function orange_delivery_agent_status_normalize(string $status): string
{
    $status = strtolower(trim($status));
    $allowed = ['active', 'inactive', 'leave', 'terminated'];

    return in_array($status, $allowed, true) ? $status : 'active';
}

function orange_delivery_agent_status_label_ar(string $status): string
{
    $labels = orange_delivery_agent_status_labels_ar();
    $status = orange_delivery_agent_status_normalize($status);

    return $labels[$status] ?? $status;
}

/**
 * @return list<string>
 */
function orange_delivery_agent_assignable_statuses(): array
{
    return ['active'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function orange_delivery_agents_admin_list(PDO $pdo, ?int $countryId = null, ?string $statusFilter = null): array
{
    if (!orange_table_exists($pdo, 'delivery_agents')) {
        return [];
    }
    $sql = 'SELECT * FROM delivery_agents WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
        $sql .= ' AND status = ?';
        $params[] = orange_delivery_agent_status_normalize($statusFilter);
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function orange_delivery_agents_dropdown(PDO $pdo, int $countryId, bool $assignableOnly = false): array
{
    $rows = orange_delivery_agents_admin_list($pdo, $countryId > 0 ? $countryId : null);
    if (!$assignableOnly) {
        return $rows;
    }
    $ok = orange_delivery_agent_assignable_statuses();

    return array_values(array_filter(
        $rows,
        static fn(array $r): bool => in_array((string) ($r['status'] ?? ''), $ok, true)
    ));
}

function orange_delivery_agent_row_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !orange_table_exists($pdo, 'delivery_agents')) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM delivery_agents WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function orange_delivery_agents_next_sort_order(PDO $pdo, int $countryId): int
{
    if (!orange_table_exists($pdo, 'delivery_agents') || $countryId <= 0) {
        return 1;
    }
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM delivery_agents WHERE country_id = ?');
    $st->execute([$countryId]);
    $n = (int) $st->fetchColumn();

    return $n > 0 ? $n : 1;
}

function orange_delivery_agent_has_orders(PDO $pdo, int $agentId): bool
{
    if ($agentId <= 0 || !orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM orders WHERE delivery_agent_id = ? LIMIT 1');
    $st->execute([$agentId]);

    return (bool) $st->fetchColumn();
}

function orange_delivery_agent_display_name(array $row): string
{
    $ar = trim((string) ($row['name_ar'] ?? ''));
    if ($ar !== '') {
        return $ar;
    }

    return trim((string) ($row['name_en'] ?? ''));
}

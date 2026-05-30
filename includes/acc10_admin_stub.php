<?php

declare(strict_types=1);

require_once __DIR__ . '/analytical_dimensions.php';

/**
 * Shared stub chrome for ACC-10 admin pages (phase 0).
 *
 * @param array{
 *   title: string,
 *   phase: int,
 *   phase_label: string,
 *   features: list<string>
 * } $opts
 */
function orange_acc10_render_stub_page(PDO $pdo, array $opts): void
{
    $title = (string) ($opts['title'] ?? '');
    $phase = (int) ($opts['phase'] ?? 0);
    $phaseLabel = (string) ($opts['phase_label'] ?? '');
    $features = isset($opts['features']) && is_array($opts['features']) ? $opts['features'] : [];
    $ready = orange_acc10_phase0_ready($pdo);

    echo '<div class="admin-fy-shell" dir="rtl">';
    echo '<h1 class="admin-fy-shell__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';

    if (!$ready) {
        echo '<div class="card" style="margin-bottom:1rem;border:1px solid #fcd34d;background:#fffbeb;">';
        echo '<p style="margin:0;"><strong>تنبيه:</strong> جداول ACC-10 لم تُكتمل بعد. حدّث الصفحة أو راجع ترحيل المخطط.</p>';
        echo '</div>';
    } else {
        echo '<div class="card" style="margin-bottom:1rem;border:1px solid #93c5fd;background:#eff6ff;">';
        echo '<p style="margin:0;"><strong>المرحلة 0 — منتهية:</strong> الهيكل (جداول + قائمة + صفحة) جاهز.</p>';
        echo '</div>';
    }

    echo '<div class="card" style="margin-bottom:1rem;">';
    echo '<p class="card-hint" style="margin:0 0 12px;">هذه الشاشة <strong>placeholder</strong> حتى تنفيذ '
        . htmlspecialchars($phaseLabel, ENT_QUOTES, 'UTF-8')
        . ' (مرحلة ' . $phase . ' من خطة ACC-10).</p>';
    if ($features !== []) {
        echo '<ul style="margin:0;padding-right:1.25rem;">';
        foreach ($features as $f) {
            echo '<li>' . htmlspecialchars((string) $f, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';

    echo '<p class="muted" style="font-size:0.9rem;">مرجع: ORANGE_ADMIN_ACCOUNTING_REPORTS_STATUS.txt §6b</p>';
    echo '</div>';
}

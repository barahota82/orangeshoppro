<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_nav_tree.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/admin_page_bootstrap.php';

/**
 * عرض صفحة فهرس لقسم mega (كروت بنفس ترتيب القائمة المنسدلة).
 *
 * @param array<string,mixed> $admin
 * @param array<string,string> $descByPage أوصاف اختيارية حسب page=
 */
function orange_admin_render_mega_section_index(
    array $admin,
    PDO $pdo,
    string $megaSectionId,
    string $selfPageSlug,
    string $pageTitle,
    string $pageSubtitle = '',
    array $descByPage = []
): void {
    $section = null;
    foreach (orange_admin_permission_mega_sections() as $mega) {
        if ((string) ($mega['id'] ?? '') === $megaSectionId) {
            $section = $mega;
            break;
        }
    }
    $subgroups = is_array($section) ? ($section['subgroups'] ?? []) : [];
    if ($pageSubtitle === '') {
        $pageSubtitle = 'روابط سريعة — بنفس ترتيب القائمة المنسدلة.';
    }
    $countryLabel = orange_admin_page_country_label($pdo);
    ?>
<div class="page-title">
    <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php if ($pageSubtitle !== ''): ?>
<p class="page-subtitle" style="margin:0 0 0.75rem;"><?php echo htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php foreach ($subgroups as $sg): ?>
    <?php
    $visible = [];
    foreach ($sg['pages'] ?? [] as $p) {
        $pg = (string) ($p['page'] ?? '');
        if ($pg === '' || $pg === $selfPageSlug) {
            continue;
        }
        if (!orange_admin_nav_visible($admin, $pdo, $pg)) {
            continue;
        }
        $visible[] = $p;
    }
    if ($visible === []) {
        continue;
    }
    ?>
    <div class="card">
        <h2 class="card-title"><?php echo htmlspecialchars((string) ($sg['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="ari-grid">
            <?php foreach ($visible as $p): ?>
                <?php
                $pg = (string) ($p['page'] ?? '');
                $rawHref = trim((string) ($p['href'] ?? ''));
                $cardHref = $rawHref !== ''
                    ? storefront_public_path($rawHref)
                    : storefront_public_path('/admin/index.php?page=' . rawurlencode($pg));
                $desc = trim((string) ($p['desc'] ?? ''));
                if ($desc === '' && isset($descByPage[$pg])) {
                    $desc = $descByPage[$pg];
                }
                ?>
                <a class="ari-card" href="<?php echo htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="ari-card__title"><?php echo htmlspecialchars((string) ($p['label'] ?? $pg), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="ari-card__desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<style>
.ari-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(15rem, 1fr)); gap:12px; }
.ari-card { display:flex; flex-direction:column; gap:4px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; text-decoration:none; color:#0f172a; }
.ari-card:hover { border-color:#0f172a; }
.ari-card__title { font-weight:700; font-size:0.95rem; }
.ari-card__desc { font-size:0.82rem; color:#64748b; line-height:1.5; }
</style>
    <?php
}

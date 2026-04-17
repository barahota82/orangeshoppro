<?php

declare(strict_types=1);

if (!isset($channel, $lang, $channelSlug, $pageKind, $storefrontExtra, $langOpts, $currentLangLabel)) {
    return;
}

$navPlace = $SF_NAV_PLACEMENT ?? 'header';
$waHref = storefront_whatsapp_href($channel);
$ddCls = $navPlace === 'dock' ? 'lang-dropdown lang-dropdown--dock' : 'lang-dropdown';
$wrapCls = $navPlace === 'dock' ? 'app-bottom-dock__cell' : '';

if ($navPlace === 'dock') {
    echo '<nav class="app-bottom-dock" role="navigation" aria-label="' . htmlspecialchars(t('language') . ' · ' . t('cart') . ' · ' . t('track_order') . ' · ' . t('whatsapp'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="app-bottom-dock__grid">';
}

$openDockCell = static function () use ($navPlace, $wrapCls): void {
    if ($navPlace === 'dock') {
        echo '<div class="' . htmlspecialchars($wrapCls, ENT_QUOTES, 'UTF-8') . '">';
    }
};

$closeDockCell = static function () use ($navPlace): void {
    if ($navPlace === 'dock') {
        echo '</div>';
    }
};

if ($navPlace === 'header') {
    echo '<nav class="lang-dropdown-nav" aria-label="' . htmlspecialchars(t('language'), ENT_QUOTES, 'UTF-8') . '">';
}

$openDockCell();
?>
                <details class="<?php echo htmlspecialchars($ddCls, ENT_QUOTES, 'UTF-8'); ?>">
                    <summary class="lang-dropdown__summary<?php echo $navPlace === 'dock' ? ' lang-dropdown__summary--dock' : ''; ?>">
                        <span class="lang-dropdown__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </span>
                        <span class="lang-dropdown__current"><?php echo htmlspecialchars($currentLangLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="lang-dropdown__chev" aria-hidden="true"></span>
                    </summary>
                    <ul class="lang-dropdown__list">
                        <?php foreach ($langOpts as $lc => $meta) {
                            $href = storefront_url($pageKind, $channelSlug, $lc, $storefrontExtra);
                            $isActive = $lc === $lang;
                            $label = (string)($meta['label'] ?? $lc);
                            ?>
                        <li>
                            <a class="lang-dropdown__option<?php echo $isActive ? ' is-active' : ''; ?>"
                               href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
                               hreflang="<?php echo htmlspecialchars($lc, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $isActive ? 'aria-current="true"' : ''; ?>>
                                <span class="lang-dropdown__check" aria-hidden="true"><?php echo $isActive ? '✓' : ''; ?></span>
                                <span class="lang-dropdown__label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </details>
<?php
$closeDockCell();

if ($navPlace === 'header') {
    echo '</nav>';
}

$openDockCell();
$cartHref = htmlspecialchars(storefront_url('cart', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');
$trackHref = htmlspecialchars(storefront_url('track', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');
if ($navPlace === 'dock') {
    ?>
<a class="app-dock-btn" href="<?php echo $cartHref; ?>">
    <span class="app-dock-btn__icon app-dock-btn__icon--cart" aria-hidden="true"></span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
<?php
} else {
    ?>
            <a class="icon-btn" href="<?php echo $cartHref; ?>"><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></a>
<?php
}
$closeDockCell();

$openDockCell();
if ($navPlace === 'dock') {
    ?>
<a class="app-dock-btn" href="<?php echo $trackHref; ?>">
    <span class="app-dock-btn__icon app-dock-btn__icon--track" aria-hidden="true"></span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
<?php
} else {
    ?>
            <a class="icon-btn" href="<?php echo $trackHref; ?>"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></a>
<?php
}
$closeDockCell();

$openDockCell();
if ($waHref !== null) {
    $waEsc = htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8');
    if ($navPlace === 'dock') {
        ?>
<a class="app-dock-btn app-dock-btn--whatsapp" href="<?php echo $waEsc; ?>" target="_blank" rel="noopener noreferrer">
    <span class="app-dock-btn__icon app-dock-btn__icon--wa" aria-hidden="true"></span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
<?php
    } else {
        ?>
            <a class="icon-btn icon-btn--whatsapp" href="<?php echo $waEsc; ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></a>
<?php
    }
} elseif ($navPlace === 'dock') {
    ?>
<span class="app-dock-btn app-dock-btn--disabled" aria-disabled="true">
    <span class="app-dock-btn__icon app-dock-btn__icon--wa" aria-hidden="true"></span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></span>
</span>
<?php
}
$closeDockCell();

if ($navPlace === 'dock') {
    echo '</div></nav>';
}

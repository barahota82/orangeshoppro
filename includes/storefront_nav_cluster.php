<?php

declare(strict_types=1);

if (!isset($channel, $lang, $channelSlug, $pageKind, $storefrontExtra, $langOpts, $currentLangLabel)) {
    return;
}

$countryOptions = $countryOptions ?? [];
$countryCode = $countryCode ?? '';
$countryLabel = $countryLabel ?? '';

$clusterPart = $SF_NAV_CLUSTER_PART ?? '';

$waHref = storefront_whatsapp_href($channel);
$cartHref = htmlspecialchars(storefront_url('cart', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');
$trackHref = htmlspecialchars(storefront_url('track', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');
$registerHref = htmlspecialchars(storefront_url('register', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');

$dockCartCurrent = $pageKind === 'cart';
$dockTrackCurrent = $pageKind === 'track';
$dockRegisterCurrent = \in_array($pageKind, ['register', 'verify_email'], true);

$langAria = htmlspecialchars(t('language'), ENT_QUOTES, 'UTF-8');

/** قائمة اللغات — نمط الهيدر (ليس dock) */
$renderLangHeader = static function () use ($langOpts, $lang, $channelSlug, $pageKind, $storefrontExtra, $currentLangLabel): void {
    ?>
                <details class="lang-dropdown">
                    <summary class="lang-dropdown__summary" aria-label="<?php echo htmlspecialchars(t('language'), ENT_QUOTES, 'UTF-8'); ?>">
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
};

if ($clusterPart === 'country') {
    $countryAria = htmlspecialchars(function_exists('t') ? t('storefront_country') : 'Country', ENT_QUOTES, 'UTF-8');
    if (count($countryOptions) < 2) {
        return;
    }
    echo '<nav class="lang-dropdown-nav country-dropdown-nav" aria-label="' . $countryAria . '">';
    ?>
                <details class="lang-dropdown country-dropdown">
                    <summary class="lang-dropdown__summary" aria-label="<?php echo $countryAria; ?>">
                        <span class="lang-dropdown__current"><?php echo htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="lang-dropdown__chev" aria-hidden="true"></span>
                    </summary>
                    <ul class="lang-dropdown__list">
                        <?php foreach ($countryOptions as $co) {
                            $cc = (string) ($co['code'] ?? '');
                            if ($cc === '') {
                                continue;
                            }
                            $href = htmlspecialchars(storefront_country_switch_href($cc, $lang), ENT_QUOTES, 'UTF-8');
                            $isActive = $cc === $countryCode;
                            $label = (string) ($co['name'] ?? $cc);
                            ?>
                        <li>
                            <a class="lang-dropdown__option<?php echo $isActive ? ' is-active' : ''; ?>"
                               href="<?php echo $href; ?>"
                               <?php echo $isActive ? 'aria-current="true"' : ''; ?>>
                                <span class="lang-dropdown__check" aria-hidden="true"><?php echo $isActive ? '✓' : ''; ?></span>
                                <span class="lang-dropdown__label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </details>
    <?php
    echo '</nav>';

    return;
}

if ($clusterPart === 'lang') {
    echo '<nav class="lang-dropdown-nav" aria-label="' . $langAria . '">';
    $renderLangHeader();
    echo '</nav>';

    return;
}

if ($clusterPart === 'actions') {
    ?>
            <button type="button" class="icon-btn icon-btn--install" data-orange-install-app hidden aria-label="<?php echo htmlspecialchars(t('storefront_install_app_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars(t('storefront_install_app_btn'), ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <a class="icon-btn" data-orange-cart-link href="<?php echo $cartHref; ?>"><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="icon-btn" href="<?php echo $trackHref; ?>"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="icon-btn" href="<?php echo $registerHref; ?>"><?php echo htmlspecialchars(t('storefront_register'), ENT_QUOTES, 'UTF-8'); ?></a>
    <?php
    if ($waHref !== null) {
        $waEsc = htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8');
        ?>
            <a class="icon-btn icon-btn--whatsapp" href="<?php echo $waEsc; ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php
    }

    return;
}

if ($clusterPart === 'dock') {
    $dockAria = t('cart') . ' · ' . t('track_order') . ' · ' . t('storefront_register') . ' · ' . t('storefront_install_app_btn') . ' · ' . t('whatsapp');
    echo '<nav class="app-bottom-dock" role="navigation" aria-label="' . htmlspecialchars($dockAria, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="app-bottom-dock__grid">';
    ?>
<div class="app-bottom-dock__cell">
<a class="app-dock-btn<?php echo $dockCartCurrent ? ' app-dock-btn--current' : ''; ?>"<?php echo $dockCartCurrent ? ' aria-current="page"' : ''; ?> data-orange-cart-link href="<?php echo $cartHref; ?>">
    <span class="app-dock-btn__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M6 6h14l-1.3 8H7.7L6 6Zm0 0L5 3H2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="19" r="1.35" fill="currentColor"/><circle cx="17" cy="19" r="1.35" fill="currentColor"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
</div>
<div class="app-bottom-dock__cell">
<a class="app-dock-btn<?php echo $dockTrackCurrent ? ' app-dock-btn--current' : ''; ?>"<?php echo $dockTrackCurrent ? ' aria-current="page"' : ''; ?> href="<?php echo $trackHref; ?>">
    <span class="app-dock-btn__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M12 21c-3.9-3.2-6-6.7-6-10a6 6 0 1 1 12 0c0 3.3-2.1 6.8-6 10Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><circle cx="12" cy="11" r="2.25" fill="currentColor"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
</div>
<div class="app-bottom-dock__cell">
<a class="app-dock-btn<?php echo $dockRegisterCurrent ? ' app-dock-btn--current' : ''; ?>"<?php echo $dockRegisterCurrent ? ' aria-current="page"' : ''; ?> href="<?php echo $registerHref; ?>">
    <span class="app-dock-btn__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="1.75"/><path d="M4 21a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('storefront_register'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
</div>
<div class="app-bottom-dock__cell">
<button type="button" class="app-dock-btn app-dock-btn--install" data-orange-install-app hidden aria-label="<?php echo htmlspecialchars(t('storefront_install_app_aria'), ENT_QUOTES, 'UTF-8'); ?>">
    <span class="app-dock-btn__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M12 3v10m0 0 3.5-3.5M12 13 8.5 9.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 19h14a2 2 0 0 0 2-2v-3H3v3a2 2 0 0 0 2 2Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('storefront_install_app_btn'), ENT_QUOTES, 'UTF-8'); ?></span>
</button>
</div>
<div class="app-bottom-dock__cell">
    <?php
    if ($waHref !== null) {
        $waEsc = htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8');
        ?>
<a class="app-dock-btn app-dock-btn--whatsapp" href="<?php echo $waEsc; ?>" target="_blank" rel="noopener noreferrer">
    <span class="app-dock-btn__icon app-dock-btn__icon--wa" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false"><path fill="#25d366" d="M17.5 14.2c-.3-.15-1.7-.9-2-1-.2-.1-.5-.15-.7.15-.2.3-.8 1-1 1.2-.2.2-.3.22-.6.07-.3-.15-1.2-.45-2.3-1.4-1-.85-1.6-1.7-1.8-2-.2-.3 0-.45.15-.6.15-.15.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.7-1.6-.95-2.2-.25-.58-.5-.5-.65-.51h-.55c-.2 0-.52.07-.8.37-.27.3-1 1-1 2.5s1 2.9 1.2 3.1c.15.2 2 3.1 5 4.4.7.3 1.3.5 1.7.6.7.23 1.4.2 1.9.12.6-.09 1.7-.7 2-1.4.2-.7.2-1.3.15-1.4-.1-.12-.25-.2-.55-.35z"/><path fill="#25d366" d="M12.1 21.9h-.01a9.8 9.8 0 0 1-5-1.4l-.36-.2-3.7 1 .99-3.6-.24-.38a9.9 9.9 0 0 1-1.5-5.2c0-5.4 4.4-9.8 9.9-9.8 2.6 0 5.1 1 7 2.9a9.8 9.8 0 0 1 2.9 7c0 5.5-4.4 9.9-9.9 9.9z"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></span>
</a>
        <?php
    } else {
        ?>
<span class="app-dock-btn app-dock-btn--disabled" aria-disabled="true">
    <span class="app-dock-btn__icon app-dock-btn__icon--wa" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false"><path fill="currentColor" opacity="0.35" d="M17.5 14.2c-.3-.15-1.7-.9-2-1-.2-.1-.5-.15-.7.15-.2.3-.8 1-1 1.2-.2.2-.3.22-.6.07-.3-.15-1.2-.45-2.3-1.4-1-.85-1.6-1.7-1.8-2-.2-.3 0-.45.15-.6.15-.15.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.7-1.6-.95-2.2-.25-.58-.5-.5-.65-.51h-.55c-.2 0-.52.07-.8.37-.27.3-1 1-1 2.5s1 2.9 1.2 3.1c.15.2 2 3.1 5 4.4.7.3 1.3.5 1.7.6.7.23 1.4.2 1.9.12.6-.09 1.7-.7 2-1.4.2-.7.2-1.3.15-1.4-.1-.12-.25-.2-.55-.35z"/><path fill="currentColor" opacity="0.35" d="M12.1 21.9h-.01a9.8 9.8 0 0 1-5-1.4l-.36-.2-3.7 1 .99-3.6-.24-.38a9.9 9.9 0 0 1-1.5-5.2c0-5.4 4.4-9.8 9.9-9.8 2.6 0 5.1 1 7 2.9a9.8 9.8 0 0 1 2.9 7c0 5.5-4.4 9.9-9.9 9.9z"/></svg>
    </span>
    <span class="app-dock-btn__label"><?php echo htmlspecialchars(t('whatsapp'), ENT_QUOTES, 'UTF-8'); ?></span>
</span>
        <?php
    }
    ?>
</div>
    <?php
    echo '</div></nav>';

    return;
}

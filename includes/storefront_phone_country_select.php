<?php

declare(strict_types=1);

/**
 * @return array<string, string> dial code => label
 */
function orange_phone_country_options(): array
{
    return [
        '' => t('phone_country_full_international'),
        '965' => t('phone_country_kw'),
        '63' => t('phone_country_ph'),
        '91' => t('phone_country_in'),
        '92' => t('phone_country_pk'),
    ];
}

/** Echo <select> for optional country prefix (national number in the phone field). */
function orange_storefront_render_phone_country_select(string $selectId, string $extraClass = ''): void
{
    $opts = orange_phone_country_options();
    $id = htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8');
    $cls = trim('orange-phone-country-select ' . $extraClass);
    $clsAttr = htmlspecialchars($cls, ENT_QUOTES, 'UTF-8');
    $aria = htmlspecialchars(t('phone_country_label'), ENT_QUOTES, 'UTF-8');
    echo '<select id="' . $id . '" class="' . $clsAttr . '" dir="ltr" autocomplete="tel-country-code" aria-label="' . $aria . '">';
    foreach ($opts as $val => $label) {
        echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
}

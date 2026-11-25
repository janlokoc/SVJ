<?php
declare(strict_types=1);

// Hlavní doména platformy: veřejná úvodní stránka s registrací nových společenství a pod adresou /sprava konzole hlavního správce.

if ($PATH === '/sprava' || str_starts_with($PATH, '/sprava/')) {
    // Konzole běží „o patro níž“: její odkazy i přesměrování se počítají od /sprava.
    $GLOBALS['ASSET_BASE'] = $BASE;
    $BASE .= '/sprava';
    $PATH = '/' . ltrim(substr($PATH, 7), '/');
    require __DIR__ . '/platform_console.php';
    exit;
}
if ($PATH === '/partner' || str_starts_with($PATH, '/partner/')) {
    // Partnerský portál správcovských firem – stejně jako konzole běží „o patro níž“, od /partner.
    $GLOBALS['ASSET_BASE'] = $BASE;
    $BASE .= '/partner';
    $PATH = '/' . ltrim(substr($PATH, 8), '/');
    require __DIR__ . '/partner_portal.php';
    exit;
}
require __DIR__ . '/platform_site.php';

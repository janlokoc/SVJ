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
require __DIR__ . '/platform_site.php';

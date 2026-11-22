<?php
declare(strict_types=1);

// Import obsahu (aktuality, dokumenty, kontakty, název) ze souboru JSON připraveného převodem původního webu.
// Použití: php tools/import-content.php soubor.json [--pridat]
// Na platformě pro více SVJ zvolte společenství: SVJ_TENANT=krouzova php tools/import-content.php soubor.json
// Bez --pridat se dosavadní aktuality, dokumenty a kontakty nahradí.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../app/lib.php';
date_default_timezone_set('Europe/Prague');
$tenant = (string) getenv('SVJ_TENANT');
if (platform_enabled() && ($tenant === '' || !tenant_row($tenant))) {
    fwrite(STDERR, "Zvolte společenství proměnnou SVJ_TENANT (adresa webu, např. SVJ_TENANT=krouzova).\n");
    exit(1);
}
$file = (string) ($argv[1] ?? '');
$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($data)) {
    fwrite(STDERR, "Použití: php tools/import-content.php soubor.json [--pridat]\n");
    exit(1);
}
$result = import_content(db(), $data, !in_array('--pridat', $argv, true));
if (is_string($result)) {
    fwrite(STDERR, $result . "\n");
    exit(1);
}
audit('Import obsahu', 'aktualit ' . $result['posts'] . ', dokumentů ' . $result['documents'] . ', kontaktů ' . $result['contacts']);
echo "Naimportováno: {$result['posts']} aktualit, {$result['documents']} dokumentů, {$result['contacts']} kontaktů.\n"
    . "Dokumenty zatím odkazují na původní web. Stáhnete je v administraci na stránce Přehled, nebo příkazem php tools/import-webnode.php.\n";

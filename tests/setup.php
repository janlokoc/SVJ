<?php
// Příprava testovacího prostředí (spouští tests/bootstrap.php v samostatném procesu): platforma a ukázkový dům „dum“
// v dočasné složce SVJ_STORAGE. Skutečná data webu se nikdy nepoužijí.
declare(strict_types=1);
if (!getenv('SVJ_STORAGE')) {
    fwrite(STDERR, "Chybí SVJ_STORAGE.\n");
    exit(1);
}
chdir(__DIR__ . '/..');
require 'app/lib.php';
pset_save('mail_from', 'sluzba@example.cz');
pset_save('service_name', 'Testovací služba');
tenant_create(['slug' => 'dum', 'name' => 'SVJ Zahradní 12', 'title' => 'Zahradní 12', 'full' => 'Společenství vlastníků jednotek Zahradní 12', 'address' => 'Zahradní 12, Brno',
    'unit_prefix' => '12', 'entrances' => 'A,B', 'look' => 'les', 'style' => 'klasik', 'seed' => 'demo']);
tenant_set('dum', 'mail_from', 'vybor@example.cz');
tenant_pdo('dum');
echo "ok\n";

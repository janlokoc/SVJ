<?php
declare(strict_types=1);

// Převod webu jednoho SVJ pod platformu pro více SVJ: stávající databáze a soubory se přesunou
// do storage/tenants/<adresa>/ a společenství se zapíše do seznamu platformy.
//
// Postup: 1) do storage/config.php doplňte 'platform' => ['domain' => 'vase-domena.cz'],
//         2) spusťte: php tools/move-to-platform.php tabor
// Web pak poběží na adrese tabor.vase-domena.cz se všemi účty, dokumenty i nastavením. Před převodem si udělejte zálohu.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../app/lib.php';
date_default_timezone_set('Europe/Prague');

$slug = strtolower((string) ($argv[1] ?? ''));
$fail = function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
if (!platform_enabled()) {
    $fail("V souboru storage/config.php není zapnutý provoz pro více SVJ ('platform' => ['domain' => '…']).");
}
if (db_driver() !== 'sqlite') {
    $fail('Převod umí jen databázi SQLite. U MySQL stáhněte v administraci zálohu a obnovte ji do nového společenství příkazem tools/restore-backup.php.');
}
if ($problem = slug_problem($slug)) {
    $fail("Adresa „{$slug}“: $problem\nPoužití: php tools/move-to-platform.php adresa");
}
$dbs = glob(ROOT_STORAGE . '/svj-*.sqlite');
if (count($dbs) !== 1) {
    $fail('Ve složce storage není databáze webu jednoho SVJ (soubor svj-….sqlite), není co převádět.');
}
$target = tenant_storage($slug);
if (is_dir($target)) {
    $fail("Složka $target už existuje.");
}
$pdo = new PDO('sqlite:' . $dbs[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$s = array_column($pdo->query('SELECT `key`, value FROM settings')->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
$pdo = null;
$get = fn(string $k, string $default) => $s['site_' . $k] ?? $default;

mkdir($target, 0775, true);
foreach (array_merge($dbs, glob(ROOT_STORAGE . '/svj-*.sqlite-*')) as $f) {
    rename($f, $target . '/' . basename($f));
}
if (is_dir(ROOT_STORAGE . '/uploads')) {
    rename(ROOT_STORAGE . '/uploads', $target . '/uploads');
}
if (is_dir(ROOT_STORAGE . '/sessions')) {
    array_map('unlink', glob(ROOT_STORAGE . '/sessions/sess_*'));   // přihlášení ze staré adresy přestávají platit
}
pq('INSERT INTO tenants (slug, name, title, accent, tagline, "full", address, unit_prefix, entrances, look, lang, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    [$slug, $get('name', 'SVJ Tábor 48'), $get('title', 'Tábor 48'), $get('accent', 'a–d'), $get('tagline', 'Společenství vlastníků jednotek · Brno'), $get('full', 'Společenství vlastníků jednotek Tábor 48'),
        $s['address'] ?? '', $get('unit_prefix', '48'), $get('entrances', 'A,B,C,D'), $get('look', 'les'), $get('lang', 'cs'), date('Y-m-d H:i:s')]);
// Výchozí název a značení jednotek se dosud braly z programu; na platformě musí být uložené v nastavení webu.
foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'lang'] as $k) {
    if (!isset($s['site_' . $k])) {
        tenant_set($slug, 'site_' . $k, $get($k, ['name' => 'SVJ Tábor 48', 'title' => 'Tábor 48', 'accent' => 'a–d', 'tagline' => 'Společenství vlastníků jednotek · Brno',
            'full' => 'Společenství vlastníků jednotek Tábor 48', 'unit_prefix' => '48', 'entrances' => 'A,B,C,D', 'look' => 'les', 'lang' => 'cs'][$k]));
    }
}
pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', 'příkazová řádka', 'Převod webu pod platformu', $slug]);
echo "Hotovo. Web běží na adrese $slug." . platform_domain() . " – účty a hesla zůstávají stejné.\nHlavní doména " . platform_domain() . " teď zobrazuje úvodní stránku služby; konzole hlavního správce je na /sprava.\n";

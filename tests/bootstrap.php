<?php
// Unit testy běží nad dočasnou kopií platformy s jedním ukázkovým domem („dum“, edice Premium bez placení).
// Každé spuštění začíná s čistými daty; e-maily se zachytávají do souboru (viz TestCase::mails()).
declare(strict_types=1);

$root = dirname(__DIR__);
$store = sys_get_temp_dir() . '/svj-unit-' . bin2hex(random_bytes(4));
mkdir($store, 0700, true);
file_put_contents($store . '/config.php', "<?php\nreturn ['platform' => ['domain' => 'test.invalid'], 'ai' => ['api_key' => '']];\n");
putenv('SVJ_STORAGE=' . $store);
putenv('SVJ_MAIL_CAPTURE=' . $store . '/mail.jsonl');
putenv('SVJ_TENANT=');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/setup.php') . ' 2>&1', $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "Příprava testů selhala:\n" . implode("\n", $out) . "\n");
    exit(1);
}
putenv('SVJ_TENANT=dum');
$_SERVER['HTTP_HOST'] = 'dum.test.invalid';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $root . '/vendor/autoload.php';
require $root . '/app/lib.php';
require __DIR__ . '/TestCase.php';
register_shutdown_function(function () use ($store) {
    exec('rm -rf ' . escapeshellarg($store));
});

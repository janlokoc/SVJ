<?php
// Stáhne dokumenty ze starého webu (Webnode) do úložiště nového webu.
// Spuštění z příkazové řádky:  php tools/import-webnode.php
// Totéž jde udělat bez příkazové řádky tlačítkem v Administraci → Přehled.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../app/lib.php';
do {
    [$done, $failed, $left] = import_external_files(10);
    echo "staženo $done, chyb $failed, zbývá $left\n";
} while ($left > 0 && $done > 0);

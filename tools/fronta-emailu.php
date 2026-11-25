<?php
// Odešle čekající e-maily z fronty (limit hostingu). Fronta se jinak zpracovává sama při návštěvách webu;
// cron jen zajistí odeslání i v noci, kdy na web nikdo nechodí. Příklad pro cron: každých 10 minut
//   php /cesta/k/webu/tools/fronta-emailu.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/lib.php';
[$done, $bad, $left] = mailq_run(100, true);
echo "Odesláno: $done, nepodařilo se: $bad, čeká: $left\n";

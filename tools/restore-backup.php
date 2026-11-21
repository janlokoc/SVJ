<?php
// Obnoví web ze zálohy stažené v Administraci → Záloha. Stávající data a nahrané soubory NAHRADÍ obsahem zálohy.
// Spuštění z příkazové řádky:  php tools/restore-backup.php cesta/k/zaloha.zip
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/extra_lib.php';
$file = $argv[1] ?? '';
$zip = new ZipArchive();
if ($file === '' || $zip->open($file) !== true) {
    fwrite(STDERR, "Použití: php tools/restore-backup.php zaloha.zip\n");
    exit(1);
}
$data = json_decode((string) $zip->getFromName('data.json'), true);
if (!is_array($data)) {
    fwrite(STDERR, "V záloze chybí čitelný soubor data.json.\n");
    exit(1);
}
$counts = restore_data($data);
$restored = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    // Jen soubory s bezpečným názvem, jaký web sám vytváří.
    if (preg_match('~^uploads/([a-f0-9]{32}\.[a-z0-9]+)$~', $name, $m)) {
        file_put_contents(UPLOAD_DIR . '/' . $m[1], $zip->getFromIndex($i));
        $restored++;
    }
}
echo "Obnoveno ze zálohy z {$data['created']}: řádků " . array_sum($counts) . ", souborů $restored.\n";

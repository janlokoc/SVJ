<?php
declare(strict_types=1);

/*
 * Úplná záloha dat služby pro pravidelné stažení mimo hosting (GitHub Actions ji zašifruje a uloží).
 * Obsah: databáze (konzistentní kopie přes VACUUM INTO), nahrané soubory, hlášení, klíče pro upozornění,
 * certifikáty a storage/config.php. Bez přihlášení (sessions), fronty e-mailů a dočasných souborů (i příloh odpovědí e-mailem čekajících na web domu).
 * Adresa /zaloha/<klíč>, klíč v storage/config.php jako 'backup_key' (jiný než pro cron).
 */

const BACKUP_SKIP = '~(^|/)([a-z-]*sessions|tmp)(/|$)|(^|/)mailq[.-]|\.lock$|\.tick$|\.pending$|(^|/)instalacni-kod|^obnova-2fa|^obnova-pristupu|^mail-staging\.log$|^inbound/files(/|$)|^inbound/pending-.*\.flag$|\.sqlite-(wal|shm|journal)$~';

/** Sestaví ZIP se zálohou do dočasného souboru a vrátí jeho cestu (volající ho po odeslání smaže). */
function backup_build(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Na serveru chybí rozšíření PHP zip.');
    }
    @set_time_limit(300);
    $root = rtrim(ROOT_STORAGE, '/');
    $tmp = $root . '/tmp';
    @mkdir($tmp, 0775, true);
    $zipPath = $tmp . '/zaloha-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Zálohu nejde vytvořit.');
    }
    $copies = [];
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $file) {
        $path = $file->getPathname();
        $rel = ltrim(substr($path, strlen($root)), '/');
        if (!$file->isFile() || preg_match(BACKUP_SKIP, $rel)) {
            continue;
        }
        if (str_ends_with($rel, '.sqlite')) {
            // Konzistentní kopie i za běhu webu; při chybě aspoň samotný soubor.
            $copy = $tmp . '/db-' . bin2hex(random_bytes(6)) . '.sqlite';
            try {
                $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]);
                $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
                $pdo = null;
                $zip->addFile($copy, 'storage/' . $rel);
                $copies[] = $copy;
            } catch (Throwable $e) {
                $zip->addFile($path, 'storage/' . $rel);
            }
        } else {
            $zip->addFile($path, 'storage/' . $rel);
        }
        $count++;
    }
    $zip->addFromString('ZALOHA.txt', "Záloha dat služby " . (function_exists('platform_main_domain') ? platform_main_domain() : '') . "\nVytvořeno: " . date('Y-m-d H:i:s')
        . "\nSouborů: $count\nObnovení: obsah složky storage/ nahrajte do storage/ na serveru (při zastaveném webu), viz README – Záloha.\n");
    $zip->close();
    foreach ($copies as $c) {
        @unlink($c);
    }
    return $zipPath;
}
/** Odešle zálohu jako soubor ke stažení a smaže dočasné soubory. */
function backup_send(): never
{
    try {
        $zipPath = backup_build();
    } catch (Throwable $e) {
        error_log('SVJ web, záloha: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zálohu se nepodařilo vytvořit.\n";
        exit;
    }
    if (function_exists('platform_log') && platform_enabled()) {
        platform_log('Záloha stažena', round(filesize($zipPath) / 1048576, 1) . ' MB · ' . client_ip());
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="zaloha-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    readfile($zipPath);
    @unlink($zipPath);
    // Zbytky po přerušených zálohách
    foreach (glob(rtrim(ROOT_STORAGE, '/') . '/tmp/{zaloha-*.zip,db-*.sqlite}', GLOB_BRACE) ?: [] as $old) {
        if (filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }
    exit;
}

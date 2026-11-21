<?php
declare(strict_types=1);

// Vestavěný vývojový server PHP: statické soubory vzhledu servíruj přímo.
if (PHP_SAPI === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($p, '/assets/') && !str_contains($p, '..') && is_file(__DIR__ . $p)) {
        return false;
    }
}

require __DIR__ . '/app/lib.php';

// Chyby se návštěvníkům nikdy nevypisují (prozradily by cesty a strukturu webu); zapisují se do protokolu serveru.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) {
    error_log('SVJ web: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Chyba</title><p>Omlouváme se, na webu došlo k chybě. Zkuste to prosím za chvíli znovu.</p></html>';
});

date_default_timezone_set('Europe/Prague');
mb_internal_encoding('UTF-8');

$BASE = PHP_SAPI === 'cli-server' ? '' : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$PATH = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($BASE !== '' && str_starts_with($PATH, $BASE)) {
    $PATH = substr($PATH, strlen($BASE));
}
$PATH = '/' . trim($PATH, '/');
if ($PATH === '/index.php') {
    $PATH = '/';
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) SESSION_LIMIT);
session_name('svj_session');
session_set_cookie_params(['lifetime' => 0, 'path' => $BASE . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

// Automatické odhlášení po delší nečinnosti a nejdéle po 12 hodinách.
if (!empty($_SESSION['uid'])) {
    if (time() - ($_SESSION['seen'] ?? 0) > IDLE_LIMIT || time() - ($_SESSION['start'] ?? 0) > SESSION_LIMIT) {
        audit('session_expired');
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Z bezpečnostních důvodů jsme Vás po delší nečinnosti odhlásili. Přihlaste se prosím znovu.', 'info');
    } else {
        $_SESSION['seen'] = time();
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header_remove('X-Powered-By');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
if (!empty($_SESSION['uid']) || !empty($_SESSION['pending'])) {
    // Stránky přihlášených se neukládají v prohlížeči ani po cestě – na sdíleném počítači je po odhlášení nejde vrátit tlačítkem Zpět.
    header('Cache-Control: no-store, private');
}

// Srozumitelná hláška, když na hostingu chybí databáze nebo k ní nesedí přihlašovací údaje.
$dbProblem = null;
if (db_driver() === 'sqlite' && !extension_loaded('pdo_sqlite')) {
    $dbProblem = 'Na tomto hostingu není dostupná databáze SQLite. Založte si u poskytovatele databázi MySQL a její údaje zapište do souboru storage/config.php podle vzoru config.example.php.';
} elseif (db_driver() === 'mysql' && !extension_loaded('pdo_mysql')) {
    $dbProblem = 'Na tomto hostingu chybí rozšíření PHP pro MySQL (pdo_mysql). Požádejte poskytovatele hostingu o jeho zapnutí.';
} else {
    try {
        db();
    } catch (PDOException $e) {
        error_log('SVJ web, databáze: ' . $e->getMessage());
        $dbProblem = db_driver() === 'mysql'
            ? 'Nepodařilo se připojit k databázi MySQL. Zkontrolujte adresu serveru, název databáze, uživatele a heslo v souboru storage/config.php.'
            : 'Nepodařilo se otevřít databázi. Zkontrolujte, že PHP smí zapisovat do složky storage.';
    }
}
if ($dbProblem !== null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Web není nastaven</title><p>' . htmlspecialchars($dbProblem) . '</p></html>';
    exit;
}

$METHOD = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
if ($METHOD === 'POST') {
    check_csrf();
}

// První spuštění: dokud neexistuje žádný správce, nabídni jeho vytvoření.
$needsSetup = (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin'") === 0;
if ($needsSetup && $PATH !== '/instalace') {
    redirect('/instalace');
}
// Uživatel s dočasným heslem si ho musí nejdřív změnit.
if (user() && user()['must_change_password'] && !in_array($PATH, ['/muj-ucet', '/odhlaseni'], true)) {
    flash('Přihlásili jste se dočasným heslem. Nastavte si prosím vlastní heslo.', 'info');
    redirect('/muj-ucet');
}

// Ikonu webu žádají některé prohlížeče na pevné adrese.
if ($PATH === '/favicon.ico') {
    header('Location: ' . asset('favicon.svg'), true, 302);
    exit;
}

$routes = [];
$route = function (string $method, string $pattern, callable $handler) use (&$routes): void {
    $routes[] = [$method, $pattern, $handler];
};
require __DIR__ . '/app/routes_public.php';
require __DIR__ . '/app/routes_member.php';
require __DIR__ . '/app/routes_admin.php';

foreach ($routes as [$m, $pattern, $handler]) {
    if ($m === $METHOD && preg_match('#^' . $pattern . '$#', $PATH, $mm)) {
        $args = array_map(fn($v) => ctype_digit($v) ? (int) $v : $v, array_slice($mm, 1));
        // Každá úspěšná změna provedená správcem v administraci se zapisuje do záznamů (kdo, co, co se změnilo).
        if ($METHOD === 'POST' && str_starts_with($PATH, '/admin') && is_admin()) {
            admin_audit_begin($pattern, $args);
        }
        $handler(...$args);
        exit;
    }
}
not_found();

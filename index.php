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
session_name('svj_session');
session_set_cookie_params(['lifetime' => 0, 'path' => $BASE . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

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

$routes = [];
$route = function (string $method, string $pattern, callable $handler) use (&$routes): void {
    $routes[] = [$method, $pattern, $handler];
};
require __DIR__ . '/app/routes_public.php';
require __DIR__ . '/app/routes_member.php';
require __DIR__ . '/app/routes_admin.php';

foreach ($routes as [$m, $pattern, $handler]) {
    if ($m === $METHOD && preg_match('#^' . $pattern . '$#', $PATH, $mm)) {
        $handler(...array_map('intval', array_slice($mm, 1)));
        exit;
    }
}
not_found();

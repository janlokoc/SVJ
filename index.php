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
    report_auto($e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Chyba</title><p>Omlouváme se, na webu došlo k chybě. Zkuste to prosím za chvíli znovu.</p></html>';
});

date_default_timezone_set('Europe/Prague');
mb_internal_encoding('UTF-8');

// Zkoušení přes Cloudflare Tunnel: požadavek přijde od programu cloudflared na tomtéž počítači (127.0.0.1).
// Skutečnou adresu návštěvníka a to, že přišel přes HTTPS, pošle Cloudflare v hlavičkách; jinému odesílateli se nevěří.
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    if (filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $_SERVER['HTTPS'] = 'on';
    }
}
// Dočasná adresa tunelu je veřejná: vyhledávače ji nemají indexovat.
if (str_ends_with(strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))), '.trycloudflare.com')) {
    header('X-Robots-Tag: noindex, nofollow');
}

$BASE = PHP_SAPI === 'cli-server' ? '' : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$PATH = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($BASE !== '' && str_starts_with($PATH, $BASE)) {
    $PATH = substr($PATH, strlen($BASE));
}
$PATH = '/' . trim($PATH, '/');
if ($PATH === '/index.php') {
    $PATH = '/';
}

// Provoz pro více SVJ: podle adresy v prohlížeči se určí, zda jde o konzoli platformy, nebo o web konkrétního společenství.
$IS_PLATFORM = false;
if (platform_enabled()) {
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $onMain = $host === platform_domain() || $host === 'www.' . platform_domain();
    $tenantRow = null;
    // Weby jako složky hlavní domény (svj.cz/tabor): první část adresy určí společenství a zbytek je adresa uvnitř jeho webu.
    if ($onMain && platform_paths() && preg_match('~^/([a-z0-9][a-z0-9-]{1,38})(/.*)?$~', $PATH, $pm) && !in_array($pm[1], PLATFORM_PATHS, true) && ($tenantRow = tenant_row($pm[1]))) {
        // Adresa bez lomítka na konci (svj.cz/tabor) se sjednotí na svj.cz/tabor/ – přihlašovací cookie platí pro cestu /tabor/.
        if (($pm[2] ?? '') === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !str_ends_with((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')) {
            header('Location: ' . $BASE . '/' . $pm[1] . '/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
            exit;
        }
        $GLOBALS['ASSET_BASE'] = $BASE;
        $GLOBALS['ASSET_BASE_SET'] = true;   // web je dostupný jako složka hlavní domény
        $BASE .= '/' . $pm[1];
        $PATH = '/' . trim($pm[2] ?? '', '/');
    }
    if ($onMain && !$tenantRow) {
        $IS_PLATFORM = true;
        boot_tenant('');
    } else {
        $tenantRow ??= tenant_by_host($host);
        if (!$tenantRow || !$tenantRow['active']) {
            http_response_code($tenantRow ? 503 : 404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Web není dostupný</title><p>'
                . ($tenantRow ? 'Tento web je dočasně pozastaven. Obraťte se prosím na výbor svého společenství.' : 'Na této adrese žádný web společenství není.') . '</p></html>';
            exit;
        }
        boot_tenant($tenantRow['slug']);
    }
} else {
    boot_tenant('');
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) SESSION_LIMIT);
// Přihlášení se ukládají do složky daného společenství (ne do společné složky serveru): relace jednoho webu na jiném vůbec neexistuje.
$sessionDir = ($IS_PLATFORM ? ROOT_STORAGE . '/platform-sessions' : STORAGE_DIR . '/sessions');
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0700, true);
}
session_save_path($sessionDir);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
// Ve variantě se složkami sdílejí všechny weby jednu doménu, proto má každý vlastní název i cestu přihlašovací cookie.
session_name(!$IS_PLATFORM && !empty($GLOBALS['ASSET_BASE_SET']) ? 'svj_s_' . str_replace('-', '_', TENANT) : 'svj_session');
session_set_cookie_params(['lifetime' => 0, 'path' => $BASE . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();
// Přihlášení platí jen pro web, na kterém vzniklo: relaci nejde přenést na web jiného společenství ani do konzole platformy.
$sessionOwner = $IS_PLATFORM ? '#platform' : TENANT;
if (($_SESSION['t'] ?? $sessionOwner) !== $sessionOwner) {
    $_SESSION = [];
    session_regenerate_id(false);   // původní relaci nemažeme, patří jinému webu
}
$_SESSION['t'] = $sessionOwner;

// Automatické odhlášení po delší nečinnosti a nejdéle po 12 hodinách.
if (!$IS_PLATFORM && !empty($_SESSION['uid'])) {
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
if (is_staging()) {
    header('X-Robots-Tag: noindex, nofollow');
}
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

if (platform_enabled() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    platform_daily();   // připomenutí plateb, jednou denně
}
// Světlý / tmavý vzhled / podle zařízení – volba návštěvníka, platí pro celou službu.
// Platí i v konzoli platformy (/sprava/vzhled/…), kde se předpona /sprava odstraňuje až později.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~^(/sprava)?/vzhled/(svetly|tmavy|system)$~', $PATH, $tm)) {
    theme_set(['svetly' => 'light', 'tmavy' => 'dark', 'system' => ''][$tm[2]]);
    $back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $back = $BASE !== '' && str_starts_with($back, $BASE) ? substr($back, strlen($BASE)) : $back;
    header('Location: ' . url(preg_match('~^/(?![/\\\\])[^\s\\\\]*$~', $back) && !str_contains($back, '/vzhled/') ? $back : ($tm[1] !== '' ? '/sprava' : '/')), true, 303);
    exit;
}
// Plánovač hostingu (webcron) volá tuto adresu s tajným klíčem z config.php ('cron_key'): odešle frontu e-mailů
// a spustí denní úlohy i u webů, na které ten den nikdo nepřišel (upozornění na spotřebu, revize, platby).
if (preg_match('~^/cron/([A-Za-z0-9_\-]{16,100})$~', $PATH, $cronKey) && ($IS_PLATFORM || !platform_enabled())) {
    $ck = (string) (app_config()['cron_key'] ?? '');
    if ($ck === '' || !hash_equals($ck, $cronKey[1])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    @set_time_limit(150);
    [$sentQ, $badQ, $leftQ] = mailq_run(100, true);
    echo "E-maily z fronty: odesláno $sentQ, nepodařilo se $badQ, čeká $leftQ\n";
    if (platform_enabled()) {
        platform_daily();
        [$woke, $waiting] = cron_wake_tenants(60);
        echo "Denní úlohy webů: spuštěno $woke" . ($waiting ? ", zbývá $waiting (příště)" : '') . "\n";
    } elseif ((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0) {
        daily_tasks();
        echo "Denní úlohy: hotovo\n";
    }
    exit;
}
// Pravidelná záloha (GitHub Actions ji stáhne, zašifruje a uloží): tajný klíč 'backup_key' v config.php.
if (preg_match('~^/zaloha/([A-Za-z0-9_\-]{32,100})$~', $PATH, $bk) && ($IS_PLATFORM || !platform_enabled())) {
    $key = (string) (app_config()['backup_key'] ?? '');
    if ($key === '' || strlen($key) < 32 || !hash_equals($key, $bk[1])) {
        http_response_code(404);
        exit;
    }
    backup_send();
}
if ($IS_PLATFORM && preg_match('~^/sprava(/mcp(?:/[A-Za-z0-9_\-]{20,})?)$~', $PATH, $mcpPath)) {
    // MCP server pro hlášení: vlastní ověření klíčem, bez formulářového tokenu a bez přihlášení do konzole.
    $PATH = $mcpPath[1];
    require __DIR__ . '/app/platform_mcp.php';
    exit;
}
if ($IS_PLATFORM) {
    $METHOD = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
    $GLOBALS['LAYOUT'] = 'platform/layout';
    if ($METHOD === 'POST') {
        check_csrf();
    }
    $GLOBALS['IS_PLATFORM'] = true;
    require __DIR__ . '/app/platform.php';
    exit;
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
// Adresa pro příjem odečtů ze strojů (odečtová firma, sběrnice) se ověřuje tajným klíčem v adrese, ne formulářovým tokenem.
if ($METHOD === 'POST' && !preg_match('~^/api/odecty/[a-f0-9]{40}$~', $PATH)) {
    check_csrf();
}

// První spuštění: dokud neexistuje žádný správce, nabídni jeho vytvoření.
$needsSetup = (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin'") === 0;
if ($needsSetup && $PATH !== '/instalace') {
    redirect('/instalace');
}
// Uživatel s dočasným heslem si ho musí nejdřív změnit.
if (user() && user()['must_change_password'] && !in_array($PATH, ['/muj-ucet', '/odhlaseni', '/sw.js', '/apple-touch-icon.png', '/manifest.webmanifest'], true)) {
    flash('Přihlásili jste se dočasným heslem. Nastavte si prosím vlastní heslo.', 'info');
    redirect('/muj-ucet');
}

// Ukázkový web je jen pro čtení: přihlašuje se tlačítkem a žádná změna se neuloží.
$GLOBALS['DEMO'] = TENANT !== '' && !empty($tenantRow['demo']);
// Ukázkový web nemá účty s heslem: místo přihlášení nabídne vstup jedním kliknutím jako obyvatel nebo jako správce.
// Rozcestník /vstup se ukáže vždy (odkaz „Prohlédnout ukázku“ vede sem), přihlašovací stránky jen nepřihlášenému.
if ($GLOBALS['DEMO'] && $METHOD === 'GET' && $PATH === '/vstup') {
    render('demo_login', ['title' => 'Vstup do ukázky']);
    exit;
}
// Vstup z ukázky partnerského portálu: rovnou jako správce (ukázkový dům je jen pro čtení).
if ($GLOBALS['DEMO'] && $METHOD === 'GET' && $PATH === '/demo-vstup') {
    $who = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 AND email LIKE 'ukazka-partner@%'") ?? one("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
    if ($who) {
        $edition = $_SESSION['demo_edition'] ?? null;
        login_user($who);
        $_SESSION['partner'] = 'Ukázková správa domů s.r.o.';
        if ($edition) {
            $_SESSION['demo_edition'] = $edition;
        }
    }
    redirect(ctype_digit((string) ($_GET['zavada'] ?? '')) ? '/admin/zavady?f=vse#z' . (int) $_GET['zavada'] : '/admin');
}
if ($GLOBALS['DEMO'] && $METHOD === 'GET' && (in_array($PATH, ['/prihlaseni', '/prihlaseni/overeni', '/zapomenute-heslo'], true) || str_starts_with($PATH, '/obnova-hesla/'))) {
    if (user()) {
        redirect(is_admin() ? '/admin' : '/');
    }
    render('demo_login', ['title' => 'Vstup do ukázky']);
    exit;
}
if ($GLOBALS['DEMO'] && $METHOD === 'POST' && $PATH !== '/odhlaseni') {
    if ($PATH === '/demo-edice') {
        $_SESSION['demo_edition'] = isset(EDITIONS[post('edice')]) ? post('edice') : 'premium';
        $back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        $back = $BASE !== '' && str_starts_with($back, $BASE) ? (string) substr($back, strlen($BASE)) : $back;
        redirect(preg_match('~^/(?![/\\\\])~', $back) ? $back : '/');
    }
    if ($PATH === '/demo-prihlaseni') {
        if (post('jako') === 'host') {
            // Návštěvník bez účtu: odhlásit a ukázat veřejnou část webu.
            $edition = $_SESSION['demo_edition'] ?? null;   // zvolená edice zůstává i po odhlášení
            $_SESSION = $edition ? ['demo_edition' => $edition] : [];
            session_regenerate_id(true);
            redirect('/');
        }
        $role = post('jako') === 'admin' ? 'admin' : 'resident';
        if ($demoUser = one('SELECT * FROM users WHERE role = ? AND active = 1 ORDER BY id LIMIT 1', [$role])) {
            $edition = $_SESSION['demo_edition'] ?? null;
            login_user($demoUser);
            if ($edition) {
                $_SESSION['demo_edition'] = $edition;
            }
        }
        redirect($role === 'admin' ? '/admin' : '/');
    }
    flash('V ukázce se změny neukládají. Na Vašem vlastním webu by se tato akce provedla.', 'info');
    $ref = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $ref = $BASE !== '' && str_starts_with($ref, $BASE) ? (string) substr($ref, strlen($BASE)) : $ref;
    redirect(preg_match('~^/(?![/\\\\])~', $ref) ? $ref : '/');
}

// Edice: web se nikdy nepozastavuje. Funkce, která není v edici, jde vyzkoušet; po skončení zkoušky zůstává jen pro čtení.
$GLOBALS['BILLING'] = TENANT !== '' ? billing_state($tenantRow) : ['mode' => 'free', 'until' => '', 'days' => 0, 'edition' => 'premium'];
// Režim podpory je jen pro čtení: žádný formulář se neprovede (kromě odchodu) a soukromé zprávy se neukazují.
if (support_mode()) {
    support_lock(true);
    if ($METHOD === 'POST' && $PATH !== '/odhlaseni') {
        flash('Režim podpory je jen pro čtení – nic se neuložilo. Změnu musí udělat uživatel sám.', 'error');
        $back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        redirect($back !== '' && str_starts_with($back, ($GLOBALS['BASE'] ?? '') . '/') ? substr($back, strlen($GLOBALS['BASE'] ?? '')) : '/');
    }
    if ($PATH === '/zpravy' || str_starts_with($PATH, '/zpravy/')) {
        flash('Soukromé zprávy mezi uživateli se v režimu podpory nezobrazují.', 'info');
        redirect('/');
    }
}
if (TENANT !== '' && !$needsSetup && ($planFeature = plan_feature_for($PATH)) !== null && !plan_has($planFeature)) {
    $planState = plan_state($planFeature);
    $inAdminPath = str_starts_with($PATH, '/admin');
    if ($planState === 'available') {
        if (!$inAdminPath || !is_admin()) {
            $inAdminPath && !user() ? redirect('/prihlaseni') : not_found();
        }
        if ($METHOD === 'POST') {
            redirect('/admin/predplatne#funkce');
        }
        render('admin/plan_try', ['title' => PLAN_FEATURES[$planFeature][1], 'feature' => $planFeature, 'back' => $PATH]);
        exit;
    }
    // Zkouška skončila: data zůstávají ke čtení, měnit je nejde.
    $GLOBALS['PLAN_READONLY'] = $planFeature;
    if ($METHOD === 'POST') {
        flash('Zkouška funkce „' . PLAN_FEATURES[$planFeature][1] . '“ skončila, proto ji teď lze jen prohlížet. Dál ji můžete používat s edicí ' . EDITIONS[PLAN_FEATURES[$planFeature][0]] . '.', 'error');
        if ($planFeature === 'ai-zapis') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Zkouška AI zápisu skončila. Dál ho můžete používat s edicí Premium.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect($inAdminPath && is_admin() ? '/admin/predplatne#funkce' : '/');
    }
}

// Ikonu webu žádají některé prohlížeče na pevné adrese.
if ($PATH === '/favicon.ico') {
    header('Location: ' . asset('favicon.svg'), true, 302);
    exit;
}

// Úlohy jednou denně (připomenutí revizí) spustí první návštěva dne.
if ($METHOD === 'GET' && !$needsSetup && !support_mode()) {
    daily_tasks();
}
// Fronta e-mailů (limit hostingu): čekající zprávy odcházejí po vyřízení požadavku, nejvýš jednou za minutu.
if (function_exists('mailq_run') && is_file(mailq_marker())) {
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();   // návštěvník nečeká na odeslání
        }
        mailq_run();
    });
}

$routes = [];
$route = function (string $method, string $pattern, callable $handler) use (&$routes): void {
    $routes[] = [$method, $pattern, $handler];
};
require __DIR__ . '/app/routes_public.php';
require __DIR__ . '/app/routes_member.php';
require __DIR__ . '/app/routes_admin.php';
require __DIR__ . '/app/routes_extra.php';
require __DIR__ . '/app/routes_more.php';
require __DIR__ . '/app/routes_chat.php';
require __DIR__ . '/app/routes_gdpr.php';
require __DIR__ . '/app/routes_onboarding.php';
require __DIR__ . '/app/routes_join.php';
require __DIR__ . '/app/routes_meters.php';
require __DIR__ . '/app/routes_presence.php';
require __DIR__ . '/app/routes_help.php';
require __DIR__ . '/app/routes_push.php';
require __DIR__ . '/app/routes_search.php';
require __DIR__ . '/app/routes_assistant.php';
require __DIR__ . '/app/routes_reports.php';
if (TENANT !== '') {
    require __DIR__ . '/app/routes_billing.php';
}

// Části webu, které správce společenství schoval, nejsou dostupné ani přímou adresou.
foreach (FEATURES as $key => [$label, $public, $adminPaths]) {
    if (!feature_on($key)) {
        foreach ([...$public, ...$adminPaths] as $prefix) {
            if ($PATH === $prefix || str_starts_with($PATH, $prefix . '/')) {
                not_found();
            }
        }
    }
}

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

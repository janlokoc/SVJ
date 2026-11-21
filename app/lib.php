<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');
putenv('TZ=Europe/Prague');   // stejný čas používá i SQLite pro výchozí hodnoty sloupců, ať je server v jakémkoli pásmu

// Kořen úložiště. Při provozu pro více SVJ má každé společenství vlastní podsložku (data, databázi i soubory zcela oddělené).
const ROOT_STORAGE = __DIR__ . '/../storage';

/** Určí, pro které společenství tento požadavek běží ('' = jediný web bez platformy), a nastaví cesty k jeho datům. */
function boot_tenant(string $slug): void
{
    define('TENANT', $slug);
    define('STORAGE_DIR', $slug === '' ? ROOT_STORAGE : ROOT_STORAGE . '/tenants/' . $slug);
    define('UPLOAD_DIR', STORAGE_DIR . '/uploads');
}
// Nástroje z příkazové řádky: společenství se volí proměnnou prostředí, např. SVJ_TENANT=tabor php tools/…
if (PHP_SAPI === 'cli') {
    $cliTenant = (string) getenv('SVJ_TENANT');
    boot_tenant(preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $cliTenant) ? $cliTenant : '');
}

const LANGS = ['cs' => 'Čeština', 'sk' => 'Slovenčina', 'en' => 'English', 'uk' => 'Українська', 'vi' => 'Tiếng Việt'];
const LOOKS = ['les' => 'Les – zelená a cihlová', 'more' => 'Moře – modrá a tyrkysová', 'cihla' => 'Cihla – červenohnědá a písková', 'grafit' => 'Grafit – šedá a jantarová', 'svestka' => 'Švestka – fialová a zlatá'];
/** Části webu, které může správce společenství schovat z menu: klíč → [název, adresy pro obyvatele, adresy v administraci]. */
const FEATURES = [
    'aktuality' => ['Aktuality', ['/aktuality'], ['/admin/aktuality']],
    'dokumenty' => ['Dokumenty', ['/dokumenty'], []],
    'kontakty' => ['Kontakty', ['/kontakty'], ['/admin/kontakty']],
    'diskuze' => ['Diskuze', ['/diskuze'], ['/admin/diskuze']],
    'zavady' => ['Závady a požadavky', ['/zavady'], ['/admin/zavady']],
    'hlasovani' => ['Hlasování', ['/hlasovani'], ['/admin/hlasovani']],
    'odecty' => ['Odečty měřidel', ['/odecty'], ['/admin/odecty']],
    'rezervace' => ['Rezervace', ['/rezervace'], ['/admin/rezervace', '/admin/prostory']],
];
const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'jpg', 'jpeg', 'png'];

const CATEGORIES = [
    'zakladni' => 'Stanovy a smlouvy',
    'pozvanky' => 'Pozvánky',
    'zapisy' => 'Zápisy ze shromáždění',
    'uzaverky' => 'Účetní závěrky',
    'oznameni' => 'Oznámení',
    'nabidky' => 'Nabídky a projekty',
    'formulare' => 'Formuláře',
];
const CONTACT_GROUPS = ['vybor' => 'Výbor SVJ', 'sprava' => 'Správa a účetnictví', 'havarie' => 'Havarijní servis'];
const TICKET_STATUS = ['new' => 'Nová', 'in_progress' => 'Řeší se', 'done' => 'Vyřešeno'];
const VISIBILITY = ['public' => 'Veřejné', 'residents' => 'Jen pro přihlášené'];
const KINDS = ['owner' => 'Vlastník', 'tenant' => 'Nájemník'];
const AUDIENCES = ['owners' => 'Vlastníci', 'all' => 'Všichni obyvatelé (vlastníci i nájemníci)'];
const NO_PASSWORD = '!'; // účet čeká na přijetí pozvánky, přihlásit se zatím nejde
const ROLES = ['resident' => 'Obyvatel domu', 'admin' => 'Správce webu'];

// ---------- Databáze ----------

/** Nastavení ze souboru storage/config.php (viz config.example.php); bez něj se použije SQLite. */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = ROOT_STORAGE . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
    }
    return $config;
}
function db_driver(): string
{
    return (app_config()['db']['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
}
/** Dotazy jsou psané pro SQLite; pro MySQL/MariaDB se těch několik odlišných zápisů převede zde. */
function sql(string $sql): string
{
    // Aktuální čas dosazuje PHP (český čas), aby výsledek nezávisel na časovém pásmu serveru ani databáze.
    $sql = strtr($sql, [
        "datetime('now','localtime','-1 day')" => "'" . date('Y-m-d H:i:s', time() - 86400) . "'",
        "datetime('now','localtime','-1 year')" => "'" . date('Y-m-d H:i:s', strtotime('-1 year')) . "'",
        "datetime('now','localtime')" => "'" . date('Y-m-d H:i:s') . "'",
        "date('now','localtime')" => "'" . date('Y-m-d') . "'",
    ]);
    if (db_driver() !== 'mysql') {
        return $sql;
    }
    $sql = str_replace('INSERT OR IGNORE', 'INSERT IGNORE', $sql);
    $sql = preg_replace('/ON CONFLICT\s*\([^)]*\)\s*DO UPDATE SET/', 'ON DUPLICATE KEY UPDATE', $sql);
    return preg_replace('/\bexcluded\.(\w+)/', 'VALUES($1)', $sql);
}
function db_mysql(string $tenant): PDO
{
    $c = app_config()['db'];
    $name = (string) ($c['name'] ?? '');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'] ?? 'localhost', (int) ($c['port'] ?? 3306));
    if ($tenant !== '') {
        // Každé společenství má vlastní databázi; v názvu musí být zástupný text {tenant}, jinak by se data smíchala.
        if (!str_contains($name, '{tenant}')) {
            throw new RuntimeException('Pro provoz více SVJ na MySQL musí název databáze v storage/config.php obsahovat {tenant}, např. svj_{tenant}.');
        }
        $name = str_replace('{tenant}', str_replace('-', '_', $tenant), $name);
        try {
            (new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''), $options))->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            // Bez práva zakládat databáze ji musí předem založit správce serveru; připojení níže pak buď projde, nebo ohlásí chybu.
        }
    }
    $pdo = new PDO($dsn . ';dbname=' . $name, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''), $options);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/schema.mysql.sql')))) as $statement) {
            $pdo->exec($statement);
        }
        require_once __DIR__ . '/seed.php';
        $pdo->beginTransaction();
        seed_for($pdo, $tenant);
        $pdo->exec("INSERT INTO settings (`key`, value) VALUES ('schema_version', '6')");
        $pdo->commit();
        return $pdo;
    }
    $mysqlVersion = (int) $pdo->query("SELECT value FROM settings WHERE `key` = 'schema_version'")->fetchColumn();
    if ($mysqlVersion < 6) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN unit_id INT NULL, ADD FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL');
        $pdo->exec("ALTER TABLE units ADD COLUMN owner_contact VARCHAR(2000) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE events ADD COLUMN program VARCHAR(4000) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE event_rsvp ADD COLUMN proxy_name VARCHAR(191) NOT NULL DEFAULT ''");
        foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/schema.v6.mysql.sql')))) as $statement) {
            $pdo->exec($statement);
        }
    }
    if ($mysqlVersion < 5) {
        $pdo->exec("ALTER TABLE audit_log ADD COLUMN user_name VARCHAR(191) NOT NULL DEFAULT '', ADD COLUMN chain CHAR(64) NULL, MODIFY action VARCHAR(100) NOT NULL, MODIFY detail VARCHAR(1000) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE forum_posts ADD COLUMN edited_note VARCHAR(191) NOT NULL DEFAULT ''");
    }
    if ($mysqlVersion < 6) {
        $pdo->exec("UPDATE settings SET value = '6' WHERE `key` = 'schema_version'");
    }
    return $pdo;
}
function db(): PDO
{
    static $pdo = null;
    return $pdo ??= open_database(STORAGE_DIR, TENANT);
}
/** Otevře databázi jednoho společenství ve složce $storage; při prvním otevření ji založí a naplní, starší doplní. */
function open_database(string $storage, string $tenant): PDO
{
    if (!is_dir($storage . '/uploads')) {
        mkdir($storage . '/uploads', 0775, true);
    }
    foreach (['', '/uploads'] as $dir) {
        if (!is_file($storage . $dir . '/index.html')) {
            @file_put_contents($storage . $dir . '/index.html', '');
        }
    }
    if (db_driver() === 'mysql') {
        return db_mysql($tenant);
    }
    // Databáze má náhodný název, aby ji nešlo stáhnout ani na serveru, který by nerespektoval zákaz přístupu do složky storage.
    if (is_file($storage . '/svj.sqlite')) {
        rename($storage . '/svj.sqlite', $storage . '/svj-' . bin2hex(random_bytes(12)) . '.sqlite');
    }
    $found = glob($storage . '/svj-*.sqlite');
    $file = $found ? $found[0] : $storage . '/svj-' . bin2hex(random_bytes(12)) . '.sqlite';
    $fresh = !$found;
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($fresh) {
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
        require_once __DIR__ . '/seed.php';
        seed_for($pdo, $tenant);
        $pdo->exec('PRAGMA user_version = 6');
        $pdo->commit();
        return $pdo;
    }
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        // Doplnění starší databáze o rozesílání e-mailů.
        $pdo->beginTransaction();
        $pdo->exec("ALTER TABLE users ADD COLUMN kind TEXT NOT NULL DEFAULT 'owner' CHECK (kind IN ('owner','tenant'))");
        $pdo->exec('ALTER TABLE users ADD COLUMN email_notify INTEGER NOT NULL DEFAULT 1');
        $pdo->exec("CREATE TABLE mailings (id INTEGER PRIMARY KEY AUTOINCREMENT, subject TEXT NOT NULL, body TEXT NOT NULL,
            audience TEXT NOT NULL CHECK (audience IN ('owners','all')), sent_count INTEGER NOT NULL DEFAULT 0, failed_count INTEGER NOT NULL DEFAULT 0,
            failed_list TEXT NOT NULL DEFAULT '', sent_by INTEGER REFERENCES users(id) ON DELETE SET NULL, sent_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('PRAGMA user_version = 1');
        $pdo->commit();
    }
    if ($version < 2) {
        // Doplnění starší databáze o seznam jednotek a pozvánky; dosavadní vchod a číslo bytu u účtů se převedou na jednotky.
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
CREATE TABLE units (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entrance TEXT NOT NULL DEFAULT '',
  number TEXT NOT NULL,
  floor TEXT NOT NULL DEFAULT '',
  share TEXT NOT NULL DEFAULT '',
  owner_note TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  UNIQUE (entrance, number)
);
CREATE TABLE user_units (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, unit_id)
);
ALTER TABLE users ADD COLUMN invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE users ADD COLUMN invite_hash TEXT;
ALTER TABLE users ADD COLUMN invite_expires TEXT;
INSERT OR IGNORE INTO units (entrance, number) SELECT DISTINCT entrance, unit FROM users WHERE unit <> '';
INSERT INTO user_units (user_id, unit_id) SELECT u.id, n.id FROM users u JOIN units n ON n.entrance = u.entrance AND n.number = u.unit WHERE u.unit <> '';
SQL);
        $pdo->exec('PRAGMA user_version = 2');
        $pdo->commit();
    }
    if ($version < 3) {
        // Doplnění starší databáze o účast na shromáždění a evidenci příjemců e-mailů.
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
ALTER TABLE events ADD COLUMN rsvp INTEGER NOT NULL DEFAULT 0;
ALTER TABLE mailings ADD COLUMN tracked INTEGER NOT NULL DEFAULT 0;
CREATE TABLE event_rsvp (
  event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL CHECK (status IN ('yes','proxy','no')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  PRIMARY KEY (event_id, unit_id)
);
CREATE TABLE mailing_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  mailing_id INTEGER NOT NULL REFERENCES mailings(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  token TEXT NOT NULL UNIQUE,
  sent_ok INTEGER NOT NULL DEFAULT 0,
  opened_at TEXT,
  clicked_at TEXT
);
SQL);
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->commit();
    }
    if ($version < 4) {
        // Doplnění starší databáze o dvoufázové ověření, zámek účtu a záznamy událostí.
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
ALTER TABLE users ADD COLUMN totp_secret TEXT;
ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN totp_last INTEGER NOT NULL DEFAULT 0;
ALTER TABLE login_attempts ADD COLUMN email TEXT NOT NULL DEFAULT '';
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, ip TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
SQL);
        $pdo->exec('PRAGMA user_version = 4');
        $pdo->commit();
    }
    if ($version < 5) {
        // Doplnění starší databáze: jméno a otisk u záznamů činnosti, poznámka o úpravě příspěvku správcem.
        $pdo->beginTransaction();
        $pdo->exec("ALTER TABLE audit_log ADD COLUMN user_name TEXT NOT NULL DEFAULT ''");
        $pdo->exec('ALTER TABLE audit_log ADD COLUMN chain TEXT');
        $pdo->exec("ALTER TABLE forum_posts ADD COLUMN edited_note TEXT NOT NULL DEFAULT ''");
        $pdo->exec('PRAGMA user_version = 5');
        $pdo->commit();
    }
    if ($version < 6) {
        // Doplnění starší databáze: hlasování, dokumenty jednotek, revize, odečty, rezervace, klíče, podklady shromáždění.
        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE documents ADD COLUMN unit_id INTEGER REFERENCES units(id) ON DELETE SET NULL');
        $pdo->exec("ALTER TABLE units ADD COLUMN owner_contact TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE events ADD COLUMN program TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE event_rsvp ADD COLUMN proxy_name TEXT NOT NULL DEFAULT ''");
        $pdo->exec(file_get_contents(__DIR__ . '/schema.v6.sql'));
        $pdo->exec('PRAGMA user_version = 6');
        $pdo->commit();
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare(sql($sql));
    $st->execute($params);
    return $st;
}
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function val(string $sql, array $params = []): mixed
{
    return q($sql, $params)->fetchColumn();
}
/** Pojmenování webu daného společenství (nastavuje se v Nastavení → Vzhled a název). */
function site(string $key): string
{
    static $defaults = ['name' => 'SVJ Tábor 48', 'title' => 'Tábor 48', 'accent' => 'a–d', 'tagline' => 'Společenství vlastníků jednotek · Brno',
        'full' => 'Společenství vlastníků jednotek Tábor 48', 'unit_prefix' => '48', 'entrances' => 'A,B,C,D', 'look' => 'les', 'lang' => 'cs'];
    $v = setting('site_' . $key, "\0");
    return $v === "\0" ? $defaults[$key] : $v;
}
/** Označení vchodů v domě; první prázdná položka znamená „bez vchodu“. */
function entrances(): array
{
    $list = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtoupper(trim($x)), explode(',', site('entrances'))), fn($x) => preg_match('/^[\p{L}\p{N}]{1,3}$/u', $x))));
    return ['', ...array_slice($list, 0, 26)];
}
function unit_prefix(): string
{
    return mb_substr(preg_replace('/[^\p{L}\p{N} .\-]/u', '', site('unit_prefix')), 0, 10);
}
function feature_on(string $key): bool
{
    return !in_array($key, array_filter(explode(',', setting('hidden_features'))), true);
}
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = array_column(all('SELECT `key`, value FROM settings'), 'value', 'key');
    }
    return $cache[$key] ?? $default;
}

// ---------- Výstup a formátování ----------

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function url(string $path = '/'): string
{
    return ($GLOBALS['BASE'] ?? '') . $path;
}
function asset(string $path): string
{
    $f = __DIR__ . '/../assets/' . $path;
    return url('/assets/' . $path) . (is_file($f) ? '?v=' . filemtime($f) : '');
}
function redirect(string $path): never
{
    admin_audit_flush();
    header('Location: ' . url($path), true, 303);
    exit;
}
function not_found(): never
{
    http_response_code(404);
    render('error', ['title' => 'Stránka nenalezena', 'message' => 'Tato stránka neexistuje, nebo byla přesunuta.']);
    exit;
}
function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    ob_start();
    require __DIR__ . '/views/' . ($GLOBALS['LAYOUT'] ?? 'layout') . '.php';
    $html = ob_get_clean();
    echo page_translatable() ? translate_html($html, current_lang()) : $html;
}
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        if ($type === 'error') {
            unset($GLOBALS['ADMIN_AUDIT']);   // akce se nepovedla, nezapisuje se jako provedená
        }
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

const MONTHS = ['', 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
const MONTHS_SHORT = ['', 'led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];
const WEEKDAYS = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

function cz_date(?string $iso, bool $long = false): string
{
    if (!$iso || !($t = strtotime($iso))) {
        return '';
    }
    if (!$long) {
        return date('j. n. Y', $t);
    }
    $month = month_name((int) date('n', $t));
    $lang = page_translatable() ? current_lang() : 'cs';
    return in_array($lang, ['en', 'uk', 'vi'], true) ? date('j', $t) . ' ' . $month . ($lang === 'vi' ? ', ' : ' ') . date('Y', $t) : date('j', $t) . '. ' . $month . ' ' . date('Y', $t);
}
function cz_datetime(?string $iso): string
{
    return $iso && ($t = strtotime($iso)) ? date('j. n. Y H:i', $t) : '';
}
function file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' MB' : max(1, (int) round($bytes / 1024)) . ' kB';
}
function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}
function tel_href(string $phone): string
{
    $n = preg_replace('/[^0-9+]/', '', $phone);
    return 'tel:' . (strlen($n) === 9 ? '+420' . $n : $n);
}

/** Bezpečné formátování textu: odstavce, odrážky (- ), nadpisy (## ), **tučně**, odkazy. */
function format_text(string $text): string
{
    $inline = function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        return preg_replace_callback('~https?://[^\s<]+[^\s<.,;:!?)]~u', fn($m) => '<a href="' . $m[0] . '" rel="noopener noreferrer">' . $m[0] . '</a>', $s);
    };
    $out = '';
    $paragraph = [];
    $list = [];
    $flush = function () use (&$out, &$paragraph, &$list, $inline) {
        if ($paragraph) {
            $out .= '<p>' . implode('<br>', array_map($inline, $paragraph)) . '</p>';
        }
        if ($list) {
            $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . $inline($l) . '</li>', $list)) . '</ul>';
        }
        $paragraph = $list = [];
    };
    foreach (explode("\n", trim(str_replace("\r", '', $text))) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
        } elseif (str_starts_with($line, '## ')) {
            $flush();
            $out .= '<h3>' . $inline(substr($line, 3)) . '</h3>';
        } elseif (str_starts_with($line, '- ')) {
            if ($paragraph) {
                $flush();
            }
            $list[] = substr($line, 2);
        } else {
            if ($list) {
                $flush();
            }
            $paragraph[] = $line;
        }
    }
    $flush();
    return $out;
}
function excerpt(string $text, int $len = 180): string
{
    $t = trim(preg_replace('/\s+/u', ' ', str_replace(['**', '## '], '', $text)));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
}
function icon(string $name): string
{
    static $paths = [
        'doc' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'pin' => '<path d="M12 17v5M8 3h8l-1 6 3 4H6l3-4z"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'alert' => '<path d="M12 3 2 20h20zM12 10v4M12 17.5v.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'wrench' => '<path d="M14.5 6.5a4 4 0 0 0 5 5L11 20a2.1 2.1 0 0 1-3-3l8.5-8.5a4 4 0 0 1-2-2z"/><path d="m14.5 6.5 3-3"/>',
        'download' => '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// ---------- Přihlášení a oprávnění ----------

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null : one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
        // Po změně hesla (i správcem) přestávají platit všechna dřívější přihlášení.
        if ($u && !hash_equals(pw_fingerprint($u['password_hash']), (string) ($_SESSION['pv'] ?? ''))) {
            $u = null;
        }
        if (!$u) {
            unset($_SESSION['uid'], $_SESSION['pv']);
        }
    }
    return $u;
}
function is_admin(): bool
{
    return (user()['role'] ?? '') === 'admin';
}
function require_login(): array
{
    if (!user()) {
        $_SESSION['after_login'] = $GLOBALS['PATH'] ?? '/';
        flash('Tato část webu je jen pro obyvatele domu. Přihlaste se prosím.', 'info');
        redirect('/prihlaseni');
    }
    return user();
}
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        render('error', ['title' => 'Přístup odepřen', 'message' => 'Tato část je určena jen správcům webu.']);
        exit;
    }
    return $u;
}
/** SQL podmínka viditelnosti pro aktuálního návštěvníka. */
function vis_sql(string $alias = ''): string
{
    return user() ? '1=1' : ($alias ? "$alias." : '') . "visibility = 'public'";
}
function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}
function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        render('error', ['title' => 'Formulář vypršel', 'message' => 'Vraťte se prosím zpět, načtěte stránku znovu a odešlete formulář ještě jednou.']);
        exit;
    }
}
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function random_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 12; $i++) {
        $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $p;
}
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------- Soubory ----------

/** Uloží nahraný soubor a vrátí [filename, original_name, size], nebo text chyby. */
function store_upload(array $f): array|string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Soubor je příliš velký (limit serveru je ' . ini_get('upload_max_filesize') . ').'
            : 'Soubor se nepodařilo nahrát.';
    }
    $orig = basename((string) $f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        return 'Nepovolený typ souboru. Povolené jsou: ' . implode(', ', ALLOWED_EXT) . '.';
    }
    // Obsah musí odpovídat příponě – přejmenovaný program nebo skript se nenahraje.
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $expected = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']][$ext]
        ?? ['application/zip', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet'];
    if (!in_array($mime, $expected, true)) {
        return 'Obsah souboru neodpovídá jeho příponě (.' . $ext . '). Soubor nebyl nahrán.';
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        return 'Soubor se nepodařilo uložit. Zkontrolujte práva zápisu do složky storage.';
    }
    return [$name, $orig, (int) filesize(UPLOAD_DIR . '/' . $name)];
}
/** Převede $_FILES['x'] s atributem multiple na seznam jednotlivých souborů. */
function uploaded_list(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!$f || !is_array($f['name'])) {
        return [];
    }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    }
    return $out;
}
function delete_document(int $id): void
{
    $d = one('SELECT filename FROM documents WHERE id = ?', [$id]);
    if ($d && $d['filename'] && preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $d['filename'])) {
        @unlink(UPLOAD_DIR . '/' . $d['filename']);
    }
    q('DELETE FROM documents WHERE id = ?', [$id]);
}
function http_download(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXREDIRS => 3]);
        $data = curl_exec($ch);
        $ok = $data !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok ? $data : null;
    }
    $data = @file_get_contents($url);
    return $data === false ? null : $data;
}
/** Stáhne soubory, které zatím leží jen na starém webu, do úložiště nového webu. Vrací [staženo, chyb, zbývá]. */
function import_external_files(int $limit): array
{
    $done = $failed = 0;
    $rows = all("SELECT id, external_url, original_name FROM documents WHERE filename IS NULL AND external_url LIKE 'https://%' ORDER BY id LIMIT " . (int) $limit);
    foreach ($rows as $r) {
        $ext = strtolower(pathinfo($r['original_name'], PATHINFO_EXTENSION));
        $data = in_array($ext, ALLOWED_EXT, true) ? http_download($r['external_url']) : null;
        if ($data === null || $data === '') {
            $failed++;
            q("UPDATE documents SET external_url = replace(external_url, 'https://', 'https-failed://') WHERE id = ?", [$r['id']]);
            continue;
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        file_put_contents(UPLOAD_DIR . '/' . $name, $data);
        q('UPDATE documents SET filename = ?, size = ?, external_url = NULL WHERE id = ?', [$name, strlen($data), $r['id']]);
        $done++;
    }
    // Neúspěšné pokusy vrátíme zpět, aby šly zkusit znovu při dalším spuštění.
    q("UPDATE documents SET external_url = replace(external_url, 'https-failed://', 'https://') WHERE external_url LIKE 'https-failed://%'");
    return [$done, $failed, (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NULL AND external_url IS NOT NULL')];
}
function post_documents(int $postId): array
{
    return all('SELECT d.* FROM documents d JOIN post_documents pd ON pd.document_id = d.id WHERE pd.post_id = ? ORDER BY d.title', [$postId]);
}
function author_label(?array $row): string
{
    if (!$row || empty($row['author_name'])) {
        return 'Bývalý uživatel';
    }
    return $row['author_name'] . (!empty($row['author_units']) ? ' (' . $row['author_units'] . ')' : '');
}

// ---------- E-maily ----------

/** Úplná adresa webu včetně domény – pro odkazy v e-mailech. */
function absolute_url(string $path = '/'): string
{
    // Pevně nastavená adresa webu má přednost – odkazy v e-mailech pak nejde podvrhnout upravenou hlavičkou požadavku.
    $fixed = setting('site_url');
    if (preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$~', $fixed)) {
        return rtrim($fixed, '/') . $path;
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}
function mail_recipients(string $audience): array
{
    // Účty založené správcem dostávají zprávy i před prvním přihlášením; lidé pozvaní jiným obyvatelem až po přijetí pozvánky.
    return all("SELECT id, name, email FROM users WHERE active = 1 AND email_notify = 1 AND (password_hash <> '!' OR invited_by IS NULL)" . ($audience === 'owners' ? " AND kind = 'owner'" : '') . ' ORDER BY name');
}
/** Odešle jeden e-mail jako prostý text v UTF-8. Vrací, zda ho server přijal k odeslání. */
function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    $from = setting('mail_from');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $name = trim(preg_replace('/[\r\n"<>]+/', ' ', setting('mail_from_name', site('name'))));
    $subject = trim(preg_replace('/\s+/u', ' ', $subject));
    $headers = [
        'From' => mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n") . ' <' . $from . '>',
        'Reply-To' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'Auto-Submitted' => 'auto-generated',
    ];
    $body = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $text)), 76, "\r\n");
    if ($html !== null) {
        $b = 'svj-' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $b . '"';
        unset($headers['Content-Transfer-Encoding']);
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$body\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n--$b--\r\n";
    }
    $params = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from) ? '-f' . $from : '';
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"), $body, $headers, $params);
}

// ---------- Jednotky ----------

/** SQL výraz: označení jednotek uživatele (např. „48C/12“), pro tabulku s aliasem $alias. */
function units_sql(string $alias): string
{
    $prefix = db()->quote(unit_prefix());
    $names = db_driver() === 'mysql' ? "GROUP_CONCAT(CONCAT($prefix, un.entrance, '/', un.number) ORDER BY un.entrance, un.number SEPARATOR ', ')" : "group_concat($prefix || un.entrance || '/' || un.number, ', ')";
    return "(SELECT $names FROM user_units uu JOIN units un ON un.id = uu.unit_id WHERE uu.user_id = $alias.id)";
}
function unit_name(array $unit): string
{
    return unit_prefix() . $unit['entrance'] . '/' . $unit['number'];
}
function user_unit_ids(int $userId): array
{
    return array_map('intval', array_column(all('SELECT unit_id FROM user_units WHERE user_id = ?', [$userId]), 'unit_id'));
}
function all_units(): array
{
    return all('SELECT * FROM units ORDER BY entrance, number + 0, number');
}

// ---------- Nastavení, účast na shromáždění, sledování e-mailů ----------

const RSVP = ['yes' => 'Přijdu osobně', 'proxy' => 'Zastoupí mě zmocněnec', 'no' => 'Nepřijdu'];

function setting_on(string $key, bool $default = true): bool
{
    return setting($key, $default ? '1' : '0') === '1';
}
function setting_int(string $key, int $default, int $min, int $max): int
{
    $v = setting($key, (string) $default);
    return ctype_digit($v) ? max($min, min($max, (int) $v)) : $default;
}
/** Převede zápis podílu („652/100000“, „0,65 %“) na číslo; nečitelný zápis dá 0. */
function share_value(string $share): float
{
    $s = str_replace([' ', ','], ['', '.'], trim($share));
    if (preg_match('~^(\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$~', $s, $m)) {
        return (float) $m[2] > 0 ? (float) $m[1] / (float) $m[2] : 0.0;
    }
    return preg_match('~^(\d+(?:\.\d+)?)%$~', $s, $m) ? (float) $m[1] / 100 : 0.0;
}
/**
 * Přehled přihlášené účasti na termínu a odhad usnášeníschopnosti.
 * Váhou jednotky je její spoluvlastnický podíl; pokud ho některá jednotka nemá vyplněný, počítá se každá jednotka stejně.
 */
function attendance(int $eventId): array
{
    $rows = all('SELECT un.*, r.status, r.proxy_name, r.updated_at, u.name responder FROM units un
        LEFT JOIN event_rsvp r ON r.unit_id = un.id AND r.event_id = ?
        LEFT JOIN users u ON u.id = r.user_id ORDER BY un.entrance, un.number + 0, un.number', [$eventId]);
    $byShare = (bool) $rows;
    foreach ($rows as $r) {
        $byShare = $byShare && share_value($r['share']) > 0;
    }
    $total = $present = 0.0;
    $counts = ['yes' => 0, 'proxy' => 0, 'no' => 0, 'none' => 0];
    foreach ($rows as &$r) {
        $r['weight'] = $byShare ? share_value($r['share']) : 1.0;
        $total += $r['weight'];
        $counts[$r['status'] ?? 'none']++;
        if ($r['status'] === 'yes' || $r['status'] === 'proxy') {
            $present += $r['weight'];
        }
    }
    unset($r);
    $threshold = setting_int('quorum_percent', 50, 1, 99);
    $percent = $total > 0 ? $present / $total * 100 : 0.0;
    return ['rows' => $rows, 'byShare' => $byShare, 'percent' => $percent, 'threshold' => $threshold,
        'quorate' => $percent > $threshold, 'counts' => $counts, 'units' => count($rows)];
}
function percent_cz(float $p): string
{
    return number_format($p, 1, ',', ' ') . ' %';
}
/** Sestaví textovou a HTML podobu zprávy; s $token přepíše odkazy na tento web na sledované a přidá měřicí obrázek. */
function build_mail(string $body, ?string $token): array
{
    $base = rtrim(absolute_url('/'), '/');
    $track = fn(string $url): string => $token === null ? $url
        : $base . '/e/' . $token . '?u=' . rawurlencode(substr($url, strlen($base)) ?: '/');
    $pattern = '~' . preg_quote($base, '~') . '(?:/[^\s<"]*[^\s<".,;:!?)])?~u';
    $footer = 'Zprávu posílá výbor ' . site('name') . ' obyvatelům domu s účtem na webu.';
    $notice = $token === null ? '' : ' Web eviduje otevření zprávy a odkazů v ní, aby výbor věděl, ke komu se informace dostala.';
    $text = preg_replace_callback($pattern, fn($m) => $track($m[0]), trim(str_replace('**', '', $body)))
        . "\n\n-- \n" . $footer . $notice . "\nZasílání zpráv si můžete vypnout ve svém účtu: " . absolute_url('/muj-ucet') . "\n";
    $html = preg_replace_callback('~href="(' . preg_quote(e($base), '~') . '[^"]*)"~u', fn($m) => 'href="' . e($track(html_entity_decode($m[1], ENT_QUOTES))) . '"', format_text($body));
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1b2622;max-width:600px">' . $html
        . '<p style="font-size:13px;color:#586660;border-top:1px solid #ddd5c3;padding-top:12px;margin-top:24px">' . e($footer . $notice)
        . ' Zasílání zpráv si můžete vypnout ve <a href="' . e(absolute_url('/muj-ucet')) . '">svém účtu</a>.</p>'
        . ($token === null ? '' : '<img src="' . e($base . '/e/' . $token . '.gif') . '" width="1" height="1" alt="">') . '</div>';
    return [$text, $html];
}

// ---------- Zabezpečení ----------

const IDLE_LIMIT = 7200;      // odhlášení po 2 hodinách nečinnosti
const SESSION_LIMIT = 43200;  // nejdéle 12 hodin na jedno přihlášení

/** Otisk hesla uložený v relaci: po změně hesla přestanou platit všechna ostatní přihlášení. */
function pw_fingerprint(string $hash): string
{
    return substr(hash('sha256', 'svj-session|' . $hash), 0, 32);
}
function login_user(array $u): void
{
    session_regenerate_id(true);
    // 't' váže přihlášení ke konkrétnímu společenství: relaci nejde přenést na web jiného SVJ.
    $_SESSION = ['t' => TENANT, 'uid' => (int) $u['id'], 'pv' => pw_fingerprint($u['password_hash']), 'start' => time(), 'seen' => time()];
}
/**
 * Zapíše událost do záznamů. Záznamy se nikdy nemažou ani neupravují; každý nese otisk navazující na předchozí,
 * takže dodatečný zásah přímo v databázi je poznat (viz audit_chain_check).
 */
function audit(string $action, string $detail = '', ?int $userId = null): void
{
    $GLOBALS['AUDITED'] = true;
    $uid = $userId ?? ($_SESSION['uid'] ?? null);
    $name = $uid ? (string) val('SELECT name FROM users WHERE id = ?', [$uid]) : '';
    $detail = mb_substr($detail, 0, 1000);
    $ip = PHP_SAPI === 'cli' ? 'příkazová řádka' : client_ip();
    $pdo = db();
    // Zápisy jdou po jednom, aby otisky navazovaly i při souběžných požadavcích: v MySQL hlídá pořadí zámek,
    // v SQLite transakce, která začíná zápisem (další zapisující počká).
    $mysql = db_driver() === 'mysql';
    $own = !$pdo->inTransaction();
    if ($mysql) {
        $pdo->query("SELECT GET_LOCK('svj_audit', 5)");
    }
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $at = date('Y-m-d H:i:s');
        q('INSERT INTO audit_log (at, user_id, user_name, ip, action, detail) VALUES (?,?,?,?,?,?)', [$at, $uid, $name, $ip, $action, $detail]);
        $id = (int) $pdo->lastInsertId();
        $prev = (string) val('SELECT chain FROM audit_log WHERE id < ? AND chain IS NOT NULL ORDER BY id DESC LIMIT 1', [$id]);
        q('UPDATE audit_log SET chain = ? WHERE id = ?', [hash('sha256', implode('|', [$prev, $at, $name, $ip, $action, $detail])), $id]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        if ($mysql) {
            $pdo->query("SELECT RELEASE_LOCK('svj_audit')");
        }
    }
}
/** Ochrana proti hádání hesel: nejvýše 8 chyb z jedné adresy a 10 chyb na jeden účet za 15 minut. */
function login_blocked(string $email): bool
{
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    return (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()]) >= 8
        || ($email !== '' && (int) val('SELECT COUNT(*) FROM login_attempts WHERE email = ?', [mb_strtolower($email)]) >= 10);
}
function login_failed(string $email, string $what = 'login_failed'): void
{
    q('INSERT INTO login_attempts (ip, at, email) VALUES (?,?,?)', [client_ip(), time(), mb_strtolower($email)]);
    audit($what, $email);
}
function finish_login(array $u, mixed $after): never
{
    login_user($u);
    q("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?", [$u['id']]);
    q('DELETE FROM login_attempts WHERE ip = ? OR email = ?', [client_ip(), mb_strtolower($u['email'])]);
    audit('login', $u['email']);
    redirect(is_string($after) && preg_match('~^/(?![/\\\\])~', $after) ? $after : ($u['role'] === 'admin' ? '/admin' : '/'));
}
/** Vrátí důvod, proč heslo nelze použít, nebo null, když je v pořádku. */
function password_problem(string $pass, string $email = '', string $name = ''): ?string
{
    static $common = ['1234567890', '0123456789', '12345678910', '0987654321', 'qwertyuiop', 'qwertzuiop', 'asdfghjkl1', 'password123', 'password1234',
        'heslo12345', 'heslo123456', 'heslo1234567', 'hesloheslo', 'administrator', 'adminadmin', 'tabor48tabor48', 'svjtabor48', 'iloveyou123', 'qwerty12345', 'abcdefghij', 'aaaaaaaaaa', '1q2w3e4r5t', '1q2w3e4r5t6z', 'milujutebe', 'nevimheslo'];
    $low = mb_strtolower($pass);
    if (mb_strlen($pass) < 10) {
        return 'Heslo musí mít alespoň 10 znaků.';
    }
    if (mb_strlen($pass) > 200) {
        return 'Heslo je příliš dlouhé (nejvýše 200 znaků).';
    }
    if (in_array($low, $common, true) || preg_match('/^(.)\1+$/u', $pass) || ctype_digit($pass)) {
        return 'Toto heslo je příliš snadné uhodnout. Zvolte jiné – nejlépe několik nesouvisejících slov.';
    }
    $local = mb_strtolower(strstr($email, '@', true) ?: '');
    if (($local !== '' && mb_strlen($local) >= 4 && str_contains($low, $local)) || ($name !== '' && $low === mb_strtolower($name))) {
        return 'Heslo nesmí obsahovat Váš e-mail ani jméno.';
    }
    return null;
}
/** Instalační kód pro první spuštění; leží v souboru s náhodným názvem ve složce storage, kam se dostane jen ten, kdo web nahrál. */
function setup_code(?string $storage = null): string
{
    $storage ??= STORAGE_DIR;
    $files = glob($storage . '/instalacni-kod-*.txt');
    if ($files) {
        return trim((string) file_get_contents($files[0]));
    }
    $code = strtoupper(bin2hex(random_bytes(6)));
    file_put_contents($storage . '/instalacni-kod-' . bin2hex(random_bytes(8)) . '.txt', $code . "\n");
    return $code;
}

// ---------- Dvoufázové ověření (TOTP, RFC 6238) ----------

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_new_secret(): string
{
    $s = '';
    foreach (str_split(random_bytes(20)) as $byte) {
        $s .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    return implode('', array_map(fn($c) => BASE32[bindec($c)], str_split($s, 5)));
}
function totp_code(string $secret, int $counter): string
{
    $bits = '';
    foreach (str_split(strtoupper($secret)) as $c) {
        $bits .= str_pad(decbin((int) strpos(BASE32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $key = implode('', array_map(fn($b) => chr(bindec($b)), array_filter(str_split($bits, 8), fn($b) => strlen($b) === 8)));
    $h = hash_hmac('sha1', pack('J', $counter), $key, true);
    $o = ord($h[19]) & 0x0f;
    return str_pad((string) ((unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Ověří kód (s tolerancí ±30 s). Vrátí časový krok použitého kódu, nebo null; krok ≤ $last odmítne, aby kód nešel použít dvakrát. */
function totp_verify(string $secret, string $code, int $last = 0): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([$now, $now - 1, $now + 1] as $step) {
        if ($step > $last && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

// ---------- Import vlastníků z katastru nemovitostí ----------

function gcd_int(int $a, int $b): int
{
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }
    return max(1, abs($a));
}
/**
 * Zpracuje text zkopírovaný z Nahlížení do KN (stránka stavby, část „Vlastníci, jiní oprávnění“).
 * Vrací jednotky s vlastníky a podílem (součet podílů vlastníků jednotky) a případná upozornění.
 */
function parse_cadastre(string $text): array
{
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);   // odkazy ve tvaru [text](adresa) → text
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn($l) => $l !== ''));
    $start = 0;
    foreach ($lines as $i => $l) {
        if (str_starts_with($l, 'Vlastnické právo')) {
            $start = $i + 1;
        }
    }
    $units = [];
    $warnings = [];
    for ($i = $start; $i < count($lines) - 1; $i++) {
        if (!preg_match('/^Jednotk[ay]\s*:\s*(.+)$/u', $lines[$i + 1], $m) || str_starts_with($lines[$i], 'Jednotk')) {
            continue;
        }
        preg_match_all('~\d+\s*/\s*\d+~', $m[1], $f);
        $fractions = array_map(fn($x) => preg_replace('/\s+/', '', $x), $f[0]);
        if (count($fractions) < 2) {
            $warnings[] = 'Řádek „' . mb_substr($lines[$i + 1], 0, 60) . '“ se nepodařilo přečíst (chybí jednotka nebo podíl).';
            continue;
        }
        [$num, $den] = array_map('intval', explode('/', array_pop($fractions)));
        $name = trim(explode(',', $lines[$i])[0]);
        $contact = $lines[$i];
        // U společného jmění manželů bez společné adresy následují řádky s adresou každého z nich.
        for ($j = $i + 2; str_starts_with($name, 'SJ ') && $j <= $i + 3 && isset($lines[$j]) && str_contains($lines[$j], ',')
            && !str_starts_with($lines[$j], 'Jednotk') && !str_starts_with($lines[$j + 1] ?? '', 'Jednotk'); $j++) {
            $contact .= ' | ' . $lines[$j];
        }
        if ($den <= 0 || $name === '') {
            continue;
        }
        if (count($fractions) > 1) {
            $warnings[] = $name . ': jeden podíl ' . $num . '/' . $den . ' je v katastru uveden společně pro jednotky ' . implode(', ', $fractions) . '. Rozdělil se mezi ně rovným dílem – skutečné rozdělení ověřte v prohlášení vlastníka.';
        }
        foreach ($fractions as $unitKey) {
            [$cp, $number] = explode('/', $unitKey);
            $units[$unitKey] ??= ['cp' => (int) $cp, 'number' => $number, 'owners' => [], 'num' => 0, 'den' => 1];
            $u = &$units[$unitKey];
            $u['owners'][] = ['name' => $name, 'share' => $num . '/' . $den, 'contact' => $contact];
            // přičtení podílu: a/b + c/d
            $addNum = $num;
            $addDen = $den * count($fractions);
            $u['num'] = $u['num'] * $addDen + $addNum * $u['den'];
            $u['den'] *= $addDen;
            $g = gcd_int($u['num'], $u['den']);
            $u['num'] = intdiv($u['num'], $g);
            $u['den'] = intdiv($u['den'], $g);
            unset($u);
        }
        $i++;
    }
    // Čísla popisná → vchody A, B, C, D podle pořadí.
    $cps = array_values(array_unique(array_column($units, 'cp')));
    sort($cps);
    $letters = array_values(array_filter(entrances()));
    $total = 0.0;
    foreach ($units as &$u) {
        $u['entrance'] = $letters[array_search($u['cp'], $cps, true)] ?? '';
        $u['share'] = $u['num'] . '/' . $u['den'];
        $u['owner_contact'] = mb_substr(implode("\n", array_map(fn($o) => $o['contact'], $u['owners'])), 0, 2000);
        $u['owner_note'] = mb_substr(implode('; ', array_map(fn($o) => $o['name'] . (count($u['owners']) > 1 ? ' (' . $o['share'] . ')' : ''), $u['owners'])), 0, 255);
        $total += $u['num'] / $u['den'];
    }
    unset($u);
    if (count($cps) > count($letters)) {
        $warnings[] = 'Stavba má víc čísel popisných než vchodů A–D; jednotky z dalších vchodů zůstaly bez označení vchodu.';
    }
    uasort($units, fn($a, $b) => [$a['cp'], (int) $a['number']] <=> [$b['cp'], (int) $b['number']]);
    return ['units' => $units, 'warnings' => $warnings, 'total' => $total];
}

// ---------- Import uživatelů a pozvánky od správce ----------

/**
 * Zpracuje seznam uživatelů vložený z tabulky (sloupce: jméno, příjmení, telefon, e-mail, vlastník/nájemník, volitelně jednotka).
 * Oddělovačem může být tabulátor (kopie z Excelu), středník nebo čárka. Vrací řádky se stavem „ok“, „exists“ nebo „error“.
 */
function parse_user_import(string $text): array
{
    $lines = array_values(array_filter(preg_split('/\R/u', $text), fn($l) => trim($l) !== ''));
    $rows = [];
    $seen = [];
    $unitIndex = [];
    foreach (all_units() as $un) {
        $unitIndex[mb_strtoupper($un['entrance']) . '/' . $un['number']] = $un;
    }
    foreach ($lines as $n => $line) {
        $delimiter = str_contains($line, "\t") ? "\t" : (substr_count($line, ';') >= substr_count($line, ',') && str_contains($line, ';') ? ';' : ',');
        $c = array_map(fn($v) => trim((string) $v), str_getcsv($line, $delimiter, '"', ''));
        $c = array_pad($c, 6, '');
        // Záhlaví tabulky (první řádek bez e-mailové adresy) se přeskočí.
        if ($n === 0 && !str_contains($line, '@')) {
            continue;
        }
        $row = ['line' => $n + 1, 'name' => trim($c[0] . ' ' . $c[1]), 'phone' => $c[2], 'email' => $c[3],
            'kind' => preg_match('/^(n|t)/iu', $c[4]) ? 'tenant' : 'owner', 'units' => [], 'status' => 'ok', 'message' => ''];
        if ($row['name'] === '') {
            $row['status'] = 'error';
            $row['message'] = 'Chybí jméno.';
        } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $row['status'] = 'error';
            $row['message'] = 'E-mail nemá správný tvar.';
        } elseif (isset($seen[mb_strtolower($row['email'])])) {
            $row['status'] = 'error';
            $row['message'] = 'Stejný e-mail je v seznamu dvakrát.';
        } elseif (val('SELECT 1 FROM users WHERE email = ?', [$row['email']])) {
            $row['status'] = 'exists';
            $row['message'] = 'Účet s tímto e-mailem už existuje – přeskočí se.';
        }
        $seen[mb_strtolower($row['email'])] = true;
        if ($c[5] !== '') {
            preg_match_all('~(?:' . preg_quote(unit_prefix(), '~') . '\s*)?([\p{L}])\s*/?\s*(\d+)~u', $c[5], $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $key = mb_strtoupper($hit[1]) . '/' . $hit[2];
                isset($unitIndex[$key]) ? $row['units'][] = $unitIndex[$key] : $row['message'] = trim($row['message'] . ' Jednotka ' . unit_prefix() . $key . ' v seznamu jednotek není.');
            }
            if (!$m) {
                $row['message'] = trim($row['message'] . ' Jednotku „' . $c[5] . '“ se nepodařilo přečíst (pište např. 48A/9).');
            }
        }
        $rows[] = $row;
    }
    return $rows;
}
/** Vystaví účtu, který ještě nemá heslo, pozvánku a pošle ji e-mailem. Vrací [odesláno?, odkaz]. */
function send_account_invite(array $user): array
{
    $days = setting_int('invite_days', 14, 1, 90);
    $token = bin2hex(random_bytes(32));
    q('UPDATE users SET invite_hash = ?, invite_expires = ? WHERE id = ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() + $days * 86400), $user['id']]);
    $link = absolute_url('/pozvanka/' . $token);
    $text = "Dobrý den,\n\nvýbor " . site('name') . " Vám založil účet na webu společenství. Najdete tam oznámení výboru, dokumenty a můžete potvrdit účast na shromáždění.\n\nÚčet si aktivujete nastavením hesla na této adrese (platí $days dní):\n$link\n\nPřihlašovat se budete tímto e-mailem. Pokud zprávu nečekáte, ignorujte ji prosím.\n";
    return [send_mail($user['email'], 'Váš účet na webu ' . site('name'), $text), $link];
}

// ---------- Záznam činnosti správců ----------

/** Akce v administraci: vzor adresy → [popis, tabulka, sloupec s názvem záznamu]. Co tu chybí, zapíše se s adresou. */
const ADMIN_ACTIONS = [
    '/admin/aktuality/nova' => ['Nová aktualita', null, null],
    '/admin/aktuality/(\d+)' => ['Úprava aktuality', 'posts', 'title'],
    '/admin/aktuality/(\d+)/smazat' => ['Smazání aktuality', 'posts', 'title'],
    '/admin/dokumenty/novy' => ['Nový dokument', null, null],
    '/admin/dokumenty/(\d+)' => ['Úprava dokumentu', 'documents', 'title'],
    '/admin/dokumenty/(\d+)/smazat' => ['Smazání dokumentu', 'documents', 'title'],
    '/admin/kontakty/novy' => ['Nový kontakt', null, null],
    '/admin/kontakty/(\d+)' => ['Úprava kontaktu', 'contacts', 'name'],
    '/admin/kontakty/(\d+)/smazat' => ['Smazání kontaktu', 'contacts', 'name'],
    '/admin/terminy/novy' => ['Nový termín', null, null],
    '/admin/terminy/(\d+)' => ['Úprava termínu', 'events', 'title'],
    '/admin/terminy/(\d+)/smazat' => ['Smazání termínu', 'events', 'title'],
    '/admin/terminy/(\d+)/ucast' => ['Zápis účasti na shromáždění', 'events', 'title'],
    '/admin/uzivatele/novy' => ['Založení účtu', null, null],
    '/admin/uzivatele/(\d+)' => ['Úprava účtu', 'users', 'email'],
    '/admin/uzivatele/(\d+)/smazat' => ['Smazání účtu', 'users', 'email'],
    '/admin/zavady/(\d+)' => ['Úprava závady / požadavku', 'tickets', 'subject'],
    '/admin/zavady/(\d+)/smazat' => ['Smazání závady / požadavku', 'tickets', 'subject'],
    '/admin/jednotky/nova' => ['Nová jednotka', null, null],
    '/admin/jednotky/(\d+)' => ['Úprava jednotky', 'units', null],
    '/admin/jednotky/(\d+)/smazat' => ['Smazání jednotky', 'units', null],
    '/admin/jednotky/hromadne' => ['Hromadné založení jednotek', null, null],
    '/admin/diskuze/(\d+)' => ['Úprava příspěvku v diskuzi', 'forum_posts', 'title'],
    '/admin/nastaveni' => ['Změna nastavení', 'settings', null],
    '/admin/import' => ['Stažení souborů ze starého webu', null, null],
    '/admin/hlasovani/nove' => ['Nové hlasování', null, null],
    '/admin/hlasovani/(\d+)' => ['Úprava hlasování', 'polls', 'title'],
    '/admin/hlasovani/(\d+)/smazat' => ['Smazání hlasování', 'polls', 'title'],
    '/admin/revize/(\d+)/provedeno' => ['Revize zapsána jako provedená', 'maintenance', 'title'],
];
const AUDIT_FIELDS = ['title' => 'nadpis', 'body' => 'text', 'visibility' => 'viditelnost', 'pinned' => 'připnuto', 'published_at' => 'datum zveřejnění',
    'name' => 'jméno', 'phone' => 'telefon', 'role' => 'role', 'kind' => 'typ', 'active' => 'aktivní', 'email_notify' => 'zasílání e-mailů',
    'share' => 'podíl', 'owner_note' => 'vlastník dle evidence', 'floor' => 'patro', 'entrance' => 'vchod', 'number' => 'číslo', 'note' => 'poznámka',
    'status' => 'stav', 'admin_note' => 'odpověď výboru', 'subject' => 'předmět', 'location' => 'místo', 'category' => 'kategorie', 'doc_date' => 'datum',
    'original_name' => 'soubor', 'event_date' => 'datum', 'event_time' => 'čas', 'place' => 'místo', 'rsvp' => 'sbírat účast', 'grp' => 'skupina', 'sort' => 'pořadí',
    'must_change_password' => 'vynucená změna hesla', 'totp_enabled' => 'dvoufázové ověření', 'email' => 'e-mail', 'owner_contact' => 'adresa pro doručování', 'program' => 'program', 'unit_id' => 'jednotka', 'opens_at' => 'začátek', 'closes_at' => 'konec',
    'threshold' => 'hranice přijetí (%)', 'online' => 'hlasování přes web', 'published' => 'zveřejnit výsledky', 'contractor' => 'kdo provádí', 'last_date' => 'naposledy provedeno',
    'interval_months' => 'interval (měsíce)', 'next_date' => 'příští termín', 'label' => 'označení', 'holder' => 'komu vydáno', 'issued_at' => 'vydáno', 'returned_at' => 'vráceno',
    'kind_reading' => 'měřidlo', 'meter_no' => 'číslo měřidla', 'amount' => 'stav', 'read_date' => 'datum odečtu', 'res_date' => 'den', 'time_from' => 'od', 'time_to' => 'do', 'privacy_text' => 'text o ochraně údajů',
    // nastavení webu
    'site_url' => 'adresa webu', 'intro' => 'úvodní text', 'address' => 'adresa domu', 'ico' => 'IČ', 'account' => 'číslo účtu', 'emergency_note' => 'pravidla havarijního servisu',
    'forum_note' => 'úvodní text diskuze', 'webmaster_email' => 'e-mail správce webu', 'mail_from' => 'adresa odesílatele', 'mail_from_name' => 'jméno odesílatele',
    'mail_tracking' => 'sledování e-mailů', 'invite_enabled' => 'pozvánky vlastníků', 'invite_by_tenants' => 'pozvánky od nájemníků', 'invite_kind' => 'pozvaný je veden jako',
    'invite_max' => 'nejvíc účtů na jednotku', 'invite_days' => 'platnost pozvánky (dní)', 'quorum_percent' => 'hranice usnášeníschopnosti (%)'];
const AUDIT_SKIP = ['reminded_for', 'event_id', 'created_by', 'resource_id', 'id', 'password_hash', 'totp_secret', 'totp_last', 'invite_hash', 'invite_expires', 'last_login_at', 'created_at', 'updated_at', 'filename', 'size', 'external_url', 'edited_note', 'last_activity_at', 'author_id', 'user_id', 'invited_by'];

function audit_row(?string $table, int $id): ?array
{
    if ($table === 'settings') {
        return array_column(all('SELECT `key`, value FROM settings'), 'value', 'key');
    }
    return $table && $id ? one("SELECT * FROM $table WHERE id = ?", [$id]) : null;
}
function audit_value(mixed $v, string $col = ''): string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    $named = match ($col) {
        'kind' => KINDS, 'role' => ROLES, 'status' => TICKET_STATUS, 'visibility' => VISIBILITY, 'category' => CATEGORIES, 'grp' => CONTACT_GROUPS, 'invite_kind' => KINDS,
        'pinned', 'active', 'email_notify', 'rsvp', 'must_change_password', 'totp_enabled', 'mail_tracking', 'invite_enabled', 'invite_by_tenants' => ['0' => 'ne', '1' => 'ano'],
        default => [],
    };
    if (isset($named[$v])) {
        return mb_strtolower($named[$v]);
    }
    return $v === '' ? '(prázdné)' : '„' . (mb_strlen($v) > 40 ? mb_substr($v, 0, 40) . '…' : $v) . '“';
}
/** Zavolá se před obsluhou požadavku správce: zapamatuje si akci a stav záznamu před změnou. */
function admin_audit_begin(string $pattern, array $args): void
{
    $known = ADMIN_ACTIONS[$pattern] ?? null;
    // Obecné evidence (revize, prostory, rezervace, klíče, odečty).
    if (!$known && preg_match('~^/admin/([a-z]+)(/novy|/\(\\d\+\)(/smazat)?)$~', $pattern, $m) && isset(crud_defs()[$m[1]])) {
        $def = crud_defs()[$m[1]];
        $known = [($m[2] === '/novy' ? 'Nový záznam' : (isset($m[3]) ? 'Smazání záznamu' : 'Úprava záznamu')) . ' – ' . $def['title'], $def['table'], $def['name']];
    }
    [$label, $table, $nameCol] = $known ?? ['Akce v administraci (' . ($GLOBALS['PATH'] ?? '') . ')', null, null];
    $id = isset($args[0]) && is_int($args[0]) ? $args[0] : 0;
    $GLOBALS['ADMIN_AUDIT'] = ['label' => $label, 'table' => $table, 'id' => $id, 'nameCol' => $nameCol, 'before' => audit_row($table, $id)];
}
/** Zavolá se po úspěšném dokončení akce (před přesměrováním): zapíše, co se změnilo. */
function admin_audit_flush(): void
{
    $a = $GLOBALS['ADMIN_AUDIT'] ?? null;
    unset($GLOBALS['ADMIN_AUDIT']);
    if (!$a || !empty($GLOBALS['AUDITED'])) {
        return;   // nic nečeká, nebo akce už zapsala vlastní podrobnější záznam
    }
    $before = $a['before'];
    $after = audit_row($a['table'], $a['id']);
    $row = $after ?? $before ?? [];
    $name = match (true) {
        $a['table'] === 'units' && $row => unit_name($row),
        $a['table'] === 'settings' => '',
        $a['nameCol'] && !empty($row[$a['nameCol']]) => (string) $row[$a['nameCol']],
        $a['table'] === 'forum_posts' => 'příspěvek č. ' . $a['id'],
        default => (string) ($_POST['title'] ?? $_POST['name'] ?? $_POST['subject'] ?? (isset($_POST['number']) ? unit_prefix() . ($_POST['entrance'] ?? '') . '/' . $_POST['number'] : '')),
    };
    $changes = [];
    if ($before && $after) {
        foreach ($after as $col => $new) {
            $old = $before[$col] ?? '';
            $same = fn($x) => trim(str_replace("\r\n", "\n", (string) $x));   // formulář vrací jiné konce řádků, to změna není
            if ($same($old) === $same($new) || in_array($col, AUDIT_SKIP, true)) {
                continue;
            }
            $field = AUDIT_FIELDS[$col] ?? $col;
            $changes[] = mb_strlen((string) $old . (string) $new) > 160 ? "$field: text změněn" : $field . ': ' . audit_value($old, $col) . ' → ' . audit_value($new, $col);
        }
        foreach (array_diff_key($before, $after) as $col => $old) {
            $changes[] = (AUDIT_FIELDS[$col] ?? $col) . ': odstraněno';
        }
    }
    if ($a['label'] === 'Zápis účasti na shromáždění') {
        $unit = one('SELECT * FROM units WHERE id = ?', [(int) ($_POST['unit_id'] ?? 0)]);
        $changes[] = ($unit ? unit_name($unit) : 'jednotka') . ': ' . (RSVP[$_POST['status'] ?? ''] ?? 'bez odpovědi');
    }
    if ($a['table'] === 'users' && $before && $after) {
        $units = (string) val('SELECT ' . units_sql('u') . ' FROM users u WHERE u.id = ?', [$a['id']]);
        $changes[] = 'jednotky: ' . ($units !== '' ? $units : 'žádné');
    }
    audit($a['label'], ltrim($name . ($changes ? ' · ' . implode('; ', $changes) : ($before && $after ? ' · beze změny údajů' : '')), ' ·'));
}
/** Ověří, že do záznamů nikdo nezasáhl: každý záznam nese otisk navazující na předchozí. Vrací [v pořádku?, id prvního vadného, počet ověřených]. */
function audit_chain_check(): array
{
    $prev = null;
    $checked = 0;
    $st = q('SELECT id, at, user_name, ip, action, detail, chain FROM audit_log ORDER BY id');
    while ($r = $st->fetch()) {
        if ($r['chain'] === null && $prev === null) {
            continue;   // starší záznamy z doby před zavedením otisků
        }
        $expected = hash('sha256', implode('|', [$prev ?? '', $r['at'], $r['user_name'], $r['ip'], $r['action'], $r['detail']]));
        if ($r['chain'] === null || !hash_equals($expected, $r['chain'])) {
            // První záznam s otiskem může navazovat na starší záznam bez otisku.
            if ($prev !== null || $r['chain'] === null) {
                return [false, (int) $r['id'], $checked];
            }
        }
        $prev = $r['chain'];
        $checked++;
    }
    return [true, 0, $checked];
}

// ---------- Zapomenuté heslo ----------

/** Pošle odkaz na nastavení nového hesla (platí hodinu). Účtu, který heslo ještě nemá, pošle znovu pozvánku. */
function send_password_reset(array $user): bool
{
    if ($user['password_hash'] === NO_PASSWORD) {
        return send_account_invite($user)[0];
    }
    $token = bin2hex(random_bytes(32));
    q('UPDATE users SET invite_hash = ?, invite_expires = ? WHERE id = ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), $user['id']]);
    $text = "Dobrý den,\n\nna webu " . site('name') . " někdo požádal o nové heslo k Vašemu účtu. Nové heslo si nastavíte na této adrese (platí 1 hodinu):\n"
        . absolute_url('/obnova-hesla/' . $token) . "\n\nPokud jste o to nežádali, zprávu ignorujte – heslo zůstává beze změny.\n";
    return send_mail($user['email'], 'Nové heslo na webu ' . site('name'), $text);
}
/** Krátké označení přihlášeného pod jeho jménem: vlastník/nájemník a jednotky. */
function user_badge(array $u): string
{
    $units = (string) val('SELECT ' . units_sql('u') . ' FROM users u WHERE u.id = ?', [$u['id']]);
    return mb_strtolower(KINDS[$u['kind']] ?? '') . ($units !== '' ? ' · ' . $units : '');
}

// Části aplikace, které knihovna potřebuje vždy (i v nástrojích z příkazové řádky).
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/platform_lib.php';
require_once __DIR__ . '/extra_lib.php';

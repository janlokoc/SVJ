<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');

const STORAGE_DIR = __DIR__ . '/../storage';
const UPLOAD_DIR = STORAGE_DIR . '/uploads';
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
const ENTRANCES = ['', 'A', 'B', 'C', 'D'];

// ---------- Databáze ----------

/** Nastavení ze souboru storage/config.php (viz config.example.php); bez něj se použije SQLite. */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = STORAGE_DIR . '/config.php';
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
    if (db_driver() !== 'mysql') {
        return $sql;
    }
    $sql = strtr($sql, [
        "datetime('now','localtime','-1 day')" => '(NOW() - INTERVAL 1 DAY)',
        "datetime('now','localtime','-1 year')" => '(NOW() - INTERVAL 1 YEAR)',
        "datetime('now','localtime')" => 'NOW()',
        "date('now','localtime')" => 'CURDATE()',
        'INSERT OR IGNORE' => 'INSERT IGNORE',
    ]);
    $sql = preg_replace('/ON CONFLICT\s*\([^)]*\)\s*DO UPDATE SET/', 'ON DUPLICATE KEY UPDATE', $sql);
    return preg_replace('/\bexcluded\.(\w+)/', 'VALUES($1)', $sql);
}
function db_mysql(): PDO
{
    $c = app_config()['db'];
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', (int) ($c['port'] ?? 3306), $c['name'] ?? ''),
        (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/schema.mysql.sql')))) as $statement) {
            $pdo->exec($statement);
        }
        require __DIR__ . '/seed.php';
        $pdo->beginTransaction();
        seed_database($pdo);
        $pdo->exec("INSERT INTO settings (`key`, value) VALUES ('schema_version', '4')");
        $pdo->commit();
    }
    return $pdo;
}
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (db_driver() === 'mysql') {
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0775, true);
        }
        foreach (['', '/uploads'] as $dir) {
            if (!is_file(STORAGE_DIR . $dir . '/index.html')) {
                @file_put_contents(STORAGE_DIR . $dir . '/index.html', '');
            }
        }
        return $pdo = db_mysql();
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    // Databáze má náhodný název, aby ji nešlo stáhnout ani na serveru, který by nerespektoval zákaz přístupu do složky storage.
    if (is_file(STORAGE_DIR . '/svj.sqlite')) {
        rename(STORAGE_DIR . '/svj.sqlite', STORAGE_DIR . '/svj-' . bin2hex(random_bytes(12)) . '.sqlite');
    }
    $found = glob(STORAGE_DIR . '/svj-*.sqlite');
    $file = $found ? $found[0] : STORAGE_DIR . '/svj-' . bin2hex(random_bytes(12)) . '.sqlite';
    $fresh = !$found;
    foreach (['', '/uploads'] as $dir) {
        if (!is_file(STORAGE_DIR . $dir . '/index.html')) {
            @file_put_contents(STORAGE_DIR . $dir . '/index.html', '');
        }
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($fresh) {
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
        require __DIR__ . '/seed.php';
        seed_database($pdo);
        $pdo->exec('PRAGMA user_version = 4');
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
    require __DIR__ . '/views/layout.php';
}
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
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
    return $long ? date('j', $t) . '. ' . MONTHS[(int) date('n', $t)] . ' ' . date('Y', $t) : date('j. n. Y', $t);
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
    foreach (preg_split('/\R\s*\R/u', trim(str_replace("\r", '', $text))) as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== ''));
        if (!$lines) {
            continue;
        }
        if (count(array_filter($lines, fn($l) => str_starts_with($l, '- '))) === count($lines)) {
            $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . $inline(substr($l, 2)) . '</li>', $lines)) . '</ul>';
        } elseif (count($lines) === 1 && str_starts_with($lines[0], '## ')) {
            $out .= '<h3>' . $inline(substr($lines[0], 3)) . '</h3>';
        } else {
            $out .= '<p>' . implode('<br>', array_map($inline, $lines)) . '</p>';
        }
    }
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
    return all("SELECT id, name, email FROM users WHERE active = 1 AND email_notify = 1 AND password_hash <> '!'" . ($audience === 'owners' ? " AND kind = 'owner'" : '') . ' ORDER BY name');
}
/** Odešle jeden e-mail jako prostý text v UTF-8. Vrací, zda ho server přijal k odeslání. */
function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    $from = setting('mail_from');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $name = trim(preg_replace('/[\r\n"<>]+/', ' ', setting('mail_from_name', 'SVJ Tábor 48')));
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
    $names = db_driver() === 'mysql' ? "GROUP_CONCAT(CONCAT('48', un.entrance, '/', un.number) ORDER BY un.entrance, un.number SEPARATOR ', ')" : "group_concat('48' || un.entrance || '/' || un.number, ', ')";
    return "(SELECT $names FROM user_units uu JOIN units un ON un.id = uu.unit_id WHERE uu.user_id = $alias.id)";
}
function unit_name(array $unit): string
{
    return '48' . $unit['entrance'] . '/' . $unit['number'];
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
    $rows = all('SELECT un.*, r.status, r.updated_at, u.name responder FROM units un
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
    $footer = 'Zprávu posílá výbor SVJ Tábor 48 a–d obyvatelům domu s účtem na webu.';
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
    $_SESSION = ['uid' => (int) $u['id'], 'pv' => pw_fingerprint($u['password_hash']), 'start' => time(), 'seen' => time()];
}
function audit(string $action, string $detail = '', ?int $userId = null): void
{
    q('INSERT INTO audit_log (user_id, ip, action, detail) VALUES (?,?,?,?)', [$userId ?? ($_SESSION['uid'] ?? null), client_ip(), $action, mb_substr($detail, 0, 300)]);
    if (random_int(1, 200) === 1) {
        q("DELETE FROM audit_log WHERE at < datetime('now','localtime','-1 year')");
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
function setup_code(): string
{
    $files = glob(STORAGE_DIR . '/instalacni-kod-*.txt');
    if ($files) {
        return trim((string) file_get_contents($files[0]));
    }
    $code = strtoupper(bin2hex(random_bytes(6)));
    file_put_contents(STORAGE_DIR . '/instalacni-kod-' . bin2hex(random_bytes(8)) . '.txt', $code . "\n");
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

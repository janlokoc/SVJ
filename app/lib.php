<?php
declare(strict_types=1);

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

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $file = STORAGE_DIR . '/svj.sqlite';
    $fresh = !is_file($file);
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
        $pdo->exec('PRAGMA user_version = 2');
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
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
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
        $cache = array_column(all('SELECT key, value FROM settings'), 'value', 'key');
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
        if (!$u) {
            unset($_SESSION['uid']);
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
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
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
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}
function mail_recipients(string $audience): array
{
    return all("SELECT id, name, email FROM users WHERE active = 1 AND email_notify = 1 AND password_hash <> '!'" . ($audience === 'owners' ? " AND kind = 'owner'" : '') . ' ORDER BY name');
}
/** Odešle jeden e-mail jako prostý text v UTF-8. Vrací, zda ho server přijal k odeslání. */
function send_mail(string $to, string $subject, string $text): bool
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
    $params = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from) ? '-f' . $from : '';
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"), $body, $headers, $params);
}
/** Text zprávy doplněný o patičku s informací, proč ji adresát dostal a jak odběr vypnout. */
function mail_text(string $body): string
{
    return trim(str_replace('**', '', $body)) . "\n\n-- \nZprávu posílá výbor SVJ Tábor 48 a–d obyvatelům domu s účtem na webu " . absolute_url('/')
        . "\nZasílání zpráv si můžete vypnout ve svém účtu: " . absolute_url('/muj-ucet') . "\n";
}

// ---------- Jednotky ----------

/** SQL výraz: označení jednotek uživatele (např. „48C/12“), pro tabulku s aliasem $alias. */
function units_sql(string $alias): string
{
    return "(SELECT group_concat('48' || un.entrance || '/' || un.number, ', ') FROM user_units uu JOIN units un ON un.id = uu.unit_id WHERE uu.user_id = $alias.id)";
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
    return all('SELECT * FROM units ORDER BY entrance, CAST(number AS INTEGER), number');
}

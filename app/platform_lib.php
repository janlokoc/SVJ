<?php
declare(strict_types=1);

// Platforma pro více SVJ: seznam společenství a účty hlavních správců jsou v samostatné databázi,
// data jednotlivých společenství leží odděleně ve storage/tenants/<adresa>/.

const RESERVED_SLUGS = ['www', 'admin', 'api', 'mail', 'smtp', 'ftp', 'static', 'assets', 'platforma', 'svj'];

function platform_domain(): string
{
    return strtolower((string) (app_config()['platform']['domain'] ?? ''));
}
function platform_enabled(): bool
{
    return platform_domain() !== '';
}
function platform_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $found = glob(ROOT_STORAGE . '/platform-*.sqlite');
    $file = $found ? $found[0] : ROOT_STORAGE . '/platform-' . bin2hex(random_bytes(12)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if (!$found) {
        $pdo->exec(<<<'SQL'
CREATE TABLE tenants (
  slug TEXT PRIMARY KEY,
  name TEXT NOT NULL, title TEXT NOT NULL, accent TEXT NOT NULL DEFAULT '', tagline TEXT NOT NULL DEFAULT '', "full" TEXT NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '', unit_prefix TEXT NOT NULL DEFAULT '', entrances TEXT NOT NULL DEFAULT 'A', look TEXT NOT NULL DEFAULT 'les', lang TEXT NOT NULL DEFAULT 'cs',
  custom_domain TEXT NOT NULL DEFAULT '', seed TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);
CREATE TABLE super_admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL, password_hash TEXT NOT NULL,
  totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, totp_last INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, last_login_at TEXT
);
CREATE TABLE platform_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, admin TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
CREATE TABLE attempts (ip TEXT NOT NULL, at INTEGER NOT NULL);
SQL);
    }
    // Pozdější rozšíření: předplatné, platby, nastavení služby a samoobslužná registrace.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
        $pdo->exec(<<<'SQL'
ALTER TABLE tenants ADD COLUMN billing INTEGER NOT NULL DEFAULT 0;
ALTER TABLE tenants ADD COLUMN trial_ends TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN paid_until TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN owner_name TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN owner_email TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN reminded TEXT NOT NULL DEFAULT '';
CREATE TABLE payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, vs TEXT NOT NULL DEFAULT '', amount INTEGER NOT NULL, months INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL, claimed_at TEXT, paid_at TEXT, period_from TEXT, period_to TEXT,
  confirmed_by TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT ''
);
CREATE INDEX payments_tenant ON payments (tenant);
CREATE TABLE platform_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE signups (ip TEXT NOT NULL, at INTEGER NOT NULL);
PRAGMA user_version = 1;
SQL);
    }
    return $pdo;
}
function pq(string $sql, array $params = []): PDOStatement
{
    $st = platform_db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function tenant_row(string $slug): ?array
{
    if (!platform_enabled()) {
        return null;
    }
    $r = pq('SELECT * FROM tenants WHERE slug = ?', [$slug])->fetch();
    return $r ?: null;
}
/** Najde společenství podle adresy v prohlížeči: adresa.domena-platformy, nebo vlastní doména společenství. */
function tenant_by_host(string $host): ?array
{
    $domain = platform_domain();
    if (str_ends_with($host, '.' . $domain)) {
        $slug = substr($host, 0, -strlen($domain) - 1);
        return preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $slug) ? tenant_row($slug) : null;
    }
    $r = pq("SELECT * FROM tenants WHERE custom_domain <> '' AND (custom_domain = ? OR 'www.' || custom_domain = ?)", [$host, $host])->fetch();
    return $r ?: null;
}
function tenant_url(array $t, string $path = '/'): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $port = preg_match('/:(\d+)$/', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m) ? ':' . $m[1] : '';
    return ($https ? 'https' : 'http') . '://' . ($t['custom_domain'] !== '' ? $t['custom_domain'] : $t['slug'] . '.' . platform_domain()) . $port . $path;
}
function tenant_storage(string $slug): string
{
    return ROOT_STORAGE . '/tenants/' . $slug;
}
/** Otevře databázi společenství z konzole platformy (založí ji, pokud ještě neexistuje). */
function tenant_pdo(string $slug): PDO
{
    static $open = [];
    return $open[$slug] ??= open_database(tenant_storage($slug), $slug);
}
function tenant_settings(string $slug): array
{
    return array_column(tenant_pdo($slug)->query('SELECT `key`, value FROM settings')->fetchAll(), 'value', 'key');
}
function tenant_set(string $slug, string $key, string $value): void
{
    $pdo = tenant_pdo($slug);
    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)')->execute([$key, $value]);
}
function tenant_stats(string $slug): array
{
    if (!is_dir(tenant_storage($slug))) {
        return ['ready' => false];
    }
    try {
        $pdo = tenant_pdo($slug);
        $one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
        return ['ready' => true, 'users' => (int) $one('SELECT COUNT(*) FROM users WHERE active = 1'), 'admins' => (int) $one("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"),
            'units' => (int) $one('SELECT COUNT(*) FROM units'), 'last_login' => (string) $one('SELECT MAX(last_login_at) FROM users'), 'documents' => (int) $one('SELECT COUNT(*) FROM documents')];
    } catch (Throwable $e) {
        return ['ready' => false, 'error' => true];
    }
}
function platform_log(string $action, string $detail = ''): void
{
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), $GLOBALS['SUPER']['email'] ?? '', client_ip(), $action, mb_substr($detail, 0, 500)]);
}
function platform_setup_code(): string
{
    $files = glob(ROOT_STORAGE . '/instalacni-kod-platformy-*.txt');
    if ($files) {
        return trim((string) file_get_contents($files[0]));
    }
    $code = strtoupper(bin2hex(random_bytes(6)));
    file_put_contents(ROOT_STORAGE . '/instalacni-kod-platformy-' . bin2hex(random_bytes(8)) . '.txt', $code . "\n");
    return $code;
}
/** Smaže složku společenství včetně obsahu (jen uvnitř storage/tenants). */
function tenant_delete_storage(string $slug): void
{
    $dir = realpath(tenant_storage($slug));
    $root = realpath(ROOT_STORAGE . '/tenants');
    if (!$dir || !$root || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}


// ---------- Nastavení služby, registrace a předplatné ----------

const PLATFORM_DEFAULTS = [
    'service_name' => 'Web pro SVJ', 'signup_open' => '1', 'trial_days' => '30', 'grace_days' => '14',
    'period' => 'month', 'price_first' => '999', 'price_next' => '1499',
    'account' => '', 'recipient' => '', 'contact_email' => '', 'mail_from' => '', 'operator' => '',
    'pricelist' => "Provoz webu a zálohy | v ceně\nPodpora e-mailem | v ceně\nPřevod obsahu ze stávajícího webu | 1 500 Kč jednorázově\nVlastní doména (např. www.svj-vasdum.cz) | 500 Kč / rok\nÚpravy webu na míru | 900 Kč / hod\nŠkolení výboru (online, 1 hodina) | 900 Kč",
];
const PAYMENT_STATUS = ['pending' => 'čeká na platbu', 'claimed' => 'nahlášeno jako zaplacené', 'paid' => 'zaplaceno', 'cancelled' => 'zrušeno'];

function pset(string $key): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = array_column(pq('SELECT key, value FROM platform_settings')->fetchAll(), 'value', 'key');
    }
    return $cache[$key] ?? (PLATFORM_DEFAULTS[$key] ?? '');
}
function pset_save(string $key, string $value): void
{
    pq('INSERT INTO platform_settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
    pset('__reset');
}
function pset_int(string $key, int $min, int $max): int
{
    $v = pset($key);
    return ctype_digit($v) ? max($min, min($max, (int) $v)) : (int) PLATFORM_DEFAULTS[$key];
}
function platform_url(string $path = '/'): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $port = preg_match('/:(\d+)$/', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m) ? ':' . $m[1] : '';
    return ($https ? 'https' : 'http') . '://' . platform_domain() . $port . $path;
}
/** E-mail jménem provozovatele služby (registrace, předplatné). Bez nastaveného odesílatele se neposílá nic. */
function platform_mail(string $to, string $subject, string $text): bool
{
    return send_mail($to, $subject, $text . "\n\n" . pset('service_name') . "\n" . platform_url() . "\n", null, pset('mail_from'), pset('service_name'));
}
function ascii_text(string $s): string
{
    static $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'ä' => 'a', 'ľ' => 'l', 'ĺ' => 'l', 'ô' => 'o', 'ŕ' => 'r', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', '–' => '-', '—' => '-'];
    return preg_replace('/[^\x20-\x7E]/', '', strtr(mb_strtolower($s), $map));
}
function slugify(string $s): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', ascii_text($s)), '-');
    $s = preg_replace('/^(svj|spolecenstvi-vlastniku(-jednotek)?)-(?=.{2})/', '', $s);
    return trim(substr($s, 0, 30), '-');
}
function slug_problem(string $slug): ?string
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $slug) || str_ends_with($slug, '-')) {
        return 'Adresa smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčku (2–39 znaků).';
    }
    if (in_array($slug, RESERVED_SLUGS, true) || in_array($slug, ['sprava', 'registrace', 'cenik', 'podpora', 'help', 'demo', 'test', 'app', 'blog', 'ns1', 'ns2', 'mx', 'pop', 'imap', 'webmail'], true)) {
        return 'Tato adresa je vyhrazená, zvolte prosím jinou.';
    }
    return tenant_row($slug) ? 'Tato adresa už je obsazená, zvolte prosím jinou.' : null;
}
function tenant_create(array $t): void
{
    $t += ['accent' => '', 'tagline' => 'Společenství vlastníků jednotek', 'full' => '', 'address' => '', 'unit_prefix' => '', 'entrances' => 'A', 'look' => 'les', 'lang' => 'cs', 'custom_domain' => '',
        'seed' => '', 'note' => '', 'active' => 1, 'billing' => 0, 'trial_ends' => '', 'paid_until' => '', 'owner_name' => '', 'owner_email' => ''];
    if ($t['full'] === '') {
        $t['full'] = $t['name'];
    }
    pq('INSERT INTO tenants (slug, name, title, accent, tagline, "full", address, unit_prefix, entrances, look, lang, custom_domain, seed, note, active, created_at, billing, trial_ends, paid_until, owner_name, owner_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$t['slug'], $t['name'], $t['title'], $t['accent'], $t['tagline'], $t['full'], $t['address'], $t['unit_prefix'], $t['entrances'], $t['look'], $t['lang'], $t['custom_domain'], $t['seed'], $t['note'], $t['active'],
            date('Y-m-d H:i:s'), $t['billing'], $t['trial_ends'], $t['paid_until'], $t['owner_name'], $t['owner_email']]);
    tenant_pdo($t['slug']);   // založí databázi a naplní ji výchozím obsahem
}

/**
 * Stav předplatného: free (bez placení), trial (zkušební doba), paid, grace (po splatnosti, web ještě běží), locked (web pozastaven).
 * 'until' je poslední den, do kdy je služba kryta, 'days' počet dní do tohoto dne (záporné = po splatnosti).
 */
function billing_state(?array $t): array
{
    if (!$t || empty($t['billing'])) {
        return ['mode' => 'free', 'until' => '', 'days' => 0];
    }
    $today = date('Y-m-d');
    $paid = (string) $t['paid_until'];
    $until = max($paid, (string) $t['trial_ends']);
    if ($until === '') {
        $until = substr((string) $t['created_at'], 0, 10);
    }
    $days = (int) round((strtotime($until) - strtotime($today)) / 86400);
    if ($days >= 0) {
        return ['mode' => $paid !== '' ? 'paid' : 'trial', 'until' => $until, 'days' => $days];
    }
    return ['mode' => -$days <= pset_int('grace_days', 0, 365) ? 'grace' : 'locked', 'until' => $until, 'days' => $days];
}
function billing_months(): int
{
    return pset('period') === 'year' ? 12 : 1;
}
function period_label(): string
{
    return pset('period') === 'year' ? 'rok' : 'měsíc';
}
/** Cena dalšího období: první rok po zkušební době platí zaváděcí cena, potom běžná. */
function billing_price(array $t): int
{
    $start = max(billing_state($t)['until'], date('Y-m-d'));
    $base = $t['trial_ends'] !== '' ? $t['trial_ends'] : substr((string) $t['created_at'], 0, 10);
    return $start < date('Y-m-d', strtotime($base . ' +1 year')) ? pset_int('price_first', 0, 1000000) : pset_int('price_next', 0, 1000000);
}
/** Výzva k platbě za další období (jedna otevřená na společenství); variabilní symbol páruje platbu na účtu. */
function billing_pending(array $t): array
{
    $amount = billing_price($t);
    $months = billing_months();
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    if ($p && $p['status'] === 'pending' && ((int) $p['amount'] !== $amount || (int) $p['months'] !== $months)) {
        pq('UPDATE payments SET amount = ?, months = ? WHERE id = ?', [$amount, $months, $p['id']]);
        $p = ['amount' => $amount, 'months' => $months] + $p;
    }
    if (!$p) {
        pq('INSERT INTO payments (tenant, amount, months, created_at) VALUES (?,?,?,?)', [$t['slug'], $amount, $months, date('Y-m-d H:i:s')]);
        $id = (int) platform_db()->lastInsertId();
        pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
        $p = pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
    }
    return $p;
}
/** Potvrzení přijaté platby: prodlouží předplatné o zaplacené období (od konce dosavadního, po splatnosti ode dneška). */
function billing_confirm(array $p, string $by): ?array
{
    $t = tenant_row($p['tenant']);
    if (!$t || !in_array($p['status'], ['pending', 'claimed'], true)) {
        return null;
    }
    $from = max(date('Y-m-d', strtotime(billing_state($t)['until'] . ' +1 day')), date('Y-m-d'));
    $to = date('Y-m-d', strtotime($from . ' +' . (int) $p['months'] . ' month -1 day'));
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ? WHERE id = ?", [date('Y-m-d H:i:s'), $from, $to, $by, $p['id']]);
    pq("UPDATE tenants SET paid_until = ?, billing = 1, reminded = '' WHERE slug = ?", [$to, $t['slug']]);
    if ($t['owner_email'] !== '') {
        platform_mail($t['owner_email'], 'Platba přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . money_czk((int) $p['amount']) . " (variabilní symbol " . $p['vs'] . ").\nPředplatné webu " . $t['name']
            . " je uhrazeno do " . cz_date($to) . ".\n\nDěkujeme.");
    }
    return ['from' => $from, 'to' => $to];
}
function money_czk(int $amount): string
{
    return number_format($amount, 0, ',', "\u{a0}") . "\u{a0}Kč";
}
/** České číslo účtu („19-2000145399/0800“) nebo IBAN převede na IBAN; neplatný zápis dá null. */
function cz_iban(string $account): ?string
{
    $a = strtoupper(preg_replace('/\s+/', '', $account));
    $mod97 = function (string $digits): int {
        $r = 0;
        foreach (str_split($digits) as $d) {
            $r = ($r * 10 + (int) $d) % 97;
        }
        return $r;
    };
    if (preg_match('/^CZ(\d{2})(\d{20})$/', $a, $m)) {
        return $mod97($m[2] . '1235' . $m[1]) === 1 ? $a : null;
    }
    if (!preg_match('~^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$~', $a, $m)) {
        return null;
    }
    $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
    foreach ([str_pad($m[1], 10, '0', STR_PAD_LEFT), str_pad($m[2], 10, '0', STR_PAD_LEFT)] as $part) {
        $sum = 0;
        foreach (str_split($part) as $i => $d) {
            $sum += (int) $d * $weights[$i];
        }
        if ($sum % 11 !== 0) {
            return null;
        }
    }
    $bban = $m[3] . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
    return 'CZ' . sprintf('%02d', 98 - $mod97($bban . '123500')) . $bban;
}
/** Text QR platby (český standard „QR Platba“, formát SPAYD), který přečte bankovní aplikace. */
function spayd(string $iban, int $amount, string $vs, string $message, string $recipient = ''): string
{
    $clean = fn(string $s, int $max) => substr(trim(str_replace('*', ' ', strtoupper(ascii_text($s)))), 0, $max);
    return 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format($amount, 2, '.', '') . '*CC:CZK*X-VS:' . $vs . '*MSG:' . $clean($message, 60) . ($recipient !== '' ? '*RN:' . $clean($recipient, 35) : '');
}
/** Jednou denně: připomene blížící se konec zkušební doby nebo předplatného a upozorní na pozastavení. */
function platform_daily(): void
{
    if (pset('last_daily') === date('Y-m-d')) {
        return;
    }
    pset_save('last_daily', date('Y-m-d'));
    foreach (pq("SELECT * FROM tenants WHERE billing = 1 AND active = 1 AND owner_email <> ''")->fetchAll() as $t) {
        $s = billing_state($t);
        $key = match (true) {
            $s['mode'] === 'locked' => 'locked:' . $s['until'],
            $s['mode'] === 'grace' => 'due:' . $s['until'],
            $s['days'] <= 7 => 'soon:' . $s['until'],
            default => '',
        };
        if ($key === '' || $key === $t['reminded']) {
            continue;
        }
        $what = $s['mode'] === 'trial' || ($s['mode'] !== 'paid' && $t['paid_until'] === '') ? 'zkušební doba' : 'předplatné';
        $link = tenant_url($t, '/admin/predplatne');
        [$subject, $body] = match ($s['mode']) {
            'locked' => ['Web je pozastaven – ' . $t['name'], "web " . $t['name'] . " je pozastaven, protože $what skončila " . cz_date($s['until']) . " a platba nedorazila.\nData zůstávají uložena. Po úhradě web znovu zprovozníme."],
            'grace' => ['Skončila ' . $what . ' – ' . $t['name'], "$what webu " . $t['name'] . " skončila " . cz_date($s['until']) . ". Web zatím běží dál; bez úhrady bude za " . max(0, pset_int('grace_days', 0, 365) + $s['days']) . " dní pozastaven."],
            default => ['Blíží se konec – ' . $what . ' webu ' . $t['name'], "$what webu " . $t['name'] . " končí " . cz_date($s['until']) . "."],
        };
        platform_mail($t['owner_email'], $subject, "Dobrý den,\n\n" . $body . "\n\nPlatební údaje včetně QR kódu najdete po přihlášení v administraci: " . $link);
        pq('UPDATE tenants SET reminded = ? WHERE slug = ?', [$key, $t['slug']]);
    }
}

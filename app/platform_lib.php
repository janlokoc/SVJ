<?php
declare(strict_types=1);

// Platforma pro více SVJ: seznam společenství a účty hlavních správců jsou v samostatné databázi,
// data jednotlivých společenství leží odděleně ve storage/tenants/<adresa>/.

const RESERVED_SLUGS = ['www', 'admin', 'api', 'mail', 'smtp', 'ftp', 'static', 'assets', 'platforma', 'svj'];

/** Hlavní doména platformy podle nastavení (ta, kterou uvidí zákazníci). */
function platform_main_domain(): string
{
    return strtolower((string) (app_config()['platform']['domain'] ?? ''));
}
/** Požadavek přišel přes další adresu serveru (např. IP adresa počítače v domácí síti při zkoušení z telefonu). */
function platform_via_alias(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host !== '' && !empty(app_config()['platform']['tunnel']) && preg_match('/^[a-z0-9-]+\.trycloudflare\.com$/', $host)) {
        return true;   // dočasná adresa Cloudflare Tunnel (při každém spuštění jiná) – zapíná se v configu volbou 'tunnel' => true
    }
    return $host !== '' && in_array($host, array_map('strtolower', (array) (app_config()['platform']['aliases'] ?? [])), true);
}
/** Doména, přes kterou návštěvník právě přišel: hlavní, nebo další adresa serveru. Na ni míří vytvářené odkazy. */
function platform_domain(): string
{
    $domain = platform_main_domain();
    return $domain !== '' && platform_via_alias() ? strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) : $domain;
}
/** Hlavní varianta adres: weby jako složky (svj.cz/tabor) místo subdomén (tabor.svj.cz). */
function platform_main_paths(): bool
{
    // Weby společenství běží vždy na subdoménách (tabor.svj.cz). Dřívější volba 'mode' => 'path' se už nepoužívá;
    // složky zůstávají jen pro přístup přes další adresu serveru (IP adresu při zkoušení z telefonu).
    return false;
}
/**
 * Právě platná varianta adres. Přes další adresu serveru (IP adresu) subdomény neexistují,
 * proto se tam weby otevírají vždy jako složky – i když hlavní doména používá subdomény.
 */
function platform_paths(): bool
{
    return platform_main_paths() || platform_via_alias();
}
/** Adresa webu společenství bez https://, jak ji uvidí zákazníci na hlavní doméně. */
function tenant_address(string $slug): string
{
    return platform_main_paths() ? platform_main_domain() . '/' . $slug : $slug . '.' . platform_main_domain();
}
/** První části adresy, které na hlavní doméně patří platformě, a nemohou proto být adresou společenství. */
const PLATFORM_PATHS = ['ochrana-udaju', 'funkce', 'vzhled', 'pripravujeme', 'zajem', 'domena-povolena', 'sprava', 'registrace', 'podminky', 'zpracovani-udaju', 'hlaseni', 'assets', 'storage', 'app', 'tools', 'index', 'favicon', 'robots', 'sitemap', 'kontakt', 'cenik', 'api', 'static'];
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
    // Ukázkový web, souhlas s podmínkami a faktury.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 2) {
        $pdo->exec(<<<'SQL'
ALTER TABLE tenants ADD COLUMN demo INTEGER NOT NULL DEFAULT 0;
ALTER TABLE tenants ADD COLUMN terms_at TEXT NOT NULL DEFAULT '';
ALTER TABLE payments ADD COLUMN invoice_no TEXT NOT NULL DEFAULT '';
ALTER TABLE payments ADD COLUMN invoice_data TEXT NOT NULL DEFAULT '';
PRAGMA user_version = 2;
SQL);
    }
    // Evidence odeslaných upozornění (konec zkušební doby, platby, chystané smazání).
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 3) {
        $pdo->exec(<<<'SQL'
CREATE TABLE tenant_notices (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, tenant_name TEXT NOT NULL DEFAULT '', at TEXT NOT NULL, kind TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', sent INTEGER NOT NULL DEFAULT 0, subject TEXT NOT NULL DEFAULT '');
CREATE INDEX tenant_notices_tenant ON tenant_notices (tenant);
PRAGMA user_version = 3;
SQL);
    }
    // Šablona vzhledu a vlastní doména jako placený doplněk.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 4) {
        $pdo->exec(<<<'SQL'
ALTER TABLE tenants ADD COLUMN style TEXT NOT NULL DEFAULT 'klasik';
ALTER TABLE tenants ADD COLUMN domain_request TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN domain_status TEXT NOT NULL DEFAULT '';
ALTER TABLE tenants ADD COLUMN domain_until TEXT NOT NULL DEFAULT '';
ALTER TABLE payments ADD COLUMN kind TEXT NOT NULL DEFAULT 'service';
ALTER TABLE payments ADD COLUMN item TEXT NOT NULL DEFAULT '';
PRAGMA user_version = 4;
SQL);
    }
    // Placený doplněk AI Plus.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 5) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN ai_until TEXT NOT NULL DEFAULT ''; PRAGMA user_version = 5;");
    }
    // Zájem o připravované funkce.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 6) {
        $pdo->exec("CREATE TABLE feature_interest (id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT NOT NULL, tenant TEXT NOT NULL DEFAULT '', contact TEXT NOT NULL DEFAULT '', at TEXT NOT NULL, ip TEXT NOT NULL DEFAULT ''); PRAGMA user_version = 6;");
    }
    // Prokliky na detail připravovaných funkcí.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 7) {
        $pdo->exec("CREATE TABLE feature_views (id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT NOT NULL, tenant TEXT NOT NULL DEFAULT '', visitor TEXT NOT NULL, day TEXT NOT NULL, at TEXT NOT NULL);
            CREATE INDEX feature_views_f ON feature_views (feature, day); PRAGMA user_version = 7;");
    }
    // Edice a zkoušení placených funkcí.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 8) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN plan TEXT NOT NULL DEFAULT '';
            CREATE TABLE feature_trials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, feature TEXT NOT NULL, started TEXT NOT NULL, ends TEXT NOT NULL, warned TEXT NOT NULL DEFAULT '', UNIQUE (tenant, feature));
            PRAGMA user_version = 8;");
    }
    // Objednávky jednorázového převodu obsahu ze starého webu.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 9) {
        $pdo->exec("CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, url TEXT NOT NULL, scope TEXT NOT NULL DEFAULT '', access TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
            contact_name TEXT NOT NULL DEFAULT '', contact_email TEXT NOT NULL DEFAULT '', contact_phone TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'ordered', payment_id INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, done_at TEXT NOT NULL DEFAULT '', done_note TEXT NOT NULL DEFAULT ''); PRAGMA user_version = 9;");
    }
    // Měsíční platba a smazání webu na žádost jeho správce.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 10) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN period TEXT NOT NULL DEFAULT 'year';
            ALTER TABLE tenants ADD COLUMN delete_at TEXT NOT NULL DEFAULT '';
            ALTER TABLE tenants ADD COLUMN delete_by TEXT NOT NULL DEFAULT '';
            PRAGMA user_version = 10;");
    }
    // Automatické prodlužování předplatného (lze vypnout) a den poslední obnovy ukázkového webu.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 11) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN auto_renew INTEGER NOT NULL DEFAULT 1; PRAGMA user_version = 11;");
    }
    // Úložiště navíc jako placený doplněk.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 12) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN storage_extra INTEGER NOT NULL DEFAULT 0; ALTER TABLE tenants ADD COLUMN storage_until TEXT NOT NULL DEFAULT ''; PRAGMA user_version = 12;");
    }
    // Doporučení: kód webu, kdo koho doporučil, kredit na další platbu a sleva uplatněná u platby.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 13) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN ref_code TEXT NOT NULL DEFAULT ''; ALTER TABLE tenants ADD COLUMN referred_by TEXT NOT NULL DEFAULT ''; ALTER TABLE tenants ADD COLUMN credit INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE payments ADD COLUMN discount INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE referrals (id INTEGER PRIMARY KEY AUTOINCREMENT, referrer TEXT NOT NULL, referred TEXT NOT NULL UNIQUE, referred_name TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, rewarded_at TEXT NOT NULL DEFAULT '', amount INTEGER NOT NULL DEFAULT 0);
            PRAGMA user_version = 13;");
    }
    // Počet návštěv přes odkaz s doporučením.
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 14) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN ref_clicks INTEGER NOT NULL DEFAULT 0; PRAGMA user_version = 14;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 15) {
        // Ceny jsou bez DPH: u platby si pamatujeme sazbu, která se k ní připočítává (0 = neplátce).
        $pdo->exec("ALTER TABLE payments ADD COLUMN vat_rate INTEGER NOT NULL DEFAULT 0; PRAGMA user_version = 15;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 16) {
        // Měsíční placení je zrušené: nezaplacené měsíční výzvy se ruší, zaplacené období doběhne.
        $pdo->exec("UPDATE payments SET status = 'cancelled' WHERE kind = 'service' AND months = 1 AND status IN ('pending','claimed');
            UPDATE tenants SET period = 'year'; PRAGMA user_version = 16;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 17) {
        // Standard: 500 hromadných e-mailů měsíčně místo 1 000 – jen pokud provozovatel výchozí hodnotu neměnil.
        $pdo->exec("UPDATE platform_settings SET value = '500' WHERE key = 'lim_standard_mails' AND value = '1000'; PRAGMA user_version = 17;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 18) {
        // Nové ceny: Standard 2 990 Kč, Premium 5 990 Kč ročně – jen pokud provozovatel výchozí ceny neměnil.
        // Už vystavené výzvy k platbě a zaplacená období se nemění.
        $pdo->exec("UPDATE platform_settings SET value = '5990' WHERE key = 'price_premium' AND value = '2990';
            UPDATE platform_settings SET value = '2990' WHERE key = 'price_standard' AND value = '1490'; PRAGMA user_version = 18;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 19) {
        // Edice Zdarma: 5 MB úložiště, 20 účtů, 30 e-mailů měsíčně – jen pokud provozovatel výchozí hodnoty neměnil.
        // Kdo je nad limitem, o nic nepřijde; jen nepřidá nic dalšího, dokud neuvolní místo nebo nepřejde na vyšší edici.
        $pdo->exec("UPDATE platform_settings SET value = '5' WHERE key = 'lim_free_storage' AND value = '50';
            UPDATE platform_settings SET value = '20' WHERE key = 'lim_free_users' AND value IN ('30', '10');
            UPDATE platform_settings SET value = '30' WHERE key = 'lim_free_mails' AND value = '50'; PRAGMA user_version = 19;");
    }
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 20) {
        // Zdarma nakonec 20 účtů (malý dům); oprava pro platformy, kde už proběhla předchozí změna na 10.
        $pdo->exec("UPDATE platform_settings SET value = '20' WHERE key = 'lim_free_users' AND value = '10'; PRAGMA user_version = 20;");
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
    if ($t['custom_domain'] === '' && platform_paths()) {
        return ($https ? 'https' : 'http') . '://' . platform_domain() . $port . '/' . $t['slug'] . $path;
    }
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
/**
 * Report využití pro konzoli: limity edice a jejich naplnění, k tomu provoz webu (e-maily, AI, úložiště, aktivita).
 * Vrací ['limits' => [klíč => [název, použito, limit (0 = bez omezení), jednotka, procenta, stav]], 'facts' => [...], 'verdict' => [...]].
 */
function tenant_usage_report(array $t): array
{
    $slug = $t['slug'];
    if (!is_dir(tenant_storage($slug))) {
        return [];
    }
    try {
        $pdo = tenant_pdo($slug);
    } catch (Throwable $e) {
        return [];
    }
    $one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $bytes = ['documents' => 0, 'tickets' => 0, 'other' => 0];
    $docFiles = array_flip(array_filter(array_map('strval', $pdo->query('SELECT filename FROM documents WHERE filename IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN))));
    $ticketFiles = array_flip(array_map('strval', $pdo->query('SELECT filename FROM ticket_files')->fetchAll(PDO::FETCH_COLUMN)));
    $dir = tenant_storage($slug) . '/uploads';
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $bytes[isset($docFiles[$f->getFilename()]) ? 'documents' : (isset($ticketFiles[$f->getFilename()]) ? 'tickets' : 'other')] += $f->getSize();
            }
        }
    }
    $mb = fn(int $b) => $b > 0 && $b < 104858 ? 'méně než 0,1' : str_replace('.', ',', (string) round($b / 1048576, 1));
    $edition = tenant_edition($t);
    $month = date('Y-m-01 00:00:00');
    $used = [
        'admins' => (int) $one("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"),
        'polls' => (int) $one('SELECT COUNT(*) FROM polls WHERE opens_at >= ?', [date('Y-01-01')]),
        'users' => (int) $one('SELECT COUNT(*) FROM users WHERE active = 1'),
        'mails' => (int) $one('SELECT COALESCE(SUM(sent_count), 0) FROM mailings WHERE sent_at >= ?', [$month]),
        'ai' => (int) $one('SELECT value FROM settings WHERE `key` = ?', ['ai_used_' . date('Y-m')]),
        'storage' => (int) ceil(array_sum($bytes) / 1048576),
    ];
    $limits = [];
    foreach (PLAN_LIMITS as $k => [$label, $unit]) {
        $limit = pset_int('lim_' . $edition . '_' . $k, 0, 10000000) + ($k === 'storage' ? storage_extra_mb($t) : 0);
        $pct = $limit > 0 ? (int) round($used[$k] * 100 / $limit) : 0;
        $state = $limit === 0 ? 'free' : ($pct >= 100 ? 'full' : ($pct >= 80 ? 'near' : 'ok'));
        $limits[$k] = [$label, $used[$k], $limit, $unit, $pct, $state];
    }
    $prevMonth = date('Y-m', strtotime('first day of last month'));
    $mails12 = (int) $one('SELECT COALESCE(SUM(sent_count), 0) FROM mailings WHERE sent_at >= ?', [date('Y-m-d', strtotime('-12 months'))]);
    $opened = $one('SELECT ROUND(100.0 * SUM(opened_at IS NOT NULL) / NULLIF(COUNT(*), 0)) FROM mailing_recipients r JOIN mailings m ON m.id = r.mailing_id WHERE m.sent_at >= ?', [date('Y-m-d', strtotime('-12 months'))]);
    $active30 = (int) $one('SELECT COUNT(*) FROM users WHERE active = 1 AND last_login_at >= ?', [date('Y-m-d', strtotime('-30 days'))]);
    $lastLogin = (string) $one('SELECT MAX(last_login_at) FROM users');
    // Dokumenty převzaté ze starého webu bývají jen odkazem na soubor tam – na tomto serveru místo nezabírají.
    $external = external_doc_count($pdo);
    $stored = (int) $one('SELECT COUNT(*) FROM documents WHERE filename IS NOT NULL');
    $facts = [
        'Úložiště podle druhu' => 'dokumenty ' . $mb($bytes['documents']) . ' MB · přílohy závad ' . $mb($bytes['tickets']) . ' MB · ostatní (logo, náhledy) ' . $mb($bytes['other']) . ' MB',
        'Dokumenty' => $stored . ' uloženo na tomto serveru' . ($external ? ' · ' . $external . ' jen odkazem na soubor na jiném webu (nezabírá místo)' : ''),
        'Hromadné e-maily' => 'tento měsíc ' . $used['mails'] . ' · za 12 měsíců ' . $mails12 . ($opened !== null && $opened !== false ? ' · otevřeno ' . (int) $opened . ' %' : ''),
        'AI pomocník' => 'tento měsíc ' . $used['ai'] . '× · minulý měsíc ' . (int) $one('SELECT value FROM settings WHERE `key` = ?', ['ai_used_' . $prevMonth]) . '×' . (!empty($t['ai_until']) && $t['ai_until'] >= date('Y-m-d') ? ' · AI Plus do ' . cz_date($t['ai_until']) : ''),
        'Aktivita' => 'přihlášeno za 30 dní ' . $active30 . ' z ' . $used['users'] . ' účtů · poslední přihlášení ' . ($lastLogin !== '' ? cz_datetime($lastLogin) : 'nikdy'),
        'Obsah' => (int) $one('SELECT COUNT(*) FROM units') . ' jednotek · ' . (int) $one('SELECT COUNT(*) FROM documents') . ' dokumentů · ' . (int) $one('SELECT COUNT(*) FROM posts') . ' aktualit · ' . (int) $one("SELECT COUNT(*) FROM tickets WHERE status <> 'done'") . ' otevřených závad',
    ];
    // Vyhodnocení: co z čísel plyne pro provozovatele.
    $verdict = [];
    $full = array_filter($limits, fn($l) => $l[5] === 'full');
    $near = array_filter($limits, fn($l) => $l[5] === 'near');
    if ($full) {
        $verdict[] = ['bad', 'Vyčerpaný limit: ' . implode(', ', array_map(fn($l) => $l[0], $full)) . '.' . ($edition !== 'premium' ? ' Vhodné nabídnout vyšší edici' . (isset($full['storage']) ? ' nebo úložiště navíc' : '') . '.' : '')];
    }
    if ($near) {
        $verdict[] = ['warn', 'Blíží se limitu (80 % a víc): ' . implode(', ', array_map(fn($l) => $l[0] . ' ' . $l[4] . ' %', $near)) . '.'];
    }
    if ($external) {
        $verdict[] = ['bad', 'Převod není dokončený: ' . $external . ' dokumentů je jen odkazem na soubory na starém webu (proto se nepočítají do úložiště). Po zrušení starého webu přestanou fungovat – stáhněte je tlačítkem „Stáhnout soubory ze starého webu“.'];
    }
    if ($lastLogin === '' || $lastLogin < date('Y-m-d', strtotime('-60 days'))) {
        $verdict[] = ['warn', 'Na web se přes 60 dní nikdo nepřihlásil – web možná nepoužívají.'];
    } elseif ($used['users'] > 1 && $active30 * 100 < $used['users'] * 20) {
        $verdict[] = ['warn', 'Za posledních 30 dní se přihlásilo méně než 20 % obyvatel.'];
    }
    if (!$verdict) {
        $verdict[] = ['ok', 'Vše v limitech edice ' . EDITIONS[$edition] . '.'];
    }
    return ['edition' => $edition, 'limits' => $limits, 'facts' => $facts, 'verdict' => $verdict];
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
            'units' => (int) $one('SELECT COUNT(*) FROM units'), 'ai_used' => (int) $one("SELECT value FROM settings WHERE `key` = 'ai_used_" . date('Y-m') . "'"), 'last_login' => (string) $one('SELECT MAX(last_login_at) FROM users'), 'documents' => (int) $one('SELECT COUNT(*) FROM documents')];
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

const PLATFORM_PRIVACY = <<<'TXT'
## Kdo údaje zpracovává
Tyto zásady se týkají webu služby a jejích zákazníků. Správcem údajů je provozovatel služby uvedený v záhlaví této stránky.
U webů jednotlivých společenství je správcem údajů vždy dané společenství a provozovatel je jeho zpracovatelem; podmínky upravuje smlouva o zpracování osobních údajů.

## Jaké údaje ukládáme
- **Při založení webu:** název a adresu společenství, jméno a e-mail osoby, která web zakládá, čas souhlasu s podmínkami a IP adresu (kvůli ochraně před zneužitím registrace).
- **Při platbě:** údaje na zálohové faktuře a faktuře, variabilní symbol a to, kdo platbu potvrdil.
- **Když nám napíšete nebo nahlásíte problém:** text hlášení, adresu stránky, typ prohlížeče, případný snímek obrazovky a kontakt, který uvedete.
- **Zájem o připravované funkce:** e-mail, pokud ho zadáte, abychom Vám dali vědět. U prokliků na detail funkce jen anonymní denní otisk (nelze z něj zjistit, kdo jste).
- **Provozní záznamy:** přihlášení hlavních správců a úkony v konzoli, včetně vstupů podpory do webu společenství (kdo, kdy, za koho a proč).

## K čemu údaje slouží
K poskytování služby (plnění smlouvy), k vystavení dokladů (právní povinnost) a k ochraně a zlepšování služby (oprávněný zájem). Údaje nepoužíváme k reklamě a neprodáváme je. Přístup k nim mají jen naši zpracovatelé: poskytovatel serverů a služba pro odesílání e-mailů. Text vložený do pomocníka s umělou inteligencí se odesílá poskytovateli této služby (jeho servery mohou být mimo EU); pomocníka lze v nastavení webu vypnout.

## Jak dlouho
- Údaje webu společenství do jeho zrušení; nepoužívaný web v edici Zdarma mažeme po lhůtě uvedené v obchodních podmínkách, vždy s upozorněním předem. Web lze kdykoli zrušit v administraci.
- Faktury po dobu, kterou ukládají účetní a daňové předpisy.
- E-maily zájemců o připravované funkce do spuštění funkce, nebo dokud nepožádáte o výmaz.

## Cookies
Používáme jen nezbytné cookies: přihlášení, průběh registrace a zvolený světlý nebo tmavý vzhled. Žádné reklamní ani sledovací cookies třetích stran, proto nezobrazujeme cookie lištu.

## Vaše práva
Máte právo na přístup ke svým údajům, jejich opravu, výmaz, omezení zpracování, přenositelnost a právo vznést námitku. Napište nám na kontakt uvedený v záhlaví; odpovíme do jednoho měsíce. Stížnost můžete podat u Úřadu pro ochranu osobních údajů (www.uoou.cz).
Správce webu společenství si může v placené edici data svého webu stáhnout (Záloha) a celý web kdykoli zrušit (Předplatné → Zrušit web). Každý uživatel si své vlastní údaje stáhne v kterékoli edici.
TXT;
const PLATFORM_TERMS = <<<'TXT'

## 1. Úvodní ustanovení
Tyto podmínky upravují poskytování služby, která společenstvím vlastníků jednotek a bytovým družstvům (dále „zákazník“) umožňuje provozovat vlastní web s částí pro obyvatele domu a s administrací. Poskytovatelem je provozovatel uvedený v záhlaví této stránky.
Služba je určena právnickým osobám. Zákazník není spotřebitelem a ustanovení o ochraně spotřebitele (včetně práva odstoupit od smlouvy do 14 dnů) se na smlouvu nepoužijí.
Smlouva vzniká dokončením registrace, při které osoba jednající za zákazníka potvrdí souhlas s těmito podmínkami. Tato osoba prohlašuje, že je členem statutárního orgánu zákazníka nebo je jím k založení webu pověřena. Poskytovatel může požádat o doložení tohoto oprávnění.
Poskytovatel se zákazníkem komunikuje elektronicky – e-mailem na adresy správců webu a oznámeními v administraci.

## 2. Edice, doplňky a cena
Služba se poskytuje v edicích uvedených v ceníku na webu poskytovatele. Edice Zdarma je bezplatná a časově neomezená; má limity uvedené v ceníku a web v ní může obsahovat odkaz na službu.
Funkce vyšších edic si zákazník může jednou na dobu uvedenou v administraci bezplatně vyzkoušet. Zkoušením nevzniká povinnost placenou edici objednat.
Placená edice se objednává v administraci na jeden rok. K edici lze objednat doplňky na rok (například vlastní doménu, úložiště navíc nebo vyšší limit AI pomocníka) a jednorázové služby (například převod obsahu ze staršího webu). Rozsah a cenu každé z nich uvádí ceník a objednávka.
Ceny jsou uvedeny bez daně z přidané hodnoty; je-li poskytovatel jejím plátcem, připočte ji v zákonné výši. Platí se předem bankovním převodem na základě zálohové faktury (výzvy k platbě) se splatností na ní uvedenou. Po připsání platby vystaví poskytovatel fakturu – daňový doklad a zpřístupní ji v administraci. Placená edice nebo doplněk se zapne po připsání platby.
Kredit získaný za doporučení služby se odečte od ceny další roční edice. Nelze ho vyplatit v penězích ani převést na jiného zákazníka.

## 3. Prodloužení, konec placené edice a nepoužívané weby
Předplatné se prodlužuje automaticky: před koncem období poskytovatel vystaví zálohovou fakturu na další rok a zákazníka na to upozorní e-mailem. Nic se nestrhává automaticky. Automatické prodlužování lze kdykoli vypnout v administraci; neuhrazením další zálohové faktury zákazníkovi nevzniká dluh.
Není-li placená edice po skončení zaplaceného období prodloužena, běží ještě po lhůtu uvedenou v administraci a poté web přejde na edici Zdarma. Web se nepozastavuje a data se nemažou; funkce, které edice Zdarma neobsahuje, zůstávají jen pro čtení a nad její limity nelze přidávat nové záznamy.
Web v edici Zdarma, na který se po dobu uvedenou na webu poskytovatele nikdo nepřihlásil, může poskytovatel smazat včetně všech dat. Na chystané smazání předem upozorní e-mailem osobu, která web založila; přihlášení na web lhůtu ruší.
Ceny a limity může poskytovatel změnit s účinností od dalšího období; změnu oznámí nejméně 30 dní předem. Na již zaplacené období se změna ceny nevztahuje.

## 4. Práva a povinnosti zákazníka
Zákazník odpovídá za obsah, který na web vloží on nebo jeho uživatelé, a za to, že má k uveřejnění a ke zpracování údajů právní důvod.
Zákazník určuje, kdo je správcem jeho webu, a odpovídá za jejich jednání. Chrání přístupové údaje správců a bez zbytečného odkladu oznámí poskytovateli podezření na jejich zneužití. Udržuje aktuální kontaktní e-mail a fakturační údaje.
Službu není dovoleno používat k rozesílání nevyžádaných nebo obchodních sdělení, k šíření protiprávního obsahu, k účelům nesouvisejícím se správou domu ani způsobem, který ohrožuje její provoz nebo jiné zákazníky.
Obsah, který je zjevně protiprávní, může poskytovatel znepřístupnit; zákazníka o tom bez odkladu informuje. Poskytovatel nemá povinnost obsah vkládaný zákazníkem sledovat.

## 5. Práva a povinnosti poskytovatele
Poskytovatel zajišťuje provoz služby, její zabezpečení a pravidelné zálohování. Usiluje o nepřetržitou dostupnost, konkrétní míru dostupnosti však nezaručuje. Plánovanou údržbu provádí pokud možno v noci a delší odstávku oznamuje předem.
Zálohy slouží k obnově služby po havárii. Zákazníkovi v placené edici poskytovatel umožňuje stáhnout si vlastní úplnou zálohu dat a doporučuje to dělat pravidelně.
Poskytovatel do obsahu webu zákazníka nenahlíží, ledaže o to zákazník požádá při řešení podpory nebo to vyžaduje odstranění závady či plnění právní povinnosti. Dělá to v režimu podpory: do webu vstoupí za některého z jeho uživatelů **pouze pro čtení** – nemůže v něm nic změnit, odeslat ani smazat a soukromé zprávy mezi uživateli nevidí. Každý takový vstup se s důvodem a časem zapíše do záznamů webu, kde ho správce společenství vidí.
Na žádost uživatele může poskytovatel poslat odkaz pro nastavení nového hesla nebo vypnout dvoufázové ověření; hesla uživatelů nezná a nemůže je zobrazit.
Poskytovatel může službu měnit a rozšiřovat. Podstatné omezení funkcí zaplacené edice oznámí nejméně 30 dní předem.
Funkce označené jako připravované jsou jen informací o záměru; poskytovatel se nezavazuje je zavést.

## 6. Pomocník s umělou inteligencí
Některé funkce (návrhy textů, zápis ze shromáždění z poznámek nebo přepisu) využívají službu umělé inteligence třetí strany. Text, který do nich uživatel vloží, se k vytvoření návrhu odešle tomuto poskytovateli; jeho servery mohou být mimo Evropskou unii. Funkci lze v nastavení webu vypnout.
Výstup je návrh, který musí člověk před použitím zkontrolovat. Poskytovatel neodpovídá za jeho věcnou ani právní správnost.

## 7. Reklamace
Vadu služby zákazník oznámí bez zbytečného odkladu formulářem „Podpora webu“ nebo e-mailem na kontaktní adresu poskytovatele a popíše, v čem spočívá. Poskytovatel se k reklamaci vyjádří do 10 pracovních dnů.
Je-li reklamace oprávněná a vada podstatně omezila užívání placené edice, má zákazník právo na přiměřenou slevu, případně na prodloužení zaplaceného období o dobu, po kterou vada trvala.

## 8. Odpovědnost
Služba je poskytována v podobě, v jaké je popsána na webu poskytovatele. Poskytovatel neodpovídá za škodu způsobenou obsahem vloženým zákazníkem, nesprávným použitím služby, vyzrazením přístupových údajů na straně zákazníka ani výpadky mimo jeho vliv (připojení k internetu, doručování e-mailů, služby třetích stran, vyšší moc).
Služba není právní, účetní ani daňovou radou. Vzory dokumentů a výpočty (například usnášeníschopnost nebo výsledky hlasování) jsou pomůckou; za jejich použití v souladu se stanovami a zákonem odpovídá zákazník.
Odpovědnost poskytovatele za škodu je omezena částkou, kterou zákazník za službu zaplatil za posledních 12 měsíců. Omezení se neuplatní u škody způsobené úmyslně nebo z hrubé nedbalosti ani tam, kde to zákon nepřipouští.

## 9. Ukončení
Zákazník může službu kdykoli přestat používat; placená edice skončí uplynutím zaplaceného období, není-li uhrazeno další. Zaplacená cena se při ukončení ze strany zákazníka nevrací.
Správce zákazníka může v administraci požádat o zrušení celého webu. Web se po ochranné lhůtě uvedené v administraci smaže včetně všech dat; do té doby lze žádost vzít zpět. Před zrušením si zákazník v placené edici může stáhnout úplnou zálohu dat.
Poskytovatel může smlouvu vypovědět, porušuje-li zákazník tyto podmínky závažně nebo opakovaně a nezjedná nápravu ani po upozornění. Poměrnou část předplatného za nevyužité období v takovém případě nevrací jen tehdy, bylo-li porušení úmyslné.
Ukončí-li poskytovatel provoz služby, oznámí to nejméně 3 měsíce předem, umožní zákazníkům stáhnout jejich data a vrátí poměrnou část předplatného za nevyužité období.

## 10. Osobní údaje
Při provozu webu zpracovává poskytovatel osobní údaje obyvatel domu jako zpracovatel pro zákazníka, který je jejich správcem. Podmínky upravuje Smlouva o zpracování osobních údajů, která je součástí těchto podmínek.
Údaje o zákazníkovi a osobách, které za něj jednají (registrace, fakturace, podpora), zpracovává poskytovatel jako správce podle Zásad ochrany osobních údajů zveřejněných na webu služby.

## 11. Závěrečná ustanovení
Právní vztahy se řídí českým právem, zejména občanským zákoníkem. Spory rozhodují české soudy příslušné podle sídla poskytovatele.
Poskytovatel může podmínky změnit; změnu oznámí správcům zákazníka nejméně 30 dní předem. Zákazník ji může odmítnout ukončením služby ke dni účinnosti změny; poskytovatel mu pak vrátí poměrnou část předplatného za nevyužité období.
Je-li některé ustanovení neplatné, ostatní zůstávají v platnosti.
TXT;
const PLATFORM_DPA = <<<'TXT'
## 1. Strany a předmět
Správcem osobních údajů je společenství vlastníků nebo družstvo, které si založilo web (dále „správce“). Zpracovatelem je provozovatel služby uvedený v záhlaví této stránky (dále „zpracovatel“).
Tato smlouva podle čl. 28 nařízení (EU) 2016/679 (GDPR) upravuje zpracování osobních údajů, které správce nebo jeho uživatelé do služby vloží. Je uzavřena elektronicky přijetím obchodních podmínek a trvá po dobu poskytování služby.

## 2. Rozsah zpracování
**Účel:** provoz webu společenství, komunikace s vlastníky a obyvateli, organizace shromáždění a hlasování, evidence jednotek a správa domu.
**Subjekty údajů:** vlastníci jednotek, členové jejich domácností, nájemníci, členové výboru a kontrolní komise a kontaktní osoby dodavatelů.
**Kategorie údajů:** jméno a příjmení, e-mail, telefon, adresa, označení jednotky a spoluvlastnický podíl, funkce v orgánech společenství, údaje o účasti a hlasování, předepsané zálohy a stav plateb na jednotku včetně variabilního symbolu, odečty měřidel, evidence klíčů a rezervací, obsah vložených zpráv, hlášení, příloh a dokumentů, technické údaje o přihlášení (čas, IP adresa).
**Zvláštní kategorie údajů** (například údaje o zdravotním stavu) služba nevyžaduje a správce je do ní nevkládá.
Zpracovatel údaje nepoužívá k vlastním účelům, k marketingu ani k profilování.
**Doba:** po dobu trvání smlouvy o poskytování služby a nejdéle 6 měsíců po jejím skončení.

## 3. Povinnosti zpracovatele
Zpracovatel zpracovává údaje jen na základě doložených pokynů správce, kterými jsou tato smlouva a nastavení provedená správcem ve službě.
Zajišťuje, že osoby s přístupem k údajům jsou vázány mlčenlivostí.
Do webu správce vstupuje jen při řešení podpory, odstraňování závad nebo plnění právní povinnosti, a to v režimu pouze pro čtení; každý vstup se zapisuje do záznamů webu, které má správce k dispozici.
Přijal technická a organizační opatření: šifrovaný přenos, hesla ukládaná jen jako otisk, možnost dvoufázového ověření, oddělená databáze každého společenství, řízení přístupů, neměnné záznamy o činnosti správců a pravidelné zálohy.
Je správci nápomocen při vyřizování žádostí subjektů údajů (služba umožňuje výpis i výmaz údajů uživatele) a při plnění povinností podle čl. 32–36 GDPR.
Porušení zabezpečení osobních údajů oznámí správci bez zbytečného odkladu, nejpozději do 48 hodin poté, co se o něm dozví, a sdělí mu, co je mu o rozsahu a následcích známo.
Na žádost poskytne správci informace potřebné k doložení splnění povinností a umožní audit v přiměřeném rozsahu, nejvýše jednou ročně a po předchozí domluvě.

## 4. Další zpracovatelé
Správce souhlasí se zapojením dalších zpracovatelů v rozsahu nezbytném pro provoz služby: poskytovatele serverů a služby pro odesílání e-mailů. Zpracovatel je zaváže ke stejné ochraně údajů. O zamýšlené změně informuje správce nejméně 30 dní předem; správce může vznést námitku a při nesouhlasu službu ukončit.
Údaje jsou uloženy na serverech v Evropské unii.
Použije-li uživatel pomocníka s umělou inteligencí, odešle se text, který do něj vloží, poskytovateli této služby, jehož servery mohou být mimo Evropskou unii; předání se řídí standardními smluvními doložkami nebo jiným nástrojem podle kapitoly V GDPR. Správce může tuto funkci v nastavení webu vypnout a odpovídá za to, jaké údaje do ní jeho uživatelé vkládají.

## 5. Skončení zpracování
Po skončení služby zpracovatel umožní správci stáhnout zálohu dat a poté údaje smaže, nejpozději do 6 měsíců, ledaže právní předpis ukládá jejich další uchování. Ze záloh údaje zmizí s jejich pravidelnou obměnou.
TXT;
const PLATFORM_DEFAULTS = [
    'service_name' => 'Web pro SVJ', 'signup_open' => '1', 'demo_auto' => '1', 'delete_days' => '365', 'price_domain' => '999', 'domain_dns' => '', 'ai_model' => '', 'hosting_note' => '', 'grace_days' => '14',
    'period' => 'year', 'referral_reward' => '300', 'referral_welcome' => '150', 'storage_pack_mb' => '500', 'price_storage' => '290', 'demo_refresh_days' => '7', 'price_migration' => '1500', 'price_standard' => '2990', 'price_premium' => '5990',
    'lim_free_admins' => '1', 'lim_free_users' => '20', 'lim_free_mails' => '30', 'lim_free_storage' => '5', 'lim_free_ai' => '5', 'lim_free_polls' => '1',
    'lim_standard_admins' => '3', 'lim_standard_users' => '150', 'lim_standard_mails' => '500', 'lim_standard_storage' => '100', 'lim_standard_ai' => '30', 'lim_standard_polls' => '5',
    'lim_premium_admins' => '0', 'lim_premium_users' => '0', 'lim_premium_mails' => '0', 'lim_premium_storage' => '500', 'lim_premium_ai' => '300', 'lim_premium_polls' => '0',
    'account' => '', 'recipient' => '', 'contact_email' => '', 'mail_from' => '',
    'op_name' => '', 'op_ico' => '', 'op_dic' => '', 'op_address' => '', 'op_register' => '', 'op_phone' => '',
    'invoice_note' => 'Dodavatel není plátcem DPH.', 'invoice_due_days' => '14', 'vat_payer' => '0', 'vat_rate' => '21', 'legal_checked' => '0',
    'terms' => PLATFORM_TERMS, 'dpa' => PLATFORM_DPA,
    'pricelist' => "Provoz webu a zálohy | v ceně\nPodpora e-mailem | v ceně\nVlastní doména (např. www.svj-vasdum.cz) | 999 Kč / rok\nÚpravy webu na míru | 900 Kč / hod\nŠkolení výboru (online, 1 hodina) | 900 Kč",
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
    if (in_array($slug, RESERVED_SLUGS, true) || in_array($slug, PLATFORM_PATHS, true) || in_array($slug, ['sprava', 'registrace', 'cenik', 'podpora', 'help', 'demo', 'test', 'app', 'blog', 'ns1', 'ns2', 'mx', 'pop', 'imap', 'webmail'], true)) {
        return 'Tato adresa je vyhrazená, zvolte prosím jinou.';
    }
    return tenant_row($slug) ? 'Tato adresa už je obsazená, zvolte prosím jinou.' : null;
}
function tenant_create(array $t): void
{
    $t += ['accent' => '', 'tagline' => 'Společenství vlastníků jednotek', 'full' => '', 'address' => '', 'unit_prefix' => '', 'entrances' => 'A', 'look' => 'les', 'lang' => 'cs', 'custom_domain' => '',
        'seed' => '', 'note' => '', 'active' => 1, 'billing' => 0, 'trial_ends' => '', 'paid_until' => '', 'owner_name' => '', 'owner_email' => '', 'demo' => 0, 'terms_at' => '', 'style' => 'klasik'];
    if ($t['full'] === '') {
        $t['full'] = $t['name'];
    }
    pq('INSERT INTO tenants (slug, name, title, accent, tagline, "full", address, unit_prefix, entrances, look, lang, custom_domain, seed, note, active, created_at, billing, trial_ends, paid_until, owner_name, owner_email, demo, terms_at, style) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$t['slug'], $t['name'], $t['title'], $t['accent'], $t['tagline'], $t['full'], $t['address'], $t['unit_prefix'], $t['entrances'], $t['look'], $t['lang'], $t['custom_domain'], $t['seed'], $t['note'], $t['active'],
            date('Y-m-d H:i:s'), $t['billing'], $t['trial_ends'], $t['paid_until'], $t['owner_name'], $t['owner_email'], $t['demo'], $t['terms_at'], $t['style']]);
    tenant_pdo($t['slug']);   // založí databázi a naplní ji výchozím obsahem
}

/**
 * Stav předplatného: free (bez placení, domluveno s provozovatelem), basic (edice Zdarma), trial (dříve založený web ve zkušební době),
 * paid, grace (po splatnosti – placená edice ještě běží). Web se nikdy nepozastavuje: po lhůtě po splatnosti přejde na edici Zdarma.
 * 'until' je poslední den, do kdy je placená edice kryta, 'days' počet dní do tohoto dne (záporné = po splatnosti).
 */
function billing_state(?array $t): array
{
    if (!$t || empty($t['billing'])) {
        // Ukázka: edice podle přepínače v ukázce.
        return ['mode' => 'free', 'until' => '', 'days' => 0, 'edition' => !empty($t['demo']) ? tenant_edition($t) : 'premium'];
    }
    $today = date('Y-m-d');
    $paid = (string) $t['paid_until'];
    $until = max($paid, (string) $t['trial_ends']);
    $edition = tenant_edition($t);
    if ($until === '') {
        return ['mode' => 'basic', 'until' => '', 'days' => 0, 'edition' => 'free'];
    }
    $days = (int) round((strtotime($until) - strtotime($today)) / 86400);
    if ($days >= 0) {
        return ['mode' => $paid !== '' && $paid >= $today ? 'paid' : 'trial', 'until' => $until, 'days' => $days, 'edition' => $edition];
    }
    return ['mode' => $edition !== 'free' ? 'grace' : 'basic', 'until' => $until, 'days' => $days, 'edition' => $edition];
}
function billing_months(): int
{
    return 12;
}
function period_label(): string
{
    return 'rok';
}
/**
 * Hlavní správce nastaví předplatné ručně (dar, sleva, platba mimo systém, oprava): edice a den, do kdy platí.
 * 'free' předplatné ukončí (web přejde na edici Zdarma), 'unlimited' vypne placení úplně.
 */
function billing_set(array $t, string $edition, string $until): bool
{
    if ($edition === 'unlimited') {
        pq("UPDATE tenants SET billing = 0, reminded = '' WHERE slug = ?", [$t['slug']]);
        return true;
    }
    if ($edition === 'free') {
        pq("UPDATE tenants SET billing = 1, paid_until = '', trial_ends = '', plan = '', reminded = '' WHERE slug = ?", [$t['slug']]);
        return true;
    }
    if (!in_array($edition, ['standard', 'premium'], true) || !valid_date($until)) {
        return false;
    }
    pq("UPDATE tenants SET billing = 1, paid_until = ?, plan = ?, reminded = '' WHERE slug = ?", [$until, $edition, $t['slug']]);
    return true;
}
/** Hlavní správce zapne nebo prodlouží zkoušku funkce (i opakovaně), případně ji ukončí. */
function trial_set(string $slug, string $feature, string $ends): void
{
    if (!isset(PLAN_FEATURES[$feature])) {
        return;
    }
    if ($ends === '') {
        pq('UPDATE feature_trials SET ends = ? WHERE tenant = ? AND feature = ?', [date('Y-m-d', strtotime('-1 day')), $slug, $feature]);
        return;
    }
    if (pq('SELECT 1 FROM feature_trials WHERE tenant = ? AND feature = ?', [$slug, $feature])->fetchColumn()) {
        pq("UPDATE feature_trials SET ends = ?, warned = '' WHERE tenant = ? AND feature = ?", [$ends, $slug, $feature]);
    } else {
        pq('INSERT INTO feature_trials (tenant, feature, started, ends) VALUES (?,?,?,?)', [$slug, $feature, date('Y-m-d'), $ends]);
    }
}
/** Otevřená výzva k platbě za edici (nejvýše jedna na společenství), nebo null. */
function billing_open(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'service' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
/**
 * Kolik Kč zbývá z už zaplacené nižší edice: při přechodu na vyšší se nevyčerpaná část odečte od ceny.
 * Počítá se z poslední přijaté platby za edici a ze zbývajících dnů zaplaceného období.
 */
function billing_credit(array $t, string $edition): int
{
    $now = tenant_edition($t);
    if ($t['paid_until'] === '' || $t['paid_until'] < date('Y-m-d') || !isset(EDITION_RANK[$edition]) || EDITION_RANK[$edition] <= EDITION_RANK[$now]) {
        return 0;
    }
    $p = pq("SELECT amount, period_from, period_to FROM payments WHERE tenant = ? AND kind = 'service' AND status = 'paid' AND period_to = ? ORDER BY id DESC LIMIT 1", [$t['slug'], $t['paid_until']])->fetch();
    if (!$p || $p['period_from'] === '') {
        return 0;   // období nastavil ručně hlavní správce – není z čeho počítat
    }
    $total = max(1, (int) round((strtotime($p['period_to']) - strtotime($p['period_from'])) / 86400) + 1);
    $left = max(0, (int) round((strtotime($p['period_to']) - strtotime(date('Y-m-d'))) / 86400));
    return (int) floor((int) $p['amount'] * min($left, $total) / $total);
}
/** Proč teď edici objednat nejde (nebo null): na nižší edici se přechází až po skončení zaplaceného období. */
function billing_order_problem(array $t, string $edition): ?string
{
    $now = tenant_edition($t);
    if (isset(EDITION_RANK[$edition]) && $t['paid_until'] >= date('Y-m-d') && EDITION_RANK[$edition] < EDITION_RANK[$now]) {
        return 'Edici ' . EDITIONS[$now] . ' máte zaplacenou do ' . cz_date($t['paid_until']) . '. Na edici ' . EDITIONS[$edition] . ' můžete přejít po tomto datu – o zaplacené období tak nepřijdete.';
    }
    return null;
}
/** Výzva k platbě za edici na rok; variabilní symbol páruje platbu na účtu. Nahlášenou platbu už nemění. */
function billing_order(array $t, string $edition): ?array
{
    $months = 12;
    if (!isset(EDITIONS[$edition]) || $edition === 'free') {
        return null;
    }
    if (billing_order_problem($t, $edition)) {
        return null;
    }
    $amount = max(0, edition_price($edition) - billing_credit($t, $edition));
    // Kredit za doporučení se strhne až při potvrzení platby.
    $discount = min($amount, max(0, (int) ($t['credit'] ?? 0)));
    $amount -= $discount;
    $p = billing_open($t);
    if ($p && $p['status'] === 'claimed') {
        return $p;
    }
    if ($p) {
        pq('UPDATE payments SET amount = ?, months = ?, item = ?, discount = ? WHERE id = ?', [$amount, $months, $edition, $discount, $p['id']]);
        return pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item, discount) VALUES (" . vat_rate_now() . ",?,?,?,?,'service',?,?)", [$t['slug'], $amount, $months, date('Y-m-d H:i:s'), $edition, $discount]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}
/** Potvrzení přijaté platby: prodlouží předplatné o zaplacené období (od konce dosavadního, po splatnosti ode dneška). */
function billing_confirm(array $p, string $by): ?array
{
    $t = tenant_row($p['tenant']);
    if (!$t || !in_array($p['status'], ['pending', 'claimed'], true)) {
        return null;
    }
    if (($p['kind'] ?? '') === 'storage') {
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+12 month -1 day'));
        $year = date('Y');
        $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
        pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $from, $to, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
        pq('UPDATE tenants SET storage_extra = ?, storage_until = ? WHERE slug = ?', [(int) $p['item'], $to, $t['slug']]);
        if ($t['owner_email'] !== '') {
            platform_mail($t['owner_email'], 'Úložiště navíc je zapnuté – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " za úložiště navíc. Web " . $t['name'] . " má do "
                . cz_date($to) . " o " . (int) $p['item'] . " MB místa více.\nFakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $from, 'to' => $to];
    }
    if (($p['kind'] ?? '') === 'migration') {
        $today = date('Y-m-d');
        $year = date('Y');
        $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
        pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $today, $today, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
        pq("UPDATE migrations SET status = 'paid' WHERE payment_id = ? AND status = 'ordered'", [$p['id']]);
        $m = pq('SELECT * FROM migrations WHERE payment_id = ?', [$p['id']])->fetch();
        $to = $m && filter_var($m['contact_email'], FILTER_VALIDATE_EMAIL) ? $m['contact_email'] : $t['owner_email'];
        if ($to !== '') {
            platform_mail($to, 'Platba za převod obsahu přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " za převod obsahu z webu " . $p['item'] . ".\n"
                . "Na převodu začínáme pracovat; obvykle je hotový do 5 pracovních dnů. Kdybychom potřebovali přístup nebo podklady, ozveme se na tento e-mail.\n"
                . "Fakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $today, 'to' => $today];
    }
    $domain = ($p['kind'] ?? 'service') === 'domain';
    if (($p['kind'] ?? '') === 'ai') {
        // AI Plus se platí na rok dopředu, při obnově od konce dosavadního období.
        $from = max(date('Y-m-d'), $t['ai_until'] !== '' ? date('Y-m-d', strtotime($t['ai_until'] . ' +1 day')) : '');
        $to = date('Y-m-d', strtotime($from . ' +12 month -1 day'));
        $year = date('Y');
        $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
        pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $from, $to, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
        pq('UPDATE tenants SET ai_until = ? WHERE slug = ?', [$to, $t['slug']]);
        if ($t['owner_email'] !== '') {
            platform_mail($t['owner_email'], 'AI Plus je zapnutý – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " za doplněk AI Plus. Do " . cz_date($to) . " má web "
                . $t['name'] . " k dispozici " . pset_int('lim_premium_ai', 0, 100000) . " použití AI pomocníka měsíčně.\nFakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $from, 'to' => $to];
    }
    // Doména se platí na rok dopředu: od dneška, při obnově od konce dosavadního období.
    $from = $domain ? max(date('Y-m-d'), $t['domain_until'] !== '' ? date('Y-m-d', strtotime($t['domain_until'] . ' +1 day')) : '')
        : (($edition = isset(EDITIONS[$p['item'] ?? '']) && $p['item'] !== 'free' ? $p['item'] : 'standard') === tenant_edition($t) && $t['paid_until'] >= date('Y-m-d')
            ? date('Y-m-d', strtotime($t['paid_until'] . ' +1 day')) : date('Y-m-d'));   // stejná edice se prodlužuje od konce období, jiná začíná dnes
    $to = date('Y-m-d', strtotime($from . ' +' . (int) $p['months'] . ' month -1 day'));
    // Faktura dostane pořadové číslo v roce a otisk údajů dodavatele a odběratele platných v den vystavení.
    $year = date('Y');
    $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), $from, $to, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
    if ($domain) {
        pq('UPDATE tenants SET domain_until = ? WHERE slug = ?', [$to, $t['slug']]);
        if ($t['owner_email'] !== '') {
            platform_mail($t['owner_email'], 'Platba za doménu přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " za vlastní doménu " . $p['item'] . " (do " . cz_date($to) . ").\n"
                . ($t['domain_status'] === 'active' ? '' : "Doménu teď zprovozníme a dáme Vám vědět.\n") . "Fakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $from, 'to' => $to];
    }
    pq("UPDATE tenants SET paid_until = ?, plan = ?, period = 'year', billing = 1, auto_renew = 1, reminded = '', credit = MAX(0, credit - ?) WHERE slug = ?", [$to, $edition, (int) ($p['discount'] ?? 0), $t['slug']]);
    referral_reward($t, $p);
    if ($t['owner_email'] !== '') {
        platform_mail($t['owner_email'], 'Platba přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " (variabilní symbol " . $p['vs'] . ").\nWeb " . $t['name']
            . " má edici " . EDITIONS[$edition] . " do " . cz_date($to) . ".\nFakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
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
function spayd(string $iban, int|float $amount, string $vs, string $message, string $recipient = ''): string
{
    $clean = fn(string $s, int $max) => substr(trim(str_replace('*', ' ', strtoupper(ascii_text($s)))), 0, $max);
    return 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format($amount, 2, '.', '') . '*CC:CZK' . ($vs !== '' ? '*X-VS:' . $vs : '')
        // Trvalý příkaz podle standardu QR Platba: první platba dnes, opakování každý měsíc, bez data ukončení.
        . '*MSG:' . $clean($message, 60) . ($recipient !== '' ? '*RN:' . $clean($recipient, 35) : '');
}
/** Jednou denně: připomene blížící se konec placené edice a přechod na edici Zdarma, konec zkoušky funkcí a uklidí nepoužívané weby. */
function platform_daily(): void
{
    if (pset('last_daily') === date('Y-m-d')) {
        return;
    }
    pset_save('last_daily', date('Y-m-d'));
    foreach (pq("SELECT * FROM tenants WHERE billing = 1 AND active = 1 AND demo = 0 AND owner_email <> ''")->fetchAll() as $t) {
        $s = billing_state($t);
        if ($s['until'] === '') {
            continue;   // edice Zdarma od začátku – není co připomínat
        }
        $key = match (true) {
            $s['mode'] === 'basic' => 'free:' . $s['until'],
            $s['mode'] === 'grace' => 'due:' . $s['until'],
            $s['mode'] !== 'grace' && $s['days'] <= 14 => 'soon:' . $s['until'],
            default => '',
        };
        if ($key === '' || $key === $t['reminded']) {
            continue;
        }
        $renews = !empty($t['auto_renew']);
        $what = $t['paid_until'] === '' ? 'zkušební doba' : 'předplatné edice ' . EDITIONS[$s['edition'] === 'free' ? ($t['plan'] ?: 'standard') : $s['edition']];
        // Před koncem zaplaceného období rovnou připravíme výzvu k platbě na další rok.
        if ($renews && $s['mode'] !== 'basic' && $t['paid_until'] !== '' && !billing_open($t)) {
            billing_order($t, $s['edition'] === 'free' ? 'standard' : $s['edition']);
        }
        $link = tenant_url($t, '/admin/predplatne');
        [$subject, $body] = match ($s['mode']) {
            'basic' => ['Web přešel na edici Zdarma – ' . $t['name'], "$what webu " . $t['name'] . " skončila " . cz_date($s['until']) . " a web teď běží v edici Zdarma.\nNic jsme nesmazali a web funguje dál; funkce vyšší edice jsou jen pro čtení a platí limity edice Zdarma."],
            'grace' => ['Skončila ' . $what . ' – ' . $t['name'], "$what webu " . $t['name'] . " skončila " . cz_date($s['until']) . ". Web zatím běží beze změny; bez úhrady přejde za " . max(0, pset_int('grace_days', 0, 365) + $s['days']) . " dní na edici Zdarma (nic se nemaže)."],
            default => $renews && $t['paid_until'] !== ''
                ? ['Předplatné se prodlužuje – ' . $t['name'], "$what webu " . $t['name'] . " končí " . cz_date($s['until']) . " a prodlužuje se automaticky o další rok. Zálohovou fakturu na další období jsme Vám připravili v administraci.\nNechcete pokračovat? V části Předplatné vypněte automatické prodlužování – web pak po skončení období přejde na edici Zdarma a nic dalšího neplatíte."]
                : ['Blíží se konec – ' . $what . ' webu ' . $t['name'], "$what webu " . $t['name'] . " končí " . cz_date($s['until']) . " a " . ($t['paid_until'] !== '' ? 'neprodlužuje se (automatické prodlužování je vypnuté)' : 'neprodlužuje se') . ". Potom web přejde na edici Zdarma – poběží dál, jen s jejími limity."],
        };
        tenant_notify($t, explode(':', $key)[0], $subject, "Dobrý den,\n\n" . $body . "\n\nEdice a platební údaje včetně QR kódu najdete po přihlášení v administraci: " . $link);
        pq('UPDATE tenants SET reminded = ? WHERE slug = ?', [$key, $t['slug']]);
    }
    trial_reminders();
    tenant_requested_deletions();
    demo_auto_refresh();
    platform_cleanup();
    domain_renewals();
    cert_reminders();
}

// ---------- Provozovatel, doklady, ukázkový web ----------

function operator(): array
{
    return ['name' => pset('op_name'), 'ico' => pset('op_ico'), 'dic' => pset('op_dic'), 'address' => pset('op_address'), 'register' => pset('op_register'), 'phone' => pset('op_phone'),
        'email' => pset('contact_email'), 'account' => pset('account'), 'iban' => (string) cz_iban(pset('account'))];
}
/** Je provozovatel plátcem DPH? Ceny v nastavení jsou bez daně a k platbě se DPH připočítává. */
function vat_payer(): bool
{
    return pset('vat_payer') === '1';
}
/** Sazba, která se právě teď připočítává k novým platbám (neplátce 0). */
function vat_rate_now(): int
{
    return vat_payer() ? pset_int('vat_rate', 0, 100) : 0;
}
/** Rozpad ceny bez DPH na základ, daň a částku k úhradě. */
function vat_split(int|float $base, int $rate): array
{
    $vat = round($base * $rate / 100, 2);
    return ['base' => (float) $base, 'vat' => $vat, 'total' => round($base + $vat, 2), 'rate' => $rate];
}
/** Částka, kterou má zákazník u platby skutečně poslat (cena + DPH). */
function pay_total(array $p): int|float
{
    $rate = (int) ($p['vat_rate'] ?? 0);
    return $rate > 0 ? vat_split((int) $p['amount'], $rate)['total'] : (int) $p['amount'];
}
function pay_money(array $p): string
{
    return (int) ($p['vat_rate'] ?? 0) > 0 ? money_czk2((float) pay_total($p)) : money_czk((int) $p['amount']);
}
/** Dovětek k částce s DPH: z čeho se skládá. */
function pay_vat_hint(array $p): string
{
    $rate = (int) ($p['vat_rate'] ?? 0);
    return $rate > 0 ? ' <small class="muted">(' . money_czk((int) $p['amount']) . ' + DPH ' . $rate . "\u{a0}%)</small>" : '';
}
/** Věta k ceníkům: ceny jsou bez DPH. */
function vat_note(): string
{
    return vat_payer() ? 'Všechny ceny jsou uvedeny bez DPH. K platbě se připočte DPH ' . pset_int('vat_rate', 0, 100) . "\u{a0}%; po zaplacení dostanete daňový doklad." : '';
}
function money_czk2(float $amount): string
{
    return number_format($amount, 2, ',', "\u{a0}") . "\u{a0}Kč";
}
/**
 * Co ještě chybí vyplnit, než službu pustíte mezi zákazníky: [co, proč, odkaz na část nastavení].
 */
function launch_checklist(): array
{
    $todo = [];
    if (pset('op_name') === '' || pset('op_address') === '' || pset('op_ico') === '') {
        $todo[] = ['Provozovatel: jméno nebo název, sídlo a IČO', 'Bez nich nejsou faktury ani obchodní podmínky úplné.', 'provozovatel'];
    }
    if (pset('op_register') === '') {
        $todo[] = ['Zápis v rejstříku', 'Uvádí se na fakturách (např. „Fyzická osoba zapsaná v živnostenském rejstříku“).', 'provozovatel'];
    }
    if (!vat_payer()) {
        $todo[] = ['Plátce DPH', 'Ceník ukazuje ceny „bez DPH“. Jste-li plátce, zaškrtněte to a vyplňte DIČ – k platbám se pak DPH připočte a faktury budou daňové doklady.', 'faktury'];
    }
    if (vat_payer() && pset('op_dic') === '') {
        $todo[] = ['DIČ', 'Plátce DPH ho musí mít na každém daňovém dokladu.', 'provozovatel'];
    }
    if (!cz_iban(pset('account'))) {
        $todo[] = ['Číslo účtu pro platby', 'Bez něj se nevytvoří QR kódy a zákazníci nevědí, kam platit.', 'platby'];
    }
    if (pset('mail_from') === '') {
        $todo[] = ['Odesílatel e-mailů služby', 'Bez něj neodcházejí potvrzení plateb, připomínky ani upozornění před smazáním webu.', 'kontakt'];
    }
    if (pset('contact_email') === '') {
        $todo[] = ['Kontaktní e-mail', 'Chodí na něj objednávky převodu, žádosti o doménu a žádosti podle GDPR.', 'kontakt'];
    }
    if (pset('hosting_note') === '') {
        $todo[] = ['Kde jsou data uložena', 'Patří do častých otázek a k zásadám ochrany údajů.', 'provozovatel'];
    }
    if (pset('legal_checked') !== '1') {
        $todo[] = ['Kontrola obchodních podmínek a smlouvy o zpracování údajů', 'Texty jsou vzor. Po kontrole to potvrďte zaškrtnutím v části Obchodní podmínky.', 'podminky'];
    }
    return $todo;
}
/** Dodavatel a odběratel pro doklad: odběratelem je společenství s údaji ze svého webu. */
function invoice_parties(array $t): array
{
    $s = tenant_settings($t['slug']);
    return ['supplier' => operator(), 'note' => pset('invoice_note'), 'vat' => ['payer' => pset('vat_payer') === '1', 'rate' => pset_int('vat_rate', 0, 100)],
        'customer' => ['name' => $s['site_full'] ?? $t['full'], 'address' => $s['address'] ?? $t['address'], 'ico' => $s['ico'] ?? '']];
}
/** Podklady pro tisk dokladu: u čekající platby zálohová faktura, u zaplacené faktura s údaji uloženými při vystavení. */
function invoice_view(array $p): ?array
{
    $t = tenant_row($p['tenant']);
    $paid = $p['status'] === 'paid';
    $parties = $paid && $p['invoice_data'] !== '' ? (array) json_decode($p['invoice_data'], true) : ($t ? invoice_parties($t) : null);
    if (!$parties || $p['status'] === 'cancelled') {
        return null;
    }
    $issued = substr((string) ($paid ? $p['paid_at'] : $p['created_at']), 0, 10);
    $iban = (string) ($parties['supplier']['iban'] ?? '');
    return ['item' => match ($p['kind'] ?? 'service') { 'domain' => 'Vlastní doména ' . $p['item'] . ' – provoz webu na této doméně', 'ai' => 'Doplněk AI Plus – ' . $p['item'], 'migration' => 'Jednorázový převod obsahu z webu ' . $p['item'], 'storage' => 'Úložiště navíc ' . (int) $p['item'] . ' MB na 1 rok', default => isset(EDITIONS[$p['item'] ?? '']) ? 'Web společenství – edice ' . EDITIONS[$p['item']] . ' na 1 rok' : null }, 'p' => $p, 'paid' => $paid, 'parties' => $parties, 'issued' => $issued, 'due' => date('Y-m-d', strtotime($issued . ' +' . pset_int('invoice_due_days', 0, 90) . ' day')),
        'site' => $t ? preg_replace('~^https?://~', '', rtrim(tenant_url($t), '/')) : $p['tenant'],
        'qr' => !$paid && $iban !== '' ? qr_svg(spayd($iban, pay_total($p), $p['vs'], 'Web SVJ ' . $p['tenant'], pset('recipient')), 'QR platba') : ''];
}
function demo_tenant(): ?array
{
    $r = pq('SELECT * FROM tenants WHERE demo = 1 AND active = 1 LIMIT 1')->fetch();
    return $r ?: null;
}
/** Znovu založí ukázkový web s čerstvými daty (termíny, zprávy a lhůty se počítají ode dneška). */
function demo_refresh(): void
{
    $old = pq('SELECT * FROM tenants WHERE demo = 1 LIMIT 1')->fetch();
    if ($old) {
        tenant_delete_storage($old['slug']);
        pq('DELETE FROM tenants WHERE slug = ?', [$old['slug']]);
    }
    demo_create();
}
/**
 * Verze ukázkového obsahu. Zvyšte ji při každé změně toho, co ukázka obsahuje (nové části webu, nová ukázková data):
 * ukázka založená starší verzí se pak sama založí znovu, aby v ní nic nechybělo.
 */
const DEMO_CONTENT_VERSION = 6;

/** Ukázka vznikla ze staršího obsahu, než jaký web umí teď? Pak ji hned obnoví. */
function demo_update_content(): void
{
    if (pset('demo_content') === (string) DEMO_CONTENT_VERSION || !pq('SELECT 1 FROM tenants WHERE demo = 1 LIMIT 1')->fetchColumn()) {
        return;
    }
    try {
        demo_refresh();
        pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Obnovení ukázkového webu', 'demo · nový ukázkový obsah (verze ' . DEMO_CONTENT_VERSION . ')']);
    } catch (Throwable $e) {
        error_log('SVJ web, obnova ukázky: ' . $e->getMessage());
    }
}
/** Jednou za pár dní ukázku obnoví, aby nepůsobila opuštěně. */
function demo_auto_refresh(): void
{
    $days = pset_int('demo_refresh_days', 0, 365);
    $demo = pq('SELECT created_at FROM tenants WHERE demo = 1 LIMIT 1')->fetchColumn();
    if ($days > 0 && $demo && substr((string) $demo, 0, 10) <= date('Y-m-d', strtotime('-' . $days . ' day'))) {
        try {
            demo_refresh();
            pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Obnovení ukázkového webu', 'demo · automaticky po ' . $days . ' dnech']);
        } catch (Throwable $e) {
            error_log('SVJ web, obnova ukázky: ' . $e->getMessage());
        }
    }
}
function demo_create(): void
{
    pset_save('demo_content', (string) DEMO_CONTENT_VERSION);
    tenant_create(['slug' => 'demo', 'name' => 'SVJ Zahradní 12', 'title' => 'Zahradní 12', 'tagline' => 'Společenství vlastníků jednotek · ukázkový web', 'full' => 'Společenství vlastníků jednotek Zahradní 12',
        'address' => 'Zahradní 12, Brno', 'unit_prefix' => '12', 'entrances' => 'A,B', 'look' => 'more', 'style' => 'modern', 'seed' => 'demo', 'demo' => 1, 'note' => 'ukázkový web, jen pro čtení']);
}

// ---------- Upozornění společenstvím a automatické mazání nezaplacených zkušebních webů ----------

const NOTICE_KINDS = ['soon' => 'blíží se konec období', 'due' => 'období skončilo', 'free' => 'přechod na edici Zdarma', 'manual' => 'předplatné nastaveno ručně', 'trial7' => 'končí zkouška funkce (7 dní)', 'trial2' => 'končí zkouška funkce (2 dny)', 'locked' => 'web pozastaven', 'del14' => 'web bude smazán (první upozornění)',
    'del3' => 'web bude smazán (poslední upozornění)', 'deleted' => 'web byl smazán', 'domain' => 'končí předplatné vlastní domény', 'cert' => 'končí platnost nahraného certifikátu'];

/** Pošle upozornění kontaktní osobě společenství a zapíše, komu, kdy a zda se ho podařilo odeslat. */
function tenant_notify(array $t, string $kind, string $subject, string $text): bool
{
    $ok = $t['owner_email'] !== '' && platform_mail($t['owner_email'], $subject, $text);
    pq('INSERT INTO tenant_notices (tenant, tenant_name, at, kind, email, sent, subject) VALUES (?,?,?,?,?,?,?)', [$t['slug'], $t['name'], date('Y-m-d H:i:s'), $kind, $t['owner_email'], $ok ? 1 : 0, mb_substr($subject, 0, 200)]);
    return $ok;
}
/** Kdy byl web naposledy používán: poslední přihlášení kohokoli, jinak den založení. */
function tenant_last_active(array $t): string
{
    $s = tenant_stats($t['slug']);
    return substr(max((string) ($s['last_login'] ?? ''), (string) $t['created_at']), 0, 10);
}
/** Den, kdy bude web smazán: jen web v edici Zdarma, na který se dlouho nikdo nepřihlásil. Jinak null. */
function tenant_delete_date(array $t): ?string
{
    $days = pset_int('delete_days', 0, 3650);
    if ($days === 0 || empty($t['billing']) || !empty($t['demo']) || tenant_edition($t) !== 'free') {
        return null;
    }
    if (pq("SELECT 1 FROM payments WHERE tenant = ? AND status = 'claimed' LIMIT 1", [$t['slug']])->fetchColumn()) {
        return null;   // platba je nahlášená – nemazat
    }
    return date('Y-m-d', strtotime(tenant_last_active($t) . ' +' . $days . ' day'));
}
/**
 * Smaže nepoužívané weby v edici Zdarma a předem na to upozorní: 14 a 3 dny před smazáním.
 * Bez prokazatelně odeslaného upozornění starého aspoň 3 dny se nic nemaže; přihlášení na web lhůtu vynuluje.
 */
function platform_cleanup(): void
{
    $today = date('Y-m-d');
    foreach (pq('SELECT * FROM tenants WHERE billing = 1 AND demo = 0')->fetchAll() as $t) {
        $on = tenant_delete_date($t);
        if ($on === null || $today < date('Y-m-d', strtotime($on . ' -14 day'))) {
            continue;
        }
        $since = tenant_last_active($t);
        $sent = array_column(pq("SELECT kind, MIN(at) AS at FROM tenant_notices WHERE tenant = ? AND sent = 1 AND kind IN ('del14','del3') AND at >= ? GROUP BY kind", [$t['slug'], $since])->fetchAll(), 'at', 'kind');
        $tried = array_column(pq("SELECT kind, MAX(at) AS at FROM tenant_notices WHERE tenant = ? AND kind IN ('del14','del3') AND at >= ? GROUP BY kind", [$t['slug'], $since])->fetchAll(), 'at', 'kind');
        $warned = $sent ? min($sent) : null;
        if ($today >= $on && $warned !== null && substr($warned, 0, 10) <= date('Y-m-d', strtotime('-3 day'))) {
            tenant_delete_storage($t['slug']);
            pq('DELETE FROM tenants WHERE slug = ?', [$t['slug']]);
            pq('DELETE FROM feature_trials WHERE tenant = ?', [$t['slug']]);
            pq("UPDATE payments SET status = 'cancelled', note = 'web smazán pro nečinnost' WHERE tenant = ? AND status = 'pending'", [$t['slug']]);
            pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Automatické smazání společenství',
                $t['slug'] . ' · ' . $t['name'] . ' · bez přihlášení od ' . $since . ', upozorněno ' . substr($warned, 0, 10) . ' na ' . $t['owner_email']]);
            tenant_notify($t, 'deleted', 'Web byl smazán – ' . $t['name'], "Dobrý den,\n\nweb " . $t['name'] . " (" . tenant_address($t['slug']) . ") jsme smazali včetně všech dat, protože se na něj od "
                . cz_date($since) . " nikdo nepřihlásil.\n\nKdykoli si můžete založit nový: " . platform_url('/registrace'));
            continue;
        }
        // Které upozornění je na řadě; každé se zkouší nejvýše jednou denně.
        $kind = $today >= date('Y-m-d', strtotime($on . ' -3 day')) && isset($sent['del14']) && substr($sent['del14'], 0, 10) < $today ? 'del3' : 'del14';
        if (isset($sent[$kind]) || substr($tried[$kind] ?? '', 0, 10) === $today) {
            continue;
        }
        $when = max($on, date('Y-m-d', strtotime('+3 day')));
        tenant_notify($t, $kind, ($kind === 'del3' ? 'Poslední upozornění: web' : 'Web') . ' bude smazán – ' . $t['name'],
            "Dobrý den,\n\nna web " . $t['name'] . " se od " . cz_date($since) . " nikdo nepřihlásil. Nepoužívané weby v edici Zdarma mažeme, tento nejdříve "
            . cz_date($when) . ".\n\nChcete web zachovat? Stačí se přihlásit: " . tenant_url($t, '/prihlaseni')
            . "\nChcete si jen odnést data? Záloha ke stažení je součástí placených edic a lze ji jednou na 30 dní zdarma vyzkoušet: " . tenant_url($t, '/admin/zaloha') . "\n\nPokud web nepotřebujete, nemusíte dělat nic.");
    }
}
const TENANT_DELETE_WAIT_DAYS = 7;

/** Správce webu požádal o zrušení: web se smaže po týdnu, do té doby lze žádost vzít zpět. Ukázkový web zrušit nelze. */
function tenant_delete_request(array $t, string $by): ?string
{
    if (!empty($t['demo'])) {
        return null;
    }
    $on = date('Y-m-d', strtotime('+' . TENANT_DELETE_WAIT_DAYS . ' day'));
    pq('UPDATE tenants SET delete_at = ?, delete_by = ? WHERE slug = ?', [$on, mb_substr($by, 0, 190), $t['slug']]);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Žádost o smazání webu', $t['slug'] . ' · ' . $t['name'] . ' · požádal ' . $by . ' · smazat ' . $on]);
    return $on;
}
function tenant_delete_cancel(array $t, string $by): void
{
    pq("UPDATE tenants SET delete_at = '', delete_by = '' WHERE slug = ?", [$t['slug']]);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Žádost o smazání webu vzata zpět', $t['slug'] . ' · ' . $by]);
}
/** Smaže weby, u kterých uplynula lhůta od žádosti správce. */
function tenant_requested_deletions(): void
{
    foreach (pq("SELECT * FROM tenants WHERE delete_at <> '' AND delete_at <= ? AND demo = 0", [date('Y-m-d')])->fetchAll() as $t) {
        tenant_delete_storage($t['slug']);
        pq('DELETE FROM tenants WHERE slug = ?', [$t['slug']]);
        pq('DELETE FROM feature_trials WHERE tenant = ?', [$t['slug']]);
        pq("UPDATE payments SET status = 'cancelled', note = 'web zrušen na žádost správce' WHERE tenant = ? AND status = 'pending'", [$t['slug']]);
        pq("UPDATE migrations SET status = 'cancelled' WHERE tenant = ? AND status = 'ordered'", [$t['slug']]);
        pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Smazání společenství na žádost správce', $t['slug'] . ' · ' . $t['name'] . ' · požádal ' . $t['delete_by']]);
        tenant_notify($t, 'deleted', 'Web byl smazán – ' . $t['name'], "Dobrý den,\n\nweb " . $t['name'] . " (" . tenant_address($t['slug']) . ") jsme na žádost jeho správce smazali včetně všech dat, účtů a dokumentů.\n"
            . "Vystavené faktury uchováváme po dobu, kterou ukládají účetní předpisy.\n\nKdykoli si můžete založit nový web: " . platform_url('/registrace'));
    }
}
/** Upozorní správce 7 a 2 dny před koncem zkoušky funkce. */
function trial_reminders(): void
{
    $today = date('Y-m-d');
    foreach (pq("SELECT * FROM feature_trials WHERE ends >= ? AND ends <= ? AND warned <> '2'", [$today, date('Y-m-d', strtotime('+7 day'))])->fetchAll() as $r) {
        $left = (int) round((strtotime($r['ends']) - strtotime($today)) / 86400);
        $step = $left <= 2 ? '2' : '7';
        $t = tenant_row($r['tenant']);
        if ($r['warned'] === $step || !$t || !isset(PLAN_FEATURES[$r['feature']]) || EDITION_RANK[tenant_edition($t)] >= EDITION_RANK[PLAN_FEATURES[$r['feature']][0]]) {
            continue;
        }
        [$min, $name] = PLAN_FEATURES[$r['feature']];
        tenant_notify($t, 'trial' . $step, 'Zkouška funkce ' . $name . ' končí ' . cz_date($r['ends']) . ' – ' . $t['name'], "Dobrý den,\n\nzkouška funkce „" . $name . "“ na webu " . $t['name'] . " končí " . cz_date($r['ends'])
            . ".\nPotom zůstane vše, co jste do ní zapsali, uložené a viditelné, jen už nepůjde nic přidávat ani měnit.\n\nFunkce je součástí edice " . EDITIONS[$min] . " (" . money_czk(edition_price($min))
            . " ročně). Objednat ji můžete zde: " . tenant_url($t, '/admin/predplatne'));
        pq('UPDATE feature_trials SET warned = ? WHERE id = ?', [$step, $r['id']]);
    }
}

// ---------- Vlastní doména společenství jako placený doplněk ----------

/** Upraví zadanou doménu na tvar „priklad.cz“; nesmyslný zápis vrátí jako prázdný text. */
function domain_clean(string $d): string
{
    $d = strtolower(trim(preg_replace('~^https?://|/.*$~i', '', trim($d)), '. '));
    $d = preg_replace('/^www\./', '', $d);
    return preg_match('/^(?=.{4,100}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/', $d) ? $d : '';
}
/** Zda doména podle DNS už někomu patří: true = registrovaná, false = vypadá volná, null = nepodařilo se zjistit. */
function domain_registered(string $d): ?bool
{
    if (!function_exists('checkdnsrr')) {
        return null;
    }
    try {
        return @checkdnsrr($d . '.', 'NS') || @checkdnsrr($d . '.', 'SOA') || @checkdnsrr($d . '.', 'A');
    } catch (Throwable $e) {
        return null;
    }
}
function domain_payment(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'domain' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
/** Výzva k platbě za doménu na rok (jedna otevřená na společenství). */
function domain_payment_create(array $t, string $domain): array
{
    if ($p = domain_payment($t)) {
        pq('UPDATE payments SET item = ?, amount = ? WHERE id = ? AND status = ?', [$domain, pset_int('price_domain', 0, 1000000), $p['id'], 'pending']);
        return pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,12,?,'domain',?)", [$t['slug'], pset_int('price_domain', 0, 1000000), date('Y-m-d H:i:s'), $domain]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}
/** Měsíc před koncem zaplaceného období domény vystaví výzvu na další rok a pošle upozornění. */
function domain_renewals(): void
{
    foreach (pq("SELECT * FROM tenants WHERE domain_status = 'active' AND domain_until <> '' AND domain_until <= ?", [date('Y-m-d', time() + 30 * 86400)])->fetchAll() as $t) {
        if (domain_payment($t)) {
            continue;   // výzva už existuje, upozornění odešlo při jejím vzniku
        }
        $p = domain_payment_create($t, $t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request']);
        tenant_notify($t, 'domain', 'Končí předplatné vlastní domény – ' . $t['name'], "Dobrý den,\n\npředplatné vlastní domény " . $p['item'] . " webu " . $t['name'] . " končí " . cz_date($t['domain_until'])
            . ".\nPlatbu na další rok (" . pay_money($p) . ", variabilní symbol " . $p['vs'] . ") najdete včetně QR kódu v administraci: " . tenant_url($t, '/admin/predplatne')
            . "\n\nBez úhrady web dál poběží na adrese " . tenant_address($t['slug']) . ", vlastní doménu ale po skončení období odpojíme.");
    }
}

// ---------- Doporučení: odměna za přivedení platícího společenství ----------

/** Kód pro doporučení (vznikne při prvním použití) – je součástí odkazu, který společenství posílá dál. */
function referral_code(array $t): string
{
    if (($t['ref_code'] ?? '') !== '') {
        return $t['ref_code'];
    }
    do {
        $code = substr(strtr(base64_encode(random_bytes(8)), ['+' => '', '/' => '', '=' => '']), 0, 8);
    } while (strlen($code) < 8 || pq('SELECT 1 FROM tenants WHERE ref_code = ?', [$code])->fetchColumn());
    pq('UPDATE tenants SET ref_code = ? WHERE slug = ?', [$code, $t['slug']]);
    return $code;
}
function referral_link(array $t): string
{
    return platform_url('/') . '?ref=' . referral_code($t);
}
/** Společenství, kterému kód patří (jen běžný web, ne ukázka). */
function referral_owner(string $code): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{8}$/', $code)) {
        return null;
    }
    $t = pq('SELECT * FROM tenants WHERE ref_code = ? AND demo = 0', [$code])->fetch();
    return $t ?: null;
}
/** Zapíše, že nové společenství přišlo na doporučení; samo sebe (stejný kontaktní e-mail) doporučit nejde. */
function referral_register(string $code, array $new): void
{
    $ref = referral_owner($code);
    if (!$ref || $ref['slug'] === $new['slug'] || ($ref['owner_email'] !== '' && strcasecmp($ref['owner_email'], (string) $new['owner_email']) === 0)) {
        return;
    }
    pq('UPDATE tenants SET referred_by = ?, credit = credit + ? WHERE slug = ?', [$ref['slug'], pset_int('referral_welcome', 0, 100000), $new['slug']]);
    pq('INSERT OR IGNORE INTO referrals (referrer, referred, referred_name, created_at) VALUES (?,?,?,?)', [$ref['slug'], $new['slug'], $new['name'], date('Y-m-d H:i:s')]);
}
/**
 * Odměna doporučiteli: připíše se jako kredit ve chvíli, kdy doporučené společenství poprvé zaplatí roční edici.
 * Za každé doporučené společenství jen jednou.
 */
function referral_reward(array $paid, array $payment): void
{
    $r = pq("SELECT * FROM referrals WHERE referred = ? AND rewarded_at = ''", [$paid['slug']])->fetch();
    $amount = pset_int('referral_reward', 0, 100000);
    if (!$r || $amount === 0) {
        return;
    }
    $ref = tenant_row($r['referrer']);
    if (!$ref) {
        return;
    }
    pq('UPDATE referrals SET rewarded_at = ?, amount = ? WHERE id = ?', [date('Y-m-d H:i:s'), $amount, $r['id']]);
    pq('UPDATE tenants SET credit = credit + ? WHERE slug = ?', [$amount, $ref['slug']]);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Odměna za doporučení', $ref['slug'] . ' · +' . $amount . ' Kč za ' . $paid['slug']]);
    if ($ref['owner_email'] !== '') {
        platform_mail($ref['owner_email'], 'Odměna za doporučení: ' . money_czk($amount) . ' – ' . $ref['name'], "Dobrý den,\n\nspolečenství " . $paid['name'] . ", které jste nám doporučili, si zaplatilo edici. Děkujeme!\n"
            . "Na účet webu " . $ref['name'] . " jsme připsali kredit " . money_czk($amount) . ". Odečte se sám od Vaší platby za další období (při roční platbě).\n\nPřehled doporučení: " . tenant_url($ref, '/admin/predplatne') . '#doporuceni');
    }
}

/**
 * Přehled doporučení jednoho společenství: každé doporučené s aktuálním stavem a souhrn.
 * Stavy: registered (jen se zaregistrovalo), trying (zkouší placené funkce), ordered (objednalo edici, čeká se na platbu),
 * converted (zaplatilo – kredit připsán), gone (web už neexistuje).
 */
function referral_overview(array $t): array
{
    $rows = [];
    $reward = pset_int('referral_reward', 0, 100000);
    foreach (pq('SELECT * FROM referrals WHERE referrer = ? ORDER BY id DESC', [$t['slug']])->fetchAll() as $r) {
        $ref = tenant_row($r['referred']);
        $state = 'registered';
        $note = '';
        if ($r['rewarded_at'] !== '') {
            $state = 'converted';
        } elseif (!$ref) {
            $state = 'gone';
        } else {
            if (pq("SELECT 1 FROM payments WHERE tenant = ? AND kind = 'service' AND status IN ('pending','claimed') LIMIT 1", [$ref['slug']])->fetchColumn()) {
                $state = 'ordered';
            } elseif (pq('SELECT 1 FROM feature_trials WHERE tenant = ? AND ends >= ? LIMIT 1', [$ref['slug'], date('Y-m-d')])->fetchColumn()) {
                $state = 'trying';
            }
        }
        $rows[] = $r + ['state' => $state, 'note' => $note];
    }
    $converted = array_filter($rows, fn($r) => $r['state'] === 'converted');
    $used = (int) pq("SELECT COALESCE(SUM(discount), 0) FROM payments WHERE tenant = ? AND status = 'paid'", [$t['slug']])->fetchColumn();
    return ['rows' => $rows, 'clicks' => (int) ($t['ref_clicks'] ?? 0), 'registered' => count($rows), 'converted' => count($converted),
        'earned' => array_sum(array_map(fn($r) => (int) $r['amount'], $converted)), 'used' => $used, 'credit' => (int) ($t['credit'] ?? 0), 'reward' => $reward];
}
const REFERRAL_STATES = ['registered' => ['Zaregistrovalo se', 'new'], 'trying' => ['Zkouší placené funkce', 'in_progress'], 'ordered' => ['Objednalo edici, čeká se na platbu', 'in_progress'],
    'converted' => ['Zaplatilo – kredit připsán', 'done'], 'gone' => ['Web už neexistuje', 'new']];

// ---------- Úložiště navíc: balíčky nad limit edice, placené na rok ----------

/** Kolik MB navíc má web právě zaplaceno. */
function storage_extra_mb(?array $t): int
{
    return $t && ($t['storage_until'] ?? '') !== '' && $t['storage_until'] >= date('Y-m-d') ? (int) $t['storage_extra'] : 0;
}
function storage_payment(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'storage' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
/** Objednávka balíčků úložiště na rok; 'item' nese počet MB. Platí pro celý počet balíčků (nahrazuje dosavadní). */
function storage_order(array $t, int $packs): ?array
{
    $packs = max(1, min(20, $packs));
    $mb = $packs * pset_int('storage_pack_mb', 1, 100000);
    $amount = $packs * pset_int('price_storage', 0, 1000000);
    if ($p = storage_payment($t)) {
        if ($p['status'] === 'claimed') {
            return $p;
        }
        pq('UPDATE payments SET amount = ?, item = ? WHERE id = ?', [$amount, (string) $mb, $p['id']]);
        return pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,12,?,'storage',?)", [$t['slug'], $amount, date('Y-m-d H:i:s'), (string) $mb]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}

// ---------- Jednorázový převod obsahu ze starého webu ----------

/** Co lze převést (klíč => popis) a v jakém stavu může objednávka být. */
const MIGRATION_SCOPE = ['aktuality' => 'Aktuality a oznámení', 'dokumenty' => 'Dokumenty ke stažení (PDF, Word, Excel)', 'kontakty' => 'Kontakty na výbor a správce', 'stranky' => 'Texty stránek (o domě, domovní řád…)', 'fotky' => 'Fotografie'];
const MIGRATION_ACCESS = ['public' => 'Vše je na starém webu veřejně vidět', 'login' => 'Část je za přihlášením – přístup pošleme e-mailem', 'export' => 'Máme export nebo zálohu, pošleme ji'];
const MIGRATION_STATUS = ['ordered' => 'čeká na platbu', 'paid' => 'zaplaceno, pracujeme na převodu', 'done' => 'hotovo', 'cancelled' => 'zrušeno'];

/** Poslední nezrušená objednávka převodu tohoto webu (nebo null). */
function migration_row(string $slug): ?array
{
    $m = pq("SELECT * FROM migrations WHERE tenant = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [$slug])->fetch();
    return $m ?: null;
}
function migration_payment(?array $m): ?array
{
    $p = $m && $m['payment_id'] ? pq('SELECT * FROM payments WHERE id = ?', [$m['payment_id']])->fetch() : null;
    return $p ?: null;
}
/** Založí objednávku a výzvu k platbě. */
function migration_order(array $t, array $in): array
{
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,0,?,'migration',?)", [$t['slug'], pset_int('price_migration', 0, 1000000), date('Y-m-d H:i:s'), mb_substr($in['url'], 0, 150)]);
    $pid = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $pid), $pid]);
    pq('INSERT INTO migrations (tenant, url, scope, access, note, contact_name, contact_email, contact_phone, payment_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$t['slug'], $in['url'], $in['scope'], $in['access'], $in['note'], $in['contact_name'], $in['contact_email'], $in['contact_phone'], $pid, date('Y-m-d H:i:s')]);
    return migration_row($t['slug']);
}

// ---------- AI Plus: placený doplněk s vyšším měsíčním limitem AI pomocníka ----------

function ai_plus_active(?array $t): bool
{
    return $t && ($t['ai_until'] ?? '') !== '' && $t['ai_until'] >= date('Y-m-d');
}
function ai_payment(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'ai' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
function ai_payment_create(array $t): array
{
    if ($p = ai_payment($t)) {
        return $p;
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,12,?,'ai',?)", [$t['slug'], 990, date('Y-m-d H:i:s'), pset_int('lim_premium_ai', 0, 100000) . ' použití měsíčně']);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}

/** Z toho, co člověk napsal do pole adresy, nechá jen název: pevnou část (doménu) i protokol odstraní. */
function slug_input(string $v): string
{
    $v = strtolower(trim(preg_replace('~^https?://~i', '', trim($v))));
    foreach (array_unique([platform_main_domain(), platform_domain()]) as $fixed) {
        $v = preg_replace('~' . preg_quote($fixed, '~') . '(:\d+)?~', '', $v);
    }
    return trim(substr(preg_replace('/-{2,}/', '-', preg_replace('/[^a-z0-9-]/', '', preg_replace('~[\s_./]+~', '-', ascii_text($v)))), 0, 39), '-');
}

// ---------- Vlastní certifikát HTTPS pro vlastní doménu společenství ----------
// Aplikace certifikát jen přijme, zkontroluje a uloží do storage/certs/<doména>/. Používat ho musí webový server (viz README).

function cert_dir(string $domain): string
{
    return ROOT_STORAGE . '/certs/' . $domain;
}
/**
 * Zkontroluje certifikát a soukromý klíč pro danou doménu. Vrací údaje o certifikátu, nebo text chyby.
 * Klíč chráněný heslem se nepřijímá – server by ho neuměl načíst.
 */
function cert_check(string $certPem, string $keyPem, string $domain): array|string
{
    if (!function_exists('openssl_x509_read')) {
        return 'Na serveru chybí rozšíření OpenSSL, certifikát nejde zkontrolovat.';
    }
    $certPem = trim(str_replace("\r", '', $certPem)) . "\n";
    $keyPem = trim(str_replace("\r", '', $keyPem)) . "\n";
    if (!str_contains($certPem, '-----BEGIN CERTIFICATE-----') || !($cert = @openssl_x509_read($certPem))) {
        return 'Certifikát se nepodařilo přečíst. Vložte ho ve formátu PEM (začíná řádkem -----BEGIN CERTIFICATE-----), nejlépe celý řetězec včetně mezilehlých certifikátů.';
    }
    if (str_contains($keyPem, 'ENCRYPTED')) {
        return 'Soukromý klíč je chráněný heslem. Nahrajte klíč bez hesla.';
    }
    if (!preg_match('/-----BEGIN (RSA |EC )?PRIVATE KEY-----/', $keyPem) || !($key = @openssl_pkey_get_private($keyPem))) {
        return 'Soukromý klíč se nepodařilo přečíst. Vložte ho ve formátu PEM (začíná řádkem -----BEGIN PRIVATE KEY-----).';
    }
    if (!openssl_x509_check_private_key($cert, $key)) {
        return 'Soukromý klíč nepatří k tomuto certifikátu.';
    }
    $info = openssl_x509_parse($cert) ?: [];
    $names = [];
    foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $part) {
        if (preg_match('/^\s*DNS:(.+)$/', $part, $m)) {
            $names[] = strtolower(trim($m[1]));
        }
    }
    if (!$names && !empty($info['subject']['CN'])) {
        $names[] = strtolower((string) $info['subject']['CN']);
    }
    $covers = function (string $host) use ($names): bool {
        foreach ($names as $n) {
            if ($n === $host || (str_starts_with($n, '*.') && substr($host, strpos($host, '.') + 1) === substr($n, 2))) {
                return true;
            }
        }
        return false;
    };
    if (!$covers($domain)) {
        return 'Certifikát není vydaný pro doménu ' . $domain . ' (je pro: ' . implode(', ', array_slice($names, 0, 6)) . ').';
    }
    $to = (int) ($info['validTo_time_t'] ?? 0);
    if ($to < time()) {
        return 'Platnost certifikátu skončila ' . date('j. n. Y', $to) . '.';
    }
    if ((int) ($info['validFrom_time_t'] ?? 0) > time() + 86400) {
        return 'Certifikát ještě nezačal platit.';
    }
    return ['cert' => $certPem, 'key' => $keyPem, 'valid_to' => date('Y-m-d', $to), 'names' => $names, 'www' => $covers('www.' . $domain),
        'issuer' => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''), 'self_signed' => ($info['subject'] ?? []) == ($info['issuer'] ?? [])];
}
function cert_store(string $domain, array $checked): void
{
    $dir = cert_dir($domain);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    @file_put_contents(ROOT_STORAGE . '/certs/index.html', '');
    // Nejdřív do dočasných souborů a pak přejmenovat, aby server nikdy nenačetl napůl zapsaný soubor nebo nový certifikát se starým klíčem.
    foreach (['fullchain.pem' => $checked['cert'], 'privkey.pem' => $checked['key']] as $name => $content) {
        $tmp = $dir . '/.' . $name . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        chmod($tmp, $name === 'privkey.pem' ? 0640 : 0644);
        rename($tmp, $dir . '/' . $name);
    }
}
/** Údaje o uloženém certifikátu domény (bez klíče), nebo null. */
function cert_info(string $domain): ?array
{
    $file = cert_dir($domain) . '/fullchain.pem';
    if ($domain === '' || !is_file($file) || !function_exists('openssl_x509_parse') || !($info = @openssl_x509_parse((string) file_get_contents($file)))) {
        return null;
    }
    $to = (int) ($info['validTo_time_t'] ?? 0);
    return ['valid_to' => date('Y-m-d', $to), 'days' => (int) floor(($to - time()) / 86400), 'issuer' => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''),
        'uploaded' => date('Y-m-d H:i', (int) filemtime($file)), 'self_signed' => ($info['subject'] ?? []) == ($info['issuer'] ?? [])];
}
function cert_remove(string $domain): void
{
    foreach (glob(cert_dir($domain) . '/{,.}*.pem*', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(cert_dir($domain));
}
/** Přijme certifikát z formuláře (soubory nebo vložený text) pro doménu společenství. Vrací zprávu pro uživatele a zda se povedlo. */
function cert_upload(array $t): array
{
    $domain = $t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request'];
    if ($domain === '') {
        return [false, 'Společenství nemá nastavenou vlastní doménu.'];
    }
    $read = function (string $field): string {
        $f = $_FILES[$field . '_file'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $f['size'] <= 200000) {
            return (string) file_get_contents($f['tmp_name']);
        }
        return mb_substr((string) ($_POST[$field] ?? ''), 0, 200000);
    };
    $checked = cert_check($read('cert'), $read('key'), $domain);
    if (is_string($checked)) {
        return [false, $checked];
    }
    cert_store($domain, $checked);
    return [true, 'Certifikát pro ' . $domain . ' je uložen, platí do ' . cz_date($checked['valid_to']) . '.'
        . ($checked['www'] ? '' : ' Pozor: nepokrývá adresu www.' . $domain . '.') . ($checked['self_signed'] ? ' Je podepsaný sám sebou, prohlížeče před ním budou varovat.' : ''), $domain];
}
/** Tři týdny před koncem platnosti nahraného certifikátu upozorní kontaktní osobu (nejvýše jednou za 10 dní). */
function cert_reminders(): void
{
    foreach (pq("SELECT * FROM tenants WHERE custom_domain <> ''")->fetchAll() as $t) {
        $c = cert_info($t['custom_domain']);
        if (!$c || $c['days'] > 21 || pq("SELECT 1 FROM tenant_notices WHERE tenant = ? AND kind = 'cert' AND at >= ?", [$t['slug'], date('Y-m-d H:i:s', time() - 10 * 86400)])->fetchColumn()) {
            continue;
        }
        tenant_notify($t, 'cert', 'Končí platnost certifikátu – ' . $t['custom_domain'], "Dobrý den,\n\ncertifikát HTTPS, který jste nahráli pro doménu " . $t['custom_domain'] . ", "
            . ($c['days'] < 0 ? 'už neplatí' : 'platí jen do ' . cz_date($c['valid_to'])) . ".\nNový nahrajte po přihlášení v části Předplatné: " . tenant_url(['custom_domain' => ''] + $t, '/admin/predplatne')
            . "\n\nBez platného certifikátu budou prohlížeče návštěvníky před webem varovat.");
    }
}

// ---------- Připravované funkce a zájem o ně ----------

/** klíč → [název, popis, předpokládaná cena nebo ''] – funkce, které ještě nejsou hotové; zájem o ně se sbírá tlačítkem. */
/**
 * Připravované funkce: klíč => [název, popis, kde a za kolik budou, krátký název do menu administrace].
 * Edice a ceny jsou předběžné – slouží k tomu, aby zájem odpovídal skutečné nabídce.
 */
const PLANNED_FEATURES = [
    'ai-zapis-nahravka' => ['Zápis ze shromáždění z nahrávky', 'Nahrajete zvukový záznam schůze a AI z něj připraví zápis. (Zápis z poznámek a z nahraného přepisu už funguje.)', 'Premium · platí se AI kredity', 'Zápis z nahrávky'],
    'ai-asistent' => ['AI asistent pro obyvatele i výbor', 'Odpovídá sousedům na dotazy podle stanov, domovního řádu a oznámení výboru – a výboru ubere rutinu u závad, dokumentů, shromáždění i vyúčtování.', 'doplněk k Premium · asi 990 Kč ročně, náročnější úlohy za AI kredity', 'AI asistent'],
    'sms' => ['SMS upozornění', 'Důležitá oznámení a pozvánky i vlastníkům, kteří nečtou e-mail.', 'doplněk od Standardu · asi 1,50 Kč za SMS', 'SMS upozornění'],
    'katastr' => ['Hlídání změn v katastru', 'Web sám pozná, že se u jednotky změnil vlastník, a upozorní výbor.', 'doplněk od Standardu · asi 490 Kč ročně', 'Hlídání katastru'],
    'insolvence' => ['Hlídání insolvencí a exekucí', 'Upozornění, když se vlastník ocitne v insolvenčním rejstříku, aby výbor stihl přihlásit pohledávku.', 'Premium · v ceně', 'Hlídání insolvencí'],
    'bankid' => ['Hlasování s bankovní identitou', 'Ověření hlasujícího přes banku pro rozhodnutí, kde záleží na průkaznosti.', 'doplněk k Premium · asi 15 Kč za ověření', 'Bankovní identita'],
    'banka' => ['Párování plateb s bankou', 'Stav záloh a nedoplatků se aktualizuje sám podle výpisu z účtu společenství.', 'Premium · v ceně', 'Párování plateb'],
    'napojeni-ai' => ['Napojení na vlastního AI asistenta', 'Web si připojíte ke svému AI nástroji a běžnou řečí se ptáte na závady, dokumenty, platby a termíny – výbor si navíc nechá připravit koncept oznámení.', 'Premium · cena se upřesní', 'Napojení AI'],
];
/**
 * Podrobný popis připravovaných funkcí pro stránku s detailem:
 * lead = jednou větou, problem = jaký problém řeší, how = co přesně bude dělat, note = cena a omezení.
 */
const PLANNED_DETAILS = [
    'ai-zapis-nahravka' => [
        'lead' => 'Schůzi nahrajete na telefon, nahrávku vložíte na web a dostanete návrh zápisu.',
        'problem' => 'Zápis ze shromáždění bývá nejméně oblíbená práce ve výboru. Zapisovatel během schůze nestíhá poslouchat i psát, zápis pak vzniká týdny po schůzi z paměti a vlastníci se dohadují, co kdo vlastně řekl. Dnes už pomocník umí zápis napsat z poznámek nebo z hotového přepisu – přepis si ale musíte obstarat sami.',
        'how' => ['Nahrajete zvukový soubor ze schůze (telefon, diktafon, záznam online schůzky).', 'Služba nahrávku přepíše do textu a rozliší, kdy mluví kdo.', 'AI z přepisu sestaví zápis podle programu: průběh jednání, návrhy, výsledky hlasování, úkoly a termíny.', 'Před spuštěním uvidíte, kolik AI kreditů zpracování spotřebuje, a potvrdíte ho.', 'Zapisovatel návrh projde, opraví a vytiskne nebo uloží do Dokumentů. Nahrávka se po zpracování smaže.'],
        'note' => 'Bude v edici Premium a poběží celé automaticky. Platit se bude předplacenými AI kredity, které si společenství dokoupí v administraci; zpracování nahrávky z nich odečte podle její délky (odhadem 100 Kč za hodinu). Když se zpracování technicky nepovede, kredity se nestrhnou. Výsledek je návrh od umělé inteligence – jeho kvalita závisí na nahrávce a ručně ho nikdo neupravuje, kontrola je na zapisovateli. Účastníci musí o nahrávání vědět.',
    ],
    'ai-asistent' => [
        'lead' => 'Sousedé se zeptají webu a dostanou odpověď podle Vašich stanov, domovního řádu a oznámení.',
        'problem' => 'Výbor odpovídá pořád na stejné otázky: kdy se smí vrtat, kam s kočárkem, kdy bude vyúčtování, komu hlásit kapající radiátor. Odpovědi přitom v dokumentech na webu jsou, jen je nikdo nehledá.',
        'how' => ['Přihlášený obyvatel položí otázku běžnou řečí, česky i v dalších jazycích webu.', 'Asistent odpoví jen podle dokumentů a oznámení Vašeho domu a uvede, odkud odpověď vzal.', 'Když odpověď v podkladech není, řekne to a nabídne předání dotazu výboru.', 'Výbor vidí, na co se lidé ptají nejčastěji, a ví, co na webu doplnit.'],
        'note' => 'Doplněk k edici Premium, asi 990 Kč ročně; náročnější úlohy (dlouhé dokumenty, překlady, přehledy) se budou platit předplacenými AI kredity. Asistent neposkytuje právní rady a neodpovídá na dotazy mimo podklady domu. Každý výstup je návrh, který před zveřejněním nebo odesláním schvaluje člověk.',
        // Kde všude může AI na webu pomoci: skupina => [[kde, co udělá, k čemu to je, stav]]. Stav 'hotovo' = už na webu funguje.
        'areas' => [
            'Co už dnes funguje' => [
                ['Aktuality a e-maily', 'Z pár poznámek napíše oznámení, uhladí nebo zkrátí hotový text.', 'Oznámení je venku za pět minut a čte se dobře i na telefonu.', 'hotovo'],
                ['Závady a požadavky', 'Navrhne odpověď obyvateli na jeho hlášení.', 'Nikdo nečeká týden na „přijali jsme, řešíme“.', 'hotovo'],
                ['Zápis ze shromáždění', 'Z poznámek nebo nahraného přepisu sestaví průběh jednání podle programu.', 'Zápis je hotový týž večer, ne za měsíc.', 'hotovo'],
            ],
            'Pro obyvatele' => [
                ['Dotazy na dům', 'Odpoví podle stanov, domovního řádu a oznámení a uvede zdroj.', 'Výbor přestane odpovídat na stejné otázky pořád dokola.', ''],
                ['Vyúčtování a platby', 'Vysvětlí vlastníkovi jeho vyúčtování a předpis záloh běžnou řečí.', 'Méně telefonátů „proč mám nedoplatek“.', ''],
                ['Hlášení závady', 'Z popisu a fotky pomůže hlášení doplnit (kde, od kdy, co hrozí) a upozorní, že totéž už někdo nahlásil.', 'Výbor dostane hlášení, se kterým jde hned něco dělat.', ''],
                ['Jazyky', 'Přeloží oznámení a odpovědi do jazyků webu pro nájemníky, kteří česky nečtou.', 'O odstávce vody se dozví všichni v domě.', ''],
            ],
            'Pro výbor' => [
                ['Závady a požadavky', 'Roztřídí nová hlášení podle naléhavosti a druhu a navrhne dodavatele z Vašeho seznamu.', 'Havárie nezapadne mezi stížnostmi na úklid.', ''],
                ['Dokumenty', 'Po nahrání navrhne název, zařazení a krátké shrnutí pro sousedy; v dokumentech pak hledá otázkou, ne podle názvu souboru.', 'Smlouva nebo zápis z roku 2019 se najde za pár vteřin.', ''],
                ['Shromáždění', 'Připraví pozvánku s programem a návrhy usnesení a po schůzi vytáhne ze zápisu úkoly s termíny do Úkolů a usnesení.', 'Nic z toho, co se odhlasovalo, se neztratí.', ''],
                ['Hlasování a ankety', 'Upozorní na nejednoznačnou otázku, navrhne srozumitelnější znění a shrne volné odpovědi z ankety.', 'Méně sporů o to, o čem se vlastně hlasovalo.', ''],
                ['Revize a smlouvy', 'Z nahrané revizní zprávy nebo smlouvy vyčte termín příští revize, zjištěné závady a výpovědní lhůtu a nabídne je zapsat do Revizí.', 'Termíny se hlídají samy, i když se výbor vymění.', ''],
                ['Dodavatelé', 'Z popisu závady sepíše poptávku a došlé nabídky srovná do přehledné tabulky.', 'Výběr dodavatele je doložitelný a rychlý.', ''],
                ['Dlužníci', 'Navrhne slušnou, ale jasnou upomínku podle výše a stáří dluhu.', 'Nepříjemný dopis nemusí nikdo vymýšlet.', ''],
                ['Diskuze', 'Shrne dlouhé vlákno do pár vět a upozorní správce na vulgární nebo urážlivý příspěvek.', 'Výbor ví, co lidi trápí, aniž čte všechno.', ''],
                ['Měsíční přehled', 'Jednou za měsíc pošle výboru shrnutí: otevřené závady, blížící se revize, nesplněné úkoly, neuhrazené zálohy.', 'Předseda má dům na jedné stránce.', ''],
                ['Nový správce webu', 'Poradí s ovládáním webu („jak přidám nájemníka“, „jak vyhlásím hlasování“) a pomůže roztřídit obsah převedený ze starého webu.', 'Web zvládne i ten, kdo ho předtím neviděl.', ''],
            ],
        ],
    ],
    'napojeni-ai' => [
        'lead' => 'Web společenství si připojíte k AI nástroji, který už používáte, a pracujete s ním na dálku běžnou řečí.',
        'problem' => 'Kdo ve výboru používá AI asistenta, dnes mezi ním a webem přepisuje ručně: zkopíruje hlášené závady, nechá si napsat odpověď a tu zase vloží zpátky. Přehled o tom, co v domě hoří, si musí proklikat stránku po stránce.',
        'how' => ['Uživatel si ve svém účtu vytvoří osobní přístup pro AI nástroj; má přesně oprávnění jeho účtu, nic navíc. Vlastník tak vidí jen to, co vidí na webu, správce i administraci.', 'V AI nástroji se pak zeptá třeba „které závady jsou otevřené déle než měsíc“ nebo „co je na programu příštího shromáždění“.', 'Asistent umí připravit koncept aktuality, e-mailu nebo odpovědi na závadu. Zveřejnění a odeslání vždy potvrzuje člověk na webu.', 'Vše, co asistent přečte nebo připraví, je vidět v Záznamech. Přístup lze kdykoli zrušit a výbor může funkci pro celý web vypnout.'],
        // Příklady zadání: skupina => [zadání, co se stane]
        'examples' => [
            'Pro správce a výbor' => [
                ['Které závady jsou otevřené déle než měsíc a kdo je nahlásil?', 'Vypíše je seřazené podle stáří, s odkazem na každou.'],
                ['Připrav aktualitu: ve čtvrtek 12. 12. od 8 do 14 hodin nepoteče teplá voda kvůli výměně stoupaček.', 'Vytvoří koncept aktuality; zveřejníte ho na webu jedním kliknutím.'],
                ['Navrhni odpověď paní z bytu 2/14 na hlášení o kapajícím radiátoru – technik přijde v úterý.', 'Připraví koncept odpovědi k jejímu hlášení.'],
                ['Co je na programu příštího shromáždění a kolik lidí zatím potvrdilo účast?', 'Shrne program a stav přihlášek podle podílů.'],
                ['Kterým revizím končí platnost v příštích třech měsících?', 'Vypíše revize s termínem a dodavatelem.'],
                ['Které úkoly ze shromáždění nejsou splněné a komu jsou přidělené?', 'Vypíše úkoly s termínem a odpovědnou osobou.'],
                ['Kdo z vlastníků ještě nemá na webu účet?', 'Vypíše jednotky bez aktivního účtu, aby jim výbor mohl poslat pozvánku.'],
                ['Najdi v dokumentech smlouvu s úklidovou firmou a řekni mi výpovědní lhůtu.', 'Najde dokument a vypíše, co v něm o lhůtě stojí, s odkazem.'],
                ['Shrň, o čem se poslední dva týdny diskutovalo na fóru.', 'Napíše pár vět o hlavních tématech.'],
                ['Připrav e-mail všem vlastníkům s pozvánkou na shromáždění 20. 1. v 18 hodin ve sklepní místnosti.', 'Vytvoří koncept hromadného e-mailu; odeslání potvrdíte na webu.'],
            ],
            'Pro vlastníky a nájemníky' => [
                ['Kdy je příští shromáždění a co se na něm bude hlasovat?', 'Odpoví podle pozvánky a programu na webu.'],
                ['Jakou mám měsíční zálohu a mám nějaký nedoplatek?', 'Ukáže předpis a stav účtu jeho jednotky.'],
                ['Kdo je předseda výboru a jak ho zastihnu?', 'Odpoví podle kontaktů a funkcí na webu.'],
                ['Co říká domovní řád o vrtání o víkendu?', 'Najde příslušné místo v domovním řádu a uvede zdroj.'],
                ['V jakém stavu je moje hlášení o nefunkčním zvonku?', 'Ukáže stav jeho hlášení a odpověď výboru.'],
                ['Je sušárna v sobotu odpoledne volná? Pokud ano, zarezervuj mi ji.', 'Ukáže volné termíny; rezervaci potvrdí uživatel na webu.'],
                ['Kdy je odečet vodoměrů a jak mám stav nahlásit?', 'Odpoví podle termínů a návodu na webu.'],
            ],
        ],
        'note' => 'Bude v edici Premium; cenu upřesníme. Používá standard MCP, který podporuje řada AI nástrojů. Data, která si asistent přečte, zpracovává AI nástroj, který si uživatel sám zvolil – za jeho výběr odpovídá společenství.',
    ],
    'sms' => [
        'lead' => 'Důležité zprávy dojdou i těm, kdo e-mail nečtou.',
        'problem' => 'Část vlastníků, hlavně starších, e-maily nečte nebo e-mail vůbec nemá. O odstávce vody, havárii nebo termínu shromáždění se tak dozvědí pozdě a výbor jim musí volat nebo obcházet byty.',
        'how' => ['U oznámení, pozvánky nebo havárie zaškrtnete „poslat i SMS“.', 'Zpráva odejde na telefonní čísla, která mají vlastníci a nájemníci na webu uvedená.', 'Vyberete příjemce: celý dům, jeden vchod, nebo jen ty, kdo nemají e-mail.', 'U každé zprávy uvidíte, komu byla doručena.'],
        'note' => 'Doplněk od edice Standard. Platí se podle počtu odeslaných zpráv (asi 1,50 Kč za SMS), bez měsíčního paušálu.',
    ],
    'katastr' => [
        'lead' => 'Web sám pozná, že byt změnil majitele.',
        'problem' => 'Vlastník má změnu nahlásit, ale často to neudělá. Výbor pak posílá pozvánky a předpisy záloh původnímu majiteli, seznam pro hlasování neodpovídá skutečnosti a usnesení se dá kvůli tomu napadnout.',
        'how' => ['Web pravidelně porovnává seznam jednotek s údaji v katastru nemovitostí.', 'Při změně vlastníka nebo podílu upozorní výbor e-mailem a v administraci.', 'Jedním kliknutím změnu převezmete do evidence jednotek, včetně hlasovacích podílů.', 'Před shromážděním dostanete přehled změn od posledního hlasování.'],
        'note' => 'Doplněk od edice Standard, asi 490 Kč ročně – dálkový přístup do katastru je zpoplatněný státem, u velkých domů může být cena vyšší.',
    ],
    'insolvence' => [
        'lead' => 'Upozornění, když se dlužník dostane do insolvence – dokud je čas přihlásit pohledávku.',
        'problem' => 'Na přihlášení pohledávky do insolvenčního řízení má společenství jen dva měsíce od rozhodnutí o úpadku. Když si toho výbor nevšimne, o dlužné zálohy zpravidla přijde a doplatí je ostatní vlastníci.',
        'how' => ['Web denně kontroluje vlastníky jednotek v insolvenčním rejstříku.', 'Při nálezu pošle výboru upozornění s číslem řízení a lhůtou pro přihlášení pohledávky.', 'U jednotky uvidíte stav řízení a dlužnou částku z evidence plateb.', 'Volitelně i hlídání exekucí a zástav zapsaných u jednotky v katastru.'],
        'note' => 'Bude v ceně edice Premium. Insolvenční rejstřík je veřejný, kontrola se týká jen vlastníků Vašich jednotek.',
    ],
    'bankid' => [
        'lead' => 'Hlasující se ověří přes svou banku – stejně jako na úřadech.',
        'problem' => 'U hlasování mimo shromáždění (per rollam) se dá namítnout, že hlasoval někdo jiný než vlastník. U běžných věcí to nevadí, u půjčky, velké rekonstrukce nebo změny stanov ale může spor o platnost stát roky.',
        'how' => ['U vybraného hlasování zapnete ověření bankovní identitou.', 'Vlastník před odesláním hlasu potvrdí totožnost ve svém bankovnictví.', 'K hlasu se uloží záznam o ověření, který obstojí jako důkaz.', 'Ostatní hlasování běží dál bez ověřování, jako dnes.'],
        'note' => 'Doplněk k edici Premium. Platí se podle počtu ověření, asi 15 Kč za jedno (banky účtují jednotky korun za ověření).',
    ],
    'banka' => [
        'lead' => 'Stav záloh a nedoplatků se aktualizuje sám podle účtu společenství.',
        'problem' => 'Dnes musí výbor nebo správce stav plateb do webu nahrávat ručně z tabulky. Údaje proto zastarávají, vlastníci se ptají, zda jejich platba došla, a upomínky chodí i těm, kdo už zaplatili.',
        'how' => ['Web si z banky stahuje výpis z účtu společenství (jen pro čtení, platit z něj nemůže).', 'Platby spáruje s jednotkami podle variabilního symbolu.', 'Vlastník vidí, které zálohy má uhrazené, výbor vidí aktuální nedoplatky.', 'Upomínky odejdou jen těm, kdo opravdu dluží.'],
        'note' => 'Bude v ceně edice Premium. Nejdřív pro banky s veřejným rozhraním (Fio, ČSOB, Česká spořitelna, KB, Raiffeisenbank).',
    ],
];
/** Otisk návštěvníka pro počítání unikátních prokliků; mění se každý den a nedá se z něj zjistit adresa. */
function visitor_hash(): string
{
    return substr(hash('sha256', client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . date('Y-m-d') . '|' . basename((string) (glob(ROOT_STORAGE . '/platform-*.sqlite')[0] ?? ''))), 0, 16);
}
/** Zapíše proklik na detail funkce (roboty nepočítá). */
function feature_view(string $feature, string $tenant = ''): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!isset(PLANNED_FEATURES[$feature]) || $ua === '' || preg_match('~bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless(?!chrome)~i', $ua)) {
        return;
    }
    pq('INSERT INTO feature_views (feature, tenant, visitor, day, at) VALUES (?,?,?,?,?)', [$feature, $tenant, visitor_hash(), date('Y-m-d'), date('Y-m-d H:i:s')]);
}
/** Přehled pro konzoli: prokliky celkem, unikátní návštěvníci (za den), počet společenství a žádosti o zprávu. */
function feature_stats(int $days = 0): array
{
    $out = array_fill_keys(array_keys(PLANNED_FEATURES), ['views' => 0, 'visitors' => 0, 'tenants' => 0, 'notify' => 0, 'last' => '']);
    $since = $days > 0 ? date('Y-m-d', time() - $days * 86400) : '0000-00-00';
    foreach (pq("SELECT feature, COUNT(*) AS views, COUNT(DISTINCT visitor || day) AS visitors, COUNT(DISTINCT NULLIF(tenant, '')) AS tenants, MAX(at) AS last FROM feature_views WHERE day >= ? GROUP BY feature", [$since])->fetchAll() as $r) {
        if (isset($out[$r['feature']])) {
            $out[$r['feature']] = ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors'], 'tenants' => (int) $r['tenants'], 'notify' => 0, 'last' => (string) $r['last']];
        }
    }
    foreach (pq('SELECT feature, COUNT(*) AS n FROM feature_interest WHERE at >= ? GROUP BY feature', [$since . ' 00:00:00'])->fetchAll() as $r) {
        if (isset($out[$r['feature']])) {
            $out[$r['feature']]['notify'] = (int) $r['n'];
        }
    }
    return $out;
}
/** Zapíše zájem; od jednoho společenství (nebo z jedné adresy) se každá funkce počítá jednou. Vrací, zda šlo o nový hlas. */
function interest_add(string $feature, string $tenant, string $contact): bool
{
    if (!isset(PLANNED_FEATURES[$feature])) {
        return false;
    }
    $dup = $tenant !== '' ? pq('SELECT 1 FROM feature_interest WHERE feature = ? AND tenant = ?', [$feature, $tenant])->fetchColumn()
        : pq("SELECT 1 FROM feature_interest WHERE feature = ? AND tenant = '' AND ip = ? AND at >= ?", [$feature, client_ip(), date('Y-m-d H:i:s', time() - 30 * 86400)])->fetchColumn();
    if ($dup) {
        return false;
    }
    pq('INSERT INTO feature_interest (feature, tenant, contact, at, ip) VALUES (?,?,?,?,?)', [$feature, $tenant, mb_substr($contact, 0, 190), date('Y-m-d H:i:s'), client_ip()]);
    return true;
}
function interest_counts(): array
{
    $out = array_fill_keys(array_keys(PLANNED_FEATURES), ['tenants' => 0, 'visitors' => 0]);
    foreach (pq("SELECT feature, SUM(tenant <> '') AS tenants, SUM(tenant = '') AS visitors FROM feature_interest GROUP BY feature")->fetchAll() as $r) {
        if (isset($out[$r['feature']])) {
            $out[$r['feature']] = ['tenants' => (int) $r['tenants'], 'visitors' => (int) $r['visitors']];
        }
    }
    return $out;
}

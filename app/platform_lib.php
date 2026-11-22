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
    return (app_config()['platform']['mode'] ?? '') === 'path';
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
const PLATFORM_PATHS = ['zajem', 'domena-povolena', 'sprava', 'registrace', 'podminky', 'zpracovani-udaju', 'hlaseni', 'assets', 'storage', 'app', 'tools', 'index', 'favicon', 'robots', 'sitemap', 'kontakt', 'cenik', 'api', 'static'];
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

const PLATFORM_TERMS = <<<'TXT'
## 1. Úvodní ustanovení
Tyto podmínky upravují poskytování služby, která společenstvím vlastníků jednotek (dále „zákazník“) umožňuje provozovat vlastní web s částí pro obyvatele domu a s administrací. Poskytovatelem je provozovatel uvedený v záhlaví této stránky.
Smlouva vzniká dokončením registrace, při které osoba jednající za zákazníka potvrdí souhlas s těmito podmínkami. Tato osoba prohlašuje, že je oprávněna za společenství jednat.

## 2. Zkušební doba a cena
Po registraci běží bezplatná zkušební doba v délce uvedené v ceníku. Během ní ani po ní nevzniká povinnost službu dále využívat.
Cena služby a doplňkových služeb je uvedena v ceníku na webu poskytovatele. Platí se předem za zvolené období bankovním převodem na základě zálohové faktury (výzvy k platbě) dostupné v administraci. Po připsání platby vystaví poskytovatel fakturu.
Nic se nestrhává automaticky. Cenu může poskytovatel změnit s účinností od dalšího období; novou cenu oznámí nejméně 30 dní předem.

## 3. Neuhrazení a pozastavení
Není-li služba po skončení zkušební doby nebo zaplaceného období uhrazena, běží web ještě po lhůtu uvedenou v administraci a poté je pozastaven. Správce zákazníka má i po pozastavení přístup ke stránce Předplatné a ke stažení zálohy dat.
Web, který po zkušební době nebyl nikdy uhrazen, poskytovatel po uplynutí lhůty uvedené na webu smaže včetně všech dat. Na chystané smazání předem upozorní e-mailem osobu, která web založila.
Data pozastaveného webu, který už byl někdy uhrazen, poskytovatel uchovává nejméně 6 měsíců od pozastavení; poté je může smazat.

## 4. Práva a povinnosti zákazníka
Zákazník odpovídá za obsah, který na web vloží on nebo jeho uživatelé, a za to, že má k uveřejnění a ke zpracování údajů právní důvod.
Zákazník chrání přístupové údaje svých správců a bez zbytečného odkladu oznámí poskytovateli podezření na jejich zneužití.
Službu není dovoleno používat k rozesílání nevyžádaných sdělení, k šíření protiprávního obsahu ani způsobem, který ohrožuje její provoz.

## 5. Práva a povinnosti poskytovatele
Poskytovatel zajišťuje provoz služby, její zabezpečení a pravidelné zálohování. Krátkodobé odstávky kvůli údržbě oznamuje předem, je-li to možné.
Poskytovatel do obsahu webu zákazníka nenahlíží, ledaže o to zákazník požádá při řešení podpory nebo to vyžaduje odstranění závady či plnění právní povinnosti.
Poskytovatel může službu měnit a rozšiřovat. Podstatné omezení funkcí oznámí předem.

## 6. Odpovědnost
Služba je poskytována v podobě, v jaké je popsána na webu poskytovatele. Poskytovatel neodpovídá za škodu způsobenou obsahem vloženým zákazníkem, nesprávným použitím služby ani výpadky mimo jeho vliv (připojení, služby třetích stran).
Odpovědnost poskytovatele za škodu je omezena částkou, kterou zákazník za službu zaplatil za posledních 12 měsíců. Omezení se neuplatní tam, kde to zákon nepřipouští.

## 7. Ukončení
Zákazník může službu kdykoli přestat používat; smlouva zaniká uplynutím zaplaceného období, není-li uhrazeno další. Před ukončením si může stáhnout úplnou zálohu dat.
Poskytovatel může smlouvu vypovědět, porušuje-li zákazník tyto podmínky závažně nebo opakovaně. Poměrnou část předplatného za nevyužité období v takovém případě nevrací jen tehdy, bylo-li porušení úmyslné.

## 8. Osobní údaje
Při provozu webu zpracovává poskytovatel osobní údaje obyvatel domu jako zpracovatel pro zákazníka, který je jejich správcem. Podmínky upravuje Smlouva o zpracování osobních údajů, která je součástí těchto podmínek.

## 9. Závěrečná ustanovení
Právní vztahy se řídí českým právem. Poskytovatel může podmínky změnit; změnu oznámí správcům zákazníka nejméně 30 dní předem a zákazník ji může odmítnout ukončením služby.
TXT;
const PLATFORM_DPA = <<<'TXT'
## 1. Strany a předmět
Správcem osobních údajů je společenství vlastníků, které si založilo web (dále „správce“). Zpracovatelem je provozovatel služby uvedený v záhlaví této stránky (dále „zpracovatel“).
Tato smlouva podle čl. 28 nařízení (EU) 2016/679 (GDPR) upravuje zpracování osobních údajů, které správce nebo jeho uživatelé do služby vloží.

## 2. Rozsah zpracování
**Účel:** provoz webu společenství, komunikace s vlastníky a obyvateli, organizace shromáždění a hlasování, evidence jednotek a správa domu.
**Subjekty údajů:** vlastníci jednotek, členové jejich domácností, nájemníci, členové výboru a kontaktní osoby dodavatelů.
**Kategorie údajů:** jméno a příjmení, e-mail, telefon, adresa, označení jednotky a spoluvlastnický podíl, údaje o účasti a hlasování, obsah vložených zpráv, hlášení a dokumentů, technické údaje o přihlášení (čas, IP adresa).
**Doba:** po dobu trvání smlouvy o poskytování služby a nejdéle 6 měsíců po jejím skončení.

## 3. Povinnosti zpracovatele
Zpracovatel zpracovává údaje jen na základě doložených pokynů správce, kterými jsou tato smlouva a nastavení provedená správcem ve službě.
Zajišťuje, že osoby s přístupem k údajům jsou vázány mlčenlivostí.
Přijal technická a organizační opatření: šifrovaný přenos, hesla ukládaná jen jako otisk, oddělená databáze každého společenství, řízení přístupů, záznamy o činnosti správců a pravidelné zálohy.
Je správci nápomocen při vyřizování žádostí subjektů údajů a při plnění povinností podle čl. 32–36 GDPR.
Porušení zabezpečení osobních údajů oznámí správci bez zbytečného odkladu poté, co se o něm dozví.
Na žádost poskytne správci informace potřebné k doložení splnění povinností a umožní audit v přiměřeném rozsahu.

## 4. Další zpracovatelé
Správce souhlasí se zapojením dalších zpracovatelů v rozsahu nezbytném pro provoz služby (zejména poskytovatel serverů a služba pro odesílání e-mailů). Zpracovatel je zaváže ke stejné ochraně údajů a správce o zamýšlené změně informuje předem.
Údaje jsou uloženy na serverech v Evropské unii a nepředávají se do třetích zemí.

## 5. Skončení zpracování
Po skončení služby zpracovatel umožní správci stáhnout zálohu dat a poté údaje smaže, nejpozději do 6 měsíců, ledaže právní předpis ukládá jejich další uchování.
TXT;
const PLATFORM_DEFAULTS = [
    'service_name' => 'Web pro SVJ', 'signup_open' => '1', 'demo_auto' => '1', 'delete_days' => '60', 'price_domain' => '999', 'domain_dns' => '', 'ai_free_limit' => '30', 'ai_plus_limit' => '300', 'price_ai' => '990', 'ai_model' => '', 'hosting_note' => '', 'trial_days' => '30', 'grace_days' => '14',
    'period' => 'year', 'price_first' => '1490', 'price_next' => '2390',
    'account' => '', 'recipient' => '', 'contact_email' => '', 'mail_from' => '',
    'op_name' => '', 'op_ico' => '', 'op_dic' => '', 'op_address' => '', 'op_register' => '', 'op_phone' => '',
    'invoice_note' => 'Dodavatel není plátcem DPH.', 'invoice_due_days' => '14',
    'terms' => PLATFORM_TERMS, 'dpa' => PLATFORM_DPA,
    'pricelist' => "Provoz webu a zálohy | v ceně\nPodpora e-mailem | v ceně\nPřevod obsahu ze stávajícího webu | 1 500 Kč jednorázově\nVlastní doména (např. www.svj-vasdum.cz) | 999 Kč / rok\nÚpravy webu na míru | 900 Kč / hod\nŠkolení výboru (online, 1 hodina) | 900 Kč",
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
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'service' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
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
            platform_mail($t['owner_email'], 'AI Plus je zapnutý – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . money_czk((int) $p['amount']) . " za doplněk AI Plus. Do " . cz_date($to) . " má web "
                . $t['name'] . " k dispozici " . pset_int('ai_plus_limit', 0, 100000) . " použití AI pomocníka měsíčně.\nFakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $from, 'to' => $to];
    }
    // Doména se platí na rok dopředu: od dneška, při obnově od konce dosavadního období.
    $from = $domain ? max(date('Y-m-d'), $t['domain_until'] !== '' ? date('Y-m-d', strtotime($t['domain_until'] . ' +1 day')) : '')
        : max(date('Y-m-d', strtotime(billing_state($t)['until'] . ' +1 day')), date('Y-m-d'));
    $to = date('Y-m-d', strtotime($from . ' +' . (int) $p['months'] . ' month -1 day'));
    // Faktura dostane pořadové číslo v roce a otisk údajů dodavatele a odběratele platných v den vystavení.
    $year = date('Y');
    $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), $from, $to, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
    if ($domain) {
        pq('UPDATE tenants SET domain_until = ? WHERE slug = ?', [$to, $t['slug']]);
        if ($t['owner_email'] !== '') {
            platform_mail($t['owner_email'], 'Platba za doménu přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . money_czk((int) $p['amount']) . " za vlastní doménu " . $p['item'] . " (do " . cz_date($to) . ").\n"
                . ($t['domain_status'] === 'active' ? '' : "Doménu teď zprovozníme a dáme Vám vědět.\n") . "Fakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
        }
        return ['from' => $from, 'to' => $to];
    }
    pq("UPDATE tenants SET paid_until = ?, billing = 1, reminded = '' WHERE slug = ?", [$to, $t['slug']]);
    if ($t['owner_email'] !== '') {
        platform_mail($t['owner_email'], 'Platba přijata – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . money_czk((int) $p['amount']) . " (variabilní symbol " . $p['vs'] . ").\nPředplatné webu " . $t['name']
            . " je uhrazeno do " . cz_date($to) . ".\nFakturu č. $no najdete v administraci: " . tenant_url($t, '/admin/predplatne') . "\n\nDěkujeme.");
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
    return 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format($amount, 2, '.', '') . '*CC:CZK' . ($vs !== '' ? '*X-VS:' . $vs : '') . '*MSG:' . $clean($message, 60) . ($recipient !== '' ? '*RN:' . $clean($recipient, 35) : '');
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
        tenant_notify($t, explode(':', $key)[0], $subject, "Dobrý den,\n\n" . $body . "\n\nPlatební údaje včetně QR kódu najdete po přihlášení v administraci: " . $link);
        pq('UPDATE tenants SET reminded = ? WHERE slug = ?', [$key, $t['slug']]);
    }
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
/** Dodavatel a odběratel pro doklad: odběratelem je společenství s údaji ze svého webu. */
function invoice_parties(array $t): array
{
    $s = tenant_settings($t['slug']);
    return ['supplier' => operator(), 'note' => pset('invoice_note'),
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
    return ['item' => match ($p['kind'] ?? 'service') { 'domain' => 'Vlastní doména ' . $p['item'] . ' – provoz webu na této doméně', 'ai' => 'Doplněk AI Plus – ' . $p['item'], default => null }, 'p' => $p, 'paid' => $paid, 'parties' => $parties, 'issued' => $issued, 'due' => date('Y-m-d', strtotime($issued . ' +' . pset_int('invoice_due_days', 0, 90) . ' day')),
        'site' => $t ? preg_replace('~^https?://~', '', rtrim(tenant_url($t), '/')) : $p['tenant'],
        'qr' => !$paid && $iban !== '' ? qr_svg(spayd($iban, (int) $p['amount'], $p['vs'], 'Web SVJ ' . $p['tenant'], pset('recipient')), 'QR platba') : ''];
}
function demo_tenant(): ?array
{
    $r = pq('SELECT * FROM tenants WHERE demo = 1 AND active = 1 LIMIT 1')->fetch();
    return $r ?: null;
}
function demo_create(): void
{
    tenant_create(['slug' => 'demo', 'name' => 'SVJ Zahradní 12', 'title' => 'Zahradní 12', 'tagline' => 'Společenství vlastníků jednotek · ukázkový web', 'full' => 'Společenství vlastníků jednotek Zahradní 12',
        'address' => 'Zahradní 12, Brno', 'unit_prefix' => '12', 'entrances' => 'A,B', 'look' => 'more', 'style' => 'modern', 'seed' => 'demo', 'demo' => 1, 'note' => 'ukázkový web, jen pro čtení']);
}

// ---------- Upozornění společenstvím a automatické mazání nezaplacených zkušebních webů ----------

const NOTICE_KINDS = ['soon' => 'blíží se konec období', 'due' => 'období skončilo', 'locked' => 'web pozastaven', 'del14' => 'web bude smazán (první upozornění)',
    'del3' => 'web bude smazán (poslední upozornění)', 'deleted' => 'web byl smazán', 'domain' => 'končí předplatné vlastní domény', 'cert' => 'končí platnost nahraného certifikátu'];

/** Pošle upozornění kontaktní osobě společenství a zapíše, komu, kdy a zda se ho podařilo odeslat. */
function tenant_notify(array $t, string $kind, string $subject, string $text): bool
{
    $ok = $t['owner_email'] !== '' && platform_mail($t['owner_email'], $subject, $text);
    pq('INSERT INTO tenant_notices (tenant, tenant_name, at, kind, email, sent, subject) VALUES (?,?,?,?,?,?,?)', [$t['slug'], $t['name'], date('Y-m-d H:i:s'), $kind, $t['owner_email'], $ok ? 1 : 0, mb_substr($subject, 0, 200)]);
    return $ok;
}
/** Den, kdy bude web smazán: jen placený web, který po zkušební době nikdy nezaplatil. Jinak null. */
function tenant_delete_date(array $t): ?string
{
    $days = pset_int('delete_days', 0, 3650);
    if ($days === 0 || empty($t['billing']) || !empty($t['demo']) || $t['paid_until'] !== '' || $t['trial_ends'] === '') {
        return null;
    }
    if (pq("SELECT 1 FROM payments WHERE tenant = ? AND status IN ('paid','claimed') LIMIT 1", [$t['slug']])->fetchColumn()) {
        return null;   // platba je nahlášená nebo přijatá – nemazat
    }
    return date('Y-m-d', strtotime($t['trial_ends'] . ' +' . $days . ' day'));
}
/**
 * Smaže weby, kterým po zkušební době uplynula lhůta, a předem na to upozorní: 14 a 3 dny před smazáním.
 * Bez prokazatelně odeslaného upozornění starého aspoň 3 dny se nic nemaže.
 */
function platform_cleanup(): void
{
    $today = date('Y-m-d');
    foreach (pq("SELECT * FROM tenants WHERE billing = 1 AND demo = 0 AND paid_until = '' AND trial_ends <> ''")->fetchAll() as $t) {
        $on = tenant_delete_date($t);
        if ($on === null || $today < date('Y-m-d', strtotime($on . ' -14 day'))) {
            continue;
        }
        $sent = array_column(pq("SELECT kind, MIN(at) AS at FROM tenant_notices WHERE tenant = ? AND sent = 1 AND kind IN ('del14','del3') AND at >= ? GROUP BY kind", [$t['slug'], $t['trial_ends']])->fetchAll(), 'at', 'kind');
        $tried = array_column(pq("SELECT kind, MAX(at) AS at FROM tenant_notices WHERE tenant = ? AND kind IN ('del14','del3') AND at >= ? GROUP BY kind", [$t['slug'], $t['trial_ends']])->fetchAll(), 'at', 'kind');
        $warned = $sent ? min($sent) : null;
        if ($today >= $on && $warned !== null && substr($warned, 0, 10) <= date('Y-m-d', strtotime('-3 day'))) {
            tenant_delete_storage($t['slug']);
            pq('DELETE FROM tenants WHERE slug = ?', [$t['slug']]);
            pq("UPDATE payments SET status = 'cancelled', note = 'web smazán po zkušební době' WHERE tenant = ? AND status = 'pending'", [$t['slug']]);
            pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Automatické smazání společenství',
                $t['slug'] . ' · ' . $t['name'] . ' · zkušební doba skončila ' . $t['trial_ends'] . ', upozorněno ' . substr($warned, 0, 10) . ' na ' . $t['owner_email']]);
            tenant_notify($t, 'deleted', 'Web byl smazán – ' . $t['name'], "Dobrý den,\n\nweb " . $t['name'] . " (" . tenant_address($t['slug']) . ") jsme smazali včetně všech dat, protože po skončení zkušební doby "
                . cz_date($t['trial_ends']) . " nebyl uhrazen.\n\nKdykoli si můžete založit nový: " . platform_url('/registrace'));
            continue;
        }
        // Které upozornění je na řadě; každé se zkouší nejvýše jednou denně.
        $kind = $today >= date('Y-m-d', strtotime($on . ' -3 day')) && isset($sent['del14']) && substr($sent['del14'], 0, 10) < $today ? 'del3' : 'del14';
        if (isset($sent[$kind]) || substr($tried[$kind] ?? '', 0, 10) === $today) {
            continue;
        }
        $when = max($on, date('Y-m-d', strtotime('+3 day')));
        tenant_notify($t, $kind, ($kind === 'del3' ? 'Poslední upozornění: web' : 'Web') . ' bude smazán – ' . $t['name'],
            "Dobrý den,\n\nzkušební doba webu " . $t['name'] . " skončila " . cz_date($t['trial_ends']) . " a provoz nebyl uhrazen.\nPokud platbu neobdržíme, web včetně všech dat smažeme, nejdříve "
            . cz_date($when) . ".\n\nChcete web zachovat? Platební údaje najdete po přihlášení: " . tenant_url($t, '/admin/predplatne')
            . "\nChcete si jen odnést data? Zálohu stáhnete zde: " . tenant_url($t, '/admin/zaloha') . "\n\nPokud web nepotřebujete, nemusíte dělat nic.");
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
    pq("INSERT INTO payments (tenant, amount, months, created_at, kind, item) VALUES (?,?,12,?,'domain',?)", [$t['slug'], pset_int('price_domain', 0, 1000000), date('Y-m-d H:i:s'), $domain]);
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
            . ".\nPlatbu na další rok (" . money_czk((int) $p['amount']) . ", variabilní symbol " . $p['vs'] . ") najdete včetně QR kódu v administraci: " . tenant_url($t, '/admin/predplatne')
            . "\n\nBez úhrady web dál poběží na adrese " . tenant_address($t['slug']) . ", vlastní doménu ale po skončení období odpojíme.");
    }
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
    pq("INSERT INTO payments (tenant, amount, months, created_at, kind, item) VALUES (?,?,12,?,'ai',?)", [$t['slug'], pset_int('price_ai', 0, 1000000), date('Y-m-d H:i:s'), pset_int('ai_plus_limit', 0, 100000) . ' použití měsíčně']);
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
const PLANNED_FEATURES = [
    'ai-zapis-nahravka' => ['Zápis ze shromáždění z nahrávky', 'Nahrajete zvukový záznam schůze a AI z něj připraví zápis. (Zápis z poznámek a z nahraného přepisu už funguje.)', 'doplněk'],
    'ai-asistent' => ['AI asistent pro obyvatele', 'Odpovídá sousedům na dotazy podle stanov, domovního řádu a oznámení výboru – kdykoli, i v noci.', 'doplněk'],
    'sms' => ['SMS upozornění', 'Důležitá oznámení a pozvánky i vlastníkům, kteří nečtou e-mail.', 'podle počtu zpráv'],
    'katastr' => ['Hlídání změn v katastru', 'Web sám pozná, že se u jednotky změnil vlastník, a upozorní výbor.', 'doplněk'],
    'insolvence' => ['Hlídání insolvencí a exekucí', 'Upozornění, když se vlastník ocitne v insolvenčním rejstříku, aby výbor stihl přihlásit pohledávku.', 'doplněk'],
    'push' => ['Upozornění do telefonu', 'Nové oznámení nebo připomenutí hlasování přímo na displej, bez e-mailu.', 'v ceně'],
    'bankid' => ['Hlasování s bankovní identitou', 'Ověření hlasujícího přes banku pro rozhodnutí, kde záleží na průkaznosti.', 'za hlasování'],
    'banka' => ['Párování plateb s bankou', 'Stav záloh a nedoplatků se aktualizuje sám podle výpisu z účtu společenství.', 'doplněk'],
];
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

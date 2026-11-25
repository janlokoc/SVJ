<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');
putenv('TZ=Europe/Prague');   // stejný čas používá i SQLite pro výchozí hodnoty sloupců, ať je server v jakémkoli pásmu

// Kořen úložiště. Při provozu pro více SVJ má každé společenství vlastní podsložku (data, databázi i soubory zcela oddělené).
// Proměnná prostředí SVJ_STORAGE dovolí spustit web nad jinou složkou – používá ji ověřování návodů (tools/napoveda),
// aby nikdy nesáhlo na skutečná data.
define('ROOT_STORAGE', (($env = getenv('SVJ_STORAGE')) && is_dir($env)) ? rtrim($env, '/') : __DIR__ . '/../storage');

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
const STYLES = ['klasik' => 'Klasická – patkové nadpisy, klidné karty', 'modern' => 'Moderní – bezpatkové písmo, vzdušné plochy', 'vecna' => 'Věcná – rovné hrany, barevná hlavička',
    // Edice Premium
    'noviny' => 'Novinová – linky místo karet, název uprostřed', 'vlidna' => 'Vlídná – velké oblé tvary, měkké plochy',
    'cedule' => 'Cedule – zúžené nápisy, hlavička jako smaltovaná cedule', 'panelak' => 'Panelák – široké nápisy, mřížka a silné linky',
    'cinzak' => 'Činžák – patkové nápisy uprostřed, dvojité rámečky', 'citelna' => 'Čitelná – větší písmo a kontrast pro starší sousedy',
    'vyveska' => 'Vývěska – aktuality jako lístky na nástěnce'];
/** Šablony a barvy, které patří jen do edice Premium (lze je na 30 dní vyzkoušet). */
const PREMIUM_STYLES = ['noviny', 'vlidna', 'cedule', 'panelak', 'cinzak', 'citelna', 'vyveska'];
const PREMIUM_LOOKS = ['grafit', 'svestka', 'nebe', 'oliva', 'petrolej', 'pulnoc', 'malina'];
const LOOK_COLORS = ['les' => '#1d5446', 'more' => '#1b4f72', 'cihla' => '#8c3b2a', 'grafit' => '#2f3a45', 'svestka' => '#5b2a6e',
    'nebe' => '#225f8a', 'oliva' => '#4a5a24', 'petrolej' => '#0e5a62', 'pulnoc' => '#1e2b5e', 'malina' => '#8b1d4d'];
const LOOKS = ['les' => 'Les – zelená a cihlová', 'more' => 'Moře – modrá a tyrkysová', 'cihla' => 'Cihla – červenohnědá a písková', 'grafit' => 'Grafit – šedá a jantarová', 'svestka' => 'Švestka – fialová a zlatá',
    'nebe' => 'Nebe – azurová a slunečná', 'oliva' => 'Oliva – olivová a levandulová', 'petrolej' => 'Petrolej – petrolejová a měděná', 'pulnoc' => 'Půlnoc – noční modrá a korálová', 'malina' => 'Malina – malinová a mořská'];
/** Šablona, kterou web skutečně použije: Klasická bez edice Standard, prémiové šablony jen v Premium (nebo na zkoušku). */
function site_style_used(): string
{
    $style = site('style');
    if (!isset(STYLES[$style]) || !plan_has('sablony') || (in_array($style, PREMIUM_STYLES, true) && !plan_has('vzhledy-plus'))) {
        return 'klasik';
    }
    return $style;
}
/** Barvy, které web skutečně použije: edice Zdarma má jen Les, prémiové barvy jen Premium (nebo zkouška). */
function site_look_used(): string
{
    $look = site('look');
    if (plan_managed() && !plan_has('sablony')) {
        return 'les';
    }
    return isset(LOOKS[$look]) && (!in_array($look, PREMIUM_LOOKS, true) || plan_has('vzhledy-plus')) ? $look : 'les';
}
/** Vlastní logo se ukazuje od edice Standard; v edici Zdarma web nemá žádné přizpůsobení vzhledu. */
function site_logo_used(): string
{
    return plan_managed() && !plan_has('sablony') ? '' : setting('logo');
}
/** Části webu, které může správce společenství schovat z menu: klíč → [název, adresy pro obyvatele, adresy v administraci]. */
const FEATURES = [
    'aktuality' => ['Aktuality', ['/aktuality'], ['/admin/aktuality']],
    'dokumenty' => ['Dokumenty', ['/dokumenty'], []],
    'kontakty' => ['Kontakty', ['/kontakty'], ['/admin/kontakty']],
    'diskuze' => ['Diskuze', ['/diskuze'], ['/admin/diskuze']],
    'zavady' => ['Závady a požadavky', ['/zavady'], ['/admin/zavady']],
    'hlasovani' => ['Hlasování', ['/hlasovani'], ['/admin/hlasovani']],
    'ankety' => ['Ankety', ['/ankety'], ['/admin/ankety']],
    'platby' => ['Platby a zálohy', ['/platby'], ['/admin/platby']],
    'terminy' => ['Termíny', ['/terminy'], []],
    'zpravy' => ['Zprávy mezi sousedy', ['/zpravy'], []],
    'odecty' => ['Odečty měřidel', ['/odecty'], ['/admin/odecty']],
    'rezervace' => ['Rezervace', ['/rezervace'], ['/admin/rezervace', '/admin/prostory']],
    'asistent' => ['AI asistent', ['/asistent'], []],
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
/** Funkce ve společenství. Je vidět u jména všude na webu; s oprávněním na webu nesouvisí (to určuje role). */
const POSITIONS = ['' => '– bez funkce –', 'predseda_svj' => 'Předseda společenství', 'predseda' => 'Předseda výboru', 'mistopredseda' => 'Místopředseda výboru', 'clen_vyboru' => 'Člen výboru',
    'predseda_kk' => 'Předseda kontrolní komise', 'clen_kk' => 'Člen kontrolní komise', 'spravce_domu' => 'Správce domu'];

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
        schema_v7($pdo, true);
        schema_v8($pdo, true);
        schema_v9($pdo, true);
        schema_v10($pdo, true);
        schema_v11($pdo, true);
        schema_v12($pdo, true);
        schema_v13($pdo, true);
        schema_v14($pdo, true);
        schema_v15($pdo, true);
        schema_v16($pdo, true);
        seed_after($pdo, $tenant);
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
    if ($mysqlVersion < 7) {
        schema_v7($pdo, true);
    }
    if ($mysqlVersion < 8) {
        schema_v8($pdo, true);
    }
    if ($mysqlVersion < 9) {
        schema_v9($pdo, true);
    }
    if ($mysqlVersion < 10) {
        schema_v10($pdo, true);
    }
    if ($mysqlVersion < 11) {
        schema_v11($pdo, true);
    }
    if ($mysqlVersion < 12) {
        schema_v12($pdo, true);
    }
    if ($mysqlVersion < 13) {
        schema_v13($pdo, true);
    }
    if ($mysqlVersion < 14) {
        schema_v14($pdo, true);
    }
    if ($mysqlVersion < 15) {
        schema_v15($pdo, true);
    }
    if ($mysqlVersion < 16) {
        schema_v16($pdo, true);
    }
    return $pdo;
}
/** Šestnáctá verze databáze: text z dokumentů pro hledání (NULL = zatím nepřečteno). */
function schema_v16(PDO $pdo, bool $mysql): void
{
    $pdo->exec('ALTER TABLE documents ADD COLUMN content ' . ($mysql ? 'MEDIUMTEXT NULL' : 'TEXT'));
    $mysql ? $pdo->exec("UPDATE settings SET value = '16' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 16');
}
/** Patnáctá verze databáze: uživatel si volí, zda chce upozornění do telefonu (vedle e-mailu). */
function schema_v15(PDO $pdo, bool $mysql): void
{
    $pdo->exec('ALTER TABLE users ADD COLUMN push_notify ' . ($mysql ? 'TINYINT' : 'INTEGER') . ' NOT NULL DEFAULT 1');
    $mysql ? $pdo->exec("UPDATE settings SET value = '15' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 15');
}
/** Čtrnáctá verze databáze: zařízení přihlášená k upozorněním do telefonu (Web Push). */
function schema_v14(PDO $pdo, bool $mysql): void
{
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pdo->exec('CREATE TABLE push_subs (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", user_id $int NOT NULL, endpoint " . ($mysql ? 'VARCHAR(700) NOT NULL' : 'TEXT NOT NULL')
        . ", p256dh {$s(120)}, auth {$s(40)}, device {$s(120)}, created_at {$s(19)}, last_ok {$s(19)}, fails $int NOT NULL DEFAULT 0)$end");
    $pdo->exec('CREATE INDEX push_subs_user ON push_subs (user_id)');
    $mysql ? $pdo->exec("UPDATE settings SET value = '14' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 14');
}
/** Třináctá verze databáze: u zastoupení na shromáždění je vidět, že zmocněnec předložil plnou moc (papírově). */
function schema_v13(PDO $pdo, bool $mysql): void
{
    $pdo->exec('ALTER TABLE event_presence ADD COLUMN proxy_ok ' . ($mysql ? 'TINYINT' : 'INTEGER') . ' NOT NULL DEFAULT 0');
    $mysql ? $pdo->exec("UPDATE settings SET value = '13' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 13');
}
/** Dvanáctá verze databáze: prezence na shromáždění a nahrané podepsané plné moci. */
function schema_v12(PDO $pdo, bool $mysql): void
{
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pdo->exec("CREATE TABLE event_presence (event_id $int NOT NULL, unit_id $int NOT NULL, mode {$s(10)}, proxy_name {$s(190)}, checked_by $int NULL, checked_at {$s(19)}, PRIMARY KEY (event_id, unit_id))$end");
    $pdo->exec('CREATE TABLE event_proxy_files (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", event_id $int NOT NULL, unit_id $int NOT NULL, filename {$s(80)}, original_name {$s(255)}, size $int NOT NULL DEFAULT 0, uploaded_by $int NULL, uploaded_at {$s(19)})$end");
    $pdo->exec('CREATE INDEX event_proxy_files_unit ON event_proxy_files (event_id, unit_id)');
    $pdo->exec('CREATE TABLE meeting_motions (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", event_id $int NOT NULL, title {$s(300)}, majority {$s(20)}, sort $int NOT NULL DEFAULT 0, status {$s(10)}, result " . ($mysql ? 'TEXT NULL' : 'TEXT') . ", closed_at {$s(19)})$end");
    $pdo->exec("CREATE TABLE meeting_votes (motion_id $int NOT NULL, unit_id $int NOT NULL, choice {$s(10)}, PRIMARY KEY (motion_id, unit_id))$end");
    $mysql ? $pdo->exec("UPDATE settings SET value = '12' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 12');
}
/** Jedenáctá verze databáze: u odečtu je vidět, odkud přišel (ruční zadání, import souboru, automatický příjem). */
function schema_v11(PDO $pdo, bool $mysql): void
{
    $pdo->exec('ALTER TABLE readings ADD COLUMN source ' . ($mysql ? "VARCHAR(10) NOT NULL DEFAULT 'manual'" : "TEXT NOT NULL DEFAULT 'manual'"));
    $mysql ? $pdo->exec("UPDATE settings SET value = '11' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 11');
}
/** Desátá verze databáze: žádosti obyvatel o přiřazení k jednotce (přes odkaz od výboru, schvaluje správce). */
function schema_v10(PDO $pdo, bool $mysql): void
{
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pdo->exec('CREATE TABLE unit_requests (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", name {$s(120)}, email {$s(190)}, phone {$s(40)}, kind {$s(10)}, unit_id $int NOT NULL, note {$s(500)}, status {$s(10)},"
        . " created_at {$s(19)}, decided_at {$s(19)}, decided_by $int NULL, decision_note {$s(300)}, user_id $int NULL, ip {$s(45)})$end");
    $pdo->exec('CREATE INDEX unit_requests_status ON unit_requests (status)');
    $mysql ? $pdo->exec("UPDATE settings SET value = '10' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 10');
}
/** Devátá verze databáze: přílohy (fotografie, dokumenty) u nahlášených závad. */
function schema_v9(PDO $pdo, bool $mysql): void
{
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec('CREATE TABLE ticket_files (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ', ticket_id ' . ($mysql ? 'INT' : 'INTEGER') . ' NOT NULL, filename '
        . ($mysql ? 'VARCHAR(80) NOT NULL' : 'TEXT NOT NULL') . ', original_name ' . ($mysql ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''") . ', size ' . ($mysql ? 'INT' : 'INTEGER')
        . " NOT NULL DEFAULT 0, FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE)$end");
    $mysql ? $pdo->exec("UPDATE settings SET value = '9' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 9');
}
/** Přílohy závad seskupené podle čísla závady. */
function ticket_files(array $ticketIds): array
{
    $out = [];
    if ($ticketIds) {
        foreach (all('SELECT * FROM ticket_files WHERE ticket_id IN (' . implode(',', array_map('intval', $ticketIds)) . ') ORDER BY id') as $f) {
            $out[(int) $f['ticket_id']][] = $f;
        }
    }
    return $out;
}
/** Osmá verze databáze: funkce ve společenství (výbor, kontrolní komise) a zprávy mezi účty. */
function schema_v8(PDO $pdo, bool $mysql): void
{
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $int = $mysql ? 'INT' : 'INTEGER';
    foreach ([
        'ALTER TABLE users ADD COLUMN position ' . ($mysql ? "VARCHAR(30) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''"),
        "ALTER TABLE users ADD COLUMN chat_off $int NOT NULL DEFAULT 0",
        'CREATE TABLE messages (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", from_id $int NOT NULL, to_id $int NOT NULL, body TEXT NOT NULL, created_at "
            . ($mysql ? 'VARCHAR(19) NOT NULL' : 'TEXT NOT NULL') . ', read_at ' . ($mysql ? 'VARCHAR(19) NULL' : 'TEXT') . ", FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE)$end",
        'CREATE INDEX messages_to ON messages (to_id, read_at)',
        'CREATE INDEX messages_pair ON messages (from_id, to_id)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    $mysql ? $pdo->exec("UPDATE settings SET value = '8' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 8');
}
/** Sedmá verze databáze: ankety, úkoly výboru, dodavatelé, platby na jednotku a dodavatel u závady. */
function schema_v7(PDO $pdo, bool $mysql): void
{
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $int = $mysql ? 'INT' : 'INTEGER';
    $statements = [
        "CREATE TABLE surveys (id $id, title {$s(255)}, body $text, closes_at {$s(10)}, anonymous $int NOT NULL DEFAULT 1, published $int NOT NULL DEFAULT 1, created_at {$s(19)})$end",
        "CREATE TABLE survey_options (id $id, survey_id $int NOT NULL, label {$s(255)}, sort $int NOT NULL DEFAULT 0, FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE)$end",
        "CREATE TABLE survey_answers (survey_id $int NOT NULL, user_id $int NOT NULL, option_id $int NOT NULL, answered_at {$s(19)}, PRIMARY KEY (survey_id, user_id),
            FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE, FOREIGN KEY (option_id) REFERENCES survey_options(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$end",
        "CREATE TABLE tasks (id $id, title {$s(255)}, note $text, assignee {$s(191)}, due_date " . ($mysql ? 'DATE NULL' : 'TEXT') . ", done $int NOT NULL DEFAULT 0, source {$s(255)}, created_at {$s(19)})$end",
        "CREATE TABLE suppliers (id $id, name {$s(191)}, field {$s(191)}, phone {$s(60)}, email {$s(191)}, note $text)$end",
        "CREATE TABLE unit_accounts (unit_id $int PRIMARY KEY, prescribed " . ($mysql ? 'DECIMAL(12,2)' : 'REAL') . " NOT NULL DEFAULT 0, balance " . ($mysql ? 'DECIMAL(12,2)' : 'REAL') . " NOT NULL DEFAULT 0, vs {$s(20)},
            as_of {$s(10)}, note {$s(255)}, reminded_at {$s(19)}, FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE)$end",
        'ALTER TABLE tickets ADD COLUMN supplier_id ' . $int . ' NULL',
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    $mysql ? $pdo->exec("UPDATE settings SET value = '7' WHERE `key` = 'schema_version'") : $pdo->exec('PRAGMA user_version = 7');
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
        schema_v7($pdo, false);
        schema_v8($pdo, false);
        schema_v9($pdo, false);
        schema_v10($pdo, false);
        schema_v11($pdo, false);
        schema_v12($pdo, false);
        schema_v13($pdo, false);
        schema_v14($pdo, false);
        schema_v15($pdo, false);
        schema_v16($pdo, false);
        seed_after($pdo, $tenant);
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
    if ($version < 7) {
        schema_v7($pdo, false);
    }
    if ($version < 8) {
        schema_v8($pdo, false);
    }
    if ($version < 9) {
        schema_v9($pdo, false);
    }
    if ($version < 10) {
        schema_v10($pdo, false);
    }
    if ($version < 11) {
        schema_v11($pdo, false);
    }
    if ($version < 12) {
        schema_v12($pdo, false);
    }
    if ($version < 13) {
        schema_v13($pdo, false);
    }
    if ($version < 14) {
        schema_v14($pdo, false);
    }
    if ($version < 15) {
        schema_v15($pdo, false);
    }
    if ($version < 16) {
        schema_v16($pdo, false);
    }
    return $pdo;
}

/** Je do webu přihlášena podpora provozovatele? Pak je web jen pro čtení. */
function support_mode(): bool
{
    return !empty($_SESSION['support']);
}
/** Zapne nebo vypne zámek zápisu přímo v databázi (SQLite; u MySQL hlídá zápisy funkce q). */
function support_lock(bool $on): void
{
    if (db_driver() !== 'mysql') {
        db()->exec('PRAGMA query_only = ' . ($on ? 'ON' : 'OFF'));
    }
}
function q(string $sql, array $params = []): PDOStatement
{
    // Režim podpory: nic se nezapisuje. Výjimkou je jen zápis do záznamů o vstupu a odchodu podpory.
    if (support_mode() && empty($GLOBALS['SUPPORT_AUDIT']) && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql)) {
        return db()->query('SELECT 1 FROM (SELECT 1 AS x) t WHERE 1 = 0');
    }
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
        'full' => 'Společenství vlastníků jednotek Tábor 48', 'unit_prefix' => '48', 'entrances' => 'A,B,C,D', 'look' => 'les', 'style' => 'klasik', 'lang' => 'cs'];
    $v = setting('site_' . $key, "\0");
    return $v === "\0" ? $defaults[$key] : $v;
}
/** Označení vchodů v domě; první prázdná položka znamená „bez vchodu“. */
function entrances(): array
{
    $list = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtoupper(trim($x)), explode(',', site('entrances'))), fn($x) => preg_match('/^[\p{L}\p{N}]{1,5}$/u', $x))));
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
    return ($GLOBALS['ASSET_BASE'] ?? $GLOBALS['BASE'] ?? '') . '/assets/' . $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}
/** Vzhled zvolený návštěvníkem: 'light', 'dark', nebo '' (podle nastavení zařízení). */
function theme(): string
{
    $t = (string) ($_COOKIE['svj_theme'] ?? '');
    return in_array($t, ['light', 'dark'], true) ? $t : '';
}
function theme_set(string $theme): void
{
    $opt = ['expires' => $theme === '' ? time() - 3600 : time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'];
    // Na platformě platí volba pro hlavní adresu i weby všech společenství (tabor.svj.cz).
    $main = function_exists('platform_main_domain') ? platform_main_domain() : '';
    $host = strtolower((string) preg_replace('~:\d+$~', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($main !== '' && str_contains($main, '.') && !filter_var($main, FILTER_VALIDATE_IP) && ($host === $main || str_ends_with($host, '.' . $main))) {
        $opt['domain'] = $main;
    }
    setcookie('svj_theme', $theme, $opt);
}
/** Atribut pro <html>; bez volby se vzhled řídí nastavením zařízení. */
function theme_attr(): string
{
    return theme() !== '' ? ' data-theme="' . theme() . '"' : '';
}
/** Přepínač vzhledu do patičky. */
function theme_switch(): string
{
    $out = '<span class="theme-switch" role="group" aria-label="Vzhled" translate="no">';
    foreach (['svetly' => ['light', 'Světlý'], 'tmavy' => ['dark', 'Tmavý'], 'system' => ['', 'Podle zařízení']] as $slug => [$val, $label]) {
        $out .= '<a href="' . url('/vzhled/' . $slug) . '" rel="nofollow"' . (theme() === $val ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    }
    return $out . '</span>';
}
/** Zkušební prostředí (v storage/config.php: 'env' => 'staging'). */
function is_staging(): bool
{
    return (app_config()['env'] ?? '') === 'staging';
}
/** Pruh nahoře na každé stránce zkušebního prostředí. */
/** Pruh přes celou šířku, dokud je do webu přihlášena podpora provozovatele za některého uživatele. */
function support_bar(): string
{
    if (empty($_SESSION['support']) || !($u = user())) {
        return '';
    }
    return '<div class="support-bar" role="status"><span><strong>Režim podpory – jen pro čtení.</strong> Díváte se jako ' . e($u['name']) . ' (' . e($u['email']) . '). Nic nejde změnit, odeslat ani smazat.</span>'
        . '<form method="post" action="' . url('/odhlaseni') . '">' . csrf_field() . '<button type="submit">Ukončit režim podpory</button></form></div>';
}
function staging_bar(): string
{
    return is_staging() ? '<div class="staging-bar" role="note">Zkušební prostředí – data se mohou kdykoli smazat, e-maily neodcházejí skutečným lidem.</div>' : '';
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
const MONTHS_NOM = ['', 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
/** Měsíc jako období („říjen 2026“, „za říjen 2026“) – na rozdíl od month_name(), která je pro data („3. října“). */
function month_label(string $ym): string
{
    $n = (int) substr($ym, 5, 2);
    return (current_lang() === 'cs' || !page_translatable() ? (MONTHS_NOM[$n] ?? '') : month_name($n)) . ' ' . substr($ym, 0, 4);
}
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
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'alert' => '<path d="M12 3 2 20h20zM12 10v4M12 17.5v.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
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
    if ($limitError = plan_limit_error('storage', (int) ceil(((int) ($f['size'] ?? 0)) / 1048576))) {
        return $limitError;
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
function http_download(string $url, bool $allowHttp = false): ?string
{
    if (!preg_match($allowHttp ? '~^https?://~i' : '~^https://~i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $proto = $allowHttp ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => $proto, CURLOPT_REDIR_PROTOCOLS => $proto, CURLOPT_MAXREDIRS => 5, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; prevod-webu-SVJ)']);
        $data = curl_exec($ch);
        $ok = $data !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok ? $data : null;
    }
    $data = @file_get_contents($url);
    return $data === false ? null : $data;
}
/** Stáhne soubory, které zatím leží jen na starém webu, do úložiště tohoto webu. Vrací [staženo, chyb, zbývá]. */
function import_external_files(int $limit): array
{
    return import_external_files_into(db(), UPLOAD_DIR, $limit);
}
/**
 * Totéž pro libovolnou databázi a složku (konzole platformy stahuje za společenství).
 * Převzatý dokument nesmí zůstat jen odkazem na starý web – ten může kdykoli zaniknout.
 * $seconds omezí, jak dlouho se stahuje v jednom požadavku (0 = bez omezení, jen podle $limit).
 */
function import_external_files_into(PDO $pdo, string $uploadDir, int $limit, int $seconds = 0, bool $retryFailed = false): array
{
    $done = $failed = 0;
    if ($retryFailed) {
        // Výslovně „zkusit znovu“: dříve neúspěšné se vrátí mezi čekající.
        $pdo->exec("UPDATE documents SET external_url = substr(external_url, 8) WHERE external_url LIKE 'failed:%'");
        $pdo->exec("UPDATE documents SET external_url = replace(external_url, 'https-failed://', 'https://') WHERE external_url LIKE 'https-failed://%'");
    }
    $start = time();
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }
    // Neúspěšné pokusy se označí předponou „failed:“ a zkusí se znovu, až nezbude nic nevyzkoušeného.
    $rows = $pdo->query("SELECT id, external_url, original_name FROM documents WHERE filename IS NULL AND (external_url LIKE 'https://%' OR external_url LIKE 'http://%') ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($seconds > 0 && time() - $start >= $seconds) {
            break;
        }
        $url = (string) $r['external_url'];
        $ext = external_doc_ext($r);
        $data = in_array($ext, ALLOWED_EXT, true) ? http_download($url, true) : null;
        if (($data === null || $data === '') && in_array($ext, ALLOWED_EXT, true)) {
            // Některé soubory má původní web uložené pod názvem bez diakritiky, i když na ně odkazuje s diakritikou.
            $cut = strrpos($url, '/') + 1;
            $plain = rawurlencode(strip_accents(rawurldecode(substr($url, $cut))));
            if ($plain !== substr($url, $cut)) {
                $data = http_download(substr($url, 0, $cut) . $plain, true);
            }
        }
        if ($data === null || $data === '') {
            $failed++;
            $pdo->prepare("UPDATE documents SET external_url = ? WHERE id = ?")->execute(['failed:' . $url, $r['id']]);
            continue;
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        file_put_contents($uploadDir . '/' . $name, $data);
        $pdo->prepare('UPDATE documents SET filename = ?, size = ?, external_url = NULL, content = NULL WHERE id = ?')->execute([$name, strlen($data), $r['id']]);
        $done++;
    }
    $untried = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE filename IS NULL AND (external_url LIKE 'https://%' OR external_url LIKE 'http://%')")->fetchColumn();
    // Neúspěšné zůstávají označené („failed:“), aby je konzole vypsala s důvodem; znovu se zkusí jen na výslovné přání.
    return [$done, $failed, $untried];
}
/** Přípona souboru dokumentu – z původního názvu, jinak z adresy. */
function external_doc_ext(array $d): string
{
    $ext = strtolower(pathinfo((string) ($d['original_name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        $url = preg_replace('~^(failed:|https-failed://)~', 'https://', (string) ($d['external_url'] ?? ''));
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }
    return $ext;
}
/** Kolik dokumentů je pořád jen odkazem na cizí web (převod souborů není dokončený). */
function external_doc_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE filename IS NULL AND external_url IS NOT NULL AND external_url <> ''")->fetchColumn();
}
/** Dokumenty, které zůstaly jen odkazem, s důvodem, proč je nejde stáhnout automaticky. */
function external_doc_list(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT id, title, category, original_name, external_url, doc_date FROM documents WHERE filename IS NULL AND external_url IS NOT NULL AND external_url <> '' ORDER BY doc_date DESC, id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $raw = (string) $d['external_url'];
        $url = preg_replace(['~^failed:~', '~^https-failed://~'], ['', 'https://'], $raw);
        $ext = external_doc_ext($d);
        $d['url'] = $url;
        $d['reason'] = !preg_match('~^https?://~i', $url) ? 'Neplatná adresa souboru – soubor je potřeba nahrát ručně.'
            : (!in_array($ext, ALLOWED_EXT, true) ? 'Typ souboru „' . ($ext !== '' ? $ext : 'bez přípony') . '“ web neukládá (povolené: ' . implode(', ', ALLOWED_EXT) . '). Převeďte ho např. do PDF a nahrajte ručně.'
            : (str_starts_with($raw, 'failed:') || str_starts_with($raw, 'https-failed://') ? 'Starý web soubor nevydal (smazaný, přejmenovaný nebo za přihlášením).' : 'Zatím nezkoušeno.'));
        $out[] = $d;
    }
    return $out;
}
function strip_accents(string $s): string
{
    static $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ľ' => 'l', 'ĺ' => 'l', 'ô' => 'o', 'ŕ' => 'r', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'Ľ' => 'L', 'Ô' => 'O'];
    return strtr($s, $map);
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
    $position = position_label(['id' => (int) ($row['author_id'] ?? $row['user_id'] ?? 0)]);
    return $row['author_name'] . (!empty($row['author_units']) ? ' (' . $row['author_units'] . ')' : '') . ($position !== '' ? ' · ' . mb_strtolower($position) : '');
}
/** Funkce člověka ve společenství („Předseda výboru“), nebo prázdný text. Stačí řádek s id; funkce všech se načtou jednou. */
function position_label(array $user): string
{
    static $map = null;
    if (isset($user['position'])) {
        return $user['position'] !== '' ? (POSITIONS[$user['position']] ?? '') : '';
    }
    $map ??= array_column(all("SELECT id, position FROM users WHERE position <> ''"), 'position', 'id');
    $p = $map[(int) ($user['id'] ?? 0)] ?? '';
    return $p !== '' ? (POSITIONS[$p] ?? '') : '';
}
/** Štítek s funkcí za jménem (HTML), nebo nic. */
function position_tag(array $user): string
{
    $label = position_label($user);
    return $label !== '' ? ' <span class="tag tag-position">' . e($label) . '</span>' : '';
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
function send_mail(string $to, string $subject, string $text, ?string $html = null, ?string $from = null, ?string $fromName = null, ?string $replyTo = null): bool
{
    $from ??= setting('mail_from');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    // Zkušební prostředí: e-maily nejdou skutečným lidem, ale na jednu adresu z nastavení (nebo se neodešlou vůbec).
    if (is_staging()) {
        $catch = (string) (app_config()['staging_mail'] ?? '');
        if (!filter_var($catch, FILTER_VALIDATE_EMAIL)) {
            // Bez adresy pro zachytávání se ve zkušebním prostředí nic neodesílá – jen se zapíše, co by odešlo.
            @file_put_contents(ROOT_STORAGE . '/mail-staging.log', date('Y-m-d H:i:s') . "\t$to\t$subject\n", FILE_APPEND);
            $GLOBALS['MAIL_LAST'] = 'staging-off';
            return true;
        }
        $subject = '[STAGING pro ' . $to . '] ' . $subject;
        $to = $catch;
    }
    $name = trim(preg_replace('/[\r\n"<>]+/', ' ', $fromName ?? setting('mail_from_name', site('name'))));
    $subject = trim(preg_replace('/\s+/u', ' ', $subject));
    // Odpovědi: na adresu, kterou výbor nastavil (odesílatel bývá schránka, kterou nikdo nečte).
    $replyTo ??= defined('TENANT') && filter_var(setting('mail_reply_to'), FILTER_VALIDATE_EMAIL) ? setting('mail_reply_to') : $from;
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $from;
    }
    $domain = substr(strrchr($from, '@'), 1);
    $headers = [
        'From' => mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n") . ' <' . $from . '>',
        'Reply-To' => $replyTo,
        'Date' => date('r'),
        // Identifikátor na doméně odesílatele (jinak ho doplní server s cizí doménou, což filtry hodnotí hůř).
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>',
        'MIME-Version' => '1.0',
        'Auto-Submitted' => 'auto-generated',
    ];
    // Text i HTML verze; kódování quoted-printable (u čitelného textu ho filtry hodnotí lépe než base64).
    $plain = quoted_printable_encode(str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $text)));
    $html ??= mail_text_html($text);
    $b = 'svj-' . bin2hex(random_bytes(12));
    $headers['Content-Type'] = 'multipart/alternative; boundary="' . $b . '"';
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$plain\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $html)) . "\r\n--$b--\r\n";
    $msg = ['to' => $to, 'from' => $from, 'subject' => mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"), 'headers' => $headers, 'body' => $body];
    return function_exists('mailq_send') ? mailq_send($msg) : mail_deliver($msg);
}
/** Prostý text e-mailu jako jednoduché HTML (odstavce, řádky, odkazy) – zprávy s oběma verzemi méně padají do spamu. */
function mail_text_html(string $text): string
{
    $html = '';
    foreach (preg_split('/\n\s*\n/u', trim(str_replace(["\r\n", "\r"], "\n", $text))) as $para) {
        $p = nl2br(e($para), false);
        $p = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)])~u', '<a href="$1">$1</a>', $p);
        $html .= '<p style="margin:0 0 14px">' . $p . "</p>\n";
    }
    return '<!doctype html><html lang="cs"><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1b2622">'
        . '<div style="max-width:600px">' . $html . '</div></body></html>';
}
/** Předá hotovou zprávu serveru (SMTP z config.php, jinak PHP mail()). */
function mail_deliver(array $msg): bool
{
    $smtp = app_config()['smtp'] ?? null;
    if (is_array($smtp) && !empty($smtp['host'])) {
        $GLOBALS['MAIL_LAST'] = 'smtp';
        return smtp_send($smtp, $msg['from'], $msg['to'], $msg['subject'], $msg['headers'], $msg['body']);
    }
    $GLOBALS['MAIL_LAST'] = 'mail';
    $params = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $msg['from']) ? '-f' . $msg['from'] : '';
    return @mail($msg['to'], $msg['subject'], $msg['body'], $msg['headers'], $params);
}
/**
 * Odeslání přes SMTP server (Seznam, Gmail, Brevo, hosting…). Konfigurace v storage/config.php:
 * 'smtp' => ['host' => 'smtp.seznam.cz', 'port' => 465, 'secure' => 'ssl', 'user' => '…', 'password' => '…'].
 * secure: 'ssl' (port 465), 'tls' (STARTTLS, port 587) nebo '' (bez šifrování – jen pro místní testovací server).
 */
function smtp_send(array $c, string $from, string $to, string $subject, array $headers, string $body): bool
{
    $secure = strtolower((string) ($c['secure'] ?? ((int) ($c['port'] ?? 587) === 465 ? 'ssl' : 'tls')));
    $port = (int) ($c['port'] ?? ($secure === 'ssl' ? 465 : 587));
    $err = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]));
    if (!$fp) {
        $GLOBALS['MAIL_ERROR'] = 'Nepodařilo se připojit k ' . $c['host'] . ':' . $port . ' (' . $errstr . ').';
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $line, array $ok) use ($fp, $read, &$err): bool {
        if ($line !== '') {
            fwrite($fp, $line . "\r\n");
        }
        $res = $read();
        if (!in_array((int) substr($res, 0, 3), $ok, true)) {
            $err = trim($res) !== '' ? trim(preg_replace('/\s+/', ' ', $res)) : 'server neodpověděl';
            return false;
        }
        return true;
    };
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    $ok = $cmd('', [220]) && $cmd('EHLO ' . $host, [250]);
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', [220]) && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) && $cmd('EHLO ' . $host, [250]);
    }
    if ($ok && !empty($c['user'])) {
        $ok = $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode((string) $c['user']), [334]) && $cmd(base64_encode((string) ($c['password'] ?? '')), [235]);
    }
    $ok = $ok && $cmd('MAIL FROM:<' . $from . '>', [250]) && $cmd('RCPT TO:<' . $to . '>', [250, 251]) && $cmd('DATA', [354]);
    if ($ok) {
        $head = ['Date' => date('r'), 'To' => '<' . $to . '>', 'Subject' => $subject, 'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? $host) . '>'] + $headers;
        $msg = '';
        foreach ($head as $k => $v) {
            $msg .= $k . ': ' . $v . "\r\n";
        }
        $msg .= "\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
        fwrite($fp, $msg . "\r\n.\r\n");
        $ok = $cmd('', [250]);
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if (!$ok) {
        $hint = match (true) {
            str_contains($err, '5.7.9') || str_contains($err, 'InvalidSecondFactor') => ' Účet má dvoufázové ověření: místo běžného hesla vytvořte „heslo aplikace“ (u Googlu: myaccount.google.com/apppasswords) a vložte ho do storage/config.php.',
            str_starts_with($err, '535') || str_contains($err, '5.7.8') => ' Jméno nebo heslo nesedí – zkontrolujte user a password v storage/config.php.',
            default => '',
        };
        $GLOBALS['MAIL_ERROR'] = 'SMTP server odmítl zprávu: ' . mb_substr($err, 0, 200) . $hint;
    }
    return $ok;
}
/** Jak web posílá e-maily – pro poctivou zprávu správci, kam e-mail opravdu odešel. */
function mail_route(): string
{
    if (is_staging()) {
        return filter_var((string) (app_config()['staging_mail'] ?? ''), FILTER_VALIDATE_EMAIL) ? 'staging-catch' : 'staging-off';
    }
    if (!empty(app_config()['smtp']['host'])) {
        return 'smtp';
    }
    // Na vlastním počítači (vestavěný server PHP) funkce mail() zprávu jen předá místnímu programu, který ji obvykle nikam nedoručí.
    // Kdo má na počítači nastavené vlastní doručování (sendmail_path v php.ini), tomu věříme.
    $custom = !in_array(trim((string) ini_get('sendmail_path')), ['', '/usr/sbin/sendmail -t -i', '/usr/sbin/sendmail -t'], true);
    return PHP_SAPI === 'cli-server' && !$custom ? 'local' : 'mail';
}
function mail_route_note(): string
{
    return match (mail_route()) {
        'staging-off' => 'Web běží jako zkušební prostředí bez adresy pro zachytávání e-mailů, takže se nic neodeslalo (jen se zapsalo do storage/mail-staging.log). Doplňte v storage/config.php staging_mail.',
        'staging-catch' => 'Zkušební prostředí: e-mail šel na adresu ' . app_config()['staging_mail'] . ', ne skutečným příjemcům.',
        'smtp' => (($u = strtolower((string) (app_config()['smtp']['user'] ?? ''))) !== '' && str_contains($u, '@') && $u !== strtolower((string) setting('mail_from')))
            ? 'Pozor: přihlašujete se k SMTP jako ' . $u . ', ale odesílatel v Nastavení je ' . setting('mail_from') . '. Gmail, Seznam a další služby odesílatele obvykle přepíšou na přihlašovací účet; odpovědi lidí ale půjdou na ' . setting('mail_from') . '. Pro správného odesílatele použijte SMTP poštovní schránky této adresy.'
            : '',
        'local' => 'Web běží na Vašem počítači bez nastaveného SMTP serveru. Počítač zprávu převzal, ale skutečně doručit ji nejspíš neumí. Pro opravdové odesílání doplňte v storage/config.php údaje SMTP (viz config.example.php).',
        default => '',
    };
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
    $rows = all('SELECT un.*, r.status, r.proxy_name, r.updated_at, u.name responder, f.id AS file_id FROM units un
        LEFT JOIN event_rsvp r ON r.unit_id = un.id AND r.event_id = ?
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN event_proxy_files f ON f.unit_id = un.id AND f.event_id = ? ORDER BY un.entrance, un.number + 0, un.number', [$eventId, $eventId]);
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
    // V režimu podpory se zapisuje jen vstup a odchod podpory; nic jiného se stát nemůže.
    if (support_mode()) {
        if (!in_array($action, ['support_login', 'support_logout'], true)) {
            return;
        }
        $GLOBALS['SUPPORT_AUDIT'] = true;
        support_lock(false);
    }
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
        unset($GLOBALS['SUPPORT_AUDIT']);
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
    // Má-li dům víc čísel popisných, než kolik je nastaveno vchodů (typicky dům, kde se jednotky značí „3024/7“),
    // označí se vchody přímo čísly popisnými a jednotka se jmenuje stejně jako v katastru.
    $byCp = count($cps) > count($letters);
    $total = 0.0;
    foreach ($units as &$u) {
        $u['entrance'] = $byCp ? (string) $u['cp'] : ($letters[array_search($u['cp'], $cps, true)] ?? '');
        $u['share'] = $u['num'] . '/' . $u['den'];
        $u['owner_contact'] = mb_substr(implode("\n", array_map(fn($o) => $o['contact'], $u['owners'])), 0, 2000);
        $u['owner_note'] = mb_substr(implode('; ', array_map(fn($o) => $o['name'] . (count($u['owners']) > 1 ? ' (' . $o['share'] . ')' : ''), $u['owners'])), 0, 255);
        $total += $u['num'] / $u['den'];
    }
    unset($u);
    uasort($units, fn($a, $b) => [$a['cp'], (int) $a['number']] <=> [$b['cp'], (int) $b['number']]);
    return ['units' => $units, 'warnings' => $warnings, 'total' => $total, 'by_cp' => $byCp ? array_map('strval', $cps) : []];
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
    '/admin/ankety/nova' => ['Nová anketa', null, null],
    '/admin/ankety/(\d+)' => ['Úprava ankety', 'surveys', 'title'],
    '/admin/ankety/(\d+)/smazat' => ['Smazání ankety', 'surveys', 'title'],
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
    $position = position_label($u);
    return ($position !== '' ? mb_strtolower($position) : mb_strtolower(KINDS[$u['kind']] ?? '')) . ($units !== '' ? ' · ' . $units : '');
}

// Části aplikace, které knihovna potřebuje vždy (i v nástrojích z příkazové řádky).
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/platform_lib.php';
require_once __DIR__ . '/plan_lib.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/reports_lib.php';
require_once __DIR__ . '/ai_lib.php';
require_once __DIR__ . '/meters_lib.php';
require_once __DIR__ . '/push_lib.php';
require_once __DIR__ . '/search_lib.php';
require_once __DIR__ . '/assistant_lib.php';
require_once __DIR__ . '/extra_lib.php';
require_once __DIR__ . '/ares_lib.php';
require_once __DIR__ . '/mailq_lib.php';
/** Oslovení křestním jménem v 5. pádě (Jan → Jane, Jana → Jano, Tomáš → Tomáši). Neznámé tvary nechá beze změny. */
function cz_vocative(string $name): string
{
    $n = trim($name);
    if ($n === '' || !preg_match('/^\p{Lu}\p{Ll}+$/u', $n)) {
        return $n;
    }
    $fixed = ['Pavel' => 'Pavle', 'Karel' => 'Karle', 'Petr' => 'Petře', 'Alexandr' => 'Alexandře', 'Kryštof' => 'Kryštofe', 'Leoš' => 'Leoši', 'Zdeněk' => 'Zdeňku', 'Hynek' => 'Hynku', 'Daniel' => 'Danieli', 'Gabriel' => 'Gabrieli', 'Michael' => 'Michaeli', 'Rafael' => 'Rafaeli', 'Samuel' => 'Samueli', 'Emanuel' => 'Emanueli', 'Ivo' => 'Ivo', 'Hugo' => 'Hugo', 'Oto' => 'Oto'];
    if (isset($fixed[$n])) {
        return $fixed[$n];
    }
    // Ženská jména zakončená souhláskou se neskloňují.
    if (in_array($n, ['Dagmar', 'Ester', 'Karin', 'Miriam', 'Ingrid', 'Carmen', 'Nikol', 'Ivet', 'Rút', 'Edit', 'Judit', 'Elizabet', 'Margit', 'Doris', 'Iris', 'Agnes', 'Kim'], true)) {
        return $n;
    }
    $last = mb_substr($n, -1);
    $last2 = mb_substr($n, -2);
    return match (true) {
        $last === 'a' => mb_substr($n, 0, -1) . 'o',                                   // Jana → Jano
        $last2 === 'ek' && mb_strlen($n) > 3 => mb_substr($n, 0, -2) . 'ku',           // Marek → Marku
        $last2 === 'ch' => $n . 'u',                                                   // Vojtěch → Vojtěchu
        in_array($last, ['k', 'h', 'g'], true) => $n . 'u',                            // Dominik → Dominiku
        in_array($last, ['š', 'ž', 'č', 'ř', 'c', 'j', 'x', 's', 'z'], true) => $n . 'i', // Tomáš → Tomáši, Matěj → Matěji
        $last === 'r' && preg_match('/[^aeiouyáéíóúůý]r$/u', $n) => mb_substr($n, 0, -1) . 'ře',
        (bool) preg_match('/[bdfjlmnprtv]$/u', $n) => $n . 'e',                         // Jan → Jane, Lukáš… Michal → Michale
        default => $n,                                                                 // Marie, Lucie, Jiří, Ivo
    };
}

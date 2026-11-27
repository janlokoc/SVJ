<?php
declare(strict_types=1);

/*
 * Odpovědi e-mailem: odpověď na upozornění se připojí ke správnému vláknu.
 *
 * - Hlášení provozovateli (H): odpověď autora na e-mail s odpovědí podpory → doplnění ve vlákně (jako „Moje hlášení“).
 * - Závady a požadavky (Z): odpověď obyvatele na zprávu výboru → odpověď obyvatele (jako /zavady/{id}/odpoved).
 * - Závady a požadavky (V): odpověď správce domu na upozornění o hlášení → zpráva obyvateli (jen aktivní správce domu).
 * - Inzeráty (I): odpověď zájemce na zprávu vlastníka bytu → zpráva ve vlákně zájemce (jen z e-mailu, který zájemce zadal).
 *
 * Jedna schránka pro celou službu, v storage/config.php:
 *   'inbound' => ['host' => 'imap.example.cz', 'port' => 993, 'secure' => 'ssl', 'user' => 'odpovedi@…', 'password' => '…',
 *                 'address' => 'odpovedi@…', 'folder' => 'INBOX', 'keep_days' => 30],
 * Volitelné: 'plus' => true (Reply-To odpovedi+značka@… – jen když hosting plus-adresy doručuje), 'max_per_run' => 20,
 * 'secret' => '…' (jinak se klíč pro značky založí sám v storage/inbound/secret.key), 'enabled' => false (vypnout).
 * Bez sekce inbound je vše vypnuté a e-maily vypadají jako dřív. Zkušební prostředí ('env' => 'staging') schránku
 * použije jen s 'staging_ok' => true – staging tak nikdy nezpracuje produkční schránku omylem.
 *
 * Značka vlákna (např. „#Z45.dum.0123456789abcdef“) je v předmětu i v patičce e-mailu: druh, číslo vlákna, dům
 * a HMAC přes (druh, dům, číslo, e-mail příjemce) tajným klíčem. Odpověď se připojí, jen když značka sedí na adresu
 * odesílatele (From) a ten k vláknu stále má přístup. Automatické odpovědi a nedoručenky se ignorují, nikomu se
 * automaticky neodpovídá. Zpracované zprávy jdou do složky Zpracovano, odmítnuté do Nezpracovano; obojí se po
 * keep_days dnech maže (0 = zpracované hned). Do vlákna se ukládá jen vytažený text (a přílohy).
 *
 * Běh: webcron /cron/<klíč> (inbound_run) a tlačítko v konzoli. Odpovědi k závadám se ukládají v kontextu webu
 * domu: běh je zařadí do fronty a dům je převezme (inbound_apply_pending) – hned přes /cron/prichozi/<klíč>,
 * případně při nejbližší návštěvě webu.
 */

const INBOUND_KINDS = ['H' => 'hlášení provozovateli', 'Z' => 'závada – odpověď obyvatele', 'V' => 'závada – odpověď správce', 'I' => 'inzerát – odpověď zájemce'];
const INBOUND_STATUS = ['attached' => 'připojeno', 'queued' => 'čeká na web domu', 'rejected' => 'odmítnuto', 'ignored' => 'automatická zpráva', 'error' => 'chyba'];
const INBOUND_DONE_FOLDER = 'Zpracovano';
const INBOUND_FAILED_FOLDER = 'Nezpracovano';
/** Nejdelší uložený text odpovědi (stejně jako na webu). */
const INBOUND_MAX_TEXT = 5000;

// ---------- Nastavení ----------

/** Nastavení schránky, nebo null (vypnuto). Důvod vypnutí vrací inbound_off_reason(). */
function inbound_config(): ?array
{
    return inbound_off_reason() === '' ? inbound_raw_config() : null;
}
/** Sekce inbound z config.php (testy ji podstrčí přes $GLOBALS['INBOUND_CONFIG']). */
function inbound_raw_config(): array
{
    $c = $GLOBALS['INBOUND_CONFIG'] ?? (app_config()['inbound'] ?? []);
    return is_array($c) ? $c : [];
}
function inbound_off_reason(): string
{
    $c = inbound_raw_config();
    if ($c === []) {
        return 'Schránka pro odpovědi není nastavená (sekce inbound v storage/config.php).';
    }
    if (array_key_exists('enabled', $c) && !$c['enabled']) {
        return 'Příjem odpovědí je vypnutý (inbound.enabled = false).';
    }
    if (($GLOBALS['INBOUND_STAGING'] ?? is_staging()) && empty($c['staging_ok'])) {
        return 'Zkušební prostředí: schránka se použije jen s inbound.staging_ok = true (ochrana před zpracováním produkční schránky).';
    }
    foreach (['host', 'user', 'password'] as $k) {
        if (trim((string) ($c[$k] ?? '')) === '') {
            return 'V nastavení inbound chybí ' . $k . '.';
        }
    }
    if (!filter_var((string) ($c['address'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return 'V nastavení inbound chybí platná adresa (address).';
    }
    return '';
}
function inbound_on(): bool
{
    return inbound_config() !== null;
}
function inbound_dir(): string
{
    $dir = ROOT_STORAGE . '/inbound';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/index.html', '');
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}
/** Tajný klíč pro značky vláken: inbound.secret z config.php, jinak vygenerovaný soubor (mimo repozitář). */
function inbound_secret(): string
{
    $s = (string) (inbound_raw_config()['secret'] ?? '');
    if (strlen($s) >= 32) {
        return $s;
    }
    $f = inbound_dir() . '/secret.key';
    if (!is_file($f)) {
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, bin2hex(random_bytes(32)));
        chmod($tmp, 0600);
        @rename($tmp, $f);   // při souběhu vyhraje první, oba pak čtou totéž
        @unlink($tmp);
    }
    return trim((string) file_get_contents($f));
}
function inbound_keep_days(): int
{
    return max(0, min(365, (int) (inbound_raw_config()['keep_days'] ?? 30)));
}

// ---------- Značka vlákna ----------

function inbound_mac(string $kind, string $tenant, int $id, string $email): string
{
    return substr(hash_hmac('sha256', 'inbound|' . $kind . '|' . $tenant . '|' . $id . '|' . strtolower(trim($email)), inbound_secret()), 0, 16);
}
/** Značka vlákna pro jednoho příjemce, např. „Z45.dum.0123456789abcdef“ (u hlášení provozovateli bez domu: „H12.0123…“). */
function inbound_token(string $kind, string $tenant, int $id, string $email): string
{
    return $kind . $id . ($tenant !== '' ? '.' . $tenant : '') . '.' . inbound_mac($kind, $tenant, $id, $email);
}
/** Všechny značky nalezené v textu (v pořadí výskytu): [['kind', 'id', 'tenant', 'mac', 'token'], …]. */
function inbound_tokens_in(string $s): array
{
    preg_match_all('/(?<![A-Za-z0-9])([HZVI])(\d{1,10})(?:\.([a-z0-9][a-z0-9-]{0,38}))?\.([0-9a-f]{16})(?![0-9a-f])/i', $s, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $t = ['kind' => strtoupper($x[1]), 'id' => (int) $x[2], 'tenant' => strtolower($x[3] ?? ''), 'mac' => strtolower($x[4]), 'token' => $x[0]];
        $out[$t['kind'] . $t['id'] . '.' . $t['tenant'] . '.' . $t['mac']] = $t;
    }
    return array_values($out);
}
/** Platí značka pro tohoto odesílatele? (porovnání v konstantním čase) */
function inbound_token_valid(array $t, string $email): bool
{
    return isset(INBOUND_KINDS[$t['kind']]) && $email !== '' && hash_equals(inbound_mac($t['kind'], $t['tenant'], (int) $t['id'], $email), (string) $t['mac']);
}
/**
 * Doplňky odchozího e-mailu, na který jde odpovědět: ['tag' => '[#…]' do předmětu, 'line' => řádek se značkou do patičky,
 * 'reply_to' => adresa schránky]. Null, když příjem odpovědí není zapnutý nebo adresa příjemce není platná.
 */
function inbound_reply(string $kind, string $tenant, int $id, string $email): ?array
{
    $c = inbound_config();
    if (!$c || !filter_var($email, FILTER_VALIDATE_EMAIL) || $id <= 0 || !isset(INBOUND_KINDS[$kind])) {
        return null;
    }
    $token = inbound_token($kind, $tenant, $id, $email);
    $address = strtolower((string) $c['address']);
    if (!empty($c['plus'])) {
        [$local, $domain] = explode('@', $address, 2);
        $address = $local . '+' . strtolower($token) . '@' . $domain;
    }
    return ['tag' => '[#' . $token . ']', 'line' => 'Značka vlákna (při odpovědi ji prosím ponechte): #' . $token, 'reply_to' => $address, 'token' => $token];
}

// ---------- Úložiště: záznam o zpracování a fronta pro weby domů ----------

function inbound_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $found = glob(inbound_dir() . '/inbound-*.sqlite');
        // Náhodný název souboru jako u ostatních databází (adresa se nedá uhodnout).
        $file = $found ? $found[0] : inbound_dir() . '/inbound-' . bin2hex(random_bytes(12)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS inbound_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, message_id TEXT NOT NULL DEFAULT '', sender TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT '', tenant TEXT NOT NULL DEFAULT '', thread_id INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS inbound_log_at ON inbound_log (at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS inbound_log_mid ON inbound_log (message_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS inbound_pending (id INTEGER PRIMARY KEY AUTOINCREMENT, log_id INTEGER NOT NULL, at TEXT NOT NULL, tenant TEXT NOT NULL, kind TEXT NOT NULL,
            thread_id INTEGER NOT NULL, email TEXT NOT NULL, text TEXT NOT NULL, files TEXT NOT NULL DEFAULT '[]')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS inbound_pending_tenant ON inbound_pending (tenant)');
    }
    return $pdo;
}
function inbound_log_add(array $row): int
{
    inbound_pdo()->prepare('INSERT INTO inbound_log (at, message_id, sender, kind, tenant, thread_id, status, reason) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([date('Y-m-d H:i:s'), mb_substr((string) ($row['message_id'] ?? ''), 0, 300), mb_substr((string) ($row['sender'] ?? ''), 0, 200), (string) ($row['kind'] ?? ''),
            (string) ($row['tenant'] ?? ''), (int) ($row['thread_id'] ?? 0), (string) $row['status'], mb_substr((string) ($row['reason'] ?? ''), 0, 300)]);
    return (int) inbound_pdo()->lastInsertId();
}
function inbound_log_set(int $id, string $status, string $reason = ''): void
{
    inbound_pdo()->prepare('UPDATE inbound_log SET status = ?, reason = ? WHERE id = ?')->execute([$status, mb_substr($reason, 0, 300), $id]);
}
/** Stav běhů (poslední kontrola, poslední chyba) – malý soubor vedle záznamu. */
function inbound_state(?array $set = null): array
{
    $f = inbound_dir() . '/state.json';
    $state = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    if ($set !== null) {
        $state = $set + $state;
        @file_put_contents($f, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $state;
}
/** Soubor-značka „dům má ve frontě odpovědi“ – web domu ho při návštěvě levně zkontroluje (bez dotazu do databáze). */
function inbound_marker(string $tenant): string
{
    return inbound_dir() . '/pending-' . ($tenant !== '' ? $tenant : '_') . '.flag';
}

// ---------- Rozbor a ověření jedné zprávy ----------

/** Je to automatická zpráva (nepřítomnost, nedoručenka, hromadná pošta)? Vrací důvod, nebo ''. */
function inbound_auto_reason(array $msg): string
{
    $h = $msg['headers'];
    $first = fn(string $k) => strtolower(trim((string) ($h[$k][0] ?? '')));
    if ($first('auto-submitted') !== '' && $first('auto-submitted') !== 'no') {
        return 'automatická zpráva (Auto-Submitted)';
    }
    foreach (['x-autoreply', 'x-autorespond', 'x-autoreply-from', 'x-mail-autoreply'] as $k) {
        if (isset($h[$k])) {
            return 'automatická odpověď (' . $k . ')';
        }
    }
    if (in_array($first('precedence'), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
        return 'hromadná nebo automatická zpráva (Precedence)';
    }
    if (isset($h['list-id']) || isset($h['list-unsubscribe'])) {
        return 'zpráva z rozesílacího seznamu';
    }
    if (isset($h['return-path']) && preg_match('/^<\s*>$/', trim((string) $h['return-path'][0]))) {
        return 'nedoručenka (prázdná zpáteční adresa)';
    }
    if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounces?)([+@.-]|$)/', $msg['from'])) {
        return 'automatický odesílatel (' . $msg['from'] . ')';
    }
    if (in_array('multipart/report', $msg['types'] ?? [], true)) {
        return 'nedoručenka (multipart/report)';
    }
    if (preg_match('/^(automatick[áa] odpov[ěe]ď|auto(matic)?[ -]?reply|out of (the )?office|nep[řr][íi]tomnost|abwesenheit|undeliver|nedoru[čc]|delivery status notification|mail delivery (failed|system)|returned mail)/iu', $msg['subject'])) {
        return 'automatická zpráva (předmět)';
    }
    return '';
}
/** Neprošla zpráva kontrolou odesílatele (DMARC) podle hlavičky Authentication-Results přijímajícího serveru? */
function inbound_spoofed(array $msg): bool
{
    foreach ($msg['headers']['authentication-results'] ?? [] as $ar) {
        if (preg_match('/\bdmarc=fail\b/i', $ar)) {
            return true;
        }
    }
    return false;
}
/** Kandidáti na značku: adresa příjemce (plus-adresa), předmět, pak text zprávy (i citovaná patička). */
function inbound_find_tokens(array $msg, string $raw): array
{
    $h = $msg['headers'];
    $addr = implode(' ', array_merge($h['delivered-to'] ?? [], $h['x-original-to'] ?? [], $h['to'] ?? [], $h['envelope-to'] ?? []));
    $html = $msg['html'] !== null ? html_entity_decode(strip_tags($msg['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    // Jen kandidáti z adresy +značka a z předmětu, pak z textu; duplicitní značky se sloučí.
    return inbound_tokens_in(preg_replace('/\+([HZVI]\d+[^@\s]*)@/i', ' $1 @', $addr) . "\n" . $msg['subject'] . "\n" . $msg['text'] . "\n" . $html);
}
/**
 * Ověří zprávu a určí cíl. Vrací ['ok' => bool, 'status', 'reason', 'kind', 'tenant', 'thread_id', 'text', 'attachments'].
 * Nic neukládá – jen rozhoduje.
 */
function inbound_route(string $raw): array
{
    $msg = inbound_mime_parse($raw);
    $res = ['ok' => false, 'status' => 'rejected', 'reason' => '', 'kind' => '', 'tenant' => '', 'thread_id' => 0, 'sender' => $msg['from'],
        'message_id' => trim((string) ($msg['headers']['message-id'][0] ?? ''), " <>"), 'text' => '', 'attachments' => $msg['attachments'], 'msg' => $msg];
    if ($msg['from'] === '') {
        return ['reason' => 'zpráva bez adresy odesílatele'] + $res;
    }
    if ($why = inbound_auto_reason($msg)) {
        return ['status' => 'ignored', 'reason' => $why] + $res;
    }
    if (inbound_spoofed($msg)) {
        return ['reason' => 'odesílatel neprošel kontrolou DMARC'] + $res;
    }
    $tokens = inbound_find_tokens($msg, $raw);
    if (!$tokens) {
        return ['reason' => 'bez značky vlákna'] + $res;
    }
    $token = null;
    foreach ($tokens as $t) {
        if (inbound_token_valid($t, $msg['from'])) {
            $token = $t;
            break;
        }
    }
    if (!$token) {
        return ['reason' => 'značka nesedí na odesílatele (' . $tokens[0]['kind'] . $tokens[0]['id'] . ')', 'kind' => $tokens[0]['kind'], 'tenant' => $tokens[0]['tenant'], 'thread_id' => $tokens[0]['id']] + $res;
    }
    $res = ['kind' => $token['kind'], 'tenant' => $token['tenant'], 'thread_id' => $token['id']] + $res;
    if ($why = inbound_access_error($token, $msg['from'])) {
        return ['reason' => $why] + $res;
    }
    $text = inbound_reply_text($msg['text']);
    // Řádek se značkou (když odesílatel necitoval, ale patičku zkopíroval) do vlákna nepatří.
    $text = trim(preg_replace('/^.*#?[HZVI]\d+(\.[a-z0-9-]+)?\.[0-9a-f]{16}.*$/mi', '', $text));
    $text = inbound_tidy_text($text);
    if (mb_strlen($text) > INBOUND_MAX_TEXT) {
        $text = mb_substr($text, 0, INBOUND_MAX_TEXT - 20) . "\n… (zkráceno)";
    }
    if (mb_strlen($text) < 2 && !$msg['attachments']) {
        return ['reason' => 'prázdná odpověď'] + $res;
    }
    return ['ok' => true, 'status' => 'attached', 'text' => $text] + $res;
}
/** Databáze domu pro kontrolu přístupu ('' = web bez platformy, případně dům, v jehož kontextu právě běžíme). */
function inbound_house_pdo(string $tenant): ?PDO
{
    if ($tenant === (defined('TENANT') ? TENANT : '')) {
        return db();
    }
    if (!function_exists('platform_enabled') || !platform_enabled() || $tenant === '' || !($t = tenant_row($tenant)) || !(int) $t['active']) {
        return null;
    }
    return tenant_pdo($tenant);
}
/** Má odesílatel k vláknu stále přístup? Vrací důvod odmítnutí, nebo ''. */
function inbound_access_error(array $token, string $email): string
{
    $id = (int) $token['id'];
    if ($token['kind'] === 'H') {
        $r = rq('SELECT * FROM reports WHERE id = ?', [$id])->fetch();
        if (!$r || $r['type'] === 'auto') {
            return 'hlášení neexistuje';
        }
        if (strtolower(trim((string) $r['user_email'])) !== $email) {
            return 'odesílatel není autorem hlášení';
        }
        if ((int) $r['user_id'] > 0) {
            // Autor s účtem: účet na webu domu musí být stále aktivní a se stejnou adresou.
            $pdo = reports_central() ? inbound_house_pdo((string) $r['tenant']) : db();
            if (!$pdo) {
                return 'web domu autora už není aktivní';
            }
            $st = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND active = 1 AND LOWER(email) = ?');
            $st->execute([(int) $r['user_id'], $email]);
            if (!$st->fetchColumn()) {
                return 'účet autora není aktivní';
            }
        }
        return '';
    }
    $pdo = inbound_house_pdo($token['tenant']);
    if (!$pdo) {
        return 'web domu neexistuje nebo není aktivní';
    }
    if ($token['kind'] === 'I') {
        try {
            $st = $pdo->prepare('SELECT i.email FROM listing_inquiries i JOIN listings l ON l.id = i.listing_id WHERE i.id = ?');
            $st->execute([$id]);
            $stored = $st->fetchColumn();
        } catch (Throwable $e) {
            $stored = false;   // dům inzeráty nikdy nepoužil (tabulky nejsou)
        }
        if ($stored === false) {
            return 'dotaz k inzerátu neexistuje';
        }
        return (string) $stored !== '' && (string) $stored === $email ? '' : 'odesílatel není zájemce, který dotaz poslal';
    }
    $st = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        return 'hlášení závady neexistuje';
    }
    if ($token['kind'] === 'Z') {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND active = 1 AND LOWER(email) = ?');
        $st->execute([(int) $t['user_id'], $email]);
        return $st->fetchColumn() ? '' : 'odesílatel už není aktivním autorem hlášení';
    }
    $st = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ? AND role = 'admin' AND active = 1");
    $st->execute([$email]);
    if (!$st->fetchColumn()) {
        return 'odesílatel není aktivním správcem domu';
    }
    return (int) $t['user_id'] > 0 ? '' : 'hlášení nemá autora, kterému by šlo odpovědět';
}

// ---------- Uložení do vlákna ----------

/** Doplnění k hlášení provozovateli (v databázi hlášení); obrázek PNG/JPG z přílohy se uloží jako snímek. */
function inbound_apply_report(array $route): string
{
    $r = rq('SELECT * FROM reports WHERE id = ?', [(int) $route['thread_id']])->fetch();
    if (!$r) {
        return 'hlášení neexistuje';
    }
    $text = $route['text'];
    $errors = report_followup_errors($r, $text !== '' ? $text : '(příloha)');
    if ($errors) {
        return implode(' ', $errors);
    }
    $shot = '';
    $skipped = 0;
    foreach ($route['attachments'] as $a) {
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][(string) (new finfo(FILEINFO_MIME_TYPE))->buffer($a['data'])] ?? null;
        if ($shot === '' && $ext && strlen($a['data']) <= 6 * 1048576 && @getimagesizefromstring($a['data'])) {
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (@file_put_contents(reports_dir() . '/' . $name, $a['data']) !== false) {
                $shot = $name;
                continue;
            }
        }
        if (!($a['inline'] && str_starts_with($a['type'], 'image/') && strlen($a['data']) < 15000)) {
            $skipped++;   // drobné vložené obrázky (logo v podpisu) se nepočítají
        }
    }
    $body = $text . ($skipped ? ($text !== '' ? "\n\n" : '') . '(příloha z e-mailu nebyla uložena' . ($skipped > 1 ? ' – ' . $skipped . ' souborů' : '') . ')' : '');
    report_add_followup($r, ['name' => (string) $r['user_name'], 'email' => (string) $r['user_email']], $body !== '' ? $body : '(snímek z e-mailu)', $shot);
    return '';
}
/**
 * Uloží odpověď k závadě – v kontextu webu domu (db() je databáze domu). Přílohy jako u webového formuláře
 * (povolené typy, kontrola obsahu, limit úložiště), nejvýš 4. Vrací důvod neúspěchu, nebo ''.
 */
function inbound_apply_ticket(string $kind, int $threadId, string $email, string $text, array $files): string
{
    ticket_ready();
    $t = one('SELECT * FROM tickets WHERE id = ?', [$threadId]);
    if (!$t) {
        return 'hlášení závady neexistuje';
    }
    $email = strtolower($email);
    if ($kind === 'Z') {
        $u = one('SELECT * FROM users WHERE id = ? AND active = 1 AND LOWER(email) = ?', [(int) $t['user_id'], $email]);
        if (!$u) {
            return 'odesílatel už není aktivním autorem hlášení';
        }
    } else {
        $u = ticket_admin_by_email($email);
        if (!$u || !(int) $t['user_id']) {
            return 'odesílatel není aktivním správcem domu';
        }
    }
    $saved = [];
    $notes = [];
    foreach ($files as $f) {
        if ($f['inline'] && str_starts_with((string) $f['type'], 'image/') && strlen($f['data']) < 15000) {
            continue;   // logo nebo ikona z podpisu
        }
        if (count($saved) >= 4) {
            $notes[] = $f['name'] . ' (nejvýš 4 přílohy)';
            continue;
        }
        $r = strlen($f['data']) > 10 * 1048576 ? 'soubor je větší než 10 MB' : store_file_data($f['name'], $f['data']);
        if (is_string($r)) {
            $notes[] = $f['name'] . ' – ' . rtrim($r, '.');
            continue;
        }
        q('INSERT INTO ticket_files (ticket_id, filename, original_name, size) VALUES (?,?,?,?)', [$threadId, $r[0], mb_substr($r[1], 0, 250), $r[2]]);
        $saved[] = $r[1];
    }
    $full = $text . ($saved ? ($text !== '' ? "\n\n" : '') . 'Přílohy z e-mailu: ' . implode(', ', $saved) : '')
        . ($notes ? ($text !== '' || $saved ? "\n" : '') . '(příloha z e-mailu nebyla uložena: ' . implode('; ', $notes) . ')' : '');
    if ($full === '') {
        return 'prázdná odpověď';
    }
    $kind === 'Z' ? ticket_author_reply($t, $u, $full) : ticket_admin_reply(one('SELECT * FROM tickets WHERE id = ?', [$threadId]), $u, $full);
    return '';
}
/** Uloží odpověď určenou webu domu (závada nebo zájemce o inzerát) – v kontextu webu domu. Vrací důvod neúspěchu, nebo ''. */
function inbound_apply_house(string $kind, int $threadId, string $email, string $text, array $files): string
{
    return $kind === 'I' ? listing_inquiry_inbound($threadId, $email, $text, $files) : inbound_apply_ticket($kind, $threadId, $email, $text, $files);
}
/** Web domu převezme odpovědi z fronty (spouští /cron/prichozi/<klíč>, návštěva webu, nebo přímo běh pro tentýž dům). */
function inbound_apply_pending(int $max = 50): int
{
    $tenant = defined('TENANT') ? TENANT : '';
    $pdo = inbound_pdo();
    $lock = @fopen(inbound_dir() . '/apply-' . ($tenant !== '' ? $tenant : '_') . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $done = 0;
    try {
        $st = $pdo->prepare('SELECT * FROM inbound_pending WHERE tenant = ? ORDER BY id LIMIT ' . $max);
        $st->execute([$tenant]);
        foreach ($st->fetchAll() as $p) {
            $files = [];
            foreach ((array) json_decode((string) $p['files'], true) as $f) {
                $path = inbound_dir() . '/files/' . basename((string) $f['file']);
                if (is_file($path)) {
                    $files[] = ['name' => (string) $f['name'], 'type' => (string) $f['type'], 'inline' => !empty($f['inline']), 'data' => (string) file_get_contents($path)];
                }
            }
            try {
                $why = inbound_apply_house((string) $p['kind'], (int) $p['thread_id'], (string) $p['email'], (string) $p['text'], $files);
            } catch (Throwable $e) {
                $why = 'chyba při ukládání: ' . $e->getMessage();
            }
            inbound_log_set((int) $p['log_id'], $why === '' ? 'attached' : 'rejected', $why);
            inbound_pending_drop($p);
            $done += $why === '' ? 1 : 0;
        }
        $left = $pdo->prepare('SELECT COUNT(*) FROM inbound_pending WHERE tenant = ?');
        $left->execute([$tenant]);
        if (!(int) $left->fetchColumn()) {
            @unlink(inbound_marker($tenant));
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $done;
}
function inbound_pending_drop(array $p): void
{
    foreach ((array) json_decode((string) $p['files'], true) as $f) {
        @unlink(inbound_dir() . '/files/' . basename((string) ($f['file'] ?? '')));
    }
    inbound_pdo()->prepare('DELETE FROM inbound_pending WHERE id = ?')->execute([(int) $p['id']]);
}
/** Zařadí odpověď k závadě do fronty domu (přílohy dočasně do storage/inbound/files). */
function inbound_queue(array $route, int $logId): void
{
    $dir = inbound_dir() . '/files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $files = [];
    foreach (array_slice($route['attachments'], 0, 10) as $a) {
        $name = bin2hex(random_bytes(12)) . '.bin';
        if (@file_put_contents($dir . '/' . $name, $a['data']) !== false) {
            $files[] = ['file' => $name, 'name' => $a['name'], 'type' => $a['type'], 'inline' => $a['inline']];
        }
    }
    inbound_pdo()->prepare('INSERT INTO inbound_pending (log_id, at, tenant, kind, thread_id, email, text, files) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$logId, date('Y-m-d H:i:s'), $route['tenant'], $route['kind'], (int) $route['thread_id'], $route['sender'], $route['text'], json_encode($files, JSON_UNESCAPED_UNICODE)]);
    @touch(inbound_marker($route['tenant']));
}
/**
 * Zpracuje jednu zprávu: ověří ji, uloží odpověď (nebo zařadí do fronty domu) a zapíše výsledek. Vrací výsledek
 * z inbound_route() se stavem attached / queued / rejected / ignored a čísel záznamu 'log_id'.
 */
function inbound_handle(string $raw): array
{
    $r = inbound_route($raw);
    // Táž zpráva podruhé (např. když se nepodařilo označit ji jako přečtenou): nic znovu neukládat.
    if ($r['message_id'] !== '') {
        $st = inbound_pdo()->prepare("SELECT status FROM inbound_log WHERE message_id = ? AND status IN ('attached','queued') LIMIT 1");
        $st->execute([$r['message_id']]);
        if ($st->fetchColumn()) {
            return ['ok' => true, 'status' => 'duplicate', 'reason' => 'už zpracováno', 'log_id' => 0] + $r;
        }
    }
    if ($r['ok']) {
        $current = defined('TENANT') ? TENANT : '';
        try {
            if ($r['kind'] === 'H') {
                $why = inbound_apply_report($r);
                $r = $why === '' ? $r : ['ok' => false, 'status' => 'rejected', 'reason' => $why] + $r;
            } elseif ($r['tenant'] !== $current) {
                $r['status'] = 'queued';
            } else {
                $why = inbound_apply_house($r['kind'], (int) $r['thread_id'], $r['sender'], $r['text'], $r['attachments']);
                $r = $why === '' ? $r : ['ok' => false, 'status' => 'rejected', 'reason' => $why] + $r;
            }
        } catch (Throwable $e) {
            $r = ['ok' => false, 'status' => 'error', 'reason' => 'chyba při ukládání: ' . mb_substr($e->getMessage(), 0, 200)] + $r;
        }
    }
    $r['log_id'] = inbound_log_add($r);
    if ($r['status'] === 'queued') {
        inbound_queue($r, $r['log_id']);
    }
    if (!$r['ok'] && $r['status'] !== 'ignored') {
        error_log('SVJ web, odpověď e-mailem nepřipojena: ' . $r['reason'] . ' (' . $r['sender'] . ')');
    }
    return $r;
}

// ---------- Běh nad schránkou ----------

/**
 * Projde nepřečtené zprávy ve schránce (nejvýš max_per_run a $seconds sekund), každou zpracuje a přesune.
 * Jen jeden běh najednou (zámek). $box: jiná schránka (testy). Vrací souhrn ['checked', 'attached', 'queued', 'rejected', 'ignored', 'duplicate',
 * 'failed' (chyba při ukládání), 'error' (text chyby schránky), 'skipped' (proč se neběželo)].
 */
function inbound_run(?InboundMailbox $box = null, int $seconds = 30): array
{
    $sum = ['checked' => 0, 'attached' => 0, 'queued' => 0, 'rejected' => 0, 'ignored' => 0, 'duplicate' => 0, 'failed' => 0, 'error' => '', 'skipped' => ''];
    $c = inbound_config();
    if (!$c) {
        return ['skipped' => inbound_off_reason()] + $sum;
    }
    $lock = @fopen(inbound_dir() . '/run.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['skipped' => 'Kontrola schránky už právě běží.'] + $sum;
    }
    $start = microtime(true);
    $keep = inbound_keep_days();
    try {
        $box ??= ImapMailbox::open($c);
        try {
            foreach ($box->unseen(max(1, min(200, (int) ($c['max_per_run'] ?? 20)))) as $uid) {
                if (microtime(true) - $start > $seconds) {
                    break;
                }
                $raw = $box->fetch($uid);
                $sum['checked']++;
                try {
                    $r = strlen($raw) > 20 * 1048576 ? ['ok' => false, 'status' => 'rejected', 'reason' => 'zpráva je větší než 20 MB', 'log_id' => inbound_log_add(['status' => 'rejected', 'reason' => 'zpráva je větší než 20 MB'])]
                        : inbound_handle($raw);
                } catch (Throwable $e) {
                    // Zprávu, kterou nejde zpracovat, odložit stranou – jinak by se zkoušela při každém běhu znovu.
                    $r = ['ok' => false, 'status' => 'error', 'reason' => 'chyba při zpracování: ' . mb_substr($e->getMessage(), 0, 200)];
                    $r['log_id'] = inbound_log_add($r);
                }
                $sum[$r['status'] === 'error' ? 'failed' : $r['status']]++;
                $box->finish($uid, $r['ok'] ? ($keep === 0 ? '' : INBOUND_DONE_FOLDER) : INBOUND_FAILED_FOLDER);
            }
            // Úklid: zpracované i odmítnuté zprávy se ve schránce neuchovávají déle než keep_days (jednou denně).
            $state = inbound_state();
            if (($state['purged'] ?? '') !== date('Y-m-d')) {
                $box->purge(INBOUND_DONE_FOLDER, $keep);
                $box->purge(INBOUND_FAILED_FOLDER, max($keep, 1));
                inbound_state(['purged' => date('Y-m-d')]);
            }
        } finally {
            $box->close();
        }
        inbound_state(['last_check' => date('Y-m-d H:i:s'), 'last_ok' => date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        $sum['error'] = mb_substr($e->getMessage(), 0, 300);
        inbound_state(['last_check' => date('Y-m-d H:i:s'), 'last_error' => $sum['error'], 'last_error_at' => date('Y-m-d H:i:s')]);
        error_log('SVJ web, schránka pro odpovědi: ' . $sum['error']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    inbound_cleanup();
    if ($sum['queued']) {
        inbound_wake_tenants();
    }
    return $sum;
}
/** Souhrn běhu jednou větou (cron, konzole). */
function inbound_run_summary(array $r): string
{
    return match (true) {
        $r['skipped'] !== '' => $r['skipped'],
        $r['error'] !== '' => 'Schránku se nepodařilo zkontrolovat: ' . $r['error'] . ($r['checked'] ? ' (předtím zpracováno zpráv: ' . $r['checked'] . ')' : ''),
        $r['checked'] === 0 => 'Schránka je zkontrolovaná, žádné nové zprávy.',
        default => 'Nových zpráv: ' . $r['checked'] . ', připojeno ' . ($r['attached'] + $r['queued']) . ', odmítnuto ' . ($r['rejected'] + $r['failed']) . ', automatických ' . $r['ignored'] . '.',
    };
}
/** Záznamy starší 30 dní a nepřevzatá fronta starší 7 dní se mažou (jen údaje o zpracování; text je už ve vlákně). */
function inbound_cleanup(): void
{
    try {
        $pdo = inbound_pdo();
        $pdo->prepare('DELETE FROM inbound_log WHERE at < ?')->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
        $st = $pdo->prepare('SELECT * FROM inbound_pending WHERE at < ?');
        $st->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
        foreach ($st->fetchAll() as $p) {
            inbound_log_set((int) $p['log_id'], 'rejected', 'web domu odpověď do 7 dnů nepřevzal');
            inbound_pending_drop($p);
        }
    } catch (Throwable $e) {
        error_log('SVJ web, schránka pro odpovědi (úklid): ' . $e->getMessage());
    }
}
/** Domy s odpověďmi ve frontě převezmou odpovědi hned (požadavek na /cron/prichozi/<klíč> na jejich adrese). */
function inbound_wake_tenants(): void
{
    $current = defined('TENANT') ? TENANT : '';
    $key = (string) (app_config()['cron_key'] ?? '');
    $tenants = array_column(inbound_pdo()->query('SELECT DISTINCT tenant FROM inbound_pending')->fetchAll(), 'tenant');
    foreach ($tenants as $slug) {
        if ($slug === $current) {
            inbound_apply_pending();
            continue;
        }
        if ($key === '' || PHP_SAPI === 'cli' || !function_exists('tenant_row') || !($t = tenant_row($slug)) || !function_exists('curl_init')) {
            continue;   // převezme ho nejbližší návštěva webu domu
        }
        $ch = curl_init(tenant_url($t, '/cron/prichozi/' . $key));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'spolecnyweb-cron/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        curl_exec($ch);
        curl_close($ch);
    }
}
/** Přehled pro konzoli: nastavení, poslední kontrola, počty za 7 dní, poslední odmítnuté zprávy. */
function inbound_status(): array
{
    $state = inbound_state();
    $counts = ['attached' => 0, 'queued' => 0, 'rejected' => 0, 'ignored' => 0, 'error' => 0];
    $recent = [];
    try {
        $st = inbound_pdo()->prepare('SELECT status, COUNT(*) n FROM inbound_log WHERE at >= ? GROUP BY status');
        $st->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
        foreach ($st->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $recent = inbound_pdo()->query("SELECT * FROM inbound_log WHERE status IN ('rejected','error') ORDER BY id DESC LIMIT 10")->fetchAll();
    } catch (Throwable $e) {
    }
    $c = inbound_raw_config();
    return ['on' => inbound_on(), 'off_reason' => inbound_off_reason(), 'address' => (string) ($c['address'] ?? ''), 'host' => (string) ($c['host'] ?? ''),
        'keep_days' => inbound_keep_days(), 'last_check' => (string) ($state['last_check'] ?? ''), 'last_error' => (string) ($state['last_error'] ?? ''),
        'last_error_at' => (string) ($state['last_error_at'] ?? ''), 'last_ok' => (string) ($state['last_ok'] ?? ''),
        'processed' => array_sum($counts), 'counts' => $counts, 'recent' => $recent];
}

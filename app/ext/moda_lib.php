<?php
declare(strict_types=1);

/*
 * Modul A – rychlá vylepšení a důvěra:
 * 1. přihlášení odkazem z e-mailu (bez hesla; jednorázový odkaz na 15 minut, dvoufázové ověření platí dál),
 * 2. týdenní přehled pro obyvatele e-mailem (vlastníci ho mají zapnutý, nájemníci si ho zapnou sami),
 * 3. plakát do vchodu s QR kódem,
 * 4. pomoc s hlasováním (kolik chybí k přijetí, připomenutí nehlasujícím),
 * 5. export dat domu a zpracovatelská smlouva ke stažení (veřejná stránka /bezpecnost je v site_moda.php).
 * Tabulky v databázi domu se založí samy při prvním použití (moda_ready).
 */

const MAGIC_LINK_TTL = 900;          // platnost odkazu pro přihlášení: 15 minut
const MAGIC_LINK_PER_ACCOUNT = 3;    // nejvýše 3 odkazy na jeden účet za 15 minut
const MAGIC_LINK_PER_IP = 5;         // nejvýše 5 žádostí z jedné adresy za 15 minut
const DIGEST_DAYS_AHEAD = 14;        // termíny na příštích 14 dní
const POLL_REMIND_GAP = 86400;       // připomenutí hlasování nejvýš jednou za 24 hodin
const EXPORT_FREE_DAYS = 30;         // export dat v edici bez zálohy: jednou za 30 dní

$GLOBALS['EXT_ADMIN_NAV']['/admin/plakat'] = ['Plakát do vchodu', 'Komunikace'];
$GLOBALS['EXT_ADMIN_NAV']['/admin/data-domu'] = ['Data a smlouva', 'Správa webu'];

function moda_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT' : 'INTEGER';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_links (id $id, user_id $int NOT NULL, token_hash {$s(64)}, created $int NOT NULL DEFAULT 0, expires $int NOT NULL DEFAULT 0,
        used $int NOT NULL DEFAULT 0, ip {$s(64)}" . ($mysql ? ', UNIQUE KEY login_links_token (token_hash)' : '') . ")$end");
    $pdo->exec("CREATE TABLE IF NOT EXISTS digest_prefs (user_id $int NOT NULL PRIMARY KEY, enabled $int NOT NULL DEFAULT 1, token {$s(32)}, last_sent {$s(19)})$end");
    $pdo->exec("CREATE TABLE IF NOT EXISTS poll_reminders (id $id, poll_id $int NOT NULL, at $int NOT NULL DEFAULT 0, user_id $int NOT NULL DEFAULT 0, recipients $int NOT NULL DEFAULT 0)$end");
    if (!$mysql) {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS login_links_token ON login_links (token_hash)');
    }
}
/** Uloží nastavení webu (tabulka settings) a obnoví mezipaměť setting(). */
function moda_setting(string $key, string $value): void
{
    q('DELETE FROM settings WHERE `key` = ?', [$key]);
    q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
    setting('__reset');
}
function moda_mail_ready(): bool
{
    return (bool) filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL);
}

// ---------- 1. Přihlášení odkazem z e-mailu ----------

/**
 * Žádost o odkaz pro přihlášení. Odpověď navenek je vždy stejná (nejde zjistit, kdo má účet).
 * Vrací 'sent' (odesláno nebo účet neexistuje), 'blocked' (příliš mnoho žádostí z této adresy) nebo 'nomail' (web neumí posílat e-maily).
 */
function magic_link_request(string $email): string
{
    if (!moda_mail_ready()) {
        return 'nomail';
    }
    moda_ready();
    $email = trim($email);
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    q('DELETE FROM login_links WHERE expires < ?', [time() - 86400]);
    if ((int) val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = '#magic'", [client_ip()]) >= MAGIC_LINK_PER_IP || login_blocked('')) {
        return 'blocked';
    }
    q("INSERT INTO login_attempts (ip, at, email) VALUES (?,?,'#magic')", [client_ip(), time()]);
    $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]) : null;
    if (!$u) {
        return 'sent';   // neexistující účet: nic se neodešle, odpověď je stejná
        // Útok hesly na účet odkaz z e-mailu neblokuje (bezpečnost 11/2026): odkaz dostane jen majitel schránky,
        // proto je to cesta, jak se přihlásit i během útoku. Rozesílání hlídá MAGIC_LINK_PER_IP a MAGIC_LINK_PER_ACCOUNT.
    }
    if ($u['password_hash'] === NO_PASSWORD) {
        send_account_invite($u);   // účet ještě nepřijal pozvánku – pošle se znovu
        audit('Žádost o odkaz pro přihlášení', $u['email'] . ' · účet čeká na pozvánku, poslána znovu', (int) $u['id']);
        return 'sent';
    }
    if ((int) val('SELECT COUNT(*) FROM login_links WHERE user_id = ? AND created >= ?', [$u['id'], time() - 900]) >= MAGIC_LINK_PER_ACCOUNT) {
        audit('Žádost o odkaz pro přihlášení', $u['email'] . ' · neodesláno, příliš mnoho žádostí', (int) $u['id']);
        return 'sent';
    }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO login_links (user_id, token_hash, created, expires, used, ip) VALUES (?,?,?,?,0,?)', [$u['id'], hash('sha256', $token), time(), time() + MAGIC_LINK_TTL, client_ip()]);
    $text = "Dobrý den,\n\nna webu " . site('name') . " někdo požádal o přihlášení k Vašemu účtu odkazem z e-mailu. Přihlásíte se na této adrese (platí 15 minut a jen jednou):\n"
        . absolute_url('/prihlaseni/odkaz/' . $token) . "\n\n" . ((int) $u['totp_enabled'] ? "Po otevření odkazu zadáte ještě kód z ověřovací aplikace v telefonu.\n\n" : '')
        . "Pokud jste o přihlášení nežádali, zprávu ignorujte – bez tohoto odkazu se nikdo nepřihlásí.\n";
    send_mail($u['email'], 'Přihlášení na web ' . site('name'), $text);
    audit('Žádost o odkaz pro přihlášení', $u['email'], (int) $u['id']);
    return 'sent';
}
/** Platný, dosud nepoužitý odkaz → [odkaz, uživatel]; jinak null. Nic nemění (stránka s tlačítkem „Přihlásit se“). */
function magic_link_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    moda_ready();
    $link = one('SELECT * FROM login_links WHERE token_hash = ? AND used = 0 AND expires >= ?', [hash('sha256', $token), time()]);
    $u = $link ? one('SELECT * FROM users WHERE id = ? AND active = 1', [$link['user_id']]) : null;
    return $link && $u && $u['password_hash'] !== NO_PASSWORD ? [$link, $u] : null;
}
/**
 * Použije odkaz (jen jednou – i při dvou souběžných kliknutích projde jen jedno). Vrací uživatele, nebo null.
 * Neplatný odkaz se počítá jako neúspěšný pokus o přihlášení (ochrana proti zkoušení odkazů).
 */
function magic_link_consume(string $token): ?array
{
    $found = magic_link_find($token);
    if (!$found) {
        login_failed('', 'Neplatný odkaz pro přihlášení');
        return null;
    }
    [$link, $u] = $found;
    if (q('UPDATE login_links SET used = ? WHERE id = ? AND used = 0', [time(), $link['id']])->rowCount() !== 1) {
        return null;
    }
    return $u;
}
/**
 * Dokončí přihlášení odkazem. S dvoufázovým ověřením jen připraví ověření kódem (stejně jako po zadání hesla)
 * a vrátí '/prihlaseni/overeni'; jinak uživatele přihlásí a vrátí adresu, kam pokračovat.
 */
function magic_link_login(array $u, mixed $after = null): string
{
    audit('Přihlášení odkazem z e-mailu', $u['email'], (int) $u['id']);
    if ((int) $u['totp_enabled']) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION = ['t' => TENANT, 'pending' => ['uid' => (int) $u['id'], 'at' => time(), 'after' => $after]];
        return '/prihlaseni/overeni';
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        login_user($u);
    } else {
        $_SESSION = ['t' => TENANT, 'uid' => (int) $u['id'], 'pv' => pw_fingerprint($u['password_hash']), 'start' => time(), 'seen' => time()];   // bez relace (příkazová řádka)
    }
    q("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?", [$u['id']]);
    q('DELETE FROM login_attempts WHERE ip = ? OR email = ?', [client_ip(), mb_strtolower($u['email'])]);
    audit('login', $u['email'], (int) $u['id']);
    return is_string($after) && preg_match('~^/(?![/\\\\])~', $after) ? $after : ($u['role'] === 'admin' ? '/admin' : '/');
}

// ---------- 2. Týdenní přehled ----------

/** Má uživatel týdenní přehled zapnutý? Bez vlastní volby: vlastníci ano (pokud nevypnuli zprávy e-mailem), nájemníci ne. */
function digest_enabled(array $u): bool
{
    moda_ready();
    $pref = val('SELECT enabled FROM digest_prefs WHERE user_id = ?', [$u['id']]);
    if ($pref !== false && $pref !== null) {
        return (int) $pref === 1;
    }
    return $u['kind'] === 'owner' && (int) $u['email_notify'] === 1;
}
function digest_token(int $userId): string
{
    moda_ready();
    $t = (string) val('SELECT token FROM digest_prefs WHERE user_id = ?', [$userId]);
    if (preg_match('/^[a-f0-9]{32}$/', $t)) {
        return $t;
    }
    $t = bin2hex(random_bytes(16));
    $u = one('SELECT * FROM users WHERE id = ?', [$userId]);
    $enabled = $u ? (digest_enabled($u) ? 1 : 0) : 0;
    q('DELETE FROM digest_prefs WHERE user_id = ?', [$userId]);
    q('INSERT INTO digest_prefs (user_id, enabled, token, last_sent) VALUES (?,?,?,?)', [$userId, $enabled, $t, '']);
    return $t;
}
function digest_set(int $userId, bool $on): void
{
    digest_token($userId);   // založí řádek s volbou
    q('UPDATE digest_prefs SET enabled = ? WHERE user_id = ?', [$on ? 1 : 0, $userId]);
    audit($on ? 'Týdenní přehled zapnut' : 'Týdenní přehled vypnut', (string) val('SELECT email FROM users WHERE id = ?', [$userId]), $userId);
}
/** Odhlášení odkazem z e-mailu: vrátí uživatele, jemuž odkaz patří (nebo null). */
function digest_user_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    moda_ready();
    return one('SELECT u.* FROM digest_prefs d JOIN users u ON u.id = d.user_id WHERE d.token = ? AND u.active = 1', [$token]);
}
/** Je týdenní přehled pro celý dům zapnutý (výbor ho může vypnout)? */
function digest_house_on(): bool
{
    return setting('digest_off') !== '1';
}

/** Obsah přehledu pro jednoho uživatele od data $since (Y-m-d H:i:s). Prázdné části se vynechají. */
function digest_for(array $u, string $since): array
{
    $uid = (int) $u['id'];
    $units = user_unit_ids($uid);
    $today = date('Y-m-d');
    $d = ['posts' => [], 'docs' => [], 'events' => [], 'polls' => [], 'surveys' => [], 'tickets' => [], 'ticket_news' => false];
    if (feature_on('aktuality')) {
        $d['posts'] = all('SELECT id, title, published_at FROM posts WHERE published_at > ? AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT 10', [$since, date('Y-m-d H:i:s')]);
    }
    if (feature_on('dokumenty')) {
        $scope = 'unit_id IS NULL' . ($units ? ' OR unit_id IN (' . implode(',', $units) . ')' : '');
        $d['docs'] = all("SELECT id, title, unit_id FROM documents WHERE created_at > ? AND ($scope) ORDER BY created_at DESC, id DESC LIMIT 10", [$since]);
    }
    if (feature_on('terminy')) {
        $d['events'] = all('SELECT id, title, event_date, event_time, place FROM events WHERE event_date >= ? AND event_date <= ? ORDER BY event_date, event_time LIMIT 10',
            [$today, date('Y-m-d', strtotime($today) + DIGEST_DAYS_AHEAD * 86400)]);
    }
    if (feature_on('hlasovani') && $u['kind'] === 'owner' && $units) {
        foreach (all('SELECT * FROM polls WHERE online = 1 AND opens_at <= ? AND closes_at >= ? ORDER BY closes_at', [$today, $today]) as $p) {
            $voted = (int) val('SELECT COUNT(DISTINCT v.unit_id) FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ? AND v.unit_id IN (' . implode(',', $units) . ')', [$p['id']]);
            if ($voted < count($units)) {
                $d['polls'][] = $p;
            }
        }
    }
    if (feature_on('ankety') && (!function_exists('plan_has') || plan_has('ankety'))) {
        $d['surveys'] = all("SELECT s.id, s.title, s.closes_at FROM surveys s WHERE (s.closes_at = '' OR s.closes_at IS NULL OR s.closes_at >= ?)
            AND NOT EXISTS (SELECT 1 FROM survey_answers a WHERE a.survey_id = s.id AND a.user_id = ?) ORDER BY s.closes_at, s.id", [$today, $uid]);
    }
    if (feature_on('zavady')) {
        $d['tickets'] = all("SELECT id, subject, status, updated_at FROM tickets WHERE user_id = ? AND status <> 'done' ORDER BY updated_at DESC LIMIT 10", [$uid]);
        $d['ticket_news'] = (bool) val('SELECT 1 FROM tickets WHERE user_id = ? AND updated_at > ? LIMIT 1', [$uid, $since]);
    }
    return $d;
}
/** Přehled bez novinek se neposílá: samotné otevřené hlášení beze změny za týden novinka není. */
function digest_empty(array $d): bool
{
    return !$d['posts'] && !$d['docs'] && !$d['events'] && !$d['polls'] && !$d['surveys'] && !$d['ticket_news'];
}
function digest_text(array $u, array $d, string $token): string
{
    $out = ['Dobrý den,', '', 'co je nového na webu ' . site('name') . ':'];
    $link = fn(string $path) => absolute_url($path);
    if ($d['posts']) {
        $out[] = '';
        $out[] = 'NOVÉ AKTUALITY';
        foreach ($d['posts'] as $p) {
            $out[] = '- ' . $p['title'] . ' (' . cz_date(substr($p['published_at'], 0, 10)) . ')';
        }
        $out[] = '  ' . $link('/aktuality');
    }
    if ($d['docs']) {
        $out[] = '';
        $out[] = 'NOVÉ DOKUMENTY';
        foreach ($d['docs'] as $doc) {
            $out[] = '- ' . $doc['title'] . ($doc['unit_id'] ? ' (pro Vaši jednotku)' : '');
        }
        $out[] = '  ' . $link('/dokumenty');
    }
    if ($d['events']) {
        $out[] = '';
        $out[] = 'TERMÍNY NA PŘÍŠTÍCH ' . DIGEST_DAYS_AHEAD . ' DNÍ';
        foreach ($d['events'] as $ev) {
            $out[] = '- ' . cz_date($ev['event_date']) . ($ev['event_time'] !== '' ? ' ' . $ev['event_time'] : '') . ': ' . $ev['title'] . ($ev['place'] !== '' ? ', ' . $ev['place'] : '');
        }
        $out[] = '  ' . $link('/terminy');
    }
    if ($d['polls']) {
        $out[] = '';
        $out[] = 'HLASOVÁNÍ ČEKÁ NA VÁŠ HLAS';
        foreach ($d['polls'] as $p) {
            $out[] = '- ' . $p['title'] . ' (do ' . cz_date($p['closes_at']) . ') ' . $link('/hlasovani/' . $p['id']);
        }
    }
    if ($d['surveys']) {
        $out[] = '';
        $out[] = 'ANKETY, NA KTERÉ JSTE NEODPOVĚDĚLI';
        foreach ($d['surveys'] as $s) {
            $out[] = '- ' . $s['title'] . (!empty($s['closes_at']) ? ' (do ' . cz_date($s['closes_at']) . ')' : '');
        }
        $out[] = '  ' . $link('/ankety');
    }
    if ($d['tickets']) {
        $out[] = '';
        $out[] = 'VAŠE ROZPRACOVANÁ HLÁŠENÍ';
        foreach ($d['tickets'] as $t) {
            $out[] = '- ' . $t['subject'] . ': ' . mb_strtolower(TICKET_STATUS[$t['status']] ?? $t['status']);
        }
        $out[] = '  ' . $link('/zavady');
    }
    $out[] = '';
    $out[] = '-- ';
    $out[] = 'Týdenní přehled posílá web ' . site('name') . '. Vypnout si ho můžete jedním kliknutím: ' . $link('/tydenni-prehled/odhlasit/' . $token);
    $out[] = 'nebo ve svém účtu: ' . $link('/muj-ucet') . '#tydenni-prehled';
    return implode("\n", $out) . "\n";
}
/** Komu se přehled posílá: aktivní účty s přijatou pozvánkou a platným e-mailem, které ho mají zapnutý. */
function digest_recipients(): array
{
    return array_values(array_filter(all("SELECT * FROM users WHERE active = 1 AND password_hash <> ? ORDER BY id", [NO_PASSWORD]),
        fn($u) => filter_var($u['email'], FILTER_VALIDATE_EMAIL) && digest_enabled($u)));
}
/** Rozešle přehled všem příjemcům (prázdné přehledy vynechá). Vrací [odesláno, přeskočeno prázdných]. */
function digest_send_all(string $since): array
{
    $sent = $skipped = 0;
    mail_bulk(function () use ($since, &$sent, &$skipped) {
        foreach (digest_recipients() as $u) {
            $d = digest_for($u, $since);
            if (digest_empty($d)) {
                $skipped++;
                continue;
            }
            $token = digest_token((int) $u['id']);
            if (send_mail($u['email'], 'Týdenní přehled – ' . site('name'), digest_text($u, $d, $token))) {
                $sent++;
                q('UPDATE digest_prefs SET last_sent = ? WHERE user_id = ?', [date('Y-m-d H:i:s'), $u['id']]);
            }
        }
    });
    return [$sent, $skipped];
}
/**
 * Volá se z denních úloh. Přehled odchází jednou týdně – první denní běh od pátku daného týdne
 * (bez cronu tedy s první návštěvou webu od pátku). Ukázkový web a web bez odesílání e-mailů nic neposílá.
 */
function digest_daily(bool $force = false): ?array
{
    try {
        if (!moda_mail_ready() || !digest_house_on()) {
            return null;
        }
        if (function_exists('plan_tenant') && ($t = plan_tenant()) && !empty($t['demo'])) {
            return null;
        }
        $week = date('o-W');
        if (!$force && ((int) date('N') < 5 || setting('digest_week') === $week)) {
            return null;
        }
        $since = setting('digest_since');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since) || $since < date('Y-m-d H:i:s', time() - 14 * 86400)) {
            $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        }
        moda_setting('digest_week', $week);
        moda_setting('digest_since', date('Y-m-d H:i:s'));
        [$sent, $skipped] = digest_send_all($since);
        audit('Týdenní přehled rozeslán', 'odesláno ' . $sent . ', bez novinek (neodesláno) ' . $skipped);
        return [$sent, $skipped];
    } catch (Throwable $e) {
        error_log('SVJ web, týdenní přehled: ' . $e->getMessage());
        return null;
    }
}

// ---------- 3. Plakát do vchodu ----------

/** Odkaz pro přiřazení k jednotce (routes_join.php), je-li zapnutý; jinak ''. */
function moda_join_link(): string
{
    return setting('join_on') === '1' && setting('join_token') !== '' ? absolute_url('/pripojit/' . setting('join_token')) : '';
}
/** Podklady pro plakát: název domu, adresa webu, QR kód a postup registrace. */
function moda_poster(bool $withJoin): array
{
    $web = absolute_url('/');
    $join = $withJoin ? moda_join_link() : '';
    $target = $join !== '' ? $join : $web;
    $contact = filter_var(setting('mail_reply_to'), FILTER_VALIDATE_EMAIL) ? setting('mail_reply_to') : (filter_var(setting('webmaster_email'), FILTER_VALIDATE_EMAIL) ? setting('webmaster_email') : '');
    $steps = $join !== ''
        ? ['Naskenujte QR kód fotoaparátem telefonu.', 'Vyplňte jméno, e-mail a vyberte svou jednotku.', 'Výbor žádost ověří a na e-mail Vám přijde pozvánka k nastavení hesla.']
        : ['Naskenujte QR kód fotoaparátem telefonu, nebo zadejte adresu webu do prohlížeče.', 'Účet Vám založí výbor – stačí mu napsat svůj e-mail a číslo jednotky.', 'Na e-mail Vám přijde pozvánka; pak se přihlásíte heslem nebo odkazem z e-mailu.'];
    return ['name' => site('full') !== '' ? site('full') : site('name'), 'title' => site('name'), 'address' => setting('address'),
        'web' => preg_replace('~^https?://~', '', rtrim($web, '/')), 'target' => $target, 'join' => $join !== '',
        'qr' => qr_svg($target, $join !== '' ? 'QR kód pro přihlášení k jednotce' : 'QR kód s adresou webu'), 'steps' => $steps, 'contact' => $contact];
}

// ---------- 4. Pomoc s hlasováním ----------

/**
 * Kolik chybí k přijetí jednotlivých bodů (výsledek poll_results()). Pro každý bod (podle id):
 * passed, missing (procentní body do hranice), open (procenta hlasů jednotek, které k bodu ještě nehlasovaly),
 * reachable (jde ještě přijmout?), units (nejmenší počet dalších jednotek, jejichž hlasy PRO by stačily).
 */
function moda_poll_gap(array $results): array
{
    $weights = [];
    $total = 0.0;
    foreach ($results['units'] as $un) {
        $weights[(int) $un['id']] = $results['byShare'] ? share_value((string) $un['share']) : 1.0;
        $total += $weights[(int) $un['id']];
    }
    $out = [];
    foreach ($results['items'] as $it) {
        $votedIds = array_map('intval', array_keys($results['votes'][$it['id']] ?? []));
        $open = array_diff_key($weights, array_flip($votedIds));
        $openPct = $total > 0 ? array_sum($open) / $total * 100 : 0.0;
        $yes = $it['percent']['yes'];
        $passed = $yes > $results['threshold'];
        $missing = $passed ? 0.0 : $results['threshold'] - $yes;
        $units = 0;
        if (!$passed && $total > 0) {
            rsort($open);
            $acc = $it['sum']['yes'];
            foreach ($open as $w) {
                $acc += $w;
                $units++;
                if ($acc / $total * 100 > $results['threshold'] + 1e-9) {
                    break;
                }
            }
            if ($acc / $total * 100 <= $results['threshold'] + 1e-9) {
                $units = 0;
            }
        }
        $out[(int) $it['id']] = ['passed' => $passed, 'missing' => round($missing, 4), 'open' => round($openPct, 4),
            'reachable' => $passed || ($yes + $openPct > $results['threshold'] + 1e-9), 'units' => $units];
    }
    return $out;
}
/** Vlastníci jednotek, za které v hlasování ještě nikdo nehlasoval (aktivní účty s přijatou pozvánkou a zapnutými zprávami e-mailem). */
function moda_poll_nonvoters(int $pollId): array
{
    return all("SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_units uu ON uu.user_id = u.id
        WHERE u.active = 1 AND u.kind = 'owner' AND u.email_notify = 1 AND u.password_hash <> ?
        AND uu.unit_id NOT IN (SELECT v.unit_id FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ?) ORDER BY u.name", [NO_PASSWORD, $pollId]);
}
/** Kdy šlo poslední připomenutí (unix čas), nebo 0. */
function moda_poll_last_reminder(int $pollId): int
{
    moda_ready();
    return (int) val('SELECT MAX(at) FROM poll_reminders WHERE poll_id = ?', [$pollId]);
}
/**
 * Připomene hlasování vlastníkům, kteří ještě nehlasovali (e-mail a upozornění do telefonu). Nejvýš jednou za 24 hodin.
 * Vrací text chyby, nebo ['mails' => n, 'push' => n, 'people' => n].
 */
function moda_poll_remind(int $pollId, array $admin): array|string
{
    $poll = one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    if (!$poll) {
        return 'Hlasování neexistuje.';
    }
    if (poll_state($poll) !== 'open' || !(int) $poll['online']) {
        return 'Připomenout lze jen hlasování, které právě probíhá přes web.';
    }
    $last = moda_poll_last_reminder($pollId);
    if ($last > time() - POLL_REMIND_GAP) {
        return 'Připomenutí už šlo ' . cz_datetime(date('Y-m-d H:i:s', $last)) . '. Další můžete poslat nejdřív za 24 hodin od něj.';
    }
    $people = moda_poll_nonvoters($pollId);
    if (!$people) {
        return 'Všichni vlastníci s účtem na webu už hlasovali (nebo mají zprávy e-mailem vypnuté).';
    }
    $mails = 0;
    $text = "Dobrý den,\n\nza Vaši jednotku zatím nikdo nehlasoval v hlasování „" . $poll['title'] . "“. Hlasovat můžete do " . cz_date($poll['closes_at'], true) . " na adrese:\n"
        . absolute_url('/hlasovani/' . $poll['id']) . "\n\nPokud jste už hlasovali na papíře, zprávu prosím ignorujte – výbor Váš hlas zapíše.\n";
    mail_bulk(function () use ($people, $poll, $text, &$mails) {
        foreach ($people as $p) {
            $mails += send_mail($p['email'], 'Připomenutí hlasování: ' . $poll['title'], $text) ? 1 : 0;
        }
    });
    $push = push_notify(array_column($people, 'id'), site('name'), 'Hlasování čeká na Váš hlas: ' . $poll['title'], '/hlasovani/' . $poll['id']);
    moda_ready();
    q('INSERT INTO poll_reminders (poll_id, at, user_id, recipients) VALUES (?,?,?,?)', [$pollId, time(), (int) $admin['id'], count($people)]);
    audit('Připomenutí hlasování nehlasujícím', $poll['title'] . ' · ' . count($people) . ' vlastníků, e-mailů ' . $mails . ', upozornění do telefonu ' . $push, (int) $admin['id']);
    return ['mails' => $mails, 'push' => $push, 'people' => count($people)];
}

// ---------- 5. Export dat a zpracovatelská smlouva ----------

/**
 * Smí správce stáhnout export všech dat? S funkcí „Záloha dat“ v edici vždy; jinak (edice Zdarma) jednou za 30 dní –
 * přenositelnost dat nesmí záviset na předplatném. Vrací [smí?, datum, odkdy znovu ('' když hned)].
 */
function moda_export_allowed(): array
{
    if (!function_exists('plan_has') || plan_has('zaloha')) {
        return [true, ''];
    }
    $last = setting('export_last');
    $next = $last !== '' ? date('Y-m-d', strtotime($last) + EXPORT_FREE_DAYS * 86400) : '';
    return $next === '' || $next <= date('Y-m-d') ? [true, ''] : [false, $next];
}
/** Sestaví ZIP se všemi daty domu (data.json z backup_data() a nahrané soubory) a vrátí cestu k dočasnému souboru. */
function moda_export_zip(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Na tomto hostingu chybí podpora ZIP.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'svj');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('data.json', json_encode(backup_data(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));
    $zip->addFromString('CTI-ME.txt', 'Export všech dat webu ' . site('name') . ' ze dne ' . date('j. n. Y H:i') . ".\n\n"
        . "data.json  – všechna data webu ve formátu JSON (účty, jednotky, aktuality, dokumenty, hlasování, záznamy…)\n"
        . "uploads/   – nahrané dokumenty a přílohy\n\n"
        . "Formát je otevřený a čitelný v libovolném textovém editoru či tabulkovém programu; data můžete převést k jinému poskytovateli.\n"
        . "Obnova na serveru s tímto webem: php tools/restore-backup.php cesta/k/exportu.zip\n\n"
        . "Export obsahuje osobní údaje. Uchovávejte ho bezpečně.\n");
    if (defined('UPLOAD_DIR') && is_dir(UPLOAD_DIR)) {
        foreach (glob(UPLOAD_DIR . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== 'index.html') {
                $zip->addFile($file, 'uploads/' . basename($file));
            }
        }
    }
    $zip->close();
    return $tmp;
}
/** Údaje pro zpracovatelskou smlouvu: správce (toto SVJ) a zpracovatel (provozovatel služby). null mimo platformu. */
function moda_dpa(): ?array
{
    if (!function_exists('platform_enabled') || !platform_enabled() || !defined('TENANT') || TENANT === '') {
        return null;
    }
    $op = operator();
    $t = function_exists('plan_tenant') ? plan_tenant() : null;
    $text = pset('dpa');
    return ['svj' => ['name' => site('full') !== '' ? site('full') : site('name'), 'ico' => setting('ico'), 'address' => setting('address') !== '' ? setting('address') : (string) ($t['address'] ?? ''),
        'web' => rtrim(absolute_url('/'), '/')], 'op' => $op, 'service' => pset('service_name'), 'text' => $text !== '' ? $text : PLATFORM_DPA,
        'since' => substr((string) ($t['created_at'] ?? ''), 0, 10)];
}
/** Údaje pro veřejnou stránku /bezpecnost: tvrdí jen to, co je na této instalaci skutečně nastavené. */
function moda_security_facts(): array
{
    $cfg = app_config();
    $hosting = function_exists('pset') && platform_enabled() ? pset('hosting_note') : '';
    return [
        'hosting' => $hosting !== '' ? $hosting : 'Servery v České republice (Evropská unie).',
        'backup' => strlen((string) ($cfg['backup_key'] ?? '')) >= 32,
        'operator' => function_exists('operator') && platform_enabled() ? operator() : ['name' => '', 'ico' => '', 'address' => '', 'email' => ''],
        'service' => function_exists('pset') && platform_enabled() ? pset('service_name') : '',
    ];
}

<?php
declare(strict_types=1);

/*
 * Modul H – shromáždění online a hybridně (§ 1206 odst. 2 OZ: účast a hlasování technickými prostředky, pokud to stanovy umožňují).
 *
 * Termín shromáždění má formu (prezenční / hybridní / online), odkaz na videohovor (Jitsi Meet, Microsoft Teams, Zoom,
 * Google Meet – libovolná adresa https) a potvrzení správce, že stanovy účast a hlasování technickými prostředky umožňují.
 * Přihlášený vlastník se v den konání „připojí online“: jeho jednotky se zapíšou do prezence (event_presence.mode = 'online')
 * s časem, IP adresou a způsobem ověření (přihlášení na web, případně kód z e-mailu). Předsedající pouští jednotlivé návrhy
 * k hlasování online; online přítomní hlasují pro / proti / zdržel se (váhou podílů svých jednotek), hlas se uloží do
 * meeting_votes s method = 'online'. Kdo z online přítomných nehlasuje, počítá se jako „zdržel se“ (na místě platí
 * „kdo neřekne jinak, je pro“ – online by mlčení mohlo znamenat jen výpadek spojení).
 *
 * Videohovor samotný web neprovozuje ani nenahrává – jen ukládá odkaz. Veřejný Jitsi Meet (meet.jit.si) je zdarma (místnost
 * otevírá přihlášený uživatel – Google/GitHub/Facebook; podle podmínek z 2020 limit 25 připojení měsíčně),
 * přesto je funkce v BETA_FEATURES ('shromazdeni-online', výchozí stav vypnuto), aby ji provozovatel nejdřív vyzkoušel.
 * Zapnutí pro dům: edice Standard a výš + přepínač správce v Administraci → Shromáždění online (nastavení 'hybrid_on').
 * Cena: v ceně edice Standard i Premium (11/2026) – provozovatele nic nestojí. Mechanismus platby z kreditu (price_online_meeting,
 * strhne se při přepnutí termínu na online formu, vrací se při přepnutí zpět) zůstává jen pro případ, že by provozovatel cenu v pset nastavil.
 */

const HYBRID_BETA = 'shromazdeni-online';
const HYBRID_FORMS = ['prezencni' => 'Prezenční', 'hybridni' => 'Hybridní (na místě i online)', 'online' => 'Online (jen technickými prostředky)'];
const HYBRID_CODE_TTL = 600;        // platnost kódu z e-mailu (s)
const HYBRID_CODE_TRIES = 5;
/** Vzorové ustanovení stanov (nápověda u nastavení termínu a v administraci). */
const HYBRID_STATUTES_CLAUSE = 'Shromáždění se lze zúčastnit a hlasovat na něm také s využitím technických prostředků (videokonference), '
    . 'pokud to svolavatel v pozvánce umožní. Pozvánka v takovém případě uvede způsob připojení. Vlastník jednotky se ověřuje přihlášením '
    . 'do internetových stránek společenství (případně jednorázovým kódem zaslaným na jeho e-mailovou adresu). Vlastník účastnící se '
    . 'technickými prostředky se považuje za přítomného a jeho hlasy se započítávají do usnášeníschopnosti i do výsledku hlasování. '
    . 'Způsob účasti každého vlastníka a jeho hlasování se uvedou v zápisu ze shromáždění.';

// Položka v menu administrace (jen když provozovatel funkci spustil a dům má edici Standard nebo vyšší).
$GLOBALS['EXT_ADMIN_NAV']['/admin/shromazdeni-online'] = ['Shromáždění online', 'Obsah webu', fn() => hybrid_available()];

/** Tabulky modulu v databázi domu (založí se při prvním použití) a sloupce u hlasů na shromáždění. */
function hybrid_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'hybrid_events'")->fetch()
        : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'hybrid_events'")->fetch();
    if ($exists) {
        return;
    }
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $auto = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE hybrid_events (event_id $int NOT NULL PRIMARY KEY, form {$s(10)}, video_url {$s(500)}, statutes_ok $int NOT NULL DEFAULT 0,"
        . " statutes_by $int NOT NULL DEFAULT 0, statutes_at {$s(19)}, updated_at {$s(19)})$end");
    $pdo->exec("CREATE TABLE hybrid_attendance (id $auto, event_id $int NOT NULL, unit_id $int NOT NULL, user_id $int NOT NULL, joined_at {$s(19)},"
        . " left_at {$s(19)}, ip {$s(45)}, verified {$s(10)})$end");
    $pdo->exec('CREATE INDEX hybrid_attendance_event ON hybrid_attendance (event_id, unit_id)');
    $pdo->exec("CREATE TABLE hybrid_live (motion_id $int NOT NULL PRIMARY KEY, opened_at {$s(19)}, opened_by $int NOT NULL DEFAULT 0)$end");
    // Hlasy na shromáždění: jak hlas přišel ('' = zapsal správce na místě, 'online' = vlastník přes web) a kdy.
    $cols = $mysql ? array_column($pdo->query('SHOW COLUMNS FROM meeting_votes')->fetchAll(PDO::FETCH_ASSOC), 'Field')
        : array_column($pdo->query('PRAGMA table_info(meeting_votes)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if ($cols && !in_array('method', $cols, true)) {
        $pdo->exec("ALTER TABLE meeting_votes ADD COLUMN method {$s(10)}");
    }
    if ($cols && !in_array('voted_at', $cols, true)) {
        $pdo->exec("ALTER TABLE meeting_votes ADD COLUMN voted_at {$s(19)}");
    }
}

// ---------- Dostupnost ----------

/** Provozovatel funkci spustil (beta / on) a dům má edici Standard nebo vyšší. */
function hybrid_available(): bool
{
    if (!beta_visible(HYBRID_BETA)) {
        return false;
    }
    return !plan_managed() || EDITION_RANK[plan_edition()] >= EDITION_RANK['standard'];
}
/** Platí se online shromáždění z kreditu? Dům na platformě v edici nižší než Premium. */
function hybrid_paid(): bool
{
    return function_exists('credit_managed') && credit_managed() && hybrid_price_h() > 0 && EDITION_RANK[plan_edition()] < EDITION_RANK['premium'];
}
function hybrid_price_h(): int
{
    return credit_setting('price_online_meeting') * 100;
}
function hybrid_charge_ref(int $eventId): string
{
    return 'online:' . $eventId;
}
/** Je online forma termínu zaplacená (a nevrácená)? */
function hybrid_charged(int $eventId): bool
{
    return $eventId > 0 && credit_ref_charged(TENANT, hybrid_charge_ref($eventId)) > 0;
}
/** Potřebuje termín teď zaplatit online formu a kredit na ni nestačí? */
function hybrid_credit_missing(int $eventId): bool
{
    return hybrid_paid() && !hybrid_charged($eventId) && !credit_enough(TENANT, hybrid_price_h());
}
/** Začalo už shromáždění (den konání, případně hodina)? */
function hybrid_started(array $ev): bool
{
    $now = date('Y-m-d H:i');
    $start = (string) $ev['event_date'] . ' ' . ((string) ($ev['event_time'] ?? '') !== '' ? $ev['event_time'] : '00:00');
    return $start <= $now;
}
/** Funkce je dostupná a správce domu ji zapnul. */
function hybrid_enabled(): bool
{
    return hybrid_available() && setting('hybrid_on') === '1';
}
/** Uloží nastavení domu (settings). */
function hybrid_setting_save(string $key, string $value): void
{
    q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
    setting('__reset');
}

// ---------- Nastavení termínu ----------

/** Nastavení formy termínu (výchozí: prezenční). */
function hybrid_event(int $eventId): array
{
    hybrid_ready();
    $row = one('SELECT * FROM hybrid_events WHERE event_id = ?', [$eventId]);
    return ($row ?: []) + ['event_id' => $eventId, 'form' => 'prezencni', 'video_url' => '', 'statutes_ok' => 0, 'statutes_by' => 0, 'statutes_at' => '', 'updated_at' => ''];
}
/** Termín, na kterém se dá účastnit online: funkce zapnutá, forma hybridní/online, potvrzené stanovy a odkaz na videohovor. */
function hybrid_remote(int $eventId): ?array
{
    if (!hybrid_enabled()) {
        return null;
    }
    $h = hybrid_event($eventId);
    return $h['form'] !== 'prezencni' && isset(HYBRID_FORMS[$h['form']]) && (int) $h['statutes_ok'] === 1 && $h['video_url'] !== '' ? $h : null;
}
/** Kontrola odkazu na videohovor; vrací text chyby, nebo null. */
function hybrid_url_error(string $url): ?string
{
    if ($url === '') {
        return 'Doplňte odkaz na videohovor (Jitsi, Microsoft Teams, Zoom, Google Meet…), nebo nechte vytvořit místnost Jitsi.';
    }
    if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || !parse_url($url, PHP_URL_HOST)) {
        return 'Odkaz na videohovor musí být celá adresa začínající https:// (zkopírujte ji z pozvánky do videohovoru).';
    }
    return null;
}
/** Služba podle adresy videohovoru (do zápisu a pro vlastníky). */
function hybrid_provider(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return match (true) {
        $host === '' => '',
        $host === 'meet.jit.si' || str_starts_with($host, 'jitsi.') || str_contains($host, '.jitsi.') => 'Jitsi Meet',
        $host === 'teams.microsoft.com' || $host === 'teams.live.com' || str_ends_with($host, '.teams.microsoft.com') => 'Microsoft Teams',
        $host === 'zoom.us' || str_ends_with($host, '.zoom.us') => 'Zoom',
        $host === 'meet.google.com' => 'Google Meet',
        default => 'videohovor (' . $host . ')',
    };
}
/** Nová místnost na veřejném Jitsi Meet s nehádatelným názvem (kdo zná adresu, může vstoupit – proto náhodná). */
function hybrid_jitsi_room(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
    $name = '';
    for ($i = 0; $i < 20; $i++) {
        $name .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'https://meet.jit.si/Shromazdeni-' . $name;
}
/** Údaje formy z odeslaného formuláře termínu: [chyby, data k uložení]. */
function hybrid_event_input(array $in): array
{
    $form = (string) ($in['hybrid_form'] ?? 'prezencni');
    $form = isset(HYBRID_FORMS[$form]) ? $form : 'prezencni';
    $url = trim((string) ($in['hybrid_video_url'] ?? ''));
    $ok = !empty($in['hybrid_statutes']);
    $errors = [];
    if ($form !== 'prezencni') {
        if (!$ok) {
            $errors[] = 'Hybridní nebo online shromáždění jde zapnout jen tehdy, když stanovy umožňují účast a hlasování technickými prostředky. Ověřte stanovy a potvrďte to zaškrtnutím – jinak zvolte prezenční formu.';
        }
        if ($err = hybrid_url_error($url)) {
            $errors[] = $err;
        }
    }
    return [$errors, ['form' => $form, 'video_url' => $form === 'prezencni' ? '' : $url, 'statutes_ok' => $form !== 'prezencni' && $ok ? 1 : 0]];
}
/** Háček formuláře termínu: chyby formy (prázdné, když funkce není zapnutá), včetně nedostatku kreditu v edici Standard. */
function hybrid_event_check(array $post, int $eventId = 0): array
{
    if (!hybrid_enabled()) {
        return [];
    }
    [$errors, $d] = hybrid_event_input($post);
    if (!$errors && $d['form'] !== 'prezencni' && hybrid_credit_missing($eventId)) {
        $errors[] = 'Online nebo hybridní shromáždění stojí v edici ' . EDITIONS[plan_edition()] . ' ' . credit_money(hybrid_price_h()) . ' bez DPH a platí se z kreditu služeb (zůstatek '
            . credit_money(credit_balance_h(TENANT)) . '). Dobijte kredit v části Kredit služeb, nebo zvolte prezenční formu. V edici Premium je v ceně.';
    }
    return $errors;
}
/** Háček formuláře termínu: uloží formu po uložení termínu. */
function hybrid_event_store(int $eventId, array $post, int $userId): void
{
    if (!hybrid_enabled()) {
        return;
    }
    [$errors, $d] = hybrid_event_input($post);
    if ($errors) {
        return;
    }
    $old = hybrid_event($eventId);
    $now = date('Y-m-d H:i:s');
    // Placení (edice Standard): poprvé online/hybridně → strhnout z kreditu; zpět na prezenční před začátkem, bez připojených → vrátit.
    if ($d['form'] !== 'prezencni' && hybrid_paid() && !hybrid_charged($eventId)) {
        $ev = one('SELECT * FROM events WHERE id = ?', [$eventId]) ?? ['title' => '', 'event_date' => ''];
        if (!credit_charge(TENANT, hybrid_price_h(), 'online', 'Shromáždění ' . ($d['form'] === 'online' ? 'online' : 'hybridně') . ': ' . mb_substr((string) $ev['title'], 0, 80)
            . ($ev['event_date'] !== '' ? ' ' . cz_date((string) $ev['event_date']) : ''), hybrid_charge_ref($eventId), 1, (string) (user()['name'] ?? ''))) {
            flash('Forma shromáždění se nezměnila: na online formu (' . credit_money(hybrid_price_h()) . ') nestačí kredit služeb. Dobijte ho v části Kredit služeb.', 'error');
            return;
        }
    }
    if ($d['form'] === 'prezencni' && $old['form'] !== 'prezencni' && hybrid_charged($eventId)) {
        $ev = one('SELECT * FROM events WHERE id = ?', [$eventId]);
        if ($ev && !hybrid_started($ev) && !(int) val('SELECT COUNT(*) FROM hybrid_attendance WHERE event_id = ?', [$eventId])) {
            credit_reverse(TENANT, hybrid_charge_ref($eventId), 'vráceno – shromáždění přepnuto na prezenční před začátkem');
        }
    }
    $by = $d['statutes_ok'] ? ((int) $old['statutes_ok'] === 1 ? (int) $old['statutes_by'] : $userId) : 0;
    $at = $d['statutes_ok'] ? ((int) $old['statutes_ok'] === 1 ? (string) $old['statutes_at'] : $now) : '';
    q('INSERT INTO hybrid_events (event_id, form, video_url, statutes_ok, statutes_by, statutes_at, updated_at) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(event_id) DO UPDATE SET form = excluded.form, video_url = excluded.video_url, statutes_ok = excluded.statutes_ok, statutes_by = excluded.statutes_by, statutes_at = excluded.statutes_at, updated_at = excluded.updated_at',
        [$eventId, $d['form'], $d['video_url'], $d['statutes_ok'], $by, $at, $now]);
    if ($d['form'] !== $old['form']) {
        audit('Forma shromáždění', HYBRID_FORMS[$d['form']] . ($d['statutes_ok'] ? ' · stanovy potvrzeny' : ''));
    }
}
/** Háček formuláře termínu: pole Forma, odkaz a potvrzení stanov. */
function hybrid_form_fields(int $eventId): void
{
    if (!hybrid_enabled()) {
        return;
    }
    $h = hybrid_event($eventId);
    if (($GLOBALS['METHOD'] ?? 'GET') === 'POST' && isset($_POST['hybrid_form'])) {
        $h = array_merge($h, hybrid_event_input($_POST)[1], ['video_url' => trim(post('hybrid_video_url'))]);
    }
    $jitsi = hybrid_jitsi_room();
    require __DIR__ . '/../views/ext/hybrid/_event_fields.php';
}

// ---------- Účast online ----------

/** Jednotky vlastníka (seřazené). */
function hybrid_user_units(array $user): array
{
    if (($user['kind'] ?? '') !== 'owner') {
        return [];
    }
    return all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ? ORDER BY un.entrance, un.number', [(int) $user['id']]);
}
/** Prezence jednotek vlastníka na termínu: unit_id => mode ('' = nepřítomen). */
function hybrid_user_modes(int $eventId, array $user): array
{
    $out = [];
    foreach (hybrid_user_units($user) as $un) {
        $out[(int) $un['id']] = (string) (val('SELECT mode FROM event_presence WHERE event_id = ? AND unit_id = ?', [$eventId, $un['id']]) ?: '');
    }
    return $out;
}
/** Je vlastník právě přítomen online (alespoň za jednu jednotku)? */
function hybrid_is_online(int $eventId, array $user): bool
{
    return in_array('online', hybrid_user_modes($eventId, $user), true);
}
/** Proč se vlastník nemůže připojit (text), nebo null. */
function hybrid_join_error(int $eventId, array $user): ?string
{
    $ev = one('SELECT * FROM events WHERE id = ?', [$eventId]);
    if (!$ev || !hybrid_remote($eventId)) {
        return 'Tento termín se nekoná online.';
    }
    if (($user['kind'] ?? '') !== 'owner' || !hybrid_user_units($user)) {
        return 'Online se připojuje vlastník jednotky. K Vašemu účtu není přiřazena žádná jednotka, nebo nejste vedeni jako vlastník – obraťte se prosím na výbor.';
    }
    if ($ev['event_date'] !== date('Y-m-d')) {
        return $ev['event_date'] > date('Y-m-d') ? 'Připojit se půjde v den konání shromáždění (' . cz_date($ev['event_date'], true) . ').' : 'Shromáždění už proběhlo.';
    }
    if (function_exists('presence_blocked') && presence_blocked($eventId)) {
        return 'Výbor ještě nemá doplněné spoluvlastnické podíly všech jednotek, proto prezence zatím nejde zahájit. Obraťte se prosím na výbor.';
    }
    $modes = hybrid_user_modes($eventId, $user);
    if ($modes && !array_filter($modes, fn($m) => $m === '' || $m === 'online')) {
        return 'Všechny Vaše jednotky už jsou zapsané v prezenci na místě (osobně nebo přes zmocněnce).';
    }
    return null;
}
/**
 * Zapíše vlastníka do prezence jako přítomného online (za všechny jeho jednotky, které ještě nejsou přítomné na místě).
 * Vrací ID zapsaných jednotek. $verified: 'login' (přihlášení na web) nebo 'email' (navíc kód z e-mailu).
 */
function hybrid_join(int $eventId, array $user, string $verified = 'login', ?string $ip = null): array
{
    hybrid_ready();
    if (hybrid_join_error($eventId, $user) !== null) {
        return [];
    }
    $now = date('Y-m-d H:i:s');
    $ip ??= client_ip();
    $joined = [];
    foreach (hybrid_user_modes($eventId, $user) as $unitId => $mode) {
        if ($mode === 'self' || $mode === 'proxy') {
            continue;   // na místě (osobně / zmocněnec) – online se nepřepisuje
        }
        if ($mode !== 'online') {
            q("INSERT INTO event_presence (event_id, unit_id, mode, proxy_name, proxy_ok, checked_by, checked_at) VALUES (?,?,'online','',0,?,?)
                ON CONFLICT(event_id, unit_id) DO UPDATE SET mode = excluded.mode, proxy_name = excluded.proxy_name, proxy_ok = excluded.proxy_ok, checked_by = excluded.checked_by, checked_at = excluded.checked_at",
                [$eventId, $unitId, (int) $user['id'], $now]);
        }
        if (!val("SELECT 1 FROM hybrid_attendance WHERE event_id = ? AND unit_id = ? AND left_at = ''", [$eventId, $unitId])) {
            q("INSERT INTO hybrid_attendance (event_id, unit_id, user_id, joined_at, left_at, ip, verified) VALUES (?,?,?,?,'',?,?)",
                [$eventId, $unitId, (int) $user['id'], $now, mb_substr($ip, 0, 45), $verified === 'email' ? 'email' : 'login']);
        }
        $joined[] = $unitId;
    }
    return $joined;
}
/** Vlastník odchází: jeho online jednotky přestanou být přítomné (uzavřená hlasování se nemění). Vrací počet jednotek. */
function hybrid_leave(int $eventId, array $user): int
{
    hybrid_ready();
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach (hybrid_user_modes($eventId, $user) as $unitId => $mode) {
        if ($mode === 'online') {
            q("DELETE FROM event_presence WHERE event_id = ? AND unit_id = ? AND mode = 'online'", [$eventId, $unitId]);
            $n++;
        }
        q("UPDATE hybrid_attendance SET left_at = ? WHERE event_id = ? AND unit_id = ? AND left_at = ''", [$now, $eventId, $unitId]);
    }
    return $n;
}
/** Záznamy online účasti k termínu (s jednotkou a jménem), pro správce, zápis a prezenční listinu. */
function hybrid_attendance_log(int $eventId): array
{
    hybrid_ready();
    return all('SELECT a.*, un.entrance, un.number, un.owner_note, us.name AS user_name FROM hybrid_attendance a
        JOIN units un ON un.id = a.unit_id LEFT JOIN users us ON us.id = a.user_id WHERE a.event_id = ? ORDER BY a.joined_at, a.id', [$eventId]);
}

// ---------- Ověření kódem z e-mailu (volitelné, nastavení 'hybrid_email_code') ----------

function hybrid_code_required(): bool
{
    return setting('hybrid_email_code') === '1';
}
/** Pošle vlastníkovi jednorázový kód (platí 10 minut); v relaci zůstane jen jeho otisk. */
function hybrid_code_send(int $eventId, array $user): bool
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['hybrid_code'][$eventId] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + HYBRID_CODE_TTL, 'tries' => 0, 'uid' => (int) $user['id']];
    $title = (string) val('SELECT title FROM events WHERE id = ?', [$eventId]);
    return send_mail((string) $user['email'], 'Kód pro připojení ke shromáždění: ' . $code,
        "Dobrý den,\n\nkód pro připojení ke shromáždění „{$title}“ online je:\n\n    {$code}\n\nKód platí 10 minut. Pokud jste se nepřipojoval(a) Vy, kód nikomu nesdělujte a dejte vědět výboru.\n\n" . site('full'));
}
/** Ověří kód z e-mailu; po pěti chybných pokusech nebo po vypršení je potřeba poslat nový. */
function hybrid_code_check(int $eventId, array $user, string $code): bool
{
    $c = $_SESSION['hybrid_code'][$eventId] ?? null;
    if (!$c || $c['uid'] !== (int) $user['id'] || $c['until'] < time() || $c['tries'] >= HYBRID_CODE_TRIES) {
        return false;
    }
    $_SESSION['hybrid_code'][$eventId]['tries']++;
    if (!password_verify(preg_replace('/\D/', '', $code), $c['hash'])) {
        return false;
    }
    unset($_SESSION['hybrid_code'][$eventId]);
    return true;
}
function hybrid_code_pending(int $eventId, array $user): bool
{
    $c = $_SESSION['hybrid_code'][$eventId] ?? null;
    return $c && $c['uid'] === (int) $user['id'] && $c['until'] >= time() && $c['tries'] < HYBRID_CODE_TRIES;
}

// ---------- Hlasování online ----------

/** Předsedající pustí (nebo zastaví) hlasování online o otevřeném návrhu. */
function hybrid_live_set(int $eventId, int $motionId, bool $on, int $userId = 0): bool
{
    hybrid_ready();
    $m = one('SELECT * FROM meeting_motions WHERE id = ? AND event_id = ?', [$motionId, $eventId]);
    if (!$m || ($on && $m['status'] === 'closed')) {
        return false;
    }
    q('DELETE FROM hybrid_live WHERE motion_id = ?', [$motionId]);
    if ($on) {
        q('INSERT INTO hybrid_live (motion_id, opened_at, opened_by) VALUES (?,?,?)', [$motionId, date('Y-m-d H:i:s'), $userId]);
    }
    return true;
}
/** Návrhy, o kterých se právě hlasuje online (otevřené a puštěné předsedajícím). */
function hybrid_live_motions(int $eventId): array
{
    hybrid_ready();
    return all("SELECT m.*, l.opened_at FROM meeting_motions m JOIN hybrid_live l ON l.motion_id = m.id WHERE m.event_id = ? AND m.status <> 'closed' ORDER BY m.sort, m.id", [$eventId]);
}
/** ID návrhů puštěných k hlasování online (i uzavřených – kvůli zobrazení u správce). */
function hybrid_live_ids(int $eventId): array
{
    hybrid_ready();
    return array_map('intval', array_column(all('SELECT l.motion_id FROM hybrid_live l JOIN meeting_motions m ON m.id = l.motion_id WHERE m.event_id = ?', [$eventId]), 'motion_id'));
}
/** Hlas online přítomného vlastníka za všechny jeho online jednotky. Vrací text chyby, nebo null. */
function hybrid_vote(int $eventId, int $motionId, array $user, string $choice): ?string
{
    hybrid_ready();
    if (!isset(MOTION_CHOICES[$choice])) {
        return 'Vyberte pro, proti, nebo zdržel se.';
    }
    if (!hybrid_remote($eventId)) {
        return 'Tento termín se nekoná online.';
    }
    $live = array_column(hybrid_live_motions($eventId), null, 'id');
    if (!isset($live[$motionId])) {
        return 'O tomto návrhu se online nehlasuje – hlasování ještě nezačalo, nebo už skončilo.';
    }
    $units = array_keys(array_filter(hybrid_user_modes($eventId, $user), fn($m) => $m === 'online'));
    if (!$units) {
        return 'Hlasovat online může jen vlastník, který je ke shromáždění připojený online.';
    }
    $now = date('Y-m-d H:i:s');
    foreach ($units as $unitId) {
        q("INSERT INTO meeting_votes (motion_id, unit_id, choice, method, voted_at) VALUES (?,?,?,'online',?)
            ON CONFLICT(motion_id, unit_id) DO UPDATE SET choice = excluded.choice, method = excluded.method, voted_at = excluded.voted_at", [$motionId, $unitId, $choice, $now]);
    }
    audit('Hlas online', $live[$motionId]['title'] . ' · ' . mb_strtolower(MOTION_CHOICES[$choice]) . ' · jednotek ' . count($units), (int) $user['id']);
    return null;
}
/** Hlasy vlastníka k návrhům: motion_id => choice (jen jeho online jednotky; první nalezený). */
function hybrid_my_votes(int $eventId, array $user): array
{
    hybrid_ready();
    $ids = array_map(fn($u) => (int) $u['id'], hybrid_user_units($user));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (all("SELECT v.motion_id, v.choice FROM meeting_votes v JOIN meeting_motions m ON m.id = v.motion_id WHERE m.event_id = ? AND v.method = 'online' AND v.unit_id IN (" . implode(',', $ids) . ') ORDER BY v.unit_id', [$eventId]) as $v) {
        $out[(int) $v['motion_id']] ??= $v['choice'];
    }
    return $out;
}
/** Hlasy odevzdané online k termínu (pro prezenční listinu a kontrolu). */
function hybrid_online_votes(int $eventId): array
{
    hybrid_ready();
    return all("SELECT v.*, m.title, m.sort, un.entrance, un.number FROM meeting_votes v JOIN meeting_motions m ON m.id = v.motion_id JOIN units un ON un.id = v.unit_id
        WHERE m.event_id = ? AND v.method = 'online' ORDER BY m.sort, m.id, un.entrance, un.number + 0, un.number", [$eventId]);
}
/** Kolik hlasů přišlo online a kolik na místě (z výsledku motion_result). */
function hybrid_motion_split(array $res): string
{
    $by = $res['byMode'] ?? null;
    if (!$by || array_sum($by['online'] ?? []) === 0) {
        return '';
    }
    $part = fn(array $c) => 'pro ' . (int) $c['yes'] . ', proti ' . (int) $c['no'] . ', zdrželo se ' . (int) $c['abstain'];
    return 'Online ' . $part($by['online']) . '; na místě ' . $part($by['site']) . ' (počet jednotek).';
}

// ---------- Stav pro průběžnou aktualizaci ----------

/** Stav pro obrazovku správce (JSON každé 4 s): souhrn prezence, režim jednotek a průběžné výsledky otevřených návrhů. */
function hybrid_admin_state(int $eventId): array
{
    $p = presence($eventId);
    $modes = [];
    foreach ($p['rows'] as $r) {
        $modes[(int) $r['id']] = (string) ($r['mode'] ?? '');
    }
    $motions = [];
    foreach (meeting_motions($eventId, $p) as $m) {
        if ($m['status'] !== 'closed') {
            $motions[$m['id']] = motion_summary($m['res']) + ['online' => hybrid_motion_split($m['res'])];
        }
    }
    return ['ok' => true, 'online' => (int) ($p['counts']['online'] ?? 0), 'modes' => $modes, 'motions' => $motions, 'live' => hybrid_live_ids($eventId)] + presence_summary($p);
}
/** Otisk stavu pro vlastníka: když se změní (nový návrh k hlasování, konec hlasování, odpojení), stránka se načte znovu. */
function hybrid_owner_signature(int $eventId, array $user): string
{
    $live = array_map(fn($m) => $m['id'] . ':' . $m['status'], hybrid_live_motions($eventId));
    return substr(hash('sha256', json_encode([$live, hybrid_user_modes($eventId, $user), hybrid_my_votes($eventId, $user)])), 0, 16);
}

// ---------- Výstupy do obrazovek (háčky ve sdílených šablonách) ----------

/** Rámeček na stránce termínu pro vlastníky. */
function hybrid_event_box(array $ev): void
{
    $h = hybrid_remote((int) $ev['id']);
    $u = user();
    if (!$h || !$u) {
        return;
    }
    $error = hybrid_join_error((int) $ev['id'], $u);
    $online = hybrid_is_online((int) $ev['id'], $u);
    require __DIR__ . '/../views/ext/hybrid/_event_box.php';
}
/** Panel na obrazovce Prezence na místě: online účastníci, pouštění návrhů k hlasování online, průběžná aktualizace. */
function hybrid_presence_panel(array $ev, array $p, array $motions): void
{
    $h = hybrid_remote((int) $ev['id']);
    if (!$h) {
        return;
    }
    $log = hybrid_attendance_log((int) $ev['id']);
    $live = hybrid_live_ids((int) $ev['id']);
    require __DIR__ . '/../views/ext/hybrid/_presence_panel.php';
}
/** Odstavec do zápisu: forma shromáždění a kdo se účastnil online. */
function hybrid_minutes_block(array $ev): void
{
    if (!hybrid_available()) {
        return;
    }
    $h = hybrid_event((int) $ev['id']);
    $log = hybrid_attendance_log((int) $ev['id']);
    if ($h['form'] === 'prezencni' && !$log) {
        return;
    }
    require __DIR__ . '/../views/ext/hybrid/_minutes.php';
}
/** Příloha prezenční listiny: online účast a hlasy odevzdané online. */
function hybrid_protocol_block(array $ev): void
{
    if (!hybrid_available()) {
        return;
    }
    $log = hybrid_attendance_log((int) $ev['id']);
    $votes = hybrid_online_votes((int) $ev['id']);
    if (!$log && !$votes) {
        return;
    }
    require __DIR__ . '/../views/ext/hybrid/_protocol.php';
}

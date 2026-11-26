<?php
declare(strict_types=1);

/*
 * Modul B – hlasování mimo zasedání (per rollam, § 1211–1214 zákona č. 89/2012 Sb.) s průkaznou stopou.
 *
 * - Ověření hlasu podle nastavení hlasování: žádné / kód z e-mailu / kód z SMS / Bank iD (beta). Hlas vlastníka se uloží
 *   až po potvrzení (kód platí 10 minut, nejvýše 5 pokusů); do té doby leží jen jako rozpracovaný v rollam_pending.
 * - Každý zápis hlasu (web, listina, správce) dostane záznam v rollam_records: čas, IP, prohlížeč, způsob ověření,
 *   kdo zapsal a otisk navazující na předchozí záznam téhož hlasování (řetěz jako audit_log, viz audit() v app/lib.php).
 * - Protokol o hlasování, hlasovací lístky k tisku a zápis listinného hlasu se skenem (app/ext/routes_rollam.php).
 * Výsledky počítá dál poll_results() z poll_votes – online i listinné hlasy jsou v téže tabulce.
 * Tabulky se založí samy při prvním použití.
 */

const ROLLAM_VERIFY = ['none' => 'Žádné (stačí přihlášení)', 'email' => 'Kód z e-mailu', 'sms' => 'Kód z SMS', 'bankid' => 'Bank iD (beta)'];
const ROLLAM_SOURCES = ['web' => 'web (přihlášení)', 'email' => 'web + kód z e-mailu', 'sms' => 'web + kód z SMS', 'bankid' => 'web + Bank iD',
    'listina' => 'listinný hlas', 'spravce' => 'zapsal správce'];
const ROLLAM_CODE_TTL = 600;
const ROLLAM_CODE_ATTEMPTS = 5;
const ROLLAM_CODE_SENDS = 3;
/** Hláška vlastníkovi, když kredit domu nestačí na ověření přes Bank iD. */
const ROLLAM_BANKID_NO_CREDIT = 'Ověření hlasu přes Bank iD je dočasně nedostupné: společenství nemá předplacený kredit na tuto službu. Výbor jsme upozornili, aby kredit dobil – zkuste to prosím později, nebo se obraťte na výbor (můžete hlasovat i listinně).';

function rollam_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'rollam_records'")->fetch() : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'rollam_records'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE rollam_polls (poll_id $int NOT NULL PRIMARY KEY, verify {$s(10)})$tail");
    $pdo->exec("CREATE TABLE rollam_records (id $pk, poll_id $int NOT NULL, unit_id $int NOT NULL, user_id $int NOT NULL DEFAULT 0, voter_name {$s(191)},"
        . " choices $text, method {$s(10)}, verified {$s(255)}, ip {$s(45)}, ua {$s(255)}, entered_by $int NOT NULL DEFAULT 0, entered_name {$s(191)},"
        . " signed_on {$s(10)}, received_on {$s(10)}, scan_file {$s(64)}, scan_name {$s(255)}, note {$s(255)}, at {$s(19)}, hash {$s(64)})$tail");
    $pdo->exec('CREATE INDEX rollam_records_poll ON rollam_records (poll_id, id)');
    $pdo->exec("CREATE TABLE rollam_pending (id $pk, poll_id $int NOT NULL, unit_id $int NOT NULL, user_id $int NOT NULL, choices $text, method {$s(10)},"
        . " code_hash {$s(64)}, sends $int NOT NULL DEFAULT 0, sent_at {$s(19)}, attempts $int NOT NULL DEFAULT 0, expires_at {$s(19)}, status {$s(10)},"
        . " ip {$s(45)}, ua {$s(255)}, created_at {$s(19)})$tail");
    $pdo->exec("CREATE TABLE rollam_identities (user_id $int NOT NULL PRIMARY KEY, sub_hash {$s(64)}, name {$s(191)}, birthdate {$s(10)}, at {$s(19)})$tail");
}

// ---------- Nastavení hlasování ----------

function rollam_method(int $pollId): string
{
    rollam_ready();
    $m = (string) val('SELECT verify FROM rollam_polls WHERE poll_id = ?', [$pollId]);
    return isset(ROLLAM_VERIFY[$m]) ? $m : 'none';
}
/** Způsoby ověření, které lze teď v tomto domě zvolit (SMS jen s modulem SMS, Bank iD jen zapnuté a nastavené). */
function rollam_methods_available(): array
{
    $out = ['none' => ROLLAM_VERIFY['none'], 'email' => ROLLAM_VERIFY['email']];
    if (function_exists('sms_send')) {
        $out['sms'] = ROLLAM_VERIFY['sms'];
    }
    if (function_exists('bankid_available') && bankid_available()) {
        $out['bankid'] = ROLLAM_VERIFY['bankid'];
    }
    return $out;
}
/** Uloží způsob ověření. Nedostupnou volbu odmítne (vrací text chyby), stávající nastavení pak zůstane. */
function rollam_set_method(int $pollId, string $method): ?string
{
    rollam_ready();
    if (!isset(rollam_methods_available()[$method]) && $method !== rollam_method($pollId)) {
        return 'Zvolený způsob ověření hlasu teď není k dispozici.';
    }
    q('INSERT INTO rollam_polls (poll_id, verify) VALUES (?,?) ON CONFLICT(poll_id) DO UPDATE SET verify = excluded.verify', [$pollId, isset(ROLLAM_VERIFY[$method]) ? $method : 'none']);
    return null;
}
/** Háček z formuláře hlasování (app/routes_extra.php, po uložení): uloží volbu „Ověření hlasu“; nedostupnou volbu ohlásí místo běžné zprávy. */
function rollam_poll_saved(int $pollId): void
{
    if (!isset($_POST['verify'])) {
        return;
    }
    if ($err = rollam_set_method($pollId, post('verify'))) {
        flash('Hlasování bylo uloženo, ale ' . mb_strtolower(mb_substr($err, 0, 1)) . mb_substr($err, 1) . ' Ověření hlasu zůstalo: ' . ROLLAM_VERIFY[rollam_method($pollId)] . '.', 'info');
    }
}
/** Varování k hlasování (lhůta, většina) pro formulář a protokol. */
function rollam_warnings(array $poll): array
{
    $w = [];
    if (valid_date((string) $poll['opens_at']) && valid_date((string) $poll['closes_at'])) {
        $days = (int) ((strtotime($poll['closes_at']) - strtotime($poll['opens_at'])) / 86400) + 1;
        if ($days < 15) {
            $w[] = 'Lhůta pro vyjádření je ' . $days . ' dní. Zákon (§ 1211) počítá s nejméně 15 dny od doručení návrhu, neurčí-li stanovy delší lhůtu.';
        }
    }
    if ((int) $poll['threshold'] < 50) {
        $w[] = 'Rozhodnutí mimo zasedání se přijímá většinou hlasů všech vlastníků (§ 1214); nastavená hranice ' . (int) $poll['threshold'] . ' % je nižší, protokol proto počítá s většinou (nad 50 %).';
    }
    return $w;
}

// ---------- Záznamy hlasů s navazujícími otisky ----------

function rollam_choices_json(array $choices): string
{
    $out = [];
    foreach ($choices as $itemId => $c) {
        if (isset(CHOICES[$c])) {
            $out[(int) $itemId] = (string) $c;
        }
    }
    ksort($out);
    return (string) json_encode((object) $out);
}
function rollam_hash(string $prev, array $r): string
{
    return hash('sha256', implode('|', [$prev, $r['poll_id'], $r['unit_id'], $r['voter_name'], $r['choices'], $r['method'], $r['verified'], $r['ip'], $r['ua'],
        $r['entered_name'], $r['signed_on'], $r['received_on'], $r['scan_file'], $r['note'], $r['at']]));
}

/**
 * Zapíše záznam o hlasu do řetězu daného hlasování. $meta: user_id, voter_name, verified, entered_by, entered_name, signed_on,
 * received_on, scan_file, scan_name, note, ip, ua. Vrací id záznamu.
 */
function rollam_record(int $pollId, int $unitId, array $choices, string $method, array $meta = []): int
{
    rollam_ready();
    $pdo = db();
    $mysql = db_driver() === 'mysql';
    $own = !$pdo->inTransaction();
    if ($mysql) {
        $pdo->query("SELECT GET_LOCK('svj_rollam', 5)");
    }
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $web = PHP_SAPI !== 'cli' || isset($_SERVER['REMOTE_ADDR']);
        $r =['poll_id' => $pollId, 'unit_id' => $unitId, 'user_id' => (int) ($meta['user_id'] ?? 0), 'voter_name' => mb_substr((string) ($meta['voter_name'] ?? ''), 0, 191),
            'choices' => rollam_choices_json($choices), 'method' => isset(ROLLAM_SOURCES[$method]) ? $method : 'web', 'verified' => mb_substr((string) ($meta['verified'] ?? ''), 0, 255),
            'ip' => (string) ($meta['ip'] ?? ($web ? client_ip() : '')), 'ua' => mb_substr((string) ($meta['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            'entered_by' => (int) ($meta['entered_by'] ?? 0), 'entered_name' => mb_substr((string) ($meta['entered_name'] ?? ''), 0, 191),
            'signed_on' => (string) ($meta['signed_on'] ?? ''), 'received_on' => (string) ($meta['received_on'] ?? ''), 'scan_file' => (string) ($meta['scan_file'] ?? ''),
            'scan_name' => mb_substr((string) ($meta['scan_name'] ?? ''), 0, 255), 'note' => mb_substr((string) ($meta['note'] ?? ''), 0, 255), 'at' => date('Y-m-d H:i:s')];
        q('INSERT INTO rollam_records (' . implode(', ', array_keys($r)) . ', hash) VALUES (' . str_repeat('?,', count($r)) . "'')", array_values($r));
        $id = (int) $pdo->lastInsertId();
        $prev = (string) val('SELECT hash FROM rollam_records WHERE poll_id = ? AND id < ? ORDER BY id DESC LIMIT 1', [$pollId, $id]);
        q('UPDATE rollam_records SET hash = ? WHERE id = ?', [rollam_hash($prev, $r), $id]);
        if ($own) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        if ($mysql) {
            $pdo->query("SELECT RELEASE_LOCK('svj_rollam')");
        }
    }
}
/** Aktuální hlasy jednotky v hlasování [item_id => choice]. */
function rollam_unit_votes(int $pollId, int $unitId): array
{
    return array_column(all('SELECT v.item_id, v.choice FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ? AND v.unit_id = ?', [$pollId, $unitId]), 'choice', 'item_id');
}
/** Háček ze zápisu hlasu správcem (app/routes_extra.php): zaznamená stav hlasů jednotky po zápisu. */
function rollam_record_unit(int $pollId, int $unitId, string $method = 'spravce', ?array $by = null): void
{
    $u = $by ?? user();
    rollam_record($pollId, $unitId, rollam_unit_votes($pollId, $unitId), $method, ['entered_by' => (int) ($u['id'] ?? 0), 'entered_name' => (string) ($u['name'] ?? ''),
        'voter_name' => (string) val('SELECT owner_note FROM units WHERE id = ?', [$unitId])]);
}

/**
 * Uloží hlas jednotky do poll_votes a zapíše záznam – obojí v jedné transakci.
 * $replace = false: když už jednotka hlasovala, nic neuloží a vrátí text chyby. Vrací id záznamu nebo text chyby.
 */
function rollam_commit(int $pollId, int $unitId, array $choices, string $method, array $meta, bool $replace = false): int|string
{
    rollam_ready();
    $items = array_map('intval', array_column(all('SELECT id FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$pollId]), 'id'));
    $choices = array_filter(array_intersect_key($choices, array_flip($items)), fn($c) => isset(CHOICES[$c]));
    if (!$choices) {
        return 'Hlas neobsahuje žádnou volbu.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (rollam_unit_votes($pollId, $unitId) && !$replace) {
            $pdo->rollBack();
            return 'Za tuto jednotku už bylo odhlasováno.';
        }
        foreach ($items as $itemId) {
            if (isset($choices[$itemId])) {
                q("INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?,?,?,?)
                    ON CONFLICT(item_id, unit_id) DO UPDATE SET user_id = excluded.user_id, choice = excluded.choice, voted_at = datetime('now','localtime')",
                    [$itemId, $unitId, ($meta['user_id'] ?? 0) ?: ($meta['entered_by'] ?? null) ?: null, $choices[$itemId]]);
            } elseif ($replace) {
                q('DELETE FROM poll_votes WHERE item_id = ? AND unit_id = ?', [$itemId, $unitId]);
            }
        }
        $id = rollam_record($pollId, $unitId, $choices, $method, $meta);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rollam_records(int $pollId): array
{
    rollam_ready();
    return all('SELECT * FROM rollam_records WHERE poll_id = ? ORDER BY id', [$pollId]);
}
/**
 * Kontrola řetězu otisků a shody se skutečnými hlasy.
 * Vrací ['ok', 'bad' (id prvního vadného záznamu), 'count', 'head' (otisk posledního záznamu), 'mismatch' (jednotky, jejichž hlasy neodpovídají poslednímu záznamu)].
 */
function rollam_chain_check(int $pollId): array
{
    $prev = '';
    $count = 0;
    $bad = 0;
    $last = [];
    foreach (rollam_records($pollId) as $r) {
        if (!$bad && !hash_equals(rollam_hash($prev, $r), (string) $r['hash'])) {
            $bad = (int) $r['id'];
        }
        $prev = (string) $r['hash'];
        $last[(int) $r['unit_id']] = (string) $r['choices'];
        $count++;
    }
    $current = [];
    foreach (all('SELECT v.unit_id, v.item_id, v.choice FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ?', [$pollId]) as $v) {
        $current[(int) $v['unit_id']][(int) $v['item_id']] = $v['choice'];
    }
    $mismatch = [];
    foreach ($current + array_fill_keys(array_keys($last), []) as $unitId => $ch) {
        if (rollam_choices_json($ch) !== ($last[$unitId] ?? rollam_choices_json([]))) {
            $mismatch[] = (int) $unitId;
        }
    }
    sort($mismatch);
    return ['ok' => !$bad && !$mismatch, 'bad' => $bad, 'count' => $count, 'head' => $prev, 'mismatch' => $mismatch];
}

// ---------- Hlasování vlastníka přes web (s ověřením) ----------

function rollam_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 2) . '…@' . $domain;
}
function rollam_choice_lines(array $items, array $choices): string
{
    $out = [];
    foreach (array_values($items) as $n => $it) {
        $out[] = ($n + 1) . '. ' . $it['question'] . ' — ' . (CHOICES[$choices[$it['id']] ?? ''] ?? 'bez hlasu');
    }
    return implode("\n", $out);
}

/**
 * Začátek hlasování vlastníka (po kontrolách v app/routes_extra.php). Podle nastavení hlas rovnou uloží, nebo ho odloží
 * a pošle kód / připraví Bank iD. Vrací ['status' => stored|code|bankid|error, 'pending' => id, 'error' => text].
 */
function rollam_begin(array $poll, array $user, int $unitId, array $choices): array
{
    rollam_ready();
    $method = rollam_method((int) $poll['id']);
    $choices = array_map('strval', $choices);
    $voter = ['user_id' => (int) $user['id'], 'voter_name' => (string) $user['name']];
    if ($method === 'none') {
        $r = rollam_commit((int) $poll['id'], $unitId, $choices, 'web', $voter + ['verified' => 'přihlášení k účtu ' . rollam_mask_email((string) $user['email'])]);
        return is_int($r) ? ['status' => 'stored', 'record' => $r] : ['status' => 'error', 'error' => $r];
    }
    if ($method === 'sms' && (!function_exists('sms_send') || trim((string) ($user['phone'] ?? '')) === '')) {
        return ['status' => 'error', 'error' => function_exists('sms_send') ? 'Hlas se potvrzuje kódem z SMS. Doplňte si prosím telefon v Mém účtu.' : 'Potvrzení hlasu přes SMS teď nefunguje. Obraťte se prosím na výbor.'];
    }
    if ($method === 'bankid' && !(function_exists('bankid_available') && bankid_available())) {
        return ['status' => 'error', 'error' => 'Ověření přes Bank iD teď není k dispozici. Obraťte se prosím na výbor.'];
    }
    if ($method === 'bankid' && !bankid_credit_ok()) {
        bankid_credit_alert();
        return ['status' => 'error', 'error' => ROLLAM_BANKID_NO_CREDIT];
    }
    // Ochrana před zahlcením: nejvýše 5 rozpracovaných hlasů jednoho člověka v jednom hlasování za hodinu.
    if ((int) val('SELECT COUNT(*) FROM rollam_pending WHERE poll_id = ? AND user_id = ? AND created_at > ?', [$poll['id'], $user['id'], date('Y-m-d H:i:s', time() - 3600)]) >= 5) {
        return ['status' => 'error', 'error' => 'Příliš mnoho pokusů. Zkuste to prosím za hodinu.'];
    }
    $now = date('Y-m-d H:i:s');
    q("INSERT INTO rollam_pending (poll_id, unit_id, user_id, choices, method, expires_at, status, ip, ua, created_at) VALUES (?,?,?,?,?,?,'pending',?,?,?)",
        [$poll['id'], $unitId, $user['id'], rollam_choices_json($choices), $method, date('Y-m-d H:i:s', time() + ROLLAM_CODE_TTL), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $now]);
    $pid = (int) db()->lastInsertId();
    if ($method === 'bankid') {
        return ['status' => 'bankid', 'pending' => $pid];
    }
    $sent = rollam_send_code($pid);
    return $sent === null ? ['status' => 'code', 'pending' => $pid] : ['status' => 'error', 'error' => $sent, 'pending' => $pid];
}

function rollam_pending(int $pid): ?array
{
    rollam_ready();
    return one('SELECT * FROM rollam_pending WHERE id = ?', [$pid]);
}

/** Vygeneruje nový kód, pošle ho (e-mailem / SMS) a prodlouží platnost na 10 minut. Vrací null, nebo text chyby. */
function rollam_send_code(int $pid): ?string
{
    $p = rollam_pending($pid);
    if (!$p || $p['status'] !== 'pending' || !in_array($p['method'], ['email', 'sms'], true)) {
        return 'Tento hlas už nečeká na potvrzení.';
    }
    if ((int) $p['sends'] >= ROLLAM_CODE_SENDS) {
        return 'Kód už byl odeslán ' . ROLLAM_CODE_SENDS . '×. Začněte prosím hlasovat znovu.';
    }
    if ($p['sent_at'] !== '' && strtotime($p['sent_at']) > time() - 60) {
        return 'Nový kód můžete poslat nejdříve za minutu.';
    }
    $user = one('SELECT * FROM users WHERE id = ?', [$p['user_id']]);
    $poll = one('SELECT * FROM polls WHERE id = ?', [$p['poll_id']]);
    $unit = one('SELECT * FROM units WHERE id = ?', [$p['unit_id']]);
    if (!$user || !$poll || !$unit) {
        return 'Hlasování nebo jednotka už neexistuje.';
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $items = all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$poll['id']]);
    if ($p['method'] === 'sms') {
        $ok = function_exists('sms_send') && sms_send((string) $user['phone'], 'Kod pro potvrzeni hlasu (' . strip_accents(mb_substr($poll['title'], 0, 40)) . ', jednotka ' . strip_accents(unit_name($unit)) . '): ' . $code . '. Plati 10 minut.');
    } else {
        $ok = send_mail((string) $user['email'], 'Kód pro potvrzení hlasu: ' . $code,
            "Dobrý den,\n\npro potvrzení hlasu zadejte na webu kód:\n\n    $code\n\nKód platí 10 minut.\n\nHlasování: {$poll['title']}\nJednotka: " . unit_name($unit)
            . "\n\nVaše volba:\n" . rollam_choice_lines($items, (array) json_decode((string) $p['choices'], true))
            . "\n\nHlas se uloží až po zadání kódu. Pokud jste nehlasoval(a) Vy, kód nikomu nesdělujte a dejte vědět výboru.\n\n" . site('name'));
    }
    if (!$ok) {
        return 'Kód se nepodařilo odeslat. Zkuste to prosím znovu za chvíli.';
    }
    q('UPDATE rollam_pending SET code_hash = ?, sends = sends + 1, sent_at = ?, attempts = 0, expires_at = ? WHERE id = ?',
        [hash_hmac('sha256', $code, 'rollam|' . $pid), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + ROLLAM_CODE_TTL), $pid]);
    return null;
}

/** Kam byl kód poslán (pro stránku potvrzení a záznam). */
function rollam_code_target(array $p): string
{
    $user = one('SELECT email, phone FROM users WHERE id = ?', [$p['user_id']]) ?? ['email' => '', 'phone' => ''];
    return $p['method'] === 'sms' ? 'SMS na ' . (strlen($t = preg_replace('/\s+/', '', (string) $user['phone'])) > 3 ? str_repeat('•', max(0, strlen($t) - 3)) . substr($t, -3) : $t)
        : 'e-mail ' . rollam_mask_email((string) $user['email']);
}

/**
 * Potvrzení hlasu kódem. Kontroluje vlastníka, platnost (10 min), počet pokusů (5) a stav hlasování.
 * Vrací ['ok' => true, 'record' => id] nebo ['ok' => false, 'error' => text, 'final' => bool (rozpracovaný hlas už neplatí)].
 */
function rollam_confirm(int $pid, int $userId, string $code): array
{
    $p = rollam_pending($pid);
    if (!$p || (int) $p['user_id'] !== $userId || $p['status'] !== 'pending' || !in_array($p['method'], ['email', 'sms'], true)) {
        return ['ok' => false, 'error' => 'Tento hlas už nečeká na potvrzení.', 'final' => true];
    }
    if ($p['expires_at'] < date('Y-m-d H:i:s')) {
        q("UPDATE rollam_pending SET status = 'expired' WHERE id = ?", [$pid]);
        return ['ok' => false, 'error' => 'Platnost kódu vypršela (10 minut). Hlasujte prosím znovu.', 'final' => true];
    }
    $code = preg_replace('/\D+/', '', $code);
    if ($p['code_hash'] === '' || !hash_equals((string) $p['code_hash'], hash_hmac('sha256', (string) $code, 'rollam|' . $pid))) {
        q('UPDATE rollam_pending SET attempts = attempts + 1 WHERE id = ?', [$pid]);
        $left = ROLLAM_CODE_ATTEMPTS - (int) $p['attempts'] - 1;
        if ($left <= 0) {
            q("UPDATE rollam_pending SET status = 'failed' WHERE id = ?", [$pid]);
            return ['ok' => false, 'error' => 'Kód byl zadán špatně ' . ROLLAM_CODE_ATTEMPTS . '×. Hlas se neuložil – hlasujte prosím znovu.', 'final' => true];
        }
        return ['ok' => false, 'error' => 'Kód nesouhlasí. Zbývá pokusů: ' . $left . '.', 'final' => false];
    }
    return rollam_finish($p, $p['method'], rollam_code_target($p));
}

/** Dokončí rozpracovaný hlas (po ověření): kontrola hlasování a vlastnictví, uložení, záznam. */
function rollam_finish(array $p, string $method, string $verified): array
{
    $poll = one('SELECT * FROM polls WHERE id = ?', [$p['poll_id']]);
    $user = one('SELECT * FROM users WHERE id = ?', [$p['user_id']]);
    $owns = $user && $user['kind'] === 'owner' && $user['active'] && in_array((int) $p['unit_id'], user_unit_ids((int) $user['id']), true);
    if (!$poll || !$poll['online'] || poll_state($poll) !== 'open' || !$owns) {
        q("UPDATE rollam_pending SET status = 'failed' WHERE id = ?", [$p['id']]);
        return ['ok' => false, 'error' => 'Hlasování přes web už neprobíhá, nebo za jednotku nemůžete hlasovat.', 'final' => true];
    }
    $r = rollam_commit((int) $p['poll_id'], (int) $p['unit_id'], (array) json_decode((string) $p['choices'], true), $method,
        ['user_id' => (int) $user['id'], 'voter_name' => (string) $user['name'], 'verified' => $verified, 'ip' => client_ip(), 'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? $p['ua'])]);
    q('UPDATE rollam_pending SET status = ?, code_hash = ? WHERE id = ?', [is_int($r) ? 'done' : 'failed', '', $p['id']]);
    if (!is_int($r)) {
        return ['ok' => false, 'error' => $r, 'final' => true];
    }
    $unit = one('SELECT * FROM units WHERE id = ?', [$p['unit_id']]);
    audit('Odevzdání hlasu', $poll['title'] . ' · ' . unit_name($unit) . ' · ' . (ROLLAM_SOURCES[$method] ?? $method));
    return ['ok' => true, 'record' => $r, 'poll' => (int) $poll['id'], 'unit' => $unit];
}

/** Háček z odeslání hlasu (app/routes_extra.php, po kontrolách): uloží nebo pošle k ověření, pak přesměruje. */
function rollam_web_vote(array $poll, array $user, int $unitId, array $choices): never
{
    $r = rollam_begin($poll, $user, $unitId, $choices);
    if ($r['status'] === 'stored') {
        $unit = one('SELECT * FROM units WHERE id = ?', [$unitId]);
        audit('Odevzdání hlasu', $poll['title'] . ' · ' . unit_name($unit));
        flash('Děkujeme, hlas za jednotku ' . unit_name($unit) . ' je zaznamenán.');
        redirect('/hlasovani/' . $poll['id']);
    }
    if ($r['status'] === 'code') {
        redirect('/hlasovani/potvrdit/' . $r['pending']);
    }
    if ($r['status'] === 'bankid') {
        redirect('/hlasovani/bankid/' . $r['pending']);
    }
    flash($r['error'], 'error');
    redirect('/hlasovani/' . $poll['id']);
}

// ---------- Bank iD ----------

/** Připraví přihlášení přes Bank iD pro rozpracovaný hlas; vrací adresu, kam přesměrovat, nebo text chyby. */
function rollam_bankid_start(int $pid, int $userId): string|array
{
    $p = rollam_pending($pid);
    if (!$p || (int) $p['user_id'] !== $userId || $p['status'] !== 'pending' || $p['method'] !== 'bankid' || $p['expires_at'] < date('Y-m-d H:i:s')) {
        return ['Tento hlas už nečeká na ověření. Hlasujte prosím znovu.'];
    }
    if (!bankid_available()) {
        return ['Ověření přes Bank iD teď není k dispozici.'];
    }
    if (!bankid_credit_ok()) {
        bankid_credit_alert();
        return [ROLLAM_BANKID_NO_CREDIT];
    }
    $cfg = bankid_config();
    $disc = bankid_discovery($cfg);
    if (is_string($disc)) {
        return [$disc];
    }
    [$verifier, $challenge] = bankid_pkce();
    $state = bankid_b64url(random_bytes(24));
    $nonce = bankid_b64url(random_bytes(24));
    $_SESSION['bankid'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'pending' => $pid, 'at' => time()];
    return bankid_auth_url($cfg, $disc, $state, $nonce, $challenge);
}

/** Ověří, že osoba z Bank iD je vlastník: jméno sedí s účtem nebo evidencí jednotky a účet není svázán s jinou osobou. */
function rollam_identity_check(array $user, array $unit, array $person): ?string
{
    rollam_ready();
    $subHash = hash('sha256', 'bankid|' . $person['sub']);
    $bound = one('SELECT * FROM rollam_identities WHERE user_id = ?', [$user['id']]);
    if ($bound && !hash_equals((string) $bound['sub_hash'], $subHash)) {
        return 'Bank iD patří jiné osobě, než která se k tomuto účtu ověřila dříve. Obraťte se prosím na výbor.';
    }
    if (!$bound && !bankid_name_matches($person, [$user['name'], $unit['owner_note']])) {
        return 'Jméno z Bank iD (' . $person['given_name'] . ' ' . $person['family_name'] . ') nesouhlasí s vlastníkem jednotky ' . unit_name($unit) . ' v evidenci. Obraťte se prosím na výbor.';
    }
    q('INSERT INTO rollam_identities (user_id, sub_hash, name, birthdate, at) VALUES (?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET name = excluded.name, birthdate = excluded.birthdate, at = excluded.at',
        [$user['id'], $subHash, $person['given_name'] . ' ' . $person['family_name'], $person['birthdate'], date('Y-m-d H:i:s')]);
    return null;
}

/** Návrat z Bank iD: ověří stav, tokeny a osobu, pak hlas uloží. Vrací stejný tvar jako rollam_confirm() (+ 'poll'). */
function rollam_bankid_finish(string $state, string $code, string $error, int $userId, ?int $now = null): array
{
    $s = $_SESSION['bankid'] ?? null;
    unset($_SESSION['bankid']);
    if (!is_array($s) || $state === '' || !hash_equals((string) $s['state'], $state) || time() - (int) $s['at'] > ROLLAM_CODE_TTL) {
        return ['ok' => false, 'error' => 'Přihlášení přes Bank iD vypršelo nebo nepatří k tomuto prohlížeči. Hlasujte prosím znovu.', 'final' => true];
    }
    $p = rollam_pending((int) $s['pending']);
    if (!$p || (int) $p['user_id'] !== $userId || $p['status'] !== 'pending' || $p['method'] !== 'bankid') {
        return ['ok' => false, 'error' => 'Tento hlas už nečeká na ověření.', 'final' => true];
    }
    $fail = function (string $msg) use ($p): array {
        q("UPDATE rollam_pending SET status = 'failed' WHERE id = ?", [$p['id']]);
        return ['ok' => false, 'error' => $msg, 'final' => true, 'poll' => (int) $p['poll_id']];
    };
    if ($error !== '' || $code === '') {
        return $fail('Ověření v Bank iD bylo zrušeno nebo se nepodařilo. Hlas se neuložil.');
    }
    $cfg = bankid_config();
    if (!$cfg) {
        return $fail('Bank iD není nastaveno.');
    }
    $person = bankid_complete($cfg, $code, (string) $s['verifier'], (string) $s['nonce'], $now);
    if (is_string($person)) {
        return $fail($person);
    }
    $user = one('SELECT * FROM users WHERE id = ?', [$userId]);
    $unit = one('SELECT * FROM units WHERE id = ?', [$p['unit_id']]);
    if (!$user || !$unit || ($err = rollam_identity_check($user, $unit, $person))) {
        return $fail($err ?? 'Účet nebo jednotka už neexistuje.');
    }
    $r = rollam_finish($p, 'bankid', 'Bank iD' . ($cfg['sandbox'] ? ' (sandbox)' : '') . ': ' . $person['given_name'] . ' ' . $person['family_name']
        . ($person['birthdate'] !== '' ? ', nar. ' . $person['birthdate'] : ''));
    if ($r['ok']) {
        // Platí se jen ověřený a uložený hlas (z kreditu služeb domu).
        bankid_charge((int) $p['poll_id'], (int) $p['unit_id'], unit_name($r['unit']) . ' · hlasování č. ' . (int) $p['poll_id']);
    }
    return $r + ['poll' => (int) $p['poll_id']];
}

// ---------- Listinné hlasy ----------

/**
 * Zápis vráceného hlasovacího lístku. $in: unit_id, choice[item_id], signed_on, received_on, note, replace.
 * $scan: [filename, original_name] z store_upload() nebo null. Vrací id záznamu, nebo seznam chyb.
 */
function rollam_paper_vote(array $poll, array $in, array $admin, ?array $scan = null): int|array
{
    if ($errors = rollam_paper_errors($poll, $in)) {
        return $errors;
    }
    $unit = one('SELECT * FROM units WHERE id = ?', [(int) $in['unit_id']]);
    $choices = array_filter(array_map('strval', (array) $in['choice']), fn($c) => isset(CHOICES[$c]));
    $r = rollam_commit((int) $poll['id'], (int) $unit['id'], $choices, 'listina', ['voter_name' => (string) $unit['owner_note'], 'verified' => 'vlastnoruční podpis na lístku',
        'entered_by' => (int) $admin['id'], 'entered_name' => (string) $admin['name'], 'signed_on' => (string) $in['signed_on'], 'received_on' => (string) $in['received_on'],
        'scan_file' => (string) ($scan[0] ?? ''), 'scan_name' => (string) ($scan[1] ?? ''), 'note' => trim((string) ($in['note'] ?? ''))], true);
    if (!is_int($r)) {
        return [$r];
    }
    audit('Zápis listinného hlasu', $poll['title'] . ' · ' . unit_name($unit) . ($scan ? ' · se skenem' : ''));
    return $r;
}
/** Kontrola zápisu listinného hlasu (bez ukládání); vrací seznam chyb. */
function rollam_paper_errors(array $poll, array $in): array
{
    $errors = [];
    $unit = one('SELECT * FROM units WHERE id = ?', [(int) ($in['unit_id'] ?? 0)]);
    if (!$unit) {
        $errors[] = 'Vyberte jednotku.';
    }
    $choices = array_filter(array_map('strval', (array) ($in['choice'] ?? [])), fn($c) => isset(CHOICES[$c]));
    if (!$choices) {
        $errors[] = 'Zapište alespoň jednu volbu z lístku.';
    }
    $signed = (string) ($in['signed_on'] ?? '');
    $received = (string) ($in['received_on'] ?? '');
    if (!valid_date($signed)) {
        $errors[] = 'Zadejte datum, které vlastník uvedl u podpisu (§ 1212 – bez data není hlas platný).';
    } elseif ($signed > date('Y-m-d')) {
        $errors[] = 'Datum podpisu nemůže být v budoucnosti.';
    }
    if (!valid_date($received) || $received > date('Y-m-d')) {
        $errors[] = 'Zadejte datum, kdy lístek do SVJ došel (ne v budoucnosti).';
    } elseif ($received > $poll['closes_at']) {
        $errors[] = 'Lístek došel po skončení lhůty (' . cz_date($poll['closes_at']) . ') – k hlasu se nepřihlíží.';
    } elseif ($received < $poll['opens_at'] || (valid_date($signed) && $signed > $received)) {
        $errors[] = 'Data nesedí: lístek nemohl dojít před zahájením hlasování ani před podpisem.';
    }
    if ($unit && rollam_unit_votes((int) $poll['id'], (int) $unit['id']) && empty($in['replace'])) {
        $errors[] = 'Za jednotku ' . unit_name($unit) . ' už hlas je. Pokud lístek nahrazuje dřívější hlas, zaškrtněte „Nahradit dosavadní hlas“.';
    }
    return $errors;
}

// ---------- Protokol a lístky ----------

/** Podklady pro protokol o hlasování. */
function rollam_protocol(int $pollId): ?array
{
    $poll = one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    if (!$poll) {
        return null;
    }
    $res = poll_results($pollId);
    $need = max(50, (int) $poll['threshold']);
    foreach ($res['items'] as &$it) {
        $it['adopted'] = $it['percent']['yes'] > $need;
    }
    unset($it);
    $units = array_column($res['units'], null, 'id');
    $items = array_column($res['items'], null, 'id');
    $records = [];
    foreach (rollam_records($pollId) as $r) {
        $r['unit'] = $units[(int) $r['unit_id']] ?? null;
        $r['choice_list'] = (array) json_decode((string) $r['choices'], true);
        $records[] = $r;
    }
    return ['poll' => $poll, 'state' => poll_state($poll), 'method' => rollam_method($pollId), 'results' => $res, 'need' => $need, 'items' => $items,
        'records' => $records, 'check' => rollam_chain_check($pollId), 'fingerprint' => hash('sha256', (string) json_encode(all('SELECT v.item_id, v.unit_id, v.choice, v.voted_at FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ? ORDER BY v.item_id, v.unit_id', [$pollId]))),
        'warnings' => rollam_warnings($poll), 'generated' => date('Y-m-d H:i:s')];
}
/** Jednotky pro hlasovací lístky (všechny, nebo jedna); každá s vlastníkem podle evidence a jmény účtů vlastníků. */
function rollam_ballot_units(?int $unitId = null): array
{
    $rows = $unitId ? all('SELECT * FROM units WHERE id = ?', [$unitId]) : all_units();
    foreach ($rows as &$un) {
        $names = array_column(all("SELECT u.name FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.kind = 'owner' AND u.active = 1 ORDER BY u.name", [$un['id']]), 'name');
        $un['owner_label'] = trim((string) $un['owner_note']) !== '' ? (string) $un['owner_note'] : implode(', ', $names);
    }
    return $rows;
}

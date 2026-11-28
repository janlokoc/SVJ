<?php
declare(strict_types=1);

/*
 * Závady v bytě (11/2026): nájemník napíše vlastníkovi bytu (a obráceně) – nahlásí závadu uvnitř bytu (spotřebič,
 * vybavení, okna…) nebo pošle zprávu. Vlákno s fotkami, u závady stav řešení (nová → řeší se → vyřešeno).
 *
 * Kdo: účty přiřazené ke stejné jednotce – nájemníci (users.kind = 'tenant') na jedné straně, vlastníci ('owner') na druhé.
 * Spoluvlastníci i více nájemníků téže jednotky vidí totéž. Výbor ani správa domu obsah NEVIDÍ (soukromá věc mezi
 * vlastníkem a nájemníkem). Nájemník, kterého výbor od jednotky odpojí, přestane vlákna vidět; vlastník je má dál.
 *
 * Upozornění: druhé straně e-mail a upozornění do telefonu jen jako oznámení BEZ obsahu – komunikace jen na webu.
 * Nájemník bez vlastníka nevznikne (tenant_units_error v app/lib.php), přesto je ošetřeno, že druhá strana chybí.
 * Zapíná se v Nastavení → Menu (položka Závady v bytě), výchozí zapnuto, ve všech edicích.
 */

const UNITCASE_KINDS = ['zavada' => 'Závada v bytě', 'zprava' => 'Zpráva'];
const UNITCASE_STATUS = ['new' => 'Nová', 'in_progress' => 'Řeší se', 'done' => 'Vyřešeno'];
const UNITCASE_MAX = 5000;   // nejvýše znaků zprávy
const UNITCASE_FILES = 4;    // nejvýše příloh k jedné zprávě

$GLOBALS['EXT_RESIDENT_NAV']['/byt'] = ['Závady v bytě', fn() => unitcase_enabled() && (bool) unitcase_my_units()];
$GLOBALS['EXT_MENU']['zavady-bytu'] = ['Závady v bytě (nájemník ↔ vlastník)', fn() => unitcase_enabled(), fn(bool $on) => unitcase_setting('unitcase_enabled', $on ? '1' : '0')];

function unitcase_enabled(): bool
{
    return setting_on('unitcase_enabled', true);
}
function unitcase_setting(string $key, string $value): void
{
    q('DELETE FROM settings WHERE `key` = ?', [$key]);
    q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
    setting('__reset');
}

/** Tabulky modulu (jednou za požadavek a databázi). */
function unitcase_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $have = $mysql ? array_map('strval', $pdo->query("SHOW TABLES LIKE 'unit_case%'")->fetchAll(PDO::FETCH_COLUMN))
        : array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'unit_case%'")->fetchAll(PDO::FETCH_COLUMN));
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $id = 'id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        // side: kdo vlákno založil – 'tenant' | 'owner'; status jen u závady.
        'unit_cases' => "CREATE TABLE unit_cases ($id, unit_id $int, kind {$s(10)}, subject {$s(150)}, status {$s(12)}, side {$s(10)}, created_by $int, created_at {$s(19)}, updated_at {$s(19)})$end",
        'unit_case_messages' => "CREATE TABLE unit_case_messages ($id, case_id $int, user_id $int, side {$s(10)}, body $text, created_at {$s(19)})$end",
        'unit_case_files' => "CREATE TABLE unit_case_files ($id, case_id $int, message_id $int, filename {$s(80)}, original_name {$s(255)}, size $int)$end",
        // last_id: poslední přečtená zpráva (podle id, ne času – dvě zprávy ve stejné sekundě by se jinak ztratily).
        'unit_case_reads' => "CREATE TABLE unit_case_reads (case_id $int, user_id $int, last_id $int, PRIMARY KEY (case_id, user_id))$end",
    ];
    foreach ($tables as $name => $ddl) {
        if (!in_array($name, $have, true)) {
            $pdo->exec($ddl);
            if ($name === 'unit_cases') {
                $pdo->exec('CREATE INDEX unit_cases_unit ON unit_cases (unit_id, updated_at)');
            } elseif ($name === 'unit_case_messages') {
                $pdo->exec('CREATE INDEX unit_case_messages_case ON unit_case_messages (case_id)');
            }
        }
    }
}

/** Aktivní účty dané strany u jednotky: [id => řádek users]. */
function unitcase_people(int $unitId, string $side): array
{
    $rows = all("SELECT u.* FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 AND u.kind = ? ORDER BY u.name",
        [$unitId, $side === 'tenant' ? 'tenant' : 'owner']);
    return array_column($rows, null, 'id');
}
/**
 * Jednotky přihlášeného, u kterých funkce dává smysl: [unit_id => ['name' => označení, 'side' => 'tenant'|'owner',
 * 'other' => počet účtů druhé strany]]. Nájemník vidí jednotku vždy (i když vlastník účet nemá – dozví se proč),
 * vlastník jen tu, kde je nájemník nebo už nějaké vlákno.
 */
function unitcase_my_units(?array $u = null): array
{
    $u ??= user();
    if (!$u) {
        return [];
    }
    unitcase_ready();
    $side = ($u['kind'] ?? 'owner') === 'tenant' ? 'tenant' : 'owner';
    $names = unit_options();
    $out = [];
    foreach (array_map('intval', array_column(all('SELECT unit_id FROM user_units WHERE user_id = ?', [$u['id']]), 'unit_id')) as $unitId) {
        $other = count(unitcase_people($unitId, $side === 'tenant' ? 'owner' : 'tenant'));
        if ($side === 'tenant' || $other > 0 || val('SELECT id FROM unit_cases WHERE unit_id = ? LIMIT 1', [$unitId])) {
            $out[$unitId] = ['name' => $names[$unitId] ?? ('jednotka ' . $unitId), 'side' => $side, 'other' => $other];
        }
    }
    return $out;
}
/** Smí uživatel vlákno vidět? Jen účet přiřazený k jednotce vlákna (nájemník i vlastník); výbor ne. */
function unitcase_can_see(array $case, ?array $u = null): bool
{
    $u ??= user();
    return $u && isset(unitcase_my_units($u)[(int) $case['unit_id']]);
}
function unitcase_side(?array $u = null): string
{
    $u ??= user();
    return ($u['kind'] ?? 'owner') === 'tenant' ? 'tenant' : 'owner';
}
function unitcase_other(string $side): string
{
    return $side === 'tenant' ? 'owner' : 'tenant';
}

/** Vlákna přihlášeného (nejnovější první) s počtem nepřečtených zpráv od druhé strany. */
function unitcase_list(?array $u = null): array
{
    $u ??= user();
    $units = unitcase_my_units($u);
    if (!$units) {
        return [];
    }
    $rows = all('SELECT c.*, (SELECT COUNT(*) FROM unit_case_messages m WHERE m.case_id = c.id AND m.user_id <> ? AND m.id > COALESCE((SELECT r.last_id FROM unit_case_reads r WHERE r.case_id = c.id AND r.user_id = ?), 0)) AS unread
        FROM unit_cases c WHERE c.unit_id IN (' . implode(',', array_keys($units)) . ') ORDER BY c.updated_at DESC, c.id DESC LIMIT 200', [$u['id'], $u['id']]);
    return $rows;
}
/** Počet nepřečtených zpráv (pro odznak v menu). */
function unitcase_unread(?array $u = null): int
{
    return (int) array_sum(array_column(unitcase_list($u), 'unread'));
}
function unitcase_mark_read(int $caseId, int $userId): void
{
    q('DELETE FROM unit_case_reads WHERE case_id = ? AND user_id = ?', [$caseId, $userId]);
    q('INSERT INTO unit_case_reads (case_id, user_id, last_id) VALUES (?,?,?)', [$caseId, $userId, (int) val('SELECT MAX(id) FROM unit_case_messages WHERE case_id = ?', [$caseId])]);
}

/**
 * Kontrola nahraných příloh (nejvýše UNITCASE_FILES): uloží je a vrátí [[filename, original, size]], nebo text chyby
 * (pak se nic neuloží).
 */
function unitcase_store_files(array $files): array|string
{
    $uploads = [];
    foreach ((array) ($files['name'] ?? []) as $i => $name) {
        if ($name === '' || ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploads[] = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
    }
    if (count($uploads) > UNITCASE_FILES) {
        return 'Přiložit lze nejvýše ' . UNITCASE_FILES . ' soubory.';
    }
    $stored = [];
    foreach ($uploads as $f) {
        $r = store_upload($f);
        if (is_string($r)) {
            foreach ($stored as $done) {
                @unlink(UPLOAD_DIR . '/' . $done[0]);
            }
            return 'Přílohu „' . basename((string) $f['name']) . '“ se nepodařilo nahrát: ' . $r;
        }
        $stored[] = $r;
    }
    return $stored;
}

/** Založí vlákno; vrací id, nebo text chyby. $files = výsledek unitcase_store_files(). */
function unitcase_create(array $u, int $unitId, string $kind, string $subject, string $body, array $files = []): int|string
{
    $units = unitcase_my_units($u);
    if (!isset($units[$unitId])) {
        return 'K této jednotce nemáte přístup.';
    }
    $side = $units[$unitId]['side'];
    if (!unitcase_people($unitId, unitcase_other($side))) {
        return $side === 'tenant' ? 'Vlastník Vašeho bytu zatím nemá na webu účet, zprávu by nikdo nedostal.' : 'U této jednotky zatím není přihlášený žádný nájemník.';
    }
    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '' || mb_strlen($subject) > 150 || $body === '' || mb_strlen($body) > UNITCASE_MAX) {
        return 'Vyplňte prosím stručný název (nejvýše 150 znaků) i popis.';
    }
    $kind = isset(UNITCASE_KINDS[$kind]) ? $kind : 'zavada';
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO unit_cases (unit_id, kind, subject, status, side, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)',
        [$unitId, $kind, $subject, $kind === 'zavada' ? 'new' : '', $side, $u['id'], $now, $now]);
    $caseId = (int) db()->lastInsertId();
    unitcase_add_message($caseId, $u, $body, $files, false);
    $case = one('SELECT * FROM unit_cases WHERE id = ?', [$caseId]);
    unitcase_notify($case, $u, $kind === 'zavada' ? 'nová závada' : 'nová zpráva');
    audit('unitcase_new', UNITCASE_KINDS[$kind] . ' · ' . ($units[$unitId]['name']));   // bez textu – obsah je soukromý
    return $caseId;
}
/** Přidá zprávu do vlákna (bez kontroly práv – volá se po unitcase_can_see). */
function unitcase_add_message(int $caseId, array $u, string $body, array $files = [], bool $notify = true): int
{
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO unit_case_messages (case_id, user_id, side, body, created_at) VALUES (?,?,?,?,?)', [$caseId, $u['id'], unitcase_side($u), trim($body), $now]);
    $msgId = (int) db()->lastInsertId();
    foreach ($files as [$file, $orig, $size]) {
        q('INSERT INTO unit_case_files (case_id, message_id, filename, original_name, size) VALUES (?,?,?,?,?)', [$caseId, $msgId, $file, mb_substr((string) $orig, 0, 250), (int) $size]);
    }
    q('UPDATE unit_cases SET updated_at = ? WHERE id = ?', [$now, $caseId]);
    unitcase_mark_read($caseId, (int) $u['id']);
    if ($notify) {
        unitcase_notify(one('SELECT * FROM unit_cases WHERE id = ?', [$caseId]), $u, 'odpověď');
    }
    return $msgId;
}
/** Změna stavu závady (smí vlastník i nájemník – např. nájemník potvrdí, že je opraveno). */
function unitcase_set_status(array $case, array $u, string $status): bool
{
    if ($case['kind'] !== 'zavada' || !isset(UNITCASE_STATUS[$status]) || $case['status'] === $status) {
        return false;
    }
    q('UPDATE unit_cases SET status = ?, updated_at = ? WHERE id = ?', [$status, date('Y-m-d H:i:s'), $case['id']]);
    unitcase_add_message((int) $case['id'], $u, 'Stav změněn na: ' . UNITCASE_STATUS[$status] . '.', [], false);
    unitcase_notify(array_merge($case, ['status' => $status]), $u, 'změna stavu závady');
    return true;
}
/**
 * Upozornění druhé straně (všem jejím účtům u jednotky): e-mail a upozornění do telefonu BEZ obsahu zprávy – komunikace
 * probíhá jen na webu (rozhodnutí provozovatele 11/2026). Vrací počet odeslaných e-mailů.
 */
function unitcase_notify(array $case, array $from, string $what, string $text = ''): int
{
    $to = unitcase_people((int) $case['unit_id'], unitcase_other(unitcase_side($from)));
    $unit = unit_options()[(int) $case['unit_id']] ?? '';
    $who = unitcase_side($from) === 'tenant' ? 'Nájemník' : 'Vlastník';
    $link = absolute_url('/byt/' . $case['id']);
    $sent = 0;
    foreach ($to as $p) {
        $mail = "Dobrý den,\n\n$who bytu $unit Vám píše na webu " . site('name') . " ($what).\n\nPřečíst a odpovědět můžete po přihlášení: $link\n\n"
            . "Z důvodu soukromí e-mail obsah zprávy neobsahuje a odpověď na něj se nikam nedostane – odpovídejte prosím na webu.\n";
        $sent += send_mail($p['email'], $who . ' bytu ' . $unit . ' Vám píše – ' . site('name'), $mail) ? 1 : 0;
    }
    if ($to && function_exists('push_notify')) {
        push_notify(array_keys($to), $who . ' bytu ' . $unit . ' Vám píše', 'Zprávu najdete na webu v části Závady v bytě.', '/byt/' . $case['id']);
    }
    return $sent;
}
/** Přílohy vláken: [message_id => [řádky]]. */
function unitcase_files(int $caseId): array
{
    $out = [];
    foreach (all('SELECT * FROM unit_case_files WHERE case_id = ? ORDER BY id', [$caseId]) as $f) {
        $out[(int) $f['message_id']][] = $f;
    }
    return $out;
}

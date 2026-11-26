<?php
declare(strict_types=1);

/*
 * Modul F – provoz domu: vyúčtování služeb (zákon č. 67/2013 Sb.), plán oprav a fond oprav, smlouvy a pojištění.
 * Společné části: tabulky (zakládají se samy při prvním použití, i v databázi jiného domu – partnerský portál),
 * menu administrace, denní úlohy (připomínky), termíny do „Co se blíží“ v Přehledu správy a kalendář pro správce.
 * Vyúčtování: app/ext/modf_settle_lib.php, fond oprav: app/ext/modf_fund_lib.php, smlouvy: app/ext/modf_contract_lib.php.
 */

$GLOBALS['EXT_ADMIN_NAV']['/admin/vyuctovani'] = ['Vyúčtování služeb', 'Provoz domu'];
$GLOBALS['EXT_ADMIN_NAV']['/admin/plan-oprav'] = ['Plán oprav a fond', 'Provoz domu'];
$GLOBALS['EXT_ADMIN_NAV']['/admin/smlouvy'] = ['Smlouvy a pojištění', 'Provoz domu'];

/** Založí tabulky modulu (jednou za požadavek a databázi). */
function modf_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $have = $mysql ? array_map('strval', $pdo->query("SHOW TABLES LIKE 'modf\\_%'")->fetchAll(PDO::FETCH_COLUMN))
        : array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'modf_%'")->fetchAll(PDO::FETCH_COLUMN));
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $money = $mysql ? 'DECIMAL(14,2) NOT NULL DEFAULT 0' : 'REAL NOT NULL DEFAULT 0';
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $id = 'id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        // Vyúčtování za zúčtovací období; delivery_date = den doručení (zpřístupnění vlastníkům), published_at = kdy se zveřejnilo.
        'modf_settlements' => "CREATE TABLE modf_settlements ($id, title {$s(191)}, period_from {$s(10)}, period_to {$s(10)}, delivery_date {$s(10)}, published_at {$s(19)},"
            . " note $text, created_at {$s(19)}, created_by $int)$end",
        // Soubor vyúčtování jedné jednotky (unit_id = 0: jednotku se z názvu souboru nepodařilo poznat).
        'modf_settlement_items' => "CREATE TABLE modf_settlement_items ($id, settlement_id $int, unit_id $int, filename {$s(80)}, original_name {$s(255)}, size $int,"
            . " uploaded_at {$s(19)}, delivered_on {$s(10)}, confirmed_at {$s(19)}, confirmed_by $int, confirmed_name {$s(191)})$end",
        'modf_objections' => "CREATE TABLE modf_objections ($id, item_id $int, user_id $int, user_name {$s(191)}, text $text, filename {$s(80)}, original_name {$s(255)}, size $int,"
            . " created_at {$s(19)}, answer $text, answered_at {$s(19)}, answered_by {$s(191)})$end",
        'modf_repairs' => "CREATE TABLE modf_repairs ($id, title {$s(191)}, year $int, cost $int, priority $int, status {$s(20)}, note $text, created_at {$s(19)})$end",
        'modf_contracts' => "CREATE TABLE modf_contracts ($id, kind {$s(20)}, supplier_id $int, party {$s(191)}, subject {$s(255)}, number {$s(80)}, start_date {$s(10)}, end_date {$s(10)},"
            . " notice_value $int, notice_unit {$s(1)}, auto_renew $int, renew_months $int, price $money, price_period {$s(10)}, remind_days $int, note $text,"
            . " filename {$s(80)}, original_name {$s(255)}, size $int, created_at {$s(19)})$end",
        // Odeslané připomínky (aby každá odešla jen jednou).
        'modf_reminders' => "CREATE TABLE modf_reminders (rkey {$s(120)}, sent_at {$s(19)}, PRIMARY KEY (rkey))$end",
    ];
    foreach ($tables as $name => $ddl) {
        if (!in_array($name, $have, true)) {
            $pdo->exec($ddl);
        }
    }
    if (!in_array('modf_settlement_items', $have, true)) {
        $pdo->exec('CREATE INDEX modf_items_settlement ON modf_settlement_items (settlement_id, unit_id)');
        $pdo->exec('CREATE INDEX modf_objections_item ON modf_objections (item_id)');
    }
}

/** Datum posunuté o $months měsíců; když takový den v cílovém měsíci není, je to jeho poslední den (§ 605 občanského zákoníku). */
function modf_add_months(string $date, int $months): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    $total = $y * 12 + ($m - 1) + $months;
    $ny = intdiv($total, 12);
    $nm = $total % 12 + 1;
    $last = (int) date('t', mktime(0, 0, 0, $nm, 1, $ny));
    return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $last));
}
function modf_add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' day'));
}
/** Částka v korunách („12 500 Kč“). */
function modf_money(float|int $v, int $dec = 0): string
{
    return number_format((float) $v, $dec, ',', "\u{a0}") . "\u{a0}Kč";
}
/** Číslo z formuláře („12 500,50“ → 12500.5); nečitelné = null. */
function modf_number(string $s): ?float
{
    $s = str_replace([' ', "\u{a0}", 'Kč', 'kč', ','], ['', '', '', '', '.'], trim($s));
    return $s !== '' && is_numeric($s) ? (float) $s : null;
}
/** Aktivní správci webu (příjemci připomínek). */
function modf_admins(): array
{
    return all("SELECT id, name, email FROM users WHERE role = 'admin' AND active = 1 ORDER BY id");
}
/** E-mail (a upozornění do telefonu) všem správcům. */
function modf_mail_admins(string $subject, string $body, string $path): int
{
    $admins = modf_admins();
    $n = 0;
    foreach ($admins as $a) {
        if (filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            $n += send_mail($a['email'], $subject . ' – ' . site('name'), "Dobrý den,\n\n" . $body . "\n\n" . absolute_url($path)) ? 1 : 0;
        }
    }
    if (function_exists('push_notify')) {
        push_notify(array_column($admins, 'id'), site('name'), $subject, $path);
    }
    return $n;
}
/** Zapíše připomínku jako odeslanou; vrací false, pokud už odešla. */
function modf_remind_once(string $key): bool
{
    modf_ready();
    if (val('SELECT 1 FROM modf_reminders WHERE rkey = ?', [$key])) {
        return false;
    }
    q('INSERT INTO modf_reminders (rkey, sent_at) VALUES (?,?)', [$key, date('Y-m-d H:i:s')]);
    return true;
}

/** Denně (z daily_tasks): prodloužení smluv, připomínky výpovědních lhůt a konců smluv, lhůty vyúčtování a námitek. */
function modf_daily(?string $today = null): void
{
    $today ??= date('Y-m-d');
    try {
        modf_ready();
        if (!function_exists('plan_has') || plan_has('smlouvy')) {
            modf_contracts_roll($today);
            modf_contract_reminders($today);
        }
        if (!function_exists('plan_has') || plan_has('vyuctovani')) {
            modf_settlement_reminders($today);
        }
    } catch (Throwable $e) {
        error_log('SVJ web, provoz domu: ' . $e->getMessage());
    }
}

/** Termíny modulu pro „Co se blíží“ v Přehledu správy (nad databází domu $pdo, i z partnerského portálu). */
function modf_upcoming(PDO $pdo, string $today, int $days = 60): array
{
    modf_ready($pdo);
    $limit = modf_add_days($today, $days);
    $out = [];
    try {
        $sup = $pdo->query('SELECT id, name FROM suppliers')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable) {
        $sup = [];
    }
    foreach ($pdo->query('SELECT * FROM modf_contracts')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c['supplier'] = (string) ($sup[$c['supplier_id']] ?? '');
        foreach (modf_contract_events($c) as $ev) {
            if ($ev['date'] >= $today && $ev['date'] <= $limit) {
                $out[] = ['date' => $ev['date'], 'title' => $ev['label'] . ': ' . modf_contract_title($c), 'late' => false];
            }
        }
    }
    // Vyúčtování, které ještě není doručené: zákonná lhůta 4 měsíce od konce období.
    foreach ($pdo->query("SELECT * FROM modf_settlements WHERE published_at = ''")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (valid_date($s['period_to'])) {
            $dl = modf_delivery_deadline($s['period_to']);
            if ($dl <= $limit) {
                $out[] = ['date' => $dl, 'title' => 'Doručit vyúčtování: ' . $s['title'], 'late' => $dl < $today];
            }
        }
    }
    // Námitky k vyúčtování: vyřídit do 30 dnů od doručení.
    foreach ($pdo->query("SELECT o.created_at, s.title FROM modf_objections o JOIN modf_settlement_items i ON i.id = o.item_id JOIN modf_settlements s ON s.id = i.settlement_id WHERE o.answered_at = ''")->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $dl = modf_answer_deadline(substr($o['created_at'], 0, 10));
        $out[] = ['date' => $dl, 'title' => 'Vyřídit námitku k vyúčtování: ' . $o['title'], 'late' => $dl < $today];
    }
    return $out;
}

/** Odkazy pro obyvatele na stránce Můj účet. */
function modf_account_links(array $u): string
{
    $links = [];
    if (plan_has('vyuctovani') && ($u['role'] === 'admin' || modf_items_for_user($u))) {
        $links[] = '<a href="' . url('/vyuctovani') . '">Moje vyúčtování</a>';
    }
    if (plan_has('plan-oprav') && setting('modf_fund_published') === '1') {
        $links[] = '<a href="' . url('/plan-oprav') . '">Plán oprav a fond oprav</a>';
    }
    return $links ? '<p class="modf-links">' . implode(' · ', $links) . '</p>' : '';
}

// ---------- Kalendář termínů pro správce (iCalendar, odběr v Google, Apple i Outlook) ----------

/** Tajný odkaz na kalendář správce; $new = vytvořit nový (starý přestane fungovat). */
function modf_ics_token(bool $new = false): string
{
    $t = setting('modf_ics_token');
    if ($new || !preg_match('/^[a-f0-9]{32}$/', $t)) {
        do {
            $t = bin2hex(random_bytes(16));
        } while (ctype_digit($t));   // směrovač by token ze samých číslic převedl na číslo
        q('DELETE FROM settings WHERE `key` = ?', ['modf_ics_token']);
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['modf_ics_token', $t]);
        setting('__reset');
    }
    return $t;
}
/** Termíny smluv, pojištění a vyúčtování jako kalendář (celodenní události s připomenutím). */
function modf_ics(?string $today = null): string
{
    modf_ready();
    $today ??= date('Y-m-d');
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) parse_url(absolute_url('/'), PHP_URL_HOST)) ?: 'svj';
    $items = [];
    foreach (all('SELECT * FROM modf_contracts') as $c) {
        foreach (modf_contract_events($c) as $ev) {
            $items[] = ['uid' => 'smlouva-' . $c['id'] . '-' . $ev['kind'], 'date' => $ev['date'], 'title' => $ev['label'] . ': ' . modf_contract_title($c), 'path' => '/admin/smlouvy/' . $c['id']];
        }
    }
    foreach (all("SELECT * FROM modf_settlements WHERE published_at = ''") as $s) {
        if (valid_date($s['period_to'])) {
            $items[] = ['uid' => 'vyuctovani-' . $s['id'], 'date' => modf_delivery_deadline($s['period_to']), 'title' => 'Doručit vyúčtování: ' . $s['title'], 'path' => '/admin/vyuctovani/' . $s['id']];
        }
    }
    foreach (all("SELECT o.id, o.created_at, i.settlement_id FROM modf_objections o JOIN modf_settlement_items i ON i.id = o.item_id WHERE o.answered_at = ''") as $o) {
        $items[] = ['uid' => 'namitka-' . $o['id'], 'date' => modf_answer_deadline(substr($o['created_at'], 0, 10)), 'title' => 'Vyřídit námitku k vyúčtování', 'path' => '/admin/vyuctovani/' . $o['settlement_id']];
    }
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SVJ web//CS', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ics_text(site('name') . ' – smlouvy a lhůty')];
    foreach ($items as $it) {
        if ($it['date'] < modf_add_days($today, -365)) {
            continue;
        }
        $d = str_replace('-', '', $it['date']);
        array_push($lines, 'BEGIN:VEVENT', 'UID:' . $it['uid'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART;VALUE=DATE:' . $d,
            'DTEND;VALUE=DATE:' . date('Ymd', strtotime($it['date'] . ' +1 day')), 'SUMMARY:' . ics_text($it['title']), 'DESCRIPTION:' . ics_text(absolute_url($it['path'])),
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text($it['title']), 'TRIGGER:-P7D', 'END:VALARM', 'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';
    return ics_fold($lines);
}

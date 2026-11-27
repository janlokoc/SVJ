<?php
declare(strict_types=1);

// Dálkové odečty měřidel. Od 1. 1. 2027 musí být měřidla tepla, teplé vody a indikátory topných nákladů
// dálkově odečitatelná (zákon 406/2000 Sb., § 7) a konečný spotřebitel má každý měsíc dostat informaci o spotřebě
// (zákon 67/2013 Sb., § 8a). Data dodává odečtová firma nebo vlastní sběrnice; web je přijme (import souboru nebo
// automaticky přes adresu pro příjem dat), ukáže obyvatelům měsíční spotřebu a upozorní je e-mailem.

/** Druhy měřidel, které se odečítají na dálku: obyvatel je nezadává ručně. Výchozí podle zákona: teplá voda, teplo, indikátory. */
function remote_kinds(): array
{
    $v = setting('remote_kinds', 'tv,teplo,itn');
    return array_values(array_intersect(array_keys(READING_KINDS), array_filter(explode(',', $v))));
}
function meter_setting(string $key, string $value): void
{
    q('DELETE FROM settings WHERE `key` = ?', [$key]);
    q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
    setting('__reset');
}
/** Druh měřidla z textu ve vstupních datech (různé odečtové firmy ho píší různě). */
function meter_kind_from(string $s): ?string
{
    $s = mb_strtolower(strip_accents(trim($s)));
    return match (true) {
        isset(READING_KINDS[$s]) => $s,
        (bool) preg_match('~indik|itn|rozdelovac|hca~', $s) => 'itn',
        (bool) preg_match('~tepl\w* (uzitkov\w* )?vod|vod\w* tepl|uzitkov\w* vod|tuv|^tv$|hot|warm~', $s) => 'tv',
        (bool) preg_match('~studen|^sv$|cold|^voda$|vodomer~', $s) => 'sv',
        (bool) preg_match('~tepl|kalorim|heat|^ut$~', $s) => 'teplo',
        (bool) preg_match('~plyn|gas~', $s) => 'plyn',
        (bool) preg_match('~elektr|^el$|power~', $s) => 'el',
        default => null,
    };
}
/** Najde jednotku podle označení (A/2, 12A/2, 2) nebo podle čísla měřidla z dřívějších odečtů. */
function meter_unit_from(string $label, string $meterNo, array $units): ?int
{
    $label = mb_strtoupper(preg_replace('/\s+/', '', $label));
    if ($label !== '') {
        foreach ($units as $u) {
            $variants = [mb_strtoupper(unit_name($u)), mb_strtoupper($u['entrance'] . '/' . $u['number']), mb_strtoupper($u['entrance'] . $u['number'])];
            if (in_array($label, array_map(fn($x) => preg_replace('/\s+/', '', $x), $variants), true)) {
                return (int) $u['id'];
            }
        }
        $byNumber = array_values(array_filter($units, fn($u) => mb_strtoupper((string) $u['number']) === $label));
        if (count($byNumber) === 1) {
            return (int) $byNumber[0]['id'];
        }
    }
    if ($meterNo !== '' && ($id = val('SELECT unit_id FROM readings WHERE meter_no = ? ORDER BY read_date DESC, id DESC LIMIT 1', [$meterNo]))) {
        return (int) $id;
    }
    return null;
}
/**
 * Převede vstupní řádky (z CSV nebo z JSON) na odečty. Každý řádek: [jednotka, číslo měřidla, druh, stav, datum].
 * Vrací seznam [řádek vstupu, data k uložení nebo null, chyba].
 */
function meter_rows_check(array $rows): array
{
    $units = all('SELECT * FROM units');
    $out = [];
    foreach ($rows as $i => $r) {
        $err = '';
        $kind = meter_kind_from((string) ($r['kind'] ?? ''));
        $meter = mb_substr(trim((string) ($r['meter_no'] ?? '')), 0, 50);
        $unit = meter_unit_from((string) ($r['unit'] ?? ''), $meter, $units);
        $amount = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) ($r['amount'] ?? '')));
        $d = trim((string) ($r['date'] ?? ''));
        $date = preg_match('~^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})~', $d, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : substr($d, 0, 10);
        if (!$unit) {
            $err = 'Neznámá jednotka' . ($meter !== '' ? ' ani číslo měřidla' : '');
        } elseif (!$kind) {
            $err = 'Neznámý druh měřidla „' . mb_substr((string) ($r['kind'] ?? ''), 0, 30) . '“';
        } elseif (!is_numeric($amount) || (float) $amount < 0) {
            $err = 'Stav není číslo';
        } elseif (!valid_date($date) || $date > date('Y-m-d')) {
            $err = 'Neplatné datum';
        }
        $out[] = [$i + 1, $err ? null : ['unit_id' => $unit, 'kind' => $kind, 'meter_no' => $meter, 'amount' => (float) $amount, 'read_date' => $date], $err, $r];
    }
    return $out;
}
/**
 * Uloží odečty; stejné měřidlo ke stejnému dni se přepíše (opakovaný import nevytvoří dvojice). Vrací [nových, přepsaných, zadržených].
 * Každé přepsání uloží původní hodnotu do reading_history. U automatického příjmu ('api') hlídá meter_guard(): podezřelé
 * řádky (pokles stavu, nepravděpodobný skok, přepis ručního odečtu, přepis za měsíc s odeslaným upozorněním nebo starší
 * než METER_LOCK_DAYS) se neuloží, ale čekají ve reading_pending na schválení správcem.
 */
function meter_rows_save(array $checked, string $source): array
{
    meters_ready();
    $new = $upd = $held = 0;
    foreach ($checked as [, $d]) {
        if (!$d) {
            continue;
        }
        $old = one('SELECT * FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date = ?', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
        if ($old && abs((float) $old['amount'] - $d['amount']) < 1e-9) {
            continue;   // beze změny – nic nezapisovat
        }
        if ($source === 'api' && ($reason = meter_guard($d, $old))) {
            meter_hold($d, $source, $reason);
            $held++;
            continue;
        }
        meter_write($d, $source, $old);
        $old ? $upd++ : $new++;
    }
    return [$new, $upd, $held];
}
/** Zapíše jeden odečet; při přepisu uloží původní hodnotu do historie. */
function meter_write(array $d, string $source, ?array $old): void
{
    meters_ready();
    if ($old) {
        q('INSERT INTO reading_history (reading_id, unit_id, kind, meter_no, read_date, old_amount, old_source, new_amount, new_source, changed_at, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$old['id'], $old['unit_id'], $old['kind'], $old['meter_no'], $old['read_date'], $old['amount'], $old['source'] ?? 'manual', $d['amount'], $source, date('Y-m-d H:i:s'), user()['id'] ?? null]);
        q('UPDATE readings SET amount = ?, source = ? WHERE id = ?', [$d['amount'], $source, $old['id']]);
    } else {
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['amount'], $d['read_date'], '', $source]);
    }
}

/** CSV od odečtové firmy → vstupní řádky. Sloupce se poznají podle názvů v záhlaví. */
function meter_csv_rows(string $text): array|string
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = bank_utf8($text);   // Windows-1250 (mbstring ho neumí, převede iconv)
    }
    $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $text), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) {
        return 'Soubor je prázdný nebo má jen záhlaví.';
    }
    $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? (substr_count($lines[0], "\t") > substr_count($lines[0], ';') ? "\t" : ';') : ',';
    $head = array_map(fn($h) => mb_strtolower(strip_accents(trim($h, " \"'"))), str_getcsv($lines[0], $sep, '"', ''));
    $col = [];
    foreach ($head as $i => $h) {
        $key = match (true) {
            (bool) preg_match('~jednotk|byt|unit|prostor~', $h) => 'unit',
            (bool) preg_match('~cislo|meridl|meter|vyrobni|serial|sn~', $h) && !isset($col['meter_no']) => 'meter_no',
            (bool) preg_match('~druh|typ|medium|kind|sluzb~', $h) => 'kind',
            (bool) preg_match('~stav|hodnot|value|odecet|reading|dilk~', $h) => 'amount',
            (bool) preg_match('~datum|date|den~', $h) => 'date',
            default => null,
        };
        if ($key && !isset($col[$key])) {
            $col[$key] = $i;
        }
    }
    if (!isset($col['amount']) || !isset($col['date']) || (!isset($col['unit']) && !isset($col['meter_no']))) {
        return 'V záhlaví chybí potřebné sloupce. Potřeba je: jednotka nebo číslo měřidla, druh měřidla, stav a datum odečtu.';
    }
    $rows = [];
    foreach (array_slice($lines, 1) as $l) {
        $c = str_getcsv($l, $sep, '"', '');
        $rows[] = ['unit' => $c[$col['unit'] ?? -1] ?? '', 'meter_no' => $c[$col['meter_no'] ?? -1] ?? '', 'kind' => $c[$col['kind'] ?? -1] ?? '', 'amount' => $c[$col['amount']] ?? '', 'date' => $c[$col['date']] ?? ''];
    }
    return array_slice($rows, 0, 5000);
}
/**
 * Měsíční spotřeba: pro každé měřidlo poslední stav v měsíci minus poslední stav v předchozím měsíci.
 * Vrací [měsíc 'Y-m' => [[jednotka, druh, měřidlo, spotřeba, stav]]], nejnovější první.
 */
function monthly_consumption(array $unitIds, int $months = 12): array
{
    if (!$unitIds) {
        return [];
    }
    $since = date('Y-m-01', strtotime('-' . $months . ' months'));
    $rows = all('SELECT unit_id, kind, meter_no, amount, read_date FROM readings WHERE unit_id IN (' . implode(',', array_map('intval', $unitIds)) . ') AND read_date >= ? ORDER BY read_date, id', [$since]);
    $last = [];
    foreach ($rows as $r) {
        $last[$r['unit_id'] . '|' . $r['kind'] . '|' . $r['meter_no']][substr($r['read_date'], 0, 7)] = $r;
    }
    $out = [];
    foreach ($last as $byMonth) {
        ksort($byMonth);
        $prev = null;
        foreach ($byMonth as $ym => $r) {
            // Jen uzavřené měsíce: rozpracovaný měsíc by ukazoval neúplnou spotřebu.
            if ($prev !== null && $ym >= date('Y-m', strtotime('-' . ($months - 1) . ' months')) && $ym < date('Y-m')) {
                $out[$ym][] = [(int) $r['unit_id'], $r['kind'], $r['meter_no'], max(0, (float) $r['amount'] - (float) $prev['amount']), (float) $r['amount']];
            }
            $prev = $r;
        }
    }
    krsort($out);
    return $out;
}
/**
 * Upozorní obyvatele e-mailem, že je na webu informace o spotřebě za měsíc (prokazatelné upozornění podle § 8a). Vrací počet odeslaných.
 * $onlyNew: jen jednotkám, kterým data přišla až po předchozím upozornění za tento měsíc (dodatečné upozornění).
 */
function meter_notice_send(string $ym, bool $onlyNew = false): int
{
    $start = $ym . '-01';
    $end = date('Y-m-t', strtotime($start));
    $units = array_map('intval', array_column(all('SELECT DISTINCT unit_id FROM readings WHERE read_date BETWEEN ? AND ?', [$start, $end]), 'unit_id'));
    $done = array_map('intval', array_filter(explode(',', setting('meter_notice_units_' . $ym))));
    $target = $onlyNew ? array_values(array_diff($units, $done)) : $units;
    if (!$target) {
        return 0;
    }
    $people = all('SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.active = 1 AND uu.unit_id IN (' . implode(',', $target) . ')');
    $month = month_label($ym);
    $sent = 0;
    $GLOBALS['MAIL_BULK'] = true;   // hromadné upozornění: nad limit hostingu čeká ve frontě
    foreach ($people as $p) {
        $text = "Dobrý den,\n\nna webu " . site('name') . " je informace o spotřebě tepla a vody ve Vaší jednotce za $month.\n"
            . "Najdete ji po přihlášení v části Odečty měřidel: " . absolute_url('/odecty') . "\n\nTuto zprávu posíláme každý měsíc, protože měřidla se odečítají na dálku (zákon č. 67/2013 Sb., § 8a).\n";
        $sent += send_mail($p['email'], 'Spotřeba za ' . $month . ' – ' . site('name'), $text) ? 1 : 0;
    }
    $GLOBALS['MAIL_BULK'] = false;
    push_notify(array_column($people, 'id'), site('name'), 'Spotřeba za ' . $month . ' je na webu.', '/odecty');
    $prev = explode('|', setting('meter_notice_' . $ym));
    $total = $onlyNew && count($prev) === 2 ? (int) $prev[1] + $sent : $sent;
    meter_setting('meter_notice_' . $ym, ($onlyNew && count($prev) === 2 ? $prev[0] : date('Y-m-d H:i')) . '|' . $total);
    meter_setting('meter_notice_units_' . $ym, implode(',', array_values(array_unique([...$done, ...$target]))));
    audit('meter_notice', ($onlyNew ? 'dodatečná ' : '') . 'informace o spotřebě za ' . $month . ' · odesláno ' . $sent . ' z ' . count($people));
    return $sent;
}
/**
 * Denní úloha: když je zapnuté automatické upozorňování, pošle ho od 5. dne v měsíci za předchozí měsíc (jednou),
 * a jednotkám, kterým data přijdou později, pošle dodatečné upozornění (nejvýš jednou za den).
 */
function meter_daily(): void
{
    meter_missing_daily();
    if (setting('meter_notice_auto') !== '1' || (int) date('j') < 5) {
        return;
    }
    $ym = date('Y-m', strtotime('first day of last month'));
    if (setting('meter_notice_' . $ym) === '') {
        meter_notice_send($ym);
    } elseif (setting('meter_notice_units_' . $ym) !== '') {   // starší záznam bez seznamu jednotek dodatečně neposílá (nevíme, komu už odešlo)
        meter_notice_send($ym, true);
    }
}
/**
 * Stav automatického upozornění pro obrazovku správce: ['on' => bool, 'month' => 'Y-m' (za který měsíc půjde další),
 * 'date' => 'Y-m-d' (kdy nejdřív), 'waiting' => true když je termín za námi, ale data ještě nepřišla].
 */
function meter_notice_plan(?string $today = null): array
{
    $today ??= date('Y-m-d');
    $last = date('Y-m', strtotime('first day of last month', strtotime($today)));
    $sentLast = setting('meter_notice_' . $last) !== '';
    if ((int) substr($today, 8, 2) >= 5 && !$sentLast) {
        return ['on' => setting('meter_notice_auto') === '1', 'month' => $last, 'date' => $today, 'waiting' => true];
    }
    $month = (int) substr($today, 8, 2) < 5 && !$sentLast ? $last : substr($today, 0, 7);
    return ['on' => setting('meter_notice_auto') === '1', 'month' => $month, 'date' => date('Y-m-05', strtotime($month . '-01 +1 month')), 'waiting' => false];
}

// ---------- Přehled pro obyvatele: graf, trend, srovnání s průměrem domu ----------

/** Měřidla, u kterých má smysl srovnání mezi byty (indikátory topení ne: každý radiátor má jiný koeficient). */
const METER_COMPARE_KINDS = ['sv', 'tv', 'teplo', 'plyn', 'el'];
/** Průměr domu se ukáže jen tehdy, když v něm je aspoň tolik jednotek s daty – z menšího počtu by šlo dopočítat souseda. */
const METER_COMPARE_MIN_UNITS = 5;

function meter_unit_label(string $kind): string
{
    return match ($kind) { 'itn' => 'dílků', 'sv', 'tv', 'plyn' => 'm³', 'el' => 'kWh', default => '' };
}
function meter_compare_on(): bool
{
    return setting('meter_compare', '1') === '1';
}
/**
 * Průměr domu na m² podlahové plochy za měsíc a druh měřidla: [průměr, počet jednotek] nebo null.
 * Počítá se celková spotřeba / celková plocha jednotek, které mají plochu a nenulovou spotřebu (prázdné byty by průměr zkreslily
 * a prozradily, že byt stojí). Pod METER_COMPARE_MIN_UNITS jednotek nic – průměr by prozradil souseda.
 */
function meter_house_average(array $houseMonthly, string $ym, string $kind, array $areas): ?array
{
    if (!in_array($kind, METER_COMPARE_KINDS, true)) {
        return null;
    }
    $perUnit = [];
    foreach ($houseMonthly[$ym] ?? [] as [$unitId, $k, , $use]) {
        if ($k === $kind) {
            $perUnit[$unitId] = ($perUnit[$unitId] ?? 0) + $use;
        }
    }
    $use = $area = 0.0;
    $n = 0;
    foreach ($perUnit as $unitId => $u) {
        if ($u > 0 && ($areas[$unitId] ?? 0) > 0) {
            $use += $u;
            $area += $areas[$unitId];
            $n++;
        }
    }
    return $n >= METER_COMPARE_MIN_UNITS ? [$use / $area, $n] : null;
}
/**
 * Přehled pro obyvatele: jeden záznam na jednotku a druh měřidla (víc vodoměrů téhož druhu se sečte).
 * [['unit' => id, 'kind' => k, 'series' => ['Y-m' => spotřeba] za 12 uzavřených měsíců (starší první), 'last' => 'Y-m', 'use' => spotřeba,
 *   'yearAgo' => spotřeba téhož měsíce loni | null, 'total12' => součet, 'area' => m² | null, 'perM2' => | null,
 *   'house' => ['Y-m' => průměr domu na m²] (jen když je srovnání zapnuté a dost jednotek), 'houseN' => počet jednotek v průměru za poslední měsíc]]
 */
function meter_overview(array $mine, ?string $now = null): array
{
    $now ??= date('Y-m-d');
    $mine = array_values(array_filter(array_map('intval', $mine)));
    if (!$mine) {
        return [];
    }
    $months = [];
    for ($i = 12; $i >= 1; $i--) {
        $months[] = date('Y-m', strtotime(substr($now, 0, 7) . "-01 -$i months"));
    }
    $own = monthly_consumption($mine, 14);
    $areas = array_map('floatval', array_column(all('SELECT id, area FROM units WHERE area IS NOT NULL AND area > 0'), 'area', 'id'));
    $house = meter_compare_on() ? monthly_consumption(array_map('intval', array_column(all('SELECT id FROM units'), 'id')), 13) : [];
    $by = [];
    foreach ($own as $ym => $items) {
        foreach ($items as [$unitId, $kind, , $use]) {
            $by[$unitId . '|' . $kind][$ym] = ($by[$unitId . '|' . $kind][$ym] ?? 0) + $use;
        }
    }
    $out = [];
    foreach ($by as $key => $byMonth) {
        [$unitId, $kind] = explode('|', $key);
        $series = [];
        foreach ($months as $ym) {
            if (isset($byMonth[$ym])) {
                $series[$ym] = $byMonth[$ym];
            }
        }
        if (!$series) {
            continue;
        }
        $last = array_key_last($series);
        $ago = date('Y-m', strtotime($last . '-01 -1 year'));
        $area = $areas[(int) $unitId] ?? null;
        $houseAvg = [];
        $houseN = 0;
        foreach (array_keys($series) as $ym) {
            if ($avg = meter_house_average($house, $ym, $kind, $areas)) {
                $houseAvg[$ym] = $avg[0];
                if ($ym === $last) {
                    $houseN = $avg[1];
                }
            }
        }
        $out[] = ['unit' => (int) $unitId, 'kind' => $kind, 'series' => $series, 'last' => $last, 'use' => $series[$last],
            'yearAgo' => $byMonth[$ago] ?? null, 'total12' => array_sum($series), 'area' => $area,
            'perM2' => $area ? $series[$last] / $area : null, 'house' => $area ? $houseAvg : [], 'houseN' => $area ? $houseN : 0];
    }
    $order = array_flip(array_keys(READING_KINDS));
    usort($out, fn($a, $b) => [$a['unit'], $order[$a['kind']] ?? 99] <=> [$b['unit'], $order[$b['kind']] ?? 99]);
    return $out;
}
/** Rozdíl v procentech (zaokrouhlený), nebo null když není s čím srovnat. */
function meter_delta(float $value, ?float $base): ?int
{
    return $base === null || $base <= 0 ? null : (int) round(($value - $base) / $base * 100);
}
/** Jednotky bez odečtu v daném měsíci (pro správce): [id => označení]. */
function meter_units_missing(string $ym): array
{
    $have = array_flip(array_map('intval', array_column(all('SELECT DISTINCT unit_id FROM readings WHERE read_date BETWEEN ? AND ?', [$ym . '-01', date('Y-m-t', strtotime($ym . '-01'))]), 'unit_id')));
    return array_diff_key(unit_options(), $have);
}

// ---------- Ochrana automatického příjmu dat (11/2026) ----------

/** Neúspěšných pokusů z jedné adresy za hodinu, po kterých se adresa na hodinu zablokuje. */
const METER_API_FAIL_LIMIT = 10;
/** Starší odečty (dny) automatický příjem nepřepíše bez schválení správcem. */
const METER_LOCK_DAYS = 45;
/** Kolikrát vyšší denní spotřeba než obvykle je podezřelá (a zároveň aspoň METER_JUMP_MIN jednotek navíc). */
const METER_JUMP_FACTOR = 10;
const METER_JUMP_MIN = 1.0;
/** Od kolikátého dne v měsíci web hlídá chybějící data za předchozí měsíc. */
const METER_MISSING_DAY = 3;

/** Tabulky ochrany příjmu: záznam pokusů, zadržené řádky, historie přepisů. */
function meters_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $have = $mysql ? array_map('strval', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN))
        : array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $num = $mysql ? 'DECIMAL(14,4) NULL' : 'REAL';
    $id = 'id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        // ok: 1 přijato, 0 odmítnuto (špatný klíč, nepovolená adresa, blokace); auth: 'header' | 'url'.
        'meter_api_log' => "CREATE TABLE meter_api_log ($id, at {$s(19)}, ip {$s(45)}, ok $int, auth {$s(10)}, saved $int, updated $int, held $int, errors $int, note {$s(200)})$end",
        'reading_pending' => "CREATE TABLE reading_pending ($id, unit_id $int, kind {$s(10)}, meter_no {$s(50)}, amount $num, read_date {$s(10)}, source {$s(10)}, reason {$s(200)}, created_at {$s(19)})$end",
        'reading_history' => "CREATE TABLE reading_history ($id, reading_id $int, unit_id $int, kind {$s(10)}, meter_no {$s(50)}, read_date {$s(10)}, old_amount $num, old_source {$s(10)}, new_amount $num, new_source {$s(10)}, changed_at {$s(19)}, user_id " . ($mysql ? 'INT NULL' : 'INTEGER') . ")$end",
    ];
    foreach ($tables as $name => $ddl) {
        if (!in_array($name, $have, true)) {
            $pdo->exec($ddl);
        }
    }
}
/**
 * Je řádek z automatického příjmu podezřelý? Vrací důvod (pak čeká na schválení), nebo ''.
 * $old: dosavadní odečet téhož měřidla ke stejnému dni (přepis), nebo null.
 */
function meter_guard(array $d, ?array $old, ?string $today = null): string
{
    $today ??= date('Y-m-d');
    if ($old) {
        if (($old['source'] ?? 'manual') === 'manual' || ($old['user_id'] ?? null)) {
            return 'Přepsal by ručně zadaný odečet';
        }
        if ($d['read_date'] < date('Y-m-d', strtotime($today . ' -' . METER_LOCK_DAYS . ' days'))) {
            return 'Přepsal by odečet starší než ' . METER_LOCK_DAYS . ' dní';
        }
        if (setting('meter_notice_' . substr($d['read_date'], 0, 7)) !== '') {
            return 'Přepsal by odečet za měsíc, o kterém už obyvatelé dostali upozornění';
        }
    }
    $ym = substr($d['read_date'], 0, 7);
    if (!$old && setting('meter_notice_' . $ym) !== '' && val('SELECT id FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date BETWEEN ? AND ?',
        [$d['unit_id'], $d['kind'], $d['meter_no'], $ym . '-01', date('Y-m-t', strtotime($ym . '-01'))])) {
        return 'Změnil by spotřebu za měsíc, o kterém už obyvatelé dostali upozornění';
    }
    $prev = one('SELECT amount, read_date FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date < ? ORDER BY read_date DESC, id DESC LIMIT 1',
        [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
    if ($prev && $d['amount'] + 1e-9 < (float) $prev['amount']) {
        return 'Stav je nižší než předchozí odečet (' . str_replace('.', ',', (string) (float) $prev['amount']) . ' k ' . cz_date($prev['read_date']) . ')';
    }
    $next = one('SELECT amount, read_date FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date > ? ORDER BY read_date, id LIMIT 1',
        [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
    if ($next && $d['amount'] > (float) $next['amount'] + 1e-9) {
        return 'Stav je vyšší než pozdější odečet (' . str_replace('.', ',', (string) (float) $next['amount']) . ' k ' . cz_date($next['read_date']) . ')';
    }
    if ($prev) {
        $hist = all('SELECT amount, read_date FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date < ? ORDER BY read_date DESC, id DESC LIMIT 7',
            [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
        $rates = [];
        for ($i = 0; $i + 1 < count($hist); $i++) {
            $days = (strtotime($hist[$i]['read_date']) - strtotime($hist[$i + 1]['read_date'])) / 86400;
            if ($days >= 1) {
                $rates[] = max(0, (float) $hist[$i]['amount'] - (float) $hist[$i + 1]['amount']) / $days;
            }
        }
        $days = max(1, (strtotime($d['read_date']) - strtotime($prev['read_date'])) / 86400);
        $rate = ($d['amount'] - (float) $prev['amount']) / $days;
        if (count($rates) >= 3) {
            sort($rates);
            $median = $rates[intdiv(count($rates), 2)];
            if ($median > 0 && $rate > $median * METER_JUMP_FACTOR && $d['amount'] - (float) $prev['amount'] >= METER_JUMP_MIN) {
                return 'Nezvykle vysoká spotřeba (' . round($rate / $median) . '× víc než obvykle)';
            }
        }
    }
    return '';
}
/** Odloží podezřelý řádek ke schválení (stejné měřidlo a den nahradí). */
function meter_hold(array $d, string $source, string $reason): void
{
    meters_ready();
    q('DELETE FROM reading_pending WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date = ?', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
    q('INSERT INTO reading_pending (unit_id, kind, meter_no, amount, read_date, source, reason, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$d['unit_id'], $d['kind'], $d['meter_no'], $d['amount'], $d['read_date'], $source, mb_substr($reason, 0, 200), date('Y-m-d H:i:s')]);
}
/** Správce rozhodl o zadrženém řádku: schválit (zapíše se, i s historií) nebo zamítnout. */
function meter_pending_decide(int $id, bool $approve): bool
{
    meters_ready();
    $p = one('SELECT * FROM reading_pending WHERE id = ?', [$id]);
    if (!$p) {
        return false;
    }
    if ($approve) {
        $d = ['unit_id' => (int) $p['unit_id'], 'kind' => $p['kind'], 'meter_no' => $p['meter_no'], 'amount' => (float) $p['amount'], 'read_date' => $p['read_date']];
        meter_write($d, $p['source'], one('SELECT * FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date = ?', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]) ?: null);
    }
    q('DELETE FROM reading_pending WHERE id = ?', [$id]);
    audit('meter_pending', ($approve ? 'schválen' : 'zamítnut') . ' odečet ' . (READING_KINDS[$p['kind']] ?? $p['kind']) . ' k ' . $p['read_date'] . ' (' . $p['reason'] . ')');
    return true;
}
/** Je adresa v seznamu povolených (IP nebo rozsah CIDR, oddělené čárkou/mezerou)? Prázdný seznam = všechny. */
function meter_ip_allowed(string $ip, string $list): bool
{
    $items = array_filter(preg_split('/[\s,;]+/', trim($list)));
    if (!$items) {
        return true;
    }
    $bin = @inet_pton($ip);
    foreach ($items as $item) {
        [$net, $bits] = str_contains($item, '/') ? explode('/', $item, 2) : [$item, null];
        $nb = @inet_pton($net);
        if ($bin === false || $nb === false || strlen($bin) !== strlen($nb)) {
            continue;
        }
        $bits = $bits === null ? strlen($nb) * 8 : max(0, min(strlen($nb) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}
/** Seznam povolených adres zkontroluje; vrací normalizovaný text, nebo chybovou hlášku jako ['error' => …]. */
function meter_ip_list_clean(string $list): string|array
{
    $out = [];
    foreach (array_filter(preg_split('/[\s,;]+/', trim($list))) as $item) {
        [$net, $bits] = str_contains($item, '/') ? explode('/', $item, 2) : [$item, null];
        if (!filter_var($net, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > (str_contains($net, ':') ? 128 : 32)))) {
            return ['error' => '„' . mb_substr($item, 0, 50) . '“ není IP adresa ani rozsah (např. 203.0.113.10 nebo 203.0.113.0/24).'];
        }
        $out[] = $item;
    }
    return implode(', ', array_slice(array_unique($out), 0, 20));
}
/** Zapíše pokus o příjem dat. */
function meter_api_log(string $ip, bool $ok, string $auth, array $counts = [], string $note = ''): void
{
    meters_ready();
    q('INSERT INTO meter_api_log (at, ip, ok, auth, saved, updated, held, errors, note) VALUES (?,?,?,?,?,?,?,?,?)',
        [date('Y-m-d H:i:s'), mb_substr($ip, 0, 45), $ok ? 1 : 0, $auth, $counts[0] ?? 0, $counts[1] ?? 0, $counts[2] ?? 0, $counts[3] ?? 0, mb_substr($note, 0, 200)]);
    q('DELETE FROM meter_api_log WHERE at < ?', [date('Y-m-d H:i:s', strtotime('-180 days'))]);
}
/** Je adresa dočasně zablokovaná po opakovaných neúspěšných pokusech? */
function meter_api_blocked(string $ip): bool
{
    meters_ready();
    return (int) val('SELECT COUNT(*) FROM meter_api_log WHERE ip = ? AND ok = 0 AND at >= ?', [$ip, date('Y-m-d H:i:s', strtotime('-1 hour'))]) >= METER_API_FAIL_LIMIT;
}
/** Neúspěšný pokus: zapíše ho a při dosažení limitu jednou upozorní správce e-mailem. */
function meter_api_fail(string $ip, string $auth, string $note): void
{
    meter_api_log($ip, false, $auth, [], $note);
    $n = (int) val('SELECT COUNT(*) FROM meter_api_log WHERE ip = ? AND ok = 0 AND at >= ?', [$ip, date('Y-m-d H:i:s', strtotime('-1 hour'))]);
    if ($n === METER_API_FAIL_LIMIT) {
        audit('meter_api_block', 'příjem odečtů: ' . $n . ' neúspěšných pokusů z ' . $ip . ' za hodinu – adresa na hodinu zablokována');
        foreach (meter_admin_emails() as $to) {
            send_mail($to, 'Podezřelé pokusy o zápis odečtů – ' . site('name'),
                "Dobrý den,\n\nna adresu pro příjem odečtů přišlo za poslední hodinu $n neúspěšných pokusů z IP adresy $ip (např. špatný klíč). Adresu jsme na hodinu zablokovali.\n\n"
                . "Pokud to nebyla Vaše odečtová firma, doporučujeme v administraci vytvořit novou adresu pro příjem dat a omezit příjem na IP adresy firmy:\n" . absolute_url('/admin/odecty/dalkove#prijem') . "\n");
        }
    }
}
function meter_admin_emails(): array
{
    return array_column(all("SELECT email FROM users WHERE role = 'admin' AND active = 1"), 'email');
}
/** Klíč z hlavičky Authorization: Bearer … nebo X-Api-Key (doporučeno místo klíče v adrese), jinak ''. */
function meter_bearer(): string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([a-f0-9]{40})$/i', trim($h), $m)) {
        return strtolower($m[1]);
    }
    // Náhrada pro servery, které hlavičku Authorization do PHP nepředají: X-Api-Key.
    $k = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return preg_match('/^[a-f0-9]{40}$/i', $k) ? strtolower($k) : '';
}

// ---------- Chybějící data od odečtové firmy ----------

/**
 * Měřidla odečítaná na dálku, která poslala data předminulý měsíc (před $ym), ale za $ym nic: [[unit_id, kind, meter_no]].
 * Jednotky, které nemají žádná data za $ym ani za předchozí měsíc, se uvádějí zvlášť v meter_units_missing().
 */
function meter_missing(string $ym): array
{
    $kinds = remote_kinds();
    if (!$kinds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($kinds), '?'));
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    $range = fn(string $m) => [$m . '-01', date('Y-m-t', strtotime($m . '-01'))];
    $before = all("SELECT DISTINCT unit_id, kind, meter_no FROM readings WHERE kind IN ($in) AND read_date BETWEEN ? AND ?", [...$kinds, ...$range($prevYm)]);
    $now = array_flip(array_map(fn($r) => $r['unit_id'] . '|' . $r['kind'] . '|' . $r['meter_no'],
        all("SELECT DISTINCT unit_id, kind, meter_no FROM readings WHERE kind IN ($in) AND read_date BETWEEN ? AND ?", [...$kinds, ...$range($ym)])));
    $out = [];
    foreach ($before as $r) {
        if (!isset($now[$r['unit_id'] . '|' . $r['kind'] . '|' . $r['meter_no']])) {
            $out[] = [(int) $r['unit_id'], $r['kind'], $r['meter_no']];
        }
    }
    return $out;
}
/** Text seznamu chybějících měřidel pro e-mail. */
function meter_missing_text(array $missing): string
{
    $units = unit_options();
    return implode("\n", array_map(fn($m) => '- ' . ($units[$m[0]] ?? ('jednotka ' . $m[0])) . ': ' . (READING_KINDS[$m[1]] ?? $m[1]) . ($m[2] !== '' ? ', měřidlo č. ' . $m[2] : ''), $missing));
}
/** E-mail odečtové firmě s žádostí o doplnění. Vrací true, když odešel. */
function meter_missing_request(string $ym, array $missing): bool
{
    $firm = setting('meter_firm_email');
    if (!filter_var($firm, FILTER_VALIDATE_EMAIL) || !$missing) {
        return false;
    }
    $admins = meter_admin_emails();
    $ok = send_mail($firm, 'Chybějící dálkové odečty za ' . month_label($ym) . ' – ' . site('name'),
        "Dobrý den,\n\nza " . month_label($ym) . " nám od Vás nepřišla data z těchto měřidel (" . site('name') . ", " . setting('address') . "):\n\n"
        . meter_missing_text($missing) . "\n\nProsíme o jejich doplnění. Za předchozí měsíc data z těchto měřidel přišla.\n\n"
        . "Obyvatelé mají podle zákona č. 67/2013 Sb. dostávat informaci o spotřebě každý měsíc, proto data potřebujeme co nejdřív.\n\nDěkujeme\nvýbor " . site('name') . "\n",
        null, null, null, $admins[0] ?? null);
    if ($ok) {
        meter_setting('meter_missing_asked_' . $ym, date('Y-m-d H:i'));
        audit('meter_missing', 'žádost odečtové firmě o doplnění dat za ' . month_label($ym) . ' (' . count($missing) . ' měřidel)');
    }
    return $ok;
}
/** Denní hlídání: od METER_MISSING_DAY. dne upozorní správce (a případně firmu) jednou za měsíc, když data chybí. */
function meter_missing_daily(?string $today = null): void
{
    $today ??= date('Y-m-d');
    if (!remote_kinds() || (int) substr($today, 8, 2) < METER_MISSING_DAY) {
        return;
    }
    $ym = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    if (setting('meter_missing_warned_' . $ym) !== '' || !($missing = meter_missing($ym))) {
        return;
    }
    meter_setting('meter_missing_warned_' . $ym, $today);
    $firmAuto = setting('meter_firm_auto') === '1' && meter_missing_request($ym, $missing);
    foreach (meter_admin_emails() as $to) {
        send_mail($to, 'Chybí data o spotřebě za ' . month_label($ym) . ' – ' . site('name'),
            "Dobrý den,\n\nza " . month_label($ym) . " zatím nepřišla data z " . count($missing) . " měřidel, která minulý měsíc data posílala:\n\n" . meter_missing_text($missing) . "\n\n"
            . ($firmAuto ? "Odečtovou firmu jsme automaticky požádali o doplnění.\n" : "Požádejte prosím odečtovou firmu o doplnění – v administraci na to je tlačítko:\n" . absolute_url('/admin/odecty/dalkove#chybi') . "\n")
            . "Až data přijdou, obyvatelé dostanou upozornění dodatečně. Do té doby můžete stav zadat ručně, nebo dočasně povolit, aby ho obyvatelé zadali sami.\n");
    }
    audit('meter_missing', 'chybí data za ' . month_label($ym) . ': ' . count($missing) . ' měřidel');
}
/** Pro stránku obyvatele: když jeho jednotkám chybí data za minulý měsíc, který měly mít (data přišla předminulý měsíc), vrátí 'Y-m'. */
function meter_late_month(array $mine): string
{
    $ym = date('Y-m', strtotime('first day of last month'));
    $mine = array_flip(array_map('intval', $mine));
    foreach (meter_missing($ym) as [$unitId]) {
        if (isset($mine[$unitId])) {
            return $ym;
        }
    }
    return '';
}
/** Smí obyvatelé dočasně zadávat stav i u měřidel odečítaných na dálku (náhradní odečet při výpadku)? */
function meter_manual_fallback(): bool
{
    return setting('meter_manual_fallback') === '1';
}

// ---------- Správce: nastavení, import, adresa pro příjem dat, upozornění ----------

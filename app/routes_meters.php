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
        (bool) preg_match('~tepl\w* vod|tuv|^tv$|hot|warm~', $s) => 'tv',
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
/** Uloží odečty; stejné měřidlo ke stejnému dni se přepíše (opakovaný import nevytvoří dvojice). Vrací [nových, přepsaných]. */
function meter_rows_save(array $checked, string $source): array
{
    $new = $upd = 0;
    foreach ($checked as [, $d]) {
        if (!$d) {
            continue;
        }
        $id = val('SELECT id FROM readings WHERE unit_id = ? AND kind = ? AND meter_no = ? AND read_date = ?', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['read_date']]);
        if ($id) {
            q('UPDATE readings SET amount = ?, source = ? WHERE id = ?', [$d['amount'], $source, $id]);
            $upd++;
        } else {
            q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$d['unit_id'], $d['kind'], $d['meter_no'], $d['amount'], $d['read_date'], '', $source]);
            $new++;
        }
    }
    return [$new, $upd];
}
/** CSV od odečtové firmy → vstupní řádky. Sloupce se poznají podle názvů v záhlaví. */
function meter_csv_rows(string $text): array|string
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1250');
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
            if ($prev !== null && $ym >= date('Y-m', strtotime('-' . ($months - 1) . ' months'))) {
                $out[$ym][] = [(int) $r['unit_id'], $r['kind'], $r['meter_no'], max(0, (float) $r['amount'] - (float) $prev['amount']), (float) $r['amount']];
            }
            $prev = $r;
        }
    }
    krsort($out);
    return $out;
}
/** Upozorní obyvatele e-mailem, že je na webu informace o spotřebě za měsíc (prokazatelné upozornění podle § 8a). Vrací počet odeslaných. */
function meter_notice_send(string $ym): int
{
    $start = $ym . '-01';
    $end = date('Y-m-t', strtotime($start));
    $units = array_map('intval', array_column(all('SELECT DISTINCT unit_id FROM readings WHERE read_date BETWEEN ? AND ?', [$start, $end]), 'unit_id'));
    if (!$units) {
        return 0;
    }
    $people = all('SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.active = 1 AND uu.unit_id IN (' . implode(',', $units) . ')');
    $month = month_name((int) substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4);
    $sent = 0;
    foreach ($people as $p) {
        $text = "Dobrý den,\n\nna webu " . site('name') . " je informace o spotřebě tepla a vody ve Vaší jednotce za $month.\n"
            . "Najdete ji po přihlášení v části Odečty měřidel: " . absolute_url('/odecty') . "\n\nTuto zprávu posíláme každý měsíc, protože měřidla se odečítají na dálku (zákon č. 67/2013 Sb., § 8a).\n";
        $sent += send_mail($p['email'], 'Spotřeba za ' . $month . ' – ' . site('name'), $text) ? 1 : 0;
    }
    meter_setting('meter_notice_' . $ym, date('Y-m-d H:i') . '|' . $sent);
    audit('meter_notice', 'informace o spotřebě za ' . $month . ' · odesláno ' . $sent . ' z ' . count($people));
    return $sent;
}
/** Denní úloha: když je zapnuté automatické upozorňování, pošle ho 5. den v měsíci za předchozí měsíc (jednou). */
function meter_daily(): void
{
    if (setting('meter_notice_auto') !== '1' || (int) date('j') < 5) {
        return;
    }
    $ym = date('Y-m', strtotime('first day of last month'));
    if (setting('meter_notice_' . $ym) === '') {
        meter_notice_send($ym);
    }
}

// ---------- Správce: nastavení, import, adresa pro příjem dat, upozornění ----------

$route('GET', '/admin/odecty/dalkove', function () {
    require_admin();
    $prev = date('Y-m', strtotime('first day of last month'));
    $start = $prev . '-01';
    $end = date('Y-m-t', strtotime($start));
    $withData = (int) val('SELECT COUNT(DISTINCT unit_id) FROM readings WHERE read_date BETWEEN ? AND ?', [$start, $end]);
    $notices = [];
    foreach (all("SELECT `key`, value FROM settings WHERE `key` LIKE 'meter_notice_2%' ORDER BY `key` DESC LIMIT 12") as $r) {
        $notices[substr($r['key'], 13)] = explode('|', $r['value']);
    }
    $token = setting('meter_api_token');
    render('admin/meters', ['title' => 'Dálkové odečty', 'remote' => remote_kinds(), 'prev' => $prev, 'withData' => $withData, 'units' => (int) val('SELECT COUNT(*) FROM units'),
        'bySource' => array_column(all('SELECT source, COUNT(*) n FROM readings WHERE read_date >= ? GROUP BY source', [date('Y-m-d', strtotime('-60 days'))]), 'n', 'source'),
        'notices' => $notices, 'apiUrl' => $token !== '' ? absolute_url('/api/odecty/' . $token) : '', 'preview' => $_SESSION['meter_preview'] ?? null]);
    unset($_SESSION['meter_preview']);
});
$route('POST', '/admin/odecty/dalkove', function () {
    require_admin();
    $do = post('do');
    if ($do === 'kinds') {
        $kinds = array_values(array_intersect(array_keys(READING_KINDS), (array) ($_POST['kinds'] ?? [])));
        meter_setting('remote_kinds', implode(',', $kinds) ?: '-');
        meter_setting('meter_notice_auto', isset($_POST['auto']) ? '1' : '0');
        audit('meter_settings', 'na dálku: ' . (implode(', ', array_map(fn($k) => READING_KINDS[$k], $kinds)) ?: 'nic') . ' · automatické upozornění ' . (isset($_POST['auto']) ? 'zapnuto' : 'vypnuto'));
        flash('Nastavení dálkových odečtů je uložené.');
    } elseif ($do === 'token') {
        meter_setting('meter_api_token', bin2hex(random_bytes(20)));
        audit('meter_settings', 'nová adresa pro příjem dat');
        flash('Vytvořili jsme novou adresu pro příjem dat. Ta dosavadní přestala fungovat – pošlete odečtové firmě novou.');
    } elseif ($do === 'token-off') {
        meter_setting('meter_api_token', '');
        flash('Příjem dat přes adresu je vypnutý.', 'info');
    } elseif ($do === 'notice') {
        $ym = preg_match('/^\d{4}-\d{2}$/', post('mesic')) ? post('mesic') : date('Y-m', strtotime('first day of last month'));
        $n = meter_notice_send($ym);
        flash($n ? 'Upozornění odeslali jsme ' . $n . ' obyvatelům.' : 'Za tento měsíc nejsou žádné odečty, nebo web neumí posílat e-maily.', $n ? 'ok' : 'error');
    } elseif ($do === 'preview' || $do === 'save') {
        $text = $do === 'preview' ? (is_uploaded_file($_FILES['file']['tmp_name'] ?? '') && ($_FILES['file']['size'] ?? 0) <= 5 * 1048576 ? (string) file_get_contents($_FILES['file']['tmp_name']) : '') : (string) base64_decode(post('data'), true);
        $rows = $text !== '' ? meter_csv_rows($text) : 'Vyberte soubor CSV (do 5 MB).';
        if (is_string($rows)) {
            flash($rows, 'error');
        } elseif ($do === 'preview') {
            $checked = meter_rows_check($rows);
            $_SESSION['meter_preview'] = ['data' => base64_encode($text), 'ok' => count(array_filter($checked, fn($c) => $c[1])), 'bad' => array_slice(array_values(array_filter($checked, fn($c) => !$c[1])), 0, 50),
                'sample' => array_slice(array_values(array_filter($checked, fn($c) => $c[1])), 0, 10), 'total' => count($checked)];
        } else {
            [$new, $upd] = meter_rows_save(meter_rows_check($rows), 'import');
            audit('meter_import', 'nových ' . $new . ', přepsaných ' . $upd);
            flash('Uloženo: nových odečtů ' . $new . ($upd ? ', přepsaných ' . $upd : '') . '.');
        }
    }
    redirect('/admin/odecty/dalkove');
});

// ---------- Automatický příjem dat (odečtová firma nebo vlastní sběrnice) ----------

$route('POST', '/api/odecty/([a-f0-9]{40})', function (string|int $token) {
    $token = (string) $token;
    header('Content-Type: application/json; charset=utf-8');
    $answer = function (int $code, array $body): never {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (setting('meter_api_token') === '' || !hash_equals(setting('meter_api_token'), $token)) {
        $answer(403, ['ok' => false, 'error' => 'Neplatná adresa pro příjem dat.']);
    }
    if (!plan_has('odecty')) {
        $answer(402, ['ok' => false, 'error' => 'Odečty měřidel nejsou součástí edice tohoto webu.']);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 5 * 1048576);
    $json = json_decode($body, true);
    $rows = is_array($json) ? array_map(fn($r) => is_array($r) ? ['unit' => $r['unit'] ?? $r['jednotka'] ?? '', 'meter_no' => $r['meter_no'] ?? $r['meridlo'] ?? '', 'kind' => $r['kind'] ?? $r['druh'] ?? '',
        'amount' => (string) ($r['amount'] ?? $r['stav'] ?? ''), 'date' => $r['date'] ?? $r['datum'] ?? ''] : [], array_slice(isset($json['readings']) ? (array) $json['readings'] : $json, 0, 5000)) : meter_csv_rows($body);
    if (is_string($rows)) {
        $answer(400, ['ok' => false, 'error' => $rows]);
    }
    $checked = meter_rows_check($rows);
    [$new, $upd] = meter_rows_save($checked, 'api');
    $errors = array_map(fn($c) => ['row' => $c[0], 'error' => $c[2]], array_values(array_filter($checked, fn($c) => !$c[1])));
    audit('meter_import', 'automaticky · nových ' . $new . ', přepsaných ' . $upd . ($errors ? ', chybných ' . count($errors) : ''));
    $answer(200, ['ok' => true, 'saved' => $new, 'updated' => $upd, 'errors' => array_slice($errors, 0, 100)]);
});

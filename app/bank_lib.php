<?php
declare(strict_types=1);

/*
 * Párování plateb s bankou (modul Platby na jednotku).
 *
 * Stav účtu jednotky nahrává výbor z účetnictví ke dni (unit_accounts.as_of). Příchozí platby z výpisu banky se
 * k němu přičítají, jen pokud přišly PO tomto dni – nic se tak nezapočítá dvakrát a nový import z účetnictví
 * starší platby přirozeně „pohltí“. Platby se párují s jednotkami podle variabilního symbolu.
 *
 * Zdroje: soubor výpisu (GPC/ABO – standard českých bank, nebo CSV s českými záhlavími, např. z Fio)
 * a automaticky denně Fio API (token jen pro čtení, zdarma; zadává výbor).
 */

function bank_ready(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $mysql = db_driver() === 'mysql';
    $exists = $mysql ? (bool) db()->query("SHOW TABLES LIKE 'bank_tx'")->fetch() : (bool) db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'bank_tx'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    db()->exec('CREATE TABLE bank_tx (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", tx_id {$s(80)}, tx_date {$s(10)}, amount " . ($mysql ? 'DECIMAL(12,2)' : 'REAL')
        . " NOT NULL DEFAULT 0, vs {$s(20)}, counter_account {$s(60)}, counter_name {$s(140)}, message {$s(255)}, unit_id " . ($mysql ? 'INT' : 'INTEGER') . " NOT NULL DEFAULT 0, source {$s(20)}, created_at {$s(19)})"
        . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    db()->exec('CREATE UNIQUE INDEX bank_tx_id ON bank_tx (tx_id)');
    db()->exec('CREATE INDEX bank_tx_unit ON bank_tx (unit_id, tx_date)');
}
function bank_utf8(string $s): string
{
    if (mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    // Výpisy z bank bývají ve Windows-1250; mbstring ho neumí všude, iconv ano (jinak blízké ISO-8859-2).
    $out = function_exists('iconv') ? @iconv('CP1250', 'UTF-8//IGNORE', $s) : false;
    return $out !== false ? $out : (string) mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
}
function bank_vs(string $vs): string
{
    return ltrim(preg_replace('/\D/', '', $vs), '0');
}
/**
 * Výpis ve formátu GPC (ABO): řádky 075 jsou pohyby. Bere jen příchozí platby (kód účtování 2) a storna
 * příchozích plateb (5, záporně). Pevné pozice podle specifikace formátu.
 */
function bank_parse_gpc(string $data): array
{
    $out = [];
    foreach (preg_split('/\R/', bank_utf8($data)) as $line) {
        if (!str_starts_with($line, '075') || mb_strlen($line) < 97) {
            continue;
        }
        $f = fn(int $from, int $len) => trim(mb_substr($line, $from - 1, $len));
        $code = $f(61, 1);
        if (!in_array($code, ['2', '5'], true)) {
            continue;
        }
        $d = $f(92, 6);   // DDMMRR
        $date = preg_match('/^(\d\d)(\d\d)(\d\d)$/', $d, $m) ? '20' . $m[3] . '-' . $m[2] . '-' . $m[1] : '';
        $amount = (int) $f(49, 12) / 100 * ($code === '5' ? -1 : 1);
        $account = ltrim($f(20, 16), '0');
        $out[] = ['id' => 'gpc:' . $f(4, 16) . ':' . $f(36, 13) . ':' . $date . ':' . $f(49, 12), 'date' => $date, 'amount' => $amount, 'vs' => bank_vs($f(62, 10)),
            'account' => $account !== '' ? $account . '/' . $f(74, 4) : '', 'name' => $f(98, 20), 'message' => ''];
    }
    return $out;
}
/** CSV výpis s českými záhlavími (Fio a další banky): najde řádek se záhlavím a sloupce podle názvů. */
function bank_parse_csv(string $data): array
{
    $lines = preg_split('/\R/', bank_utf8(preg_replace('/^\xEF\xBB\xBF/', '', $data)));
    $norm = fn(string $s) => mb_strtolower(trim(trim($s), '"'));
    $col = null;
    $sep = ';';
    $out = [];
    $seen = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        if ($col === null) {
            foreach ([';', ',', "\t"] as $try) {
                $h = array_map($norm, str_getcsv($line, $try));
                // Názvy v pořadí přednosti: nejdřív přesná shoda, pak začátek názvu sloupce.
                $find = function (array $names) use ($h): ?int {
                    foreach ($names as $n) {
                        $i = array_search($n, $h, true);
                        if ($i !== false) {
                            return $i;
                        }
                    }
                    foreach ($names as $n) {
                        foreach ($h as $i => $name) {
                            if (str_starts_with($name, $n)) {
                                return $i;
                            }
                        }
                    }
                    return null;
                };
                $date = $find(['datum', 'datum zaúčtování', 'datum provedení', 'date']);
                $amount = $find(['objem', 'částka', 'castka', 'amount']);
                $vs = $find(['vs', 'variabilní symbol', 'variabilni symbol']);
                if ($date !== null && $amount !== null && $vs !== null) {
                    $col = ['date' => $date, 'amount' => $amount, 'vs' => $vs, 'id' => $find(['id pohybu', 'id transakce', 'id']),
                        'account' => $find(['protiúčet', 'číslo protiúčtu', 'protiucet']), 'bank' => $find(['kód banky', 'kod banky']),
                        'name' => $find(['název protiúčtu', 'nazev protiuctu', 'protistrana', 'název']), 'message' => $find(['zpráva pro příjemce', 'zpráva', 'poznámka'])];
                    $sep = $try;
                    break;
                }
            }
            continue;
        }
        $c = str_getcsv($line, $sep);
        $get = fn(?int $i) => $i === null ? '' : trim((string) ($c[$i] ?? ''));
        $amount = (float) str_replace([' ', "\u{a0}", ','], ['', '', '.'], $get($col['amount']));
        $rawDate = $get($col['date']);
        $date = preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $rawDate, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate) ? substr($rawDate, 0, 10) : '');
        if ($date === '' || $amount <= 0) {
            continue;   // jen příchozí platby
        }
        $id = $get($col['id']);
        if ($id === '') {
            // Bez ID pohybu: otisk řádku; stejné řádky ve výpisu se rozliší pořadím.
            $key = md5($line);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $id = 'csv:' . $key . ':' . $seen[$key];
        } else {
            $id = 'csv:' . $id;
        }
        $acc = $get($col['account']);
        $out[] = ['id' => $id, 'date' => $date, 'amount' => $amount, 'vs' => bank_vs($get($col['vs'])), 'account' => $acc . ($acc !== '' && $get($col['bank']) !== '' ? '/' . $get($col['bank']) : ''),
            'name' => $get($col['name']), 'message' => $get($col['message'])];
    }
    return $out;
}
function bank_parse(string $data): array
{
    return preg_match('/^07[45]/m', $data) ? bank_parse_gpc($data) : bank_parse_csv($data);
}
/**
 * Fio API (zdarma, token jen pro čtení z internetového bankovnictví Fio): pohyby za posledních 30 dnů.
 * Adresa jde v config.php přepsat (bank.fio_url) – kvůli zkouškám. Vrací pohyby, nebo chybu jako string.
 */
function bank_fio_fetch(string $token): array|string
{
    if (!preg_match('/^[A-Za-z0-9]{20,100}$/', $token)) {
        return 'Token Fio API nemá správný tvar.';
    }
    $base = rtrim((string) (app_config()['bank']['fio_url'] ?? 'https://fioapi.fio.cz/v1/rest'), '/');
    $url = $base . '/periods/' . $token . '/' . date('Y-m-d', time() - 30 * 86400) . '/' . date('Y-m-d') . '/transactions.json';
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    if ($body === false || $status === 0) {
        return 'Banka teď neodpovídá. Zkusíme to znovu zítra.';
    }
    if ($status === 409) {
        return 'Fio API dovoluje stažení jednou za 30 sekund. Zkuste to za chvíli.';
    }
    if ($status >= 400) {
        return 'Banka token odmítla (chyba ' . $status . '). Zkontrolujte, že je token platný a má oprávnění k pohybům.';
    }
    $j = json_decode($body, true);
    $list = $j['accountStatement']['transactionList']['transaction'] ?? null;
    if (!is_array($list)) {
        return 'Odpověď banky nemá očekávaný tvar.';
    }
    $v = fn(array $t, int $n) => $t['column' . $n]['value'] ?? null;
    $out = [];
    foreach ($list as $t) {
        $amount = (float) $v($t, 1);
        if ($amount <= 0) {
            continue;
        }
        $acc = (string) ($v($t, 2) ?? '');
        $out[] = ['id' => 'fio:' . (string) $v($t, 22), 'date' => substr((string) $v($t, 0), 0, 10), 'amount' => $amount, 'vs' => bank_vs((string) ($v($t, 5) ?? '')),
            'account' => $acc . ($acc !== '' && $v($t, 3) ? '/' . $v($t, 3) : ''), 'name' => (string) ($v($t, 10) ?? ''), 'message' => (string) ($v($t, 16) ?? '')];
    }
    return $out;
}
/** Uloží nové pohyby (už známé přeskočí) a spáruje je podle VS. Vrací [nových, spárovaných, nespárovaných]. */
function bank_store(array $txs, string $source): array
{
    bank_ready();
    $byVs = [];
    foreach (all("SELECT unit_id, vs FROM unit_accounts WHERE vs <> ''") as $a) {
        $byVs[bank_vs((string) $a['vs'])] = (int) $a['unit_id'];
    }
    $new = $matched = 0;
    foreach ($txs as $t) {
        if ($t['date'] === '' || one('SELECT 1 AS x FROM bank_tx WHERE tx_id = ?', [mb_substr($t['id'], 0, 80)])) {
            continue;
        }
        $unit = $t['vs'] !== '' ? ($byVs[$t['vs']] ?? 0) : 0;
        q('INSERT INTO bank_tx (tx_id, tx_date, amount, vs, counter_account, counter_name, message, unit_id, source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [mb_substr($t['id'], 0, 80), $t['date'], round((float) $t['amount'], 2), mb_substr($t['vs'], 0, 20), mb_substr($t['account'], 0, 60), mb_substr($t['name'], 0, 140), mb_substr($t['message'], 0, 255), $unit, $source, date('Y-m-d H:i:s')]);
        $new++;
        $matched += $unit ? 1 : 0;
    }
    return [$new, $matched, $new - $matched];
}
/** Kolik přišlo na každou jednotku z banky po dni stavu z účetnictví: [unit_id => částka]. */
function bank_paid_since(): array
{
    bank_ready();
    $out = [];
    foreach (all('SELECT a.unit_id, SUM(t.amount) AS paid FROM unit_accounts a JOIN bank_tx t ON t.unit_id = a.unit_id AND t.tx_date > a.as_of GROUP BY a.unit_id') as $r) {
        $out[(int) $r['unit_id']] = (float) $r['paid'];
    }
    return $out;
}
/** Denně: stáhne pohyby z Fio API, je-li zadán token (a funkce Platby je v edici). */
function bank_daily(): void
{
    $token = setting('fio_token');
    if ($token === '' || (function_exists('plan_has') && !plan_has('platby'))) {
        return;
    }
    $r = bank_fio_fetch($token);
    $set = fn(string $k, string $v) => q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$k, $v]);
    if (is_string($r)) {
        $set('bank_last', date('Y-m-d H:i') . '|chyba|' . $r);
        return;
    }
    [$new, $matched] = bank_store($r, 'fio');
    $set('bank_last', date('Y-m-d H:i') . "|ok|nových $new, spárováno $matched");
}

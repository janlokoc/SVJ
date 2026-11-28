<?php
declare(strict_types=1);

/*
 * Konto bytu z účetnictví (11/2026, první zdroj: POHODA): předpis záloh po položkách, pohledávky se splatností a stavem
 * úhrady a přijaté platby – vlastník je vidí v Platby a zálohy, výbor je nahraje z XML exportu POHODY.
 *
 * POHODA (Stormware) – XML export (Datová komunikace → XML export, nebo odpověď na lst:listInvoiceRequest /
 * lst:listBankRequest), obálka rsp:responsePack → lst:listInvoice / lst:listBank, kódování Windows-1250.
 * Dokumentace: https://api.stormware.cz/pohoda/popis-struktury-xml/ , schémata invoice.xsd, bank.xsd (version_2).
 *  - Ostatní pohledávky: lst:invoice s inv:invoiceHeader/inv:invoiceType = receivable – SVJ v nich obvykle vede měsíční
 *    předpis záloh na jednotku s variabilním symbolem. Částka = součet inv:invoiceSummary/inv:homeCurrency
 *    (priceNone + priceLowSum + priceHighSum + priceHigh3Sum + zaokrouhlení), uhrazeno = inv:liquidation/typ:amountHome
 *    (stav likvidace, jen export). Položky (fond oprav, teplo…) = inv:invoiceDetail/inv:invoiceItem (inv:text, typ:priceSum).
 *  - Banka: lst:bank s bnk:bankType = receipt (příjem), bnk:symVar, bnk:datePayment, částka z bnk:bankSummary/bnk:homeCurrency
 *    (případně součet položek bnk:bankDetail).
 * NEOVĚŘENO na skutečném exportu ostatních pohledávek SVJ (vzorové soubory Stormware obsahují jen vydané faktury a pár
 * testovacích bankovních dokladů); „uhrazeno = liquidation/amountHome“ je odvozené z popisu schématu a vzorů.
 *
 * Přiřazení k jednotce: podle variabilního symbolu z Platby na jednotku (unit_accounts.vs), bez úvodních nul.
 * Po importu se u přiřazených jednotek přepíše měsíční předpis (poslední pohledávka) a stav účtu (−zbývá uhradit) v
 * unit_accounts, takže dál fungují upomínky, QR platba a upozornění na úvodní stránce.
 */

const LEDGER_SOURCES = ['pohoda' => 'POHODA'];
const LEDGER_MAX_BYTES = 20 * 1048576;

function ledger_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $have = $mysql ? array_map('strval', $pdo->query("SHOW TABLES LIKE 'ledger%'")->fetchAll(PDO::FETCH_COLUMN))
        : array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'ledger%'")->fetchAll(PDO::FETCH_COLUMN));
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $num = $mysql ? 'DECIMAL(14,2) NOT NULL DEFAULT 0' : 'REAL NOT NULL DEFAULT 0';
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $id = 'id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    if (!in_array('ledger_docs', $have, true)) {
        // kind: receivable (pohledávka – předpis, vyúčtování…) | payment (přijatá platba); unit_id 0 = VS se nepřiřadil.
        $pdo->exec("CREATE TABLE ledger_docs ($id, unit_id $int, vs {$s(20)}, kind {$s(12)}, source {$s(12)}, ext_id {$s(60)}, number {$s(40)},
            doc_date {$s(10)}, due_date {$s(10)}, text {$s(255)}, amount $num, paid $num, items $text, imported_at {$s(19)})$end");
        $pdo->exec('CREATE UNIQUE INDEX ledger_docs_ext ON ledger_docs (source, kind, ext_id)');
        $pdo->exec('CREATE INDEX ledger_docs_unit ON ledger_docs (unit_id, kind, doc_date)');
    }
    if (!in_array('ledger_imports', $have, true)) {
        $pdo->exec("CREATE TABLE ledger_imports ($id, at {$s(19)}, source {$s(12)}, file {$s(200)}, receivables $int, payments $int, unmatched $int, user_id $int)$end");
    }
}

/** Částka v Kč pro zobrazení (stejný tvar jako Platby na jednotku). */
function ledger_money(float $v): string
{
    return number_format($v, round($v) == $v ? 0 : 2, ',', "\u{a0}") . "\u{a0}Kč";
}
function ledger_float(?DOMNode $n): float
{
    return $n ? (float) str_replace([' ', ','], ['', '.'], trim($n->textContent)) : 0.0;
}
function ledger_vs(string $vs): string
{
    return ltrim(preg_replace('/\D/', '', $vs), '0');
}

/**
 * Rozebere XML export POHODY. Vrací ['receivables' => [...], 'payments' => [...], 'skipped' => počet jiných dokladů]
 * nebo text chyby. Doklad: ['ext_id', 'number', 'vs', 'doc_date', 'due_date', 'text', 'amount', 'paid', 'items' => [[text, částka]]].
 */
function ledger_pohoda_parse(string $xml): array|string
{
    if ($xml === '' || strlen($xml) > LEDGER_MAX_BYTES) {
        return 'Soubor je prázdný nebo příliš velký (nejvýše 20 MB).';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET);   // bez síťových odkazů; externí entity libxml nenačítá (bez LIBXML_NOENT)
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return 'Soubor není platné XML. Nahrajte XML export z POHODY (Datová komunikace → XML export).';
    }
    $x = new DOMXPath($doc);
    $ns = ['inv' => 'invoice.xsd', 'bnk' => 'bank.xsd', 'typ' => 'type.xsd'];
    foreach ($ns as $p => $file) {
        $x->registerNamespace($p, 'http://www.stormware.cz/schema/version_2/' . $file);
    }
    $out = ['receivables' => [], 'payments' => [], 'skipped' => 0];
    $one = fn(string $q, DOMNode $ctx) => $x->query($q, $ctx)->item(0);
    $txt = fn(string $q, DOMNode $ctx) => trim((string) ($one($q, $ctx)?->textContent ?? ''));
    foreach ($x->query('//inv:invoiceHeader') as $h) {
        $root = $h->parentNode;
        if ($txt('inv:invoiceType', $h) !== 'receivable') {
            $out['skipped']++;
            continue;
        }
        $sum = 0.0;
        foreach (['typ:priceNone', 'typ:priceLowSum', 'typ:priceHighSum', 'typ:price3Sum', 'typ:round/typ:priceRound'] as $q) {
            $sum += ledger_float($one('inv:invoiceSummary/inv:homeCurrency/' . $q, $root));
        }
        $items = [];
        foreach ($x->query('inv:invoiceDetail/inv:invoiceItem', $root) as $it) {
            $price = $one('inv:homeCurrency/typ:priceSum', $it) ?? $one('inv:homeCurrency/typ:price', $it);
            $items[] = [mb_substr($txt('inv:text', $it), 0, 120), round(ledger_float($price), 2)];
        }
        if ($sum == 0.0 && $items) {
            $sum = array_sum(array_column($items, 1));   // bez souhrnu: součet položek
        }
        $number = $txt('inv:number/typ:numberRequested', $h);
        $out['receivables'][] = ['ext_id' => ($txt('inv:id', $h) ?: $number), 'number' => mb_substr($number, 0, 40), 'vs' => ledger_vs($txt('inv:symVar', $h)),
            'doc_date' => substr($txt('inv:date', $h), 0, 10), 'due_date' => substr($txt('inv:dateDue', $h), 0, 10), 'text' => mb_substr($txt('inv:text', $h), 0, 255),
            'amount' => round($sum, 2), 'paid' => round(ledger_float($one('inv:liquidation/typ:amountHome', $h)), 2), 'items' => $items];
    }
    foreach ($x->query('//bnk:bankHeader') as $h) {
        $root = $h->parentNode;
        if ($txt('bnk:bankType', $h) !== 'receipt') {
            $out['skipped']++;
            continue;
        }
        $sum = 0.0;
        foreach (['typ:priceNone', 'typ:priceLowSum', 'typ:priceHighSum', 'typ:price3Sum'] as $q) {
            $sum += ledger_float($one('bnk:bankSummary/bnk:homeCurrency/' . $q, $root));
        }
        if ($sum == 0.0) {
            foreach ($x->query('bnk:bankDetail/bnk:bankItem/bnk:homeCurrency/typ:unitPrice', $root) as $p) {
                $sum += ledger_float($p);
            }
        }
        $number = $txt('bnk:number', $h);
        $out['payments'][] = ['ext_id' => ($txt('bnk:id', $h) ?: $number), 'number' => mb_substr($number, 0, 40), 'vs' => ledger_vs($txt('bnk:symVar', $h)),
            'doc_date' => substr($txt('bnk:datePayment', $h) ?: $txt('bnk:dateStatement', $h), 0, 10), 'due_date' => '', 'text' => mb_substr($txt('bnk:text', $h), 0, 255),
            'amount' => round($sum, 2), 'paid' => round($sum, 2), 'items' => []];
    }
    if (!$out['receivables'] && !$out['payments']) {
        return $out['skipped'] ? 'V souboru jsou jen jiné doklady (např. vydané faktury nebo výdaje z banky). Exportujte agendu Ostatní pohledávky a příjmy z Banky.'
            : 'V souboru nejsou žádné doklady POHODY (ostatní pohledávky ani bankovní doklady).';
    }
    return $out;
}

/** Variabilní symbol → jednotka podle Platby na jednotku. */
function ledger_vs_index(): array
{
    $idx = [];
    foreach (all("SELECT unit_id, vs FROM unit_accounts WHERE vs <> ''") as $r) {
        $idx[ledger_vs((string) $r['vs'])] = (int) $r['unit_id'];
    }
    return $idx;
}
/** Náhled importu: počty, přiřazené jednotky a nepřiřazené VS. */
function ledger_preview(array $parsed): array
{
    $idx = ledger_vs_index();
    $units = [];
    $unmatched = [];
    foreach (['receivables', 'payments'] as $k) {
        foreach ($parsed[$k] as $d) {
            isset($idx[$d['vs']]) ? $units[$idx[$d['vs']]] = true : $unmatched[$d['vs'] === '' ? '(bez VS)' : $d['vs']] = true;
        }
    }
    return ['receivables' => count($parsed['receivables']), 'payments' => count($parsed['payments']), 'skipped' => $parsed['skipped'],
        'units' => count($units), 'unmatched' => array_map('strval', array_keys($unmatched)), 'vsKnown' => count($idx)];
}
/**
 * Uloží doklady (stejný doklad z dalšího exportu se přepíše – např. když mezitím přišla úhrada) a přepočte konto
 * přiřazených jednotek. Vrací ['saved' => n, 'units' => n, 'unmatched' => n].
 */
function ledger_import(array $parsed, string $source, string $file, int $userId): array
{
    ledger_ready();
    $idx = ledger_vs_index();
    $now = date('Y-m-d H:i:s');
    $saved = $unmatched = 0;
    $touched = [];
    foreach (['receivables' => 'receivable', 'payments' => 'payment'] as $k => $kind) {
        foreach ($parsed[$k] as $d) {
            $unitId = $idx[$d['vs']] ?? 0;
            $unitId ? $touched[$unitId] = true : $unmatched++;
            q('DELETE FROM ledger_docs WHERE source = ? AND kind = ? AND ext_id = ?', [$source, $kind, (string) $d['ext_id']]);
            q('INSERT INTO ledger_docs (unit_id, vs, kind, source, ext_id, number, doc_date, due_date, text, amount, paid, items, imported_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$unitId, $d['vs'], $kind, $source, (string) $d['ext_id'], $d['number'], $d['doc_date'], $d['due_date'], $d['text'], $d['amount'], $d['paid'],
                    json_encode($d['items'], JSON_UNESCAPED_UNICODE), $now]);
            $saved++;
        }
    }
    foreach (array_keys($touched) as $unitId) {
        $acc = ledger_account((int) $unitId);
        if ($acc['prescription']) {
            q('UPDATE unit_accounts SET prescribed = ?, balance = ?, as_of = ? WHERE unit_id = ?', [$acc['prescription']['amount'], -$acc['open'], date('Y-m-d'), $unitId]);
        }
    }
    q('INSERT INTO ledger_imports (at, source, file, receivables, payments, unmatched, user_id) VALUES (?,?,?,?,?,?,?)',
        [$now, $source, mb_substr($file, 0, 200), count($parsed['receivables']), count($parsed['payments']), $unmatched, $userId]);
    audit('ledger_import', (LEDGER_SOURCES[$source] ?? $source) . ' · dokladů ' . $saved . ', jednotek ' . count($touched) . ($unmatched ? ', bez přiřazení ' . $unmatched : ''));
    return ['saved' => $saved, 'units' => count($touched), 'unmatched' => $unmatched];
}

/**
 * Konto jednotky: ['prescription' => poslední pohledávka s položkami | null, 'receivables' => za posledních $months měsíců
 * i starší neuhrazené (nejnovější první, s 'open'), 'payments' => přijaté platby za $months měsíců, 'open' => zbývá uhradit
 * (jen splatné a po splatnosti i před splatností dohromady), 'overdue' => z toho po splatnosti, 'as_of' => datum importu].
 */
function ledger_account(int $unitId, int $months = 12, ?string $today = null): array
{
    ledger_ready();
    $today ??= date('Y-m-d');
    $since = date('Y-m-01', strtotime($today . " -$months months"));
    $rec = all("SELECT * FROM ledger_docs WHERE unit_id = ? AND kind = 'receivable' AND (doc_date >= ? OR paid + 0.005 < amount) ORDER BY doc_date DESC, id DESC", [$unitId, $since]);
    $open = $overdue = 0.0;
    foreach ($rec as &$r) {
        $r['open'] = max(0.0, round((float) $r['amount'] - (float) $r['paid'], 2));
        $r['items'] = (array) json_decode((string) $r['items'], true);
        $open += $r['open'];
        if ($r['open'] > 0 && $r['due_date'] !== '' && $r['due_date'] < $today) {
            $overdue += $r['open'];
        }
    }
    unset($r);
    $presc = null;
    foreach ($rec as $r) {
        if ($r['doc_date'] <= $today) {
            $presc = $r;
            break;
        }
    }
    return ['prescription' => $presc ?? ($rec[0] ?? null), 'receivables' => $rec,
        'payments' => all("SELECT * FROM ledger_docs WHERE unit_id = ? AND kind = 'payment' AND doc_date >= ? ORDER BY doc_date DESC, id DESC", [$unitId, $since]),
        'open' => round($open, 2), 'overdue' => round($overdue, 2),
        'as_of' => (string) (val('SELECT MAX(imported_at) FROM ledger_docs WHERE unit_id = ?', [$unitId]) ?? '')];
}

<?php
declare(strict_types=1);

/*
 * Modul D – propojení s účetnictvím: export do účetních programů a import z nich (s přiřazením sloupců).
 *
 * Export (správce domu: /admin/ucetnictvi, partner: /partner/ucetnictvi pro všechny domy najednou):
 *  - POHODA (Stormware) – XML datová komunikace, balík dat dat:dataPack / dat:dataPackItem (verze 2.0):
 *      adresář vlastníků (agenda Adresář, adb:addressbook), předpisy záloh jako „Ostatní pohledávky“
 *      (agenda Faktury, inv:invoice s inv:invoiceType = receivable) a příchozí platby (agenda Banka, bnk:bank).
 *      Proč ostatní pohledávky: SVJ vlastníkům zálohy na služby a do fondu oprav nefakturuje (nejde o zdanitelné plnění),
 *      ale potřebuje evidovat, kdo kolik dluží, a párovat úhrady z banky podle VS. To dělá agenda Ostatní pohledávky
 *      (saldo, likvidace, upomínky). Interní doklad (intDoc) jen zaúčtuje, saldo za vlastníka by nevzniklo.
 *      Dokumentace a schémata: https://www.stormware.cz/xml/ , https://www.stormware.cz/xml/schema/version_2/data.xsd,
 *      invoice.xsd (invoiceTypeType: „receivable = Ostatní pohledávka“), addressbook.xsd, bank.xsd, type.xsd.
 *      Kódování Windows-1250 jako vzorové soubory Stormware.
 *  - Money S3 (Seyfor/Solitea) – XML přenosy, kořen MoneyData se seznamy SeznamFirem/Firma, SeznamPohledavek/Pohledavka
 *      (ostatní pohledávky) a SeznamBankDokl/BankDokl. Dokumentace a XSD: https://money.cz/navod/s3xmlde/
 *      (schemas.zip – _Document.xsd, __Firma.xsd, __UcDokl.xsd).
 *  - ABRA Flexi – XML <winstrom version="1.0"> s evidencemi adresar, pohledavka, banka a identifikátory ext:.
 *      Dokumentace: https://podpora.flexibee.eu/en/articles/4722242-abra-flexi-xml (XML), identifikátory záznamů
 *      https://podpora.flexibee.eu/cs/articles/4725798-identifikatory-zaznamu . Stejný soubor jde poslat i přes REST API
 *      (PUT /c/<firma>/pohledavka.xml) – web ho ale jen nabízí ke stažení, k účetnictví se nepřipojuje.
 *  - CSV (UTF-8 s BOM, středník) – vlastníci a jednotky, předpisy, salda, platby z banky.
 *  NEOVĚŘENO importem do skutečné Pohody, Money ani ABRA Flexi (jen proti zveřejněným schématům a vzorům).
 *
 * Import (CSV, XLSX): odhad oddělovače a kódování (bank_utf8), odhad sloupců podle záhlaví (české i anglické názvy),
 * obrazovka přiřazení sloupců s náhledem a kontrolou, poslední přiřazení se pamatuje (nastavení domu acct_map_<cíl>).
 * Cíle: 'units' (jednotky, podíly, vlastníci dle evidence) a 'accounts' (předpis záloh a stav účtu jednotky – unit_accounts).
 *
 * Všechny funkce pracují s PDO zadaného domu, takže je používá i partnerský portál pro více domů.
 */

const ACCT_TARGETS = [
    'units' => ['Jednotky, podíly a vlastníci', ['unit' => 'Jednotka', 'entrance' => 'Vchod', 'share' => 'Podíl', 'owner' => 'Vlastník', 'contact' => 'Adresa / kontakt', 'floor' => 'Patro']],
    'accounts' => ['Předpisy záloh a stavy účtů', ['unit' => 'Jednotka', 'entrance' => 'Vchod', 'vs' => 'Variabilní symbol', 'prescribed' => 'Měsíční předpis (záloha)', 'balance' => 'Stav účtu (saldo)', 'note' => 'Poznámka']],
];
/** Názvy sloupců (bez diakritiky, malými písmeny) – pořadí polí je zároveň pořadí přednosti při odhadu. */
const ACCT_SYNONYMS = [
    'house' => ['dum', 'svj', 'spolecenstvi', 'nazev domu', 'nazev svj', 'objekt', 'ico svj', 'ico', 'house', 'building', 'hoa'],
    'vs' => ['vs', 'var symbol', 'var sym', 'variabilni symbol', 'variabilni', 'variable symbol'],
    'entrance' => ['vchod', 'cislo vchodu', 'blok', 'entrance'],
    'unit' => ['jednotka', 'cislo jednotky', 'c jednotky', 'oznaceni jednotky', 'ozn jednotky', 'jednotka c', 'byt', 'cislo bytu', 'c bytu', 'prostor', 'cislo prostoru', 'unit', 'flat', 'apartment', 'unit no'],
    'share' => ['podil', 'spoluvlastnicky podil', 'spoluvl podil', 'podil na spolecnych castech', 'velikost podilu', 'share', 'ownership share'],
    'prescribed' => ['predpis', 'mesicni predpis', 'predpis zaloh', 'predpis celkem', 'zaloha', 'zalohy', 'mesicni zaloha', 'zalohy celkem', 'mesicni platba', 'prescribed', 'advance', 'monthly advance', 'monthly payment'],
    'balance' => ['saldo', 'stav uctu', 'stav konta', 'stav', 'zustatek', 'nedoplatek preplatek', 'preplatek nedoplatek', 'konecny stav', 'saldo k datu', 'balance', 'account balance'],
    'owner' => ['vlastnik', 'vlastnici', 'jmeno vlastnika', 'vlastnik jmeno', 'majitel', 'prijmeni a jmeno', 'jmeno a prijmeni', 'jmeno', 'nazev', 'owner', 'owner name', 'name'],
    'contact' => ['adresa pro dorucovani', 'korespondencni adresa', 'adresa', 'kontakt', 'e mail', 'email', 'telefon', 'contact', 'address'],
    'floor' => ['patro', 'podlazi', 'floor'],
    'note' => ['poznamka', 'pozn', 'note'],
];
/**
 * Předvolby pro exporty běžných programů správy domů. ODHAD – nemáme vzorové exporty, názvy sloupců jsou podle
 * obvyklých pojmenování; před prvním použitím ověřit na skutečném souboru (obrazovka přiřazení ukáže, co se poznalo).
 */
const ACCT_PRESETS = [
    'domsys' => ['DOMSYS', ['unit' => ['cislo jednotky', 'jednotka'], 'owner' => ['vlastnik', 'nazev vlastnika'], 'share' => ['podil'], 'prescribed' => ['predpis celkem', 'predpis'], 'balance' => ['saldo'], 'vs' => ['vs', 'variabilni symbol']]],
    'ides' => ['IDES', ['unit' => ['jednotka', 'byt'], 'owner' => ['najemnik vlastnik', 'vlastnik'], 'share' => ['spoluvl podil', 'podil'], 'prescribed' => ['mesicni predpis', 'predpis'], 'balance' => ['stav konta', 'saldo'], 'vs' => ['var symbol', 'vs']]],
    'mecom' => ['MECOM', ['unit' => ['byt', 'cislo bytu'], 'owner' => ['jmeno', 'vlastnik'], 'share' => ['podil'], 'prescribed' => ['zaloha', 'predpis'], 'balance' => ['zustatek', 'saldo'], 'vs' => ['variabilni symbol', 'vs']]],
    'integri' => ['INTEGRI', ['unit' => ['ozn jednotky', 'jednotka'], 'owner' => ['vlastnik jmeno', 'vlastnik'], 'share' => ['podil citatel jmenovatel', 'podil'], 'prescribed' => ['predpis'], 'balance' => ['saldo k datu', 'saldo'], 'vs' => ['vs']]],
];
const ACCT_MAX_ROWS = 5000;

// ---------- Pomocné ----------

/** Text bez diakritiky, malými písmeny, jen písmena a číslice oddělené mezerou (pro porovnání názvů). */
function acct_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ĺ' => 'l', 'ľ' => 'l', 'ŕ' => 'r', 'ô' => 'o']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}
/** Částka z textu účetního exportu: „-1 200,50 Kč“, „1.200,50“, „(300)“, „300-“, „4.5“. Neplatné = null, prázdné = null. */
function acct_num(string $v): ?float
{
    $s = str_replace(["\u{a0}", "\u{202f}", ' ', "'", 'Kč', 'kč', 'CZK', 'czk', "\u{2212}"], ['', '', '', '', '', '', '', '', '-'], trim($v));
    if ($s === '') {
        return null;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        [$s, $neg] = [$m[1], true];
    }
    if (str_ends_with($s, '-')) {
        [$s, $neg] = [substr($s, 0, -1), true];
    }
    if (str_starts_with($s, '-')) {
        [$s, $neg] = [substr($s, 1), !$neg];
    }
    $s = ltrim($s, '+');
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    } elseif (str_contains($s, ',')) {
        $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) && substr_count($s, '.') > 1) {
        $s = str_replace('.', '', $s);   // 1.200.000 – tečky jako oddělovač tisíců
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
        return null;
    }
    return round(((float) $s) * ($neg ? -1 : 1), 2);
}
/** Podíl v podobě, které rozumí share_value(): „812/10000“, „8.12%“; desetinné číslo pod 1 převede na procenta. Neplatný = null. */
function acct_share(string $v): ?string
{
    $s = str_replace([' ', "\u{a0}"], '', trim($v));
    if ($s === '') {
        return null;
    }
    if (preg_match('~^(\d+(?:[.,]\d+)?)/(\d+(?:[.,]\d+)?)$~', $s, $m)) {
        $out = str_replace(',', '.', $m[1]) . '/' . str_replace(',', '.', $m[2]);
        return share_value($out) > 0 ? $out : null;
    }
    if (preg_match('/^(\d+(?:[.,]\d+)?)%$/', $s, $m)) {
        $out = str_replace(',', '.', $m[1]) . '%';
        return share_value($out) > 0 ? $out : null;
    }
    $n = preg_match('/^0[.,]\d+$/', $s) ? (float) str_replace(',', '.', $s) : null;   // bez zaokrouhlení (acct_num zaokrouhluje na haléře)
    if ($n !== null && $n > 0 && $n < 1) {
        return rtrim(rtrim(number_format($n * 100, 6, '.', ''), '0'), '.') . '%';
    }
    return null;
}
function acct_money(float $n): string
{
    return number_format($n, 2, ',', '');
}
/** Nastavení domu z jeho databáze. */
function acct_settings(PDO $pdo): array
{
    return array_column($pdo->query('SELECT `key`, value FROM settings')->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
}
function acct_setting_save(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)')->execute([$key, $value]);
}
function acct_rows(PDO $pdo, string $sql, array $p = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** Označení jednotky v domě s předponou (např. „12A/3“). */
function acct_unit_label(array $un, string $prefix): string
{
    return $prefix . $un['entrance'] . '/' . $un['number'];
}

// ---------- Údaje domu pro export ----------

/** Údaje o domě pro export: aktuální web (správce). */
function acct_house_current(): array
{
    return ['name' => site('full') !== '' ? site('full') : site('name'), 'short' => site('name'), 'ico' => preg_replace('/\D/', '', setting('ico')), 'address' => setting('address'),
        'account' => setting('account'), 'prefix' => unit_prefix(), 'slug' => defined('TENANT') ? (string) TENANT : '', 'entrances' => entrances(), 'opts' => acct_options(acct_settings(db()))];
}
/** Údaje o domě pro export: dům z databáze platformy (partnerský portál). */
function acct_house_tenant(array $t): array
{
    $s = tenant_settings($t['slug']);
    $prefix = mb_substr(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string) ($s['site_unit_prefix'] ?? $t['unit_prefix'] ?? '')), 0, 10);
    $full = (string) ($s['site_full'] ?? $t['full'] ?? '');
    $entr = array_values(array_filter(array_map(fn($x) => mb_strtoupper(trim($x)), explode(',', (string) ($s['site_entrances'] ?? $t['entrances'] ?? 'A')))));
    return ['name' => $full !== '' ? $full : (string) ($s['site_name'] ?? $t['name']), 'short' => (string) ($s['site_name'] ?? $t['name']), 'ico' => preg_replace('/\D/', '', (string) ($s['ico'] ?? '')),
        'address' => (string) ($s['address'] ?? $t['address'] ?? ''), 'account' => (string) ($s['account'] ?? ''), 'prefix' => $prefix, 'slug' => $t['slug'], 'entrances' => $entr ?: [''], 'opts' => acct_options($s)];
}
/** Nastavení exportu (kódy v účetním programu), s výchozími hodnotami. */
const ACCT_OPTION_DEFAULTS = ['acct_bank_code' => '', 'acct_due_day' => '20', 'acct_flexi_receivable' => 'OST. POHLEDÁVKY', 'acct_flexi_bank_type' => 'STANDARD'];
function acct_options(array $settings): array
{
    $o = [];
    foreach (ACCT_OPTION_DEFAULTS as $k => $def) {
        $o[$k] = trim((string) ($settings[$k] ?? '')) !== '' ? trim((string) $settings[$k]) : $def;
    }
    $o['acct_due_day'] = (string) max(1, min(28, (int) $o['acct_due_day']));
    return $o;
}
/** Jednotky s vlastníky a účty: [['id','label','entrance','number','share','owner','contact','emails','phones','prescribed','balance','vs','as_of','note','bank'], …]. */
function acct_units(PDO $pdo, string $prefix): array
{
    $hasAcc = acct_table_exists($pdo, 'unit_accounts');
    $units = acct_rows($pdo, 'SELECT un.*' . ($hasAcc ? ', a.prescribed, a.balance, a.vs, a.as_of, a.note AS acc_note' : '') . ' FROM units un'
        . ($hasAcc ? ' LEFT JOIN unit_accounts a ON a.unit_id = un.id' : '') . ' ORDER BY un.entrance, un.number + 0, un.number');
    $people = [];
    foreach (acct_rows($pdo, "SELECT uu.unit_id, u.name, u.email, u.phone FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.active = 1 AND u.kind = 'owner' ORDER BY u.name") as $p) {
        $people[(int) $p['unit_id']][] = $p;
    }
    $bank = acct_bank_paid_since($pdo);
    $out = [];
    foreach ($units as $un) {
        $id = (int) $un['id'];
        $ppl = $people[$id] ?? [];
        $owner = trim((string) $un['owner_note']) !== '' ? trim((string) $un['owner_note']) : implode(', ', array_column($ppl, 'name'));
        $contact = trim((string) $un['owner_contact']);
        $emails = array_values(array_unique(array_filter(array_merge(array_column($ppl, 'email'), filter_var($contact, FILTER_VALIDATE_EMAIL) ? [$contact] : []))));
        $out[] = ['id' => $id, 'label' => acct_unit_label($un, $prefix), 'entrance' => (string) $un['entrance'], 'number' => (string) $un['number'], 'floor' => (string) $un['floor'],
            'share' => (string) $un['share'], 'owner' => $owner, 'contact' => $contact, 'emails' => $emails, 'phones' => array_values(array_filter(array_column($ppl, 'phone'))),
            'accounts' => array_column($ppl, 'name'), 'has_account' => isset($un['as_of']) && $un['as_of'] !== null,
            'prescribed' => (float) ($un['prescribed'] ?? 0), 'balance' => (float) ($un['balance'] ?? 0), 'vs' => (string) ($un['vs'] ?? ''), 'as_of' => (string) ($un['as_of'] ?? ''),
            'note' => (string) ($un['acc_note'] ?? ''), 'bank' => $bank[$id] ?? 0.0];
    }
    return $out;
}
function acct_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
/** Platby z banky po dni stavu z účetnictví (jako bank_paid_since(), ale pro libovolný dům). */
function acct_bank_paid_since(PDO $pdo): array
{
    if (!acct_table_exists($pdo, 'bank_tx') || !acct_table_exists($pdo, 'unit_accounts')) {
        return [];
    }
    $out = [];
    foreach (acct_rows($pdo, 'SELECT a.unit_id, SUM(t.amount) AS paid FROM unit_accounts a JOIN bank_tx t ON t.unit_id = a.unit_id AND t.tx_date > a.as_of GROUP BY a.unit_id') as $r) {
        $out[(int) $r['unit_id']] = round((float) $r['paid'], 2);
    }
    return $out;
}
/** Pohyby z banky za období (včetně), s označením jednotky. */
function acct_bank(PDO $pdo, string $prefix, string $from, string $to): array
{
    if (!acct_table_exists($pdo, 'bank_tx')) {
        return [];
    }
    $rows = acct_rows($pdo, 'SELECT t.*, un.entrance, un.number FROM bank_tx t LEFT JOIN units un ON un.id = t.unit_id WHERE t.tx_date >= ? AND t.tx_date <= ? ORDER BY t.tx_date, t.id', [$from, $to]);
    foreach ($rows as &$r) {
        $r['unit_label'] = $r['number'] !== null ? acct_unit_label($r, $prefix) : '';
        $r['amount'] = (float) $r['amount'];
    }
    return $rows;
}
/** Předpisy: jeden řádek za jednotku s předpisem a měsíc. $from = 'YYYY-MM'. */
function acct_prescriptions(array $units, string $from, int $months, int $dueDay): array
{
    $out = [];
    $start = DateTimeImmutable::createFromFormat('!Y-m', $from) ?: new DateTimeImmutable('first day of this month');
    for ($i = 0; $i < max(1, min(12, $months)); $i++) {
        $m = $start->modify("+$i month");
        foreach ($units as $u) {
            if ($u['prescribed'] <= 0) {
                continue;
            }
            $out[] = ['unit' => $u, 'period' => $m->format('Y-m'), 'date' => $m->format('Y-m-01'), 'due' => $m->format('Y-m-') . sprintf('%02d', $dueDay),
                'amount' => $u['prescribed'], 'vs' => $u['vs'], 'text' => 'Předpis záloh ' . $m->format('n/Y') . ' – jednotka ' . $u['label']];
        }
    }
    return $out;
}
/** Adresa pro doručování z volného textu: „Ulice 1, 602 00 Město“ → [ulice, PSČ, město]; jinak [text, '', '']. */
function acct_split_address(string $s): array
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '' || filter_var($s, FILTER_VALIDATE_EMAIL)) {
        return ['', '', ''];
    }
    if (preg_match('/^(.+?),\s*(\d{3}\s?\d{2})\s+(.+)$/u', $s, $m)) {
        return [trim($m[1]), str_replace(' ', '', $m[2]), trim($m[3])];
    }
    return [$s, '', ''];
}
/** Klíč jednotky pro identifikátory v účetnictví (ASCII, bez lomítek): „12A-3“. */
function acct_unit_key(array $u): string
{
    $k = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', $u['label']) ?: '');
    return trim(preg_replace('/[^A-Z0-9]+/', '-', $k), '-') ?: 'J' . $u['id'];
}

// ---------- Export: POHODA ----------

const ACCT_POHODA_NS = ['dat' => 'data', 'inv' => 'invoice', 'typ' => 'type', 'adb' => 'addressbook', 'bnk' => 'bank'];

/**
 * POHODA XML (dataPack v2.0, Windows-1250). $parts: 'addressbook', 'receivable', 'bank'.
 * Vrací XML text. Dokumentace viz začátek souboru.
 */
function acct_pohoda_xml(array $house, array $units, array $prescriptions, array $bank, array $parts): string
{
    $d = new DOMDocument('1.0', 'Windows-1250');
    $d->formatOutput = true;
    $ns = fn(string $p) => 'http://www.stormware.cz/schema/version_2/' . ACCT_POHODA_NS[$p] . '.xsd';
    $root = $d->createElementNS($ns('dat'), 'dat:dataPack');
    foreach (['inv', 'typ', 'adb', 'bnk'] as $p) {
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$p", $ns($p));
    }
    $root->setAttribute('version', '2.0');
    $root->setAttribute('id', 'svj-' . date('YmdHis'));
    $root->setAttribute('ico', $house['ico']);
    $root->setAttribute('application', 'Web SVJ');
    $root->setAttribute('note', mb_substr('Export z webu ' . $house['short'], 0, 255));
    $d->appendChild($root);
    $el = function (DOMElement $parent, string $name, ?string $text = null) use ($d, $ns): DOMElement {
        $p = explode(':', $name)[0];
        $node = $d->createElementNS($ns($p), $name);
        if ($text !== null) {
            $node->appendChild($d->createTextNode($text));
        }
        $parent->appendChild($node);
        return $node;
    };
    $item = function (string $id) use ($el, $root): DOMElement {
        $i = $el($root, 'dat:dataPackItem');
        $i->setAttribute('id', $id);
        $i->setAttribute('version', '2.0');
        return $i;
    };
    $address = function (DOMElement $parent, array $u) use ($el): void {
        $a = $el($parent, 'typ:address');
        [$street, $zip, $city] = acct_split_address($u['contact']);
        // typ:company má 255 znaků (typ:name jen 32) – vlastníci bývají dva i víc („Novák Petr a Nováková Marie (SJM)“).
        $el($a, 'typ:company', mb_substr($u['owner'] !== '' ? $u['owner'] : 'Vlastník jednotky ' . $u['label'], 0, 255));
        $el($a, 'typ:division', mb_substr('Jednotka ' . $u['label'], 0, 32));
        if ($city !== '') {
            $el($a, 'typ:city', mb_substr($city, 0, 45));
        }
        if ($street !== '') {
            $el($a, 'typ:street', mb_substr($street, 0, 64));
        }
        if ($zip !== '') {
            $el($a, 'typ:zip', mb_substr($zip, 0, 15));
        }
    };
    if (in_array('addressbook', $parts, true)) {
        foreach ($units as $u) {
            $h = $el($el($item('ADR-' . acct_unit_key($u)), 'adb:addressbook'), 'adb:addressbookHeader');
            $h->parentNode->setAttribute('version', '2.0');
            $address($el($h, 'adb:identity'), $u);
            if ($u['phones']) {
                $el($h, 'adb:phone', mb_substr($u['phones'][0], 0, 40));
            }
            if ($u['emails']) {
                $el($h, 'adb:email', mb_substr($u['emails'][0], 0, 98));
            }
            $el($h, 'adb:note', 'Jednotka ' . $u['label'] . ($u['share'] !== '' ? ', podíl ' . $u['share'] : '') . ($u['vs'] !== '' ? ', VS ' . $u['vs'] : ''));
        }
    }
    if (in_array('receivable', $parts, true)) {
        foreach ($prescriptions as $p) {
            $inv = $el($item('POH-' . str_replace('-', '', $p['period']) . '-' . acct_unit_key($p['unit'])), 'inv:invoice');
            $inv->setAttribute('version', '2.0');
            $h = $el($inv, 'inv:invoiceHeader');
            $el($h, 'inv:invoiceType', 'receivable');
            if ($p['vs'] !== '') {
                $el($h, 'inv:symVar', mb_substr(preg_replace('/\D/', '', $p['vs']), 0, 20));
            }
            $el($h, 'inv:date', $p['date']);
            $el($h, 'inv:dateAccounting', $p['date']);
            $el($h, 'inv:dateDue', $p['due']);
            $el($el($h, 'inv:classificationVAT'), 'typ:classificationVATType', 'nonSubsume');
            $el($h, 'inv:text', mb_substr($p['text'], 0, 240));
            $address($el($h, 'inv:partnerIdentity'), $p['unit']);
            $el($el($h, 'inv:paymentType'), 'typ:paymentType', 'draft');
            $el($h, 'inv:note', 'Export z webu SVJ, jednotka ' . $p['unit']['label']);
            $s = $el($inv, 'inv:invoiceSummary');
            $el($s, 'inv:roundingDocument', 'none');
            $el($el($s, 'inv:homeCurrency'), 'typ:priceNone', number_format($p['amount'], 2, '.', ''));
        }
    }
    if (in_array('bank', $parts, true)) {
        foreach ($bank as $t) {
            $b = $el($item('BAN-' . (int) $t['id']), 'bnk:bank');
            $b->setAttribute('version', '2.0');
            $h = $el($b, 'bnk:bankHeader');
            $el($h, 'bnk:bankType', $t['amount'] >= 0 ? 'receipt' : 'expense');
            if ($house['opts']['acct_bank_code'] !== '') {
                $el($el($h, 'bnk:account'), 'typ:ids', mb_substr($house['opts']['acct_bank_code'], 0, 19));
            }
            if ($t['vs'] !== '') {
                $el($h, 'bnk:symVar', mb_substr($t['vs'], 0, 20));
            }
            $el($h, 'bnk:dateStatement', $t['tx_date']);
            $el($h, 'bnk:datePayment', $t['tx_date']);
            $el($h, 'bnk:text', mb_substr(($t['unit_label'] !== '' ? 'Platba jednotky ' . $t['unit_label'] : 'Platba') . ($t['message'] !== '' ? ' – ' . $t['message'] : ''), 0, 96));
            if ($t['counter_name'] !== '') {
                $el($el($el($h, 'bnk:partnerIdentity'), 'typ:address'), 'typ:name', mb_substr($t['counter_name'], 0, 32));
            }
            if (preg_match('~^([\d-]{1,34})/(\d{4})$~', (string) $t['counter_account'], $m)) {
                $pa = $el($h, 'bnk:paymentAccount');
                $el($pa, 'typ:accountNo', $m[1]);
                $el($pa, 'typ:bankCode', $m[2]);
            }
            $el($h, 'bnk:note', 'Export z webu SVJ, ' . (string) $t['tx_id']);
            $s = $el($b, 'bnk:bankSummary');
            $el($s, 'bnk:roundingDocument', 'none');
            $el($el($s, 'bnk:homeCurrency'), 'typ:priceNone', number_format(abs($t['amount']), 2, '.', ''));
        }
    }
    return (string) $d->saveXML();
}

// ---------- Export: Money S3 ----------

/** Money S3 XML (MoneyData, UTF-8). $parts: 'addressbook', 'receivable', 'bank'. Pořadí prvků podle XSD (xs:sequence). */
function acct_money_xml(array $house, array $units, array $prescriptions, array $bank, array $parts): string
{
    $d = new DOMDocument('1.0', 'UTF-8');
    $d->formatOutput = true;
    $root = $d->createElement('MoneyData');
    $root->setAttribute('ICAgendy', $house['ico']);
    $root->setAttribute('description', mb_substr('Export z webu ' . $house['short'], 0, 100));
    $root->setAttribute('ExpZkratka', '_SVJ');
    $root->setAttribute('ExpDate', date('Y-m-d'));
    $root->setAttribute('ExpTime', date('H:i:s'));
    $root->setAttribute('VyberZaznamu', '0');
    $d->appendChild($root);
    $el = function (DOMElement $parent, string $name, ?string $text = null) use ($d): DOMElement {
        $n = $d->createElement($name);
        if ($text !== null) {
            $n->appendChild($d->createTextNode($text));
        }
        $parent->appendChild($n);
        return $n;
    };
    $adresa = function (DOMElement $parent, string $tag, array $u) use ($el): void {
        [$street, $zip, $city] = acct_split_address($u['contact']);
        $a = $el($parent, $tag);
        $el($a, 'Ulice', mb_substr($street, 0, 64));
        $el($a, 'Misto', mb_substr($city, 0, 45));
        $el($a, 'PSC', mb_substr($zip, 0, 15));
        $el($a, 'KodStatu', 'CZ');
    };
    $name = fn(array $u) => mb_substr($u['owner'] !== '' ? $u['owner'] : 'Vlastník jednotky ' . $u['label'], 0, 96);
    $firma = function (DOMElement $parent, array $u) use ($el, $adresa, $name): void {   // dokladFirmaType
        $el($parent, 'ObchNazev', $name($u));
        $adresa($parent, 'ObchAdresa', $u);
        $el($parent, 'Nazev', $name($u));
        if ($u['emails']) {
            $el($parent, 'EMail', mb_substr($u['emails'][0], 0, 98));
        }
        $el($parent, 'KodPartn', mb_substr(acct_unit_key($u), 0, 10));
    };
    if (in_array('addressbook', $parts, true)) {
        $list = $el($root, 'SeznamFirem');
        foreach ($units as $u) {
            $f = $el($list, 'Firma');
            $el($f, 'Nazev', $name($u));
            $adresa($f, 'Adresa', $u);
            if ($u['phones']) {
                $el($el($f, 'Tel'), 'Cislo', mb_substr($u['phones'][0], 0, 40));
            }
            if ($u['emails']) {
                $el($f, 'EMail', mb_substr($u['emails'][0], 0, 98));
            }
            $el($f, 'Poznamka', 'Jednotka ' . $u['label'] . ($u['share'] !== '' ? ', podíl ' . $u['share'] : '') . ($u['vs'] !== '' ? ', VS ' . $u['vs'] : ''));
            $el($f, 'KodPartn', mb_substr(acct_unit_key($u), 0, 10));
        }
    }
    if (in_array('receivable', $parts, true) && $prescriptions) {
        $list = $el($root, 'SeznamPohledavek');
        foreach ($prescriptions as $p) {
            $x = $el($list, 'Pohledavka');
            $el($x, 'Popis', mb_substr($p['text'], 0, 50));
            if ($p['vs'] !== '') {
                $el($x, 'VarSym', mb_substr(preg_replace('/\D/', '', $p['vs']), 0, 10));
            }
            $el($x, 'DatUcPr', $p['date']);
            $el($x, 'DatVyst', $p['date']);
            $el($x, 'DatSpl', $p['due']);
            $firma($el($x, 'Adresa'), $p['unit']);
            $el($x, 'Pozn', 'Export z webu SVJ, jednotka ' . $p['unit']['label']);
            $el($el($x, 'SouhrnDPH'), 'Zaklad0', number_format($p['amount'], 2, '.', ''));
            $el($x, 'Celkem', number_format($p['amount'], 2, '.', ''));
        }
    }
    if (in_array('bank', $parts, true) && $bank) {
        $list = $el($root, 'SeznamBankDokl');
        foreach ($bank as $t) {
            $x = $el($list, 'BankDokl');
            $el($x, 'Vydej', $t['amount'] < 0 ? '1' : '0');
            $el($x, 'Popis', mb_substr($t['unit_label'] !== '' ? 'Platba jednotky ' . $t['unit_label'] : 'Platba', 0, 50));
            $el($x, 'DatUcPr', $t['tx_date']);
            $el($x, 'DatVyst', $t['tx_date']);
            $el($x, 'DatPlat', $t['tx_date']);
            if (preg_match('~^([\d-]{1,34})/(\d{4})$~', (string) $t['counter_account'], $m)) {
                $el($x, 'AdUcet', $m[1]);
                $el($x, 'AdKod', $m[2]);
            }
            if ($t['vs'] !== '') {
                $el($x, 'VarSym', mb_substr($t['vs'], 0, 10));
            }
            if ($t['counter_name'] !== '') {
                $el($el($x, 'Adresa'), 'ObchNazev', mb_substr($t['counter_name'], 0, 96));
            }
            $el($x, 'SouhrnDPH');
            $x->lastChild->appendChild($d->createElement('Zaklad0', number_format(abs($t['amount']), 2, '.', '')));
            $el($x, 'Celkem', number_format(abs($t['amount']), 2, '.', ''));
            $el($x, 'Pozn', mb_substr(trim($t['message'] . ' ' . $t['tx_id']), 0, 250));
            if ($house['opts']['acct_bank_code'] !== '') {
                $el($el($x, 'Ucet'), 'Zkrat', mb_substr($house['opts']['acct_bank_code'], 0, 20));
            }
        }
    }
    return (string) $d->saveXML();
}

// ---------- Export: ABRA Flexi ----------

/** ABRA Flexi XML (winstrom 1.0, UTF-8). Identifikátory ext:SVJ-<dům>:… – opakovaný import záznam aktualizuje. */
function acct_flexi_xml(array $house, array $units, array $prescriptions, array $bank, array $parts): string
{
    $d = new DOMDocument('1.0', 'UTF-8');
    $d->formatOutput = true;
    $root = $d->createElement('winstrom');
    $root->setAttribute('version', '1.0');
    $d->appendChild($root);
    $el = function (DOMElement $parent, string $name, string $text) use ($d): DOMElement {
        $n = $d->createElement($name);
        $n->appendChild($d->createTextNode($text));
        $parent->appendChild($n);
        return $n;
    };
    $sys = 'SVJ-' . strtoupper(preg_replace('/[^a-z0-9]+/i', '', $house['slug'] !== '' ? $house['slug'] : 'web'));
    $firmId = fn(array $u) => 'ext:' . $sys . ':ADR-' . acct_unit_key($u);
    if (in_array('addressbook', $parts, true)) {
        foreach ($units as $u) {
            $a = $d->createElement('adresar');
            $root->appendChild($a);
            [$street, $zip, $city] = acct_split_address($u['contact']);
            $el($a, 'id', $firmId($u));
            $el($a, 'kod', mb_substr('J' . acct_unit_key($u), 0, 20));
            $el($a, 'nazev', mb_substr($u['owner'] !== '' ? $u['owner'] : 'Vlastník jednotky ' . $u['label'], 0, 255));
            $el($a, 'ulice', mb_substr($street, 0, 255));
            $el($a, 'mesto', mb_substr($city, 0, 255));
            $el($a, 'psc', mb_substr($zip, 0, 255));
            if ($u['emails']) {
                $el($a, 'email', $u['emails'][0]);
            }
            if ($u['phones']) {
                $el($a, 'tel', $u['phones'][0]);
            }
            $el($a, 'poznam', 'Jednotka ' . $u['label'] . ($u['share'] !== '' ? ', podíl ' . $u['share'] : '') . ($u['vs'] !== '' ? ', VS ' . $u['vs'] : ''));
        }
    }
    if (in_array('receivable', $parts, true)) {
        foreach ($prescriptions as $p) {
            $x = $d->createElement('pohledavka');
            $root->appendChild($x);
            $el($x, 'id', 'ext:' . $sys . ':POH-' . str_replace('-', '', $p['period']) . '-' . acct_unit_key($p['unit']));
            $el($x, 'typDokl', 'code:' . $house['opts']['acct_flexi_receivable']);
            if (in_array('addressbook', $parts, true)) {
                $el($x, 'firma', $firmId($p['unit']));
            } else {
                $el($x, 'nazFirmy', $p['unit']['owner'] !== '' ? $p['unit']['owner'] : 'Vlastník jednotky ' . $p['unit']['label']);
            }
            if ($p['vs'] !== '') {
                $el($x, 'varSym', preg_replace('/\D/', '', $p['vs']));
            }
            $el($x, 'datVyst', $p['date']);
            $el($x, 'datSplat', $p['due']);
            $el($x, 'popis', $p['text']);
            $el($x, 'bezPolozek', 'true');
            $el($x, 'sumOsv', number_format($p['amount'], 2, '.', ''));
        }
    }
    if (in_array('bank', $parts, true)) {
        foreach ($bank as $t) {
            $x = $d->createElement('banka');
            $root->appendChild($x);
            $el($x, 'id', 'ext:' . $sys . ':BAN-' . (int) $t['id']);
            $el($x, 'typDokl', 'code:' . $house['opts']['acct_flexi_bank_type']);
            if ($house['opts']['acct_bank_code'] !== '') {
                $el($x, 'banka', 'code:' . $house['opts']['acct_bank_code']);
            }
            $el($x, 'typPohybuK', $t['amount'] >= 0 ? 'typPohybu.prijem' : 'typPohybu.vydej');
            $el($x, 'datVyst', $t['tx_date']);
            if ($t['vs'] !== '') {
                $el($x, 'varSym', $t['vs']);
            }
            if (preg_match('~^([\d-]{1,34})/(\d{4})$~', (string) $t['counter_account'], $m)) {
                $el($x, 'buc', $m[1]);
                $el($x, 'smerKod', 'code:' . $m[2]);
            }
            if ($t['counter_name'] !== '') {
                $el($x, 'nazFirmy', $t['counter_name']);
            }
            $el($x, 'popis', mb_substr(($t['unit_label'] !== '' ? 'Platba jednotky ' . $t['unit_label'] : 'Platba') . ($t['message'] !== '' ? ' – ' . $t['message'] : ''), 0, 255));
            $el($x, 'bezPolozek', 'true');
            $el($x, 'sumOsv', number_format(abs($t['amount']), 2, '.', ''));
        }
    }
    return (string) $d->saveXML();
}

// ---------- Export: CSV ----------

const ACCT_CSV_KINDS = ['vlastnici' => 'Vlastníci a jednotky', 'predpisy' => 'Předpisy záloh', 'salda' => 'Stavy účtů jednotek', 'banka' => 'Platby z banky'];

/** Bezpečná buňka CSV: text začínající =, +, @ nebo - (ne číslo) dostane apostrof, aby ho tabulka nespustila jako vzorec. */
function acct_csv_cell(mixed $v): string
{
    $s = (string) $v;
    if (preg_match('/^[=+@\t\r]/', $s) || preg_match('/^-(?![\d,.]+$)/', $s)) {
        $s = "'" . $s;
    }
    return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}
function acct_csv(array $header, array $rows): string
{
    $out = "\xEF\xBB\xBF" . implode(';', array_map('acct_csv_cell', $header)) . "\r\n";
    foreach ($rows as $r) {
        $out .= implode(';', array_map('acct_csv_cell', $r)) . "\r\n";
    }
    return $out;
}
function acct_csv_export(string $kind, array $units, array $prescriptions, array $bank): string
{
    return match ($kind) {
        'vlastnici' => acct_csv(['Jednotka', 'Vchod', 'Číslo', 'Patro', 'Podíl', 'Vlastník dle evidence', 'Adresa pro doručování', 'Účty vlastníků na webu', 'E-maily', 'Telefony', 'VS'],
            array_map(fn($u) => [$u['label'], $u['entrance'], $u['number'], $u['floor'], $u['share'], $u['owner'], $u['contact'], implode(', ', $u['accounts']), implode(', ', $u['emails']), implode(', ', $u['phones']), $u['vs']], $units)),
        'predpisy' => acct_csv(['Jednotka', 'Období', 'VS', 'Předpis (Kč)', 'Datum', 'Splatnost', 'Vlastník', 'Text'],
            array_map(fn($p) => [$p['unit']['label'], $p['period'], $p['vs'], acct_money($p['amount']), $p['date'], $p['due'], $p['unit']['owner'], $p['text']], $prescriptions)),
        'salda' => acct_csv(['Jednotka', 'VS', 'Měsíční záloha', 'Stav z účetnictví', 'Ke dni', 'Platby z banky po tomto dni', 'Aktuální stav', 'Poznámka'],
            array_map(fn($u) => [$u['label'], $u['vs'], acct_money($u['prescribed']), acct_money($u['balance']), $u['as_of'], acct_money($u['bank']), acct_money($u['balance'] + $u['bank']), $u['note']],
                array_values(array_filter($units, fn($u) => $u['has_account'])))),
        'banka' => acct_csv(['Datum', 'Částka', 'VS', 'Protiúčet', 'Protistrana', 'Zpráva', 'Jednotka', 'Zdroj', 'ID pohybu'],
            array_map(fn($t) => [$t['tx_date'], acct_money($t['amount']), $t['vs'], $t['counter_account'], $t['counter_name'], $t['message'], $t['unit_label'], $t['source'], $t['tx_id']], $bank)),
        default => '',
    };
}

// ---------- Export: sestavení souborů ----------

const ACCT_FORMATS = ['pohoda' => 'POHODA (XML)', 'money' => 'Money S3 (XML)', 'flexi' => 'ABRA Flexi (XML)', 'csv' => 'CSV (Excel)'];
const ACCT_PARTS = ['addressbook' => 'Adresář vlastníků', 'receivable' => 'Předpisy záloh (ostatní pohledávky)', 'bank' => 'Platby z banky'];

/** Parametry exportu z formuláře (GET), s výchozími hodnotami a kontrolou. */
function acct_export_params(array $in): array
{
    $from = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($in['od'] ?? '')) ? $in['od'] : date('Y-m');
    $bankFrom = valid_date((string) ($in['banka_od'] ?? '')) ? $in['banka_od'] : date('Y-m-01', strtotime('first day of last month'));
    $bankTo = valid_date((string) ($in['banka_do'] ?? '')) ? $in['banka_do'] : date('Y-m-d');
    $parts = array_values(array_intersect(array_keys(ACCT_PARTS), (array) ($in['casti'] ?? array_keys(ACCT_PARTS))));
    return ['format' => isset(ACCT_FORMATS[$in['format'] ?? '']) ? $in['format'] : 'pohoda', 'from' => $from, 'months' => max(1, min(12, (int) ($in['mesicu'] ?? 1))),
        'bank_from' => min($bankFrom, $bankTo), 'bank_to' => max($bankFrom, $bankTo), 'parts' => $parts ?: array_keys(ACCT_PARTS)];
}
/**
 * Soubory exportu jednoho domu: [název souboru => obsah]. Formát XML = jeden soubor se všemi částmi,
 * CSV = soubor za každou část (salda jsou jen v CSV – stav účtu je výsledek účetnictví, do účetnictví se nevrací).
 */
function acct_export_files(PDO $pdo, array $house, array $params): array
{
    $units = acct_units($pdo, $house['prefix']);
    $presc = in_array('receivable', $params['parts'], true) ? acct_prescriptions($units, $params['from'], $params['months'], (int) $house['opts']['acct_due_day']) : [];
    $bank = in_array('bank', $params['parts'], true) ? acct_bank($pdo, $house['prefix'], $params['bank_from'], $params['bank_to']) : [];
    $base = acct_filename_base($house);
    return match ($params['format']) {
        'pohoda' => [$base . '-pohoda.xml' => acct_pohoda_xml($house, $units, $presc, $bank, $params['parts'])],
        'money' => [$base . '-money-s3.xml' => acct_money_xml($house, $units, $presc, $bank, $params['parts'])],
        'flexi' => [$base . '-abra-flexi.xml' => acct_flexi_xml($house, $units, $presc, $bank, $params['parts'])],
        default => array_filter([
            $base . '-vlastnici.csv' => in_array('addressbook', $params['parts'], true) ? acct_csv_export('vlastnici', $units, [], []) : '',
            $base . '-predpisy.csv' => in_array('receivable', $params['parts'], true) ? acct_csv_export('predpisy', $units, $presc, []) : '',
            $base . '-salda.csv' => acct_csv_export('salda', $units, [], []),
            $base . '-banka.csv' => in_array('bank', $params['parts'], true) ? acct_csv_export('banka', $units, [], $bank) : '',
        ]),
    };
}
function acct_filename_base(array $house): string
{
    $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $house['slug'] !== '' ? $house['slug'] : $house['short']) ?: 'svj');
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'svj';
}
/** ZIP ze souborů [cesta => obsah]; vrací obsah archivu. */
function acct_zip(array $files): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'acz');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    if (!$files) {
        $zip->addFromString('README.txt', "Žádná data k exportu.\r\n");
    }
    $zip->close();
    $out = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $out;
}
/** Export všech domů partnera do jednoho ZIP (složka za každý dům). Vrací [obsah ZIP, [dům => chyba]]. */
function acct_partner_zip(array $houses, array $params): array
{
    $files = [];
    $failed = [];
    foreach ($houses as $t) {
        try {
            $house = acct_house_tenant($t);
            foreach (acct_export_files(tenant_pdo($t['slug']), $house, $params) as $name => $body) {
                $files[acct_filename_base($house) . '/' . $name] = $body;
            }
        } catch (Throwable $e) {
            $failed[$t['name']] = 'export se nepodařil';
            error_log('SVJ účetnictví, export domu ' . $t['slug'] . ': ' . $e->getMessage());
        }
    }
    return [acct_zip($files), $failed];
}
function acct_send(string $body, string $filename, string $type): never
{
    header('Content-Type: ' . $type);
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

// ---------- Import: čtení souboru ----------

/** Oddělovač CSV podle prvních řádků: nejčastější ze ; , tab | se stejným počtem na řádek. */
function acct_detect_delimiter(string $text): string
{
    $lines = array_slice(array_values(array_filter(preg_split('/\R/', $text), fn($l) => trim($l) !== '')), 0, 20);
    $best = ';';
    $bestScore = -1;
    foreach ([';', "\t", ',', '|'] as $d) {
        $counts = array_map(fn($l) => count(str_getcsv($l, $d, '"', '')) - 1, $lines);
        if (!$counts || max($counts) === 0) {
            continue;
        }
        $freq = array_count_values($counts);
        arsort($freq);
        $mode = (int) array_key_first($freq);
        $score = $mode > 0 ? $freq[$mode] * 100 + $mode : 0;
        if ($score > $bestScore) {
            [$best, $bestScore] = [$d, $score];
        }
    }
    return $best;
}
/**
 * Přečte tabulku z CSV nebo XLSX. Vrací ['headers' => [...], 'rows' => [[...], …], 'delimiter', 'encoding', 'error' => ''].
 * Záhlaví = první řádek, ve kterém se poznají aspoň dva sloupce (jinak první neprázdný řádek).
 */
function acct_read_table(string $data, string $filename = ''): array
{
    $res = ['headers' => [], 'rows' => [], 'delimiter' => '', 'encoding' => '', 'error' => ''];
    if (str_starts_with($data, "PK\x03\x04")) {
        $grid = acct_read_xlsx($data);
        if ($grid === null) {
            return ['error' => 'Soubor XLSX se nepodařilo přečíst. Uložte ho z Excelu jako CSV (UTF-8, oddělený středníkem).'] + $res;
        }
        $res['encoding'] = 'XLSX';
    } else {
        if (preg_match('/\.(xls|ods|pdf|docx?)$/i', $filename) || str_starts_with($data, "\xD0\xCF\x11\xE0")) {
            return ['error' => 'Tento formát neumíme přečíst. Nahrajte CSV nebo XLSX.'] + $res;
        }
        $bom = str_starts_with($data, "\xEF\xBB\xBF");
        $data = $bom ? substr($data, 3) : $data;
        $res['encoding'] = $bom || mb_check_encoding($data, 'UTF-8') ? 'UTF-8' : 'Windows-1250';
        $text = bank_utf8($data);
        $res['delimiter'] = acct_detect_delimiter($text);
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $grid = [];
        while (($row = fgetcsv($fh, null, $res['delimiter'], '"', '')) !== false) {
            $grid[] = array_map(fn($c) => trim((string) $c), $row);
            if (count($grid) > ACCT_MAX_ROWS + 20) {
                break;
            }
        }
        fclose($fh);
    }
    $grid = array_values(array_filter($grid, fn($r) => implode('', $r) !== ''));
    if (!$grid) {
        return ['error' => 'Soubor je prázdný.'] + $res;
    }
    $headerAt = 0;
    foreach (array_slice($grid, 0, 15, true) as $i => $r) {
        if (count(array_filter(acct_guess_mapping($r, array_keys(ACCT_SYNONYMS)))) >= 2) {
            $headerAt = $i;
            break;
        }
    }
    $headers = $grid[$headerAt];
    foreach ($headers as $i => $h) {
        $headers[$i] = $h !== '' ? mb_substr($h, 0, 80) : 'Sloupec ' . ($i + 1);
    }
    $width = count($headers);
    $rows = [];
    foreach (array_slice($grid, $headerAt + 1) as $r) {
        $rows[] = array_slice(array_pad($r, $width, ''), 0, $width);
    }
    if (count($rows) > ACCT_MAX_ROWS) {
        return ['error' => 'Soubor má víc než ' . ACCT_MAX_ROWS . ' řádků. Rozdělte ho prosím.'] + $res;
    }
    return ['headers' => $headers, 'rows' => $rows] + $res;
}
/** Jednoduché čtení prvního listu XLSX (sdílené texty, vložené texty, čísla). Null = nejde přečíst. */
function acct_read_xlsx(string $data): ?array
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'acx');
    file_put_contents($tmp, $data);
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($tmp) !== true) {
            return null;
        }
        $opened = true;
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        // První list podle workbook.xml a jeho vztahu (Target bývá „worksheets/sheet1.xml“ nebo „/xl/worksheets/…“).
        if ($wb !== false && $rels !== false && preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)) {
            foreach (preg_split('/(?=<Relationship\b)/', $rels) as $rel) {
                if (str_contains($rel, 'Id="' . $m[1] . '"') && preg_match('/Target="([^"]+)"/', $rel, $t)) {
                    $target = ltrim($t[1], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        $sheet = $zip->getFromName($sheetPath);
        if ($sheet === false || strlen($sheet) > 30_000_000) {
            return null;
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        $prev = libxml_use_internal_errors(true);
        if ($ss !== false && ($sx = simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET)) !== false) {
            foreach ($sx->si as $si) {
                $txt = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r as $r) {
                    $txt .= (string) $r->t;
                }
                $shared[] = $txt;
            }
        }
        $sx = simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($sx === false || !isset($sx->sheetData)) {
            return null;
        }
        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = 0;
                if (preg_match('/^([A-Z]+)/', $ref, $mm)) {
                    foreach (str_split($mm[1]) as $ch) {
                        $col = $col * 26 + (ord($ch) - 64);
                    }
                    $col--;
                } else {
                    $col = count($cells);
                }
                $type = (string) $c['t'];
                $v = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    'b' => (string) $c->v === '1' ? 'ano' : 'ne',
                    default => (string) $c->v,
                };
                if ($type === '' && is_numeric($v) && str_contains(strtolower($v), 'e')) {
                    $v = rtrim(rtrim(number_format((float) $v, 10, '.', ''), '0'), '.');
                }
                if ($col < 200) {
                    $cells[$col] = trim($v);
                }
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $grid[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
            }
            if (count($grid) > ACCT_MAX_ROWS + 20) {
                break;
            }
        }
        return $grid;
    } finally {
        if ($opened) {
            $zip->close();
        }
        @unlink($tmp);
    }
}

// ---------- Import: přiřazení sloupců ----------

/**
 * Odhad přiřazení: [pole => index sloupce] pro zadaná pole. Nejdřív přesná shoda názvu, pak začátek/celé slovo;
 * $preset = klíč z ACCT_PRESETS (jeho názvy mají přednost). Každý sloupec nejvýš jednomu poli.
 */
function acct_guess_mapping(array $headers, array $fields, string $preset = ''): array
{
    $norm = array_map(fn($h) => acct_norm((string) $h), $headers);
    $syn = [];
    foreach (array_keys(ACCT_SYNONYMS) as $f) {
        if (in_array($f, $fields, true)) {
            $syn[$f] = array_values(array_unique(array_merge(ACCT_PRESETS[$preset][1][$f] ?? [], ACCT_SYNONYMS[$f])));
        }
    }
    $map = array_fill_keys($fields, null);
    $used = [];
    $passes = [
        fn(string $h, string $s) => $h === $s,
        fn(string $h, string $s) => str_starts_with($h, $s . ' '),
        fn(string $h, string $s) => strlen($s) > 2 && preg_match('/(^| )' . preg_quote($s, '/') . '( |$)/', $h) === 1,
    ];
    foreach ($passes as $match) {
        foreach ($syn as $f => $names) {
            if ($map[$f] !== null) {
                continue;
            }
            foreach ($names as $s) {
                foreach ($norm as $i => $h) {
                    if (!isset($used[$i]) && $h !== '' && $match($h, $s)) {
                        $map[$f] = $i;
                        $used[$i] = true;
                        break 2;
                    }
                }
            }
        }
    }
    return $map;
}
/** Pole cíle importu (s polem „dům“ pro import za více domů). */
function acct_fields(string $target, bool $multi = false): array
{
    return ($multi ? ['house' => 'Dům'] : []) + ACCT_TARGETS[$target][1];
}
/** Přiřazení z formuláře: [pole => index] (prázdné = nepoužito), každý sloupec jen jednou. */
function acct_mapping_from_input(array $in, array $headers, array $fields): array
{
    $map = [];
    $used = [];
    foreach (array_keys($fields) as $f) {
        $v = $in[$f] ?? '';
        $i = is_string($v) && ctype_digit($v) && (int) $v < count($headers) && !isset($used[(int) $v]) ? (int) $v : null;
        $map[$f] = $i;
        if ($i !== null) {
            $used[$i] = true;
        }
    }
    return $map;
}
/** Uložené přiřazení (podle názvů sloupců) převede na indexy pro nový soubor; nesedí-li některý sloupec, vrací null. */
function acct_mapping_restore(string $json, array $headers, array $fields): ?array
{
    $saved = json_decode($json, true);
    if (!is_array($saved) || !$saved) {
        return null;
    }
    $norm = array_map(fn($h) => acct_norm((string) $h), $headers);
    $map = array_fill_keys(array_keys($fields), null);
    foreach ($saved as $f => $name) {
        if (!array_key_exists($f, $map) || !is_string($name)) {
            continue;
        }
        $i = array_search(acct_norm($name), $norm, true);
        if ($i === false) {
            return null;
        }
        $map[$f] = $i;
    }
    return $map;
}
/** Přiřazení k uložení: [pole => název sloupce]. */
function acct_mapping_export(array $map, array $headers): string
{
    $out = [];
    foreach ($map as $f => $i) {
        if ($i !== null && isset($headers[$i])) {
            $out[$f] = $headers[$i];
        }
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// ---------- Import: kontrola a uložení ----------

/** Rejstřík jednotek domu pro hledání podle označení, vchodu a čísla, samotného čísla (je-li jedinečné) a VS. */
function acct_unit_index(PDO $pdo, string $prefix): array
{
    $idx = ['label' => [], 'en' => [], 'num' => [], 'vs' => [], 'units' => []];
    $k = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', '', $s));
    $numCount = [];
    foreach (acct_rows($pdo, 'SELECT * FROM units') as $un) {
        $id = (int) $un['id'];
        $idx['units'][$id] = $un;
        $n = acct_num_key((string) $un['number']);
        $idx['label'][$k(acct_unit_label($un, $prefix))] = $id;
        $idx['en'][$k($un['entrance']) . '/' . $n] = $id;
        $numCount[$n] = ($numCount[$n] ?? 0) + 1;
        $idx['num'][$n] = $id;
    }
    $idx['num'] = array_filter($idx['num'], fn($id, $n) => $numCount[$n] === 1, ARRAY_FILTER_USE_BOTH);
    if (acct_table_exists($pdo, 'unit_accounts')) {
        foreach (acct_rows($pdo, "SELECT unit_id, vs FROM unit_accounts WHERE vs <> ''") as $a) {
            $idx['vs'][ltrim(preg_replace('/\D/', '', (string) $a['vs']), '0')] = (int) $a['unit_id'];
        }
    }
    return $idx;
}
/** Číslo jednotky pro porovnání: malými písmeny, bez mezer a úvodních nul („01“ = „1“). */
function acct_num_key(string $n): string
{
    $n = mb_strtolower(preg_replace('/\s+/u', '', $n));
    return preg_match('/^0+\d/', $n) ? ltrim($n, '0') : $n;
}
/** Najde jednotku; vrací [id|0, vchod, číslo] (vchod a číslo pro založení nové jednotky). */
function acct_resolve_unit(array $idx, string $prefix, string $unit, string $entrance, string $vs, array $entrances): array
{
    $k = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', '', $s));
    $u = trim($unit);
    $e = mb_strtoupper(trim($entrance));
    // Rozklad označení: [předpona]vchod/číslo, případně jen číslo.
    $num = $u;
    $ent = $e;
    if (str_contains($u, '/')) {
        $num = trim(mb_substr($u, mb_strrpos($u, '/') + 1));
        $head = trim(mb_substr($u, 0, mb_strrpos($u, '/')));
        if ($prefix !== '' && mb_stripos($head, $prefix) === 0) {
            $head = mb_substr($head, mb_strlen($prefix));
        }
        if ($ent === '') {
            $ent = mb_strtoupper(trim($head));
        }
    }
    if ($u !== '') {
        if (isset($idx['label'][$k($u)])) {
            $id = $idx['label'][$k($u)];
            return [$id, $idx['units'][$id]['entrance'], $idx['units'][$id]['number']];
        }
        $key = $k($ent) . '/' . acct_num_key($num);
        if (isset($idx['en'][$key])) {
            $id = $idx['en'][$key];
            return [$id, $idx['units'][$id]['entrance'], $idx['units'][$id]['number']];
        }
        if ($ent === '' && isset($idx['num'][acct_num_key($num)])) {
            $id = $idx['num'][acct_num_key($num)];
            return [$id, $idx['units'][$id]['entrance'], $idx['units'][$id]['number']];
        }
    }
    $vsKey = ltrim(preg_replace('/\D/', '', $vs), '0');
    if ($vsKey !== '' && isset($idx['vs'][$vsKey])) {
        $id = $idx['vs'][$vsKey];
        return [$id, $idx['units'][$id]['entrance'], $idx['units'][$id]['number']];
    }
    if ($ent === '' && count($entrances) === 1) {
        $ent = (string) $entrances[0];
    }
    return [0, $ent, preg_match('/^0+\d/', $num) ? ltrim($num, '0') : $num];   // „07“ se založí jako „7“
}
/**
 * Náhled importu: každý řádek se zkontroluje a přiřadí k jednotce. Vrací
 * ['rows' => [['line', 'label', 'unit_id', 'entrance', 'number', 'values' => [pole => hodnota], 'errors' => [], 'warnings' => [], 'action' => 'update'|'create'|'skip'], …],
 *  'ok' => n, 'bad' => n, 'create' => n].
 */
function acct_preview(PDO $pdo, array $house, string $target, array $table, array $map): array
{
    $idx = acct_unit_index($pdo, $house['prefix']);
    $out = ['rows' => [], 'ok' => 0, 'bad' => 0, 'create' => 0, 'missing' => []];
    $get = fn(array $r, string $f) => isset($map[$f]) ? trim((string) ($r[$map[$f]] ?? '')) : '';
    if (($map['unit'] ?? null) === null && !($target === 'accounts' && ($map['vs'] ?? null) !== null)) {
        $out['missing'][] = $target === 'accounts' ? 'Přiřaďte sloupec s jednotkou nebo s variabilním symbolem.' : 'Přiřaďte sloupec s jednotkou.';
        return $out;
    }
    if ($target === 'accounts' && ($map['prescribed'] ?? null) === null && ($map['balance'] ?? null) === null && ($map['vs'] ?? null) === null) {
        $out['missing'][] = 'Přiřaďte aspoň jeden ze sloupců předpis, stav účtu nebo variabilní symbol.';
        return $out;
    }
    $seen = [];
    foreach ($table['rows'] as $n => $r) {
        $row = ['line' => $n + 2, 'label' => '', 'unit_id' => 0, 'entrance' => '', 'number' => '', 'values' => [], 'errors' => [], 'warnings' => [], 'action' => 'skip'];
        $unitText = $get($r, 'unit');
        $vs = $target === 'accounts' ? $get($r, 'vs') : '';
        if ($unitText === '' && $vs === '') {
            continue;   // prázdný řádek nebo součet
        }
        if (preg_match('/^(celkem|soucet|součet|total)\b/iu', $unitText)) {
            continue;
        }
        [$id, $ent, $num] = acct_resolve_unit($idx, $house['prefix'], $unitText, $get($r, 'entrance'), $vs, $house['entrances']);
        $row['unit_id'] = $id;
        $row['entrance'] = $ent;
        $row['number'] = $num;
        $row['label'] = $id ? acct_unit_label($idx['units'][$id], $house['prefix']) : ($num !== '' ? $house['prefix'] . $ent . '/' . $num : $unitText);
        if ($target === 'units') {
            if (($s = $get($r, 'share')) !== '') {
                $share = acct_share($s);
                $share === null ? $row['errors'][] = 'Podíl „' . $s . '“ nerozumíme (čekáme např. 812/10000 nebo 8,12 %).' : $row['values']['share'] = $share;
            }
            foreach (['owner' => 255, 'contact' => 255, 'floor' => 20] as $f => $len) {
                if (($v = $get($r, $f)) !== '') {
                    $row['values'][$f] = mb_substr($v, 0, $len);
                }
            }
            if (!$id) {
                if ($num === '' || mb_strlen($num) > 20 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\-]*$/u', $num)) {
                    $row['errors'][] = 'Jednotku „' . $unitText . '“ jsme nenašli a nejde z ní poznat číslo.';
                } elseif (!in_array($ent, $house['entrances'], true)) {
                    $row['errors'][] = 'Vchod „' . $ent . '“ dům nemá (vchody: ' . implode(', ', array_map(fn($x) => $x === '' ? 'bez vchodu' : $x, $house['entrances'])) . ').';
                } else {
                    $row['warnings'][] = 'Nová jednotka – založí se.';
                }
            }
        } else {
            if (!$id) {
                $row['errors'][] = $unitText !== '' ? 'Jednotku „' . $unitText . '“ v domě nemáme.' : 'Variabilní symbol ' . $vs . ' nepatří žádné jednotce.';
            }
            foreach (['prescribed', 'balance'] as $f) {
                if (($v = $get($r, $f)) !== '') {
                    $num2 = acct_num($v);
                    $num2 === null ? $row['errors'][] = ACCT_TARGETS['accounts'][1][$f] . ': „' . $v . '“ není částka.' : $row['values'][$f] = $num2;
                }
            }
            if (isset($row['values']['prescribed']) && $row['values']['prescribed'] < 0) {
                $row['errors'][] = 'Předpis nemůže být záporný.';
            }
            if ($vs !== '') {
                $digits = preg_replace('/\D/', '', $vs);
                $digits === '' || strlen($digits) > 10 ? $row['errors'][] = 'Variabilní symbol „' . $vs . '“ musí mít 1 až 10 číslic.' : $row['values']['vs'] = $digits;
            }
            if (($v = $get($r, 'note')) !== '') {
                $row['values']['note'] = mb_substr($v, 0, 250);
            }
            if (!$row['values'] && !$row['errors']) {
                $row['warnings'][] = 'Bez údajů – přeskočí se.';
            }
        }
        $key = $id ?: 'new:' . $row['label'];
        if (isset($seen[$key]) && !$row['errors']) {
            $row['errors'][] = 'Jednotka je v souboru víckrát (řádek ' . $seen[$key] . ').';
        }
        $seen[$key] ??= $row['line'];
        $row['action'] = $row['errors'] || (!$row['values'] && $id) ? 'skip' : ($id ? 'update' : 'create');
        if ($target === 'accounts' && !$row['values']) {
            $row['action'] = 'skip';
        }
        $row['errors'] ? $out['bad']++ : ($row['action'] !== 'skip' ? $out['ok']++ : null);
        $row['action'] === 'create' ? $out['create']++ : null;
        $out['rows'][] = $row;
    }
    return $out;
}
/** Uloží bezchybné řádky náhledu. Vrací ['updated' => n, 'created' => n]. Řádky s chybou se vynechají. */
function acct_apply(PDO $pdo, string $target, array $preview, string $asOf): array
{
    $res = ['updated' => 0, 'created' => 0];
    $hasAcc = $target === 'accounts' && acct_table_exists($pdo, 'unit_accounts');
    $pdo->beginTransaction();
    try {
        foreach ($preview['rows'] as $r) {
            if ($r['action'] === 'skip' || $r['errors']) {
                continue;
            }
            $v = $r['values'];
            if ($target === 'units') {
                $cols = array_intersect_key(['share' => $v['share'] ?? null, 'owner_note' => $v['owner'] ?? null, 'owner_contact' => $v['contact'] ?? null, 'floor' => $v['floor'] ?? null],
                    array_filter(['share' => isset($v['share']), 'owner_note' => isset($v['owner']), 'owner_contact' => isset($v['contact']), 'floor' => isset($v['floor'])]));
                if ($r['action'] === 'create') {
                    $all = ['entrance' => $r['entrance'], 'number' => $r['number']] + $cols;
                    $pdo->prepare('INSERT INTO units (' . implode(', ', array_keys($all)) . ') VALUES (' . implode(',', array_fill(0, count($all), '?')) . ')')->execute(array_values($all));
                    $res['created']++;
                } elseif ($cols) {
                    $pdo->prepare('UPDATE units SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ?')->execute([...array_values($cols), $r['unit_id']]);
                    $res['updated']++;
                }
                continue;
            }
            if (!$hasAcc || !$r['unit_id']) {
                continue;
            }
            $st = $pdo->prepare('SELECT * FROM unit_accounts WHERE unit_id = ?');
            $st->execute([$r['unit_id']]);
            $old = $st->fetch(PDO::FETCH_ASSOC) ?: ['prescribed' => 0, 'balance' => 0, 'vs' => '', 'note' => '', 'reminded_at' => ''];
            $pdo->prepare('DELETE FROM unit_accounts WHERE unit_id = ?')->execute([$r['unit_id']]);
            $pdo->prepare('INSERT INTO unit_accounts (unit_id, prescribed, balance, vs, as_of, note, reminded_at) VALUES (?,?,?,?,?,?,?)')->execute([$r['unit_id'],
                $v['prescribed'] ?? (float) $old['prescribed'], $v['balance'] ?? (float) $old['balance'], $v['vs'] ?? (string) $old['vs'], $asOf, $v['note'] ?? (string) $old['note'], (string) ($old['reminded_at'] ?? '')]);
            $res['updated']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $res;
}

// ---------- Import za více domů (partner) ----------

/** Najde dům podle textu ve sloupci „dům“: slug, název, titulek, plný název nebo IČO. Vrací slug nebo ''. */
function acct_match_house(string $text, array $houses): string
{
    $t = acct_norm($text);
    $digits = preg_replace('/\D/', '', $text);
    if ($t === '') {
        return '';
    }
    foreach ($houses as $h) {
        if ($t === acct_norm($h['slug']) || ($digits !== '' && strlen($digits) === 8 && $digits === ($h['_ico'] ?? ''))) {
            return $h['slug'];
        }
    }
    $hits = [];
    foreach ($houses as $h) {
        foreach (['name', 'title', 'full'] as $k) {
            if (($h[$k] ?? '') !== '' && acct_norm((string) $h[$k]) === $t) {
                $hits[$h['slug']] = true;
            }
        }
    }
    return count($hits) === 1 ? (string) array_key_first($hits) : '';
}
/**
 * Rozdělí řádky souboru za více domů podle sloupce „dům“. Vrací ['groups' => [slug => tabulka], 'unknown' => [text => počet řádků]].
 * $houses: domy partnera (tenants), doplněné o '_ico' z nastavení domu.
 */
function acct_split_houses(array $table, ?int $houseCol, array $houses): array
{
    $out = ['groups' => [], 'unknown' => []];
    if ($houseCol === null) {
        return $out;
    }
    foreach ($table['rows'] as $n => $r) {
        $text = trim((string) ($r[$houseCol] ?? ''));
        if ($text === '' && implode('', $r) === '') {
            continue;
        }
        $slug = acct_match_house($text, $houses);
        if ($slug === '') {
            $out['unknown'][$text !== '' ? $text : '(prázdné)'] = ($out['unknown'][$text !== '' ? $text : '(prázdné)'] ?? 0) + 1;
            continue;
        }
        $out['groups'][$slug] ??= ['headers' => $table['headers'], 'rows' => [], 'lines' => []];
        $out['groups'][$slug]['rows'][$n] = $r;   // klíč = původní pořadí (číslo řádku v náhledu sedí se souborem)
    }
    return $out;
}
/** Domy partnera s IČO z nastavení domu (pro párování podle IČO). */
function acct_partner_houses(array $houses): array
{
    foreach ($houses as &$h) {
        try {
            $h['_ico'] = preg_replace('/\D/', '', (string) (tenant_settings($h['slug'])['ico'] ?? ''));
        } catch (Throwable $e) {
            $h['_ico'] = '';
        }
    }
    return $houses;
}

/** Záznam do historie změn jiného domu (partnerský portál) – s otiskem navazujícím na předchozí záznam jako audit(). */
function acct_tenant_audit(PDO $pdo, string $who, string $action, string $detail): void
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $at = date('Y-m-d H:i:s');
        $ip = PHP_SAPI === 'cli' ? 'příkazová řádka' : client_ip();
        $detail = mb_substr($detail, 0, 1000);
        $pdo->prepare('INSERT INTO audit_log (at, user_id, user_name, ip, action, detail) VALUES (?,?,?,?,?,?)')->execute([$at, null, $who, $ip, $action, $detail]);
        $id = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('SELECT chain FROM audit_log WHERE id < ? AND chain IS NOT NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$id]);
        $prev = (string) $st->fetchColumn();
        $pdo->prepare('UPDATE audit_log SET chain = ? WHERE id = ?')->execute([hash('sha256', implode('|', [$prev, $at, $who, $ip, $action, $detail])), $id]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------- Menu administrace ----------

$GLOBALS['EXT_ADMIN_NAV']['/admin/ucetnictvi'] = ['Účetnictví – export a import', 'Dům a lidé'];
$GLOBALS['EXT_PARTNER_NAV']['/ucetnictvi'] = 'Účetnictví';

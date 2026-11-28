<?php
declare(strict_types=1);

/*
 * Modul E – insolvence vlastníků, kteří jsou fyzické osoby (doplněk k app/isir_lib.php, který hlídá firmy podle IČO).
 *
 * - Veřejná webová služba insolvenčního rejstříku pro ČÚZK (Ministerstvo spravedlnosti, zdarma), stejná adresa jako u firem
 *   (ISIR_URL, v config.php jde přepsat isir.url). Fyzická osoba se hledá podle příjmení, jména a data narození.
 *   NEOVĚŘENO PROTI SKUTEČNÉ SLUŽBĚ: názvy prvků dotazu (REJ_ISIR_PERSON_FIELDS) odpovídají obvyklému popisu služby
 *   IsirWsCuzkService (vstupy rc / ic / nazevOsoby + jmeno + datumNarozeni); WSDL: https://isir.justice.cz:8443/isir_cuzk_ws/IsirWsCuzkService?wsdl
 *   Výsledky se navíc filtrují podle data narození v odpovědi, aby shoda jen ve jméně nehlásila cizího člověka.
 * - Ochrana údajů: rodné číslo se nikdy neukládá. Datum narození zadává správce jen u vlastníků, u kterých to dává smysl;
 *   vidí ho jen správci, při převzetí nového vlastníka z katastru se smaže. Právní titul: oprávněný zájem SVJ na vymáhání
 *   pohledávek (čl. 6 odst. 1 písm. f GDPR) – viz nápověda „Hlídání insolvencí a exekucí vlastníků“.
 */

/** Prvky dotazu getIsirWsCuzkDataRequest pro fyzickou osobu (jedno místo pro opravu, kdyby se lišily). */
const REJ_ISIR_PERSON_FIELDS = ['surname' => 'nazevOsoby', 'first' => 'jmeno', 'birth' => 'datumNarozeni'];

/** Kontrola údajů osoby: chybová hláška, nebo null. */
function rej_person_error(string $surname, string $first, string $birth): ?string
{
    if (mb_strlen(trim($surname)) < 2 || mb_strlen(trim($surname)) > 100 || preg_match('/[\d<>]/u', $surname)) {
        return 'Vyplňte příjmení vlastníka.';
    }
    if (mb_strlen(trim($first)) > 100 || preg_match('/[\d<>]/u', $first)) {
        return 'Jméno nemá správný tvar.';
    }
    if (!valid_date($birth) || $birth > date('Y-m-d', strtotime('-15 years')) || $birth < '1900-01-01') {
        return 'Datum narození zadejte ve tvaru RRRR-MM-DD (vlastníkovi musí být aspoň 15 let).';
    }
    return null;
}
/** Návrhy osob z pole „Vlastník dle evidence“ („Novák Petr; SJM Novák Petr a Nováková Marie“) – firmy vynechá. */
function rej_owner_people(string $note): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(';', $note))) as $part) {
        $part = trim(preg_replace(['~\(\d+\s*/\s*\d+\)\s*$~', '/\(\s*SJM?\s*\)/u'], '', $part));
        if (defined('ISIR_COMPANY') && preg_match(ISIR_COMPANY, $part)) {
            continue;
        }
        $part = preg_replace('/^(SJM|SJ)\s+/u', '', $part);
        foreach (preg_split('/\s+a\s+/u', $part) as $person) {
            $person = trim(preg_replace('/\b(Ing|Mgr|Bc|JUDr|MUDr|MVDr|PhDr|RNDr|PaedDr|doc|prof|CSc|Ph\.D|DiS|MBA)\.?(?=\s|,|$)/u', '', explode(',', $person)[0]));
            $words = preg_split('/\s+/u', $person, -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) >= 2) {
                $out[] = ['surname' => $words[0], 'first' => implode(' ', array_slice($words, 1))];
            }
        }
    }
    return $out;
}
/**
 * Lidé u jednotek k rychlému výběru při přidání hlídané osoby: vlastníci z katastru (owner_note) a uživatelé webu
 * s rolí vlastník přiřazení k jednotce. [unit_id => [['surname', 'first'], …]] bez duplicit a bez těch, kdo se už u jednotky hlídají.
 */
function rej_unit_people(): array
{
    $out = [];
    $seen = [];
    $add = function (int $unit, string $surname, string $first) use (&$out, &$seen): void {
        $surname = trim($surname);
        $first = trim($first);
        $k = $unit . '|' . mb_strtolower($surname . '|' . $first);
        if ($surname === '' || isset($seen[$k])) {
            return;
        }
        $seen[$k] = true;
        $out[$unit][] = ['surname' => $surname, 'first' => $first];
    };
    foreach (rej_persons() as $p) {
        $seen[(int) $p['unit_id'] . '|' . mb_strtolower(trim($p['surname']) . '|' . trim($p['first_name']))] = true;
    }
    foreach (all_units() as $u) {
        foreach (rej_owner_people((string) $u['owner_note']) as $rp) {
            $add((int) $u['id'], $rp['surname'], $rp['first']);
        }
    }
    // Uživatelé webu – jen vlastníci (nájemce není dlužníkem společenství, hlídat ho nemá SVJ důvod).
    // Jméno mají ve tvaru „Jméno Příjmení“ (tituly se vynechají).
    foreach (all("SELECT uu.unit_id, u.name FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.active = 1 AND u.kind = 'owner' ORDER BY u.name") as $r) {
        $name = trim(preg_replace('/\b(Ing|Mgr|Bc|JUDr|MUDr|MVDr|PhDr|RNDr|PaedDr|doc|prof|CSc|Ph\.D|DiS|MBA)\.?(?=\s|,|$)/u', '', explode(',', (string) $r['name'])[0]));
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) >= 2) {
            $add((int) $r['unit_id'], (string) array_pop($words), implode(' ', $words));
        }
    }
    return $out;
}
function rej_person_add(int $unitId, string $surname, string $first, string $birth, int $by): ?string
{
    rej_ready();
    if ($err = rej_person_error($surname, $first, $birth)) {
        return $err;
    }
    if ($unitId && !val('SELECT id FROM units WHERE id = ?', [$unitId])) {
        return 'Jednotka neexistuje.';
    }
    if (val('SELECT id FROM rej_persons WHERE unit_id = ? AND surname = ? AND first_name = ? AND birth_date = ?', [$unitId, trim($surname), trim($first), $birth])) {
        return 'Tuto osobu už u jednotky hlídáte.';
    }
    if ((int) val('SELECT COUNT(*) FROM rej_persons') >= 2000) {
        return 'Hlídat jde nejvýš 2 000 osob.';
    }
    q('INSERT INTO rej_persons (unit_id, surname, first_name, birth_date, created_at, created_by, isir_checked, isir_found) VALUES (?,?,?,?,?,?,?,?)',
        [$unitId, trim($surname), trim($first), $birth, date('Y-m-d H:i:s'), $by, '', '']);
    return null;
}
function rej_person_delete(int $id): bool
{
    rej_ready();
    $exists = (bool) val('SELECT id FROM rej_persons WHERE id = ?', [$id]);
    q('DELETE FROM rej_persons WHERE id = ?', [$id]);
    q('DELETE FROM rej_exekuce WHERE person_id = ?', [$id]);   // i výsledky lustrací této osoby
    return $exists;
}
/** Hlídané osoby s jednotkou. */
function rej_persons(): array
{
    rej_ready();
    return all('SELECT p.*, u.entrance, u.number, u.owner_note FROM rej_persons p LEFT JOIN units u ON u.id = p.unit_id ORDER BY u.entrance, u.number + 0, u.number, p.surname');
}

/** Dotaz do ISIR na fyzickou osobu: ['ok', 'found' => [řízení], 'error']. */
function rej_isir_check_person(string $surname, string $first, string $birth, bool $activeOnly = true): array
{
    if ($err = rej_person_error($surname, $first, $birth)) {
        return ['ok' => false, 'found' => [], 'error' => $err];
    }
    $f = REJ_ISIR_PERSON_FIELDS;
    $url = (string) (app_config()['isir']['url'] ?? ISIR_URL);
    $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="http://isirws.cca.cz/types/"><soapenv:Body>'
        . '<typ:getIsirWsCuzkDataRequest><' . $f['surname'] . '>' . rej_xml(trim($surname)) . '</' . $f['surname'] . '>'
        . (trim($first) !== '' ? '<' . $f['first'] . '>' . rej_xml(trim($first)) . '</' . $f['first'] . '>' : '')
        . '<' . $f['birth'] . '>' . $birth . '</' . $f['birth'] . '>'
        . '<maxPocetVysledku>50</maxPocetVysledku><filtrAktualniRizeni>' . ($activeOnly ? 'T' : 'F') . '</filtrAktualniRizeni></typ:getIsirWsCuzkDataRequest>'
        . '</soapenv:Body></soapenv:Envelope>';
    $r = rej_http('POST', $url, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'], $xml, 20);
    if ($r['status'] === 0) {
        return ['ok' => false, 'found' => [], 'error' => 'Insolvenční rejstřík neodpovídá (' . $r['error'] . ').'];
    }
    return rej_isir_parse($r['body'], $r['status'], $birth);
}
/** Odpověď ISIR → řízení; $birth = jen záznamy s tímto datem narození (pokud ho odpověď uvádí). */
function rej_isir_parse(string $body, int $status, string $birth = ''): array
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($body, LIBXML_NONET)) {
        return ['ok' => false, 'found' => [], 'error' => 'Odpověď rejstříku (HTTP ' . $status . ') není XML.'];
    }
    if ($fault = $dom->getElementsByTagNameNS('*', 'faultstring')->item(0) ?? $dom->getElementsByTagName('faultstring')->item(0)) {
        return ['ok' => false, 'found' => [], 'error' => 'Rejstřík vrátil chybu: ' . trim($fault->textContent)];
    }
    $found = [];
    foreach ($dom->getElementsByTagNameNS('*', 'data') as $node) {
        $row = [];
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $row[$c->localName] = trim($c->textContent);
            }
        }
        if (!$row) {
            continue;
        }
        if ($birth !== '' && ($row['datumNarozeni'] ?? '') !== '' && substr($row['datumNarozeni'], 0, 10) !== $birth) {
            continue;   // stejné jméno, jiný člověk
        }
        $found[] = ['sz' => trim(($row['cisloSenatu'] ?? '') . ' ' . ($row['druhVec'] ?? 'INS') . ' ' . ($row['bcVec'] ?? '') . '/' . ($row['rocnik'] ?? '')),
            'name' => trim(($row['nazevOsoby'] ?? '') . ' ' . ($row['jmeno'] ?? '')), 'court' => $row['nazevOrganizace'] ?? '', 'state' => $row['druhStavKonkursu'] ?? '', 'url' => $row['urlDetailRizeni'] ?? ''];
    }
    $code = $dom->getElementsByTagNameNS('*', 'kodChyby')->item(0);
    if ($code && !in_array(trim($code->textContent), ['', '0', 'OK', 'WS2'], true) && !$found) {
        $text = $dom->getElementsByTagNameNS('*', 'textChyby')->item(0);
        if (!preg_match('/nenalezen|nebyl|žádn/iu', (string) ($text?->textContent))) {
            return ['ok' => false, 'found' => [], 'error' => 'Rejstřík vrátil kód ' . trim($code->textContent) . ($text ? ': ' . trim($text->textContent) : '')];
        }
    }
    return ['ok' => $status < 400, 'found' => $found, 'error' => $status >= 400 ? 'HTTP ' . $status : ''];
}
/** Ověří všechny hlídané osoby; nová řízení pošle správcům. Vrací [ověřeno, nalezeno, chyba]. */
function rej_isir_run(bool $notify = true): array
{
    $checked = $hits = 0;
    $error = '';
    $new = [];
    foreach (rej_persons() as $p) {
        $r = rej_isir_check_person($p['surname'], $p['first_name'], $p['birth_date']);
        if (!$r['ok']) {
            $error = $r['error'];
            break;   // služba nejde – zkusí se příště, výsledky se nemění
        }
        $checked++;
        $before = array_column((array) json_decode((string) $p['isir_found'], true), 'sz');
        foreach ($r['found'] as $f) {
            $hits++;
            if (!in_array($f['sz'], $before, true)) {
                $new[] = ['unit' => $p['number'] !== null ? unit_name($p) : '–', 'person' => trim($p['surname'] . ' ' . $p['first_name'])] + $f;
            }
        }
        q('UPDATE rej_persons SET isir_checked = ?, isir_found = ? WHERE id = ?', [date('Y-m-d H:i'), $r['found'] ? json_encode($r['found'], JSON_UNESCAPED_UNICODE) : '', $p['id']]);
    }
    rej_set('rej_isir_last', date('Y-m-d H:i') . '|' . ($error === '' ? 'ok' : 'chyba') . '|' . ($error !== '' ? $error : "ověřeno osob $checked, řízení $hits"));
    if ($notify && $new) {
        $text = "Dobrý den,\n\nv insolvenčním rejstříku se objevilo řízení u vlastníka jednotky (fyzické osoby):\n\n" . implode("\n\n", array_map(fn($n) => $n['unit'] . ' – ' . $n['person']
            . "\nSpisová značka: " . $n['sz'] . ($n['court'] !== '' ? ', ' . $n['court'] : '') . ($n['state'] !== '' ? "\nStav: " . $n['state'] : '') . ($n['url'] !== '' ? "\nDetail: " . $n['url'] : ''), $new))
            . "\n\nPohledávku (dlužné zálohy) je potřeba přihlásit do insolvenčního řízení včas – zpravidla do dvou měsíců od rozhodnutí o úpadku. Poraďte se s účetní nebo právníkem.\n\nPřehled: " . absolute_url('/admin/insolvence');
        foreach (rej_admin_emails() as $to) {
            send_mail($to, 'Insolvence vlastníka jednotky – ' . site('name'), $text);
        }
    }
    return [$checked, $hits, $error];
}
/** Denně: jednou týdně ověří hlídané osoby (Premium, insolvence spuštěná provozovatelem). */
function rej_isir_daily(): void
{
    if (!rej_allowed('insolvence')) {
        return;
    }
    $last = substr(setting('rej_isir_last'), 0, 10);
    if ($last !== '' && $last > date('Y-m-d', time() - 6 * 86400)) {
        return;
    }
    rej_ready();
    if (!(int) val('SELECT COUNT(*) FROM rej_persons')) {
        return;
    }
    rej_isir_run();
}

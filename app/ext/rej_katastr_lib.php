<?php
declare(strict_types=1);

/*
 * Modul E – sledování změn v katastru nemovitostí přes dálkový přístup ČÚZK (WSDP 3.1, placená služba).
 *
 * Dokumentace (veřejná): https://cuzk.gov.cz/Katastr-nemovitosti/Poskytovani-udaju-z-KN/Dalkovy-pristup/Webove-sluzby-dalkoveho-pristupu.aspx
 *  - balík WSDP_10_4_2026 (WSDL/XSD verze 3.1, „Popis webových služeb DP pro uživatele“):
 *    služba sestavy https://katastr.cuzk.gov.cz/ws/wsdp/3.1/sestavy, operace generujLVZjednodusene (kód k.ú. + číslo LV)
 *    a vratSestavu (asynchronní odebrání, příloha MTOM), autentizace WS-Security UsernameToken (PasswordText);
 *  - XSD výstupu: https://katastr.cuzk.gov.cz/dokumentace/xsd/sestavy/VypisZKatastruNemovitosti.xsd
 *    (budova → JEDNOTKY → JEDNOTKA: CISLO_JEDNOTKY, PODIL_BA, VLASTNICTVI/OPSUB_NAZEV + PODIL; PLOMBA_BUDOVY; ZMENY_PV/ZMENA_PV/PLOMBA).
 *  - úplata: Výpis z KN přes WSDP 100 Kč za LV (vyhláška 358/2013 Sb., příloha 5, položka 5001),
 *    https://cuzk.gov.cz/Katastr-nemovitosti/Poskytovani-udaju-z-KN/Dalkovy-pristup/Uctovani-vystupu-z-KN-poskytovanych-DP-A-WSDP.aspx
 *
 * Proč ne ČÚZK „Služba sledování změn“: tu smí používat jen vlastník nebo jiný oprávněný k nemovitosti (SVJ ani provozovatel jím u bytů nejsou).
 * Proto web jednou za 30 dní (nastavitelné, rej_katastr_days) stáhne výpis LV budovy a porovná ho s minulým stavem.
 *
 * Cena: doplněk k ročnímu předplatnému – rej_price_katastr_house (1 490 Kč) za dům a rok včetně 1 LV, každý další LV
 * rej_price_katastr_lv_extra (990 Kč). Zapnutí v průběhu zaplaceného období = poměrná část do jeho konce (addon_set()
 * v app/platform_lib.php). Dům bez předplatného (bez placení, ukázka) se jako dosud jen zapisuje do spotřeby (rej_usage).
 *
 * NEOVĚŘENO PROTI SKUTEČNÉ SLUŽBĚ: volání a čtení odpovědi jsou napsané podle WSDL/XSD a popisu, testované na vzorových XML.
 * Účet dálkového přístupu (WSDP) zřizuje ČÚZK; přihlašovací údaje patří do storage/config.php:
 *   'katastr' => ['user' => '…', 'password' => '…', 'endpoint' => 'https://katastr.cuzk.gov.cz/ws/wsdp/3.1']
 */

const REJ_WSDP_URL = 'https://katastr.cuzk.gov.cz/ws/wsdp/3.1';
const REJ_CHANGE_KINDS = ['owner' => 'Nový vlastník', 'share' => 'Změna podílu', 'plomba' => 'Probíhající řízení (plomba)', 'new_unit' => 'Nová jednotka v katastru', 'gone_unit' => 'Jednotka chybí v katastru'];
const REJ_CHANGE_STATUS = ['new' => 'k vyřízení', 'applied' => 'převzato do evidence', 'dismissed' => 'vzato na vědomí'];

// Doplněk k ročnímu předplatnému (fakturace: addon_set / billing_order v app/platform_lib.php).
$GLOBALS['BILLING_ADDONS']['katastr'] = ['name' => 'Sledování změn v katastru', 'price' => fn(int $lv): int => rej_katastr_year_price($lv),
    'qty' => fn(int $lv): string => $lv . ' LV'];

/** Roční cena sledování (Kč bez DPH): dům včetně 1 LV + každý další LV. */
function rej_katastr_year_price(int $lv): int
{
    return $lv <= 0 ? 0 : rej_price('rej_price_katastr_house') + ($lv - 1) * rej_price('rej_price_katastr_lv_extra');
}
/** Počet sledovaných LV (aspoň 1). */
function rej_katastr_lv_count(): int
{
    return max(1, count(rej_katastr_target()['lv']));
}
/** Účtuje se sledování fakturou (dům s předplatným na platformě)? Jinak se jako dosud jen zapisuje do spotřeby. */
function rej_katastr_billed(): bool
{
    return function_exists('plan_managed') && plan_managed() && addon_billable(tenant_row(TENANT));
}

function rej_katastr_config(): array
{
    $c = (array) ($GLOBALS['REJ_CONFIG']['katastr'] ?? app_config()['katastr'] ?? []);   // REJ_CONFIG: podstrčení v testech
    return ['user' => (string) ($c['user'] ?? ''), 'password' => (string) ($c['password'] ?? ''), 'endpoint' => rtrim((string) ($c['endpoint'] ?? REJ_WSDP_URL), '/')];
}
function rej_katastr_configured(): bool
{
    $c = rej_katastr_config();
    return $c['user'] !== '' && $c['password'] !== '';
}
/** Čísla LV budovy (nastaví správce): ['ku' => kód k.ú. (6 číslic), 'lv' => [čísla LV]]. */
function rej_katastr_target(): array
{
    $lv = array_values(array_unique(array_filter(array_map('trim', explode(',', setting('rej_katastr_lv'))), fn($x) => preg_match('/^\d{1,6}$/', $x))));
    return ['ku' => preg_match('/^\d{6}$/', setting('rej_katastr_ku')) ? setting('rej_katastr_ku') : '', 'lv' => array_slice($lv, 0, 5)];
}
/** Kontrola zadání k.ú. a LV: chybová hláška, nebo null. */
function rej_katastr_target_error(string $ku, string $lv): ?string
{
    if (!preg_match('/^\d{6}$/', trim($ku))) {
        return 'Kód katastrálního území má šest číslic (najdete ho v Nahlížení do katastru u stavby, např. 610372).';
    }
    $list = array_filter(array_map('trim', explode(',', $lv)), fn($x) => $x !== '');
    if (!$list || count($list) > 5) {
        return 'Zadejte číslo listu vlastnictví budovy (nejvýš pět čísel oddělených čárkou).';
    }
    foreach ($list as $x) {
        if (!preg_match('/^\d{1,6}$/', $x)) {
            return 'Číslo LV „' . mb_substr($x, 0, 20) . '“ není platné – jsou to jen číslice.';
        }
    }
    return null;
}

// ---------- SOAP (WSDP) ----------

function rej_xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
/** Obálka SOAP s WS-Security UsernameToken podle „Popisu webových služeb DP“ (kap. 3.13). */
function rej_wsdp_envelope(string $service, string $request, array $fields): string
{
    $c = rej_katastr_config();
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= '<typ:' . $k . '>' . rej_xml((string) $v) . '</typ:' . $k . '>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="urn:cz:gov:cuzk:iskn:types:wsdp:' . $service . ':3.1">'
        . '<soapenv:Header><wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
        . '<wsse:UsernameToken><wsse:Username>' . rej_xml($c['user']) . '</wsse:Username>'
        . '<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . rej_xml($c['password']) . '</wsse:Password>'
        . '</wsse:UsernameToken></wsse:Security></soapenv:Header>'
        . '<soapenv:Body><typ:' . $request . '>' . $body . '</typ:' . $request . '></soapenv:Body></soapenv:Envelope>';
}
/** Rozdělí odpověď MTOM (multipart/related) na části: [['headers' => [lower => value], 'body' => …], …]; jiná odpověď = jedna část. */
function rej_mime_parts(string $raw): array
{
    if (!preg_match('/^\s*--([^\r\n]+)\r?\n/', $raw, $m)) {
        return [['headers' => [], 'body' => $raw]];
    }
    $parts = [];
    foreach (explode('--' . $m[1], $raw) as $chunk) {
        $chunk = ltrim($chunk, "\r\n");
        if ($chunk === '' || str_starts_with($chunk, '--')) {
            continue;
        }
        $split = preg_split('/\r?\n\r?\n/', $chunk, 2);
        $headers = [];
        foreach (preg_split('/\r?\n/', $split[0]) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $parts[] = ['headers' => $headers, 'body' => preg_replace('/\r?\n$/', '', $split[1] ?? '')];
    }
    return $parts ?: [['headers' => [], 'body' => $raw]];
}
/**
 * Volání služby WSDP. Vrací ['ok' => bool, 'dom' => ?DOMDocument, 'parts' => části MIME, 'error' => text, 'messages' => [[kod, uroven, text]]].
 */
function rej_wsdp_call(string $service, string $op, string $request, array $fields): array
{
    $c = rej_katastr_config();
    if (!rej_katastr_configured()) {
        return ['ok' => false, 'dom' => null, 'parts' => [], 'error' => 'Dálkový přístup do katastru není nastavený (provozovatel doplní účet WSDP).', 'messages' => []];
    }
    $r = rej_http('POST', $c['endpoint'] . '/' . $service, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "urn:cz:gov:cuzk:iskn:services:wsdp:' . $service . ':' . $op . '"'],
        rej_wsdp_envelope($service, $request, $fields), 60);
    if ($r['status'] === 0) {
        return ['ok' => false, 'dom' => null, 'parts' => [], 'error' => 'Katastr neodpovídá (' . $r['error'] . ').', 'messages' => []];
    }
    $parts = rej_mime_parts($r['body']);
    $dom = new DOMDocument();
    if (!@$dom->loadXML($parts[0]['body'], LIBXML_NONET)) {
        return ['ok' => false, 'dom' => null, 'parts' => $parts, 'error' => 'Odpověď katastru (HTTP ' . $r['status'] . ') není XML.', 'messages' => []];
    }
    $fault = $dom->getElementsByTagName('faultstring')->item(0);
    if ($fault) {
        $text = trim($fault->textContent);
        return ['ok' => false, 'dom' => $dom, 'parts' => $parts, 'error' => str_contains($text, 'UsernameToken') ? 'Katastr odmítl přihlašovací údaje WSDP (' . $text . ').' : 'Katastr vrátil chybu: ' . $text, 'messages' => []];
    }
    $messages = [];
    $error = '';
    foreach ($dom->getElementsByTagNameNS('*', 'zprava') as $z) {
        $messages[] = [(int) $z->getAttribute('kod'), $z->getAttribute('uroven'), trim($z->textContent)];
        if ($z->getAttribute('uroven') === 'CHYBA' && $error === '') {
            $error = 'Katastr: ' . trim($z->textContent) . ' (kód ' . $z->getAttribute('kod') . ')';
        }
    }
    return ['ok' => $error === '' && $r['status'] < 400, 'dom' => $dom, 'parts' => $parts, 'error' => $error !== '' ? $error : ($r['status'] >= 400 ? 'HTTP ' . $r['status'] : ''), 'messages' => $messages];
}
/** Sestava z odpovědi: ['id', 'stav', 'cena' (Kč), 'content' (obsah souboru nebo '')]. */
function rej_wsdp_report(array $call): ?array
{
    $report = $call['dom']?->getElementsByTagNameNS('*', 'report')->item(0);
    if (!$report) {
        return null;
    }
    $out = ['id' => '', 'stav' => '', 'cena' => 0, 'content' => ''];
    foreach ($report->childNodes as $ch) {
        if (!$ch instanceof DOMElement) {
            continue;
        }
        match ($ch->localName) {
            'id' => $out['id'] = trim($ch->textContent),
            'stav' => $out['stav'] = trim($ch->textContent),
            'cena' => $out['cena'] = (int) round((float) str_replace(',', '.', trim($ch->textContent))),
            'souborSestavy' => $out['content'] = rej_wsdp_attachment($ch, $call['parts']),
            default => null,
        };
    }
    return $out;
}
/** Obsah přílohy: odkaz XOP na část MTOM, jinak base64 v textu; ZIP rozbalí (první soubor XML). */
function rej_wsdp_attachment(DOMElement $el, array $parts): string
{
    $data = '';
    $inc = $el->getElementsByTagNameNS('*', 'Include')->item(0);
    if ($inc instanceof DOMElement) {
        $cid = rawurldecode(preg_replace('/^cid:/', '', $inc->getAttribute('href')));
        foreach ($parts as $p) {
            if (trim($p['headers']['content-id'] ?? '', '<> ') === $cid) {
                $data = strtolower($p['headers']['content-transfer-encoding'] ?? '') === 'base64' ? (string) base64_decode($p['body']) : $p['body'];
            }
        }
    } else {
        $data = (string) base64_decode(preg_replace('/\s+/', '', $el->textContent), true);
    }
    if (str_starts_with($data, "PK\x03\x04") && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'rejzip');
        file_put_contents($tmp, $data);
        $zip = new ZipArchive();
        $data = '';
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles && $data === ''; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.xml')) {
                    $data = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
        @unlink($tmp);
    }
    return $data;
}

// ---------- Čtení výpisu LV ----------

/** Přímé podřízené prvky podle jména. */
function rej_children(DOMNode $n, string $name): array
{
    $out = [];
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === $name) {
            $out[] = $c;
        }
    }
    return $out;
}
function rej_child_text(DOMNode $n, string $name): string
{
    return ($c = rej_children($n, $name)[0] ?? null) ? trim($c->textContent) : '';
}
/** Zlomek z prvku s citatel/jmenovatel (zkrácený), prázdný = ''. */
function rej_fraction(?DOMElement $el): string
{
    if (!$el) {
        return '';
    }
    $num = rej_child_text($el, 'citatel');
    $den = rej_child_text($el, 'jmenovatel');
    if (!ctype_digit($num) || !ctype_digit($den) || (int) $den === 0) {
        return '';
    }
    $g = gcd_int((int) $num, (int) $den);
    return intdiv((int) $num, $g) . '/' . intdiv((int) $den, $g);
}
/** Jméno oprávněného tak, jak ho píše Nahlížení do KN („Novák Petr“, „SJM Novák Petr a Nováková Marie“, název firmy). */
function rej_opsub_name(DOMElement $v): string
{
    foreach (['OPSUB_NAZEV', 'OPSUB_IDENT'] as $tag) {
        foreach (rej_children($v, $tag) as $o) {
            if (($n = rej_child_text($o, 'nazev')) !== '') {
                return $n;
            }
            if (($p = rej_child_text($o, 'prijmeni')) !== '') {
                return trim($p . ' ' . rej_child_text($o, 'jmeno'));
            }
            if (($p1 = rej_child_text($o, 'bsm1_prijmeni')) !== '') {
                return trim('SJM ' . $p1 . ' ' . rej_child_text($o, 'bsm1_jmeno') . ' a ' . rej_child_text($o, 'bsm2_prijmeni') . ' ' . rej_child_text($o, 'bsm2_jmeno'));
            }
        }
    }
    foreach ($v->getElementsByTagName('oprav_subjekt') as $o) {   // VLA_IDENT/oprav_subjekt
        if (($n = rej_child_text($o, 'nazev')) !== '') {
            return $n;
        }
        if (($p = rej_child_text($o, 'prijmeni')) !== '') {
            return trim($p . ' ' . rej_child_text($o, 'jmeno'));
        }
    }
    return '';
}
/**
 * Přečte výpis LV (VypisZKatastruNemovitosti.xsd). Vrací
 * ['lv' => číslo LV, 'units' => ['č.p./číslo' => ['cp', 'number', 'share', 'owners' => [['name', 'share']], 'lv']], 'plomby' => [text]].
 */
function rej_katastr_parse_lv(string $xml): array
{
    $dom = new DOMDocument();
    if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET)) {
        return ['lv' => '', 'units' => [], 'plomby' => [], 'error' => 'Výpis LV se nepodařilo přečíst.'];
    }
    $out = ['lv' => '', 'units' => [], 'plomby' => [], 'error' => ''];
    $out['lv'] = trim((string) $dom->getElementsByTagNameNS('*', 'CISLO_LV')->item(0)?->textContent);
    foreach ($dom->getElementsByTagNameNS('*', 'JEDNOTKA') as $j) {
        $num = rej_children($j, 'CISLO_JEDNOTKY')[0] ?? null;
        if (!$num) {
            continue;
        }
        $ident = '';
        $cp = '';
        foreach ($num->getElementsByTagName('jednotka') as $jt) {
            $ident = rej_child_text($jt, 'jed_ident');
            $cp = preg_replace('/\D/', '', rej_child_text($jt, 'cp_ident'));
        }
        if ($ident === '') {
            $ident = trim($num->textContent);
        }
        if (preg_match('~(\d+)\s*/\s*(\w+)~u', $ident, $m)) {
            [$cp, $number] = [$m[1], $m[2]];
        } else {
            $number = preg_replace('/\s+/', '', $ident);
        }
        if ($number === '') {
            continue;
        }
        $owners = [];
        foreach (rej_children($j, 'VLASTNICTVI') as $v) {
            $name = rej_opsub_name($v);
            if ($name !== '') {
                $owners[] = ['name' => $name, 'share' => rej_fraction(rej_children($v, 'PODIL')[0] ?? null) ?: '1/1'];
            }
        }
        $key = ($cp !== '' ? $cp : '0') . '/' . $number;
        $unit = $out['units'][$key] ?? ['cp' => $cp, 'number' => $number, 'share' => '', 'owners' => [], 'lv' => ''];
        $unit['share'] = rej_fraction(rej_children($j, 'PODIL_BA')[0] ?? null) ?: $unit['share'];
        $unit['owners'] = $owners ?: $unit['owners'];
        $naLv = rej_children($j, 'NA_LV')[0] ?? null;
        $unit['lv'] = $naLv ? (rej_child_text($naLv, 'cislo_tel') ?: trim($naLv->textContent)) : $unit['lv'];
        if (($pl = rej_child_text($j, 'PLOMBA_JEDNOTKY')) !== '') {
            $out['plomby'][] = 'Jednotka ' . $key . ': ' . $pl;
        }
        $out['units'][$key] = $unit;
    }
    foreach ($dom->getElementsByTagNameNS('*', 'PLOMBA_BUDOVY') as $p) {
        if (trim($p->textContent) !== '') {
            $out['plomby'][] = 'Budova: ' . trim($p->textContent);
        }
    }
    foreach ($dom->getElementsByTagNameNS('*', 'ZMENA_PV') as $z) {
        foreach (rej_children($z, 'PLOMBA') as $p) {
            $t = rej_child_text($p, 'typriz_kod');
            if ($t !== '') {
                $out['plomby'][] = 'Řízení ' . $t . '-' . rej_child_text($p, 'poradove_cislo') . '/' . rej_child_text($p, 'rok') . '-' . rej_child_text($p, 'prares_kod');
            }
        }
    }
    $out['plomby'] = array_values(array_unique($out['plomby']));
    ksort($out['units'], SORT_NATURAL);
    return $out;
}

// ---------- Porovnání s evidencí ----------

/** Text vlastníků do pole „Vlastník dle evidence“ (stejně jako import z katastru). */
function rej_owner_note(array $owners): string
{
    return mb_substr(implode('; ', array_map(fn($o) => $o['name'] . (count($owners) > 1 ? ' (' . $o['share'] . ')' : ''), $owners)), 0, 255);
}
/** Vlastníci z textu „Vlastník dle evidence“: [['name', 'share']]. */
function rej_owners_from_note(string $note): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(';', $note))) as $part) {
        $share = preg_match('~\((\d+\s*/\s*\d+)\)\s*$~', $part, $m) ? preg_replace('/\s+/', '', $m[1]) : '';
        $out[] = ['name' => trim(preg_replace('~\(\d+\s*/\s*\d+\)\s*$~', '', $part)), 'share' => $share];
    }
    return $out;
}
/** Porovnatelný tvar jména (bez velikosti písmen, titulů, interpunkce a pořadí slov). */
function rej_name_key(string $name): string
{
    $s = mb_strtolower($name);
    $s = preg_replace('/\b(ing|mgr|bc|judr|mudr|mvdr|phdr|rndr|paeddr|doc|prof|csc|phd|dis|mba|arch)\.?(?=\s|,|$)/u', ' ', $s);
    $words = array_filter(preg_split('/[\s,.;()]+/u', $s), fn($w) => $w !== '' && $w !== 'a');
    sort($words);
    return implode(' ', $words);
}
function rej_owner_set(array $owners): array
{
    $keys = array_map(fn($o) => rej_name_key($o['name']), $owners);
    sort($keys);
    return $keys;
}
/** Zlomek ve zkráceném tvaru pro porovnání ('' když není). */
function rej_fraction_norm(string $f): string
{
    if (!preg_match('~^\s*(\d+)\s*/\s*(\d+)\s*$~', $f, $m) || (int) $m[2] === 0) {
        return '';
    }
    $g = gcd_int((int) $m[1], (int) $m[2]);
    return intdiv((int) $m[1], $g) . '/' . intdiv((int) $m[2], $g);
}
/** Vchod v evidenci pro jednotky z katastru (čísla popisná → vchody A, B…, nebo vchod = č.p. jako u importu z katastru). */
function rej_katastr_entrances(array $units): array
{
    $cps = array_values(array_unique(array_map(fn($u) => (int) $u['cp'], $units)));
    sort($cps);
    $letters = array_values(array_filter(entrances()));
    $byCp = count($cps) > max(1, count($letters));
    $out = [];
    foreach ($units as $key => $u) {
        $pos = array_search((int) $u['cp'], $cps, true);
        $out[$key] = $byCp ? (string) $u['cp'] : ($letters[$pos] ?? '');
    }
    return $out;
}
/** Jednotky z katastru → id jednotky v evidenci (0 = nenalezena). */
function rej_katastr_map(array $units): array
{
    $index = [];
    foreach (all('SELECT id, entrance, number FROM units') as $u) {
        $index[mb_strtoupper($u['entrance']) . '|' . $u['number']] = (int) $u['id'];
    }
    $map = [];
    foreach (rej_katastr_entrances($units) as $key => $entrance) {
        $number = $units[$key]['number'];
        $map[$key] = $index[mb_strtoupper($entrance) . '|' . $number] ?? ($index['|' . $number] ?? 0);
    }
    return $map;
}
/** Výchozí stav z evidence jednotek, ve tvaru jako výpis (pro první porovnání). */
function rej_katastr_baseline_from_units(array $fetched): array
{
    $map = rej_katastr_map($fetched);
    $units = [];
    foreach (all('SELECT * FROM units') as $u) {
        $key = array_search((int) $u['id'], $map, true);
        if ($key === false) {
            continue;   // jednotka, kterou katastr nezná – neřešíme (může jít o vlastní označení)
        }
        $units[$key] = ['cp' => $fetched[$key]['cp'], 'number' => $fetched[$key]['number'], 'share' => (string) $u['share'], 'owners' => rej_owners_from_note((string) $u['owner_note']), 'lv' => ''];
    }
    return ['units' => $units, 'plomby' => [], 'from_units' => true];
}
/** Rozdíly mezi dvěma stavy: [['unit_key', 'kind', 'old', 'new', 'data']]. */
function rej_katastr_diff(array $base, array $now): array
{
    $changes = [];
    foreach ($now['units'] as $key => $u) {
        $b = $base['units'][$key] ?? null;
        if (!$b) {
            if (empty($base['from_units'])) {
                $changes[] = ['unit_key' => $key, 'kind' => 'new_unit', 'old' => '', 'new' => rej_owner_note($u['owners']), 'data' => $u];
            }
            continue;
        }
        if ($u['owners'] && rej_owner_set($u['owners']) !== rej_owner_set($b['owners'])) {
            $changes[] = ['unit_key' => $key, 'kind' => 'owner', 'old' => rej_owner_note($b['owners']), 'new' => rej_owner_note($u['owners']), 'data' => $u];
        } elseif ($u['owners'] && count($u['owners']) > 1 && empty($base['from_units'])
            && array_map(fn($o) => rej_fraction_norm($o['share']), $u['owners']) !== array_map(fn($o) => rej_fraction_norm($o['share']), $b['owners'])) {
            $changes[] = ['unit_key' => $key, 'kind' => 'owner', 'old' => rej_owner_note($b['owners']), 'new' => rej_owner_note($u['owners']), 'data' => $u];   // změna podílů spoluvlastníků
        }
        if ($u['share'] !== '' && rej_fraction_norm($u['share']) !== rej_fraction_norm((string) $b['share'])) {
            $changes[] = ['unit_key' => $key, 'kind' => 'share', 'old' => (string) $b['share'], 'new' => $u['share'], 'data' => $u];
        }
    }
    if (empty($base['from_units'])) {
        foreach ($base['units'] as $key => $b) {
            if (!isset($now['units'][$key])) {
                $changes[] = ['unit_key' => $key, 'kind' => 'gone_unit', 'old' => rej_owner_note($b['owners']), 'new' => '', 'data' => $b];
            }
        }
    }
    foreach (array_diff($now['plomby'], $base['plomby'] ?? []) as $p) {
        $changes[] = ['unit_key' => preg_match('~^Jednotka (\S+):~u', $p, $m) ? $m[1] : '', 'kind' => 'plomba', 'old' => '', 'new' => $p, 'data' => []];
    }
    return $changes;
}

// ---------- Stažení a kontrola ----------

/**
 * Stáhne výpis jednoho LV: zařadí sestavu (generujLVZjednodusene), počká a odebere ji (vratSestavu).
 * Vrací ['ok', 'xml', 'pending' => id sestavy, která ještě není hotová, 'cena', 'error'].
 */
function rej_katastr_fetch_lv(string $ku, string $lv, string $pendingId = ''): array
{
    $id = $pendingId;
    $cena = 0;
    if ($id === '') {
        $gen = rej_wsdp_call('sestavy', 'generujLVZjednodusene', 'GenerujLVZjednoduseneRequest', ['katastrUzemiKod' => $ku, 'lvCislo' => $lv, 'format' => 'xml']);
        $rep = $gen['ok'] ? rej_wsdp_report($gen) : null;
        if (!$rep || $rep['id'] === '') {
            return ['ok' => false, 'xml' => '', 'pending' => '', 'cena' => 0, 'error' => $gen['error'] !== '' ? $gen['error'] : 'Katastr nevrátil číslo sestavy.'];
        }
        $id = $rep['id'];
        $cena = $rep['cena'];
    }
    for ($try = 0; $try < 4; $try++) {
        rej_sleep($try === 0 ? 3 : 5);
        $ret = rej_wsdp_call('sestavy', 'vratSestavu', 'VratSestavuRequest', ['idSestavy' => $id]);
        $rep = rej_wsdp_report($ret);
        if ($rep && $rep['content'] !== '') {
            return ['ok' => true, 'xml' => $rep['content'], 'pending' => '', 'cena' => $rep['cena'] ?: $cena, 'error' => ''];
        }
        if ($rep && str_starts_with($rep['stav'], 'chyba')) {
            return ['ok' => false, 'xml' => '', 'pending' => '', 'cena' => 0, 'error' => 'Katastr sestavu nevytvořil (' . $rep['stav'] . ').'];
        }
        if (!$ret['ok'] && !in_array(526, array_column($ret['messages'], 0), true) && !$rep) {
            return ['ok' => false, 'xml' => '', 'pending' => $id, 'cena' => $cena, 'error' => $ret['error']];
        }
    }
    return ['ok' => false, 'xml' => '', 'pending' => $id, 'cena' => $cena, 'error' => ''];
}
/**
 * Kontrola katastru: stáhne LV budovy, porovná s minulým stavem (napoprvé s evidencí jednotek), zapíše změny a pošle e-mail správcům.
 * Vrací ['ok' => bool, 'new' => počet nových změn, 'pending' => bool, 'error' => text].
 */
function rej_katastr_run(bool $notify = true): array
{
    rej_ready();
    $t = rej_katastr_target();
    if ($t['ku'] === '' || !$t['lv']) {
        return ['ok' => false, 'new' => 0, 'pending' => false, 'error' => 'Doplňte kód katastrálního území a číslo LV budovy.'];
    }
    $pending = (array) json_decode(setting('rej_katastr_pending'), true);
    $now = ['units' => [], 'plomby' => []];
    $stillPending = [];
    $error = '';
    foreach ($t['lv'] as $lv) {
        $r = rej_katastr_fetch_lv($t['ku'], $lv, (string) ($pending[$lv] ?? ''));
        if ($r['pending'] !== '') {
            $stillPending[$lv] = $r['pending'];
        }
        if (!$r['ok']) {
            $error = $r['error'];
            continue;
        }
        rej_usage_add('katastr_lv', 1, 0, $r['cena'] ?: rej_price('rej_cost_katastr_lv'), 'LV ' . $lv . ', k.ú. ' . $t['ku']);
        $p = rej_katastr_parse_lv($r['xml']);
        if ($p['error'] !== '') {
            $error = $p['error'];
            continue;
        }
        $now['units'] += $p['units'];
        $now['plomby'] = array_values(array_unique([...$now['plomby'], ...$p['plomby']]));
    }
    rej_set('rej_katastr_pending', $stillPending ? json_encode($stillPending) : '');
    if ($stillPending && $error === '') {
        rej_set('rej_katastr_last', date('Y-m-d H:i') . '|pending|katastr výpis ještě připravuje, dokončí se při další kontrole');
        return ['ok' => true, 'new' => 0, 'pending' => true, 'error' => ''];
    }
    if ($error !== '' || !$now['units']) {
        $error = $error !== '' ? $error : 'Ve výpisu LV nejsou žádné jednotky – zkontrolujte číslo LV budovy.';
        rej_set('rej_katastr_last', date('Y-m-d H:i') . '|chyba|' . $error);
        return ['ok' => false, 'new' => 0, 'pending' => (bool) $stillPending, 'error' => $error];
    }
    $base = (array) json_decode(setting('rej_katastr_base'), true);
    $base = isset($base['units']) ? $base : rej_katastr_baseline_from_units($now['units']);
    $all = $now['units'] + ($base['units'] ?? []);
    $map = rej_katastr_map($all);
    $entr = rej_katastr_entrances($all);
    $new = [];
    foreach (rej_katastr_diff($base, $now) as $c) {
        $dup = val("SELECT id FROM rej_katastr_changes WHERE status = 'new' AND unit_key = ? AND kind = ? AND new_value = ?", [$c['unit_key'], $c['kind'], $c['new']]);
        if ($dup) {
            continue;
        }
        q('INSERT INTO rej_katastr_changes (found_at, unit_key, unit_id, kind, old_value, new_value, data, status) VALUES (?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), $c['unit_key'], $map[$c['unit_key']] ?? 0, $c['kind'], $c['old'], $c['new'], json_encode($c['data'] + ($c['data'] ? ['entrance' => $entr[$c['unit_key']] ?? ''] : []), JSON_UNESCAPED_UNICODE), 'new']);
        $new[] = $c;
    }
    rej_set('rej_katastr_base', json_encode($now + ['at' => date('Y-m-d H:i')], JSON_UNESCAPED_UNICODE));
    rej_set('rej_katastr_last', date('Y-m-d H:i') . '|ok|jednotek ' . count($now['units']) . ', nových změn ' . count($new));
    if ($notify && $new) {
        $lines = array_map(fn($c) => '- ' . REJ_CHANGE_KINDS[$c['kind']] . ($c['unit_key'] !== '' ? ' (jednotka ' . $c['unit_key'] . ')' : '') . ': '
            . ($c['old'] !== '' ? $c['old'] . ' → ' : '') . ($c['new'] !== '' ? $c['new'] : 'není ve výpisu'), $new);
        $text = "Dobrý den,\n\nv katastru nemovitostí se u domu objevily změny:\n\n" . implode("\n", $lines)
            . "\n\nZměny si prohlédněte a převezměte do evidence jednotek: " . absolute_url('/admin/rejstriky/katastr') . "\n";
        foreach (rej_admin_emails() as $to) {
            send_mail($to, 'Změny v katastru – ' . site('name'), $text);
        }
    }
    return ['ok' => true, 'new' => count($new), 'pending' => false, 'error' => ''];
}
/**
 * Zapne sledování (správce odsouhlasil cenu). Dům s předplatným: doplněk k předplatnému (výzva k platbě za zbytek období);
 * jinak se roční poplatek jako dosud zapíše do spotřeby. Vrací roční cenu (Kč bez DPH).
 */
function rej_katastr_activate(): int
{
    $lv = rej_katastr_lv_count();
    $price = rej_katastr_year_price($lv);
    rej_set('rej_katastr_on', date('Y-m-d'));
    rej_set('rej_katastr_year', date('Y-m-d'));
    if (rej_katastr_billed()) {
        addon_set(tenant_row(TENANT), 'katastr', $lv);
    } else {
        rej_usage_add('katastr_year', $lv, $price, 0, 'zapnutí sledování');
    }
    return $price;
}
/** Výzva k platbě za sledování za zbytek období (po zapnutí), nebo null. */
function rej_katastr_payment(): ?array
{
    return rej_katastr_billed() ? addon_payment(tenant_row(TENANT), 'katastr') : null;
}
/** Doplněk se platí předem: když výzva k platbě zůstane nezaplacená déle než 30 dní, kontroly se pozastaví (náklad ČÚZK by nesl provozovatel). */
const REJ_KATASTR_GRACE_DAYS = 30;
function rej_katastr_unpaid(?array $payment = null): bool
{
    $payment ??= rej_katastr_payment();
    return $payment !== null && $payment['status'] === 'pending' && (string) $payment['created_at'] < date('Y-m-d H:i:s', time() - REJ_KATASTR_GRACE_DAYS * 86400);
}
/** Správce změnil čísla LV: u zapnutého sledování se přepočítá doplněk (navýšení se doplatí poměrně). */
function rej_katastr_target_changed(): void
{
    if (setting('rej_katastr_on') !== '' && rej_katastr_billed()) {
        addon_set(tenant_row(TENANT), 'katastr', rej_katastr_lv_count());
    }
}
function rej_katastr_deactivate(): void
{
    rej_set('rej_katastr_on', '');
    rej_set('rej_katastr_pending', '');
    if (function_exists('plan_managed') && plan_managed() && ($t = tenant_row(TENANT))) {
        addon_stop($t, 'katastr');
    }
}
/** Denně: kontrola podle nastaveného intervalu (výchozí 30 dní); u domu bez předplatného roční poplatek do spotřeby. */
function rej_katastr_daily(): void
{
    if (!rej_allowed('katastr') || !rej_katastr_configured() || setting('rej_katastr_on') === '') {
        return;
    }
    $year = setting('rej_katastr_year');
    if ($year !== '' && $year <= date('Y-m-d', strtotime('-1 year'))) {
        if (!rej_katastr_billed()) {
            $lv = rej_katastr_lv_count();
            rej_usage_add('katastr_year', $lv, rej_katastr_year_price($lv), 0, 'prodloužení sledování');
        }
        rej_set('rej_katastr_year', date('Y-m-d'));
    }
    if (rej_katastr_unpaid()) {
        return;   // pozastaveno do zaplacení
    }
    $last = substr(setting('rej_katastr_last'), 0, 10);
    $days = max(1, rej_price('rej_katastr_days'));
    if (setting('rej_katastr_pending') === '' && $last !== '' && $last > date('Y-m-d', time() - $days * 86400)) {
        return;
    }
    rej_katastr_run();
}
/** Převzetí změny do evidence ('apply') nebo jen vzetí na vědomí ('dismiss'). Vrací hlášku pro správce. */
function rej_katastr_handle(int $id, string $action, string $by): string
{
    rej_ready();
    $c = one("SELECT * FROM rej_katastr_changes WHERE id = ? AND status = 'new'", [$id]);
    if (!$c) {
        return 'Změna už je vyřízená.';
    }
    $msg = 'Vzato na vědomí.';
    if ($action === 'apply') {
        $data = (array) json_decode((string) $c['data'], true);
        $unitId = (int) $c['unit_id'];
        switch ($c['kind']) {
            case 'owner':
                if (!$unitId) {
                    return 'Jednotku ' . $c['unit_key'] . ' web v evidenci nenašel – upravte ji ručně v Jednotkách.';
                }
                q('UPDATE units SET owner_note = ? WHERE id = ?', [(string) $c['new_value'], $unitId]);
                // Údaje bývalého vlastníka pro hlídání insolvence se mažou (minimalizace osobních údajů).
                $gone = 0;
                foreach (all('SELECT id FROM rej_persons WHERE unit_id = ?', [$unitId]) as $p) {
                    $gone += (int) rej_person_delete((int) $p['id']);
                }
                $msg = 'Vlastník jednotky je převzatý do evidence.' . ($gone ? ' Údaje bývalého vlastníka pro hlídání insolvence se smazaly.' : '');
                break;
            case 'share':
                if (!$unitId) {
                    return 'Jednotku ' . $c['unit_key'] . ' web v evidenci nenašel – upravte ji ručně v Jednotkách.';
                }
                q('UPDATE units SET share = ? WHERE id = ?', [(string) $c['new_value'], $unitId]);
                $msg = 'Podíl jednotky je převzatý do evidence.';
                break;
            case 'new_unit':
                $entrance = (string) ($data['entrance'] ?? '');
                $number = (string) ($data['number'] ?? '');
                if ($number === '' || val('SELECT id FROM units WHERE entrance = ? AND number = ?', [$entrance, $number])) {
                    $msg = 'Jednotka už v evidenci je.';
                } else {
                    q('INSERT INTO units (entrance, number, share, owner_note) VALUES (?,?,?,?)', [$entrance, $number, (string) ($data['share'] ?? ''), (string) $c['new_value']]);
                    $msg = 'Jednotka je založená v evidenci – zkontrolujte vchod a patro v Jednotkách.';
                }
                break;
            default:
                $msg = 'Vzato na vědomí – v evidenci se nic nemění.';
        }
        audit('Katastr: převzetí změny', REJ_CHANGE_KINDS[$c['kind']] . ' ' . $c['unit_key'] . ': ' . mb_substr((string) $c['new_value'], 0, 200));
    } else {
        audit('Katastr: změna vzata na vědomí', REJ_CHANGE_KINDS[$c['kind']] . ' ' . $c['unit_key']);
    }
    q('UPDATE rej_katastr_changes SET status = ?, handled_at = ?, handled_by = ? WHERE id = ?', [$action === 'apply' ? 'applied' : 'dismissed', date('Y-m-d H:i:s'), $by, $id]);
    return $msg;
}

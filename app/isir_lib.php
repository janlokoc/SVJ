<?php
declare(strict_types=1);

/*
 * Hlídání insolvencí vlastníků-firem (insolvenční rejstřík ISIR, veřejná webová služba Ministerstva spravedlnosti, zdarma).
 *
 * - Hlídají se jen vlastníci s IČO: fyzické osoby rejstřík dohledává podle rodného čísla nebo data narození,
 *   která web nemá, a samotné jméno by dávalo falešné shody.
 * - IČO se bere z textu u jednotky (vlastník dle evidence, adresa pro doručování), nebo ho výbor doplní.
 * - Jednou týdně se ověří všechna IČO domu; nové řízení přijde správcům e-mailem a ukáže se v administraci.
 * - Celé je zapínané provozovatelem (Nastavení služby → Insolvenční rejstřík) až po zkoušce spojení z hostingu.
 *   Adresa služby jde v config.php přepsat (isir.url).
 */

const ISIR_URL = 'https://isir.justice.cz:8443/isir_cuzk_ws/IsirWsCuzkService';
const ISIR_COMPANY = '/(?<!\p{L})(s\.\s?r\.\s?o\.|spol\.\s?s\s?r\.\s?o\.|a\.\s?s\.|v\.\s?o\.\s?s\.|k\.\s?s\.|z\.\s?s\.|z\.\s?ú\.|o\.\s?p\.\s?s\.|družstvo|společnost|nadace|obec|město|statutární město|kraj|česká republika|svj|společenství vlastníků)(?!\p{L})/iu';

function isir_enabled(): bool
{
    return function_exists('beta_stage') && beta_stage('insolvence') !== 'off';
}
/** Dotaz do insolvenčního rejstříku podle IČO: ['ok' => bool, 'found' => [řízení], 'error' => string, 'raw' => začátek odpovědi]. */
function isir_check_ico(string $ico, bool $activeOnly = true): array
{
    $ico = preg_replace('/\D/', '', $ico);
    if (strlen($ico) !== 8) {
        return ['ok' => false, 'found' => [], 'error' => 'IČO musí mít 8 číslic.', 'raw' => ''];
    }
    $url = (string) (app_config()['isir']['url'] ?? ISIR_URL);
    $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:typ="http://isirws.cca.cz/types/"><soapenv:Body>'
        . '<typ:getIsirWsCuzkDataRequest><ic>' . $ico . '</ic><maxPocetVysledku>50</maxPocetVysledku><filtrAktualniRizeni>' . ($activeOnly ? 'T' : 'F') . '</filtrAktualniRizeni></typ:getIsirWsCuzkDataRequest>'
        . '</soapenv:Body></soapenv:Envelope>';
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 20, 'ignore_errors' => true,
        'header' => "Content-Type: text/xml; charset=utf-8\r\nSOAPAction: \"\"\r\n", 'content' => $xml]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    if ($body === false || $status === 0) {
        $err = error_get_last()['message'] ?? '';
        return ['ok' => false, 'found' => [], 'error' => 'Insolvenční rejstřík neodpovídá (spojení se nepodařilo' . ($err !== '' ? ': ' . mb_substr($err, 0, 160) : '') . ').', 'raw' => ''];
    }
    $raw = mb_substr($body, 0, 3000);
    $dom = new DOMDocument();
    if (!@$dom->loadXML($body, LIBXML_NONET)) {
        return ['ok' => false, 'found' => [], 'error' => 'Odpověď (HTTP ' . $status . ') není XML.', 'raw' => $raw];
    }
    $fault = $dom->getElementsByTagNameNS('*', 'faultstring')->item(0);
    if ($fault) {
        return ['ok' => false, 'found' => [], 'error' => 'Rejstřík vrátil chybu: ' . trim($fault->textContent), 'raw' => $raw];
    }
    $found = [];
    foreach ($dom->getElementsByTagNameNS('*', 'data') as $node) {
        $row = [];
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $row[$c->localName] = trim($c->textContent);
            }
        }
        if ($row) {
            $sz = trim(($row['cisloSenatu'] ?? '') . ' ' . ($row['druhVec'] ?? 'INS') . ' ' . ($row['bcVec'] ?? '') . '/' . ($row['rocnik'] ?? ''));
            $found[] = ['sz' => $sz, 'name' => $row['nazevOsoby'] ?? '', 'court' => $row['nazevOrganizace'] ?? '', 'state' => $row['druhStavKonkursu'] ?? '', 'url' => $row['urlDetailRizeni'] ?? '', 'ico' => $row['ic'] ?? $ico];
        }
    }
    $code = $dom->getElementsByTagNameNS('*', 'kodChyby')->item(0);
    if ($code && !in_array(trim($code->textContent), ['', '0', 'OK', 'WS2'], true) && !$found) {
        // WS2 = nic nenalezeno (podle běžného chování služby); ostatní kódy ukážeme.
        $text = $dom->getElementsByTagNameNS('*', 'textChyby')->item(0);
        if (!preg_match('/nenalezen|nebyl|žádn/iu', (string) ($text?->textContent))) {
            return ['ok' => false, 'found' => [], 'error' => 'Rejstřík vrátil kód ' . trim($code->textContent) . ($text ? ': ' . trim($text->textContent) : ''), 'raw' => $raw];
        }
    }
    return ['ok' => $status < 400, 'found' => $found, 'error' => $status >= 400 ? 'HTTP ' . $status : '', 'raw' => $raw];
}
/** IČO v textu u jednotky (8 číslic s platným kontrolním součtem). */
function isir_ico_in(string $text): string
{
    // IČO bývá zapsané vcelku i po skupinách („27 082 440“, „270 82 440“, „000 64 581“).
    if (preg_match_all('/(?:IČO?|IC|ič)\s*[:.]?\s*(\d(?:\s?\d){7})(?!\d)/iu', $text, $m)) {
        foreach ($m[1] as $c) {
            $c = preg_replace('/\s/', '', $c);
            if (ares_ico($c) !== null) {
                return $c;
            }
        }
    }
    return '';
}
/** Jednotky s vlastníkem-firmou: [unit_id => ['unit' => řádek, 'name' => vlastník, 'ico' => IČO nebo '', 'source' => 'text'|'manual'|'']]. */
function isir_company_units(): array
{
    $manual = (array) json_decode(setting('isir_ico'), true);
    $out = [];
    foreach (all('SELECT * FROM units ORDER BY entrance, number + 0, number') as $u) {
        $text = $u['owner_note'] . "\n" . $u['owner_contact'];
        $ico = isset($manual[$u['id']]) ? (string) $manual[$u['id']] : isir_ico_in($text);
        $company = preg_match(ISIR_COMPANY, $u['owner_note']) || $ico !== '';
        if ($company) {
            $out[(int) $u['id']] = ['unit' => $u, 'name' => $u['owner_note'], 'ico' => $ico, 'source' => isset($manual[$u['id']]) ? 'manual' : ($ico !== '' ? 'text' : '')];
        }
    }
    return $out;
}
/** Ověří všechna IČO domu; nová řízení pošle správcům. Vrací [ověřeno, nalezeno, chyba]. */
function isir_run(bool $notify = true): array
{
    $results = (array) json_decode(setting('isir_results'), true);
    $checked = $hits = 0;
    $error = '';
    $new = [];
    foreach (isir_company_units() as $uid => $c) {
        if ($c['ico'] === '') {
            continue;
        }
        $r = isir_check_ico($c['ico']);
        if (!$r['ok']) {
            $error = $r['error'];
            break;   // služba nejde – zkusíme to příště, výsledky neměníme
        }
        $checked++;
        $before = array_column($results[$c['ico']]['found'] ?? [], 'sz');
        foreach ($r['found'] as $f) {
            $hits++;
            if (!in_array($f['sz'], $before, true)) {
                $new[] = ['unit' => unit_name($c['unit']), 'owner' => $c['name'], 'ico' => $c['ico']] + $f;
            }
        }
        $results[$c['ico']] = ['checked' => date('Y-m-d H:i'), 'found' => $r['found']];
    }
    q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', ['isir_results', json_encode($results, JSON_UNESCAPED_UNICODE)]);
    q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', ['isir_last', date('Y-m-d H:i') . '|' . ($error === '' ? 'ok' : 'chyba') . '|' . ($error !== '' ? $error : "ověřeno $checked, řízení $hits")]);
    if ($notify && $new) {
        $text = "Dobrý den,\n\nv insolvenčním rejstříku se objevilo řízení u vlastníka jednotky:\n\n" . implode("\n\n", array_map(fn($n) => $n['unit'] . ' – ' . $n['owner'] . ' (IČO ' . $n['ico'] . ")\n"
            . 'Spisová značka: ' . $n['sz'] . ($n['court'] !== '' ? ', ' . $n['court'] : '') . ($n['state'] !== '' ? "\nStav: " . $n['state'] : '') . ($n['url'] !== '' ? "\nDetail: " . $n['url'] : ''), $new))
            . "\n\nPohledávku (dlužné zálohy) je potřeba přihlásit do insolvenčního řízení včas – zpravidla do dvou měsíců od rozhodnutí o úpadku. Poraďte se s účetní nebo právníkem.\n\nPřehled: " . absolute_url('/admin/insolvence');
        foreach (all("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $a) {
            send_mail($a['email'], 'Insolvence vlastníka jednotky – ' . site('name'), $text);
        }
    }
    return [$checked, $hits, $error];
}
/** Denně: jednou týdně ověří IČO vlastníků (jen v edici Premium a když provozovatel hlídání zapnul). */
function isir_daily(): void
{
    if (!isir_enabled() || (function_exists('plan_managed') && plan_managed() && EDITION_RANK[plan_edition()] < EDITION_RANK['premium'])) {
        return;
    }
    $last = substr(setting('isir_last'), 0, 10);
    if ($last !== '' && $last > date('Y-m-d', time() - 6 * 86400)) {
        return;
    }
    if (!array_filter(isir_company_units(), fn($c) => $c['ico'] !== '')) {
        return;
    }
    isir_run();
}

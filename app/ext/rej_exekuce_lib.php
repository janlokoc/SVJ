<?php
declare(strict_types=1);

/*
 * Modul E – lustrace vlastníka v Centrální evidenci exekucí (CEE, vede Exekutorská komora ČR), placený dotaz na kliknutí správce
 * (cena rej_price_exekuce, výchozí 99 Kč bez DPH, platí se z kreditu služeb – app/ext/credit_lib.php – až po úspěšném dotazu).
 *
 * Dokumentace API (veřejná, pro vývojáře): https://www.ceecr.cz/dev → „API reference“ (verze 4):
 *  - GET {url}/auth?api_key=…&api_secret=… → token_value, valid_till (token platí jen pro IP adresu, ze které o něj server požádal);
 *  - GET {url}/search?token=…&name=Jan%20Novák&birth=2000-01-01&person=F  (fyzická osoba: jméno + datum narození),
 *    GET {url}/search?token=…&ico=12345678&person=P (právnická osoba);
 *    odpověď: data[] (kauzy: ex_cislo, datum_zapisu, posledni_uprava, date_vydano, subjects[] …), info.cena.zaplaceno;
 *  - chyby 5501–5508 (chybí/neplatný klíč, token neplatný nebo pro jinou IP, vypršel).
 *  Cena: neregistrovaný dotaz 60 Kč za výpis; registrovaný účet s předplaceným kreditem podle ceníku CEE (sestupně podle počtu dotazů).
 *
 * NEOVĚŘENO PROTI SKUTEČNÉ SLUŽBĚ (potřebuje registrovaný účet a API klíč): testováno na vzorových odpovědích z dokumentace.
 * Údaje v storage/config.php: 'exekuce' => ['api_key' => '…', 'api_secret' => '…', 'url' => 'https://www.ceecr.cz/api/v4'].
 * Ukládá se jen souhrn kauz (číslo, data, poslední úkon) – ne údaje dalších povinných ani rodná čísla.
 */

const REJ_CEE_URL = 'https://www.ceecr.cz/api/v4';

function rej_cee_config(): array
{
    $c = (array) ($GLOBALS['REJ_CONFIG']['exekuce'] ?? app_config()['exekuce'] ?? []);   // REJ_CONFIG: podstrčení v testech
    return ['key' => (string) ($c['api_key'] ?? ''), 'secret' => (string) ($c['api_secret'] ?? ''), 'url' => rtrim((string) ($c['url'] ?? REJ_CEE_URL), '/')];
}
function rej_cee_configured(): bool
{
    $c = rej_cee_config();
    return $c['key'] !== '' && $c['secret'] !== '';
}
/** Chyba z odpovědi CEE (různé tvary: error{code,message}, message, errors[]), nebo ''. */
function rej_cee_error(?array $j, int $status): string
{
    if ($j === null) {
        return 'Odpověď evidence exekucí (HTTP ' . $status . ') není JSON.';
    }
    $err = $j['error'] ?? null;
    $code = is_array($err) ? (string) ($err['code'] ?? '') : (string) ($j['code'] ?? '');
    $msg = is_array($err) ? (string) ($err['message'] ?? '') : (is_string($err) ? $err : (string) ($j['message'] ?? ''));
    if ($msg === '' && isset($j['errors'])) {
        $msg = implode(' ', array_map(fn($e) => is_array($e) ? (string) ($e['message'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string) $e, (array) $j['errors']));
    }
    if ($msg === '' && $code === '' && $status < 400 && (int) ($j['status'] ?? 200) < 400) {
        return '';
    }
    return 'Evidence exekucí: ' . ($msg !== '' ? $msg : 'chyba') . ($code !== '' ? ' (kód ' . $code . ')' : '') . ($msg === '' && $code === '' ? ' (HTTP ' . $status . ')' : '');
}
/** Token pro API (uložený do konce platnosti); $fresh = vyžádat nový. */
function rej_cee_token(bool $fresh = false): array
{
    $c = rej_cee_config();
    if (!rej_cee_configured()) {
        return ['token' => '', 'error' => 'Evidence exekucí není nastavená (provozovatel doplní API klíč CEE).'];
    }
    $cached = (array) json_decode(function_exists('pset') && platform_enabled() ? pset('rej_cee_token') : '', true);
    if (!$fresh && ($cached['token'] ?? '') !== '' && ($cached['till'] ?? '') > date('Y-m-d H:i:s', time() + 60)) {
        return ['token' => $cached['token'], 'error' => ''];
    }
    $r = rej_http('GET', $c['url'] . '/auth?' . http_build_query(['api_key' => $c['key'], 'api_secret' => $c['secret']]), ['Accept: application/json'], '', 20);
    if ($r['status'] === 0) {
        return ['token' => '', 'error' => 'Evidence exekucí neodpovídá (' . $r['error'] . ').'];
    }
    $j = json_decode($r['body'], true);
    $j = is_array($j) ? $j : null;
    $d = is_array($j['data'] ?? null) ? $j['data'] : (array) $j;
    $token = (string) ($d['token_value'] ?? '');
    if ($token === '') {
        return ['token' => '', 'error' => rej_cee_error($j, $r['status']) ?: 'Evidence exekucí nevrátila token.'];
    }
    $till = strtotime((string) ($d['valid_till'] ?? '')) ?: time() + 600;
    if (function_exists('pset_save') && platform_enabled()) {
        pset_save('rej_cee_token', json_encode(['token' => $token, 'till' => date('Y-m-d H:i:s', $till)]));
    }
    return ['token' => $token, 'error' => ''];
}
/**
 * Dotaz do CEE. $kind 'F' = fyzická osoba ($name „Jméno Příjmení“, $id = datum narození RRRR-MM-DD), 'P' = firma ($id = IČO).
 * Vrací ['ok', 'causes' => [['ex_cislo', 'zapsano', 'uprava', 'vydano', 'urad']], 'paid' => text ceny, 'error'].
 */
function rej_cee_search(string $kind, string $name, string $id): array
{
    if ($kind === 'F' && (trim($name) === '' || !valid_date($id))) {
        return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => 'U fyzické osoby je potřeba jméno a datum narození.'];
    }
    if ($kind === 'P' && ares_ico($id) === null) {
        return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => 'IČO není platné.'];
    }
    $params = $kind === 'F' ? ['name' => trim($name), 'birth' => $id, 'person' => 'F'] : ['ico' => $id, 'person' => 'P'];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $tok = rej_cee_token($attempt > 0);
        if ($tok['error'] !== '') {
            return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => $tok['error']];
        }
        $r = rej_http('GET', rej_cee_config()['url'] . '/search?' . http_build_query(['token' => $tok['token']] + $params, '', '&', PHP_QUERY_RFC3986), ['Accept: application/json'], '', 30);
        if ($r['status'] === 0) {
            return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => 'Evidence exekucí neodpovídá (' . $r['error'] . ').'];
        }
        $j = json_decode($r['body'], true);
        $j = is_array($j) ? $j : null;
        $err = rej_cee_error($j, $r['status']);
        if ($err !== '' && $attempt === 0 && preg_match('/550[678]/', $err)) {
            continue;   // token neplatný / vypršel → nový token a znovu
        }
        if ($err !== '') {
            return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => $err];
        }
        return ['ok' => true, 'causes' => rej_cee_causes((array) ($j['data'] ?? [])), 'paid' => (string) ($j['info']['cena']['zaplaceno'] ?? ''), 'error' => ''];
    }
    return ['ok' => false, 'causes' => [], 'paid' => '', 'error' => 'Evidence exekucí odmítla přihlášení.'];
}
/** Souhrn kauz z odpovědi (bez údajů ostatních povinných). */
function rej_cee_causes(array $data): array
{
    $out = [];
    foreach ($data as $c) {
        if (!is_array($c)) {
            continue;
        }
        $out[] = ['ex_cislo' => (string) ($c['ex_cislo'] ?? ''), 'zapsano' => (string) ($c['datum_zapisu'] ?? $c['datetime_zalozeni'] ?? ''), 'uprava' => (string) ($c['posledni_uprava'] ?? ''),
            'vydano' => (string) ($c['date_vydano'] ?? $c['datum_soudniho_narizeni'] ?? ''), 'urad' => (string) ($c['jmeno_kancelare'] ?? '')];
    }
    return $out;
}
/** Koho jde lustrovat: osoby z hlídání insolvence (jméno + datum narození) a firmy s IČO. */
function rej_exekuce_subjects(): array
{
    $out = [];
    foreach (rej_persons() as $p) {
        $out['F' . $p['id']] = ['kind' => 'F', 'person_id' => (int) $p['id'], 'unit_id' => (int) $p['unit_id'], 'unit' => $p['number'] !== null ? unit_name($p) : '–',
            'label' => trim($p['first_name'] . ' ' . $p['surname']), 'id' => $p['birth_date']];
    }
    if (function_exists('isir_company_units')) {
        foreach (isir_company_units() as $uid => $c) {
            if ($c['ico'] !== '') {
                $out['P' . $uid] = ['kind' => 'P', 'person_id' => 0, 'unit_id' => (int) $uid, 'unit' => unit_name($c['unit']), 'label' => $c['name'], 'id' => $c['ico']];
            }
        }
    }
    return $out;
}
/** Cena lustrace v haléřích (z kreditu služeb). */
function rej_exekuce_price_h(): int
{
    return rej_price('rej_price_exekuce') * 100;
}
/** Stačí kredit služeb na lustraci? (Web mimo platformu kredit nepotřebuje.) */
function rej_exekuce_credit_ok(): bool
{
    return !function_exists('credit_managed') || !credit_managed() || credit_enough(TENANT, rej_exekuce_price_h());
}
/**
 * Lustrace jednoho vlastníka (správce potvrdil cenu). Bez dost kreditu se dotaz vůbec nepošle ('refused' => true).
 * Platí se z kreditu služeb až po úspěšném dotazu. Zapíše výsledek a spotřebu. Vrací uložený řádek.
 */
function rej_exekuce_check(array $subject, string $by): array
{
    rej_ready();
    if (!rej_exekuce_credit_ok()) {
        return ['refused' => true, 'found' => 0, 'price' => 0, 'error' => 'Kredit služeb nestačí na lustraci (' . credit_money(rej_exekuce_price_h()) . ', zůstatek ' . credit_money(credit_balance_h(TENANT)) . '). Dobijte ho v části Kredit služeb.'];
    }
    $r = rej_cee_search($subject['kind'], $subject['label'], $subject['id']);
    $price = $r['ok'] ? rej_price('rej_price_exekuce') : 0;
    q('INSERT INTO rej_exekuce (at, unit_id, person_id, subject, query_kind, found, result, price, user_name, error) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [date('Y-m-d H:i:s'), $subject['unit_id'], $subject['person_id'], mb_substr($subject['label'], 0, 191), $subject['kind'], count($r['causes']),
            $r['causes'] ? json_encode($r['causes'], JSON_UNESCAPED_UNICODE) : '', $price, mb_substr($by, 0, 191), mb_substr($r['error'], 0, 255)]);
    $id = (int) db()->lastInsertId();   // hned – audit() níže vkládá do téže databáze
    if ($r['ok'] && $price > 0 && function_exists('credit_managed') && credit_managed()
        && !credit_charge(TENANT, $price * 100, 'exekuce', 'Lustrace: ' . $subject['unit'] . ($subject['kind'] === 'P' ? ' · IČO ' . $subject['id'] : ''), 'exekuce:' . $id, 1, $by)) {
        // Kredit mezitím spotřebovala jiná služba: výsledek správce dostane, dotaz se nevyúčtuje (ztráta provozovatele).
        q('UPDATE rej_exekuce SET price = 0 WHERE id = ?', [$id]);
        $price = 0;
    }
    if ($r['ok']) {
        $cost = preg_match('/(\d+)/', $r['paid'], $m) ? (int) $m[1] : rej_price('rej_cost_exekuce');
        rej_usage_add('exekuce', 1, $price, $cost, ($subject['kind'] === 'F' ? 'fyzická osoba' : 'IČO ' . $subject['id']));
        audit('Exekuce: lustrace', $subject['unit'] . ' · ' . $subject['label'] . ' · kauz ' . count($r['causes']));
    }
    return one('SELECT * FROM rej_exekuce WHERE id = ?', [$id]) ?? [];
}
/** Poslední výsledek lustrace pro každý subjekt (klíč jako v rej_exekuce_subjects). */
function rej_exekuce_latest(): array
{
    rej_ready();
    $out = [];
    foreach (all('SELECT * FROM rej_exekuce ORDER BY id') as $r) {
        $key = $r['query_kind'] === 'F' ? 'F' . $r['person_id'] : 'P' . $r['unit_id'];
        $out[$key] = $r;
    }
    return $out;
}

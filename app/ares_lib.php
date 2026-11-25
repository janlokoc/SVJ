<?php
declare(strict_types=1);

/*
 * Údaje o společenství z ARES (Administrativní registr ekonomických subjektů, ares.gov.cz).
 * Veřejné rozhraní bez klíče: podle IČO vrátí název, sídlo a datum vzniku, podle názvu hledá mezi SVJ.
 * Posílá se jen to, co zakládající sám napíše (IČO nebo část názvu) – žádné osobní údaje.
 */

const ARES_SVJ_FORM = '145';   // právní forma „Společenství vlastníků jednotek“

function ares_base(): string
{
    return rtrim((string) (app_config()['ares']['base_url'] ?? 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest'), '/');
}

/** IČO: 8 číslic s kontrolní číslicí (modulo 11). Kratší doplní nulami zleva. */
function ares_ico(string $raw): ?string
{
    $ico = preg_replace('/\s+/', '', $raw);
    if (!preg_match('/^\d{1,8}$/', $ico)) {
        return null;
    }
    $ico = str_pad($ico, 8, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $sum += (int) $ico[$i] * (8 - $i);
    }
    $check = (11 - $sum % 11) % 10;
    return (int) $ico[7] === $check ? $ico : null;
}

/** Jeden požadavek na ARES; vrací dekódovaný JSON, nebo null (nedostupné, chyba, nenalezeno). */
function ares_request(string $path, ?array $body = null): ?array
{
    $url = ares_base() . $path;
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'web-spolecenstvi/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 8, 'ignore_errors' => true,
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '']]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = preg_match('~^HTTP/\S+ (\d{3})~', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
    }
    if ($raw === false || $code !== 200) {
        return $code === 404 ? [] : null;
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

/** Krátký název bez „Společenství vlastníků jednotek (domu|pro dům|v domě)“ – „Zahradní 12, Brno“. */
function ares_short_name(string $name): string
{
    $short = preg_replace('/^(Společenství\s+vlastníků(\s+jednotek)?|SVJ)\s*(,\s*)?((pro\s+)?(dům|domu|domy|budovu|budovy|objekt)\s+|v\s+(domě|domech|budově)\s+|(č\.\s*p\.|čp\.)\s*)?/iu', '', trim($name));
    $short = trim((string) $short, " ,.-–");
    return $short !== '' ? $short : trim($name);
}

/** Subjekt z ARES převedený na údaje pro web. */
function ares_subject(array $s): array
{
    $name = trim((string) ($s['obchodniJmeno'] ?? ''));
    $sidlo = $s['sidlo'] ?? [];
    $short = ares_short_name($name);
    return [
        'ico' => (string) ($s['ico'] ?? ''),
        'name' => $name,
        'short' => $short,
        'site_name' => 'SVJ ' . $short,
        'address' => trim((string) ($sidlo['textovaAdresa'] ?? '')),
        'founded' => (string) ($s['datumVzniku'] ?? ''),
        'svj' => (string) ($s['pravniForma'] ?? '') === ARES_SVJ_FORM,
        'closed' => !empty($s['datumZaniku']),
    ];
}

/** Podle IČO. Vrací ['ok' => true, 'subject' => …] nebo ['ok' => false, 'error' => text pro člověka]. */
function ares_by_ico(string $raw): array
{
    $ico = ares_ico($raw);
    if ($ico === null) {
        return ['ok' => false, 'error' => 'IČO má 8 číslic a tohle není platné IČO. Zkontrolujte ho, nebo hledejte podle názvu.'];
    }
    $data = ares_request('/ekonomicke-subjekty/' . $ico);
    if ($data === null) {
        return ['ok' => false, 'error' => 'ARES teď neodpovídá. Zkuste to za chvíli, nebo údaje vyplňte ručně.'];
    }
    if (!$data || empty($data['ico'])) {
        return ['ok' => false, 'error' => 'V ARES není žádný subjekt s IČO ' . $ico . '.'];
    }
    $subject = ares_subject($data);
    return ['ok' => true, 'subject' => $subject, 'warning' => $subject['closed'] ? 'Podle ARES tento subjekt zanikl.' : (!$subject['svj'] ? 'Podle ARES to není společenství vlastníků jednotek – zkontrolujte IČO.' : '')];
}

/** Podle části názvu nebo ulice, jen mezi SVJ. Vrací až $limit nalezených. */
function ares_search(string $query, int $limit = 8): array
{
    $query = trim(preg_replace('/\s+/u', ' ', $query));
    // obecná slova by našla tisíce SVJ – hledá se podle toho, co je za nimi
    $needle = trim(ares_short_name($query));
    if (mb_strlen($needle) < 3) {
        return ['ok' => false, 'error' => 'Napište IČO, nebo aspoň tři písmena z názvu či ulice.'];
    }
    $data = ares_request('/ekonomicke-subjekty/vyhledat', ['obchodniJmeno' => $needle, 'pravniForma' => [ARES_SVJ_FORM], 'start' => 0, 'pocet' => $limit]);
    if ($data === null) {
        return ['ok' => false, 'error' => 'ARES teď neodpovídá. Zkuste to za chvíli, nebo údaje vyplňte ručně.'];
    }
    $list = array_map('ares_subject', $data['ekonomickeSubjekty'] ?? []);
    $list = array_values(array_filter($list, fn($s) => !$s['closed']));
    if (!$list) {
        return ['ok' => false, 'error' => 'V ARES jsme nenašli žádné SVJ s „' . $needle . '“ v názvu. Zkuste IČO nebo jinou část názvu.'];
    }
    return ['ok' => true, 'results' => $list, 'total' => (int) ($data['pocetCelkem'] ?? count($list))];
}

/** Dotaz z formuláře: IČO, nebo text k hledání. Výsledek jako JSON pro skript ve formuláři. */
function ares_lookup_json(string $q): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $digits = preg_replace('/\s+/', '', $q);
    $res = preg_match('/^\d{6,8}$/', $digits) ? ares_by_ico($digits) : ares_search($q);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}

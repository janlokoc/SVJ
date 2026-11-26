<?php
declare(strict_types=1);

/*
 * Bank iD (BETA, vyžaduje smlouvu s Bankovní identitou, a.s.): ověření totožnosti vlastníka při hlasování mimo zasedání.
 *
 * OpenID Connect, tok „authorization code“ s PKCE (S256), podle veřejné dokumentace pro poskytovatele služeb:
 *   https://developer.bankid.cz/docs/apis_sep      (koncové body, scopes, PKCE, ověření klienta)
 *   https://developer.bankid.cz/docs/security_sep  (bezpečnost, podpis tokenů)
 * Zjištěno z dokumentace (11/2026): issuer sandboxu https://oidc.sandbox.bankid.cz/, discovery /.well-known/openid-configuration,
 * JWKS /.well-known/jwks, autorizace /auth, token /token, userinfo /userinfo, profile /profile; ID token podepsaný PS512;
 * ověření klienta client_secret_post; scopes openid, profile.name, profile.birthdate.
 * Adresa produkce (https://oidc.bankid.cz/) v dokumentaci výslovně uvedena není – NEOVĚŘENO proti skutečné službě.
 *
 * Nastavení v storage/config.php:
 *   'bankid' => ['client_id' => '…', 'client_secret' => '…', 'issuer' => 'sandbox' | 'production' | 'https://…/',
 *                'redirect_uri' => 'https://…/bankid/navrat' (nepovinné; jinak adresa webu domu + /bankid/navrat)]
 * Funkce se zapíná v konzoli: BETA_FEATURES['bankid'] (výchozí stav vypnuto).
 * HTTP přenos je vyměnitelný (bankid_transport()), testy používají podvrženou službu – nic nevolají do sítě.
 */

const BANKID_ISSUERS = ['sandbox' => 'https://oidc.sandbox.bankid.cz/', 'production' => 'https://oidc.bankid.cz/'];
const BANKID_SCOPES = 'openid profile.name profile.birthdate';
/** Podpisové algoritmy ID tokenu, které přijímáme (Bank iD používá PS512; RS* jen pro úplnost OIDC). */
const BANKID_ALGS = ['PS256' => 'sha256', 'PS384' => 'sha384', 'PS512' => 'sha512', 'RS256' => 'sha256', 'RS384' => 'sha384', 'RS512' => 'sha512'];

/** HTTP přenos pro Bank iD (v testech podvržený). Vrací ['status' => int, 'body' => string]; chyba spojení = status 0. */
interface BankidTransport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null): array;
}

final class BankidCurlTransport implements BankidTransport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $out === false ? 0 : $status, 'body' => $out === false ? '' : (string) $out];
    }
}

/** Aktuální přenos; $set ho vymění (testy), null vrátí výchozí. */
function bankid_transport(?BankidTransport $set = null, bool $reset = false): BankidTransport
{
    static $t = null;
    if ($set !== null || $reset) {
        $t = $set;
        bankid_discovery(null);
    }
    return $t ??= new BankidCurlTransport();
}

/** Surové nastavení 'bankid' z storage/config.php; $set ho pro testy nahradí (reset = zpět na config.php). */
function bankid_settings(?array $set = null, bool $reset = false): array
{
    static $override = null;
    if ($set !== null || $reset) {
        $override = $set;
    }
    return $override ?? (array) (app_config()['bankid'] ?? []);
}
/** Nastavení z storage/config.php, nebo null, když chybí client_id/secret. */
function bankid_config(): ?array
{
    $c = bankid_settings();
    if (($c['client_id'] ?? '') === '' || ($c['client_secret'] ?? '') === '') {
        return null;
    }
    $iss = (string) ($c['issuer'] ?? 'sandbox');
    $iss = BANKID_ISSUERS[$iss] ?? $iss;
    if (!preg_match('~^https://[A-Za-z0-9.\-]+(:\d+)?/~', rtrim($iss, '/') . '/')) {
        return null;
    }
    return ['client_id' => (string) $c['client_id'], 'client_secret' => (string) $c['client_secret'], 'issuer' => rtrim($iss, '/') . '/',
        'redirect_uri' => (string) ($c['redirect_uri'] ?? '') ?: absolute_url('/bankid/navrat'), 'sandbox' => str_contains($iss, 'sandbox')];
}
/** Lze Bank iD v tomto domě nabídnout? (zapnuto v konzoli + vyplněné údaje klienta) */
function bankid_available(): bool
{
    return function_exists('beta_visible') && beta_visible('bankid') && bankid_config() !== null;
}

function bankid_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function bankid_b64url_decode(string $s): string|false
{
    if (!preg_match('/^[A-Za-z0-9_\-]*$/', $s)) {
        return false;
    }
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** PKCE (RFC 7636): [code_verifier, code_challenge S256]. */
function bankid_pkce(): array
{
    $verifier = bankid_b64url(random_bytes(48));
    return [$verifier, bankid_b64url(hash('sha256', $verifier, true))];
}

/** Discovery dokument issuera (v rámci požadavku se pamatuje). null = smazat paměť. */
function bankid_discovery(?array $cfg): array|string
{
    static $cache = [];
    if ($cfg === null) {
        $cache = [];
        return [];
    }
    $iss = $cfg['issuer'];
    if (isset($cache[$iss])) {
        return $cache[$iss];
    }
    $r = bankid_transport()->send('GET', $iss . '.well-known/openid-configuration', ['Accept' => 'application/json']);
    $d = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    if (!is_array($d) || empty($d['authorization_endpoint']) || empty($d['token_endpoint']) || empty($d['jwks_uri'])) {
        return 'Služba Bank iD neodpověděla (konfigurace poskytovatele). Zkuste to prosím později.';
    }
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $k) {
        if (isset($d[$k]) && !str_starts_with((string) $d[$k], 'https://')) {
            return 'Konfigurace Bank iD obsahuje nezabezpečenou adresu.';
        }
    }
    return $cache[$iss] = $d;
}

/** Adresa, na kterou se vlastník přesměruje k přihlášení bankou. */
function bankid_auth_url(array $cfg, array $disc, string $state, string $nonce, string $challenge): string
{
    return $disc['authorization_endpoint'] . (str_contains($disc['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
        'client_id' => $cfg['client_id'], 'redirect_uri' => $cfg['redirect_uri'], 'response_type' => 'code', 'scope' => BANKID_SCOPES,
        'state' => $state, 'nonce' => $nonce, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'ui_locales' => 'cs',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Výměna autorizačního kódu za tokeny (client_secret_post + code_verifier). */
function bankid_exchange(array $cfg, array $disc, string $code, string $verifier): array|string
{
    $r = bankid_transport()->send('POST', $disc['token_endpoint'], ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $cfg['redirect_uri'], 'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'], 'code_verifier' => $verifier]));
    $t = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($t) || empty($t['id_token']) || empty($t['access_token'])) {
        return 'Bank iD nepotvrdila přihlášení (výměna kódu se nepodařila).';
    }
    return $t;
}

// ---------- Ověření podpisu (JWKS, RSA PKCS#1 v1.5 i RSA-PSS) ----------

function bankid_asn1_len(int $n): string
{
    if ($n < 128) {
        return chr($n);
    }
    $s = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($s)) . $s;
}
function bankid_asn1_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || ord($bytes[0]) & 0x80) {
        $bytes = "\0" . $bytes;
    }
    return "\x02" . bankid_asn1_len(strlen($bytes)) . $bytes;
}
/** Veřejný klíč RSA z JWK (n, e) jako PEM (SubjectPublicKeyInfo). */
function bankid_jwk_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
        return null;
    }
    $n = bankid_b64url_decode($jwk['n']);
    $e = bankid_b64url_decode($jwk['e']);
    if ($n === false || $e === false || strlen(ltrim($n, "\0")) < 256) {   // nejméně 2048 bitů
        return null;
    }
    $rsa = "\x30" . bankid_asn1_len(strlen($i = bankid_asn1_int($n) . bankid_asn1_int($e))) . $i;
    $bit = "\x03" . bankid_asn1_len(strlen($rsa) + 1) . "\0" . $rsa;
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . bankid_asn1_len(strlen($alg . $bit)) . $alg . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function bankid_mgf1(string $seed, int $len, string $hash): string
{
    $out = '';
    for ($i = 0; strlen($out) < $len; $i++) {
        $out .= hash($hash, $seed . pack('N', $i), true);
    }
    return substr($out, 0, $len);
}
/** RSASSA-PSS (RFC 8017, 8.1.2), MGF1 se stejným hashem, délka soli = délka hashe (jako u JWS PS256/384/512). */
function bankid_pss_verify(string $data, string $sig, string $pem, string $hash): bool
{
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return false;
    }
    $det = openssl_pkey_get_details($key);
    $modBits = (int) ($det['bits'] ?? 0);
    $k = intdiv($modBits + 7, 8);
    if ($modBits < 2048 || strlen($sig) !== $k || !openssl_public_decrypt($sig, $m, $key, OPENSSL_NO_PADDING) || strlen($m) !== $k) {
        return false;
    }
    $emBits = $modBits - 1;
    $emLen = intdiv($emBits + 7, 8);
    if ($emLen < $k) {
        if ($m[0] !== "\0") {
            return false;
        }
        $m = substr($m, 1);
    }
    $hLen = strlen(hash($hash, '', true));
    $sLen = $hLen;
    if ($emLen < $hLen + $sLen + 2 || substr($m, -1) !== "\xbc") {
        return false;
    }
    $masked = substr($m, 0, $emLen - $hLen - 1);
    $h = substr($m, $emLen - $hLen - 1, $hLen);
    $topMask = 0xff >> (8 * $emLen - $emBits);
    if ((ord($masked[0]) & ~$topMask & 0xff) !== 0) {
        return false;
    }
    $db = $masked ^ bankid_mgf1($h, strlen($masked), $hash);
    $db[0] = chr(ord($db[0]) & $topMask);
    $psLen = $emLen - $hLen - $sLen - 2;
    if (substr($db, 0, $psLen) !== str_repeat("\0", $psLen) || $db[$psLen] !== "\x01") {
        return false;
    }
    $salt = substr($db, -$sLen);
    return hash_equals($h, hash($hash, str_repeat("\0", 8) . hash($hash, $data, true) . $salt, true));
}

/** Klíče poskytovatele (JWKS). */
function bankid_jwks(array $disc): array|string
{
    $r = bankid_transport()->send('GET', $disc['jwks_uri'], ['Accept' => 'application/json']);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j['keys'] ?? null)) {
        return 'Nepodařilo se načíst klíče Bank iD.';
    }
    return $j['keys'];
}

/**
 * Ověří ID token: podpis klíčem z JWKS (podle kid), iss, aud, exp/iat, nonce. Vrací deklarace (claims), nebo text chyby.
 */
function bankid_verify_id_token(string $jwt, array $cfg, array $disc, array $keys, string $nonce, ?int $now = null): array|string
{
    $now ??= time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return 'Neplatný token (formát).';
    }
    $head = json_decode((string) bankid_b64url_decode($parts[0]), true);
    $claims = json_decode((string) bankid_b64url_decode($parts[1]), true);
    $sig = bankid_b64url_decode($parts[2]);
    if (!is_array($head) || !is_array($claims) || $sig === false || $sig === '') {
        return 'Neplatný token (obsah).';
    }
    $alg = (string) ($head['alg'] ?? '');
    if (!isset(BANKID_ALGS[$alg])) {
        return 'Nepodporovaný podpis tokenu (' . $alg . ').';   // mimo jiné odmítne „none“ a HS256
    }
    $ok = false;
    foreach ($keys as $jwk) {
        if (!is_array($jwk) || (isset($head['kid']) && ($jwk['kid'] ?? null) !== $head['kid']) || (($jwk['use'] ?? 'sig') !== 'sig')) {
            continue;
        }
        $pem = bankid_jwk_pem($jwk);
        if ($pem === null) {
            continue;
        }
        $data = $parts[0] . '.' . $parts[1];
        $ok = $alg[0] === 'P' ? bankid_pss_verify($data, $sig, $pem, BANKID_ALGS[$alg])
            : openssl_verify($data, $sig, $pem, ['sha256' => OPENSSL_ALGO_SHA256, 'sha384' => OPENSSL_ALGO_SHA384, 'sha512' => OPENSSL_ALGO_SHA512][BANKID_ALGS[$alg]]) === 1;
        if ($ok) {
            break;
        }
    }
    if (!$ok) {
        return 'Podpis tokenu Bank iD nesouhlasí.';
    }
    $iss = rtrim((string) ($disc['issuer'] ?? $cfg['issuer']), '/');
    if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $iss) {
        return 'Token vydal jiný poskytovatel.';
    }
    $aud = (array) ($claims['aud'] ?? []);
    if (!in_array($cfg['client_id'], $aud, true)) {
        return 'Token není určen pro tento web.';
    }
    if (!is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - 60) {
        return 'Platnost přihlášení vypršela.';
    }
    if (is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] > $now + 300) {
        return 'Token má čas vydání v budoucnosti.';
    }
    if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        return 'Token nepatří k tomuto přihlášení (nonce).';
    }
    if (($claims['sub'] ?? '') === '') {
        return 'Token neobsahuje identifikátor osoby.';
    }
    return $claims;
}

/** Údaje o osobě (jméno, datum narození) z /userinfo; sub musí sedět s ID tokenem. */
function bankid_userinfo(array $disc, string $accessToken, string $sub): array|string
{
    if (empty($disc['userinfo_endpoint'])) {
        return 'Bank iD nenabízí údaje o osobě.';
    }
    $r = bankid_transport()->send('GET', $disc['userinfo_endpoint'], ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']);
    $u = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($u)) {
        return 'Bank iD nevydala údaje o osobě.';
    }
    if (($u['sub'] ?? '') !== $sub) {
        return 'Údaje o osobě nepatří k přihlášení.';
    }
    return ['sub' => $sub, 'given_name' => trim((string) ($u['given_name'] ?? '')), 'family_name' => trim((string) ($u['family_name'] ?? '')),
        'birthdate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($u['birthdate'] ?? '')) ? (string) $u['birthdate'] : ''];
}

/**
 * Celé dokončení přihlášení: kód → tokeny → ověřený ID token → údaje o osobě.
 * Vrací ['sub', 'given_name', 'family_name', 'birthdate'] nebo text chyby.
 */
function bankid_complete(array $cfg, string $code, string $verifier, string $nonce, ?int $now = null): array|string
{
    $disc = bankid_discovery($cfg);
    if (is_string($disc)) {
        return $disc;
    }
    $tok = bankid_exchange($cfg, $disc, $code, $verifier);
    if (is_string($tok)) {
        return $tok;
    }
    $keys = bankid_jwks($disc);
    if (is_string($keys)) {
        return $keys;
    }
    $claims = bankid_verify_id_token((string) $tok['id_token'], $cfg, $disc, $keys, $nonce, $now);
    if (is_string($claims)) {
        return $claims;
    }
    $info = bankid_userinfo($disc, (string) $tok['access_token'], (string) $claims['sub']);
    if (is_string($info)) {
        return $info;
    }
    if ($info['family_name'] === '' || $info['given_name'] === '') {
        return 'Bank iD nevydala jméno a příjmení (zkontrolujte rozsah oprávnění profile.name).';
    }
    return $info;
}

/** Jména bez diakritiky a velkých písmen, rozdělená na slova. */
function bankid_name_words(string $s): array
{
    $s = mb_strtolower(strip_accents($s));
    return array_values(array_filter(preg_split('/[^a-z]+/', $s) ?: [], fn($w) => strlen($w) >= 2));
}
/**
 * Patří ověřená osoba k vlastníkovi? Příjmení (všechna slova) a první křestní jméno se musí najít v jednom ze jmen
 * (jméno účtu, vlastník jednotky podle evidence – může jich být víc, např. „SJM Novák Jan a Nováková Eva“).
 */
function bankid_name_matches(array $person, array $candidates): bool
{
    $family = bankid_name_words($person['family_name'] ?? '');
    $given = array_slice(bankid_name_words($person['given_name'] ?? ''), 0, 1);
    if (!$family || !$given) {
        return false;
    }
    foreach ($candidates as $c) {
        $words = bankid_name_words((string) $c);
        if (!array_diff($family, $words) && !array_diff($given, $words)) {
            return true;
        }
    }
    return false;
}

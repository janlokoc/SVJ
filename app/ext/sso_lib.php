<?php
declare(strict_types=1);

/*
 * Modul I – přihlášení přes Google, Microsoft, Apple a Facebook (SSO).
 *
 * Zásady:
 * - SSO NIKDY nezakládá účet. Přihlásí jen existující aktivní účet, jehož e-mail souhlasí s e-mailem, který poskytovatel
 *   výslovně označil za ověřený – nebo účet, který si uživatel dřív sám propojil (Můj účet → Přihlášení přes další účty).
 *   Propojení ukládá poskytovatele a jeho stálý identifikátor uživatele (sub/id), takže funguje i se skrytým e-mailem Apple.
 * - Tok: OAuth 2.0 authorization code + PKCE (RFC 7636) + state + nonce (OpenID Connect Core 1.0,
 *   https://openid.net/specs/openid-connect-core-1_0.html). ID token se ověřuje klíči poskytovatele (JWKS, RS256/ES256)
 *   včetně iss, aud, exp, iat a nonce.
 * - Na platformě je JEDNA návratová adresa pro každého poskytovatele na hlavní doméně (https://<doména>/sso/callback/<poskytovatel>).
 *   Kam se má uživatel vrátit (dům, partnerský portál, konzole), je v záznamu toku na serveru (ROOT_STORAGE/sso), který
 *   označuje podepsaný parametr state (HMAC tajemstvím serveru). Po ověření se identita předá jednorázovým tokenem
 *   (platí 2 minuty) na adresu /prihlaseni/sso/vstup/<token> webu domu (vzor: /spravce-vstup). Předání platí jen v tom
 *   prohlížeči, který přihlášení začal (identifikátor toku je v jeho relaci) – cizí odkaz nikoho nepřihlásí.
 * - Dvoufázové ověření platí dál: kdo má zapnutý kód z aplikace, zadá ho i po přihlášení přes poskytovatele.
 * - Samostatná instalace (bez platformy): návratová adresa je přímo na webu.
 *
 * Nastavení v storage/config.php (žádné tajné údaje v databázi ani v gitu):
 *   'sso' => [
 *     'google'    => ['client_id' => '…', 'client_secret' => '…'],
 *     'microsoft' => ['client_id' => '…', 'client_secret' => '…', 'tenant' => 'common'],   // common | organizations | consumers | ID tenanta
 *     'facebook'  => ['app_id' => '…', 'app_secret' => '…'],                              // nepovinně 'graph_version' => 'v23.0'
 *     'apple'     => ['client_id' => 'cz.example.web' (Services ID), 'team_id' => '…', 'key_id' => '…', 'private_key_path' => '/cesta/AuthKey_XXXX.p8'],
 *     'secret'    => '…',          // nepovinné: klíč pro podpis state (jinak se vygeneruje do storage/sso/secret.key)
 *     'redirect_base' => '…',      // nepovinné: pevný začátek návratové adresy (např. https://spolecnyweb.cz)
 *   ],
 * Zapínání: beta program „sso“ (konzole → Nastavení služby → Nové funkce) pro weby domů; partnerský portál a konzole
 * mají vlastní přepínač v Nastavení služby → Přihlášení přes další účty (konzole výchozí vypnuto).
 */

const SSO_PROVIDERS = ['google' => 'Google', 'microsoft' => 'Microsoft', 'apple' => 'Apple', 'facebook' => 'Facebook'];
/** Tenant osobních účtů Microsoft (Outlook.com, Hotmail): e-mail těchto účtů Microsoft ověřuje. */
const SSO_MS_CONSUMERS = '9188040d-6c67-4c5b-b112-36a304b66dad';
const SSO_FLOW_TTL = 900;      // od kliknutí na tlačítko po návrat od poskytovatele
const SSO_HANDOFF_TTL = 120;   // jednorázový token pro předání na web domu
const SSO_LEEWAY = 120;        // tolerance hodin serverů při kontrole exp/iat
const SSO_CONTEXTS = ['site', 'partner', 'console'];

final class SsoError extends RuntimeException
{
}

// ---------- Nastavení ----------

/** Celé nastavení 'sso' z config.php (testy ho podstrčí přes $GLOBALS['SSO_CONFIG']). */
function sso_config(): array
{
    return (array) ($GLOBALS['SSO_CONFIG'] ?? (app_config()['sso'] ?? []));
}
/** Povinné položky nastavení poskytovatele. */
function sso_required_keys(string $p): array
{
    return match ($p) {
        'google', 'microsoft' => ['client_id', 'client_secret'],
        'facebook' => ['app_id', 'app_secret'],
        'apple' => ['client_id', 'team_id', 'key_id', 'private_key_path'],
        default => [],
    };
}
/** Které povinné položky poskytovateli v config.php chybějí (prázdné pole = nastaveno). */
function sso_missing(string $p): array
{
    $c = (array) (sso_config()[$p] ?? []);
    $miss = [];
    foreach (sso_required_keys($p) as $k) {
        if ($k === 'private_key_path') {
            $path = (string) ($c[$k] ?? '');
            if (trim((string) ($c['private_key'] ?? '')) === '' && ($path === '' || !is_readable($path))) {
                $miss[] = $path === '' ? $k : $k . ' (soubor nejde přečíst)';
            }
            continue;
        }
        if (!is_string($c[$k] ?? null) || trim($c[$k]) === '') {
            $miss[] = $k;
        }
    }
    if ($p === 'microsoft' && isset($c['tenant']) && !preg_match('/^(common|organizations|consumers|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', (string) $c['tenant'])) {
        $miss[] = 'tenant (common, organizations, consumers nebo ID tenanta)';
    }
    if ($p === 'facebook' && isset($c['graph_version']) && !preg_match('/^v\d{1,2}\.\d$/', (string) $c['graph_version'])) {
        $miss[] = 'graph_version (např. v23.0)';
    }
    return $miss;
}
/** Nastavení poskytovatele, nebo null, když není (úplně) nastaven. */
function sso_provider_config(string $p): ?array
{
    if (!isset(SSO_PROVIDERS[$p]) || sso_missing($p)) {
        return null;
    }
    $c = (array) sso_config()[$p];
    return match ($p) {
        'microsoft' => $c + ['tenant' => 'common'],
        'facebook' => $c + ['graph_version' => 'v23.0', 'trust_email' => true],
        default => $c,
    };
}
/** Nastavení poskytovatelé: klíč => název. */
function sso_configured(): array
{
    return array_filter(SSO_PROVIDERS, fn($p) => sso_provider_config($p) !== null, ARRAY_FILTER_USE_KEY);
}
/** Je SSO v daném místě zapnuté? site = web domu, partner = partnerský portál, console = konzole provozovatele. */
function sso_enabled(string $ctx): bool
{
    return match ($ctx) {
        'site' => empty($GLOBALS['DEMO']) && beta_visible('sso'),
        'partner' => platform_enabled() && beta_stage('sso') !== 'off' && pset('sso_partner') !== '0',
        'console' => platform_enabled() && pset('sso_console') === '1',
        default => false,
    };
}
/** Poskytovatelé, jejichž tlačítka se v daném místě ukazují. */
function sso_available(string $ctx): array
{
    return sso_enabled($ctx) ? sso_configured() : [];
}
/** Tajemství pro podpis state: z config.php, jinak jednou vygenerované do storage/sso/secret.key. */
function sso_secret(): string
{
    $s = (string) (sso_config()['secret'] ?? '');
    if (strlen($s) >= 32) {
        return $s;
    }
    $f = sso_dir() . '/secret.key';
    if (!is_file($f)) {
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, bin2hex(random_bytes(32)));
        chmod($tmp, 0600);
        @rename($tmp, $f);   // při souběhu vyhraje první, oba pak čtou totéž
        @unlink($tmp);
    }
    return trim((string) file_get_contents($f));
}
/** Návratová adresa (redirect URI), kterou je třeba zaregistrovat u poskytovatele. */
function sso_redirect_uri(string $p): string
{
    $base = rtrim((string) (sso_config()['redirect_base'] ?? ''), '/');
    if (preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[a-z0-9._~/-]*)?$#i', $base)) {
        return $base . '/sso/callback/' . $p;
    }
    return platform_enabled() ? platform_url('/sso/callback/' . $p) : absolute_url('/sso/callback/' . $p);
}

// ---------- Úložiště toků a předání (soubory v ROOT_STORAGE/sso) ----------

function sso_dir(): string
{
    $d = ROOT_STORAGE . '/sso';
    if (!is_dir($d)) {
        mkdir($d, 0700, true);
    }
    return $d;
}
function sso_store_put(string $name, array $data): void
{
    if (!preg_match('/^[a-z]+-[a-z0-9]{16,64}$/', $name)) {
        throw new InvalidArgumentException('sso: neplatný název záznamu');
    }
    $f = sso_dir() . '/' . $name . '.json';
    file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($f, 0600);
}
/** Přečte a SMAŽE záznam (jednorázový): přejmenování je atomické, takže ho při souběhu dostane jen jeden požadavek. */
function sso_store_take(string $name): ?array
{
    if (!preg_match('/^[a-z]+-[a-z0-9]{16,64}$/', $name)) {
        return null;
    }
    $f = sso_dir() . '/' . $name . '.json';
    $tmp = $f . '.' . bin2hex(random_bytes(6)) . '.taken';
    if (!is_file($f) || !@rename($f, $tmp)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($tmp), true);
    @unlink($tmp);
    return is_array($d) ? $d : null;
}
/** Úklid nepoužitých toků a předání starších než hodina. */
function sso_store_gc(): void
{
    foreach ([...(glob(sso_dir() . '/flow-*') ?: []), ...(glob(sso_dir() . '/hand-*') ?: [])] as $f) {
        if (@filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}
function sso_state_sig(string $provider, string $id): string
{
    return substr(hash_hmac('sha256', 'sso-state|' . $provider . '|' . $id, sso_secret()), 0, 32);
}
/** Ověří podpis state a vrátí identifikátor toku, nebo null. */
function sso_state_id(string $provider, string $state): ?string
{
    if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{32})$/', $state, $m)) {
        return null;
    }
    return hash_equals(sso_state_sig($provider, $m[1]), $m[2]) ? $m[1] : null;
}
function sso_safe_path(mixed $p): string
{
    return is_string($p) && preg_match('~^/(?![/\\\\])[^\s\\\\]*$~', $p) ? $p : '';
}

// ---------- Začátek přihlášení ----------

/**
 * Začne přihlášení (nebo propojení) a vrátí adresu, na kterou se má prohlížeč přesměrovat k poskytovateli.
 * $opts: tenant (slug, u webu domu), mode ('login' | 'link'), uid (u propojení), after (kam po přihlášení).
 * Identifikátor toku se uloží do relace tohoto prohlížeče – předání po návratu jinde neprojde.
 */
function sso_start(string $provider, string $ctx, array $opts = []): string
{
    $cfg = sso_provider_config($provider);
    if (!$cfg || !in_array($ctx, SSO_CONTEXTS, true)) {
        throw new SsoError('Poskytovatel není nastaven.');
    }
    if (random_int(1, 20) === 1) {
        sso_store_gc();
    }
    $id = bin2hex(random_bytes(16));
    $flow = [
        'provider' => $provider, 'ctx' => $ctx, 'tenant' => $ctx === 'site' ? (string) ($opts['tenant'] ?? TENANT) : '',
        'mode' => ($opts['mode'] ?? 'login') === 'link' ? 'link' : 'login', 'uid' => (int) ($opts['uid'] ?? 0),
        'after' => sso_safe_path($opts['after'] ?? ''), 'verifier' => b64url(random_bytes(48)), 'nonce' => b64url(random_bytes(24)),
        'redirect_uri' => sso_redirect_uri($provider), 'created' => time(),
    ];
    sso_store_put('flow-' . $id, $flow);
    $_SESSION['sso_flows'] = array_slice([...array_filter((array) ($_SESSION['sso_flows'] ?? []), 'is_string'), $id], -5);
    return sso_authorize_url($provider, $cfg, $id . '.' . sso_state_sig($provider, $id), $flow);
}
/** Adresa přihlašovací stránky poskytovatele. */
function sso_authorize_url(string $p, array $cfg, string $state, array $flow): string
{
    $challenge = b64url(hash('sha256', $flow['verifier'], true));   // PKCE S256 (RFC 7636 §4.2)
    switch ($p) {
        case 'google':
            // https://developers.google.com/identity/openid-connect/openid-connect#sendauthrequest
            // https://developers.google.com/identity/protocols/oauth2/web-server#creatingclient (PKCE)
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'response_type' => 'code', 'client_id' => $cfg['client_id'], 'redirect_uri' => $flow['redirect_uri'], 'scope' => 'openid email profile',
                'state' => $state, 'nonce' => $flow['nonce'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'select_account',
            ], '', '&', PHP_QUERY_RFC3986);
        case 'microsoft':
            // https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#request-an-authorization-code
            return 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant']) . '/oauth2/v2.0/authorize?' . http_build_query([
                'client_id' => $cfg['client_id'], 'response_type' => 'code', 'redirect_uri' => $flow['redirect_uri'], 'response_mode' => 'query',
                'scope' => 'openid email profile', 'state' => $state, 'nonce' => $flow['nonce'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
                'prompt' => 'select_account',
            ], '', '&', PHP_QUERY_RFC3986);
        case 'apple':
            // https://developer.apple.com/documentation/sign_in_with_apple/request_an_authorization_to_the_sign_in_with_apple_server
            // Se scope name/email Apple vrací odpověď jen jako form_post (POST na návratovou adresu). PKCE Apple nedokumentuje,
            // proto se neposílá; tok chrání state, nonce v ID tokenu a tajný klíč klienta (client_secret JWT).
            return 'https://appleid.apple.com/auth/authorize?' . http_build_query([
                'response_type' => 'code', 'response_mode' => 'form_post', 'client_id' => $cfg['client_id'], 'redirect_uri' => $flow['redirect_uri'],
                'scope' => 'name email', 'state' => $state, 'nonce' => $flow['nonce'],
            ], '', '&', PHP_QUERY_RFC3986);
        case 'facebook':
            // https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow
            return 'https://www.facebook.com/' . $cfg['graph_version'] . '/dialog/oauth?' . http_build_query([
                'client_id' => $cfg['app_id'], 'redirect_uri' => $flow['redirect_uri'], 'state' => $state, 'response_type' => 'code',
                'scope' => 'email public_profile', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            ], '', '&', PHP_QUERY_RFC3986);
    }
    throw new SsoError('Neznámý poskytovatel.');
}

// ---------- Návrat od poskytovatele ----------

/**
 * Zpracuje návrat od poskytovatele ($params = GET, u Apple POST). Vrátí ['flow' => záznam toku, 'token' => jednorázový token
 * pro předání]. Výjimku SsoError vyhodí jen tehdy, když nejde určit, kam uživatele vrátit (neplatný či prošlý state).
 * Chyba poskytovatele nebo neověřený token se předá jako 'error' – uživatel ji uvidí na webu, odkud přišel.
 */
function sso_callback(string $provider, array $params): array
{
    $id = sso_state_id($provider, is_string($params['state'] ?? null) ? $params['state'] : '');
    $flow = $id !== null ? sso_store_take('flow-' . $id) : null;
    if (!$flow || ($flow['provider'] ?? '') !== $provider || time() - (int) ($flow['created'] ?? 0) > SSO_FLOW_TTL) {
        throw new SsoError('Přihlášení vypršelo nebo už bylo použito. Vraťte se prosím na web a zkuste to znovu.');
    }
    $hand = ['flow' => $id, 'ctx' => $flow['ctx'], 'tenant' => $flow['tenant'], 'mode' => $flow['mode'], 'uid' => $flow['uid'], 'after' => $flow['after'],
        'provider' => $provider, 'expires' => time() + SSO_HANDOFF_TTL];
    $code = is_string($params['code'] ?? null) ? $params['code'] : '';
    if (isset($params['error']) || $code === '') {
        // Uživatel přihlášení u poskytovatele zrušil (access_denied, user_cancelled_authorize…) nebo chybí kód.
        $hand['error'] = in_array($params['error'] ?? '', ['access_denied', 'user_cancelled_authorize'], true) || (string) ($params['error_reason'] ?? '') === 'user_denied' ? 'denied' : 'failed';
    } else {
        try {
            $hand['identity'] = sso_exchange($provider, (array) sso_provider_config($provider), $code, $flow);
        } catch (SsoError $e) {
            error_log('SVJ web, SSO ' . $provider . ': ' . $e->getMessage());
            $hand['error'] = 'failed';
        }
    }
    $token = bin2hex(random_bytes(32));
    sso_store_put('hand-' . hash('sha256', $token), $hand);
    return ['flow' => $flow, 'token' => $token];
}
/** Kam prohlížeč poslat s jednorázovým tokenem (web domu, partnerský portál, nebo konzole). */
function sso_handoff_url(array $flow, string $token): string
{
    if ($flow['ctx'] === 'partner') {
        return platform_url('/partner/sso/vstup/' . $token);
    }
    if ($flow['ctx'] === 'console') {
        return platform_url('/sprava/sso/vstup/' . $token);
    }
    if (!platform_enabled()) {
        return absolute_url('/prihlaseni/sso/vstup/' . $token);
    }
    $t = tenant_row((string) $flow['tenant']);
    if (!$t) {
        throw new SsoError('Web, ze kterého přihlášení začalo, už neexistuje.');
    }
    return tenant_url($t, '/prihlaseni/sso/vstup/' . $token);
}
/**
 * Převezme jednorázové předání. Platí jen jednou, nejdéle 2 minuty, jen pro dané místo (dům / portál / konzole)
 * a jen v prohlížeči, jehož relace přihlášení začala.
 */
function sso_handoff_take(string $token, string $ctx, string $tenant = ''): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $h = sso_store_take('hand-' . hash('sha256', $token));
    $flows = array_values(array_filter((array) ($_SESSION['sso_flows'] ?? []), 'is_string'));
    if (!$h || time() > (int) ($h['expires'] ?? 0) || ($h['ctx'] ?? '') !== $ctx || ($ctx === 'site' && ($h['tenant'] ?? '') !== $tenant)
        || !in_array((string) ($h['flow'] ?? ''), $flows, true)) {
        return null;
    }
    $_SESSION['sso_flows'] = array_values(array_diff($flows, [$h['flow']]));
    return $h;
}

// ---------- Výměna kódu a ověření identity u jednotlivých poskytovatelů ----------

/** Identita: ['provider', 'subject', 'email', 'email_verified', 'name', 'private_email']. */
function sso_exchange(string $p, array $cfg, string $code, array $flow): array
{
    $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $flow['redirect_uri']];
    switch ($p) {
        case 'google':
            // https://developers.google.com/identity/openid-connect/openid-connect#exchangecode
            $tok = sso_json('POST', 'https://oauth2.googleapis.com/token', $form + ['client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'], 'code_verifier' => $flow['verifier']]);
            // https://developers.google.com/identity/openid-connect/openid-connect#validatinganidtoken
            $c = sso_verify_id_token((string) ($tok['id_token'] ?? ''), 'google', $cfg['client_id'], $flow['nonce'],
                fn(array $c) => in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true));
            return sso_identity($p, $c['sub'] ?? '', $c['email'] ?? '', ($c['email_verified'] ?? false) === true || ($c['email_verified'] ?? '') === 'true', $c['name'] ?? '');
        case 'microsoft':
            // https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#redeem-a-code-for-an-access-token
            $tok = sso_json('POST', 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant']) . '/oauth2/v2.0/token',
                $form + ['client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'], 'code_verifier' => $flow['verifier'], 'scope' => 'openid email profile']);
            // https://learn.microsoft.com/en-us/entra/identity-platform/id-tokens#validate-tokens – u víceklientské aplikace (common)
            // je iss https://login.microsoftonline.com/{tid}/v2.0 a za {tid} se dosadí tid z tokenu.
            $c = sso_verify_id_token((string) ($tok['id_token'] ?? ''), 'microsoft', $cfg['client_id'], $flow['nonce'], fn(array $c) => sso_ms_issuer_ok($c, $cfg['tenant']));
            [$email, $verified] = sso_ms_email($c);
            return sso_identity($p, $c['sub'] ?? '', $email, $verified, $c['name'] ?? '');
        case 'apple':
            // https://developer.apple.com/documentation/sign_in_with_apple/generate_and_validate_tokens
            $tok = sso_json('POST', 'https://appleid.apple.com/auth/token', $form + ['client_id' => $cfg['client_id'], 'client_secret' => sso_apple_client_secret($cfg)]);
            // https://developer.apple.com/documentation/sign_in_with_apple/authenticating-users-with-sign-in-with-apple#Verify-the-identity-token
            $c = sso_verify_id_token((string) ($tok['id_token'] ?? ''), 'apple', $cfg['client_id'], $flow['nonce'], fn(array $c) => ($c['iss'] ?? '') === 'https://appleid.apple.com');
            $private = ($c['is_private_email'] ?? false) === true || ($c['is_private_email'] ?? '') === 'true';
            return sso_identity($p, $c['sub'] ?? '', $c['email'] ?? '', ($c['email_verified'] ?? false) === true || ($c['email_verified'] ?? '') === 'true', '', $private);
        case 'facebook':
            // https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow#confirm (výměna kódu za přístupový token)
            $base = 'https://graph.facebook.com/' . $cfg['graph_version'];
            $tok = sso_json('GET', $base . '/oauth/access_token?' . http_build_query(['client_id' => $cfg['app_id'], 'redirect_uri' => $flow['redirect_uri'],
                'client_secret' => $cfg['app_secret'], 'code' => $code, 'code_verifier' => $flow['verifier']], '', '&', PHP_QUERY_RFC3986));
            $access = (string) ($tok['access_token'] ?? '');
            if ($access === '') {
                throw new SsoError('Facebook nevrátil přístupový token.');
            }
            // https://developers.facebook.com/docs/graph-api/reference/user (id, name, email)
            // https://developers.facebook.com/docs/graph-api/securing-requests#appsecret_proof
            $me = sso_json('GET', $base . '/me?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $access,
                'appsecret_proof' => hash_hmac('sha256', $access, $cfg['app_secret'])], '', '&', PHP_QUERY_RFC3986));
            // Graph API vrací e-mail jen tehdy, když ho uživatel na Facebooku má potvrzený („valid“); samostatný příznak
            // ověření neposílá. Kdo by tomu nechtěl věřit, nastaví 'trust_email' => false – pak jde jen o propojený účet.
            $email = (string) ($me['email'] ?? '');
            return sso_identity($p, (string) ($me['id'] ?? ''), $email, $email !== '' && !empty($cfg['trust_email']), (string) ($me['name'] ?? ''));
    }
    throw new SsoError('Neznámý poskytovatel.');
}
function sso_identity(string $p, mixed $sub, mixed $email, bool $verified, mixed $name, bool $private = false): array
{
    $sub = is_scalar($sub) ? (string) $sub : '';
    if ($sub === '' || strlen($sub) > 255) {
        throw new SsoError('Poskytovatel nevrátil identifikátor uživatele.');
    }
    $email = is_string($email) ? mb_strtolower(trim($email)) : '';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    return ['provider' => $p, 'subject' => $sub, 'email' => $email, 'email_verified' => $verified && $email !== '', 'name' => is_string($name) ? mb_substr($name, 0, 200) : '',
        'private_email' => $private];
}
/** Microsoft: vydavatel tokenu musí odpovídat tenantovi z tokenu a nastavení (common / organizations / consumers / konkrétní). */
function sso_ms_issuer_ok(array $c, string $tenant): bool
{
    $tid = is_string($c['tid'] ?? null) ? strtolower($c['tid']) : '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tid) || ($c['iss'] ?? '') !== 'https://login.microsoftonline.com/' . $tid . '/v2.0') {
        return false;
    }
    return match ($tenant) {
        'common' => true,
        'organizations' => $tid !== SSO_MS_CONSUMERS,
        'consumers' => $tid === SSO_MS_CONSUMERS,
        default => $tid === strtolower($tenant),
    };
}
/**
 * Microsoft: e-mail se bere za ověřený jen u osobního účtu (tenant consumers), nebo když token nese xms_edov = true
 * (doména e-mailu ověřená vlastníkem tenanta), případně verified_primary_email. Samotný claim email ani UPN
 * (preferred_username) ověřené nejsou – Microsoft výslovně varuje před autorizací podle nich:
 * https://learn.microsoft.com/en-us/entra/identity-platform/migrate-off-email-claim-authorization
 * https://learn.microsoft.com/en-us/entra/identity-platform/optional-claims-reference (xms_edov, verified_primary_email)
 */
function sso_ms_email(array $c): array
{
    $email = is_string($c['email'] ?? null) ? mb_strtolower(trim($c['email'])) : '';
    $vpe = array_map(fn($v) => is_string($v) ? mb_strtolower($v) : '', (array) ($c['verified_primary_email'] ?? []));
    if ($email === '' && $vpe && $vpe[0] !== '') {
        $email = $vpe[0];
    }
    $edov = $c['xms_edov'] ?? null;
    $verified = $email !== '' && (strtolower((string) ($c['tid'] ?? '')) === SSO_MS_CONSUMERS || $edov === true || $edov === 1 || $edov === '1' || $edov === 'true' || in_array($email, $vpe, true));
    return [$email, $verified];
}

// ---------- HTTP (vyměnitelné kvůli testům) ----------

/** Nastaví (nebo vrátí) funkci pro HTTP: fn(string $method, string $url, array $headers, ?string $body): ['status' => int, 'body' => string]. */
function sso_http(?callable $set = null, bool $reset = false): callable
{
    static $fn = null;
    if ($reset) {
        $fn = null;
    }
    if ($set) {
        $fn = $set;
    }
    return $fn ?? 'sso_http_default';
}
function sso_http_default(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'SVJ-web-SSO/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $res === false ? 0 : $status, 'body' => $res === false ? '' : (string) $res];
    }
    $ctx =stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 10, 'ignore_errors' => true,
        'user_agent' => 'SVJ-web-SSO/1.0'], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $res === false ? 0 : $status, 'body' => $res === false ? '' : $res];
}
/** Požadavek s odpovědí v JSON; u POST se $data posílá jako formulář. */
function sso_json(string $method, string $url, array $data = []): array
{
    $headers = ['Accept: application/json'];
    $body = null;
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
    $r = (sso_http())($method, $url, $headers, $body);
    $j = json_decode((string) ($r['body'] ?? ''), true);
    if ((int) ($r['status'] ?? 0) !== 200 || !is_array($j)) {
        $why = is_array($j) ? (string) ($j['error_description'] ?? (is_string($j['error'] ?? null) ? $j['error'] : ($j['error']['message'] ?? ''))) : '';
        throw new SsoError('HTTP ' . (int) ($r['status'] ?? 0) . ' z ' . (string) parse_url($url, PHP_URL_HOST) . ($why !== '' ? ': ' . mb_substr($why, 0, 200) : ''));
    }
    return $j;
}

// ---------- JWT, JWK (RFC 7515, 7517, 7518) ----------

const SSO_JWKS = [
    'google' => 'https://www.googleapis.com/oauth2/v3/certs',                          // https://developers.google.com/identity/openid-connect/openid-connect#discovery
    'microsoft' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',    // https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens#validate-the-signature
    'apple' => 'https://appleid.apple.com/auth/keys',                                  // https://developer.apple.com/documentation/signinwithapplerestapi/fetch_apple_s_public_key_for_verifying_token_signature
];

/** Klíče poskytovatele (JWKS), uložené na den; $refresh = stáhnout znovu (poskytovatel klíče střídá). */
function sso_jwks(string $p, bool $refresh = false): array
{
    $f = sso_dir() . '/jwks-' . $p . '.json';
    if (!$refresh && is_file($f) && filemtime($f) > time() - 86400) {
        $keys = json_decode((string) file_get_contents($f), true);
        if (is_array($keys['keys'] ?? null)) {
            return $keys['keys'];
        }
    }
    if (!isset(SSO_JWKS[$p])) {
        throw new SsoError('Poskytovatel nemá klíče JWKS.');
    }
    $j = sso_json('GET', SSO_JWKS[$p]);
    if (!is_array($j['keys'] ?? null)) {
        throw new SsoError('Klíče poskytovatele mají neočekávaný tvar.');
    }
    file_put_contents($f, json_encode(['keys' => $j['keys']]), LOCK_EX);
    return $j['keys'];
}
/** Rozloží JWT na [hlavička, obsah, podepsaná část, podpis]. */
function sso_jwt_parts(string $jwt): array
{
    $p = explode('.', $jwt);
    if (count($p) !== 3 || !preg_match('/^[A-Za-z0-9_-]+$/', $p[0] . $p[1]) || !preg_match('/^[A-Za-z0-9_-]*$/', $p[2])) {
        throw new SsoError('ID token nemá tvar JWT.');
    }
    $h = json_decode(b64url_decode($p[0]), true);
    $c = json_decode(b64url_decode($p[1]), true);
    if (!is_array($h) || !is_array($c)) {
        throw new SsoError('ID token nejde přečíst.');
    }
    return [$h, $c, $p[0] . '.' . $p[1], b64url_decode($p[2])];
}
/**
 * Ověří ID token: podpis klíčem poskytovatele (jen RS256/ES256 – nikdy „none“ ani HMAC), vydavatele, příjemce (aud, azp),
 * platnost (exp, iat) a nonce. Vrátí obsah tokenu, jinak vyhodí SsoError.
 * OIDC Core 1.0 §3.1.3.7: https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
 */
function sso_verify_id_token(string $jwt, string $p, string $aud, string $nonce, callable $issOk, ?int $now = null): array
{
    [$h, $c, $signed, $sig] = sso_jwt_parts($jwt);
    $alg = (string) ($h['alg'] ?? '');
    if (!in_array($alg, ['RS256', 'ES256'], true)) {
        throw new SsoError('Nepodporovaný podpis ID tokenu: ' . $alg);
    }
    $kid = (string) ($h['kid'] ?? '');
    $key = null;
    foreach ([false, true] as $refresh) {
        foreach (sso_jwks($p, $refresh) as $k) {
            if (is_array($k) && ($k['kid'] ?? '') === $kid && ($k['use'] ?? 'sig') === 'sig') {
                $key = $k;
                break 2;
            }
        }
    }
    if (!$key || !sso_jws_verify($alg, $signed, $sig, $key)) {
        throw new SsoError('Podpis ID tokenu nesouhlasí.');
    }
    $now ??= time();
    if (!$issOk($c)) {
        throw new SsoError('ID token vydal někdo jiný (iss).');
    }
    $auds = is_array($c['aud'] ?? null) ? $c['aud'] : [$c['aud'] ?? null];
    if (!in_array($aud, $auds, true) || (count($auds) > 1 && ($c['azp'] ?? '') !== $aud)) {
        throw new SsoError('ID token je určený jiné aplikaci (aud).');
    }
    if (!is_numeric($c['exp'] ?? null) || (int) $c['exp'] < $now - SSO_LEEWAY) {
        throw new SsoError('ID token vypršel.');
    }
    if (isset($c['iat']) && (!is_numeric($c['iat']) || (int) $c['iat'] > $now + SSO_LEEWAY)) {
        throw new SsoError('ID token je vystaven v budoucnosti.');
    }
    if ($nonce !== '' && !hash_equals($nonce, is_string($c['nonce'] ?? null) ? $c['nonce'] : '')) {
        throw new SsoError('Nonce v ID tokenu nesouhlasí.');
    }
    return $c;
}
/** Ověří podpis JWS (RS256 nebo ES256) veřejným klíčem ve tvaru JWK. */
function sso_jws_verify(string $alg, string $data, string $sig, array $jwk): bool
{
    try {
        if ($alg === 'RS256' && ($jwk['kty'] ?? '') === 'RSA') {
            return openssl_verify($data, $sig, sso_jwk_pem($jwk), OPENSSL_ALGO_SHA256) === 1;
        }
        if ($alg === 'ES256' && ($jwk['kty'] ?? '') === 'EC' && ($jwk['crv'] ?? '') === 'P-256' && strlen($sig) === 64) {
            return openssl_verify($data, sso_ecdsa_der(substr($sig, 0, 32), substr($sig, 32)), sso_jwk_pem($jwk), OPENSSL_ALGO_SHA256) === 1;
        }
    } catch (SsoError) {
    }
    return false;
}
function sso_der(int $tag, string $content): string
{
    $len = strlen($content);
    if ($len < 0x80) {
        return chr($tag) . chr($len) . $content;
    }
    $l = ltrim(pack('N', $len), "\0");
    return chr($tag) . chr(0x80 | strlen($l)) . $l . $content;
}
function sso_der_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || ord($bytes[0]) & 0x80) {
        $bytes = "\0" . $bytes;
    }
    return sso_der(0x02, $bytes);
}
/** Veřejný klíč JWK (RSA, nebo EC P-256) jako PEM (SubjectPublicKeyInfo). */
function sso_jwk_pem(array $jwk): string
{
    if (($jwk['kty'] ?? '') === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
        $n = b64url_decode($jwk['n']);
        $e = b64url_decode($jwk['e']);
        if (strlen($n) < 256 || $e === '') {
            throw new SsoError('Klíč RSA je příliš krátký nebo poškozený.');   // aspoň 2048 bitů
        }
        $algo = sso_der(0x30, "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00");
        $spki = sso_der(0x30, $algo . sso_der(0x03, "\0" . sso_der(0x30, sso_der_int($n) . sso_der_int($e))));
    } elseif (($jwk['kty'] ?? '') === 'EC' && ($jwk['crv'] ?? '') === 'P-256' && is_string($jwk['x'] ?? null) && is_string($jwk['y'] ?? null)) {
        $x = b64url_decode($jwk['x']);
        $y = b64url_decode($jwk['y']);
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new SsoError('Klíč EC je poškozený.');
        }
        $algo = sso_der(0x30, "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07");
        $spki = sso_der(0x30, $algo . sso_der(0x03, "\0\x04" . $x . $y));
    } else {
        throw new SsoError('Nepodporovaný typ klíče.');
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/** Podpis ECDSA r||s (JWS) → DER, jak ho chce OpenSSL. */
function sso_ecdsa_der(string $r, string $s): string
{
    return sso_der(0x30, sso_der_int($r) . sso_der_int($s));
}
/** Podpis ECDSA v DER (z OpenSSL) → r||s po 32 bajtech (JWS ES256). */
function sso_ecdsa_raw(string $der): string
{
    $pos = 2 + ((ord($der[1]) & 0x80) ? (ord($der[1]) & 0x7f) : 0);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$pos]) !== 0x02) {
            throw new SsoError('Neplatný podpis ECDSA.');
        }
        $len = ord($der[$pos + 1]);
        $out .= str_pad(substr(ltrim(substr($der, $pos + 2, $len), "\0"), -32), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}
/** Podepíše JWT algoritmem ES256 (klíč P-256 v PEM). */
function sso_jwt_es256(array $header, array $claims, string $pem): string
{
    $key = openssl_pkey_get_private($pem);
    if (!$key) {
        throw new SsoError('Soukromý klíč nejde načíst.');
    }
    $data = b64url(json_encode($header + ['alg' => 'ES256'], JSON_UNESCAPED_SLASHES)) . '.' . b64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new SsoError('Podpis klíčem se nepovedl.');
    }
    return $data . '.' . b64url(sso_ecdsa_raw($der));
}
/**
 * Apple: client_secret je JWT podepsaný klíčem .p8 (ES256), kid = Key ID, iss = Team ID, sub = Services ID.
 * https://developer.apple.com/documentation/accountorganizationaldatasharing/creating-a-client-secret
 */
function sso_apple_client_secret(array $cfg, ?int $now = null): string
{
    $now ??= time();
    $pem = trim((string) ($cfg['private_key'] ?? '')) !== '' ? (string) $cfg['private_key'] : (string) @file_get_contents((string) $cfg['private_key_path']);
    return sso_jwt_es256(['kid' => $cfg['key_id'], 'typ' => 'JWT'],
        ['iss' => $cfg['team_id'], 'iat' => $now, 'exp' => $now + 600, 'aud' => 'https://appleid.apple.com', 'sub' => $cfg['client_id']], $pem);
}

// ---------- Propojené účty ----------

/** Tabulka propojení v databázi domu (založí se při prvním použití). */
function sso_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    if (isset($done[spl_object_id($pdo)])) {
        return;
    }
    $done[spl_object_id($pdo)] = true;
    sso_table($pdo, 'user_sso', '');
}
/** Tabulka propojení v databázi služby (partneři a hlavní správci; kind = partner | super). */
function sso_platform_ready(): void
{
    static $done = false;
    if (!$done) {
        $done = true;
        sso_table(platform_db(), 'platform_sso', 'kind');
    }
}
function sso_table(PDO $pdo, string $table, string $kindCol): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $kind = $kindCol !== '' ? "$kindCol {$s(10)}, " : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS $table (id " . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", $kind"
        . 'user_id ' . ($mysql ? 'INT' : 'INTEGER') . " NOT NULL, provider {$s(20)}, subject {$s(191)}, email {$s(191)}, linked_at {$s(19)})"
        . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    try {
        $pdo->exec("CREATE UNIQUE INDEX {$table}_subject ON $table (" . ($kindCol !== '' ? "$kindCol, " : '') . 'provider, subject)');
        $pdo->exec("CREATE INDEX {$table}_user ON $table (" . ($kindCol !== '' ? "$kindCol, " : '') . 'user_id)');
    } catch (PDOException) {
        // index už existuje
    }
}
/** Propojení uživatele webu domu: provider => řádek. */
function sso_links(int $uid): array
{
    sso_ready();
    return array_column(all('SELECT * FROM user_sso WHERE user_id = ? ORDER BY provider', [$uid]), null, 'provider');
}
/**
 * Najde účet webu domu pro ověřenou identitu. Vrací ['user' => řádek] nebo ['error' => kód]:
 * no_account, unverified (bez ověřeného e-mailu a bez propojení), invite (účet čeká na přijetí pozvánky).
 * Účet se nikdy nezakládá.
 */
function sso_site_resolve(array $id): array
{
    sso_ready();
    $link = one('SELECT * FROM user_sso WHERE provider = ? AND subject = ?', [$id['provider'], $id['subject']]);
    $u = null;
    if ($link) {
        $u = one('SELECT * FROM users WHERE id = ?', [(int) $link['user_id']]);
        if (!$u) {
            q('DELETE FROM user_sso WHERE id = ?', [$link['id']]);   // účet mezitím smazán
        }
    }
    if (!$u) {
        if ($id['email'] === '' || !$id['email_verified']) {
            return ['error' => 'unverified'];
        }
        $u = one('SELECT * FROM users WHERE lower(email) = lower(?)', [$id['email']]);
    }
    if (!$u || !(int) $u['active']) {
        return ['error' => 'no_account'];
    }
    if ($u['password_hash'] === NO_PASSWORD) {
        return ['error' => 'invite'];
    }
    return ['user' => $u];
}
/** Propojí účet webu domu s identitou poskytovatele. Vrací null, nebo kód chyby (linked_other). */
function sso_site_link(int $uid, array $id): ?string
{
    sso_ready();
    $other = one('SELECT user_id FROM user_sso WHERE provider = ? AND subject = ?', [$id['provider'], $id['subject']]);
    if ($other && (int) $other['user_id'] !== $uid && one('SELECT 1 FROM users WHERE id = ?', [(int) $other['user_id']])) {
        return 'linked_other';
    }
    q('DELETE FROM user_sso WHERE (user_id = ? AND provider = ?) OR (provider = ? AND subject = ?)', [$uid, $id['provider'], $id['provider'], $id['subject']]);
    q('INSERT INTO user_sso (user_id, provider, subject, email, linked_at) VALUES (?,?,?,?,?)', [$uid, $id['provider'], $id['subject'], $id['email'], date('Y-m-d H:i:s')]);
    return null;
}
function sso_site_unlink(int $uid, string $provider): bool
{
    sso_ready();
    return q('DELETE FROM user_sso WHERE user_id = ? AND provider = ?', [$uid, $provider])->rowCount() > 0;
}
/** Propojení v databázi služby: provider => řádek. $kind = partner | super. */
function sso_platform_links(string $kind, int $uid): array
{
    sso_platform_ready();
    return array_column(pq('SELECT * FROM platform_sso WHERE kind = ? AND user_id = ? ORDER BY provider', [$kind, $uid])->fetchAll(), null, 'provider');
}
/** Najde pracovníka partnera (kind partner) nebo hlavního správce (kind super) – stejná pravidla jako u webu domu. */
function sso_platform_resolve(string $kind, array $id): array
{
    sso_platform_ready();
    $find = fn(string $where, array $args) => ($kind === 'partner'
        ? pq("SELECT u.* FROM partner_users u JOIN partners p ON p.id = u.partner_id WHERE $where AND u.active = 1 AND p.active = 1", $args)->fetch()
        : pq('SELECT u.* FROM super_admins u WHERE ' . $where, $args)->fetch()) ?: null;
    $link = pq('SELECT * FROM platform_sso WHERE kind = ? AND provider = ? AND subject = ?', [$kind, $id['provider'], $id['subject']])->fetch();
    $u = $link ? $find('u.id = ?', [(int) $link['user_id']]) : null;
    if (!$u) {
        if ($id['email'] === '' || !$id['email_verified']) {
            return ['error' => 'unverified'];
        }
        $u = $find('lower(u.email) = lower(?)', [$id['email']]);
    }
    if (!$u) {
        return ['error' => 'no_account'];
    }
    if (in_array((string) $u['password_hash'], ['', NO_PASSWORD], true)) {
        return ['error' => 'invite'];
    }
    return ['user' => $u];
}
function sso_platform_link(string $kind, int $uid, array $id): ?string
{
    sso_platform_ready();
    $other = pq('SELECT user_id FROM platform_sso WHERE kind = ? AND provider = ? AND subject = ?', [$kind, $id['provider'], $id['subject']])->fetch();
    if ($other && (int) $other['user_id'] !== $uid) {
        return 'linked_other';
    }
    pq('DELETE FROM platform_sso WHERE kind = ? AND ((user_id = ? AND provider = ?) OR (provider = ? AND subject = ?))', [$kind, $uid, $id['provider'], $id['provider'], $id['subject']]);
    pq('INSERT INTO platform_sso (kind, user_id, provider, subject, email, linked_at) VALUES (?,?,?,?,?,?)', [$kind, $uid, $id['provider'], $id['subject'], $id['email'], date('Y-m-d H:i:s')]);
    return null;
}
function sso_platform_unlink(string $kind, int $uid, string $provider): bool
{
    sso_platform_ready();
    return pq('DELETE FROM platform_sso WHERE kind = ? AND user_id = ? AND provider = ?', [$kind, $uid, $provider])->rowCount() > 0;
}

// ---------- Texty ----------

/** Srozumitelná hláška pro uživatele. */
function sso_message(string $code, string $provider, string $email = ''): string
{
    $n = SSO_PROVIDERS[$provider] ?? $provider;
    return match ($code) {
        'denied' => 'Přihlášení přes ' . $n . ' jste zrušili.',
        'no_account' => 'S ' . ($email !== '' ? 'e-mailem ' . $email : 'tímto účtem ' . $n) . ' tu nemáme žádný účet. Přihlášení přes ' . $n . ' účty nezakládá – přístup Vám zřídí výbor (nebo správce). '
            . 'Máte-li účet s jiným e-mailem, přihlaste se heslem a v části Můj účet si ' . $n . ' propojte.',
        'unverified' => $n . ' nám nepotvrdil ověřený e-mail, podle kterého bychom Váš účet našli. Přihlaste se prosím heslem a v části Můj účet si účet ' . $n . ' propojte – pak už přihlášení půjde.',
        'invite' => 'Váš účet ještě není aktivní. Nejdřív prosím přijměte pozvánku z e-mailu a nastavte si heslo.',
        'blocked' => 'Příliš mnoho neúspěšných pokusů. Zkuste to prosím znovu za 15 minut.',
        'expired' => 'Odkaz pro dokončení přihlášení už neplatí (platí 2 minuty a jen v prohlížeči, ve kterém jste přihlášení začali). Zkuste to prosím znovu.',
        'linked_other' => 'Tento účet ' . $n . ' už je propojený s jiným uživatelem.',
        'link_wrong_user' => 'Propojení nešlo dokončit – mezitím jste se odhlásili nebo přihlásili jako někdo jiný.',
        default => 'Přihlášení přes ' . $n . ' se nepodařilo. Zkuste to prosím znovu, nebo se přihlaste e-mailem a heslem.',
    };
}
/** Jednoduchá loga (vložené SVG – CSP nedovoluje obrázky z cizích adres). */
function sso_logo(string $p): string
{
    $svg = match ($p) {
        'google' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
        'microsoft' => '<svg viewBox="0 0 21 21" aria-hidden="true" focusable="false"><path fill="#F25022" d="M1 1h9v9H1z"/><path fill="#7FBA00" d="M11 1h9v9h-9z"/><path fill="#00A4EF" d="M1 11h9v9H1z"/><path fill="#FFB900" d="M11 11h9v9h-9z"/></svg>',
        'apple' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.37 12.73c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.73-1.77-3.32-1.8-1.41-.14-2.76.83-3.47.83-.72 0-1.82-.81-3-.79-1.54.02-2.96.9-3.76 2.28-1.6 2.78-.41 6.9 1.15 9.16.76 1.1 1.67 2.34 2.86 2.3 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.23-.02 2.01-1.12 2.77-2.23.87-1.28 1.23-2.52 1.25-2.58-.03-.01-2.4-.92-2.42-3.66zM14.1 6.03c.63-.77 1.06-1.83.94-2.9-.91.04-2.01.61-2.67 1.37-.58.67-1.1 1.76-.96 2.8 1.02.08 2.06-.52 2.69-1.27z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#1877F2"/><path fill="#fff" d="M15.12 7.32H16.9V4.3a23 23 0 0 0-2.6-.14c-2.58 0-4.34 1.62-4.34 4.6v2.56H7.06v3.38h2.9V24h3.5v-9.3h2.84l.45-3.38h-3.29V9.1c0-.98.27-1.78 1.66-1.78z"/></svg>',
        default => '',
    };
    return '<span class="sso-logo">' . $svg . '</span>';
}
/** Tlačítka „Přihlásit přes …“ na přihlašovací stránce. $ctx = site | partner | console. */
function sso_buttons(string $ctx): string
{
    $list = sso_available($ctx);
    if (!$list) {
        return '';
    }
    $start = $ctx === 'site' ? '/prihlaseni/sso/' : '/sso/';
    $out = '<div class="sso-box"><p class="sso-or"><span>nebo</span></p><div class="sso-buttons">';
    foreach ($list as $p => $label) {
        $out .= '<a class="btn btn-ghost sso-btn sso-' . $p . '" href="' . e(url($start . $p)) . '" rel="nofollow">' . sso_logo($p) . 'Přihlásit přes ' . e($label) . '</a>';
    }
    $hint = $ctx === 'site' ? 'Funguje jen pro e-mail, se kterým už na webu máte účet, nebo pro účet, který jste si propojili v části Můj účet.'
        : 'Funguje jen pro e-mail, se kterým už máte účet, nebo pro účet propojený v části Můj účet.';
    return $out . '</div><p class="hint">' . e($hint) . '</p></div>';
}
/**
 * Část „Přihlášení přes další účty“ na stránce Můj účet. $ctx = site | partner | console; $uid = přihlášený uživatel.
 * Propojení se zapíše až po úspěšném přihlášení u poskytovatele.
 */
function sso_account_box(string $ctx, int $uid): string
{
    $list = sso_available($ctx);
    if (!$list) {
        return '';
    }
    $links = $ctx === 'site' ? sso_links($uid) : sso_platform_links($ctx === 'partner' ? 'partner' : 'super', $uid);
    $base = $ctx === 'site' ? '/muj-ucet/sso/' : '/sso/';
    $out = '<section class="household" id="sso"><h2>Přihlášení přes další účty' . (function_exists('beta_is') && beta_is('sso') ? ' <span class="tag tag-beta">Beta</span>' : '') . '</h2><div class="card form">'
        . '<p>Propojte si účet Google, Microsoft, Apple nebo Facebook a přihlašujte se jedním kliknutím. Propojení se hodí hlavně tehdy, když '
        . 'u poskytovatele používáte jiný e-mail než tady (nebo u Apple skrytý e-mail). Heslo ani dvoufázové ověření se tím nemění.'
        . ($ctx === 'site' && defined('HELP') && isset(HELP['prihlaseni-sso']) ? ' <a href="' . e(url('/napoveda/prihlaseni-sso')) . '">Jak propojení funguje</a>' : '') . '</p><ul class="sso-links">';
    foreach ($list as $p => $label) {
        $l = $links[$p] ?? null;
        $out .= '<li>' . sso_logo($p) . '<span><strong>' . e($label) . '</strong> ';
        if ($l) {
            $out .= '<span class="status status-done">Propojeno</span>' . ($l['email'] !== '' ? ' <small class="muted">' . e($l['email']) . '</small>' : '') . '</span>'
                . '<form method="post" action="' . e(url($base . $p . '/odpojit')) . '" class="inline-form" data-confirm="Zrušit propojení s účtem ' . e($label) . '?">' . csrf_field()
                . '<button class="btn btn-small btn-ghost" type="submit">Zrušit propojení</button></form>';
        } else {
            $out .= '<small class="muted">nepropojeno</small></span><form method="post" action="' . e(url($base . $p . '/propojit')) . '" class="inline-form">' . csrf_field()
                . '<button class="btn btn-small" type="submit">Propojit účet</button></form>';
        }
        $out .= '</li>';
    }
    return $out . '</ul></div></section>';
}
/** Přehled pro konzoli (Nastavení služby): kteří poskytovatelé jsou nastavení, návratové adresy a přepínače. Bez tajných údajů. */
function sso_console_section(): string
{
    ob_start();
    require __DIR__ . '/../views/ext/sso/console.php';
    return (string) ob_get_clean();
}

// ---------- Obsluha adres (sdílená webem domu, platformou, portálem a konzolí) ----------

/** Návratová adresa /sso/callback/<poskytovatel>: ověří přihlášení a pošle prohlížeč s jednorázovým tokenem tam, kde začalo. */
function sso_callback_respond(string $p): never
{
    $params = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;   // Apple posílá odpověď jako form_post
    header('Cache-Control: no-store');
    try {
        if (!sso_provider_config($p)) {
            throw new SsoError('Přihlášení přes ' . (SSO_PROVIDERS[$p] ?? $p) . ' tu není nastavené.');
        }
        $r = sso_callback($p, $params);
        $to = sso_handoff_url($r['flow'], $r['token']);
    } catch (SsoError $e) {
        http_response_code(400);
        render(platform_enabled() ? 'platform/message' : 'error', ['title' => 'Přihlášení se nepodařilo', 'message' => $e->getMessage()]);
        exit;
    }
    header('Location: ' . $to, true, 303);
    exit;
}
/** Zápis do záznamů služby (partnerský portál a konzole). */
function sso_platform_log(string $ctx, string $email, string $action, string $detail = ''): void
{
    $action = $ctx === 'partner' ? 'Partner: ' . $action : mb_strtoupper(mb_substr($action, 0, 1)) . mb_substr($action, 1);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), $email, client_ip(), $action, mb_substr($detail, 0, 500)]);
}
/**
 * Adresy SSO v partnerském portálu (ctx partner, relativně k /partner) a v konzoli (ctx console, relativně k /sprava):
 *   GET  /sso/<p>              – přihlášení,          POST /sso/<p>/propojit, /sso/<p>/odpojit – propojení v Můj účet,
 *   GET  /sso/vstup/<token>    – dokončení po návratu od poskytovatele.
 * Volá se před přihlašovací stránkou (app/ext/auth_*_sso.php). $me = přihlášený uživatel nebo null,
 * $signIn = přihlášení (closure portálu/konzole), $blocked/$failed = ochrana proti hádání.
 */
function sso_platform_routes(string $ctx, string $path, string $method, ?array $me, callable $signIn, callable $blocked, callable $failed): void
{
    if (!str_starts_with($path, '/sso/')) {
        return;
    }
    $kind = $ctx === 'partner' ? 'partner' : 'super';
    if ($method === 'GET' && preg_match('~^/sso/vstup/([a-f0-9]{64})$~', $path, $m)) {
        $h = sso_handoff_take($m[1], $ctx);
        if (!$h) {
            flash(sso_message('expired', ''), 'error');
            redirect('/');
        }
        $p = (string) $h['provider'];
        $label = SSO_PROVIDERS[$p] ?? $p;
        if (isset($h['error'])) {
            sso_platform_log($ctx, $me['email'] ?? '', 'přihlášení přes ' . $label . ' se nepodařilo', (string) $h['error']);
            flash(sso_message((string) $h['error'], $p), 'error');
            redirect($h['mode'] === 'link' && $me ? '/ucet' : '/');
        }
        $id = (array) $h['identity'];
        if ($h['mode'] === 'link') {
            if (!$me || (int) $me['id'] !== (int) $h['uid']) {
                flash(sso_message('link_wrong_user', $p), 'error');
                redirect('/');
            }
            if ($err = sso_platform_link($kind, (int) $me['id'], $id)) {
                flash(sso_message($err, $p), 'error');
                redirect('/ucet');
            }
            sso_platform_log($ctx, $me['email'], 'propojení účtu ' . $label, $id['email']);
            flash('Účet ' . $label . ' je propojený. Příště se můžete přihlásit tlačítkem „Přihlásit přes ' . $label . '“.');
            redirect('/ucet');
        }
        if ($blocked()) {
            flash(sso_message('blocked', $p), 'error');
            redirect('/');
        }
        $r = sso_platform_resolve($kind, $id);
        if (isset($r['error'])) {
            $failed();
            sso_platform_log($ctx, '', 'neúspěšné přihlášení přes ' . $label, ($id['email'] !== '' ? $id['email'] : 'bez e-mailu') . ' · ' . $r['error']);
            flash(sso_message((string) $r['error'], $p, $id['email']), 'error');
            redirect('/');
        }
        $u = $r['user'];
        sso_platform_log($ctx, $u['email'], 'přihlášení přes ' . $label . ($u['totp_enabled'] ? ' (následuje ověřovací kód)' : ''));
        if ($u['totp_enabled']) {
            session_regenerate_id(true);
            if ($ctx === 'partner') {
                $_SESSION['ppending'] = ['id' => (int) $u['id'], 'at' => time()];
            } else {
                $_SESSION = ['t' => '#platform', 'spending' => ['id' => (int) $u['id'], 'at' => time()]];
            }
            redirect('/overeni');
        }
        $signIn($u);
    }
    if (!preg_match('~^/sso/(google|microsoft|apple|facebook)(?:/(propojit|odpojit))?$~', $path, $m) || !isset(sso_available($ctx)[$m[1]])) {
        sso_not_found();
    }
    $p = $m[1];
    $action = $m[2] ?? '';
    if ($action === '' && $method === 'GET') {
        if ($me) {
            redirect('/');
        }
        header('Location: ' . sso_start($p, $ctx), true, 303);
        exit;
    }
    if (!$me || $method !== 'POST' || ($ctx === 'partner' && !empty($GLOBALS['PARTNER_DEMO']))) {
        redirect('/');
    }
    if ($action === 'propojit') {
        redirect_external(sso_start($p, $ctx, ['mode' => 'link', 'uid' => (int) $me['id']]), 'Pokračovat na ' . SSO_PROVIDERS[$p]);   // po odeslání formuláře (CSP form-action)
    }
    if (sso_platform_unlink($kind, (int) $me['id'], $p)) {
        sso_platform_log($ctx, $me['email'], 'zrušení propojení účtu ' . SSO_PROVIDERS[$p]);
        flash('Propojení s účtem ' . SSO_PROVIDERS[$p] . ' je zrušené.');
    }
    redirect('/ucet');
}
function sso_not_found(): never
{
    http_response_code(404);
    render('platform/message', ['title' => 'Stránka nenalezena', 'message' => 'Tato stránka neexistuje.']);
    exit;
}

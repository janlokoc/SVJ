<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Společný základ testů modulu I (SSO): falešní poskytovatelé s vlastními klíči (vygenerovanými OpenSSL),
 * falešný HTTP přenos (žádná síť) a nastavení všech čtyř poskytovatelů.
 */
abstract class ModISsoCase extends TestCase
{
    /** Zaznamenané HTTP požadavky: [['method', 'url', 'headers', 'body'], …]. */
    protected array $requests = [];
    /** ID uživatelů domu založených testem (v tearDown se smažou). */
    private array $created = [];
    /** Úklid záznamů v databázi služby (partneři, hlavní správci) po testu. */
    protected array $cleanup = [];
    private static array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['SSO_CONFIG'] = self::config();
        $this->requests = [];
        foreach (glob(ROOT_STORAGE . '/sso/jwks-*.json') ?: [] as $f) {
            unlink($f);
        }
        pset_save('beta_sso', 'on');
        pset_save('sso_partner', '');
        pset_save('sso_console', '');
        unset($GLOBALS['DEMO']);
    }
    protected function tearDown(): void
    {
        sso_http(null, true);
        // Uživatelé z testu zmizí, ať neovlivní testy jiných modulů (počty obyvatel a správců ukázkového domu).
        if ($this->created) {
            sso_ready();
        }
        foreach ($this->created as $id) {
            q('DELETE FROM user_sso WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        $this->created = [];
        foreach ($this->cleanup as $fn) {
            $fn();
        }
        $this->cleanup = [];
        unset($GLOBALS['SSO_CONFIG'], $GLOBALS['DEMO']);
        pset_save('beta_sso', 'off');
        parent::tearDown();
    }

    protected static function config(): array
    {
        return [
            'google' => ['client_id' => 'g-client.apps.googleusercontent.com', 'client_secret' => 'g-secret-XYZ'],
            'microsoft' => ['client_id' => '11111111-2222-3333-4444-555555555555', 'client_secret' => 'ms-secret-XYZ', 'tenant' => 'common'],
            'facebook' => ['app_id' => '1234567890', 'app_secret' => 'fb-secret-XYZ'],
            'apple' => ['client_id' => 'cz.example.web', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key_path' => '', 'private_key' => self::ecPem()],
        ];
    }

    /** RSA klíč poskytovatele (2048 b) a jeho JWK. */
    protected static function rsa(string $kid = 'k1'): array
    {
        if (!isset(self::$keys['rsa-' . $kid])) {
            $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $d = openssl_pkey_get_details($k);
            self::$keys['rsa-' . $kid] = [$k, ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => b64url($d['rsa']['n']), 'e' => b64url($d['rsa']['e'])], $d['key']];
        }
        return self::$keys['rsa-' . $kid];
    }
    /** EC P-256 klíč a jeho JWK. */
    protected static function ec(string $kid = 'e1'): array
    {
        if (!isset(self::$keys['ec-' . $kid])) {
            $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $d = openssl_pkey_get_details($k);
            self::$keys['ec-' . $kid] = [$k, ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'crv' => 'P-256', 'x' => b64url(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => b64url(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))], $d['key']];
        }
        return self::$keys['ec-' . $kid];
    }
    protected static function ecPem(): string
    {
        openssl_pkey_export(self::ec('apple-p8')[0], $pem);
        return $pem;
    }
    /** Podepsaný JWT. */
    protected static function jwt(array $claims, array $header = [], ?array $key = null): string
    {
        $key ??= self::rsa();
        $alg = $header['alg'] ?? ($key[1]['kty'] === 'EC' ? 'ES256' : 'RS256');
        $data = b64url(json_encode($header + ['alg' => $alg, 'kid' => $key[1]['kid'], 'typ' => 'JWT'])) . '.' . b64url(json_encode($claims));
        openssl_sign($data, $sig, $key[0], OPENSSL_ALGO_SHA256);
        return $data . '.' . b64url($alg === 'ES256' ? sso_ecdsa_raw($sig) : $sig);
    }
    protected static function jwks(array ...$keys): array
    {
        return ['status' => 200, 'body' => json_encode(['keys' => array_map(fn($k) => $k[1], $keys ?: [self::rsa()])])];
    }
    protected static function ok(array $json): array
    {
        return ['status' => 200, 'body' => json_encode($json)];
    }
    /** Falešný HTTP přenos: předpona adresy => odpověď nebo fn(method, url, headers, body). */
    protected function fakeHttp(array $routes): void
    {
        sso_http(function (string $m, string $url, array $h, ?string $body) use ($routes) {
            $this->requests[] = ['method' => $m, 'url' => $url, 'headers' => $h, 'body' => $body];
            foreach ($routes as $prefix => $r) {
                if (str_starts_with($url, $prefix)) {
                    return is_callable($r) ? $r($m, $url, $h, $body) : $r;
                }
            }
            return ['status' => 404, 'body' => '{"error":"not_found"}'];
        });
    }
    /** Začne tok a vrátí [query parametry autorizační adresy, záznam toku]. */
    protected function begin(string $p, string $ctx = 'site', array $opts = []): array
    {
        $url = sso_start($p, $ctx, $opts + ($ctx === 'site' ? ['tenant' => 'dum'] : []));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $id = explode('.', $q['state'])[0];
        $flow = json_decode((string) file_get_contents(ROOT_STORAGE . '/sso/flow-' . $id . '.json'), true);
        return [$q + ['_url' => $url], $flow];
    }
    /** Obsah ID tokenu, který by vydal Google pro daný tok. */
    protected static function googleClaims(array $flow, array $over = []): array
    {
        return $over + ['iss' => 'https://accounts.google.com', 'aud' => 'g-client.apps.googleusercontent.com', 'sub' => '1098765', 'email' => 'Jana.Test@Example.org',
            'email_verified' => true, 'name' => 'Jana Testová', 'iat' => time(), 'exp' => time() + 3600, 'nonce' => $flow['nonce']];
    }
    /** Google: token endpoint vrátí ID token s danými claimy, JWKS vrátí klíč. */
    protected function fakeGoogle(callable $claims): void
    {
        $this->fakeHttp([
            'https://oauth2.googleapis.com/token' => fn() => self::ok(['access_token' => 'at', 'id_token' => self::jwt($claims()), 'token_type' => 'Bearer']),
            'https://www.googleapis.com/oauth2/v3/certs' => self::jwks(),
        ]);
    }
    protected function makeUser(string $email, array $over = []): array
    {
        $over += ['name' => 'Test ' . $email, 'password_hash' => password_hash('heslo-pro-test-1', PASSWORD_DEFAULT), 'role' => 'resident', 'active' => 1, 'totp_enabled' => 0];
        q('INSERT INTO users (email, name, password_hash, role, active, totp_enabled) VALUES (?,?,?,?,?,?)', [$email, $over['name'], $over['password_hash'], $over['role'], $over['active'], $over['totp_enabled']]);
        $this->created[] = (int) db()->lastInsertId();
        return one('SELECT * FROM users WHERE id = ?', [end($this->created)]);
    }
    protected static function identity(string $p, string $sub, string $email = '', bool $verified = true): array
    {
        return ['provider' => $p, 'subject' => $sub, 'email' => $email, 'email_verified' => $verified && $email !== '', 'name' => '', 'private_email' => false];
    }
}

<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Podvržená služba Bank iD: odpovědi podle adresy, zapisuje požadavky. Nic nejde do sítě. */
final class ModBFakeBankid implements \BankidTransport
{
    public array $routes = [];
    public array $log = [];

    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->log[] = [$method, $url, $headers, $body];
        $key = $method . ' ' . strtok($url, '?');
        if (!isset($this->routes[$key])) {
            return ['status' => 404, 'body' => '{}'];
        }
        $r = $this->routes[$key];
        return is_callable($r) ? $r($headers, $body) : $r;
    }
}

/**
 * Modul B: Bank iD (OpenID Connect s PKCE, ověření ID tokenu přes JWKS, PS512 i RS256) – app/ext/bankid_lib.php,
 * a celé ověření hlasu přes Bank iD (rollam_bankid_start/finish). Klíče RSA se generují v testu (OpenSSL).
 */
final class ModBBankidTest extends TestCase
{
    private const ISS = 'https://oidc.sandbox.bankid.cz/';
    private static ?\OpenSSLAsymmetricKey $key = null;
    private static ?\OpenSSLAsymmetricKey $otherKey = null;
    private ModBFakeBankid $fake;
    private array $saved = [];

    public static function setUpBeforeClass(): void
    {
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new ModBFakeBankid();
        bankid_transport($this->fake);
        bankid_settings(['client_id' => 'svj-test', 'client_secret' => 'tajne', 'issuer' => 'sandbox', 'redirect_uri' => 'https://dum.test.invalid/bankid/navrat']);
        $this->fake->routes['GET ' . self::ISS . '.well-known/openid-configuration'] = ['status' => 200, 'body' => json_encode($this->disc())];
        $this->fake->routes['GET ' . self::ISS . '.well-known/jwks'] = ['status' => 200, 'body' => json_encode(['keys' => [self::jwk(self::$otherKey, 'old'), self::jwk(self::$key, 'k1')]])];
    }

    protected function tearDown(): void
    {
        bankid_transport(null, true);
        bankid_settings(null, true);
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$k]);
                pset('__reset');
            } else {
                pset_save($k, $v);
            }
        }
        $this->saved = [];
    }

    private function platformSetting(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->saved)) {
            $r = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetch();
            $this->saved[$key] = $r ? (string) $r['value'] : null;
        }
        pset_save($key, $value);
    }

    private function disc(): array
    {
        return ['issuer' => self::ISS, 'authorization_endpoint' => self::ISS . 'auth', 'token_endpoint' => self::ISS . 'token', 'userinfo_endpoint' => self::ISS . 'userinfo',
            'jwks_uri' => self::ISS . '.well-known/jwks', 'id_token_signing_alg_values_supported' => ['PS512'], 'code_challenge_methods_supported' => ['plain', 'S256']];
    }

    private static function jwk(\OpenSSLAsymmetricKey $k, string $kid): array
    {
        $d = openssl_pkey_get_details($k)['rsa'];
        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'PS512', 'n' => bankid_b64url($d['n']), 'e' => bankid_b64url($d['e'])];
    }

    /** RSASSA-PSS podpis (EMSA-PSS, MGF1, sůl = délka hashe) – nezávislá implementace pro testy. */
    private static function pssSign(string $data, \OpenSSLAsymmetricKey $key, string $hash): string
    {
        $modBits = openssl_pkey_get_details($key)['bits'];
        $emBits = $modBits - 1;
        $emLen = intdiv($emBits + 7, 8);
        $hLen = strlen(hash($hash, '', true));
        $salt = random_bytes($hLen);
        $h = hash($hash, str_repeat("\0", 8) . hash($hash, $data, true) . $salt, true);
        $db = str_repeat("\0", $emLen - $hLen - $hLen - 2) . "\x01" . $salt;
        $masked = $db ^ bankid_mgf1($h, strlen($db), $hash);
        $masked[0] = chr(ord($masked[0]) & (0xff >> (8 * $emLen - $emBits)));
        $em = $masked . $h . "\xbc";
        if ($emLen < intdiv($modBits + 7, 8)) {
            $em = "\0" . $em;
        }
        openssl_private_encrypt($em, $sig, $key, OPENSSL_NO_PADDING);
        return $sig;
    }

    private static function token(array $claims, array $head = ['alg' => 'PS512', 'kid' => 'k1'], ?\OpenSSLAsymmetricKey $key = null): string
    {
        $key ??= self::$key;
        $input = bankid_b64url(json_encode($head)) . '.' . bankid_b64url(json_encode($claims));
        $alg = $head['alg'] ?? '';
        if (str_starts_with($alg, 'PS')) {
            $sig = self::pssSign($input, $key, BANKID_ALGS[$alg]);
        } elseif (str_starts_with($alg, 'RS')) {
            openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
        } else {
            $sig = 'x';
        }
        return $input . '.' . bankid_b64url($sig);
    }

    private function claims(array $over = []): array
    {
        return $over + ['iss' => self::ISS, 'aud' => 'svj-test', 'sub' => 'person-123', 'exp' => time() + 300, 'iat' => time(), 'nonce' => 'n0nce'];
    }

    private function verify(string $jwt, string $nonce = 'n0nce'): array|string
    {
        $cfg = bankid_config();
        $disc = bankid_discovery($cfg);
        return bankid_verify_id_token($jwt, $cfg, $disc, bankid_jwks($disc), $nonce);
    }

    // ---------- konfigurace ----------

    public function testConfigIssuerAliasesAndMissingValues(): void
    {
        $c = bankid_config();
        $this->assertSame(self::ISS, $c['issuer']);
        $this->assertTrue($c['sandbox']);
        bankid_settings(['client_id' => 'a', 'client_secret' => 'b', 'issuer' => 'production']);
        $c = bankid_config();
        $this->assertSame('https://oidc.bankid.cz/', $c['issuer']);
        $this->assertFalse($c['sandbox']);
        $this->assertStringEndsWith('/bankid/navrat', $c['redirect_uri'], 'redirect URI defaults to this site');
        bankid_settings(['client_id' => 'a', 'client_secret' => 'b', 'issuer' => 'http://evil.example/']);
        $this->assertNull(bankid_config(), 'plain http issuer refused');
        bankid_settings(['client_id' => 'a', 'client_secret' => '']);
        $this->assertNull(bankid_config());
        bankid_settings(null, true);
        $this->assertNull(bankid_config(), 'test config.php has no bankid section');
    }

    public function testBetaGatingOffByDefault(): void
    {
        $this->platformSetting('beta_bankid', 'off');
        $this->assertSame('off', beta_stage('bankid'));
        $this->assertFalse(bankid_available());
        $this->assertArrayNotHasKey('bankid', rollam_methods_available());
        $this->platformSetting('beta_bankid', 'beta');
        $this->assertTrue(bankid_available(), 'demo house is Premium');
        $this->assertArrayHasKey('bankid', rollam_methods_available());
        bankid_settings(['client_id' => '', 'client_secret' => '']);
        $this->assertFalse(bankid_available(), 'not offered without client credentials');
        $this->assertArrayHasKey('bankid', BETA_FEATURES);
    }

    // ---------- kryptografie ----------

    public function testPkcePair(): void
    {
        [$v, $c] = bankid_pkce();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]{43,128}$/', $v);
        $this->assertSame(bankid_b64url(hash('sha256', $v, true)), $c);
        $this->assertNotSame($v, bankid_pkce()[0]);
    }

    public function testJwkToPemMatchesOpenSsl(): void
    {
        $pem = openssl_pkey_get_details(self::$key)['key'];
        $this->assertSame($pem, bankid_jwk_pem(self::jwk(self::$key, 'k1')));
        $this->assertNull(bankid_jwk_pem(['kty' => 'EC', 'x' => 'a', 'y' => 'b']));
        $small = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNull(bankid_jwk_pem(self::jwk($small, 's')), 'keys under 2048 bits refused');
        $this->assertNull(bankid_jwk_pem(['kty' => 'RSA', 'n' => 'a+b/', 'e' => 'AQAB']), 'invalid base64url');
    }

    public function testPssVerifyAgainstOpenSslCommandLine(): void
    {
        $dir = sys_get_temp_dir() . '/modb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            openssl_pkey_export(self::$key, $priv);
            file_put_contents("$dir/k.pem", $priv);
            file_put_contents("$dir/m.txt", 'hlavicka.obsah');
            exec('openssl dgst -sha512 -sign ' . escapeshellarg("$dir/k.pem") . ' -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:digest -out '
                . escapeshellarg("$dir/s.bin") . ' ' . escapeshellarg("$dir/m.txt") . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                $this->markTestSkipped('openssl command line tool not available');
            }
            $pem = openssl_pkey_get_details(self::$key)['key'];
            $sig = file_get_contents("$dir/s.bin");
            $this->assertTrue(bankid_pss_verify('hlavicka.obsah', $sig, $pem, 'sha512'));
            $this->assertFalse(bankid_pss_verify('hlavicka.obsaH', $sig, $pem, 'sha512'));
            $this->assertFalse(bankid_pss_verify('hlavicka.obsah', $sig, $pem, 'sha256'), 'wrong hash');
            $this->assertFalse(bankid_pss_verify('hlavicka.obsah', $sig, openssl_pkey_get_details(self::$otherKey)['key'], 'sha512'), 'wrong key');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testPssRejectsMalformedSignatures(): void
    {
        $pem = openssl_pkey_get_details(self::$key)['key'];
        $sig = self::pssSign('data', self::$key, 'sha512');
        $this->assertTrue(bankid_pss_verify('data', $sig, $pem, 'sha512'));
        $this->assertFalse(bankid_pss_verify('data', substr($sig, 1), $pem, 'sha512'), 'wrong length');
        $this->assertFalse(bankid_pss_verify('data', str_repeat("\0", 256), $pem, 'sha512'));
        $bad = $sig;
        $bad[100] = chr(ord($bad[100]) ^ 1);
        $this->assertFalse(bankid_pss_verify('data', $bad, $pem, 'sha512'));
        $this->assertFalse(bankid_pss_verify('data', $sig, 'not a key', 'sha512'));
    }

    // ---------- ID token ----------

    public function testValidTokensAccepted(): void
    {
        $c = $this->verify(self::token($this->claims()));
        $this->assertIsArray($c, is_string($c) ? $c : '');
        $this->assertSame('person-123', $c['sub']);
        $this->assertIsArray($this->verify(self::token($this->claims(['aud' => ['other', 'svj-test']]))), 'aud as array');
        $this->assertIsArray($this->verify(self::token($this->claims(['iss' => rtrim(self::ISS, '/')]))), 'issuer trailing slash tolerated');
        $this->assertIsArray($this->verify(self::token($this->claims(), ['alg' => 'RS256', 'kid' => 'k1'])), 'RS256 also accepted');
        $this->assertIsArray($this->verify(self::token($this->claims(), ['alg' => 'PS512'])), 'without kid every key is tried');
    }

    public function testInvalidTokensRejected(): void
    {
        $cases = [
            'alg none' => self::token($this->claims(), ['alg' => 'none']),
            'HS256' => self::token($this->claims(), ['alg' => 'HS256', 'kid' => 'k1']),
            'foreign key' => self::token($this->claims(), ['alg' => 'PS512', 'kid' => 'k1'], self::$otherKey),
            'unknown kid' => self::token($this->claims(), ['alg' => 'PS512', 'kid' => 'nope']),
            'wrong issuer' => self::token($this->claims(['iss' => 'https://evil.example/'])),
            'wrong audience' => self::token($this->claims(['aud' => 'someone-else'])),
            'expired' => self::token($this->claims(['exp' => time() - 120])),
            'no exp' => self::token(array_diff_key($this->claims(), ['exp' => 1])),
            'future iat' => self::token($this->claims(['iat' => time() + 3600])),
            'nonce' => self::token($this->claims(['nonce' => 'other'])),
            'no sub' => self::token($this->claims(['sub' => ''])),
            'garbage' => 'abc',
            'bad base64' => 'a*b.c.d',
        ];
        foreach ($cases as $label => $jwt) {
            $this->assertIsString($this->verify($jwt), $label);
        }
        // změna obsahu po podpisu
        [$h, , $s] = explode('.', self::token($this->claims()));
        $this->assertSame('Podpis tokenu Bank iD nesouhlasí.', $this->verify($h . '.' . bankid_b64url(json_encode($this->claims(['sub' => 'attacker']))) . '.' . $s));
    }

    // ---------- tok přihlášení s podvrženou službou ----------

    public function testAuthUrlHasPkceStateNonceAndScopes(): void
    {
        $cfg = bankid_config();
        $url = bankid_auth_url($cfg, bankid_discovery($cfg), 'st', 'nn', 'chal');
        $this->assertStringStartsWith(self::ISS . 'auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['client_id' => 'svj-test', 'redirect_uri' => 'https://dum.test.invalid/bankid/navrat', 'response_type' => 'code', 'scope' => 'openid profile.name profile.birthdate',
            'state' => 'st', 'nonce' => 'nn', 'code_challenge' => 'chal', 'code_challenge_method' => 'S256', 'ui_locales' => 'cs'], $q);
    }

    private function provider(string $nonce, array $user = []): void
    {
        $this->fake->routes['POST ' . self::ISS . 'token'] = fn($h, $body) => ['status' => 200, 'body' => json_encode(['access_token' => 'AT', 'token_type' => 'Bearer',
            'id_token' => self::token($this->claims(['nonce' => $nonce]))])];
        $this->fake->routes['GET ' . self::ISS . 'userinfo'] = fn($h) => ($h['Authorization'] ?? '') === 'Bearer AT'
            ? ['status' => 200, 'body' => json_encode($user + ['sub' => 'person-123', 'given_name' => 'Jan', 'family_name' => 'Novák', 'birthdate' => '1980-05-01'])]
            : ['status' => 401, 'body' => '{}'];
    }

    public function testCompleteFlowReturnsVerifiedPerson(): void
    {
        $this->provider('nn');
        $p = bankid_complete(bankid_config(), 'CODE', 'VERIFIER', 'nn');
        $this->assertSame(['sub' => 'person-123', 'given_name' => 'Jan', 'family_name' => 'Novák', 'birthdate' => '1980-05-01'], $p);
        $tokenCall = array_values(array_filter($this->fake->log, fn($l) => $l[0] === 'POST'))[0];
        parse_str($tokenCall[3], $form);
        $this->assertSame(['grant_type' => 'authorization_code', 'code' => 'CODE', 'redirect_uri' => 'https://dum.test.invalid/bankid/navrat', 'client_id' => 'svj-test',
            'client_secret' => 'tajne', 'code_verifier' => 'VERIFIER'], $form);
        foreach ($this->fake->log as [, $url]) {
            $this->assertStringStartsWith(self::ISS, $url, 'only the configured issuer is contacted');
        }
    }

    public function testCompleteFlowErrors(): void
    {
        $this->provider('nn', ['sub' => 'someone-else']);
        $this->assertSame('Údaje o osobě nepatří k přihlášení.', bankid_complete(bankid_config(), 'C', 'V', 'nn'));
        $this->provider('nn', ['given_name' => '']);
        $this->assertStringContainsString('profile.name', (string) bankid_complete(bankid_config(), 'C', 'V', 'nn'));
        $this->provider('other-nonce');
        $this->assertStringContainsString('nonce', (string) bankid_complete(bankid_config(), 'C', 'V', 'nn'));
        $this->fake->routes['POST ' . self::ISS . 'token'] = ['status' => 400, 'body' => '{"error":"invalid_grant"}'];
        $this->assertStringContainsString('výměna kódu', (string) bankid_complete(bankid_config(), 'C', 'V', 'nn'));
        bankid_discovery(null);
        $this->fake->routes['GET ' . self::ISS . '.well-known/openid-configuration'] = ['status' => 200, 'body' => json_encode(['token_endpoint' => 'http://x/'] + $this->disc())];
        $this->assertStringContainsString('nezabezpečenou', (string) bankid_complete(bankid_config(), 'C', 'V', 'nn'));
        bankid_discovery(null);
        $this->fake->routes['GET ' . self::ISS . '.well-known/openid-configuration'] = ['status' => 0, 'body' => ''];
        $this->assertStringContainsString('neodpověděla', (string) bankid_complete(bankid_config(), 'C', 'V', 'nn'));
    }

    public function testCurlTransportRefusesNonHttps(): void
    {
        $this->assertSame(['status' => 0, 'body' => ''], (new \BankidCurlTransport())->send('GET', 'http://example.invalid/'));
    }

    public function testNameMatching(): void
    {
        $p = ['given_name' => 'Jan Petr', 'family_name' => 'Novák'];
        $this->assertTrue(bankid_name_matches($p, ['Jan Novák']));
        $this->assertTrue(bankid_name_matches($p, ['', 'SJM Novák Jan a Nováková Eva']), 'owner from the cadastre, accents ignored');
        $this->assertTrue(bankid_name_matches(['given_name' => 'EVA', 'family_name' => 'Nováková'], ['SJM Novák Jan a Nováková Eva']));
        $this->assertFalse(bankid_name_matches($p, ['Jana Nováková']));
        $this->assertFalse(bankid_name_matches($p, ['Petr Novák']), 'first given name must match');
        $this->assertFalse(bankid_name_matches(['given_name' => 'Jan', 'family_name' => 'Novák Svoboda'], ['Jan Novák']), 'all surname words');
        $this->assertFalse(bankid_name_matches(['given_name' => '', 'family_name' => 'Novák'], ['Novák']));
    }

    // ---------- hlasování s ověřením přes Bank iD ----------

    public function testVoteVerifiedThroughBankid(): void
    {
        $this->platformSetting('beta_bankid', 'beta');
        $tag = bin2hex(random_bytes(3));
        q('INSERT INTO units (entrance, number, share, owner_note) VALUES (?,?,?,?)', ['B' . $tag, '1', '1/10', 'Novák Jan']);
        $unit = (int) db()->lastInsertId();
        q("INSERT INTO users (email, name, password_hash, role, kind) VALUES (?,?,?,'resident','owner')", ["bid-$tag@example.cz", 'Honza N.', NO_PASSWORD]);
        $uid = (int) db()->lastInsertId();
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$uid, $unit]);
        q('INSERT INTO polls (title, opens_at, closes_at, threshold) VALUES (?,?,?,50)', ['Bank iD ' . $tag, date('Y-m-d'), date('Y-m-d', time() + 20 * 86400)]);
        $poll = (int) db()->lastInsertId();
        q('INSERT INTO poll_items (poll_id, question) VALUES (?,?)', [$poll, 'Bod']);
        $item = (int) db()->lastInsertId();
        try {
            $this->assertNull(rollam_set_method($poll, 'bankid'));
            $user = one('SELECT * FROM users WHERE id = ?', [$uid]);
            $r = rollam_begin(one('SELECT * FROM polls WHERE id = ?', [$poll]), $user, $unit, [$item => 'yes']);
            $this->assertSame('bankid', $r['status']);
            $this->assertSame([], rollam_unit_votes($poll, $unit));

            $this->assertIsArray(rollam_bankid_start($r['pending'], $uid + 1), 'someone else cannot start it');
            $url = rollam_bankid_start($r['pending'], $uid);
            $this->assertIsString($url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->assertSame($_SESSION['bankid']['state'], $q['state']);
            $this->assertSame(bankid_b64url(hash('sha256', $_SESSION['bankid']['verifier'], true)), $q['code_challenge']);

            // zfalšovaný state: hlas se neuloží a relace se zahodí
            $saved = $_SESSION['bankid'];
            $this->assertFalse(rollam_bankid_finish('forged', 'C', '', $uid)['ok']);
            $this->assertArrayNotHasKey('bankid', $_SESSION);

            $_SESSION['bankid'] = $saved;
            $this->provider($saved['nonce']);
            $res = rollam_bankid_finish($saved['state'], 'CODE', '', $uid);
            $this->assertTrue($res['ok'], $res['error'] ?? '');
            $this->assertSame($poll, $res['poll']);
            $this->assertSame([$item => 'yes'], rollam_unit_votes($poll, $unit));
            $rec = rollam_records($poll)[0];
            $this->assertSame('bankid', $rec['method']);
            $this->assertSame('Bank iD (sandbox): Jan Novák, nar. 1980-05-01', $rec['verified']);
            $this->assertTrue(rollam_chain_check($poll)['ok']);
            $this->assertSame(hash('sha256', 'bankid|person-123'), val('SELECT sub_hash FROM rollam_identities WHERE user_id = ?', [$uid]));

            // jiná osoba u téhož účtu příště neprojde
            $this->assertNotNull(rollam_identity_check($user, one('SELECT * FROM units WHERE id = ?', [$unit]), ['sub' => 'person-999', 'given_name' => 'Jan', 'family_name' => 'Novák', 'birthdate' => '']));
            // jméno, které nesedí s evidencí, neprojde
            q('DELETE FROM rollam_identities WHERE user_id = ?', [$uid]);
            $this->assertStringContainsString('nesouhlasí', (string) rollam_identity_check($user, one('SELECT * FROM units WHERE id = ?', [$unit]), ['sub' => 'x', 'given_name' => 'Karel', 'family_name' => 'Dvořák', 'birthdate' => '']));

            // zrušení v bance
            q('DELETE FROM poll_votes WHERE item_id = ?', [$item]);
            $r = rollam_begin(one('SELECT * FROM polls WHERE id = ?', [$poll]), $user, $unit, [$item => 'no']);
            rollam_bankid_start($r['pending'], $uid);
            $res = rollam_bankid_finish($_SESSION['bankid']['state'], '', 'access_denied', $uid);
            $this->assertFalse($res['ok']);
            $this->assertSame('failed', rollam_pending($r['pending'])['status']);
            $this->assertSame([], rollam_unit_votes($poll, $unit));

            // vypnutí funkce v konzoli: hlas přes Bank iD se nezahájí
            $this->platformSetting('beta_bankid', 'off');
            $this->assertSame('error', rollam_begin(one('SELECT * FROM polls WHERE id = ?', [$poll]), $user, $unit, [$item => 'no'])['status']);
        } finally {
            q('DELETE FROM poll_votes WHERE item_id = ?', [$item]);
            q('DELETE FROM poll_items WHERE poll_id = ?', [$poll]);
            q('DELETE FROM polls WHERE id = ?', [$poll]);
            foreach (['rollam_records', 'rollam_pending', 'rollam_polls'] as $t) {
                q("DELETE FROM $t WHERE poll_id = ?", [$poll]);
            }
            q('DELETE FROM rollam_identities WHERE user_id = ?', [$uid]);
            q('DELETE FROM user_units WHERE user_id = ?', [$uid]);
            q('DELETE FROM users WHERE id = ?', [$uid]);
            q('DELETE FROM units WHERE id = ?', [$unit]);
            unset($_SESSION['bankid']);
        }
    }
}

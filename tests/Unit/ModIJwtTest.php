<?php
declare(strict_types=1);

namespace Tests\Unit;

require_once __DIR__ . '/ModISsoCase.php';

/** Modul I: JWK → PEM, podpisy RS256/ES256, ověření ID tokenu a client_secret pro Apple (app/ext/sso_lib.php). */
final class ModIJwtTest extends ModISsoCase
{
    private function verify(string $jwt, array $over = []): array
    {
        $o = $over + ['p' => 'google', 'aud' => 'aud-1', 'nonce' => 'n-1', 'iss' => fn(array $c) => ($c['iss'] ?? '') === 'https://issuer.test', 'now' => null];
        return sso_verify_id_token($jwt, $o['p'], $o['aud'], $o['nonce'], $o['iss'], $o['now']);
    }
    private static function claims(array $over = []): array
    {
        return $over + ['iss' => 'https://issuer.test', 'aud' => 'aud-1', 'sub' => 's-1', 'iat' => time(), 'exp' => time() + 600, 'nonce' => 'n-1'];
    }
    private function expectRejected(string $jwt, string $why, array $over = []): void
    {
        try {
            $this->verify($jwt, $over);
            $this->fail('Token měl být odmítnut: ' . $why);
        } catch (\SsoError $e) {
            $this->assertNotSame('', $e->getMessage(), $why);
        }
    }

    public function testRsaJwkConvertsToTheSamePublicKey(): void
    {
        [, $jwk, $pem] = self::rsa();
        $ours = openssl_pkey_get_details(openssl_pkey_get_public(sso_jwk_pem($jwk)));
        $this->assertSame($pem, $ours['key']);
        $this->assertSame(2048, $ours['bits']);
    }

    public function testEcJwkConvertsToTheSamePublicKey(): void
    {
        [, $jwk, $pem] = self::ec();
        $this->assertSame($pem, openssl_pkey_get_details(openssl_pkey_get_public(sso_jwk_pem($jwk)))['key']);
    }

    public function testShortOrBrokenKeysAreRefused(): void
    {
        $k = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($k)['rsa'];
        foreach ([['kty' => 'RSA', 'n' => b64url($d['n']), 'e' => b64url($d['e'])], ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'AAAA', 'y' => 'AAAA'],
            ['kty' => 'EC', 'crv' => 'P-384', 'x' => 'a', 'y' => 'b'], ['kty' => 'oct', 'k' => 'secret'], []] as $jwk) {
            try {
                sso_jwk_pem($jwk);
                $this->fail('Klíč měl být odmítnut: ' . json_encode($jwk));
            } catch (\SsoError) {
                $this->addToAssertionCount(1);
            }
        }
        // Podpis krátkým klíčem neprojde ani přes sso_jws_verify (bez výjimky ven).
        openssl_sign('data', $sig, $k, OPENSSL_ALGO_SHA256);
        $this->assertFalse(sso_jws_verify('RS256', 'data', $sig, ['kty' => 'RSA', 'n' => b64url($d['n']), 'e' => b64url($d['e'])]));
    }

    public function testEcdsaSignatureConversionRoundTrips(): void
    {
        [$key, $jwk] = self::ec();
        $pem = sso_jwk_pem($jwk);
        // Víc pokusů: r nebo s občas začíná nulou či bajtem ≥ 0x80 (v DER pak má 31 nebo 33 bajtů).
        for ($i = 0; $i < 40; $i++) {
            $data = 'zprava-' . $i;
            openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256);
            $raw = sso_ecdsa_raw($der);
            $this->assertSame(64, strlen($raw));
            $this->assertSame(1, openssl_verify($data, sso_ecdsa_der(substr($raw, 0, 32), substr($raw, 32)), $pem, OPENSSL_ALGO_SHA256));
            $this->assertTrue(sso_jws_verify('ES256', $data, $raw, $jwk));
        }
        $this->assertFalse(sso_jws_verify('ES256', 'jina zprava', $raw, $jwk));
        $this->assertFalse(sso_jws_verify('ES256', $data, substr($raw, 1), $jwk), 'podpis jiné délky');
        $this->assertFalse(sso_jws_verify('RS256', $data, $raw, $jwk), 'algoritmus nesedí k typu klíče');
    }

    public function testEs256JwtSignedByUsVerifies(): void
    {
        $pem = self::ecPem();
        $jwt = sso_jwt_es256(['kid' => 'x'], ['a' => 1], $pem);
        [$h, $c, $signed, $sig] = sso_jwt_parts($jwt);
        $this->assertSame(['kid' => 'x', 'alg' => 'ES256'], $h);
        $this->assertSame(['a' => 1], $c);
        $this->assertTrue(sso_jws_verify('ES256', $signed, $sig, self::ec('apple-p8')[1]));
    }

    public function testValidRs256TokenIsAccepted(): void
    {
        $this->fakeHttp(['https://www.googleapis.com/oauth2/v3/certs' => self::jwks()]);
        $c = $this->verify(self::jwt(self::claims(['email' => 'a@b.cz'])));
        $this->assertSame('s-1', $c['sub']);
        $this->assertSame('a@b.cz', $c['email']);
        // Klíče se uloží: druhé ověření už JWKS nestahuje.
        $this->verify(self::jwt(self::claims()));
        $this->assertCount(1, $this->requests);
    }

    public function testValidEs256TokenIsAccepted(): void
    {
        $this->fakeHttp(['https://appleid.apple.com/auth/keys' => self::jwks(self::ec())]);
        $c = $this->verify(self::jwt(self::claims(), [], self::ec()), ['p' => 'apple']);
        $this->assertSame('s-1', $c['sub']);
    }

    public function testRejectsForgedOrMismatchedTokens(): void
    {
        $this->fakeHttp(['https://www.googleapis.com/oauth2/v3/certs' => self::jwks()]);
        $good = self::jwt(self::claims());
        [$h, $p, $s] = explode('.', $good);
        $this->expectRejected($h . '.' . b64url(json_encode(self::claims(['sub' => 'attacker']))) . '.' . $s, 'změněný obsah');
        $this->expectRejected(b64url('{"alg":"none","kid":"k1"}') . '.' . $p . '.', 'alg none');
        $hs = b64url('{"alg":"HS256","kid":"k1"}') . '.' . $p;
        $this->expectRejected($hs . '.' . b64url(hash_hmac('sha256', $hs, self::rsa()[2], true)), 'HS256 s veřejným klíčem jako tajemstvím');
        $this->expectRejected(self::jwt(self::claims(['aud' => 'jina-aplikace'])), 'aud');
        $this->expectRejected(self::jwt(self::claims(['aud' => ['aud-1', 'jina']])), 'víc aud bez azp');
        $this->expectRejected(self::jwt(self::claims(['iss' => 'https://evil.test'])), 'iss');
        $this->expectRejected(self::jwt(self::claims(['exp' => time() - 3600])), 'exp');
        $this->expectRejected(self::jwt(self::claims(['iat' => time() + 3600])), 'iat v budoucnosti');
        $this->expectRejected(self::jwt(self::claims(['nonce' => 'jiny'])), 'nonce');
        $this->expectRejected(self::jwt(array_diff_key(self::claims(), ['nonce' => 1])), 'chybí nonce');
        $this->expectRejected(self::jwt(array_diff_key(self::claims(), ['exp' => 1])), 'chybí exp');
        $this->expectRejected(self::jwt(self::claims(), [], self::rsa('cizi')), 'klíč, který poskytovatel nemá');
        $this->expectRejected('abc.def', 'není JWT');
        $this->expectRejected('a$.b.c', 'neplatné znaky');
        // S azp odpovídajícím aplikaci je víc příjemců v pořádku.
        $this->assertSame('s-1', $this->verify(self::jwt(self::claims(['aud' => ['aud-1', 'jina'], 'azp' => 'aud-1'])))['sub']);
        // Tolerance hodin: token prošlý před minutou ještě projde.
        $this->assertSame('s-1', $this->verify(self::jwt(self::claims(['exp' => time() - 60])))['sub']);
    }

    public function testRotatedKeyIsFetchedAgain(): void
    {
        $served = [self::rsa()];
        $this->fakeHttp(['https://www.googleapis.com/oauth2/v3/certs' => function () use (&$served) {
            return self::jwks(...$served);
        }]);
        $this->verify(self::jwt(self::claims()));   // uloží JWKS s klíčem k1
        $served = [self::rsa(), self::rsa('k2')];       // poskytovatel přidal nový klíč
        $c = $this->verify(self::jwt(self::claims(), [], self::rsa('k2')));
        $this->assertSame('s-1', $c['sub']);
        $this->assertCount(2, $this->requests, 'neznámý kid → jedno nové stažení klíčů');
    }

    public function testJwksFailureIsAnError(): void
    {
        $this->fakeHttp(['https://www.googleapis.com/oauth2/v3/certs' => ['status' => 500, 'body' => 'oops']]);
        $this->expectException(\SsoError::class);
        $this->verify(self::jwt(self::claims()));
    }

    public function testAppleClientSecretIsSignedJwtWithRequiredClaims(): void
    {
        $cfg = sso_provider_config('apple');
        $now = 1_800_000_000;
        $jwt = sso_apple_client_secret($cfg, $now);
        [$h, $c, $signed, $sig] = sso_jwt_parts($jwt);
        $this->assertSame('ES256', $h['alg']);
        $this->assertSame('KEY1234567', $h['kid']);
        $this->assertSame(['iss' => 'TEAM123456', 'iat' => $now, 'exp' => $now + 600, 'aud' => 'https://appleid.apple.com', 'sub' => 'cz.example.web'], $c);
        $this->assertTrue(sso_jws_verify('ES256', $signed, $sig, self::ec('apple-p8')[1]));
        // Klíč ze souboru (.p8) funguje stejně.
        $file = ROOT_STORAGE . '/AuthKey_TEST.p8';
        file_put_contents($file, self::ecPem());
        $fromFile = sso_apple_client_secret(['private_key_path' => $file, 'private_key' => ''] + $cfg, $now);
        [, , $signed2, $sig2] = sso_jwt_parts($fromFile);
        $this->assertTrue(sso_jws_verify('ES256', $signed2, $sig2, self::ec('apple-p8')[1]));
        unlink($file);
    }
}

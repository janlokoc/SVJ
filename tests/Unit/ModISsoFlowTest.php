<?php
declare(strict_types=1);

namespace Tests\Unit;

require_once __DIR__ . '/ModISsoCase.php';

/**
 * Modul I: nastavení, zapínání, začátek přihlášení (PKCE, state, nonce), návrat od poskytovatelů Google, Microsoft,
 * Apple a Facebook s falešnými koncovými body a jednorázové předání na web domu.
 */
final class ModISsoFlowTest extends ModISsoCase
{
    public function testProvidersAppearOnlyWhenFullyConfigured(): void
    {
        $this->assertSame(SSO_PROVIDERS, sso_configured());
        $GLOBALS['SSO_CONFIG'] = [];
        $this->assertSame([], sso_configured());
        $this->assertSame(['client_id', 'client_secret'], sso_missing('google'));
        $this->assertSame(['app_id', 'app_secret'], sso_missing('facebook'));
        $this->assertSame(['client_id', 'team_id', 'key_id', 'private_key_path'], sso_missing('apple'));
        $GLOBALS['SSO_CONFIG'] = ['google' => ['client_id' => 'x', 'client_secret' => ' '], 'microsoft' => ['client_id' => 'a', 'client_secret' => 'b', 'tenant' => 'evil/../x'],
            'facebook' => ['app_id' => 'a', 'app_secret' => 'b', 'graph_version' => 'latest'],
            'apple' => ['client_id' => 'a', 'team_id' => 'b', 'key_id' => 'c', 'private_key_path' => '/neexistuje/AuthKey.p8']];
        $this->assertSame(['client_secret'], sso_missing('google'));
        $this->assertStringStartsWith('tenant', sso_missing('microsoft')[0]);
        $this->assertStringStartsWith('graph_version', sso_missing('facebook')[0]);
        $this->assertSame(['private_key_path (soubor nejde přečíst)'], sso_missing('apple'));
        $this->assertSame([], sso_configured());
        $this->assertNull(sso_provider_config('neznamy'));
        // Výchozí hodnoty.
        $GLOBALS['SSO_CONFIG'] = self::config();
        unset($GLOBALS['SSO_CONFIG']['microsoft']['tenant']);
        $this->assertSame('common', sso_provider_config('microsoft')['tenant']);
        $this->assertSame('v23.0', sso_provider_config('facebook')['graph_version']);
        $this->assertTrue(sso_provider_config('facebook')['trust_email']);
    }

    public function testEnabledPerPlaceAndBetaStage(): void
    {
        $this->assertTrue(sso_enabled('site'));
        $this->assertTrue(sso_enabled('partner'), 'partnerský portál: výchozí zapnuto');
        $this->assertFalse(sso_enabled('console'), 'konzole: výchozí vypnuto');
        pset_save('sso_console', '1');
        pset_save('sso_partner', '0');
        $this->assertTrue(sso_enabled('console'));
        $this->assertFalse(sso_enabled('partner'));
        pset_save('sso_partner', '1');
        pset_save('beta_sso', 'off');
        $this->assertFalse(sso_enabled('site'), 'beta vypnutá: weby domů bez SSO');
        $this->assertFalse(sso_enabled('partner'));
        $this->assertSame([], sso_available('site'));
        pset_save('beta_sso', 'beta');
        $this->assertTrue(sso_enabled('site'), 'ukázkový dům má edici Premium');
        $GLOBALS['DEMO'] = true;
        $this->assertFalse(sso_enabled('site'), 'ukázkový web se přihlašuje tlačítkem bez účtu');
        $this->assertFalse(sso_enabled('nesmysl'));
    }

    public function testLoginButtonsAndAccountBox(): void
    {
        $html = sso_buttons('site');
        foreach (SSO_PROVIDERS as $p => $label) {
            $this->assertStringContainsString('href="/prihlaseni/sso/' . $p . '"', $html);
            $this->assertStringContainsString('Přihlásit přes ' . $label, $html);
        }
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsString('<img', $html, 'CSP img-src self: žádné obrázky odjinud');
        $this->assertStringNotContainsString(' style=', $html, 'CSP style-src self: žádné vložené styly');
        $this->assertStringContainsString('href="/sso/google"', sso_buttons('partner'));
        $this->assertSame('', sso_buttons('console'));
        pset_save('beta_sso', 'off');
        $this->assertSame('', sso_buttons('site'));
        $this->assertSame('', sso_account_box('site', 1));
        pset_save('beta_sso', 'on');
        $u = $this->makeUser('box.' . bin2hex(random_bytes(3)) . '@example.org');
        sso_site_link((int) $u['id'], self::identity('apple', 'apple-sub-1', 'abc@privaterelay.appleid.com'));
        $box = sso_account_box('site', (int) $u['id']);
        $this->assertStringContainsString('/muj-ucet/sso/apple/odpojit', $box);
        $this->assertStringContainsString('abc@privaterelay.appleid.com', $box);
        $this->assertStringContainsString('/muj-ucet/sso/google/propojit', $box);
        $this->assertStringContainsString('name="_csrf"', $box);
    }

    public function testStateIsSignedAndBoundToProvider(): void
    {
        [$q] = $this->begin('google');
        $id = explode('.', $q['state'])[0];
        $this->assertSame($id, sso_state_id('google', $q['state']));
        $this->assertNull(sso_state_id('microsoft', $q['state']), 'state jiného poskytovatele');
        $this->assertNull(sso_state_id('google', $id . '.' . str_repeat('0', 32)));
        $this->assertNull(sso_state_id('google', bin2hex(random_bytes(16)) . '.' . explode('.', $q['state'])[1]));
        $this->assertNull(sso_state_id('google', '../../etc/passwd'));
        $this->assertContains($id, $_SESSION['sso_flows'], 'tok je svázaný s relací prohlížeče');
    }

    public function testSecretIsGeneratedOnceOrTakenFromConfig(): void
    {
        $s = sso_secret();
        $this->assertSame(64, strlen($s));
        $this->assertSame($s, sso_secret());
        $this->assertSame(0600, fileperms(ROOT_STORAGE . '/sso/secret.key') & 0777);
        $GLOBALS['SSO_CONFIG']['secret'] = str_repeat('k', 40);
        $this->assertSame(str_repeat('k', 40), sso_secret());
        $GLOBALS['SSO_CONFIG']['secret'] = 'kratke';
        $this->assertSame($s, sso_secret(), 'krátké tajemství z configu se nepoužije');
    }

    public function testRedirectUrisAndHandoffTargets(): void
    {
        $this->assertSame('http://test.invalid/sso/callback/google', sso_redirect_uri('google'));
        $GLOBALS['SSO_CONFIG']['redirect_base'] = 'https://spolecnyweb.cz/';
        $this->assertSame('https://spolecnyweb.cz/sso/callback/apple', sso_redirect_uri('apple'));
        $GLOBALS['SSO_CONFIG']['redirect_base'] = 'javascript:alert(1)';
        $this->assertSame('http://test.invalid/sso/callback/apple', sso_redirect_uri('apple'));
        $t = str_repeat('a', 64);
        $this->assertSame('http://dum.test.invalid/prihlaseni/sso/vstup/' . $t, sso_handoff_url(['ctx' => 'site', 'tenant' => 'dum'], $t));
        $this->assertSame('http://test.invalid/partner/sso/vstup/' . $t, sso_handoff_url(['ctx' => 'partner', 'tenant' => ''], $t));
        $this->assertSame('http://test.invalid/sprava/sso/vstup/' . $t, sso_handoff_url(['ctx' => 'console', 'tenant' => ''], $t));
        $this->expectException(\SsoError::class);
        sso_handoff_url(['ctx' => 'site', 'tenant' => 'neexistuje-' . bin2hex(random_bytes(3))], $t);
    }

    public function testAuthorizationUrlsCarryPkceStateAndNonce(): void
    {
        [$q, $flow] = $this->begin('google', 'site', ['after' => '/dokumenty']);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $q['_url']);
        $this->assertSame(['code', 'g-client.apps.googleusercontent.com', 'http://test.invalid/sso/callback/google', 'openid email profile', 'S256'],
            [$q['response_type'], $q['client_id'], $q['redirect_uri'], $q['scope'], $q['code_challenge_method']]);
        $this->assertSame(b64url(hash('sha256', $flow['verifier'], true)), $q['code_challenge']);
        $this->assertGreaterThanOrEqual(43, strlen($flow['verifier']), 'RFC 7636: 43–128 znaků');
        $this->assertSame($flow['nonce'], $q['nonce']);
        $this->assertSame(['site', 'dum', 'login', '/dokumenty'], [$flow['ctx'], $flow['tenant'], $flow['mode'], $flow['after']]);

        [$q] = $this->begin('microsoft');
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $q['_url']);
        $this->assertSame(['query', 'S256'], [$q['response_mode'], $q['code_challenge_method']]);
        $this->assertArrayHasKey('nonce', $q);

        [$q, $flow] = $this->begin('apple');
        $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $q['_url']);
        $this->assertSame(['form_post', 'name email', 'cz.example.web', $flow['nonce']], [$q['response_mode'], $q['scope'], $q['client_id'], $q['nonce']]);
        $this->assertArrayNotHasKey('code_challenge', $q, 'Apple PKCE nedokumentuje');

        [$q] = $this->begin('facebook');
        $this->assertStringStartsWith('https://www.facebook.com/v23.0/dialog/oauth?', $q['_url']);
        $this->assertSame(['1234567890', 'email public_profile', 'S256'], [$q['client_id'], $q['scope'], $q['code_challenge_method']]);

        // Nebezpečná adresa „po přihlášení“ se zahodí.
        [, $flow] = $this->begin('google', 'site', ['after' => '//evil.example/x']);
        $this->assertSame('', $flow['after']);
        $this->expectException(\SsoError::class);
        $GLOBALS['SSO_CONFIG'] = [];
        sso_start('google', 'site');
    }

    public function testGoogleFlowEndToEnd(): void
    {
        [$q, $flow] = $this->begin('google');
        $this->fakeGoogle(fn() => self::googleClaims($flow));
        $r = sso_callback('google', ['state' => $q['state'], 'code' => 'kod-123']);
        // Výměna kódu: správné parametry včetně code_verifier (PKCE) a tajného klíče klienta.
        $tokenReq = $this->requests[0];
        $this->assertSame(['POST', 'https://oauth2.googleapis.com/token'], [$tokenReq['method'], $tokenReq['url']]);
        parse_str($tokenReq['body'], $form);
        $this->assertSame(['authorization_code', 'kod-123', 'http://test.invalid/sso/callback/google', 'g-client.apps.googleusercontent.com', 'g-secret-XYZ', $flow['verifier']],
            [$form['grant_type'], $form['code'], $form['redirect_uri'], $form['client_id'], $form['client_secret'], $form['code_verifier']]);
        $this->assertFileDoesNotExist(ROOT_STORAGE . '/sso/flow-' . explode('.', $q['state'])[0] . '.json', 'tok je jednorázový');

        $this->assertStringStartsWith('http://dum.test.invalid/prihlaseni/sso/vstup/', sso_handoff_url($r['flow'], $r['token']));
        $h = sso_handoff_take($r['token'], 'site', 'dum');
        $this->assertSame(['provider' => 'google', 'subject' => '1098765', 'email' => 'jana.test@example.org', 'email_verified' => true, 'name' => 'Jana Testová', 'private_email' => false], $h['identity']);
        $this->assertSame('login', $h['mode']);
        $this->assertNull(sso_handoff_take($r['token'], 'site', 'dum'), 'předání jde použít jen jednou');
        $this->assertNotContains($h['flow'], $_SESSION['sso_flows']);
    }

    public function testGoogleUnverifiedEmailIsMarked(): void
    {
        [$q, $flow] = $this->begin('google');
        $this->fakeGoogle(fn() => self::googleClaims($flow, ['email_verified' => false]));
        $h = sso_handoff_take(sso_callback('google', ['state' => $q['state'], 'code' => 'k'])['token'], 'site', 'dum');
        $this->assertFalse($h['identity']['email_verified']);
    }

    public function testCallbackRejectsReplayExpiryAndForeignState(): void
    {
        [$q, $flow] = $this->begin('google');
        $this->fakeGoogle(fn() => self::googleClaims($flow));
        sso_callback('google', ['state' => $q['state'], 'code' => 'k']);
        foreach ([['google', ['state' => $q['state'], 'code' => 'k']], ['google', ['code' => 'k']], ['google', ['state' => ['x'], 'code' => 'k']]] as [$p, $params]) {
            try {
                sso_callback($p, $params);
                $this->fail('Návrat měl být odmítnut');
            } catch (\SsoError) {
                $this->addToAssertionCount(1);
            }
        }
        // state Googlu použitý u Microsoftu
        [$q2] = $this->begin('google');
        try {
            sso_callback('microsoft', ['state' => $q2['state'], 'code' => 'k']);
            $this->fail('state jiného poskytovatele');
        } catch (\SsoError) {
            $this->addToAssertionCount(1);
        }
        // prošlý tok
        [$q3] = $this->begin('google');
        $f = ROOT_STORAGE . '/sso/flow-' . explode('.', $q3['state'])[0] . '.json';
        $data = json_decode((string) file_get_contents($f), true);
        $data['created'] = time() - SSO_FLOW_TTL - 1;
        file_put_contents($f, json_encode($data));
        $this->expectException(\SsoError::class);
        sso_callback('google', ['state' => $q3['state'], 'code' => 'k']);
    }

    public function testProviderErrorsAreHandedBackToTheSite(): void
    {
        [$q] = $this->begin('google');
        $h = sso_handoff_take(sso_callback('google', ['state' => $q['state'], 'error' => 'access_denied'])['token'], 'site', 'dum');
        $this->assertSame('denied', $h['error']);
        $this->assertArrayNotHasKey('identity', $h);
        $this->assertSame([], $this->requests, 'bez kódu se nic nevyměňuje');

        [$q] = $this->begin('google');
        $this->fakeHttp(['https://oauth2.googleapis.com/token' => ['status' => 400, 'body' => '{"error":"invalid_grant"}']]);
        $h = sso_handoff_take(sso_callback('google', ['state' => $q['state'], 'code' => 'spatny'])['token'], 'site', 'dum');
        $this->assertSame('failed', $h['error']);

        [$q, $flow] = $this->begin('google');
        $this->fakeGoogle(fn() => self::googleClaims($flow, ['nonce' => 'podvrzeny']));
        $h = sso_handoff_take(sso_callback('google', ['state' => $q['state'], 'code' => 'k'])['token'], 'site', 'dum');
        $this->assertSame('failed', $h['error'], 'nonce nesouhlasí');
    }

    public function testHandoffIsBoundToBrowserPlaceAndTime(): void
    {
        $make = function (string $ctx = 'site') {
            [$q, $flow] = $this->begin('google', $ctx);
            $this->fakeGoogle(fn() => self::googleClaims($flow));
            return sso_callback('google', ['state' => $q['state'], 'code' => 'k'])['token'];
        };
        $t = $make();
        $saved = $_SESSION['sso_flows'];
        $_SESSION['sso_flows'] = [];   // odkaz otevřený v jiném prohlížeči (útočník poslal oběti svůj odkaz)
        $this->assertNull(sso_handoff_take($t, 'site', 'dum'));
        $_SESSION['sso_flows'] = $saved;
        $this->assertNull(sso_handoff_take($t, 'site', 'dum'), 'pokus spotřeboval předání');

        $t = $make();
        $this->assertNull(sso_handoff_take($t, 'site', 'jiny-dum'), 'jiný dům');
        $t = $make();
        $this->assertNull(sso_handoff_take($t, 'partner'), 'jiné místo');
        $t = $make('partner');
        $this->assertSame('partner', sso_handoff_take($t, 'partner')['ctx']);

        $t = $make();
        $f = ROOT_STORAGE . '/sso/hand-' . hash('sha256', $t) . '.json';
        $data = json_decode((string) file_get_contents($f), true);
        $data['expires'] = time() - 1;
        file_put_contents($f, json_encode($data));
        $this->assertNull(sso_handoff_take($t, 'site', 'dum'), 'prošlé');
        $this->assertNull(sso_handoff_take('nesmysl', 'site', 'dum'));
    }

    public function testMicrosoftPersonalAndWorkAccounts(): void
    {
        $run = function (array $claims, string $tenant = 'common'): array {
            $GLOBALS['SSO_CONFIG']['microsoft']['tenant'] = $tenant;
            [$q, $flow] = $this->begin('microsoft');
            $tid = $claims['tid'];
            $this->fakeHttp([
                'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token' => fn() => self::ok(['id_token' => self::jwt($claims + ['iss' => 'https://login.microsoftonline.com/' . $tid . '/v2.0',
                    'aud' => '11111111-2222-3333-4444-555555555555', 'sub' => 'ms-sub', 'iat' => time(), 'exp' => time() + 600, 'nonce' => $flow['nonce']])]),
                'https://login.microsoftonline.com/common/discovery/v2.0/keys' => self::jwks(),
            ]);
            return sso_handoff_take(sso_callback('microsoft', ['state' => $q['state'], 'code' => 'k'])['token'], 'site', 'dum');
        };
        $org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $h = $run(['tid' => SSO_MS_CONSUMERS, 'email' => 'pepa@outlook.com']);
        $this->assertTrue($h['identity']['email_verified'], 'osobní účet Microsoft: e-mail ověřuje Microsoft');
        parse_str($this->requests[0]['body'], $form);
        $this->assertSame('openid email profile', $form['scope']);
        $this->assertArrayHasKey('code_verifier', $form);

        $h = $run(['tid' => $org, 'email' => 'pepa@firma.cz', 'xms_edov' => true]);
        $this->assertTrue($h['identity']['email_verified'], 'pracovní účet s ověřenou doménou (xms_edov)');
        $h = $run(['tid' => $org, 'email' => 'pepa@firma.cz', 'preferred_username' => 'pepa@firma.cz']);
        $this->assertFalse($h['identity']['email_verified'], 'pracovní účet bez xms_edov: e-mail ani UPN se nevěří');
        $h = $run(['tid' => $org, 'verified_primary_email' => ['Pepa@Firma.cz']]);
        $this->assertSame(['pepa@firma.cz', true], [$h['identity']['email'], $h['identity']['email_verified']]);

        $this->assertSame('failed', $run(['tid' => SSO_MS_CONSUMERS, 'email' => 'x@outlook.com'], 'organizations')['error'], 'jen pracovní účty');
        $this->assertSame('failed', $run(['tid' => $org, 'email' => 'x@firma.cz'], 'consumers')['error'], 'jen osobní účty');
        $this->assertTrue($run(['tid' => $org, 'email' => 'x@firma.cz', 'xms_edov' => '1'], $org)['identity']['email_verified']);
        $this->assertSame('failed', $run(['tid' => $org, 'email' => 'x@firma.cz'], '99999999-9999-9999-9999-999999999999')['error'], 'jiný tenant');
    }

    public function testMicrosoftIssuerMustMatchTenantId(): void
    {
        $org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $this->assertTrue(sso_ms_issuer_ok(['tid' => $org, 'iss' => 'https://login.microsoftonline.com/' . $org . '/v2.0'], 'common'));
        $this->assertFalse(sso_ms_issuer_ok(['tid' => $org, 'iss' => 'https://login.microsoftonline.com/' . SSO_MS_CONSUMERS . '/v2.0'], 'common'));
        $this->assertFalse(sso_ms_issuer_ok(['tid' => $org, 'iss' => 'https://sts.windows.net/' . $org . '/'], 'common'), 'v1 vydavatel');
        $this->assertFalse(sso_ms_issuer_ok(['tid' => 'nesmysl', 'iss' => 'https://login.microsoftonline.com/nesmysl/v2.0'], 'common'));
        $this->assertFalse(sso_ms_issuer_ok(['iss' => 'https://login.microsoftonline.com//v2.0'], 'common'));
    }

    public function testAppleFormPostFlowWithPrivateRelayEmail(): void
    {
        [$q, $flow] = $this->begin('apple');
        $this->fakeHttp([
            'https://appleid.apple.com/auth/token' => fn() => self::ok(['id_token' => self::jwt(['iss' => 'https://appleid.apple.com', 'aud' => 'cz.example.web', 'sub' => '001234.abcd.1234',
                'email' => 'x7y8@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true', 'iat' => time(), 'exp' => time() + 600, 'nonce' => $flow['nonce']], [], self::rsa())]),
            'https://appleid.apple.com/auth/keys' => self::jwks(),
        ]);
        // Apple posílá odpověď POSTem včetně jména uživatele (jen poprvé).
        $r = sso_callback('apple', ['state' => $q['state'], 'code' => 'apple-code', 'user' => '{"name":{"firstName":"Jana"}}']);
        $h = sso_handoff_take($r['token'], 'site', 'dum');
        $this->assertSame(['001234.abcd.1234', 'x7y8@privaterelay.appleid.com', true, true],
            [$h['identity']['subject'], $h['identity']['email'], $h['identity']['email_verified'], $h['identity']['private_email']]);
        parse_str($this->requests[0]['body'], $form);
        $this->assertSame(['cz.example.web', 'authorization_code', 'apple-code'], [$form['client_id'], $form['grant_type'], $form['code']]);
        $this->assertArrayNotHasKey('code_verifier', $form);
        [, $claims, $signed, $sig] = sso_jwt_parts($form['client_secret']);
        $this->assertSame(['TEAM123456', 'cz.example.web', 'https://appleid.apple.com'], [$claims['iss'], $claims['sub'], $claims['aud']]);
        $this->assertTrue(sso_jws_verify('ES256', $signed, $sig, self::ec('apple-p8')[1]), 'client_secret podepsaný klíčem .p8');

        // Zrušení na straně Apple.
        [$q] = $this->begin('apple');
        $this->assertSame('denied', sso_handoff_take(sso_callback('apple', ['state' => $q['state'], 'error' => 'user_cancelled_authorize'])['token'], 'site', 'dum')['error']);
    }

    public function testFacebookFlowUsesAppSecretProof(): void
    {
        [$q, $flow] = $this->begin('facebook');
        $this->fakeHttp([
            'https://graph.facebook.com/v23.0/oauth/access_token' => self::ok(['access_token' => 'EAAB-token', 'token_type' => 'bearer']),
            'https://graph.facebook.com/v23.0/me' => self::ok(['id' => '10220000', 'name' => 'Jana T', 'email' => 'jana@example.org']),
        ]);
        $h = sso_handoff_take(sso_callback('facebook', ['state' => $q['state'], 'code' => 'fb-code'])['token'], 'site', 'dum');
        $this->assertSame(['10220000', 'jana@example.org', true], [$h['identity']['subject'], $h['identity']['email'], $h['identity']['email_verified']]);
        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $tq);
        $this->assertSame(['1234567890', 'fb-secret-XYZ', 'fb-code', $flow['verifier'], 'http://test.invalid/sso/callback/facebook'],
            [$tq['client_id'], $tq['client_secret'], $tq['code'], $tq['code_verifier'], $tq['redirect_uri']]);
        parse_str((string) parse_url($this->requests[1]['url'], PHP_URL_QUERY), $mq);
        $this->assertSame('id,name,email', $mq['fields']);
        $this->assertSame(hash_hmac('sha256', 'EAAB-token', 'fb-secret-XYZ'), $mq['appsecret_proof']);

        // Bez důvěry v e-mail z Facebooku jde jen o propojený účet.
        $GLOBALS['SSO_CONFIG']['facebook']['trust_email'] = false;
        [$q] = $this->begin('facebook');
        $this->assertFalse(sso_handoff_take(sso_callback('facebook', ['state' => $q['state'], 'code' => 'k'])['token'], 'site', 'dum')['identity']['email_verified']);
        // Bez přístupového tokenu → chyba.
        $this->fakeHttp(['https://graph.facebook.com/' => self::ok(['error' => ['message' => 'x']])]);
        [$q] = $this->begin('facebook');
        $this->assertSame('failed', sso_handoff_take(sso_callback('facebook', ['state' => $q['state'], 'code' => 'k'])['token'], 'site', 'dum')['error']);
    }

    public function testIdentityNormalization(): void
    {
        $i = sso_identity('google', 123, '  Jana@EXAMPLE.org ', true, str_repeat('x', 300));
        $this->assertSame(['123', 'jana@example.org', true, 200], [$i['subject'], $i['email'], $i['email_verified'], mb_strlen($i['name'])]);
        $this->assertSame(['', false], [sso_identity('google', 's', 'not-an-email', true, '')['email'], sso_identity('google', 's', 'not-an-email', true, '')['email_verified']]);
        $this->expectException(\SsoError::class);
        sso_identity('google', '', 'a@b.cz', true, '');
    }

    public function testDefaultTransportIsHttpsOnly(): void
    {
        sso_http(null, true);
        $this->assertSame('sso_http_default', sso_http());
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('bez rozšíření curl');
        }
        // Nešifrované spojení odmítne curl ještě před připojením (žádná síť).
        $this->assertSame(['status' => 0, 'body' => ''], sso_http_default('GET', 'http://example.invalid/'));
    }

    public function testOldFlowsAreCleanedUp(): void
    {
        $f = ROOT_STORAGE . '/sso/flow-' . str_repeat('ab', 16) . '.json';
        file_put_contents($f, '{}');
        touch($f, time() - 7200);
        $fresh = ROOT_STORAGE . '/sso/hand-' . str_repeat('cd', 32) . '.json';
        file_put_contents($fresh, '{}');
        sso_store_gc();
        $this->assertFileDoesNotExist($f);
        $this->assertFileExists($fresh);
        unlink($fresh);
        $this->assertNull(sso_store_take('../secret'));
        $this->expectException(\InvalidArgumentException::class);
        sso_store_put('../x', []);
    }
}

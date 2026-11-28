<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Opravy z bezpečnostní kontroly 11/2026: jednorázové odkazy, omezení pokusů na účet v konzoli a portálu. */
final class SecurityHardeningTest extends TestCase
{
    public function testOneTimeLinkConsumedOnlyByValidToken(): void
    {
        q("DELETE FROM settings WHERE `key` = 'test_once'");
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['test_once', 'hash-ok|123']);
        $check = fn(string $t) => fn($v) => hash_equals(explode('|', $v)[0], $t);
        $this->assertNull(setting_consume('test_once', $check('spatne')));
        $this->assertSame('hash-ok|123', (string) val("SELECT value FROM settings WHERE `key` = 'test_once'"), 'špatný pokus platný odkaz nezruší');
        $this->assertSame('hash-ok|123', setting_consume('test_once', $check('hash-ok')));
        $this->assertNull(setting_consume('test_once', $check('hash-ok')), 'podruhé už ne');
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes_billing.php');
        foreach (['signup_login', 'support_login', 'partner_login'] as $k) {
            $this->assertStringContainsString("setting_consume('$k'", $src);
            $this->assertStringNotContainsString("DELETE FROM settings WHERE `key` = '$k'", $src);
        }
    }

    public function testConsoleAndPortalCountFailuresPerAccount(): void
    {
        $acct = 'utok-' . bin2hex(random_bytes(3)) . '@example.org';
        $key = platform_login_account_key($acct);
        $this->assertSame($key, platform_login_account_key(' ' . strtoupper($acct) . ' '));
        $this->assertStringNotContainsString('example', $key, 'e-mail se neukládá čitelně');
        pq('DELETE FROM attempts WHERE ip = ?', [$key]);
        for ($i = 0; $i < 9; $i++) {
            pq('INSERT INTO attempts (ip, at) VALUES (?,?)', [$key, time()]);   // jako z různých adres
        }
        $this->assertFalse(platform_login_blocked($acct));
        pq('INSERT INTO attempts (ip, at) VALUES (?,?)', [$key, time()]);
        $this->assertTrue(platform_login_blocked($acct), '10 chyb na účet za 15 minut → blokace bez ohledu na adresu');
        pq('DELETE FROM attempts WHERE ip = ?', [$key]);
        foreach (['app/platform_console.php', 'app/partner_portal.php'] as $f) {
            $s = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $this->assertStringContainsString('platform_login_blocked($account)', $s);
            $this->assertMatchesRegularExpression('/\$failed\((trim\()?post\(\'email\'\)/', $s, "$f: chyba hesla se počítá i na účet");
        }
    }

    /** Registrace bez potvrzeného e-mailu: web vidí jen správce (brání podvodným webům s cizím názvem SVJ). */
    public function testUnverifiedSiteHiddenFromVisitors(): void
    {
        $this->assertTrue(verify_pending_path_ok('/prihlaseni'));
        $this->assertTrue(verify_pending_path_ok('/overit-email/' . str_repeat('a', 64)));
        $this->assertTrue(verify_pending_path_ok('/vitejte/' . str_repeat('a', 64)));
        $this->assertTrue(verify_pending_path_ok('/sso/google'));
        foreach (['/', '/aktuality', '/dokumenty', '/vyveska', '/inzeraty', '/kontakty'] as $p) {
            $this->assertFalse(verify_pending_path_ok($p), $p);
        }
        $idx = (string) file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $this->assertStringContainsString("setting('verify_pending') !== '' && !is_admin() && !verify_pending_path_ok(\$PATH)", $idx);
        $site = (string) file_get_contents(dirname(__DIR__, 2) . '/app/platform_site.php');
        $this->assertStringContainsString('signup_verify_token($slug, $email)', $site);
        $this->assertStringContainsString("tenant_url(\$t, '/overit-email/' . \$vtoken)", $site);
        $this->assertStringContainsString('tenant_with_ico(', $site, 'jedno IČO = jeden web');

        // Potvrzení: správný token spotřebuje záznam, špatný ne.
        $token = bin2hex(random_bytes(32));
        q("DELETE FROM settings WHERE `key` = 'verify_pending'");
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['verify_pending', hash('sha256', $token) . '|zakladatel@example.org']);
        $check = fn(string $t) => fn($v) => hash_equals(explode('|', $v . '|')[0], hash('sha256', $t));
        $this->assertNull(setting_consume('verify_pending', $check(str_repeat('0', 64))));
        $this->assertSame(hash('sha256', $token) . '|zakladatel@example.org', setting_consume('verify_pending', $check($token)));
        $this->assertSame(0, (int) val("SELECT COUNT(*) FROM settings WHERE `key` = 'verify_pending'"));
    }

    public function testIcoDuplicateFound(): void
    {
        $slug = (string) pq('SELECT slug FROM tenants ORDER BY slug LIMIT 1')->fetchColumn();
        if ($slug === '') {
            $this->markTestSkipped('žádný web na platformě');
        }
        $prev = tenant_settings($slug)['ico'] ?? null;
        tenant_set($slug, 'ico', '99999999');
        try {
            $this->assertSame($slug, tenant_with_ico('99999999'));
            $this->assertSame('', tenant_with_ico('99999999', $slug), 'vlastní web se nepočítá');
            $this->assertSame('', tenant_with_ico(''));
        } finally {
            $prev === null ? tenant_pdo($slug)->exec("DELETE FROM settings WHERE `key` = 'ico'") : tenant_set($slug, 'ico', $prev);
        }
    }

    public function testCalendarLinkCanBeReplaced(): void
    {
        $old = calendar_token();
        $new = calendar_token(true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $new);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, calendar_token(), 'nová adresa platí, stará ne');
    }

    public function testAresLimitedPerAddressInDatabase(): void
    {
        $orig = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(100, 200);
        pq('DELETE FROM attempts WHERE ip = ?', ['ares:' . $_SERVER['REMOTE_ADDR']]);
        try {
            for ($i = 0; $i < ARES_PER_HOUR; $i++) {
                $this->assertTrue(ares_rate_ok());
            }
            $this->assertFalse(ares_rate_ok(), 'další dotaz z téže adresy odmítnut – i bez cookie relace');
        } finally {
            pq('DELETE FROM attempts WHERE ip = ?', ['ares:' . $_SERVER['REMOTE_ADDR']]);
            $orig === null ? (function () { unset($_SERVER['REMOTE_ADDR']); })() : $_SERVER['REMOTE_ADDR'] = $orig;
        }
        $this->assertStringNotContainsString("\$_SESSION['ares_hits']", (string) file_get_contents(dirname(__DIR__, 2) . '/app/platform_site.php'));
    }

    public function testConsoleNagsAboutTwoFactor(): void
    {
        $this->assertStringContainsString("empty(\$super['totp_enabled'])", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/layout.php'));
    }
}

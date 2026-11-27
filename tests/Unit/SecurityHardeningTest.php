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
}
